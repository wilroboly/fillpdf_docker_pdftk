<?php
/**
 * @file
 * Contains \Drupal\fillpdf_docker_pdftk\Form\FillPdfDockerSettingsForm.
 */

namespace Drupal\fillpdf_docker_pdftk\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\fillpdf\Form\FillPdfSettingsForm;


class FillPdfDockerSettingsForm extends FillPdfSettingsForm {

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $config = $this->config('fillpdf.settings');
    $docker_config = $this->config('fillpdf_docker_pdftk.settings');

    $fillpdf_service = $config->get('backend');

    $url = Url::fromUri('https://github.com/wilroboly/pdf-filler');

    $form['backend']['#options']['docker_pdftk'] = $this->t('Use PDF-Filler Docker container Service: Read up about it here: <a href=":see_documentation">PDF Filler as a Docker service</a>', array(':see_documentation' => $url->getUri()));


    // RESTful settings PDFTK
    $form['pdftk'] = array(
      '#type' => 'fieldset',
      '#title' => t('Configure Local PDFTK'),
      '#collapsible' => TRUE,
      '#collapsed' => $fillpdf_service !== 'pdftk',
      '#states' => array(
        'visible' => array(
          ':input[name="backend"]' => array('value' => 'pdftk'),
        ),
      ),
    );
    $form['pdftk']['pdftk_path'] = $form['pdftk_path'];
    $form['pdftk']['pdftk_path']['#title'] = $this->t('Path to local pdftk app');
    $form['pdftk']['pdftk_path']['#states'] = array(
      'visible' => array(
        ':input[name="backend"]' => array('value' => 'pdftk'),
      ),
    );
    unset($form['pdftk_path']);

    $form['fillpdf_service']['#states'] = array(
      'visible' => array(
        ':input[name="backend"]' => array('value' => 'fillpdf_service'),
      ),
    );

    // RESTful settings PDFTK
    $form['docker_pdftk'] = array(
      '#type' => 'fieldset',
      '#title' => t('Configure RESTful PDFTK Service'),
      '#collapsible' => TRUE,
      '#collapsed' => $fillpdf_service !== 'docker_pdftk',
      '#states' => array(
        'visible' => array(
          ':input[name="backend"]' => array('value' => 'docker_pdftk'),
        ),
      ),
    );
    $form['docker_pdftk']['fillpdf_rest_endpoint'] = array(
      '#type' => 'textfield',
      '#title' => t('Service endpoint'),
      '#default_value' => $docker_config->get('fillpdf_rest_endpoint', 'rest.endpoint.local/api/1.0/pdftk'),
      '#description' => t('The URI endpoint for the docker service. You should read the documentation for the PDF-filler Docker to ensure you have this working properly.'),
    );
    // @TODO: This was an option which we were entertaining.
    //  $form['docker_pdftk']['fillpdf_rest_api_key'] = array(
    //    '#type' => 'textfield',
    //    '#title' => t('API Key'),
    //    '#default_value' => $config->get('fillpdf_rest_api_key', '0'),
    //    '#description' => t('You can setup this us with your own SHA key or whatever your REST API uses.'),
    //  );
    $form['docker_pdftk']['fillpdf_rest_protocol'] = array(
      '#type' => 'radios',
      '#title' => t('Use HTTPS?'),
      '#description' => t('HTTPS is always preferrable, but likely the service is in an internal network, so this may not be as important.'),
      '#default_value' => $docker_config->get('fillpdf_rest_protocol', 'https'),
      '#options' => array(
        'https' => t('Use HTTPS'),
        'http' => t('Do not use HTTPS'),
      ),
    );

    return $form;
  }

  /**
   * @TODO: Add the REST API Key logic and validation test
   * {@inheritdoc}
   */
//  public function validateForm(array &$form, FormStateInterface $form_state) {
//    if ($form_state->getValue('pdftk_path')) {
//      $status = FillPdf::checkPdftkPath($form_state->getValue('pdftk_path'));
//      if ($status === FALSE) {
//        $form_state->setErrorByName('pdftk_path', $this->t('The path you have entered for
//      <em>pdftk</em> is invalid. Please enter a valid path.'));
//      }
//    }
//  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save form values.
    $this->config('fillpdf.settings')
      ->set('backend', $form_state->getValue('backend'))
      ->save();

    $config =\Drupal::service('config.factory')->getEditable('fillpdf_docker_pdftk.settings');
    $config->set('fillpdf_rest_endpoint', $form_state->getValue('fillpdf_rest_endpoint'));
    $config->set('fillpdf_rest_protocol', $form_state->getValue('fillpdf_rest_protocol'));
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
