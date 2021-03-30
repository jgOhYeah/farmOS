<?php

namespace Drupal\farm_sensor_listener\Plugin\DataStream\DataStreamType;

use Drupal\Core\Form\FormStateInterface;
use Drupal\data_stream\DataStreamStorageInterface;
use Drupal\data_stream\Entity\DataStream;
use Drupal\data_stream\Entity\DataStreamInterface;
use Drupal\data_stream\Plugin\DataStream\DataStreamType\Basic;
use Drupal\data_stream\Traits\DataStreamPrivateKeyAccess;
use Drupal\entity\BundleFieldDefinition;
use Drupal\farm_sensor_listener\LegacySensorApiInterface;
use Drupal\fraction\Fraction;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the legacy listener data stream type.
 *
 * @DataStreamType(
 *   id = "legacy_listener",
 *   label = @Translation("Legacy listener"),
 * )
 */
class LegacyListener extends Basic implements DataStreamStorageInterface, LegacySensorApiInterface {

  use DataStreamPrivateKeyAccess;

  /**
   * Database table for legacy data.
   *
   * @var string
   */
  protected $tableName = 'data_stream_legacy';

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = [];

    // Define the public_key field.
    $field = BundleFieldDefinition::create('string')
      ->setLabel($this->t('Public key'))
      ->setDescription($this->t('Public key used to identify this data stream in the legacy listener endpoint.'))
      ->setDefaultValueCallback(DataStream::class . '::createUniqueKey')
      ->setSetting('max_length', 255)
      ->setSetting('text_processing', 0)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -10,
      ]);
    $fields['public_key'] = $field;

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    // Inherit the data_stream_basic views data config.
    $data[$this->tableName] = $data['data_stream_basic'];
    unset($data['data_stream_basic']);

    // Add the additional name field.
    $data[$this->tableName]['name'] = [
      'title' => $this->t('Name'),
      'help' => $this->t('Name of the value reading.'),
      'field' => [
        'id' => 'standard',
        'click sortable' => TRUE,
      ],
      'sort' => [
        'id' => 'standard',
      ],
      'filter' => [
        // @todo Use/create a filter that displays available "names".
        'id' => 'standard',
      ],
    ];

    // Add a legacy_listener_data relationship to the data_stream_data table
    // that references the data_stream_legacy table.
    $data['data_stream_data']['legacy_listener_data'] = [
      'title' => $this->t('Legacy listener data'),
      'help' => $this->t('Legacy listener data stream data.'),
      'relationship' => [
        'base' => 'data_stream_legacy',
        'base field' => 'id',
        'field' => 'id',
        'id' => 'standard',
        'label' => $this->t('Legacy listener data'),
      ],
    ];

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Get the data stream entity.
    $form_object = $form_state->getFormObject();
    /** @var \Drupal\data_stream\Entity\DataStreamInterface  $entity */
    $entity = $form_object->getEntity();

    // Field to configure the public_key.
    $form[$this->getPluginId()]['public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public key'),
      '#description' => $this->t('The public key used to identify this data stream.'),
      '#default_value' => $entity->get('public_key')->value,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    // Get the data stream entity.
    $form_object = $form_state->getFormObject();
    /** @var \Drupal\data_stream\Entity\DataStreamInterface  $entity */
    $entity = $form_object->getEntity();

    // If the entity is set, save the public_key value.
    if (!empty($entity)) {
      $public_key = $form_state->getValues()['public_key'];
      $entity->set('public_key', $public_key);
    }

  }

  /**
   * {@inheritdoc}
   */
  public function apiHandleLegacyRequest(DataStreamInterface $stream, Request $request) {
    return $this->apiHandleRequest($stream, $request);
  }

  /**
   * {@inheritdoc}
   */
  public function storageGet(DataStreamInterface $stream, array $params) {

    $query = $this->connection->select($this->tableName, 'dsdl');
    $query->fields(
      'dsdl',
      ['timestamp', 'name', 'value_numerator', 'value_denominator']
    );
    $query->condition('dsdl.id', $stream->id());

    if (isset($params['name'])) {
      $query->condition('dsdl.name', $params['name']);
    }

    if (isset($params['start']) && is_numeric($params['start'])) {
      $query->condition('dsdl.timestamp', $params['start'], '>=');
    }

    if (isset($params['end']) && is_numeric($params['end'])) {
      $query->condition('dsdl.timestamp', $params['end'], '<=');
    }

    $query->orderBy('dsdl.timestamp', 'DESC');

    $offset = 0;
    if (isset($params['offset']) && is_numeric($params['offset'])) {
      $offset = $params['offset'];
    }

    if (isset($params['limit']) && is_numeric($params['limit'])) {
      $query->range($offset, $params['limit']);
    }

    $result = $query->execute();

    // Build an array of data.
    $data = [];
    foreach ($result as $row) {

      // If name or timestamp are empty, skip.
      if (empty($row->timestamp)) {
        continue;
      }

      // Convert the value numerator and denominator to a decimal.
      $fraction = new Fraction($row->value_numerator, $row->value_denominator);
      $value = $fraction->toDecimal(0, TRUE);

      // Create a data object for the sensor value.
      $point = new \stdClass();
      $point->timestamp = $row->timestamp;
      $point->{$row->name} = $value;
      $data[] = $point;
    }

    // Return the data.
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function storageSave(DataStreamInterface $stream, array $data) {

    // If the data is an array of multiple data points, iterate over each and
    // recursively process.
    if (is_array(reset($data))) {
      foreach ($data as $point) {
        $this->storageSave($stream, $point);
      }
      return TRUE;
    }

    // Save a timestamp.
    $timestamp = NULL;

    // If a timestamp is provided, ensure that it is in UNIX timestamp format.
    if (!empty($data['timestamp'])) {

      // If the timestamp is numeric, we're good!
      if (is_numeric($data['timestamp'])) {
        $timestamp = $data['timestamp'];
      }

      // Otherwise, try converting it from a string. If that doesn't work, we
      // throw it out and fall back on REQUEST_TIME set above.
      else {
        $strtotime = strtotime($data['timestamp']);
        if (!empty($strtotime)) {
          $timestamp = $strtotime;
        }
      }
    }

    // Generate a timestamp from the request time. This will only be used if a
    // timestamp is not provided in the JSON data.
    if (empty($timestamp)) {
      $timestamp = \Drupal::time()->getRequestTime();
    }

    // Iterate over the JSON properties.
    foreach ($data as $key => $value) {

      // If the key is "timestamp", skip to the next property in the JSON.
      if ($key == 'timestamp') {
        continue;
      }

      // If the value is not numeric, skip it.
      if (!is_numeric($value)) {
        continue;
      }

      // Create a row to store in the database;.
      $row = [
        'id' => $stream->id(),
        'name' => $key,
        'timestamp' => $timestamp,
      ];

      // Convert the value to a fraction.
      $fraction = Fraction::createFromDecimal($value);
      $row['value_numerator'] = $fraction->getNumerator();
      $row['value_denominator'] = $fraction->getDenominator();

      // Enter the reading into the database.
      $this->connection->insert($this->tableName)
        ->fields($row)
        ->execute();
    }

    return TRUE;
  }

}
