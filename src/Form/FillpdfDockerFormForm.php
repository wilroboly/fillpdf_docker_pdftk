<?php

namespace Drupal\fillpdf_docker_pdftk\Form;

use Drupal\fillpdf\Form\FillPdfFormForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fillpdf\FillPdfFormInterface;

class FillpdfDockerFormForm extends FillPdfFormForm {


  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var FillPdfFormInterface $entity */
    $entity = $this->entity;
    $config = $this->config('fillpdf_docker_pdftk.settings');
    $value = $config->get($entity->uuid());

    $fillpdf_config = $this->config('fillpdf.settings');
    $fillpdf_service = $fillpdf_config->get('backend');

    if ($fillpdf_service == 'docker_pdftk') {
      $form['additional_settings']['mapping_method'] = array(
        '#type' => 'select',
        '#title' => $this->t('Field Mapping Method'),
        '#description' => $this->t('With PDFTK and the Docker PDF-filler service, the fields in the PDF are filled in via a multipart/form-data POST request. As such, the field mapping can be done in one of two ways: simple or complex. Complex is not yet supported. Simple is a straight forward key=value pair in query format urlencoded for each field.'),
        '#options' => [ 'simple' => $this->t('Simple Mapping'), 'complex' => $this->t('Complex Mapping')],
        '#default_value' => isset($value['mapping_method']) ? $value['mapping_method'] : 'simple',
        '#weight' => 50,
      );

      $form['additional_settings']['flatten'] = array(
        '#type' => 'checkbox',
        '#title' => $this->t('Flatten the output of the PDF'),
        '#description' => $this->t("If you wish to flatten the PDF such that it cannot be edited once it has been generated. This option will render the document as a simple PDF with no interactive components. This is often useful when information needs to be retained and no longer altered."),
        '#default_value' => isset($value['flatten']) ? $value['flatten'] : FALSE,
        '#weight' => 50,
      );

    }

    return $form;
  }

  /**
   *
   *
   *
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    parent::save($form, $form_state);

    $config =\Drupal::service('config.factory')->getEditable('fillpdf_docker_pdftk.settings');
    $config->set($this->entity->uuid() . '.flatten', $form_state->getValue('flatten'));
    $config->set($this->entity->uuid() . '.mapping_method', $form_state->getValue('mapping_method'));
    $config->save();
  }

}

