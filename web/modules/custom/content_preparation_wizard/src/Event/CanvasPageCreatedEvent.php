<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Event;

use Drupal\content_preparation_wizard\Model\ContentPlan;
use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\EntityInterface;

/**
 * Event dispatched after a Canvas page has been created from a content plan.
 *
 * This event allows subscribers to react to Canvas page creation,
 * enabling tasks such as logging, notifications, analytics, or
 * additional processing after a page is created from a content plan.
 */
final class CanvasPageCreatedEvent extends Event {

  /**
   * The event name.
   *
   * @var string
   */
  public const EVENT_NAME = 'content_preparation_wizard.canvas_page_created';

  /**
   * Constructs a CanvasPageCreatedEvent.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The created Canvas page entity.
   * @param \Drupal\content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan that was used to create the page.
   */
  public function __construct(
    public readonly EntityInterface $page,
    public readonly ContentPlan $plan,
  ) {}

  /**
   * Gets the created Canvas page entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The Canvas page entity.
   */
  public function getPage(): EntityInterface {
    return $this->page;
  }

  /**
   * Gets the content plan used to create the page.
   *
   * @return \Drupal\content_preparation_wizard\Model\ContentPlan
   *   The content plan.
   */
  public function getPlan(): ContentPlan {
    return $this->plan;
  }

}
