<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Event;

use Drupal\ai_content_preparation_wizard\Enum\WizardStep;
use Drupal\ai_content_preparation_wizard\Model\WizardSession;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event dispatched when the wizard step changes.
 *
 * This event allows subscribers to react to step transitions,
 * enabling tasks such as logging, validation, or side effects.
 */
final class WizardStepChangedEvent extends Event {

  /**
   * The event name.
   *
   * @var string
   */
  public const EVENT_NAME = 'ai_content_preparation_wizard.step_changed';

  /**
   * Constructs a WizardStepChangedEvent.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\WizardSession $session
   *   The wizard session.
   * @param \Drupal\ai_content_preparation_wizard\Enum\WizardStep $previousStep
   *   The step being transitioned from.
   * @param \Drupal\ai_content_preparation_wizard\Enum\WizardStep $newStep
   *   The step being transitioned to.
   */
  public function __construct(
    public readonly WizardSession $session,
    public readonly WizardStep $previousStep,
    public readonly WizardStep $newStep,
  ) {}

  /**
   * Gets the wizard session.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\WizardSession
   *   The wizard session.
   */
  public function getSession(): WizardSession {
    return $this->session;
  }

  /**
   * Gets the previous step.
   *
   * @return \Drupal\ai_content_preparation_wizard\Enum\WizardStep
   *   The previous step.
   */
  public function getPreviousStep(): WizardStep {
    return $this->previousStep;
  }

  /**
   * Gets the new step.
   *
   * @return \Drupal\ai_content_preparation_wizard\Enum\WizardStep
   *   The new step.
   */
  public function getNewStep(): WizardStep {
    return $this->newStep;
  }

  /**
   * Checks if this is a forward transition.
   *
   * @return bool
   *   TRUE if moving forward in the wizard.
   */
  public function isForward(): bool {
    return $this->newStep->value > $this->previousStep->value;
  }

  /**
   * Checks if this is a backward transition.
   *
   * @return bool
   *   TRUE if moving backward in the wizard.
   */
  public function isBackward(): bool {
    return $this->newStep->value < $this->previousStep->value;
  }

}
