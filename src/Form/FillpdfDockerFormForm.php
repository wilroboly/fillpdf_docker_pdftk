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

    $form['flatten'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Flatten the output of the PDF'),
      '#description' => $this->t("If you wish to flatten the PDF such that it cannot be edited once it has been generated. This option will render the document as a simple PDF with no interactive components. This is often useful when information needs to be retained and no longer altered."),
      '#default_value' => isset($value['flatten']) ? $value['flatten'] : FALSE,
      '#group' => 'additional_settings',
      '#weight' => 50,
    );

    return $form;
  }

  /**
   *
   *
   *
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $config =\Drupal::service('config.factory')->getEditable('fillpdf_docker_pdftk.settings');
    $flatten_flag = $form_state->getValue('flatten');
    $config->set($this->entity->uuid() . '.flatten', $flatten_flag);
    $config->save();
  }

}

