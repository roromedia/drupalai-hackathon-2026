<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Form;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\Core\Url;
use Drupal\ai_content_preparation_wizard\Enum\WizardStep;
use Drupal\ai_content_preparation_wizard\Service\WizardSessionManagerInterface;
use Drupal\ctools\Wizard\FormWizardBase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Content Preparation Wizard form.
 *
 * This wizard orchestrates the 3-step content preparation process:
 * 1. Upload - User uploads source documents
 * 2. Plan - AI generates and user reviews content plan
 * 3. Create - Content is created based on approved plan
 */
class ContentPreparationWizard extends FormWizardBase {

  /**
   * The wizard session manager.
   *
   * @var \Drupal\ai_content_preparation_wizard\Service\WizardSessionManagerInterface
   */
  protected WizardSessionManagerInterface $wizardSessionManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * Constructs a ContentPreparationWizard.
   *
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $tempstore
   *   Tempstore Factory for keeping track of values in each step of the wizard.
   * @param \Drupal\Core\Form\FormBuilderInterface $builder
   *   The Form Builder.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $class_resolver
   *   The class resolver.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param string $tempstore_id
   *   The shared temp store factory collection name.
   * @param string|null $machine_name
   *   The SharedTempStore key for our current wizard values.
   * @param string|null $step
   *   The current active step of the wizard.
   * @param \Drupal\ai_content_preparation_wizard\Service\WizardSessionManagerInterface|null $wizard_session_manager
   *   The wizard session manager.
   * @param \Drupal\Core\Messenger\MessengerInterface|null $messenger
   *   The messenger service.
   */
  public function __construct(
    SharedTempStoreFactory $tempstore,
    FormBuilderInterface $builder,
    ClassResolverInterface $class_resolver,
    EventDispatcherInterface $event_dispatcher,
    RouteMatchInterface $route_match,
    RendererInterface $renderer,
    $tempstore_id,
    $machine_name = NULL,
    $step = NULL,
    ?WizardSessionManagerInterface $wizard_session_manager = NULL,
    ?MessengerInterface $messenger = NULL,
  ) {
    parent::__construct(
      $tempstore,
      $builder,
      $class_resolver,
      $event_dispatcher,
      $route_match,
      $renderer,
      $tempstore_id,
      $machine_name,
      $step
    );

    // Use injected services or fall back to static container for BC.
    $this->wizardSessionManager = $wizard_session_manager ?: \Drupal::service('ai_content_preparation_wizard.wizard_session_manager');
    $this->messenger = $messenger ?: \Drupal::messenger();
  }

  /**
   * {@inheritdoc}
   */
  public static function getParameters() {
    return parent::getParameters() + [
      'wizard_session_manager' => \Drupal::service('ai_content_preparation_wizard.wizard_session_manager'),
      'messenger' => \Drupal::messenger(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getWizardLabel(): string {
    return $this->t('Content Preparation Wizard');
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineLabel(): string {
    return $this->t('Content Preparation');
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations($cached_values): array {
    return [
      'upload' => [
        'form' => Step1UploadForm::class,
        'title' => $this->t('Upload Documents'),
        'submit' => ['::submitUploadStep'],
      ],
      'plan' => [
        'form' => Step2PlanForm::class,
        'title' => $this->t('Review Plan'),
        'submit' => ['::submitPlanStep'],
      ],
      'create' => [
        'form' => Step3CreateForm::class,
        'title' => $this->t('Create Page'),
        'submit' => ['::submitCreateStep'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteName(): string {
    // Use the step route for navigation between steps.
    return 'ai_content_preparation_wizard.wizard.step';
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineName() {
    // Use a consistent machine name for this wizard.
    return $this->machine_name ?: 'ai_content_preparation_wizard';
  }

  /**
   * {@inheritdoc}
   */
  public function getNextParameters($cached_values): array {
    $parameters = parent::getNextParameters($cached_values);
    // Remove machine_name from URL parameters as it's set in route defaults.
    unset($parameters['machine_name']);
    // Remove js parameter for cleaner URLs.
    unset($parameters['js']);
    return $parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function getPreviousParameters($cached_values): array {
    $parameters = parent::getPreviousParameters($cached_values);
    // Remove machine_name from URL parameters as it's set in route defaults.
    unset($parameters['machine_name']);
    // Remove js parameter for cleaner URLs.
    unset($parameters['js']);
    return $parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function initValues(): array {
    // Initialize the wizard session if it doesn't exist.
    $session = $this->wizardSessionManager->getSession();
    if ($session === NULL) {
      $session = $this->wizardSessionManager->createSession();
    }

    return [
      'session_id' => $session->getId(),
      'current_step' => $session->getCurrentStep()->value,
      'uploaded_file_ids' => $session->getUploadedFileIds(),
      'template_id' => $session->getTemplateId(),
      'selected_contexts' => $session->getSelectedContexts(),
      'refinement_instructions' => $session->getRefinementInstructions(),
      'processed_documents' => [],
      'content_plan' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function customizeForm(array $form, FormStateInterface $form_state): array {
    $form = parent::customizeForm($form, $form_state);

    // Attach the wizard library.
    $form['#attached']['library'][] = 'ai_content_preparation_wizard/wizard';

    // Get cached values for step indicator.
    $cached_values = $form_state->getTemporaryValue('wizard');

    // Add custom step indicator.
    $form['#prefix'] = $this->buildStepIndicator($cached_values) . ($form['#prefix'] ?? '');

    // Add wrapper for AJAX updates.
    $form['#prefix'] = '<div id="content-preparation-wizard-wrapper">' . $form['#prefix'];
    $form['#suffix'] = '</div>';

    return $form;
  }

  /**
   * Builds the step indicator markup.
   *
   * @param array $cached_values
   *   The cached wizard values.
   *
   * @return string
   *   The rendered step indicator HTML.
   */
  protected function buildStepIndicator(array $cached_values): string {
    $operations = $this->getOperations($cached_values);
    $currentStep = $this->getStep($cached_values);
    $steps = array_keys($operations);
    $currentIndex = array_search($currentStep, $steps);

    $items = [];
    foreach ($operations as $key => $operation) {
      $index = array_search($key, $steps);
      $stepNumber = $index + 1;

      $classes = ['wizard-step'];
      if ($key === $currentStep) {
        $classes[] = 'wizard-step--active';
      }
      elseif ($index < $currentIndex) {
        $classes[] = 'wizard-step--completed';
      }
      else {
        $classes[] = 'wizard-step--pending';
      }

      $items[] = sprintf(
        '<li class="%s"><span class="wizard-step__number">%d</span><span class="wizard-step__title">%s</span></li>',
        implode(' ', $classes),
        $stepNumber,
        $operation['title']
      );
    }

    // Calculate progress percentage.
    $progress = (($currentIndex + 1) / count($steps)) * 100;

    return sprintf(
      '<div class="wizard-progress">
        <div class="wizard-progress__bar" style="width: %.0f%%"></div>
      </div>
      <ol class="wizard-steps">%s</ol>',
      $progress,
      implode('', $items)
    );
  }

  /**
   * Submit handler for the upload step.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitUploadStep(array &$form, FormStateInterface $form_state): void {
    $cached_values = $form_state->getTemporaryValue('wizard');

    // Sync wizard session with cached values.
    if (!empty($cached_values['uploaded_file_ids'])) {
      $this->wizardSessionManager->setUploadedFileIds($cached_values['uploaded_file_ids']);
    }
    if (!empty($cached_values['template_id'])) {
      $this->wizardSessionManager->setTemplateId($cached_values['template_id']);
    }
    if (!empty($cached_values['selected_contexts'])) {
      $this->wizardSessionManager->setSelectedContexts($cached_values['selected_contexts']);
    }

    // Advance to the plan step.
    $this->wizardSessionManager->setCurrentStep(WizardStep::PLAN);
  }

  /**
   * Submit handler for the plan step.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitPlanStep(array &$form, FormStateInterface $form_state): void {
    $cached_values = $form_state->getTemporaryValue('wizard');

    // Store refinement instructions if provided.
    if (!empty($cached_values['refinement_instructions'])) {
      $this->wizardSessionManager->setRefinementInstructions($cached_values['refinement_instructions']);
    }

    // Advance to the create step.
    $this->wizardSessionManager->setCurrentStep(WizardStep::CREATE);
  }

  /**
   * Submit handler for the create step.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitCreateStep(array &$form, FormStateInterface $form_state): void {
    // This is handled in finish() when the wizard completes.
  }

  /**
   * {@inheritdoc}
   */
  public function finish(array &$form, FormStateInterface $form_state): void {
    $cached_values = $form_state->getTemporaryValue('wizard');

    // Get the session for final processing.
    $session = $this->wizardSessionManager->getSession();

    if ($session !== NULL && $session->hasContentPlan()) {
      // Create the Canvas page based on the approved plan.
      $this->createCanvasPage($cached_values);

      // Display success message.
      $this->messenger->addStatus($this->t('Your content has been created successfully.'));
    }
    else {
      $this->messenger->addError($this->t('Unable to create content. No content plan found.'));
    }

    // Clear the wizard session.
    $this->wizardSessionManager->clearSession();

    // Clear the ctools tempstore.
    parent::finish($form, $form_state);

    // Redirect to the content overview or the created page.
    $form_state->setRedirect('system.admin_content');
  }

  /**
   * Creates a Canvas page based on the approved content plan.
   *
   * @param array $cached_values
   *   The cached wizard values.
   */
  protected function createCanvasPage(array $cached_values): void {
    $session = $this->wizardSessionManager->getSession();

    if ($session === NULL) {
      return;
    }

    $contentPlan = $session->getContentPlan();

    if ($contentPlan === NULL) {
      return;
    }

    // The actual Canvas page creation logic will be implemented here.
    // This involves:
    // 1. Creating a node of the appropriate content type
    // 2. Populating Canvas components based on the content plan sections
    // 3. Setting up the page structure according to the template
    //
    // For now, we'll log the action as this requires integration with
    // the Canvas page builder and AI services.
    \Drupal::logger('ai_content_preparation_wizard')->info(
      'Canvas page creation initiated for session @session_id with plan @plan_id',
      [
        '@session_id' => $session->getId(),
        '@plan_id' => $contentPlan->getId(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(FormInterface $form_object, FormStateInterface $form_state): array {
    $actions = parent::actions($form_object, $form_state);
    $cached_values = $form_state->getTemporaryValue('wizard');
    $step = $this->getStep($cached_values);

    // Customize button labels based on step.
    switch ($step) {
      case 'upload':
        $actions['submit']['#value'] = $this->t('Continue to Plan Review');
        break;

      case 'plan':
        $actions['submit']['#value'] = $this->t('Approve Plan & Continue');
        break;

      case 'create':
        $actions['submit']['#value'] = $this->t('Create Page');
        $actions['submit']['#button_type'] = 'primary';
        break;
    }

    // Add cancel button to all steps.
    $actions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('system.admin_content'),
      '#attributes' => [
        'class' => ['button', 'button--danger'],
      ],
      '#weight' => 100,
    ];

    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function getNextOp(): string {
    return $this->t('Next');
  }

}
