<?php

/**
 * @file
 * Contains \Drupal\form_overwrite\Routing\FillPdfDockerRouteSubscriber.
 */

namespace Drupal\fillpdf_docker_pdftk\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class FillPdfDockerRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('fillpdf.settings')) {
      $route->setDefault('_form', '\Drupal\fillpdf_docker_pdftk\Form\FillPdfDockerSettingsForm');
    }
  }
  
}