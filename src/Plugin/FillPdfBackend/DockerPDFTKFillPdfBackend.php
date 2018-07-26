<?php

namespace Drupal\fillpdf_docker_pdftk\Plugin\FillPdfBackend;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;

use Drupal\fillpdf\FillPdfBackendPluginInterface;
use Drupal\fillpdf\FillPdfFormInterface;
use Drupal\fillpdf\Plugin\BackendServiceManager;
use Drupal\fillpdf\FieldMapping\ImageFieldMapping;
use Drupal\fillpdf\FieldMapping\TextFieldMapping;

use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Plugin(
 *   id = "docker_pdftk",
 *   label = @Translation("Dockerized PDFtk"),
 * )
 */
class DockerPDFTKFillPdfBackend implements FillPdfBackendPluginInterface, ContainerFactoryPluginInterface {

  /** @var array $configuration */
  protected $configuration;

  /** @var array $config */
  protected $config_factory;

  /** @var string */
  protected $pluginId;

  /** @var \Drupal\Core\File\FileSystem */
  protected $fileSystem;

  /** @var string $fillPdfServiceEndpoint */
  protected $fillPdfServiceEndpoint;

  /** @var \Drupal\fillpdf\Plugin\BackendServiceManager */
  protected $backendServiceManager;

  /** @var \GuzzleHttp\Client */
  private $httpClient;

  public function __construct(FileSystem $file_system, Client $http_client, BackendServiceManager $backendServiceManager, array $configuration, $plugin_id, $plugin_definition) {
    $this->pluginId = $plugin_id;
    $this->configuration = $configuration;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->backendServiceManager = $backendServiceManager;
    $this->config_factory =\Drupal::service('config.factory')->getEditable('fillpdf_docker_pdftk.settings');
    $this->fillPdfServiceEndpoint = "{$this->config_factory->get('fillpdf_rest_protocol')}://{$this->config_factory->get('fillpdf_rest_endpoint')}";
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('file_system'),
      $container->get('http_client'),
      $container->get('plugin.manager.fillpdf_backend_service'),
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * @inheritdoc
   */
  public function parse(FillPdfFormInterface $fillpdf_form) {
    /** @var FileInterface $file */
    $file = File::load($fillpdf_form->file->target_id);
    $uri = $file->getFileUri();

    if ($wrapper = \Drupal::service('stream_wrapper_manager')->getViaUri($uri)) {
      $file_url = $wrapper->getExternalUrl();
    } else {
      $file_url = '';
    }

    /** @var \Drupal\fillpdf\Plugin\BackendServiceInterface $backend_service */
    $backend_service = $this->backendServiceManager->createInstance($this->pluginId, $this->configuration);
//    $backend_service->setEntity($fillpdf_form);

    return $backend_service->parse($file_url);
  }

  /**
   *
   * Output PDF document. This function would usually also deal with image
   * removing image metadata. But, I think its rather pointless for us.
   *
   * @inheritdoc
   */
  public function populateWithFieldData(FillPdfFormInterface $pdf_form, array $field_mapping, array $context) {

    $uuid = $pdf_form->uuid();

    $mapping_method = $this->config_factory->get($pdf_form->uuid(). '.mapping_method');

    /** @var FileInterface $original_file */
    $original_file = File::load($pdf_form->file->target_id);

    $uri = $original_file->getFileUri();

    if ($wrapper = \Drupal::service('stream_wrapper_manager')->getViaUri($uri)) {
      $file_url = $wrapper->getExternalUrl();
    } else {
      $file_url = '';
    }

    /** @var \Drupal\fillpdf\Plugin\BackendServiceInterface $backend_service */
    $backend_service = $this->backendServiceManager->createInstance($this->pluginId, $this->configuration);

    // Let's inject the entity into the context so we can spare a ->load() later.
    $context['entity'] = $pdf_form;
    $context['pdf_file'] = $file_url;

    if ($mapping_method == 'simple') {

      return $backend_service->merge(NULL, $field_mapping, $context);

    } else {

      $pdf = file_get_contents($original_file->getFileUri());

      // To use the BackendService, we need to convert the fields into the format
      // it expects.
      $mapping_objects = [];
      foreach ($field_mapping['fields'] as $key => $field) {
        if (substr($field, 0, 7) === '{image}') {
          // Remove {image} marker.
          $image_filepath = substr($field, 7);
          $image_realpath = $this->fileSystem->realpath($image_filepath);
          $mapping_objects[$key] = new ImageFieldMapping(base64_encode(file_get_contents($image_realpath)));
        }
        else {
          $mapping_objects[$key] = new TextFieldMapping($field);
        }
      }
      return $backend_service->merge_complex($pdf, $mapping_objects, $context);

    }
  }

}
