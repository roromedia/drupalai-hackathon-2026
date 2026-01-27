<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Model;

use Drupal\ai_content_preparation_wizard\Enum\WizardStep;

/**
 * Root aggregate tracking wizard session state.
 *
 * This is the main state container for a wizard session, holding all
 * information about the user's progress through the wizard including
 * uploaded documents, selected contexts, generated content plans, and
 * current step.
 */
final class WizardSession {

  /**
   * The processed documents in this session.
   *
   * @var \Drupal\ai_content_preparation_wizard\Model\ProcessedDocument[]
   */
  private array $processedDocuments = [];

  /**
   * The content plan for this session.
   *
   * @var \Drupal\ai_content_preparation_wizard\Model\ContentPlan|null
   */
  private ?ContentPlan $contentPlan = NULL;

  /**
   * The current wizard step.
   *
   * @var \Drupal\ai_content_preparation_wizard\Enum\WizardStep
   */
  private WizardStep $currentStep;

  /**
   * Selected context identifiers for AI processing.
   *
   * @var array
   */
  private array $selectedContexts = [];

  /**
   * The selected AI template ID.
   *
   * @var string|null
   */
  private ?string $templateId = NULL;

  /**
   * File IDs of uploaded files.
   *
   * @var int[]
   */
  private array $uploadedFileIds = [];

  /**
   * Refinement instructions provided by the user.
   *
   * @var string|null
   */
  private ?string $refinementInstructions = NULL;

  /**
   * Timestamp when the session was last updated.
   *
   * @var int
   */
  private int $updatedAt;

  /**
   * Constructs a WizardSession.
   *
   * @param string $id
   *   The unique session ID.
   * @param int $userId
   *   The user ID who owns this session.
   * @param int $createdAt
   *   Unix timestamp when the session was created.
   */
  public function __construct(
    public readonly string $id,
    public readonly int $userId,
    public readonly int $createdAt,
  ) {
    $this->currentStep = WizardStep::UPLOAD;
    $this->updatedAt = $createdAt;
  }

  /**
   * Gets the session ID.
   *
   * @return string
   *   The session ID.
   */
  public function getId(): string {
    return $this->id;
  }

  /**
   * Gets the user ID.
   *
   * @return int
   *   The user ID.
   */
  public function getUserId(): int {
    return $this->userId;
  }

  /**
   * Gets the current wizard step.
   *
   * @return \Drupal\ai_content_preparation_wizard\Enum\WizardStep
   *   The current step.
   */
  public function getCurrentStep(): WizardStep {
    return $this->currentStep;
  }

  /**
   * Sets the current wizard step.
   *
   * @param \Drupal\ai_content_preparation_wizard\Enum\WizardStep $step
   *   The new step.
   *
   * @return self
   *   This session for chaining.
   */
  public function setCurrentStep(WizardStep $step): self {
    $this->currentStep = $step;
    $this->touch();
    return $this;
  }

  /**
   * Gets all processed documents.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\ProcessedDocument[]
   *   The processed documents.
   */
  public function getProcessedDocuments(): array {
    return $this->processedDocuments;
  }

  /**
   * Adds a processed document to the session.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ProcessedDocument $document
   *   The processed document.
   *
   * @return self
   *   This session for chaining.
   */
  public function addProcessedDocument(ProcessedDocument $document): self {
    $this->processedDocuments[$document->id] = $document;
    $this->touch();
    return $this;
  }

  /**
   * Removes a processed document by ID.
   *
   * @param string $documentId
   *   The document ID to remove.
   *
   * @return self
   *   This session for chaining.
   */
  public function removeProcessedDocument(string $documentId): self {
    unset($this->processedDocuments[$documentId]);
    $this->touch();
    return $this;
  }

  /**
   * Clears all processed documents.
   *
   * @return self
   *   This session for chaining.
   */
  public function clearProcessedDocuments(): self {
    $this->processedDocuments = [];
    $this->touch();
    return $this;
  }

  /**
   * Gets the content plan.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\ContentPlan|null
   *   The content plan, or NULL if not yet generated.
   */
  public function getContentPlan(): ?ContentPlan {
    return $this->contentPlan;
  }

  /**
   * Sets the content plan.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan.
   *
   * @return self
   *   This session for chaining.
   */
  public function setContentPlan(ContentPlan $plan): self {
    $this->contentPlan = $plan;
    $this->touch();
    return $this;
  }

  /**
   * Clears the content plan.
   *
   * @return self
   *   This session for chaining.
   */
  public function clearContentPlan(): self {
    $this->contentPlan = NULL;
    $this->touch();
    return $this;
  }

  /**
   * Gets the selected contexts.
   *
   * @return array
   *   The selected context identifiers.
   */
  public function getSelectedContexts(): array {
    return $this->selectedContexts;
  }

  /**
   * Sets the selected contexts.
   *
   * @param array $contexts
   *   The context identifiers.
   *
   * @return self
   *   This session for chaining.
   */
  public function setSelectedContexts(array $contexts): self {
    $this->selectedContexts = $contexts;
    $this->touch();
    return $this;
  }

  /**
   * Gets the selected template ID.
   *
   * @return string|null
   *   The template ID, or NULL if not set.
   */
  public function getTemplateId(): ?string {
    return $this->templateId;
  }

  /**
   * Sets the template ID.
   *
   * @param string $templateId
   *   The template ID.
   *
   * @return self
   *   This session for chaining.
   */
  public function setTemplateId(string $templateId): self {
    $this->templateId = $templateId;
    $this->touch();
    return $this;
  }

  /**
   * Gets the uploaded file IDs.
   *
   * @return int[]
   *   The file entity IDs.
   */
  public function getUploadedFileIds(): array {
    return $this->uploadedFileIds;
  }

  /**
   * Sets the uploaded file IDs.
   *
   * @param int[] $fileIds
   *   The file entity IDs.
   *
   * @return self
   *   This session for chaining.
   */
  public function setUploadedFileIds(array $fileIds): self {
    $this->uploadedFileIds = array_map('intval', $fileIds);
    $this->touch();
    return $this;
  }

  /**
   * Adds an uploaded file ID.
   *
   * @param int $fileId
   *   The file entity ID.
   *
   * @return self
   *   This session for chaining.
   */
  public function addUploadedFileId(int $fileId): self {
    if (!in_array($fileId, $this->uploadedFileIds, TRUE)) {
      $this->uploadedFileIds[] = $fileId;
      $this->touch();
    }
    return $this;
  }

  /**
   * Gets the refinement instructions.
   *
   * @return string|null
   *   The refinement instructions, or NULL if not set.
   */
  public function getRefinementInstructions(): ?string {
    return $this->refinementInstructions;
  }

  /**
   * Sets the refinement instructions.
   *
   * @param string|null $instructions
   *   The refinement instructions.
   *
   * @return self
   *   This session for chaining.
   */
  public function setRefinementInstructions(?string $instructions): self {
    $this->refinementInstructions = $instructions;
    $this->touch();
    return $this;
  }

  /**
   * Gets the creation timestamp.
   *
   * @return int
   *   Unix timestamp when the session was created.
   */
  public function getCreatedAt(): int {
    return $this->createdAt;
  }

  /**
   * Gets the last update timestamp.
   *
   * @return int
   *   Unix timestamp when the session was last updated.
   */
  public function getUpdatedAt(): int {
    return $this->updatedAt;
  }

  /**
   * Checks if the session has processed documents.
   *
   * @return bool
   *   TRUE if there are processed documents.
   */
  public function hasProcessedDocuments(): bool {
    return !empty($this->processedDocuments);
  }

  /**
   * Checks if the session has a content plan.
   *
   * @return bool
   *   TRUE if a content plan exists.
   */
  public function hasContentPlan(): bool {
    return $this->contentPlan !== NULL;
  }

  /**
   * Checks if the session can proceed to the next step.
   *
   * @return bool
   *   TRUE if the session can proceed.
   */
  public function canProceed(): bool {
    return match ($this->currentStep) {
      WizardStep::UPLOAD => $this->hasProcessedDocuments() && $this->templateId !== NULL,
      WizardStep::PLAN => $this->hasContentPlan(),
      WizardStep::CREATE => TRUE,
    };
  }

  /**
   * Updates the updatedAt timestamp.
   */
  private function touch(): void {
    $this->updatedAt = time();
  }

  /**
   * Converts the session to an array for serialization.
   *
   * @return array
   *   The array representation.
   */
  public function toArray(): array {
    $processedDocs = [];
    foreach ($this->processedDocuments as $doc) {
      $processedDocs[$doc->id] = $doc->toArray();
    }

    return [
      'id' => $this->id,
      'user_id' => $this->userId,
      'created_at' => $this->createdAt,
      'updated_at' => $this->updatedAt,
      'current_step' => $this->currentStep->value,
      'processed_documents' => $processedDocs,
      'content_plan' => $this->contentPlan?->toArray(),
      'selected_contexts' => $this->selectedContexts,
      'template_id' => $this->templateId,
      'uploaded_file_ids' => $this->uploadedFileIds,
      'refinement_instructions' => $this->refinementInstructions,
    ];
  }

  /**
   * Creates a WizardSession from an array.
   *
   * @param array $data
   *   The array data.
   *
   * @return self
   *   The WizardSession instance.
   */
  public static function fromArray(array $data): self {
    $session = new self(
      id: $data['id'] ?? '',
      userId: $data['user_id'] ?? 0,
      createdAt: $data['created_at'] ?? time(),
    );

    $session->updatedAt = $data['updated_at'] ?? $session->createdAt;
    $session->currentStep = WizardStep::from($data['current_step'] ?? WizardStep::UPLOAD->value);
    $session->selectedContexts = $data['selected_contexts'] ?? [];
    $session->templateId = $data['template_id'] ?? NULL;
    $session->uploadedFileIds = $data['uploaded_file_ids'] ?? [];
    $session->refinementInstructions = $data['refinement_instructions'] ?? NULL;

    if (!empty($data['processed_documents'])) {
      foreach ($data['processed_documents'] as $docData) {
        $session->processedDocuments[$docData['id']] = ProcessedDocument::fromArray($docData);
      }
    }

    if (!empty($data['content_plan'])) {
      $session->contentPlan = ContentPlan::fromArray($data['content_plan']);
    }

    return $session;
  }

}
