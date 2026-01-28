<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Form;

use Drupal\ai_content_preparation_wizard\Service\CanvasCreatorInterface;
use Drupal\ai_content_preparation_wizard\Service\ContentPlanGeneratorInterface;
use Drupal\ai_content_preparation_wizard\Service\DocumentProcessingServiceInterface;
use Drupal\ai_content_preparation_wizard\Service\WebpageProcessorInterface;
use Drupal\ai_content_preparation_wizard\Service\WizardSessionManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Multi-step wizard form for content preparation.
 */
final class ContentPreparationWizardForm extends FormBase {

  /**
   * The Canvas AI page builder helper service.
   *
   * @var object|null
   */
  protected $pageBuilderHelper = NULL;

  /**
   * The webpage processor service.
   *
   * @var \Drupal\ai_content_preparation_wizard\Service\WebpageProcessorInterface|null
   */
  protected ?WebpageProcessorInterface $webpageProcessor = NULL;

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager|null
   */
  protected $aiProviderManager = NULL;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|null
   */
  protected $configFactory = NULL;

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

    $instance = new static(
      $container->get('ai_content_preparation_wizard.wizard_session_manager'),
      $container->get('ai_content_preparation_wizard.document_processing'),
      $container->get('entity_type.manager'),
      $planGenerator,
      $canvasCreator,
    );

    // Inject the page builder helper if canvas_ai module is available.
    if ($container->has('canvas_ai.page_builder_helper')) {
      $instance->pageBuilderHelper = $container->get('canvas_ai.page_builder_helper');
    }

    // Inject the webpage processor service if available.
    if ($container->has('ai_content_preparation_wizard.webpage_processor')) {
      $instance->webpageProcessor = $container->get('ai_content_preparation_wizard.webpage_processor');
    }

    // Inject AI provider manager and config factory for model info display.
    if ($container->has('ai.provider')) {
      $instance->aiProviderManager = $container->get('ai.provider');
    }
    $instance->configFactory = $container->get('config.factory');

    return $instance;
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
    // Get current step from form state, with fallbacks.
    $step = $form_state->get('step');
    if ($step === NULL) {
      // Try user input first (most reliable across rebuilds).
      $input = $form_state->getUserInput();
      $step = isset($input['wizard_step']) ? (int) $input['wizard_step'] : NULL;
    }
    if ($step === NULL) {
      // Finally try form values.
      $step = (int) ($form_state->getValue('wizard_step') ?? 1);
    }
    $form_state->set('step', $step);

    // Enable form tree for proper nested value handling.
    $form['#tree'] = FALSE;

    // Disable autosave for this form as it can interfere with multi-step.
    $form['#autosave_form_disabled'] = TRUE;

    // Attach library.
    $form['#attached']['library'][] = 'ai_content_preparation_wizard/wizard';

    // Build form wrapper for AJAX with main container class.
    $form['#prefix'] = '<div id="wizard-form-wrapper" class="content-preparation-wizard">';
    $form['#suffix'] = '</div>';

    // Build step indicator.
    $form['step_indicator'] = $this->buildStepIndicator($step);

    // Hidden field to preserve step across AJAX requests.
    // This acts as a backup if form state cache is invalidated.
    $form['wizard_step'] = [
      '#type' => 'hidden',
      '#value' => $step,
    ];

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
      '#required' => FALSE,
    ];

    // Webpage URLs input field.
    $form['step1']['webpage_urls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Web Page URLs'),
      '#description' => $this->t('Enter webpage URLs to extract content from (one URL per line). The content will be fetched and converted to markdown.'),
      '#placeholder' => "https://example.com/page1\nhttps://example.com/page2",
      '#rows' => 4,
      '#required' => FALSE,
    ];

    $form['step1']['content_source_note'] = [
      '#markup' => '<p class="form-item__description">' . $this->t('You must provide at least one document upload OR one webpage URL to proceed.') . '</p>',
    ];

    // Load AI context entities dynamically.
    $contextOptions = [];
    try {
      $contextStorage = $this->entityTypeManager->getStorage('ai_context');
      $contexts = $contextStorage->loadMultiple();
      foreach ($contexts as $context) {
        $contextOptions[$context->id()] = $context->label();
      }
    }
    catch (\Exception $e) {
      // Fallback if ai_context module is not available.
      $this->messenger()->addWarning($this->t('AI Contexts could not be loaded.'));
    }

    $form['step1']['ai_contexts'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('AI Contexts'),
      '#description' => $this->t('Select contexts to apply during content planning.'),
      '#options' => $contextOptions,
      '#default_value' => [],
      '#access' => !empty($contextOptions),
    ];

    // Load Canvas pages for template selection.
    $canvasPageOptions = $this->getCanvasPageOptions();
    $form['step1']['canvas_page'] = [
      '#type' => 'select',
      '#title' => $this->t('Canvas Page Template'),
      '#description' => $this->t('Select an existing Canvas page to use as a template for the structure.'),
      '#options' => ['' => $this->t('- Select Canvas page -')] + $canvasPageOptions,
      '#default_value' => '',
      '#access' => !empty($canvasPageOptions),
    ];

    // Show message if no Canvas pages available.
    if (empty($canvasPageOptions)) {
      $form['step1']['no_canvas_pages'] = [
        '#markup' => '<p class="messages messages--warning">' . $this->t('No Canvas pages available. Create a Canvas page first to use as a template.') . '</p>',
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['next'] = [
      '#type' => 'submit',
      '#name' => 'next_step1',
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
    $processedDocs = $session?->getProcessedDocuments() ?? [];
    $documentErrors = [];

    // Process uploaded documents if not already processed.
    // This is done async in buildStep2 (like webpages) for better UX.
    $uploadedFileIds = $session?->getUploadedFileIds() ?? [];
    if (!empty($uploadedFileIds) && empty($processedDocs)) {
      foreach ($uploadedFileIds as $fileId) {
        if (!$fileId) {
          continue;
        }
        $file = $this->entityTypeManager->getStorage('file')->load($fileId);
        if ($file) {
          try {
            $processed = $this->documentProcessing->process($file);
            $session->addProcessedDocument($processed);
          }
          catch (\Exception $e) {
            $documentErrors[$file->getFilename()] = $e->getMessage();
          }
        }
      }
      // Update session with processed documents.
      if (!empty($session->getProcessedDocuments())) {
        $this->sessionManager->updateSession($session);
        $processedDocs = $session->getProcessedDocuments();
      }
    }

    // Process webpage URLs if not already processed.
    $webpageUrls = $session?->getWebpageUrls() ?? [];
    $processedWebpages = [];
    $webpageErrors = [];

    if (!empty($webpageUrls) && $this->webpageProcessor !== NULL) {
      // Check which URLs are already processed (stored in session documents).
      $alreadyProcessedUrls = [];
      foreach ($processedDocs as $doc) {
        $sourceUrl = $doc->metadata->customProperties['source_url'] ?? NULL;
        if ($sourceUrl !== NULL) {
          $alreadyProcessedUrls[$sourceUrl] = TRUE;
        }
      }

      // Only process URLs that haven't been processed yet.
      foreach ($webpageUrls as $url) {
        if (isset($alreadyProcessedUrls[$url])) {
          // Already processed, skip.
          continue;
        }

        try {
          $processedWebpage = $this->webpageProcessor->processUrl($url);
          $processedWebpages[$url] = $processedWebpage;
          // Add to session's processed webpages for plan generation.
          $session->addProcessedWebpage($processedWebpage);
        }
        catch (\Exception $e) {
          $webpageErrors[$url] = $e->getMessage();
        }
      }
      // Update session if we processed any new webpages.
      if (!empty($processedWebpages)) {
        // Clear and regenerate content plan to include new webpage content.
        $session->clearContentPlan();
        $this->sessionManager->updateSession($session);
      }
    }

    // Get all processed webpages from session for preview display.
    $sessionWebpages = $session?->getProcessedWebpages() ?? [];

    // Merge documents and webpages for preview display.
    // The preview function will separate them by type.
    $allProcessedContent = array_merge($processedDocs, array_values($sessionWebpages));

    $form['step2'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard-step-content']],
    ];

    // Display any document processing errors.
    if (!empty($documentErrors)) {
      $form['step2']['document_errors'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
      ];
      foreach ($documentErrors as $filename => $error) {
        $form['step2']['document_errors']['error_' . md5($filename)] = [
          '#markup' => '<p>' . $this->t('Failed to process document @file: @error', [
            '@file' => $filename,
            '@error' => $error,
          ]) . '</p>',
        ];
      }
    }

    // Display any webpage processing errors.
    if (!empty($webpageErrors)) {
      $form['step2']['webpage_errors'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
      ];
      foreach ($webpageErrors as $url => $error) {
        $form['step2']['webpage_errors']['error_' . md5($url)] = [
          '#markup' => '<p>' . $this->t('Failed to process webpage @url: @error', [
            '@url' => $url,
            '@error' => $error,
          ]) . '</p>',
        ];
      }
    }

    // Check if we need to generate the plan asynchronously.
    // Both documents and webpages are processed in buildStep2 for consistent async UX.
    $hasContentSources = !empty($processedDocs) || !empty($webpageUrls) || !empty($uploadedFileIds);
    $needsAsyncGeneration = empty($plan) && $hasContentSources;

    if ($needsAsyncGeneration) {
      // Pass endpoint URL for async plan generation.
      // The async-plan.js is already loaded via the main wizard library.
      $form['#attached']['drupalSettings']['aiContentPreparationWizard'] = [
        'asyncPlanEndpoint' => '/admin/content/preparation-wizard/generate-plan',
      ];
    }

    if ($plan || $needsAsyncGeneration) {
      // Split layout container.
      $form['step2']['split_layout'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['step2-split-layout']],
      ];

      // Left panel: Markdown Preview with Tabbed Navigation.
      $form['step2']['split_layout']['markdown_panel'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['markdown-preview-panel']],
      ];

      $form['step2']['split_layout']['markdown_panel']['header'] = [
        '#markup' => '<div class="markdown-preview-header">' . $this->t('Source Content Preview') . '</div>',
      ];

      // Build tabbed document preview (includes both documents and webpages).
      $this->buildTabbedDocumentPreview($form['step2']['split_layout']['markdown_panel'], $allProcessedContent, $webpageUrls);

      // Right panel: Plan Review.
      $form['step2']['split_layout']['plan_panel'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['plan-review-panel']],
      ];

      $form['step2']['split_layout']['plan_panel']['plan_preview'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'plan-preview-wrapper', 'class' => ['plan-preview']],
      ];

      if ($needsAsyncGeneration) {
        // Show loading state while plan generates asynchronously.
        $form['step2']['split_layout']['plan_panel']['plan_preview']['loading'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['plan-loading-container'],
            'id' => 'plan-loading',
          ],
          'spinner' => [
            '#markup' => '<div class="plan-loading-spinner"></div>',
          ],
          'message' => [
            '#markup' => '<div class="plan-loading-message">' . $this->t('Generating content plan with AI...') . '</div>',
          ],
          'model_info' => [
            '#markup' => '<div class="plan-loading-model"><strong>' . $this->t('Using:') . '</strong> ' . $this->getAiModelInfo() . '</div>',
          ],
          'status' => [
            '#markup' => '<div class="plan-loading-status" id="plan-loading-status">' . $this->t('Analyzing documents and creating sections...') . '</div>',
          ],
        ];

        // Hidden title field that will be populated by JS.
        $form['step2']['split_layout']['plan_panel']['plan_preview']['title'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Page Title'),
          '#default_value' => '',
          '#required' => TRUE,
          '#attributes' => ['id' => 'edit-title-async', 'style' => 'display:none;'],
          '#title_display' => 'invisible',
        ];

        // Container for dynamically loaded plan content.
        $form['step2']['split_layout']['plan_panel']['plan_preview']['async_content'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'plan-async-content',
            'style' => 'display:none;',
          ],
        ];

        // Sections container will be populated by JavaScript.
        $form['step2']['split_layout']['plan_panel']['plan_preview']['sections'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['plan-sections-list'],
            'id' => 'plan-sections-container',
          ],
          '#tree' => TRUE,
        ];
      }
      else {
        // Plan already exists - render normally.
        $form['step2']['split_layout']['plan_panel']['plan_preview']['title'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Page Title'),
          '#default_value' => $plan->title,
          '#required' => TRUE,
        ];

        $form['step2']['split_layout']['plan_panel']['plan_preview']['summary'] = [
          '#type' => 'item',
          '#title' => $this->t('Summary'),
          '#markup' => '<p>' . $plan->summary . '</p>',
        ];

        $form['step2']['split_layout']['plan_panel']['plan_preview']['metadata'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['plan-metadata']],
          'audience' => [
            '#markup' => '<div><strong>' . $this->t('Target Audience:') . '</strong> ' . $plan->targetAudience . '</div>',
          ],
          'read_time' => [
            '#markup' => '<div><strong>' . $this->t('Estimated Read Time:') . '</strong> ' . $plan->estimatedReadTime . ' ' . $this->t('minutes') . '</div>',
          ],
          'ai_model' => [
            '#markup' => '<div><strong>' . $this->t('AI Model:') . '</strong> ' . $this->getAiModelInfo() . '</div>',
          ],
        ];

        // Get available component options.
        $componentOptions = $this->getAvailableComponentOptions($session);

        // Display sections (skip empty ones).
        $form['step2']['split_layout']['plan_panel']['plan_preview']['sections'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['plan-sections-list']],
          '#tree' => TRUE,
        ];

        foreach ($plan->sections as $index => $section) {
          // Skip sections with empty content.
          $content = trim($section->content ?? '');
          if (empty($content)) {
            continue;
          }

          $sectionId = $section->id ?? 'section_' . $index;

          $form['step2']['split_layout']['plan_panel']['plan_preview']['sections'][$sectionId] = [
            '#type' => 'details',
            '#title' => $section->title,
            '#open' => FALSE,
            '#attributes' => ['class' => ['plan-section-item']],
          ];

          // Component type dropdown.
          $form['step2']['split_layout']['plan_panel']['plan_preview']['sections'][$sectionId]['component_type'] = [
            '#type' => 'select',
            '#title' => $this->t('Component Type'),
            '#options' => $componentOptions,
            '#default_value' => $section->componentType,
            '#attributes' => ['class' => ['section-component-select']],
          ];

          // Section content - editable textarea.
          $form['step2']['split_layout']['plan_panel']['plan_preview']['sections'][$sectionId]['content'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Section Content'),
            '#default_value' => $content,
            '#rows' => 6,
            '#attributes' => ['class' => ['section-content-textarea']],
          ];

          // Store original index for submit handler.
          $form['step2']['split_layout']['plan_panel']['plan_preview']['sections'][$sectionId]['original_index'] = [
            '#type' => 'hidden',
            '#value' => $index,
          ];
        }
      }

      // Refinement section (always visible, but disabled during async loading).
      $form['step2']['split_layout']['plan_panel']['refinement_section'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['refinement-section'],
          'id' => 'refinement-section',
        ],
      ];

      $form['step2']['split_layout']['plan_panel']['refinement_section']['refinement'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Refine the Plan'),
        '#description' => $this->t('Enter instructions to modify the content plan.'),
        '#placeholder' => $this->t('Type instructions to adjust the plan...'),
        '#rows' => 3,
        '#disabled' => $needsAsyncGeneration,
        '#name' => 'refinement',
        '#attributes' => [
          'id' => 'edit-refinement-textarea',
        ],
      ];

      // Use a regular button that triggers JavaScript instead of Drupal AJAX.
      // This bypasses form state issues and uses the dedicated AJAX endpoint.
      $form['step2']['split_layout']['plan_panel']['refinement_section']['regenerate'] = [
        '#type' => 'button',
        '#value' => $this->t('Regenerate Plan'),
        '#disabled' => $needsAsyncGeneration,
        '#attributes' => [
          'id' => 'edit-regenerate-plan',
          'type' => 'button',
          'onclick' => 'return false;',
        ],
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
      '#name' => 'next_step2',
      '#value' => $this->t('Next'),
      '#submit' => ['::submitStep2'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'wizard-form-wrapper',
        'disable-refocus' => TRUE,
      ],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'id' => 'edit-next-step2',
        'data-async-hide' => $needsAsyncGeneration ? 'true' : 'false',
      ],
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

      // Show template information if one was selected.
      $templateId = $session->getTemplateId();
      if (!empty($templateId)) {
        $templateInfo = $this->getTemplateInfo($templateId);
        if ($templateInfo) {
          $form['step3']['preview']['template_info'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['template-info', 'messages', 'messages--status']],
            'label' => [
              '#markup' => '<strong>' . $this->t('Template:') . '</strong> ' . $templateInfo['label'],
            ],
            'description' => [
              '#markup' => '<br><small>' . $this->t('The new page will be created by cloning this template and filling its components with your content.') . '</small>',
            ],
          ];
        }
      }
      else {
        $form['step3']['preview']['no_template_info'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['template-info', 'messages', 'messages--warning']],
          'label' => [
            '#markup' => '<strong>' . $this->t('No template selected') . '</strong>',
          ],
          'description' => [
            '#markup' => '<br><small>' . $this->t('A new page will be created with components generated from your content sections.') . '</small>',
          ],
        ];
      }

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
      '#name' => 'op',
      '#value' => $this->t('Create Canvas Page Now'),
      '#button_type' => 'primary',
    ];
  }

  /**
   * Builds a tabbed document preview with proper markdown rendering.
   *
   * @param array &$container
   *   The form container to add elements to.
   * @param array $processedDocs
   *   Array of ProcessedDocument objects.
   * @param array $webpageUrls
   *   Array of webpage URLs that were processed (for display purposes).
   */
  protected function buildTabbedDocumentPreview(array &$container, array $processedDocs, array $webpageUrls = []): void {
    // Check if we have any content sources.
    if (empty($processedDocs) && empty($webpageUrls)) {
      $container['content'] = [
        '#markup' => '<div class="markdown-preview-content"><em>' . $this->t('No content available for preview.') . '</em></div>',
      ];
      return;
    }

    // If we have URLs but no processed docs yet, show loading state.
    if (empty($processedDocs) && !empty($webpageUrls)) {
      $container['content'] = [
        '#markup' => '<div class="markdown-preview-content"><em>' . $this->t('Processing webpage content...') . '</em></div>',
      ];
      return;
    }

    // Separate documents from webpages based on type.
    // ProcessedWebpage has 'url' property, ProcessedDocument has 'fileName'.
    $documents = [];
    $webpages = [];
    foreach ($processedDocs as $doc) {
      if (!isset($doc->markdownContent)) {
        continue;
      }
      // Check if this is a ProcessedWebpage (has 'url' property) or ProcessedDocument.
      if ($doc instanceof \Drupal\ai_content_preparation_wizard\Model\ProcessedWebpage) {
        $webpages[] = $doc;
      }
      elseif ($doc instanceof \Drupal\ai_content_preparation_wizard\Model\ProcessedDocument) {
        // ProcessedDocument could still be a webpage (from older code).
        $providerValue = '';
        if (isset($doc->provider)) {
          $providerValue = is_object($doc->provider) ? ($doc->provider->value ?? '') : (string) $doc->provider;
        }
        $isWebpage = $providerValue === 'webpage' ||
                     isset($doc->metadata->customProperties['source_url']);
        if ($isWebpage) {
          $webpages[] = $doc;
        }
        else {
          $documents[] = $doc;
        }
      }
    }

    $hasBothTypes = !empty($documents) && !empty($webpages);

    // Use Drupal native vertical_tabs for document switching.
    // Set weight to ensure it appears after any section headers.
    $container['document_tabs'] = [
      '#type' => 'vertical_tabs',
      '#default_tab' => 'edit-doc-0',
      '#weight' => 0,
    ];

    $tabIndex = 0;
    $docIndex = 0;
    $webpageIndex = 0;

    // Add uploaded documents.
    foreach ($documents as $doc) {
      $fileName = $doc->fileName ?? $this->t('Document @num', ['@num' => $docIndex + 1]);
      $tabKey = 'doc_' . $tabIndex;

      // Create a details element for each document (becomes a vertical tab).
      // The data attribute helps with CSS styling of the vertical tabs menu.
      $container[$tabKey] = [
        '#type' => 'details',
        '#title' => $fileName,
        '#group' => 'document_tabs',
        '#attributes' => [
          'class' => ['document-tab-panel', 'document-type-file'],
          'data-content-type' => 'document',
        ],
        '#weight' => $tabIndex,
      ];

      // Add document type indicator badge.
      $container[$tabKey]['type_indicator'] = [
        '#markup' => '<div class="content-type-badge content-type-document">' . $this->t('Document') . '</div>',
      ];

      // Render markdown content inside the tab.
      $renderedMarkdown = $this->renderMarkdownToHtml($doc->markdownContent);
      $container[$tabKey]['content'] = [
        '#markup' => '<div class="markdown-preview-content">' . $renderedMarkdown . '</div>',
      ];

      $tabIndex++;
      $docIndex++;
    }

    // Add processed webpages.
    foreach ($webpages as $doc) {
      // Handle both ProcessedWebpage and legacy ProcessedDocument with WEBPAGE type.
      if ($doc instanceof \Drupal\ai_content_preparation_wizard\Model\ProcessedWebpage) {
        $sourceUrl = $doc->url;
        $pageTitle = $doc->title ?: $this->t('Webpage @num', ['@num' => $webpageIndex + 1]);
      }
      else {
        // Legacy ProcessedDocument with WEBPAGE type.
        $sourceUrl = $doc->metadata->customProperties['source_url'] ?? '';
        $pageTitle = $doc->metadata->title ?? $doc->fileName ?? $this->t('Webpage @num', ['@num' => $webpageIndex + 1]);
      }
      $tabKey = 'webpage_' . $tabIndex;

      // Create a details element for each webpage (becomes a vertical tab).
      // The data attribute helps with CSS styling of the vertical tabs menu.
      $container[$tabKey] = [
        '#type' => 'details',
        '#title' => $pageTitle,
        '#group' => 'document_tabs',
        '#attributes' => [
          'class' => ['document-tab-panel', 'document-type-webpage'],
          'data-content-type' => 'webpage',
        ],
        '#weight' => 100 + $tabIndex,
      ];

      // Add webpage type indicator badge.
      $container[$tabKey]['type_indicator'] = [
        '#markup' => '<div class="content-type-badge content-type-webpage">' . $this->t('Web Page') . '</div>',
      ];

      // Show source URL.
      if (!empty($sourceUrl)) {
        $container[$tabKey]['source_url'] = [
          '#markup' => '<div class="webpage-source-url"><strong>' . $this->t('Source:') . '</strong> <a href="' . Html::escape($sourceUrl) . '" target="_blank" rel="noopener">' . Html::escape($sourceUrl) . '</a></div>',
        ];
      }

      // Render markdown content inside the tab.
      $renderedMarkdown = $this->renderMarkdownToHtml($doc->markdownContent);
      $container[$tabKey]['content'] = [
        '#markup' => '<div class="markdown-preview-content webpage-content">' . $renderedMarkdown . '</div>',
      ];

      $tabIndex++;
      $webpageIndex++;
    }
  }

  /**
   * Renders markdown content to sanitized HTML.
   *
   * @param string $markdown
   *   The raw markdown content.
   *
   * @return string
   *   Sanitized HTML output.
   */
  protected function renderMarkdownToHtml(string $markdown): string {
    // Check if CommonMark library is available.
    if (!class_exists(CommonMarkConverter::class)) {
      // Fallback: escape and preserve whitespace.
      return '<pre class="markdown-fallback">' . Html::escape($markdown) . '</pre>';
    }

    try {
      // Create converter with safe defaults.
      $converter = new CommonMarkConverter([
        'html_input' => 'strip',
        'allow_unsafe_links' => FALSE,
      ]);

      // Convert markdown to HTML.
      $html = (string) $converter->convert($markdown);

      // Define safe HTML tags for markdown output.
      $safeTags = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'code', 'dd', 'del', 'div', 'dl', 'dt',
        'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins',
        'li', 'ol', 'p', 'pre', 'span', 'strong', 'sub', 'sup', 'table',
        'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
      ];

      // Sanitize and return.
      return Xss::filter($html, $safeTags);
    }
    catch (\Exception $e) {
      // Fallback on error: escape content.
      return '<pre class="markdown-fallback">' . Html::escape($markdown) . '</pre>';
    }
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

    // Store webpage URLs (parsed in validation, with fallback).
    $webpageUrls = $form_state->get('parsed_webpage_urls') ?? [];

    // Fallback: parse URLs directly from form value if not already parsed.
    if (empty($webpageUrls)) {
      $webpageUrlsRaw = trim($form_state->getValue('webpage_urls') ?? '');
      if (!empty($webpageUrlsRaw)) {
        $lines = preg_split('/\r\n|\r|\n/', $webpageUrlsRaw);
        foreach ($lines as $line) {
          $url = trim($line);
          if (!empty($url)) {
            $webpageUrls[] = $url;
          }
        }
      }
    }

    $session->setWebpageUrls($webpageUrls);

    // Store AI contexts.
    $contexts = array_filter($form_state->getValue('ai_contexts') ?? []);
    $session->setSelectedContexts(array_values($contexts));

    // Store Canvas page template.
    $canvasPageId = $form_state->getValue('canvas_page');
    if ($canvasPageId) {
      $session->setTemplateId($canvasPageId);
    }

    // Clear existing documents and plan for fresh generation.
    // Documents and webpages are processed async in buildStep2 for better UX.
    $session->clearProcessedDocuments();
    $session->clearContentPlan();

    // Note: Document processing is now deferred to buildStep2 along with
    // webpage processing, to provide a consistent async loading experience.
    // The file IDs are already stored in session above.
    $totalSources = count($fileIds) + count($webpageUrls);
    if ($totalSources > 0) {
      $this->messenger()->addStatus($this->formatPlural(
        $totalSources,
        '1 content source will be processed.',
        '@count content sources will be processed.'
      ));
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
        $planUpdated = FALSE;

        // Update title if changed.
        $newTitle = $form_state->getValue('title');
        if ($newTitle && $newTitle !== $plan->title) {
          $plan = $plan->withTitle($newTitle);
          $planUpdated = TRUE;
        }

        // Update section component types and content if changed.
        $sectionsData = $form_state->getValue('sections') ?? [];
        if (!empty($sectionsData)) {
          $updatedSections = [];
          foreach ($plan->sections as $index => $section) {
            $sectionId = $section->id ?? 'section_' . $index;
            $needsUpdate = FALSE;
            $newComponentType = $section->componentType;
            $newContent = $section->content;

            // Check for component type change.
            if (isset($sectionsData[$sectionId]['component_type'])) {
              $submittedComponentType = $sectionsData[$sectionId]['component_type'];
              if ($submittedComponentType !== $section->componentType) {
                $newComponentType = $submittedComponentType;
                $needsUpdate = TRUE;
              }
            }

            // Check for content change.
            if (isset($sectionsData[$sectionId]['content'])) {
              $submittedContent = trim($sectionsData[$sectionId]['content']);
              if ($submittedContent !== trim($section->content)) {
                $newContent = $submittedContent;
                $needsUpdate = TRUE;
              }
            }

            if ($needsUpdate) {
              // Create updated section with new values.
              $section = new \Drupal\ai_content_preparation_wizard\Model\PlanSection(
                $section->id,
                $section->title,
                $newContent,
                $newComponentType,
                $section->order,
                $section->componentConfig,
                $section->children
              );
              $planUpdated = TRUE;
            }
            $updatedSections[] = $section;
          }
          $plan = $plan->withSections($updatedSections);
        }

        if ($planUpdated) {
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
    // Always stay on step 2 when regenerating.
    $form_state->set('step', 2);
    // Also set in user input to ensure persistence across rebuild.
    $input = $form_state->getUserInput();
    $input['wizard_step'] = 2;
    $form_state->setUserInput($input);

    // Get refinement text from the named field.
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
      // Get the selected contexts from session to apply during refinement.
      $contexts = $session->getSelectedContexts();
      $refinedPlan = $this->planGenerator->refine($plan, $refinement, $contexts);
      $session->setContentPlan($refinedPlan);
      $this->sessionManager->updateSession($session);
      $this->messenger()->addStatus($this->t('Plan has been updated.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to refine plan: @error', [
        '@error' => $e->getMessage(),
      ]));
    }

    // Ensure we stay on step 2 after regeneration.
    $form_state->set('step', 2);
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

    // Skip validation for back and regenerate buttons.
    if (str_contains($triggerName, 'back') || str_contains($triggerName, 'regenerate')) {
      return;
    }

    $step = $form_state->get('step') ?? 1;

    // Only validate step 1 fields when on step 1.
    if ($step === 1 && str_contains($triggerName, 'next')) {
      $documents = $form_state->getValue('documents');
      $hasDocuments = !empty(array_filter($documents ?? []));

      // Parse and validate webpage URLs.
      $webpageUrlsRaw = trim($form_state->getValue('webpage_urls') ?? '');
      $webpageUrls = [];
      if (!empty($webpageUrlsRaw)) {
        $lines = preg_split('/\r\n|\r|\n/', $webpageUrlsRaw);
        foreach ($lines as $line) {
          $url = trim($line);
          if (!empty($url)) {
            // Validate URL format.
            if ($this->webpageProcessor !== NULL && !$this->webpageProcessor->isValidUrl($url)) {
              $form_state->setErrorByName('webpage_urls', $this->t('Invalid URL: @url. Only HTTP/HTTPS URLs are supported.', ['@url' => $url]));
              return;
            }
            $webpageUrls[] = $url;
          }
        }
      }
      $hasWebpages = !empty($webpageUrls);

      // Require at least one content source.
      if (!$hasDocuments && !$hasWebpages) {
        $form_state->setErrorByName('documents', $this->t('Please upload at least one document or enter at least one webpage URL.'));
      }

      // Store parsed URLs for submit handler.
      $form_state->set('parsed_webpage_urls', $webpageUrls);
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

      // Get the template ID from the session (selected in step 1).
      $templateId = $session->getTemplateId();

      // Use template-based creation if a template was selected,
      // otherwise fall back to building components from scratch.
      if (!empty($templateId)) {
        $page = $this->canvasCreator->createFromTemplate($plan, $templateId, $options);
        $this->messenger()->addStatus($this->t('Canvas page "@title" has been created from template.', [
          '@title' => $page->label(),
        ]));
      }
      else {
        $page = $this->canvasCreator->create($plan, $options);
        $this->messenger()->addStatus($this->t('Canvas page "@title" has been created successfully.', [
          '@title' => $page->label(),
        ]));
      }

      $this->sessionManager->clearSession();

      // Redirect to the created page.
      $form_state->setRedirectUrl($page->toUrl());
    }
    catch (\Drupal\ai_content_preparation_wizard\Exception\CanvasCreationException $e) {
      $this->messenger()->addError($this->t('Failed to create Canvas page: @error', [
        '@error' => $e->getMessage(),
      ]));
      // Show detailed validation errors if available.
      if ($e->validationErrors) {
        foreach ($e->validationErrors as $validationError) {
          $this->messenger()->addError($validationError);
        }
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to create Canvas page: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Gets Canvas page options for a select element.
   *
   * @return array
   *   An array of Canvas page options keyed by page ID.
   */
  protected function getCanvasPageOptions(): array {
    $options = [];

    try {
      // Check if canvas_page entity type exists.
      $definition = $this->entityTypeManager->getDefinition('canvas_page', FALSE);
      if ($definition === NULL) {
        return $options;
      }

      $storage = $this->entityTypeManager->getStorage('canvas_page');
      $query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->sort('title', 'ASC');

      $ids = $query->execute();
      $pages = $storage->loadMultiple($ids);

      foreach ($pages as $page) {
        $status = $page->isPublished() ? '' : ' ' . $this->t('(unpublished)');
        $options[$page->id()] = $page->label() . $status;
      }
    }
    catch (\Exception $e) {
      // Canvas module not available or query failed - return empty options.
    }

    return $options;
  }

  /**
   * Gets available component options for section dropdowns.
   *
   * This method combines components from the selected Canvas page template
   * (if any) with all available Canvas components.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\WizardSession|null $session
   *   The wizard session.
   *
   * @return array
   *   An array of component options keyed by component ID.
   */
  protected function getAvailableComponentOptions($session): array {
    $options = [];
    $templateComponents = [];

    // Get components from the selected Canvas page template.
    $templateId = $session?->getTemplateId();
    if ($templateId) {
      try {
        $canvasPage = $this->entityTypeManager->getStorage('canvas_page')->load($templateId);
        if ($canvasPage) {
          $componentTree = $canvasPage->get('components');
          if ($componentTree) {
            // Get unique component IDs from the template.
            foreach ($componentTree as $item) {
              $componentId = $item->get('component_id')->getValue();
              if ($componentId && !isset($templateComponents[$componentId])) {
                $templateComponents[$componentId] = $componentId;
              }
            }
          }
        }
      }
      catch (\Exception $e) {
        // Failed to load template components.
      }
    }

    // Get SDC components only from the page builder helper.
    // This must match exactly what getSdcComponentsForPrompt() returns in ContentPlanGenerator
    // so the AI-selected component_type matches the dropdown options.
    if ($this->pageBuilderHelper) {
      try {
        $componentsBySource = $this->pageBuilderHelper->getAllComponentsKeyedBySource();
        // Filter to ONLY Single Directory Components (SDC) - source ID is 'sdc'.
        // This matches the AI prompt which only sees SDC components.
        $sdcComponents = $componentsBySource['sdc']['components'] ?? [];

        foreach ($sdcComponents as $componentId => $componentData) {
          $componentName = $componentData['name'] ?? $componentId;
          // Mark components from the template.
          $suffix = isset($templateComponents[$componentId]) ? ' ★' : '';
          $options[$componentId] = $componentName . $suffix;
        }
      }
      catch (\Exception $e) {
        // Failed to load available components.
      }
    }

    // If no SDC components available, add fallback options.
    // Note: These should ideally never be used if Canvas AI is properly configured.
    if (empty($options)) {
      $options = [
        'text' => $this->t('Text'),
        'heading' => $this->t('Heading'),
        'image' => $this->t('Image'),
        'accordion' => $this->t('Accordion'),
        'card' => $this->t('Card'),
        'list' => $this->t('List'),
        'quote' => $this->t('Quote'),
        'table' => $this->t('Table'),
      ];
    }

    // Sort options alphabetically.
    asort($options);

    return $options;
  }

  /**
   * Gets information about a template Canvas page.
   *
   * @param string|int $templateId
   *   The template Canvas page ID.
   *
   * @return array|null
   *   Array with 'label' and 'id' keys, or NULL if not found.
   */
  protected function getTemplateInfo(string|int $templateId): ?array {
    try {
      $definition = $this->entityTypeManager->getDefinition('canvas_page', FALSE);
      if ($definition === NULL) {
        return NULL;
      }

      $storage = $this->entityTypeManager->getStorage('canvas_page');
      $templatePage = $storage->load($templateId);

      if ($templatePage === NULL) {
        return NULL;
      }

      return [
        'id' => $templatePage->id(),
        'label' => $templatePage->label(),
      ];
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets information about the configured AI model.
   *
   * @return string
   *   A human-readable string with the AI provider and model name.
   */
  protected function getAiModelInfo(): string {
    if ($this->configFactory === NULL) {
      return $this->t('Unknown')->render();
    }

    $config = $this->configFactory->get('ai_content_preparation_wizard.settings');
    $providerId = $config->get('default_ai_provider');
    $modelId = $config->get('default_ai_model');

    // If no provider configured, try to get the default from AI module.
    if (empty($providerId) && $this->aiProviderManager !== NULL) {
      try {
        $defaultProvider = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
        if ($defaultProvider) {
          // The method returns an array with provider_id and model_id keys.
          if (is_array($defaultProvider)) {
            $providerId = $defaultProvider['provider_id'] ?? '';
            $modelId = $modelId ?: ($defaultProvider['model_id'] ?? $this->t('default')->render());
          }
          elseif (is_object($defaultProvider) && method_exists($defaultProvider, 'getPluginId')) {
            $providerId = $defaultProvider->getPluginId();
            $modelId = $modelId ?: $this->t('default')->render();
          }
        }
      }
      catch (\Exception $e) {
        // Fall through to unknown.
      }
    }

    if (empty($providerId)) {
      return $this->t('Not configured')->render();
    }

    // Try to get provider label from plugin manager.
    $providerLabel = $providerId;
    if ($this->aiProviderManager !== NULL) {
      try {
        $definitions = $this->aiProviderManager->getDefinitions();
        if (isset($definitions[$providerId])) {
          $providerLabel = $definitions[$providerId]['label'] ?? $providerId;
        }
      }
      catch (\Exception $e) {
        // Keep using providerId as label.
      }
    }

    if (!empty($modelId)) {
      return $providerLabel . ' (' . $modelId . ')';
    }

    return (string) $providerLabel;
  }

}
