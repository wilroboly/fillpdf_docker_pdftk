<?php

namespace Drupal\fillpdf_docker_pdftk\Entity;

use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

class FillpdfDockerForm extends FillPdfForm {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Serialized data'))
      ->setDescription(t('Any data and settings to be stored with an entity using serialized JSON object'));

    return $fields;
  }

}