<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Form;

use Drupal\Component\Utility\Html;
use Drupal\ai_content_preparation_wizard\Enum\PlanStatus;
use Drupal\ai_content_preparation_wizard\Exception\CanvasCreationException;
use Drupal\ai_content_preparation_wizard\Model\ContentPlan;
use Drupal\ai_content_preparation_wizard\Model\PlanSection;
use Drupal\ai_content_preparation_wizard\Service\CanvasCreatorInterface;
use Drupal\ai_content_preparation_wizard\Service\WizardSessionManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Step 3 form for creating Canvas pages from approved content plans.
 *
 * This is the final step in the Content Preparation Wizard where users
 * preview their content plan and create the actual Canvas page.
 */
class Step3CreateForm extends FormBase {

  /**
   * Constructs a Step3CreateForm object.
   *
   * @param \Drupal\ai_content_preparation_wizard\Service\WizardSessionManagerInterface $sessionManager
   *   The wizard session manager.
   * @param \Drupal\ai_content_preparation_wizard\Service\CanvasCreatorInterface $canvasCreator
   *   The Canvas page creator service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(
    protected readonly WizardSessionManagerInterface $sessionManager,
    protected readonly CanvasCreatorInterface $canvasCreator,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_content_preparation_wizard.wizard_session_manager'),
      $container->get('ai_content_preparation_wizard.canvas_creator'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_content_preparation_wizard_step3';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $session = $this->sessionManager->getSession();

    if ($session === NULL) {
      $this->messenger()->addError($this->t('No active wizard session found. Please start from the beginning.'));
      return $this->buildNoSessionForm($form);
    }

    $plan = $session->getContentPlan();

    if ($plan === NULL) {
      $this->messenger()->addError($this->t('No content plan found. Please complete the previous step first.'));
      return $this->buildNoPlanForm($form);
    }

    // Check if plan is approved or ready for creation.
    if (!$plan->status->canCreate()) {
      $this->messenger()->addWarning($this->t('The content plan must be approved before creating the Canvas page. Current status: @status', [
        '@status' => $plan->status->label(),
      ]));
    }

    // Build the preview section.
    $form['preview'] = $this->buildPreviewSection($plan);

    // Build the configuration section.
    $form['configuration'] = $this->buildConfigurationSection($plan);

    // Build the component mapping visualization.
    $form['component_mapping'] = $this->buildComponentMappingSection($plan);

    // Build the actions section.
    $form['actions'] = $this->buildActionsSection($plan);

    // Attach library for styling.
    $form['#attached']['library'][] = 'ai_content_preparation_wizard/wizard';

    return $form;
  }

  /**
   * Builds the preview section of the form.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan.
   *
   * @return array
   *   The preview section render array.
   */
  protected function buildPreviewSection(ContentPlan $plan): array {
    $section = [
      '#type' => 'details',
      '#title' => $this->t('Content Preview'),
      '#open' => TRUE,
      '#attributes' => [
        'class' => ['wizard-preview-section'],
      ],
    ];

    // Page title preview.
    $section['title_preview'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['preview-title-container'],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $this->t('Page Title:'),
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => Html::escape($plan->title),
        '#attributes' => [
          'class' => ['preview-page-title'],
        ],
      ],
    ];

    // Summary preview.
    $section['summary_preview'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['preview-summary-container'],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $this->t('Summary:'),
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => Html::escape($plan->summary),
        '#attributes' => [
          'class' => ['preview-summary'],
        ],
      ],
    ];

    // Metadata.
    $section['metadata'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['preview-metadata'],
      ],
      'audience' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('<strong>Target Audience:</strong> @audience', [
          '@audience' => $plan->targetAudience,
        ]),
      ],
      'read_time' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('<strong>Estimated Read Time:</strong> @time minutes', [
          '@time' => $plan->estimatedReadTime,
        ]),
      ],
      'sections' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('<strong>Total Sections:</strong> @count', [
          '@count' => $plan->getTotalSectionCount(),
        ]),
      ],
      'words' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('<strong>Word Count:</strong> @count', [
          '@count' => number_format($plan->getTotalWordCount()),
        ]),
      ],
    ];

    // Sections preview.
    $section['sections_preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Sections (@count)', [
        '@count' => $plan->getTotalSectionCount(),
      ]),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['preview-sections-details'],
      ],
    ];

    $section['sections_preview']['sections'] = $this->buildSectionsPreview($plan->sections);

    return $section;
  }

  /**
   * Builds the sections preview render array.
   *
   * @param array $sections
   *   The plan sections.
   * @param int $depth
   *   The current nesting depth.
   *
   * @return array
   *   The sections preview render array.
   */
  protected function buildSectionsPreview(array $sections, int $depth = 0): array {
    $items = [];

    foreach ($sections as $section) {
      if (!$section instanceof PlanSection) {
        continue;
      }

      $item = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['section-preview-item', 'depth-' . $depth],
          'style' => 'margin-left: ' . ($depth * 20) . 'px;',
        ],
        'header' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['section-header'],
          ],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => Html::escape($section->title),
          ],
          'component' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Component: @type', [
              '@type' => $section->componentType,
            ]),
            '#attributes' => [
              'class' => ['component-badge'],
            ],
          ],
        ],
        'content' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $this->truncateContent($section->content, 200),
          '#attributes' => [
            'class' => ['section-content-preview'],
          ],
        ],
      ];

      // Add children recursively.
      if ($section->hasChildren()) {
        $item['children'] = $this->buildSectionsPreview($section->children, $depth + 1);
      }

      $items[] = $item;
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['sections-preview-list'],
      ],
      'items' => $items,
    ];
  }

  /**
   * Builds the configuration section of the form.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan.
   *
   * @return array
   *   The configuration section render array.
   */
  protected function buildConfigurationSection(ContentPlan $plan): array {
    $section = [
      '#type' => 'details',
      '#title' => $this->t('Page Configuration'),
      '#open' => TRUE,
      '#attributes' => [
        'class' => ['wizard-configuration-section'],
      ],
    ];

    // Page title field.
    $section['page_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Page Title'),
      '#default_value' => $plan->title,
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('The title for the Canvas page. This will be displayed as the page heading.'),
    ];

    // URL alias field with auto-generation.
    $section['url_alias'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('URL Alias'),
      '#default_value' => $plan->getSuggestedPath(),
      '#required' => FALSE,
      '#maxlength' => 255,
      '#description' => $this->t('The URL path for this page. Leave empty for auto-generation.'),
      '#machine_name' => [
        'exists' => [$this, 'pathAliasExists'],
        'source' => ['configuration', 'page_title'],
        'replace_pattern' => '[^a-z0-9-]+',
        'replace' => '-',
      ],
    ];

    // Page status field.
    $section['page_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Page Status'),
      '#options' => [
        'draft' => $this->t('Draft (unpublished)'),
        'published' => $this->t('Published'),
      ],
      '#default_value' => 'draft',
      '#description' => $this->t('Choose whether to publish the page immediately or save as draft.'),
    ];

    return $section;
  }

  /**
   * Builds the component mapping visualization section.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan.
   *
   * @return array
   *   The component mapping section render array.
   */
  protected function buildComponentMappingSection(ContentPlan $plan): array {
    $section = [
      '#type' => 'details',
      '#title' => $this->t('Component Mapping'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['wizard-component-mapping-section'],
      ],
    ];

    // Get component mappings from the canvas creator.
    $components = $this->canvasCreator->mapToComponents($plan);

    if (empty($components)) {
      $section['empty'] = [
        '#markup' => $this->t('Component mappings will be generated when the page is created.'),
      ];
      return $section;
    }

    // Build mapping table from components.
    $rows = [];
    $weight = 0;
    foreach ($plan->sections as $planSection) {
      if (!$planSection instanceof PlanSection) {
        continue;
      }

      $rows[] = [
        'section' => Html::escape($planSection->title),
        'component' => $planSection->componentType,
        'weight' => $weight++,
        'parent' => $this->t('(root)'),
      ];

      // Add children if any.
      foreach ($planSection->children as $child) {
        if ($child instanceof PlanSection) {
          $rows[] = [
            'section' => '-- ' . Html::escape($child->title),
            'component' => $child->componentType,
            'weight' => $weight++,
            'parent' => Html::escape($planSection->title),
          ];
        }
      }
    }

    $section['mapping_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Section'),
        $this->t('Canvas Component'),
        $this->t('Weight'),
        $this->t('Parent'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No component mappings available.'),
      '#attributes' => [
        'class' => ['component-mapping-table'],
      ],
    ];

    return $section;
  }

  /**
   * Builds the actions section of the form.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan.
   *
   * @return array
   *   The actions section render array.
   */
  protected function buildActionsSection(ContentPlan $plan): array {
    $section = [
      '#type' => 'actions',
    ];

    // Check if Canvas is available by checking for the module.
    $canvasAvailable = $this->isCanvasAvailable();

    // Create button.
    $section['create'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Canvas Page Now'),
      '#button_type' => 'primary',
      '#disabled' => !$canvasAvailable || !$plan->status->canCreate(),
      '#attributes' => [
        'class' => ['wizard-create-button'],
      ],
    ];

    if (!$canvasAvailable) {
      $section['canvas_warning'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('Canvas module is not available or not properly configured.'),
        '#attributes' => [
          'class' => ['messages', 'messages--warning'],
        ],
      ];
    }

    // Back button.
    $section['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to Plan'),
      '#url' => Url::fromRoute('ai_content_preparation_wizard.wizard.step', ['step' => 'plan']),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    // Cancel button.
    $section['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('ai_content_preparation_wizard.wizard'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $section;
  }

  /**
   * Checks if Canvas module is available.
   *
   * @return bool
   *   TRUE if Canvas is available, FALSE otherwise.
   */
  protected function isCanvasAvailable(): bool {
    // Check for common Canvas module names.
    return $this->moduleHandler->moduleExists('canvas')
      || $this->moduleHandler->moduleExists('canvas_page')
      || $this->moduleHandler->moduleExists('oe_canvas');
  }

  /**
   * Builds a form when no session exists.
   *
   * @param array $form
   *   The form array.
   *
   * @return array
   *   The modified form array.
   */
  protected function buildNoSessionForm(array $form): array {
    $form['message'] = [
      '#markup' => $this->t('Please start the wizard from the beginning.'),
    ];

    $form['start'] = [
      '#type' => 'link',
      '#title' => $this->t('Start Wizard'),
      '#url' => Url::fromRoute('ai_content_preparation_wizard.wizard'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    return $form;
  }

  /**
   * Builds a form when no plan exists.
   *
   * @param array $form
   *   The form array.
   *
   * @return array
   *   The modified form array.
   */
  protected function buildNoPlanForm(array $form): array {
    $form['message'] = [
      '#markup' => $this->t('Please complete the content plan step first.'),
    ];

    $form['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Go to Plan Step'),
      '#url' => Url::fromRoute('ai_content_preparation_wizard.wizard.step', ['step' => 'plan']),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    return $form;
  }

  /**
   * Truncates content to a specified length.
   *
   * @param string $content
   *   The content to truncate.
   * @param int $length
   *   The maximum length.
   *
   * @return string
   *   The truncated content.
   */
  protected function truncateContent(string $content, int $length): string {
    $stripped = strip_tags($content);
    if (mb_strlen($stripped) <= $length) {
      return Html::escape($stripped);
    }
    return Html::escape(mb_substr($stripped, 0, $length)) . '...';
  }

  /**
   * Checks if a path alias already exists.
   *
   * @param string $value
   *   The path alias value to check.
   *
   * @return bool
   *   TRUE if the path alias exists, FALSE otherwise.
   */
  public function pathAliasExists(string $value): bool {
    if (empty($value)) {
      return FALSE;
    }

    $path = '/' . ltrim($value, '/');

    try {
      $storage = $this->entityTypeManager->getStorage('path_alias');
      $aliases = $storage->loadByProperties(['alias' => $path]);
      return !empty($aliases);
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $title = $form_state->getValue('page_title');

    if (empty(trim($title))) {
      $form_state->setErrorByName('page_title', $this->t('Page title is required and cannot be empty.'));
    }

    if (mb_strlen($title) > 255) {
      $form_state->setErrorByName('page_title', $this->t('Page title cannot exceed 255 characters.'));
    }

    // Validate that we still have a valid session and plan.
    $session = $this->sessionManager->getSession();
    if ($session === NULL) {
      $form_state->setError($form, $this->t('Your wizard session has expired. Please start again.'));
      return;
    }

    $plan = $session->getContentPlan();
    if ($plan === NULL) {
      $form_state->setError($form, $this->t('Content plan not found. Please complete the previous step.'));
      return;
    }

    // Check if plan has the required status for creation.
    if (!$plan->status->canCreate()) {
      $form_state->setError($form, $this->t('The content plan must be approved before creating the Canvas page. Current status: @status', [
        '@status' => $plan->status->label(),
      ]));
    }

    // Check if plan has sections.
    if (empty($plan->sections)) {
      $form_state->setError($form, $this->t('The content plan has no sections. Please go back and generate a valid plan.'));
    }

    // Check if Canvas is available.
    if (!$this->isCanvasAvailable()) {
      $form_state->setError($form, $this->t('Canvas module is not available. Please contact the site administrator.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $session = $this->sessionManager->getSession();

    if ($session === NULL) {
      $this->messenger()->addError($this->t('Session expired. Please start the wizard again.'));
      $form_state->setRedirect('ai_content_preparation_wizard.wizard');
      return;
    }

    $plan = $session->getContentPlan();

    if ($plan === NULL) {
      $this->messenger()->addError($this->t('Content plan not found. Please complete the previous step.'));
      $form_state->setRedirect('ai_content_preparation_wizard.wizard.step', ['step' => 'plan']);
      return;
    }

    // Prepare options for page creation.
    $pageStatus = $form_state->getValue('page_status');
    $urlAlias = $form_state->getValue('url_alias');

    // Update plan with the potentially modified title.
    $pageTitle = $form_state->getValue('page_title');
    if ($pageTitle !== $plan->title) {
      $plan = $plan->withTitle($pageTitle);
      $this->sessionManager->setContentPlan($plan);
    }

    $options = [
      'alias' => !empty($urlAlias) ? '/' . ltrim($urlAlias, '/') : NULL,
      'status' => $pageStatus === 'published',
      'owner' => (int) $this->currentUser()->id(),
    ];

    try {
      // Update plan status to creating.
      $updatedPlan = $plan->withStatus(PlanStatus::CREATING);
      $this->sessionManager->setContentPlan($updatedPlan);

      // Create the Canvas page.
      $entity = $this->canvasCreator->create($plan, $options);

      // Update plan status to completed.
      $completedPlan = $plan->withStatus(PlanStatus::COMPLETED);
      $this->sessionManager->setContentPlan($completedPlan);

      // Clear the wizard session.
      $this->sessionManager->clearSession();

      // Build success message with link.
      $entityLabel = $entity->label() ?? $plan->title;
      $entityId = $entity->id();
      $entityTypeId = $entity->getEntityTypeId();

      // Try to create a link to the entity.
      try {
        $entityLink = Link::createFromRoute(
          $entityLabel,
          'entity.' . $entityTypeId . '.canonical',
          [$entityTypeId => $entityId]
        )->toString();

        $this->messenger()->addStatus($this->t('Canvas page "@title" has been created successfully! @link', [
          '@title' => $entityLabel,
          '@link' => $entityLink,
        ]));

        // Redirect to the created entity.
        $form_state->setRedirect('entity.' . $entityTypeId . '.canonical', [$entityTypeId => $entityId]);
      }
      catch (\Exception $e) {
        // If we can't create the link, just show success without link.
        $this->messenger()->addStatus($this->t('Canvas page "@title" has been created successfully!', [
          '@title' => $entityLabel,
        ]));

        // Redirect to content overview.
        $form_state->setRedirect('system.admin_content');
      }

    }
    catch (CanvasCreationException $e) {
      $this->getLogger('ai_content_preparation_wizard')->error('Canvas page creation failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      // Update plan status to failed.
      try {
        $failedPlan = $plan->withStatus(PlanStatus::FAILED);
        $this->sessionManager->setContentPlan($failedPlan);
      }
      catch (\Exception $updateException) {
        // Log but don't fail on status update error.
        $this->getLogger('ai_content_preparation_wizard')->warning('Failed to update plan status: @message', [
          '@message' => $updateException->getMessage(),
        ]);
      }

      // Display user-friendly error message.
      $errorMessage = $this->t('Failed to create the Canvas page. @error', [
        '@error' => $e->getMessage(),
      ]);

      if ($e->validationErrors !== NULL && !empty($e->validationErrors)) {
        $errorMessage = $this->t('Failed to create the Canvas page due to validation errors:');
        $this->messenger()->addError($errorMessage);
        foreach ($e->validationErrors as $validationError) {
          $this->messenger()->addError($validationError);
        }
      }
      else {
        $this->messenger()->addError($errorMessage);
      }

      $this->messenger()->addWarning($this->t('Please review your content plan and try again, or contact the administrator if the problem persists.'));

    }
    catch (\Exception $e) {
      $this->getLogger('ai_content_preparation_wizard')->error('Unexpected error during Canvas page creation: @message', [
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);

      $this->messenger()->addError($this->t('An unexpected error occurred while creating the Canvas page. Please try again or contact the administrator.'));
    }
  }

}
