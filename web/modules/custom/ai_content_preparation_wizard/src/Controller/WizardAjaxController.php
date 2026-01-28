<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Controller;

use Drupal\ai_content_preparation_wizard\Service\ContentPlanGeneratorInterface;
use Drupal\ai_content_preparation_wizard\Service\WizardSessionManagerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for wizard AJAX operations.
 */
final class WizardAjaxController extends ControllerBase {

  /**
   * The Canvas AI page builder helper service.
   *
   * @var object|null
   */
  protected $pageBuilderHelper = NULL;

  /**
   * Constructs a WizardAjaxController object.
   */
  public function __construct(
    protected WizardSessionManagerInterface $sessionManager,
    protected ContentPlanGeneratorInterface $planGenerator,
    protected RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('ai_content_preparation_wizard.wizard_session_manager'),
      $container->get('ai_content_preparation_wizard.content_plan_generator'),
      $container->get('renderer'),
    );

    // Inject the page builder helper if canvas_ai module is available.
    if ($container->has('canvas_ai.page_builder_helper')) {
      $instance->pageBuilderHelper = $container->get('canvas_ai.page_builder_helper');
    }

    return $instance;
  }

  /**
   * AJAX callback to regenerate the content plan.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function regeneratePlan(Request $request): AjaxResponse {
    $response = new AjaxResponse();

    try {
      $session = $this->sessionManager->getSession();
      if (!$session) {
        $response->addCommand(new MessageCommand(
          $this->t('No active wizard session found. Please start over.'),
          NULL,
          ['type' => 'error']
        ));
        return $response;
      }

      $plan = $session->getContentPlan();
      if (!$plan) {
        $response->addCommand(new MessageCommand(
          $this->t('No content plan found to refine.'),
          NULL,
          ['type' => 'error']
        ));
        return $response;
      }

      // Get refinement prompt from request.
      $refinementPrompt = $request->request->get('refinement_prompt', '');
      if (empty($refinementPrompt)) {
        $refinementPrompt = $request->query->get('refinement_prompt', '');
      }

      if (empty($refinementPrompt)) {
        $response->addCommand(new MessageCommand(
          $this->t('Please provide instructions for how to refine the plan.'),
          NULL,
          ['type' => 'warning']
        ));
        return $response;
      }

      // Refine the plan with selected contexts.
      $contexts = $session->getSelectedContexts();
      $refinedPlan = $this->planGenerator->refine($plan, $refinementPrompt, $contexts);
      $this->sessionManager->setContentPlan($refinedPlan);

      $response->addCommand(new MessageCommand(
        $this->t('Plan has been updated based on your instructions.'),
        NULL,
        ['type' => 'status']
      ));

      // Build updated plan preview.
      $planPreview = $this->buildPlanPreview($refinedPlan);
      $response->addCommand(new ReplaceCommand('#plan-preview-wrapper', $planPreview));

    }
    catch (\Exception $e) {
      $this->getLogger('ai_content_preparation_wizard')->error('Plan regeneration failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $response->addCommand(new MessageCommand(
        $this->t('Failed to regenerate plan: @error', ['@error' => $e->getMessage()]),
        NULL,
        ['type' => 'error']
      ));
    }

    return $response;
  }

  /**
   * AJAX endpoint for async plan generation.
   *
   * Called from Step 2 to generate the content plan asynchronously,
   * allowing faster page load.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with plan data or error.
   */
  public function generatePlanAsync(Request $request): JsonResponse {
    try {
      $session = $this->sessionManager->getSession();
      if (!$session) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => $this->t('No active wizard session found.'),
        ], 400);
      }

      // Check if we already have a plan (might be a refresh).
      $existingPlan = $session->getContentPlan();
      if ($existingPlan) {
        return $this->buildPlanJsonResponse($existingPlan, $session);
      }

      // Get processed documents and webpages from session.
      $processedDocs = $session->getProcessedDocuments() ?? [];
      $hasWebpages = $session->hasProcessedWebpages();

      if (empty($processedDocs) && !$hasWebpages) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => $this->t('No processed documents or webpages found.'),
        ], 400);
      }

      // Get contexts and template from session.
      $contexts = $session->getSelectedContexts();
      $templateId = $session->getTemplateId();

      // Build options including webpages if available.
      $options = [];
      if ($hasWebpages) {
        $options['webpages'] = $session->getProcessedWebpages();
      }

      // Generate the plan.
      $plan = $this->planGenerator->generate($processedDocs, $contexts, $templateId, $options);
      $session->setContentPlan($plan);
      $this->sessionManager->updateSession($session);

      return $this->buildPlanJsonResponse($plan, $session);

    }
    catch (\Exception $e) {
      $this->getLogger('ai_content_preparation_wizard')->error('Async plan generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => $this->t('Failed to generate plan: @error', ['@error' => $e->getMessage()]),
      ], 500);
    }
  }

  /**
   * Builds JSON response with plan data.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan.
   * @param mixed $session
   *   The wizard session.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with plan data.
   */
  protected function buildPlanJsonResponse($plan, $session): JsonResponse {
    // Get component options for dropdowns.
    $componentOptions = $this->getAvailableComponentOptions($session);

    // Build sections data.
    $sections = [];
    foreach ($plan->sections as $index => $section) {
      // Skip sections with empty content.
      $content = trim($section->content ?? '');
      if (empty($content)) {
        continue;
      }

      $sectionId = $section->id ?? 'section_' . $index;

      $sections[] = [
        'id' => $sectionId,
        'title' => $section->title,
        'content' => $content,
        'componentType' => $section->componentType,
        'index' => $index,
      ];
    }

    return new JsonResponse([
      'success' => TRUE,
      'plan' => [
        'title' => $plan->title,
        'summary' => $plan->summary,
        'targetAudience' => $plan->targetAudience,
        'estimatedReadTime' => $plan->estimatedReadTime,
        'sections' => $sections,
      ],
      'componentOptions' => $componentOptions,
    ]);
  }

  /**
   * Gets available component options for section dropdowns.
   *
   * @param mixed $session
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
        $canvasPage = $this->entityTypeManager()->getStorage('canvas_page')->load($templateId);
        if ($canvasPage) {
          $componentTree = $canvasPage->get('components');
          if ($componentTree) {
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
    if ($this->pageBuilderHelper) {
      try {
        $componentsBySource = $this->pageBuilderHelper->getAllComponentsKeyedBySource();
        $sdcComponents = $componentsBySource['sdc']['components'] ?? [];

        foreach ($sdcComponents as $componentId => $componentData) {
          $componentName = $componentData['name'] ?? $componentId;
          $suffix = isset($templateComponents[$componentId]) ? ' ★' : '';
          $options[$componentId] = $componentName . $suffix;
        }
      }
      catch (\Exception $e) {
        // Failed to load available components.
      }
    }

    // Fallback options if no SDC components available.
    if (empty($options)) {
      $options = [
        'text' => (string) $this->t('Text'),
        'heading' => (string) $this->t('Heading'),
        'image' => (string) $this->t('Image'),
        'accordion' => (string) $this->t('Accordion'),
        'card' => (string) $this->t('Card'),
        'list' => (string) $this->t('List'),
        'quote' => (string) $this->t('Quote'),
        'table' => (string) $this->t('Table'),
      ];
    }

    asort($options);

    return $options;
  }

  /**
   * Builds the plan preview render array.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan.
   *
   * @return array
   *   The render array.
   */
  protected function buildPlanPreview($plan): array {
    $sections = [];
    foreach ($plan->getSections() as $section) {
      $sections[] = [
        '#type' => 'details',
        '#title' => $section->getTitle(),
        '#open' => FALSE,
        'content' => [
          '#markup' => '<p>' . $this->truncateContent($section->getContent(), 200) . '</p>',
        ],
        'component' => [
          '#markup' => '<small>' . $this->t('Component: @type', ['@type' => $section->getComponentType()]) . '</small>',
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'plan-preview-wrapper'],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $plan->getTitle(),
      ],
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $plan->getSummary(),
        '#attributes' => ['class' => ['plan-summary']],
      ],
      'metadata' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['plan-metadata']],
        'audience' => [
          '#markup' => '<span>' . $this->t('Audience: @audience', ['@audience' => $plan->getTargetAudience()]) . '</span> ',
        ],
        'read_time' => [
          '#markup' => '<span>' . $this->t('Read time: @time min', ['@time' => $plan->getEstimatedReadTime()]) . '</span>',
        ],
      ],
      'sections' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['plan-sections']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('Sections'),
        ],
        'items' => $sections,
      ],
    ];
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
    if (strlen($content) <= $length) {
      return $content;
    }
    return substr($content, 0, $length) . '...';
  }

}
