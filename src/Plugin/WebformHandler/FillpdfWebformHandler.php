<?php

namespace Drupal\fillpdf_docker_pdftk\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Element\WebformHtmlEditor;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Drupal\fillpdf\Component\Helper\FillPdfMappingHelper;
use Drupal\fillpdf\EntityHelper;
use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\fillpdf\FillPdfFormInterface;
use Drupal\fillpdf\FillPdfBackendManager;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Webform submission action handler.
 *
 * @WebformHandler(
 *   id = "fillpdf_action",
 *   label = @Translation("FillPDF Action"),
 *   category = @Translation("Action"),
 *   description = @Translation("Produce a PDF document from values of a submission."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class FillpdfWebformHandler extends WebformHandlerBase {

  protected $populatedPDF;

  /**
   * The backend manager (finds the filling plugin the user selected).
   *
   * @var FillPdfBackendManager
   */
  protected $backendManager;

  /**
   * The webform token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  /** @var EntityHelper */
  protected $entityHelper;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityHelper $entity_helper, FillPdfBackendManager $backend_manager, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, WebformTokenManagerInterface $token_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator);
    $this->backendManager = $backend_manager;
    $this->tokenManager = $token_manager;
    $this->entityHelper = $entity_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('fillpdf.entity_helper'),
      $container->get('plugin.manager.fillpdf_backend'),
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('webform.token_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $configuration = $this->getConfiguration();
    $settings = $configuration['settings'];

    // Get state labels.
    $states = [
      WebformSubmissionInterface::STATE_DRAFT => $this->t('Draft Saved'),
      WebformSubmissionInterface::STATE_CONVERTED => $this->t('Converted'),
      WebformSubmissionInterface::STATE_COMPLETED => $this->t('Completed'),
      WebformSubmissionInterface::STATE_UPDATED => $this->t('Updated'),
    ];
    $settings['states'] = array_intersect_key($states, array_combine($settings['states'], $settings['states']));

    // Get message type.
    $message_types = [
      'status' => t('Status'),
      'error' => t('Error'),
      'warning' => t('Warning'),
      'info' => t('Info'),
    ];
    $settings['message'] = $settings['message'] ? WebformHtmlEditor::checkMarkup($settings['message']) : NULL;
    $settings['message_type'] = $message_types[$settings['message_type']];

    // Get data element keys.
    $data = Yaml::decode($settings['data']) ?: [];
    $settings['data'] = array_keys($data);

    return [
        '#settings' => $settings,
      ] + parent::getSummary();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'states' => [WebformSubmissionInterface::STATE_COMPLETED],
      'notes' => '',
      'data' => '',
      'message' => '',
      'message_type' => 'status',
      'debug' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    //  @TODO: This gets us all the Entities of the type FillPDF_Form
    //         Now we can build a list of choices and allow the user to select the one
    //         which this action will act upon.
//    $entity = \Drupal::entityTypeManager()->getStorage('fillpdf_form')->loadMultiple();


    $results_disabled = $this->getWebform()->getSetting('results_disabled');

    $form['trigger'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Trigger'),
    ];
    $form['trigger']['states'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Execute'),
      '#options' => [
        WebformSubmissionInterface::STATE_DRAFT => $this->t('…when <b>draft</b> is saved.'),
        WebformSubmissionInterface::STATE_CONVERTED => $this->t('…when anonymous submission is <b>converted</b> to authenticated.'),
        WebformSubmissionInterface::STATE_COMPLETED => $this->t('…when submission is <b>completed</b>.'),
        WebformSubmissionInterface::STATE_UPDATED => $this->t('…when submission is <b>updated</b>.'),
      ],
      '#required' => TRUE,
      '#access' => $results_disabled ? FALSE : TRUE,
      '#default_value' => $results_disabled ? [WebformSubmissionInterface::STATE_COMPLETED] : $this->configuration['states'],
    ];

    $form['actions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Actions'),
    ];
//    $form['actions']['notes'] = [
//      '#type' => 'webform_codemirror',
//      '#mode' => 'text',
//      '#title' => $this->t('Append the below text to notes (Plain text)'),
//      '#default_value' => $this->configuration['notes'],
//    ];
    $form['actions']['message'] = [
      '#type' => 'webform_html_editor',
      '#title' => $this->t('Display message'),
      '#default_value' => $this->configuration['message'],
    ];
    $form['actions']['message_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Display message type'),
      '#options' => [
        'status' => t('Status'),
        'error' => t('Error'),
        'warning' => t('Warning'),
        'info' => t('Info'),
      ],
      '#default_value' => $this->configuration['message_type'],
    ];
    $form['actions']['data'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Update the below submission data. (YAML)'),
      '#default_value' => $this->configuration['data'],
    ];

    $elements_rows = [];
    $elements = $this->getWebform()->getElementsInitializedFlattenedAndHasValue();
    foreach ($elements as $element_key => $element) {
      $elements_rows[] = [
        $element_key,
        (isset($element['#title']) ? $element['#title'] : ''),
      ];
    }
    $form['actions']['elements'] = [
      '#type' => 'details',
      '#title' => $this->t('Available element keys'),
      'element_keys' => [
        '#type' => 'table',
        '#header' => [$this->t('Element key'), $this->t('Element title')],
        '#rows' => $elements_rows,
      ],
      '#collapsed' => TRUE,
    ];
    $form['actions']['token_tree_link'] = $this->tokenManager->buildTreeElement();

    // Development.
    $form['development'] = [
      '#type' => 'details',
      '#title' => $this->t('Development settings'),
    ];
    $form['development']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debugging'),
      '#description' => $this->t('If checked, trigger actions will be displayed onscreen to all users.'),
      '#return_value' => TRUE,
      '#default_value' => $this->configuration['debug'],
    ];

    $this->tokenManager->elementValidate($form);

    return $this->setSettingsParentsRecursively($form);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->hasAnyErrors()) {
      return;
    }

    // Validate data element keys.
    $elements = $this->getWebform()->getElementsInitializedFlattenedAndHasValue();
    $data = Yaml::decode($form_state->getValue('data')) ?: [];
    foreach ($data as $key => $value) {
      if (!isset($elements[$key])) {
        $form_state->setErrorByName('data', $this->t('%key is not valid element key.', ['%key' => $key]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);

    // Cleanup states.
    $this->configuration['states'] = array_values(array_filter($this->configuration['states']));

    // Cast debug.
    $this->configuration['debug'] = (bool) $this->configuration['debug'];
  }

  /**
   * Acts on a saved webform submission before the insert or update hook is invoked.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   * @param bool $update
   *   TRUE if the entity has been updated, or FALSE if it has been inserted.
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();
    if (in_array($state, $this->configuration['states'])) {
      $this->executeAction($webform_submission);
    }
  }

  /****************************************************************************/
  // Action helper methods.
  /****************************************************************************/

  /**
   * Execute this action.
   *
   *  - Get the FillPDF form object
   *  - Merge a FillPDF form object with the Webform Submission values
   *  - save the resulting PDF file to drive
   *  - Update flags or handler config to identify the file location for email consumption
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   */
  protected function executeAction(WebformSubmissionInterface $webform_submission) {
    // Set data -- This is really about modifying the data of the submission for
    // whatever reason BEFORE we go the PDF file.
    if ($this->configuration['data']) {
      $data = Yaml::decode($this->configuration['data']);
      $data = $this->tokenManager->replace($data, $webform_submission);
      foreach ($data as $key => $value) {
        $webform_submission->setElementData($key, $value);
      }
    }

    $id = $webform_submission->id();

    //    @TODO: Get the FillPDF Object
    //          To get the fillpdf object, we can do it in several ways, but likely
    //          the best way to do it, is identify the FillPDF object wanted from
    //          a table selection. Use a radio button to highlight the PDF wanted.
    //    @TODO: Use a simple entity_id int field for now.
    $fillpdf_fid = 22;

    // Create Context
    $context = array (
      'entity_ids' =>
        array (
          'webform_submission' =>
            array (
              $id => "{$id}",
            ),
        ),
      'fid' => "{$fillpdf_fid}",
      'sample' => false,
      'force_download' => false,
      'flatten' => true,
    );

    // Append notes.
//    if ($this->configuration['notes']) {
//      $notes = rtrim($webform_submission->getNotes());
//      $notes .= ($notes ? PHP_EOL . PHP_EOL : '') . $this->tokenManager->replace($this->configuration['notes'], $webform_submission);
//      $webform_submission->setNotes($notes);
//    }

//    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('fillpdf_form');

    $config = $this->configFactory->get('fillpdf.settings');
    $fillpdf_service = $config->get('backend');
    $config_docker = $this->configFactory->get('fillpdf_docker_pdftk.settings');

    // Load the backend plugin.
    /** @var FillPdfBackendPluginInterface $backend */
    $backend = $this->backendManager->createInstance($fillpdf_service, $config_docker->get());

    // convert to FillPdfFormInterface object
    // since we have the object id, we can do a load on that object.

    /** @var FillPdfFormInterface $fillpdf_form */
    $fillpdf_form = FillPdfForm::load($context['fid']);

    if (!$fillpdf_form) {
      // @TODO: Replace this with a watchdog message, as we do not want this to
      //        show up for USERS in the browser.
      drupal_set_message($this->t('FillPDF Form (fid) not found in the system. Please check the value in your FillPDF Link.'), 'error');
    }

    $fields = $this->entityHelper->getFormFields($fillpdf_form);

    $field_mapping = [
      'fields' => [],
      'images' => [],
    ];

    //    Once we have the object, we need to send that into the populateWithFieldData() method.
    $mapped_fields = &$field_mapping['fields'];
    $image_data = &$field_mapping['images'];
    foreach ($fields as $field) {
      $pdf_key = $field->pdf_key->value;

      // Get image fields attached to the entity and derive their token names
      // based on the entity types we are working with at the moment.
      $fill_pattern = count($field->value) ? $field->value->value : '';
      $is_image_token = FALSE;

      /** @var Webform $webform */
      $webform = $webform_submission->getWebform();
      $webform_field_data = array_filter($webform->getElementsInitializedFlattenedAndHasValue(), function ($value) {
        return (!empty($value) && $value['#type'] === 'webform_image_file');
      });
      $submission_values = $webform_submission->getData();
      $data_keys = array_keys($webform_field_data);
      foreach ($data_keys as $webform_field_name) {
        if ($fill_pattern === "[webform_submission:values:{$webform_field_name}]") {
          $webform_image_file = File::load($submission_values[$webform_field_name]);
          if (!$webform_image_file) {
            break;
          }

          $is_image_token = TRUE;
          $this->processImageTokens($webform_image_file, $mapped_fields, $pdf_key, $image_data);
        }
      }

      if (!$is_image_token) {
        $replaced_string = $this->tokenManager->replace($fill_pattern, $webform_submission);

        // Apply field transformations.
        // Replace <br /> occurrences with newlines
        $replaced_string = preg_replace('|<br />|', '
', $replaced_string);

        $form_replacements = FillPdfMappingHelper::parseReplacements($fillpdf_form->replacements->value);
        $field_replacements = FillPdfMappingHelper::parseReplacements($field->replacements->value);

        $replaced_string = FillPdfMappingHelper::transformString($replaced_string, $form_replacements, $field_replacements);

        // Apply prefix and suffix, if applicable
        if (isset($replaced_string) && $replaced_string) {
          if ($field->prefix->value) {
            $replaced_string = $field->prefix->value . $replaced_string;
          }
          if ($field->suffix->value) {
            $replaced_string .= $field->suffix->value;
          }
        }

        $mapped_fields[$pdf_key] = $replaced_string;
      }

    }

    $title_pattern = $fillpdf_form->title->value;
    // Generate the filename of downloaded PDF from title of the PDF set in
    // admin/structure/fillpdf/%fid
    $context['filename'] = $this->buildFilename($title_pattern, $webform_submission);

    $populated_pdf = $backend->populateWithFieldData($fillpdf_form, $field_mapping, $context);

    // @TODO: allow path to be set in the handler

    $destination = file_build_uri('webform/'. $webform->id());
    file_prepare_directory($destination, FILE_CREATE_DIRECTORY);
    // We're going to create a temporary file and save it. We're then going to add
    // this file to a file element as an ID. This will permit the webform to
    // associate the file to a document and use it from one handler to the next.

    /** @var \Drupal\file\Entity\File $file */
    $file = file_save_data($populated_pdf, $destination  . '/' . $context['filename'], FILE_EXISTS_REPLACE);
    $file->setPermanent();
    $file->save();

    // Display message.
    if ($this->configuration['message']) {
      $message = WebformHtmlEditor::checkMarkup(
        $this->tokenManager->replace($this->configuration['message'], $webform_submission)
      );
      $message_type = $this->configuration['message_type'];
      $this->messenger()->addMessage(\Drupal::service('renderer')->renderPlain($message), $message_type);
    }

    $data = $webform_submission->getData();
    $data['pdf'] = $file->id();
    $webform_submission->setData($data);

    // Resave the webform submission without trigger any hooks or handlers.
    $webform_submission->resave();

    // Display debugging information about the current action.
    $this->displayDebug($webform_submission);
  }

  /**
   * Display debugging information about the current action.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   */
  protected function displayDebug(WebformSubmissionInterface $webform_submission) {
    if (!$this->configuration['debug']) {
      return;
    }

    $build = [
      '#type' => 'details',
      '#title' => $this->t('Debug: Action: @title', ['@title' => $this->label()]),
      '#open' => TRUE,
    ];

    $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();
    $build['state'] = [
      '#type' => 'item',
      '#title' => $this->t('State'),
      '#markup' => $state,
      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
    ];

//    $build['notes'] = [
//      '#type' => 'item',
//      '#title' => $this->t('Notes'),
//      '#markup' => $this->configuration['notes'],
//      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
//    ];

    $build['data'] = [
      '#type' => 'item',
      '#title' => $this->t('Data'),
      '#markup' => $this->configuration['notes'] ? '<pre>' . htmlentities($this->configuration['notes']) . '</pre>' : '',
      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
    ];

    $build['message'] = [
      '#type' => 'item',
      '#title' => $this->t('Message'),
      '#markup' => $this->configuration['message'],
      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
    ];

    $build['message_type'] = [
      '#type' => 'item',
      '#title' => $this->t('Message type'),
      '#markup' => $this->configuration['message_type'],
      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
    ];

    $this->messenger()->addWarning(\Drupal::service('renderer')->renderPlain($build), TRUE);
  }

  /**
   * @param $image_file
   * @param $mapped_fields
   * @param $pdf_key
   * @param $image_data
   */
  protected function processImageTokens($image_file, &$mapped_fields, $pdf_key, &$image_data) {
    $image_path = $image_file->getFileUri();
    $mapped_fields[$pdf_key] = "{image}{$image_path}";
    $image_path_info = pathinfo($image_path);
    // Store the image data to transmit to the remote service
    // if necessary
    $file_data = file_get_contents($image_path);
    if ($file_data) {
      $image_data[$pdf_key] = [
        'data' => base64_encode($file_data),
        'filenamehash' => md5($image_path_info['filename']) . '.' . $image_path_info['extension'],
      ];
    }
  }

  /**
   * @param $original
   * @param array $entities
   *
   * @return mixed|null|string|string[]
   */
  protected function buildFilename($original, $entity) {
    $original = $this->tokenManager->replace($original, $entity);

    $output_name = str_replace(' ', '_', $original);
    $output_name = preg_replace('/\.pdf$/i', '', $output_name);
    $output_name = preg_replace('/[^a-zA-Z0-9_.-]+/', '', $output_name) . '.pdf';

    return $output_name;
  }
}