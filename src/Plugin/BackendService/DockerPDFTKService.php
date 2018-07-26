<?php

namespace Drupal\fillpdf_docker_pdftk\Plugin\BackendService;

use Drupal\Core\Annotation\Translation;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;

use Drupal\fillpdf\Annotation\BackendService;
use Drupal\fillpdf\FillPdfFormInterface;
use Drupal\fillpdf\FieldMapping\ImageFieldMapping;
use Drupal\fillpdf\FieldMapping\TextFieldMapping;
use Drupal\fillpdf\Plugin\BackendServiceBase;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @BackendService(
 *   id = "docker_pdftk",
 *   label = @Translation("Dockerized PDFtk"),
 * )
 */
class DockerPDFTKService extends BackendServiceBase implements ContainerFactoryPluginInterface {

  /** @var array $configuration */
  protected $configuration;

  /** @var \Drupal\Core\File\FileSystem */
  protected $fileSystem;

  /** @var \GuzzleHttp\Client */
  private $httpClient;

  /** @var array $config_factory */
  protected $config_factory;

  /** @var string $fillPdfServiceEndpoint */
  protected $fillPdfServiceEndpoint;

  public function __construct(FileSystem $file_system, Client $http_client, array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configuration = $configuration;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->config_factory =\Drupal::service('config.factory')->getEditable('fillpdf_docker_pdftk.settings');
    $this->fillPdfServiceEndpoint = "{$this->config_factory->get('fillpdf_rest_protocol')}://{$this->config_factory->get('fillpdf_rest_endpoint')}";
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('file_system'),
      $container->get('http_client'),
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * Setup the entity variable
   */
  public function setEntity(FillPdfFormInterface $fillpdf_form) {
    $this->entity = $fillpdf_form;
  }

  /**
   * @inheritdoc
   */
  public function parse($file_url) {

    try {
      $fields_response = $this->httpClient->get($this->fillPdfServiceEndpoint . '/fields.json', [
        'form_params'   => ['pdf' => $file_url],
        'headers'       => ['Content-Type' => 'multipart/form-data'],
      ]);
    }
    catch (RequestException $e) {
      if ($response = $e->getResponse()) {
        watchdog_exception('fillpdf_docker', $e);
        drupal_set_message(t('There was a problem contacting the FillPDF Docker service.
        It may be down, or you may not have internet access. [ERROR @code: @message]',
          ['@code' => $e->getCode(), '@message' => $e->getMessage()]), 'error');
      }
      else {
        watchdog_exception('fillpdf_docker', $e);
        drupal_set_message('Unknown error occurred parsing PDF.', 'error');
      }
    }

    $fields = \GuzzleHttp\json_decode((string) $fields_response->getBody(), TRUE);

    if (count($fields) === 0) {
      drupal_set_message(t('PDF does not contain fillable fields.'), 'warning');
      return [];
    }

    // Build a simple map of dump_data_fields keys to our own array keys.
    $data_fields_map = array(
      'pdf_name'        =>  'name',
    );

    foreach ($fields as $key => $values) {
      $fields[$key] = $this->replace_pdf_field_keys($values, $data_fields_map);
    }

    return $fields;
  }

  /**
   * @param $pdf
   * @param array $field_mappings
   * @param array $options
   *
   * @return bool|null|string
   */
  public function merge($pdf, array $field_mappings, array $options) {

    /** @var FillPdfFormInterface $entity */
    $entity = $options['entity'];

    $pdf_file = $options['pdf_file'];

    $flatten = (int)$this->config_factory->get($entity->uuid(). '.flatten');

    $query = [ 'pdf' => $pdf_file];

    if ($flatten) {
      $query += array('flatten' => $flatten);
    }

    if (is_array($field_mappings['fields'])) {
      $query += $field_mappings['fields'];
    }

    try {
      $fields_response = $this->httpClient->post($this->fillPdfServiceEndpoint . '/fill', [
          'query'    => $query,
          'headers'  => ['Content-Type' => 'multipart/form-data'],
      ]);

      return (string)$fields_response->getBody();
    }
    catch (RequestException $e) {
      if ($response = $e->getResponse()) {
          watchdog_exception('fillpdf_docker', $e);
          drupal_set_message(t('There was a problem contacting the FillPDF Docker service.
    It may be down, or you may not have internet access. [ERROR @code: @message]',
              ['@code' => $e->getCode(), '@message' => $e->getMessage()]), 'error');
      }
      else {
          watchdog_exception('fillpdf_docker', $e);
          drupal_set_message('Unknown error occurred parsing PDF.', 'error');
          return NULL;
      }
    }
  }

  /**
   *
   * @TODO: This option will require an update to the PDF-filler Docker application.
   *        Since we are currently using a simplified webform output and images
   *        along with other options are not required, we can leave this to future
   *        development.
   *
   * @param $pdf
   * @param array $field_mappings
   * @param array $options
   *
   * @return bool|null|string
   */
  public function merge_complex($pdf, array $field_mappings, array $options) {

    /** @var FillPdfFormInterface $entity */
    $entity = $options['entity'];

    $pdf_file = $options['pdf_file'];

    $flatten = (int)$this->config_factory->get($entity->uuid(). '.flatten');

    $api_fields = [];
    foreach ($field_mappings as $key => $mapping) {
      $api_field = NULL;

      if ($mapping instanceof TextFieldMapping) {
        $api_field = array(
          'type' => 'text',
          'data' => $mapping->getData(),
        );
      }
      elseif ($mapping instanceof ImageFieldMapping) {
        $api_field = array(
          'type' => 'image',
          'data' => base64_encode($mapping->getData()),
        );

        if ($extension = $mapping->getExtension()) {
          $api_field['extension'] = $extension;
        }
      }

      if ($api_field) {
        $api_fields[$key] = $api_field;
      }
    }

    $query = [ 'pdf' => $pdf_file];

    if ($flatten) {
      $query += array('flatten' => $flatten);
    }

    try {
      $fields_response = $this->httpClient->post($this->fillPdfServiceEndpoint . '/fill', [
        'query'    => $query,
        'headers'  => ['Content-Type' => 'multipart/form-data'],
      ]);

      return (string)$fields_response->getBody();
    }
    catch (RequestException $e) {
      if ($response = $e->getResponse()) {
        watchdog_exception('fillpdf_docker', $e);
        drupal_set_message(t('There was a problem contacting the FillPDF Docker service.
    It may be down, or you may not have internet access. [ERROR @code: @message]',
          ['@code' => $e->getCode(), '@message' => $e->getMessage()]), 'error');
      }
      else {
        watchdog_exception('fillpdf_docker', $e);
        drupal_set_message('Unknown error occurred parsing PDF.', 'error');
        return NULL;
      }
    }
  }

  /**
   * Replace keys of given array by values of $keys
   * $keys format is [$oldKey=>$newKey]
   *
   * With $filter==true, will remove elements with key not in $keys
   *
   * @param  array   $array
   * @param  array   $keys
   * @param  boolean $filter
   *
   * @return $array
   */
  protected function replace_pdf_field_keys(array $array, array $keys, $filter=false)
  {
    $newArray=[];
    foreach($array as $key=>$value)
    {
      if(isset($keys[$key]))
      {
        $newArray[$keys[$key]]=$value;
      }
      elseif(!$filter)
      {
        $newArray[$key]=$value;
      }
    }

    return $newArray;
  }

}
