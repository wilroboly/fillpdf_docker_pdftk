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
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform submission action handler.
 *
 * @WebformHandler(
 *   id = "deletion_action",
 *   label = @Translation("Deletion Action"),
 *   category = @Translation("Action"),
 *   description = @Translation("Trigger a delete action on a submission (i.e: file deletion, full submission deletion, specific field obfuscation, etc.)"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class DeletionWebformHandler extends WebformHandlerBase {

  /**
   * The webform token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, WebformTokenManagerInterface $token_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator);
    $this->tokenManager = $token_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
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
      WebformSubmissionInterface::STATE_DELETED => $this->t('Deleted'),
    ];
    $settings['states'] = array_intersect_key($states, array_combine($settings['states'], $settings['states']));

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
      'delete_submission' => NULL,
      'managed_files' => NULL,
      'data' => '',
      'debug' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
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
        WebformSubmissionInterface::STATE_DELETED => $this->t('…when submission is <b>delete</b>.'),
      ],
      '#required' => TRUE,
      '#access' => $results_disabled ? FALSE : TRUE,
      '#default_value' => $results_disabled ? [WebformSubmissionInterface::STATE_COMPLETED] : $this->configuration['states'],
    ];
    $form['trigger']['markup'] = [
      '#markup' => $this->t('<strong>NOTE:</strong> For whatever reason, we\'ve identified that some file element fields in a webform, when the submission is deleted, may cause artifacts to remain. [<em>think a file entity which has no size or actual file, but is identified in the DB as a placeholder for something</em>] Obviously, this is not a desired result of the process. With this Deletion Handler, we attempt to present a work around. If you check the \'<strong>delete</strong>\' option above, upon a submission being deleted, we will run through all file elements and ensure they all references to the files and their corresponding files on the drive are completely removed from the system.'),
    ];

    $form['actions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Actions'),
    ];
    $form['actions']['delete_submission'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Delete the submission'),
      '#description' => $this->t('If checked, the deletion response will be displayed onscreen to all users. This particular action can only be performed on a submission which has the state COMPLETED and is being submitted. UPDATED submissions cannot be deleted POST the COMPLETED state. It would negate the purpose of an UPDATE.'),
      '#return_value' => TRUE,
      '#access' => $results_disabled ? FALSE : TRUE,
      '#default_value' => $results_disabled ? [WebformSubmissionInterface::STATE_COMPLETED] : $this->configuration['delete_submission'],
    ];

    $managed_file_elements = [];
    $elements = $this->getWebform()->getElementsInitializedFlattenedAndHasValue();
    foreach ($elements as $element_key => $element) {
      if (substr($element['#type'], -5, 5) == '_file') {
        $managed_file_elements[$element_key] = (isset($element['#title']) ? $element['#title'] : '');
      }
    }

    $form['actions']['notes'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'text',
      '#title' => $this->t('Append the below text to notes (Plain text)'),
      '#default_value' => $this->configuration['notes'],
    ];
    $form['actions']['data'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Update the below submission data. (YAML)'),
      '#default_value' => $this->configuration['data'],
      '#description' => $this->t('Though this is similar to an ACTION handler, it does simply categorizes your actions a bit more. You could just use the ACTION handler to change or unset values of the submission or webform, but this DELETE handler makes the intention clear.'),
    ];
    $form['actions']['managed_files'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Delete the files associated to the following elements:'),
      '#options' => $managed_file_elements,
      '#access' => $results_disabled ? FALSE : TRUE,
      '#default_value' => $results_disabled ? [WebformSubmissionInterface::STATE_COMPLETED] : $this->configuration['managed_files'],
      '#description' => $this->t('This is going to be pretty cool..'),
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
      '#description' => $this->t('If checked, trigger delete actions will be displayed onscreen to all users.'),
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
   * {@inheritdoc}
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
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   */
  protected function executeAction(WebformSubmissionInterface $webform_submission) {
     if ($this->configuration['managed_files']) {
       $managed_files = $this->configuration['managed_files'];
       foreach ($managed_files as $key => $value) {
         /** @var FileInterface $file */
         $file = File::load($webform_submission->getElementData($key));
         $file->delete();
         $webform_submission->setElementData($key, '');
       }
     }

    // Append notes.
    if ($this->configuration['notes']) {
      $notes = rtrim($webform_submission->getNotes());
      $notes .= ($notes ? PHP_EOL . PHP_EOL : '') . $this->tokenManager->replace($this->configuration['notes'], $webform_submission);
      $webform_submission->setNotes($notes);
    }

    // Set data.
    if ($this->configuration['data']) {
      $data = Yaml::decode($this->configuration['data']);
      $data = $this->tokenManager->replace($data, $webform_submission);
      foreach ($data as $key => $value) {
        $webform_submission->setElementData($key, $value);
      }
    }

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

    $build['notes'] = [
      '#type' => 'item',
      '#title' => $this->t('Notes'),
      '#markup' => $this->configuration['notes'],
      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
    ];

    $build['data'] = [
      '#type' => 'item',
      '#title' => $this->t('Data'),
      '#markup' => $this->configuration['notes'] ? '<pre>' . htmlentities($this->configuration['notes']) . '</pre>' : '',
      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
    ];
    
    $this->messenger()->addWarning(\Drupal::service('renderer')->renderPlain($build), TRUE);
  }

}
