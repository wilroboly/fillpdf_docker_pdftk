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

  /**
   * This ensures the Route Event comes after the FillPDF module routes. Otherwise,
   * the module could land on events that do not exist.
   * {@inheritdoc}
   */
//  public static function getSubscribedEvents() {
//
//    // Come after field_ui.
//    $events[RoutingEvents::ALTER] = array(
//      'onAlterRoutes',
//      -110,
//    );
//    return $events;
//  }
}