<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\ai_content_preparation_wizard\Model\ContentPlan;
use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for the Canvas page creator service.
 *
 * This service handles the creation of Canvas pages from approved content plans,
 * transforming plan sections into Canvas components and building the page structure.
 */
interface CanvasCreatorInterface {

  /**
   * Creates a Canvas page entity from a content plan.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The approved content plan to create the page from.
   * @param array<string, mixed> $options
   *   Additional options for page creation:
   *   - 'alias': (string) URL alias for the page.
   *   - 'status': (bool) Publication status (default: FALSE).
   *   - 'owner': (int) User ID of the page owner.
   *   - 'description': (string) Meta description override.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The created and saved Canvas page entity.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\CanvasCreationException
   *   When page creation fails due to validation or entity save errors.
   */
  public function create(ContentPlan $plan, array $options = []): EntityInterface;

  /**
   * Maps content plan sections to Canvas component structures.
   *
   * Converts each PlanSection into an array structure suitable for
   * the Canvas component tree field.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan containing sections to map.
   *
   * @return array<int, array<string, mixed>>
   *   Array of component structures ready for the Canvas page.
   */
  public function mapToComponents(ContentPlan $plan): array;

}
