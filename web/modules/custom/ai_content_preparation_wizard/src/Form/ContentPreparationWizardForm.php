<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Form;

use Drupal\ai_content_preparation_wizard\Service\CanvasCreatorInterface;
use Drupal\ai_content_preparation_wizard\Service\ContentPlanGeneratorInterface;
use Drupal\ai_content_preparation_wizard\Service\DocumentProcessingServiceInterface;
use Drupal\ai_content_preparation_wizard\Service\WizardSessionManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Multi-step wizard form for content preparation.
 */
final class ContentPreparationWizardForm extends FormBase {

  /**
   * Constructs a ContentPreparationWizardForm object.
   */
  public function __construct(
    protected WizardSessionManagerInterface $sessionManager,
    protected DocumentProcessingServiceInterface $documentProcessing,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?ContentPlanGeneratorInterface $planGenerator = NULL,
    protected ?CanvasCreatorInterface $canvasCreator = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $planGenerator = NULL;
    $canvasCreator = NULL;

    if ($container->has('ai_content_preparation_wizard.content_plan_generator')) {
      $planGenerator = $container->get('ai_content_preparation_wizard.content_plan_generator');
    }
    if ($container->has('ai_content_preparation_wizard.canvas_creator')) {
      $canvasCreator = $container->get('ai_content_preparation_wizard.canvas_creator');
    }

    return new static(
      $container->get('ai_content_preparation_wizard.wizard_session_manager'),
      $container->get('ai_content_preparation_wizard.document_processing'),
      $container->get('entity_type.manager'),
      $planGenerator,
      $canvasCreator,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_content_preparation_wizard_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Get current step from form state, default to 1.
    $step = $form_state->get('step') ?? 1;
    $form_state->set('step', $step);

    // Enable form tree for proper nested value handling.
    $form['#tree'] = FALSE;

    // Disable autosave for this form as it can interfere with multi-step.
    $form['#autosave_form_disabled'] = TRUE;

    // Attach library.
    $form['#attached']['library'][] = 'ai_content_preparation_wizard/wizard';

    // Build form wrapper for AJAX.
    $form['#prefix'] = '<div id="wizard-form-wrapper">';
    $form['#suffix'] = '</div>';

    // Build step indicator.
    $form['step_indicator'] = $this->buildStepIndicator($step);

    // Build step-specific form.
    switch ($step) {
      case 1:
        $this->buildStep1($form, $form_state);
        break;

      case 2:
        $this->buildStep2($form, $form_state);
        break;

      case 3:
        $this->buildStep3($form, $form_state);
        break;
    }

    return $form;
  }

  /**
   * Builds the step indicator.
   */
  protected function buildStepIndicator(int $currentStep): array {
    $steps = [
      1 => $this->t('Upload Documents'),
      2 => $this->t('Review Plan'),
      3 => $this->t('Create Page'),
    ];

    $items = [];
    foreach ($steps as $stepNum => $label) {
      $class = ['wizard-step'];
      if ($stepNum < $currentStep) {
        $class[] = 'completed';
      }
      elseif ($stepNum === $currentStep) {
        $class[] = 'active';
      }

      $items[] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => $class],
        '#value' => '<span class="step-number">' . $stepNum . '</span> <span class="step-label">' . $label . '</span>',
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard-steps']],
      'steps' => $items,
    ];
  }

  /**
   * Builds Step 1: Document Upload.
   */
  protected function buildStep1(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory()->get('ai_content_preparation_wizard.settings');
    $extensions = $config->get('allowed_extensions') ?? ['txt', 'md', 'docx', 'pdf'];
    $maxSize = $config->get('max_file_size') ?? 10485760;

    $form['step1'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard-step-content']],
    ];

    $form['step1']['documents'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Documents'),
      '#description' => $this->t('Drag and drop files here or click to browse. Supported formats: @formats', [
        '@formats' => implode(', ', $extensions),
      ]),
      '#upload_location' => 'private://ai_content_preparation_wizard',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => implode(' ', $extensions)],
        'FileSizeLimit' => ['fileLimit' => $maxSize],
      ],
      '#multiple' => TRUE,
      '#required' => TRUE,
    ];

    $form['step1']['ai_contexts'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('AI Contexts'),
      '#description' => $this->t('Select contexts to apply during content planning.'),
      '#options' => [
        'site_structure' => $this->t('Site structure'),
        'brand_guidelines' => $this->t('Brand guidelines'),
        'seo_requirements' => $this->t('SEO requirements'),
        'accessibility_standards' => $this->t('Accessibility standards'),
      ],
      '#default_value' => ['site_structure', 'brand_guidelines'],
    ];

    $form['step1']['ai_template'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Template'),
      '#description' => $this->t('Select a template for content generation.'),
      '#options' => [
        '' => $this->t('- Select template -'),
        'general' => $this->t('General content'),
        'blog_post' => $this->t('Blog post'),
        'landing_page' => $this->t('Landing page'),
        'documentation' => $this->t('Documentation'),
      ],
      '#default_value' => '',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#submit' => ['::submitStep1'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'wizard-form-wrapper',
        'disable-refocus' => TRUE,
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Processing documents...'),
        ],
      ],
    ];
  }

  /**
   * Builds Step 2: Plan Review.
   */
  protected function buildStep2(array &$form, FormStateInterface $form_state): void {
    $session = $this->sessionManager->getSession();
    $plan = $session?->getContentPlan();

    $form['step2'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard-step-content']],
    ];

    if ($plan) {
      $form['step2']['plan_preview'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'plan-preview-wrapper', 'class' => ['plan-preview']],
      ];

      $form['step2']['plan_preview']['title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Page Title'),
        '#default_value' => $plan->title,
        '#required' => TRUE,
      ];

      $form['step2']['plan_preview']['summary'] = [
        '#type' => 'item',
        '#title' => $this->t('Summary'),
        '#markup' => '<p>' . $plan->summary . '</p>',
      ];

      $form['step2']['plan_preview']['metadata'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['plan-metadata']],
        'audience' => [
          '#markup' => '<div><strong>' . $this->t('Target Audience:') . '</strong> ' . $plan->targetAudience . '</div>',
        ],
        'read_time' => [
          '#markup' => '<div><strong>' . $this->t('Estimated Read Time:') . '</strong> ' . $plan->estimatedReadTime . ' ' . $this->t('minutes') . '</div>',
        ],
      ];

      // Display sections.
      $sections = [];
      foreach ($plan->sections as $section) {
        $sections[] = [
          '#type' => 'details',
          '#title' => $section->title . ' (' . $section->componentType . ')',
          '#open' => FALSE,
          'content' => [
            '#markup' => '<p>' . substr($section->content, 0, 300) . '...</p>',
          ],
        ];
      }

      $form['step2']['plan_preview']['sections'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Content Sections'),
        'items' => $sections,
      ];

      $form['step2']['refinement'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Refine the Plan'),
        '#description' => $this->t('Enter instructions to modify the content plan.'),
        '#placeholder' => $this->t('Type instructions to adjust the plan...'),
        '#rows' => 3,
      ];

      $form['step2']['regenerate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Regenerate Plan'),
        '#submit' => ['::regeneratePlan'],
        '#ajax' => [
          'callback' => '::ajaxCallback',
          'wrapper' => 'wizard-form-wrapper',
          'disable-refocus' => TRUE,
          'progress' => [
            'type' => 'throbber',
            'message' => $this->t('Regenerating plan...'),
          ],
        ],
        '#limit_validation_errors' => [['refinement']],
      ];
    }
    else {
      $form['step2']['no_plan'] = [
        '#markup' => '<p>' . $this->t('No content plan available. Please go back and upload documents.') . '</p>',
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::goBack'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'wizard-form-wrapper',
        'disable-refocus' => TRUE,
      ],
      '#limit_validation_errors' => [],
    ];

    $form['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#submit' => ['::submitStep2'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'wizard-form-wrapper',
        'disable-refocus' => TRUE,
      ],
      '#limit_validation_errors' => [],
    ];
  }

  /**
   * Builds Step 3: Create Page.
   */
  protected function buildStep3(array &$form, FormStateInterface $form_state): void {
    $session = $this->sessionManager->getSession();
    $plan = $session?->getContentPlan();

    $form['step3'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard-step-content']],
    ];

    if ($plan) {
      $form['step3']['preview'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Final Preview'),
      ];

      $form['step3']['preview']['title'] = [
        '#markup' => '<h3>' . $plan->title . '</h3>',
      ];

      $form['step3']['preview']['summary'] = [
        '#markup' => '<p><em>' . $plan->summary . '</em></p>',
      ];

      $form['step3']['preview']['sections_count'] = [
        '#markup' => '<p>' . $this->t('@count sections will be created.', ['@count' => count($plan->sections)]) . '</p>',
      ];

      $form['step3']['page_settings'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Page Settings'),
      ];

      $form['step3']['page_settings']['page_title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Page Title'),
        '#default_value' => $plan->title,
        '#required' => TRUE,
      ];

      $form['step3']['page_settings']['url_alias'] = [
        '#type' => 'textfield',
        '#title' => $this->t('URL Alias'),
        '#description' => $this->t('Leave empty for automatic alias generation.'),
        '#default_value' => '',
      ];

      $form['step3']['page_settings']['status'] = [
        '#type' => 'select',
        '#title' => $this->t('Page Status'),
        '#options' => [
          0 => $this->t('Draft'),
          1 => $this->t('Published'),
        ],
        '#default_value' => 0,
      ];
    }
    else {
      $form['step3']['error'] = [
        '#markup' => '<p>' . $this->t('No content plan available.') . '</p>',
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::goBack'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'wizard-form-wrapper',
        'disable-refocus' => TRUE,
      ],
      '#limit_validation_errors' => [],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Canvas Page Now'),
      '#button_type' => 'primary',
    ];
  }

  /**
   * AJAX callback.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state): array {
    // Add status messages to the form.
    $form['messages'] = [
      '#type' => 'status_messages',
      '#weight' => -100,
    ];

    return $form;
  }

  /**
   * Submit handler for Step 1.
   */
  public function submitStep1(array &$form, FormStateInterface $form_state): void {
    // Get or create session.
    $session = $this->sessionManager->getOrCreateSession();

    // Store uploaded file IDs.
    $fileIds = $form_state->getValue('documents') ?? [];
    $session->setUploadedFileIds(array_filter($fileIds));

    // Store AI contexts.
    $contexts = array_filter($form_state->getValue('ai_contexts') ?? []);
    $session->setSelectedContexts(array_values($contexts));

    // Store template.
    $template = $form_state->getValue('ai_template');
    if ($template) {
      $session->setTemplateId($template);
    }

    // Clear existing documents and process new ones.
    $session->clearProcessedDocuments();
    $processedDocs = [];
    foreach ($fileIds as $fileId) {
      if ($fileId) {
        $file = $this->entityTypeManager->getStorage('file')->load($fileId);
        if ($file) {
          try {
            $processed = $this->documentProcessing->process($file);
            $session->addProcessedDocument($processed);
            $processedDocs[] = $processed;
          }
          catch (\Exception $e) {
            $this->messenger()->addError($this->t('Failed to process @file: @error', [
              '@file' => $file->getFilename(),
              '@error' => $e->getMessage(),
            ]));
          }
        }
      }
    }

    // Generate content plan if we have processed documents.
    if (!empty($processedDocs) && $this->planGenerator) {
      try {
        $plan = $this->planGenerator->generate($processedDocs, $contexts, $template);
        $session->setContentPlan($plan);
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Failed to generate content plan: @error', [
          '@error' => $e->getMessage(),
        ]));
      }
    }

    $this->sessionManager->updateSession($session);

    // Go to step 2.
    $form_state->set('step', 2);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for Step 2.
   */
  public function submitStep2(array &$form, FormStateInterface $form_state): void {
    $session = $this->sessionManager->getSession();
    if ($session) {
      $plan = $session->getContentPlan();
      if ($plan) {
        // Update title if changed.
        $newTitle = $form_state->getValue('title');
        if ($newTitle && $newTitle !== $plan->title) {
          $plan = $plan->withTitle($newTitle);
          $session->setContentPlan($plan);
          $this->sessionManager->updateSession($session);
        }
      }
    }

    // Go to step 3.
    $form_state->set('step', 3);
    $form_state->setRebuild();
  }

  /**
   * Submit handler to regenerate plan.
   */
  public function regeneratePlan(array &$form, FormStateInterface $form_state): void {
    $refinement = $form_state->getValue('refinement');
    if (!$refinement || !$this->planGenerator) {
      $this->messenger()->addWarning($this->t('Please enter refinement instructions.'));
      $form_state->setRebuild();
      return;
    }

    $session = $this->sessionManager->getSession();
    if (!$session) {
      $this->messenger()->addError($this->t('No active session found.'));
      $form_state->setRebuild();
      return;
    }

    $plan = $session->getContentPlan();
    if (!$plan) {
      $this->messenger()->addError($this->t('No plan to refine.'));
      $form_state->setRebuild();
      return;
    }

    try {
      $refinedPlan = $this->planGenerator->refine($plan, $refinement);
      $session->setContentPlan($refinedPlan);
      $this->sessionManager->updateSession($session);
      $this->messenger()->addStatus($this->t('Plan has been updated.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to refine plan: @error', [
        '@error' => $e->getMessage(),
      ]));
    }

    $form_state->setRebuild();
  }

  /**
   * Submit handler to go back.
   */
  public function goBack(array &$form, FormStateInterface $form_state): void {
    $currentStep = $form_state->get('step') ?? 1;
    if ($currentStep > 1) {
      $form_state->set('step', $currentStep - 1);
    }
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Get triggering element to determine context.
    $trigger = $form_state->getTriggeringElement();
    $triggerName = $trigger['#name'] ?? '';

    // Skip validation for back buttons.
    if (str_contains($triggerName, 'back')) {
      return;
    }

    $step = $form_state->get('step') ?? 1;

    // Only validate step 1 fields when on step 1.
    if ($step === 1 && str_contains($triggerName, 'next')) {
      $documents = $form_state->getValue('documents');
      if (empty(array_filter($documents ?? []))) {
        $form_state->setErrorByName('documents', $this->t('Please upload at least one document.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $session = $this->sessionManager->getSession();
    if (!$session) {
      $this->messenger()->addError($this->t('No active session.'));
      return;
    }

    $plan = $session->getContentPlan();
    if (!$plan) {
      $this->messenger()->addError($this->t('No content plan available.'));
      return;
    }

    if (!$this->canvasCreator) {
      $this->messenger()->addError($this->t('Canvas creator service not available.'));
      return;
    }

    try {
      $options = [
        'title' => $form_state->getValue('page_title') ?? $plan->title,
        'alias' => $form_state->getValue('url_alias') ?: NULL,
        'status' => (bool) $form_state->getValue('status'),
      ];

      $page = $this->canvasCreator->create($plan, $options);

      $this->sessionManager->clearSession();

      $this->messenger()->addStatus($this->t('Canvas page "@title" has been created successfully.', [
        '@title' => $page->label(),
      ]));

      // Redirect to the created page.
      $form_state->setRedirectUrl($page->toUrl());
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to create Canvas page: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

}
