<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\ai_content_preparation_wizard\Model\ContentPlan;

/**
 * Interface for the content plan generator service.
 *
 * This service generates content plans using AI based on processed documents
 * and AI contexts, and allows refinement of existing plans based on user
 * instructions.
 */
interface ContentPlanGeneratorInterface {

  /**
   * Generates a content plan from processed documents, webpages, and AI contexts.
   *
   * Combines the content from multiple processed documents and webpages with
   * contextual information to create a structured content plan suitable for
   * Canvas pages.
   *
   * @param array<\Drupal\ai_content_preparation_wizard\Model\ProcessedDocument> $documents
   *   The processed documents to generate a plan from.
   * @param array<\Drupal\ai_content_preparation_wizard\Model\AIContext> $contexts
   *   An array of AI contexts providing additional context for generation.
   * @param string|null $templateId
   *   Optional template ID to use for generation.
   * @param array<string, mixed> $options
   *   Additional options for plan generation. May include:
   *   - 'target_audience': The intended audience.
   *   - 'tone': The desired tone (formal, casual, technical).
   *   - 'max_sections': Maximum number of sections to generate.
   *   - 'webpages': Array of ProcessedWebpage objects to include.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\ContentPlan
   *   The generated content plan.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\PlanGenerationException
   *   If plan generation fails.
   */
  public function generate(array $documents, array $contexts = [], ?string $templateId = NULL, array $options = []): ContentPlan;

  /**
   * Refines an existing content plan based on user instructions.
   *
   * Takes an existing plan and applies refinements based on the user's
   * instructions, updating sections as needed while preserving the plan's
   * history.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The existing plan to refine.
   * @param string $refinementPrompt
   *   The user's refinement instructions.
   * @param array<\Drupal\ai_content_preparation_wizard\Model\AIContext|string> $contexts
   *   An array of AI contexts to consider during refinement.
   * @param array<string, mixed> $options
   *   Additional options for refinement.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\ContentPlan
   *   The refined content plan with updated sections and refinement history.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\PlanGenerationException
   *   If refinement fails.
   */
  public function refine(ContentPlan $plan, string $refinementPrompt, array $contexts = [], array $options = []): ContentPlan;

  /**
   * Checks if refinement is available for a plan.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The plan to check.
   *
   * @return bool
   *   TRUE if the plan can be refined (has not exceeded max iterations).
   */
  public function canRefine(ContentPlan $plan): bool;

  /**
   * Gets the maximum number of allowed refinement iterations.
   *
   * @return int
   *   The maximum refinement count from configuration.
   */
  public function getMaxRefinementIterations(): int;

}
