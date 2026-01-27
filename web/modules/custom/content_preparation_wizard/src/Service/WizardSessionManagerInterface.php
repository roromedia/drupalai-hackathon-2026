<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Service;

use Drupal\content_preparation_wizard\Enum\WizardStep;
use Drupal\content_preparation_wizard\Model\ContentPlan;
use Drupal\content_preparation_wizard\Model\ProcessedDocument;
use Drupal\content_preparation_wizard\Model\WizardSession;

/**
 * Interface for the wizard session manager.
 *
 * This service manages the wizard session state using PrivateTempStore,
 * providing methods to create, update, and clear session data.
 */
interface WizardSessionManagerInterface {

  /**
   * Gets the current wizard session.
   *
   * @return \Drupal\content_preparation_wizard\Model\WizardSession|null
   *   The current wizard session, or NULL if no session exists.
   */
  public function getSession(): ?WizardSession;

  /**
   * Creates a new wizard session.
   *
   * If a session already exists, it will be replaced.
   *
   * @return \Drupal\content_preparation_wizard\Model\WizardSession
   *   The newly created wizard session.
   */
  public function createSession(): WizardSession;

  /**
   * Updates the wizard session in storage.
   *
   * @param \Drupal\content_preparation_wizard\Model\WizardSession $session
   *   The session to update.
   */
  public function updateSession(WizardSession $session): void;

  /**
   * Clears the current wizard session.
   *
   * Removes all session data from storage.
   */
  public function clearSession(): void;

  /**
   * Gets the current wizard step.
   *
   * @return \Drupal\content_preparation_wizard\Enum\WizardStep
   *   The current step. Defaults to UPLOAD if no session exists.
   */
  public function getCurrentStep(): WizardStep;

  /**
   * Sets the current wizard step.
   *
   * Updates the session step and dispatches a WizardStepChangedEvent
   * if the step actually changes.
   *
   * @param \Drupal\content_preparation_wizard\Enum\WizardStep $step
   *   The new step.
   */
  public function setCurrentStep(WizardStep $step): void;

  /**
   * Adds a processed document to the session.
   *
   * @param \Drupal\content_preparation_wizard\Model\ProcessedDocument $document
   *   The processed document to add.
   */
  public function addProcessedDocument(ProcessedDocument $document): void;

  /**
   * Sets the content plan for the session.
   *
   * @param \Drupal\content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan to set.
   */
  public function setContentPlan(ContentPlan $plan): void;

}
