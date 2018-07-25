<?php

namespace Drupal\fillpdf_docker_pdftk\Plugin\FillPdfBackend;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\Core\File\FileSystem;
use Drupal\fillpdf\FillPdfBackendPluginInterface;
use Drupal\fillpdf\FillPdfFormInterface;
use Drupal\Component\Serialization\Json;

/**
 * @Plugin(
 *   id = "docker_pdftk",
 *   label = @Translation("Dockerized PDFtk"),
 * )
 */
class DockerPDFTKFillPdfBackend implements FillPdfBackendPluginInterface {
  /** @var string $fillPdfServiceEndpoint */
  protected $fillPdfServiceEndpoint;

  /** @var array $config */
  protected $config;

  /** @var array $config */
  protected $config_factory;

  public function __construct(array $config) {
    $this->config = $config;
    $this->config_factory =\Drupal::service('config.factory')->getEditable('fillpdf_docker_pdftk.settings');
    $this->fillPdfServiceEndpoint = "{$this->config_factory->get('fillpdf_rest_protocol')}://{$this->config_factory->get('fillpdf_rest_endpoint')}";
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

    $pdf = array(
      'pdf' => $file_url
    );

    $params = [
      'method'      => 'GET',
      'action'      => 'fields.json',
      //'key'         => $api_key,
      'fields'      => $pdf
    ];

    $result = $this->json_request($params);

    $fields = Json::decode($result->data);

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

  protected function json_request($params) {
    $url = $this->fillPdfServiceEndpoint;

    $params += [
      'headers'      => array('Content-Type' => 'multipart/form-data'),
      'method'      => 'POST',
      'action'      => 'fill',
      'contents'    => '',
      'fields'      => '',
      'key'         => '',
      'flatten'     => '',
      'image_data'  => '',
      'filename'    => ''
    ];

    //$form_params = http_build_query($params['fields']);

    $options = array(
      'headers' => $params['headers'],
      'form_params' => $params['fields'],
    );

    $request_url = $url . '/' . $params['action'];
    return $this->rest_request($params['method'], $request_url, $options);
  }

  /**
   * Attempts to get a file using a HTTP request and to pass it on
   *
   * @param string $uri
   *   The URI of the file to grab.
   * @param string $destination
   *   Stream wrapper URI specifying where the file should be placed. If a
   *   directory path is provided, the file is saved into that directory under its
   *   original name. If the path contains a filename as well, that one will be
   *   used instead.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  protected function rest_request($method, $uri, $options) {
    $ret = new \stdClass;
    $ret->error = FALSE;

//    @TODO: Put messages in watchdog

    $client = \Drupal::httpClient();

    try {
      $response = $client->request($method, $uri, $options);
      $code = $response->getStatusCode();
      if ($code == 200) {
        $body = $response->getBody()->getContents();
        $ret->data = $body;
      }

    }
    catch (RequestException $e) {
      $ret->error = TRUE;
      watchdog_exception('fillpdf', $e);
      drupal_set_message(t('There was a problem contacting the FillPDF service.
      It may be down, or you may not have internet access. [ERROR @code: @message]',
        ['@code' => $e->getCode(), '@message' => $e->getMessage()]), 'error');
    }
    return $ret;
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

  /**
   *
   * Output PDF document. This function would usually also deal with image
   * removing image metadata. But, I think its rather pointless for us.
   *
   * @inheritdoc
   */
  public function populateWithFieldData(FillPdfFormInterface $pdf_form, array $field_mapping, array $context) {
    /** @var FileInterface $original_file */
    $original_file = File::load($pdf_form->file->target_id);
    $original_pdf = file_get_contents($original_file->getFileUri());

    return $original_pdf;
  }

  /**
   *
   * @TODO: This merge function needs to be updated to work with FILLPDF
   *
   * @param $pdf
   * @param array $field_mappings
   * @param array $options
   *
   * @return bool|null|string
   */
  public function merge($pdf, array $field_mappings, array $options) {
    $flatten = $options['flatten'];

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

    $request = [
      'pdf' => base64_encode($pdf),
      'flatten' => $flatten,
      'fields' => $api_fields,
    ];

    $json = \GuzzleHttp\json_encode($request);

    try {
      $response = $this->httpClient->post($this->configuration['local_service_endpoint'] . '/api/v1/merge', [
        'body' => $json,
        'headers' => ['Content-Type' => 'application/json'],
      ]);

      $decoded = \GuzzleHttp\json_decode((string) $response->getBody(), TRUE);
      return base64_decode($decoded['pdf']);
    }
    catch (RequestException $e) {
      watchdog_exception('fillpdf', $e);
      return NULL;
    }
  }


}
