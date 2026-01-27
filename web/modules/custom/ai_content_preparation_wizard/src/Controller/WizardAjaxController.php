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
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for wizard AJAX operations.
 */
final class WizardAjaxController extends ControllerBase {

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
    return new static(
      $container->get('ai_content_preparation_wizard.wizard_session_manager'),
      $container->get('ai_content_preparation_wizard.content_plan_generator'),
      $container->get('renderer'),
    );
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
