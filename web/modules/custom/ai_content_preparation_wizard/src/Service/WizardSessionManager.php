<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\ai_content_preparation_wizard\Enum\WizardStep;
use Drupal\ai_content_preparation_wizard\Event\WizardStepChangedEvent;
use Drupal\ai_content_preparation_wizard\Model\ContentPlan;
use Drupal\ai_content_preparation_wizard\Model\ProcessedDocument;
use Drupal\ai_content_preparation_wizard\Model\WizardSession;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Manages wizard session state using PrivateTempStore.
 *
 * This service provides a higher-level API for managing the wizard session,
 * including session creation, updates, step management, and document/plan
 * storage.
 */
final class WizardSessionManager implements WizardSessionManagerInterface {

  /**
   * The tempstore key for wizard session data.
   *
   * @var string
   */
  private const TEMPSTORE_KEY = 'ai_content_preparation_wizard';

  /**
   * The session key within the tempstore.
   *
   * @var string
   */
  private const SESSION_KEY = 'wizard_session';

  /**
   * The private tempstore.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  private $tempStore;

  /**
   * Constructs a WizardSessionManager.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temp store factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   */
  public function __construct(
    PrivateTempStoreFactory $tempStoreFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {
    $this->tempStore = $tempStoreFactory->get(self::TEMPSTORE_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function getSession(): ?WizardSession {
    $data = $this->tempStore->get(self::SESSION_KEY);

    if ($data === NULL) {
      return NULL;
    }

    // If we have array data, reconstruct the session.
    if (is_array($data)) {
      return WizardSession::fromArray($data);
    }

    // If we somehow stored a WizardSession object directly.
    if ($data instanceof WizardSession) {
      return $data;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function createSession(): WizardSession {
    $session = new WizardSession(
      id: $this->uuid->generate(),
      userId: (int) $this->currentUser->id(),
      createdAt: $this->time->getRequestTime(),
    );

    $this->saveSession($session);

    return $session;
  }

  /**
   * {@inheritdoc}
   */
  public function updateSession(WizardSession $session): void {
    $this->saveSession($session);
  }

  /**
   * {@inheritdoc}
   */
  public function clearSession(): void {
    $this->tempStore->delete(self::SESSION_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentStep(): WizardStep {
    $session = $this->getSession();

    if ($session === NULL) {
      return WizardStep::UPLOAD;
    }

    return $session->getCurrentStep();
  }

  /**
   * {@inheritdoc}
   */
  public function setCurrentStep(WizardStep $step): void {
    $session = $this->getSession();

    if ($session === NULL) {
      $session = $this->createSession();
    }

    $previousStep = $session->getCurrentStep();

    // Only dispatch event and update if the step actually changes.
    if ($previousStep !== $step) {
      $session->setCurrentStep($step);
      $this->saveSession($session);

      // Dispatch the step changed event.
      $event = new WizardStepChangedEvent($session, $previousStep, $step);
      $this->eventDispatcher->dispatch($event, WizardStepChangedEvent::EVENT_NAME);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addProcessedDocument(ProcessedDocument $document): void {
    $session = $this->getSession();

    if ($session === NULL) {
      $session = $this->createSession();
    }

    $session->addProcessedDocument($document);
    $this->saveSession($session);
  }

  /**
   * {@inheritdoc}
   */
  public function setContentPlan(ContentPlan $plan): void {
    $session = $this->getSession();

    if ($session === NULL) {
      $session = $this->createSession();
    }

    $session->setContentPlan($plan);
    $this->saveSession($session);
  }

  /**
   * Gets the session or creates one if it doesn't exist.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\WizardSession
   *   The wizard session.
   */
  public function getOrCreateSession(): WizardSession {
    $session = $this->getSession();

    if ($session === NULL) {
      $session = $this->createSession();
    }

    return $session;
  }

  /**
   * Sets the selected contexts for the session.
   *
   * @param array $contexts
   *   The context identifiers.
   */
  public function setSelectedContexts(array $contexts): void {
    $session = $this->getOrCreateSession();
    $session->setSelectedContexts($contexts);
    $this->saveSession($session);
  }

  /**
   * Sets the AI template ID for the session.
   *
   * @param string $templateId
   *   The template ID.
   */
  public function setTemplateId(string $templateId): void {
    $session = $this->getOrCreateSession();
    $session->setTemplateId($templateId);
    $this->saveSession($session);
  }

  /**
   * Sets the uploaded file IDs for the session.
   *
   * @param array $fileIds
   *   The file entity IDs.
   */
  public function setUploadedFileIds(array $fileIds): void {
    $session = $this->getOrCreateSession();
    $session->setUploadedFileIds($fileIds);
    $this->saveSession($session);
  }

  /**
   * Sets the refinement instructions for the session.
   *
   * @param string|null $instructions
   *   The refinement instructions.
   */
  public function setRefinementInstructions(?string $instructions): void {
    $session = $this->getOrCreateSession();
    $session->setRefinementInstructions($instructions);
    $this->saveSession($session);
  }

  /**
   * Removes a processed document from the session.
   *
   * @param string $documentId
   *   The document ID to remove.
   */
  public function removeProcessedDocument(string $documentId): void {
    $session = $this->getSession();

    if ($session !== NULL) {
      $session->removeProcessedDocument($documentId);
      $this->saveSession($session);
    }
  }

  /**
   * Clears all processed documents from the session.
   */
  public function clearProcessedDocuments(): void {
    $session = $this->getSession();

    if ($session !== NULL) {
      $session->clearProcessedDocuments();
      $this->saveSession($session);
    }
  }

  /**
   * Clears the content plan from the session.
   */
  public function clearContentPlan(): void {
    $session = $this->getSession();

    if ($session !== NULL) {
      $session->clearContentPlan();
      $this->saveSession($session);
    }
  }

  /**
   * Checks if the session can proceed to the next step.
   *
   * @return bool
   *   TRUE if the session can proceed.
   */
  public function canProceed(): bool {
    $session = $this->getSession();

    if ($session === NULL) {
      return FALSE;
    }

    return $session->canProceed();
  }

  /**
   * Advances to the next wizard step if possible.
   *
   * @return bool
   *   TRUE if the step was advanced, FALSE otherwise.
   */
  public function advanceStep(): bool {
    $session = $this->getSession();

    if ($session === NULL || !$session->canProceed()) {
      return FALSE;
    }

    $currentStep = $session->getCurrentStep();
    $nextStep = $currentStep->next();

    if ($nextStep === NULL) {
      return FALSE;
    }

    $this->setCurrentStep($nextStep);
    return TRUE;
  }

  /**
   * Goes back to the previous wizard step if possible.
   *
   * @return bool
   *   TRUE if the step was moved back, FALSE otherwise.
   */
  public function previousStep(): bool {
    $session = $this->getSession();

    if ($session === NULL) {
      return FALSE;
    }

    $currentStep = $session->getCurrentStep();
    $prevStep = $currentStep->previous();

    if ($prevStep === NULL) {
      return FALSE;
    }

    $this->setCurrentStep($prevStep);
    return TRUE;
  }

  /**
   * Saves the session to the tempstore.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\WizardSession $session
   *   The session to save.
   */
  private function saveSession(WizardSession $session): void {
    // Store as array for reliable serialization.
    $this->tempStore->set(self::SESSION_KEY, $session->toArray());
  }

}
