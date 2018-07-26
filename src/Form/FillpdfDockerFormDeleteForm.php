<?php

namespace Drupal\fillpdf_docker_pdftk\Form;

use Drupal\fillpdf\Form\FillPdfFormDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fillpdf\FillPdfFormInterface;

class FillpdfDockerFormDeleteForm extends FillPdfFormDeleteForm {

  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var FillPdfFormInterface $fillpdf_form */
//    $fillpdf_form = $this->getEntity();
//
//    /** @var FileInterface $file */
//    $file = File::load($fillpdf_form->get('file')->first()->target_id);
//    $fillpdf_form->delete();

    $config =\Drupal::service('config.factory')->getEditable('fillpdf_docker_pdftk.settings');

    $config->clear($this->entity->uuid());
    $config->save();

    parent::submitForm($form, $form_state);
  }

}

