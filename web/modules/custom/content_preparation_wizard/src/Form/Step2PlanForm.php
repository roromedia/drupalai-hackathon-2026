<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Form;

use Drupal\content_preparation_wizard\Enum\PlanStatus;
use Drupal\content_preparation_wizard\Exception\PlanGenerationException;
use Drupal\content_preparation_wizard\Model\AIContext;
use Drupal\content_preparation_wizard\Model\WizardSession;
use Drupal\content_preparation_wizard\Service\ContentPlanGeneratorInterface;
use Drupal\content_preparation_wizard\Service\WizardSessionManagerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Step 2: AI Plan Review form for the Content Preparation Wizard.
 *
 * This form displays the AI-generated content plan, allows users to edit
 * the title, review sections, and request refinements before approval.
 */
class Step2PlanForm extends FormBase {

  /**
   * The wizard session manager.
   *
   * @var \Drupal\content_preparation_wizard\Service\WizardSessionManagerInterface
   */
  protected WizardSessionManagerInterface $sessionManager;

  /**
   * The content plan generator service.
   *
   * @var \Drupal\content_preparation_wizard\Service\ContentPlanGeneratorInterface
   */
  protected ContentPlanGeneratorInterface $planGenerator;

  /**
   * Constructs a Step2PlanForm object.
   *
   * @param \Drupal\content_preparation_wizard\Service\WizardSessionManagerInterface $session_manager
   *   The wizard session manager.
   * @param \Drupal\content_preparation_wizard\Service\ContentPlanGeneratorInterface $plan_generator
   *   The content plan generator service.
   */
  public function __construct(
    WizardSessionManagerInterface $session_manager,
    ContentPlanGeneratorInterface $plan_generator,
  ) {
    $this->sessionManager = $session_manager;
    $this->planGenerator = $plan_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_preparation_wizard.wizard_session_manager'),
      $container->get('content_preparation_wizard.content_plan_generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_preparation_wizard_step2';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $session = $this->sessionManager->getSession();

    if ($session === NULL || !$session->hasProcessedDocuments()) {
      $this->messenger()->addError($this->t('No documents found. Please upload documents first.'));
      return $this->buildErrorForm($form);
    }

    // Get or generate the content plan.
    $plan = $session->getContentPlan();

    if ($plan === NULL) {
      // First load: generate the plan.
      try {
        // Get AI contexts from the selected contexts in session.
        $contexts = $this->buildContextsFromSession($session);

        $plan = $this->planGenerator->generate(
          $session->getProcessedDocuments(),
          $contexts,
          $session->getTemplateId()
        );
        $this->sessionManager->setContentPlan($plan);
      }
      catch (PlanGenerationException $e) {
        $this->messenger()->addError($this->t('Failed to generate content plan: @message', [
          '@message' => $e->getMessage(),
        ]));
        return $this->buildErrorForm($form);
      }
    }

    $form['#prefix'] = '<div id="step2-plan-form-wrapper">';
    $form['#suffix'] = '</div>';

    // Plan status indicator.
    $form['status_indicator'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['plan-status-indicator']],
    ];

    $form['status_indicator']['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $plan->status->label(),
      '#attributes' => [
        'class' => [
          'plan-status',
          'plan-status--' . $plan->status->value,
        ],
      ],
    ];

    // Plan preview section.
    $form['plan_preview'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['plan-preview']],
      '#prefix' => '<div id="plan-preview-wrapper">',
      '#suffix' => '</div>',
    ];

    // Editable title field.
    $form['plan_preview']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Content Title'),
      '#default_value' => $plan->title,
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => [
        'class' => ['plan-title-input'],
      ],
    ];

    // Summary display.
    $form['plan_preview']['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Summary'),
      '#markup' => '<div class="plan-summary">' . htmlspecialchars($plan->summary, ENT_QUOTES, 'UTF-8') . '</div>',
    ];

    // Metadata row.
    $form['plan_preview']['metadata'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['plan-metadata']],
    ];

    $form['plan_preview']['metadata']['read_time'] = [
      '#type' => 'item',
      '#title' => $this->t('Estimated Read Time'),
      '#markup' => $this->formatTime('minute', $plan->estimatedReadTime),
    ];

    $form['plan_preview']['metadata']['target_audience'] = [
      '#type' => 'item',
      '#title' => $this->t('Target Audience'),
      '#markup' => htmlspecialchars($plan->targetAudience, ENT_QUOTES, 'UTF-8'),
    ];

    $form['plan_preview']['metadata']['section_count'] = [
      '#type' => 'item',
      '#title' => $this->t('Sections'),
      '#markup' => (string) $plan->getTotalSectionCount(),
    ];

    $form['plan_preview']['metadata']['word_count'] = [
      '#type' => 'item',
      '#title' => $this->t('Word Count'),
      '#markup' => number_format($plan->getTotalWordCount()),
    ];

    // Sections list with expandable details.
    $form['plan_preview']['sections'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Sections (@count)', [
        '@count' => $plan->getTotalSectionCount(),
      ]),
      '#open' => TRUE,
      '#attributes' => ['class' => ['plan-sections']],
    ];

    $form['plan_preview']['sections']['list'] = $this->buildSectionsList($plan->sections);

    // Refinement history.
    if ($plan->getRefinementCount() > 0) {
      $form['plan_preview']['refinement_history'] = [
        '#type' => 'details',
        '#title' => $this->t('Refinement History (@count)', [
          '@count' => $plan->getRefinementCount(),
        ]),
        '#open' => FALSE,
        '#attributes' => ['class' => ['refinement-history']],
      ];

      $history_items = [];
      foreach ($plan->refinementHistory as $entry) {
        $history_items[] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['refinement-entry']],
          'timestamp' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $entry->createdAt->format('Y-m-d H:i'),
            '#attributes' => ['class' => ['refinement-timestamp']],
          ],
          'instructions' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => htmlspecialchars($entry->getSummary(150), ENT_QUOTES, 'UTF-8'),
            '#attributes' => ['class' => ['refinement-instructions']],
          ],
        ];
      }

      $form['plan_preview']['refinement_history']['entries'] = $history_items;
    }

    // Refinement section.
    $can_refine = $this->planGenerator->canRefine($plan);
    $max_iterations = $this->planGenerator->getMaxRefinementIterations();
    $remaining = $max_iterations - $plan->getRefinementCount();

    $form['refinement'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['plan-refinement']],
    ];

    $form['refinement']['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Refinement Instructions'),
      '#description' => $can_refine
        ? $this->t('Describe how you would like to adjust the plan. @remaining refinements remaining.', [
          '@remaining' => $remaining,
        ])
        : $this->t('Maximum refinement iterations reached.'),
      '#placeholder' => $this->t('Type instructions to adjust the plan...'),
      '#rows' => 4,
      '#disabled' => !$can_refine,
      '#attributes' => [
        'class' => ['refinement-textarea'],
      ],
    ];

    // Action buttons.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['regenerate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Regenerate Plan'),
      '#submit' => ['::regeneratePlan'],
      '#ajax' => [
        'callback' => '::ajaxRegeneratePlan',
        'wrapper' => 'step2-plan-form-wrapper',
        'effect' => 'fade',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Regenerating plan...'),
        ],
      ],
      '#disabled' => !$can_refine,
      '#attributes' => [
        'class' => ['button--secondary'],
      ],
      '#states' => [
        'disabled' => [
          ':input[name="instructions"]' => ['value' => ''],
        ],
      ],
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::goBack'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['button--secondary'],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Approve & Continue'),
      '#button_type' => 'primary',
    ];

    // Attach library for styling.
    $form['#attached']['library'][] = 'content_preparation_wizard/step2_plan';

    return $form;
  }

  /**
   * Builds a render array for the sections list.
   *
   * @param array<\Drupal\content_preparation_wizard\Model\PlanSection> $sections
   *   The sections to render.
   * @param int $depth
   *   The nesting depth for indentation.
   *
   * @return array
   *   The render array for the sections.
   */
  protected function buildSectionsList(array $sections, int $depth = 0): array {
    $items = [];

    foreach ($sections as $index => $section) {
      $section_key = 'section_' . $index . '_' . $depth;

      $items[$section_key] = [
        '#type' => 'details',
        '#title' => $this->t('@order. @title', [
          '@order' => $section->order,
          '@title' => $section->title,
        ]),
        '#open' => $depth === 0,
        '#attributes' => [
          'class' => [
            'plan-section',
            'plan-section--depth-' . $depth,
          ],
        ],
      ];

      // Section metadata.
      $items[$section_key]['metadata'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['section-metadata']],
      ];

      $items[$section_key]['metadata']['component_type'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Component: @type', [
          '@type' => $section->componentType,
        ]),
        '#attributes' => ['class' => ['section-component-type']],
      ];

      $items[$section_key]['metadata']['word_count'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('@count words', [
          '@count' => $section->getTotalWordCount(),
        ]),
        '#attributes' => ['class' => ['section-word-count']],
      ];

      // Section content preview.
      $content_preview = $this->truncateContent($section->content, 300);
      $items[$section_key]['content'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => htmlspecialchars($content_preview, ENT_QUOTES, 'UTF-8'),
        '#attributes' => ['class' => ['section-content-preview']],
      ];

      // Nested children.
      if ($section->hasChildren()) {
        $items[$section_key]['children'] = $this->buildSectionsList($section->children, $depth + 1);
      }
    }

    return $items;
  }

  /**
   * Builds an error form when the wizard cannot proceed.
   *
   * @param array $form
   *   The form array.
   *
   * @return array
   *   The error form.
   */
  protected function buildErrorForm(array $form): array {
    $form['error'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard-error']],
    ];

    $form['error']['message'] = [
      '#markup' => '<p>' . $this->t('Unable to proceed with plan generation. Please return to the upload step.') . '</p>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back to Upload'),
      '#submit' => ['::goBack'],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Submit handler for the regenerate button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function regeneratePlan(array &$form, FormStateInterface $form_state): void {
    $session = $this->sessionManager->getSession();
    if ($session === NULL) {
      $this->messenger()->addError($this->t('Session expired. Please start over.'));
      return;
    }

    $plan = $session->getContentPlan();
    if ($plan === NULL) {
      $this->messenger()->addError($this->t('No plan found to refine.'));
      return;
    }

    $instructions = trim($form_state->getValue('instructions', ''));
    if (empty($instructions)) {
      $this->messenger()->addWarning($this->t('Please provide refinement instructions.'));
      return;
    }

    try {
      $refined_plan = $this->planGenerator->refine($plan, $instructions);

      // Update title if changed.
      $new_title = trim($form_state->getValue('title', ''));
      if (!empty($new_title) && $new_title !== $refined_plan->title) {
        $refined_plan = $refined_plan->withTitle($new_title);
      }

      $this->sessionManager->setContentPlan($refined_plan);
      $this->messenger()->addStatus($this->t('Plan has been regenerated successfully.'));
    }
    catch (PlanGenerationException $e) {
      $this->messenger()->addError($this->t('Failed to regenerate plan: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    // Clear the instructions field after processing.
    $form_state->setValue('instructions', '');
    $form_state->setRebuild(TRUE);
  }

  /**
   * AJAX callback for the regenerate button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function ajaxRegeneratePlan(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // Replace the entire form with the rebuilt version.
    $response->addCommand(new ReplaceCommand('#step2-plan-form-wrapper', $form));

    // Add status messages.
    $messages = $this->messenger()->all();
    foreach ($messages as $type => $type_messages) {
      foreach ($type_messages as $message) {
        $response->addCommand(new MessageCommand($message, NULL, ['type' => $type]));
      }
    }
    $this->messenger()->deleteAll();

    return $response;
  }

  /**
   * Submit handler to go back to the previous step.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function goBack(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('content_preparation_wizard.step1');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $title = trim($form_state->getValue('title', ''));
    if (empty($title)) {
      $form_state->setErrorByName('title', $this->t('Content title is required.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $session = $this->sessionManager->getSession();
    if ($session === NULL) {
      $this->messenger()->addError($this->t('Session expired. Please start over.'));
      $form_state->setRedirect('content_preparation_wizard.step1');
      return;
    }

    $plan = $session->getContentPlan();
    if ($plan === NULL) {
      $this->messenger()->addError($this->t('No plan found to approve.'));
      $form_state->setRedirect('content_preparation_wizard.step1');
      return;
    }

    // Update title if changed.
    $new_title = trim($form_state->getValue('title', ''));
    if (!empty($new_title) && $new_title !== $plan->title) {
      $plan = $plan->withTitle($new_title);
    }

    // Set status to approved.
    $plan = $plan->withStatus(PlanStatus::APPROVED);
    $this->sessionManager->setContentPlan($plan);

    $this->messenger()->addStatus($this->t('Content plan approved successfully.'));
    $form_state->setRedirect('content_preparation_wizard.step3');
  }

  /**
   * Truncates content to a maximum length.
   *
   * @param string $content
   *   The content to truncate.
   * @param int $max_length
   *   Maximum character length.
   *
   * @return string
   *   The truncated content.
   */
  protected function truncateContent(string $content, int $max_length): string {
    if (mb_strlen($content) <= $max_length) {
      return $content;
    }

    return mb_substr($content, 0, $max_length - 3) . '...';
  }

  /**
   * Formats a time value with singular/plural unit.
   *
   * @param string $unit
   *   The time unit (e.g., 'minute', 'hour').
   * @param int $value
   *   The numeric value.
   *
   * @return string
   *   The formatted time string.
   */
  protected function formatTime(string $unit, int $value): string {
    if ($value === 1) {
      return $this->t('1 @unit', ['@unit' => $unit]);
    }
    return $this->t('@value @units', [
      '@value' => $value,
      '@units' => $unit . 's',
    ]);
  }

  /**
   * Builds AIContext objects from selected contexts in the session.
   *
   * @param \Drupal\content_preparation_wizard\Model\WizardSession $session
   *   The wizard session.
   *
   * @return array<\Drupal\content_preparation_wizard\Model\AIContext>
   *   An array of AIContext objects.
   */
  protected function buildContextsFromSession(WizardSession $session): array {
    $contexts = [];
    $selected_contexts = $session->getSelectedContexts();

    // Convert selected context identifiers to AIContext objects.
    // The selected contexts from step 1 may contain structured data
    // that needs to be converted to AIContext objects.
    foreach ($selected_contexts as $context_data) {
      if ($context_data instanceof AIContext) {
        $contexts[] = $context_data;
      }
      elseif (is_array($context_data) && isset($context_data['id'])) {
        try {
          $contexts[] = AIContext::fromArray($context_data);
        }
        catch (\InvalidArgumentException $e) {
          // Skip invalid context data.
          continue;
        }
      }
    }

    return $contexts;
  }

}
