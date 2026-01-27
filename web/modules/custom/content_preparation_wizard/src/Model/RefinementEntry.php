<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Model;

/**
 * Immutable value object representing a refinement entry in a content plan.
 *
 * Tracks the history of refinements made to a content plan, including
 * the user's instructions and the AI's response.
 */
final class RefinementEntry {

  /**
   * Constructs a RefinementEntry object.
   *
   * @param string $id
   *   Unique identifier for this refinement.
   * @param string $instructions
   *   The user's refinement instructions.
   * @param string $response
   *   The AI's response/changes made.
   * @param \DateTimeImmutable $createdAt
   *   When this refinement was made.
   * @param array<string> $affectedSections
   *   IDs of sections that were modified.
   * @param string|null $userId
   *   The ID of the user who requested the refinement.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $instructions,
    public readonly string $response,
    public readonly \DateTimeImmutable $createdAt,
    public readonly array $affectedSections = [],
    public readonly ?string $userId = NULL,
  ) {}

  /**
   * Checks if this refinement affected a specific section.
   *
   * @param string $sectionId
   *   The section ID to check.
   *
   * @return bool
   *   TRUE if the section was affected.
   */
  public function affectedSection(string $sectionId): bool {
    return in_array($sectionId, $this->affectedSections, TRUE);
  }

  /**
   * Gets a summary of the refinement for display.
   *
   * @param int $maxLength
   *   Maximum length of the summary.
   *
   * @return string
   *   A truncated summary of the instructions.
   */
  public function getSummary(int $maxLength = 100): string {
    if (mb_strlen($this->instructions) <= $maxLength) {
      return $this->instructions;
    }

    return mb_substr($this->instructions, 0, $maxLength - 3) . '...';
  }

  /**
   * Gets the number of affected sections.
   *
   * @return int
   *   The count of affected sections.
   */
  public function getAffectedSectionCount(): int {
    return count($this->affectedSections);
  }

  /**
   * Converts the entry to an array for serialization.
   *
   * @return array<string, mixed>
   *   The entry as an associative array.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'instructions' => $this->instructions,
      'response' => $this->response,
      'created_at' => $this->createdAt->format(\DateTimeInterface::RFC3339),
      'affected_sections' => $this->affectedSections,
      'user_id' => $this->userId,
    ];
  }

  /**
   * Creates a RefinementEntry instance from an array.
   *
   * @param array<string, mixed> $data
   *   The data array from serialization.
   *
   * @return self
   *   A new RefinementEntry instance.
   *
   * @throws \InvalidArgumentException
   *   If required data is missing.
   */
  public static function fromArray(array $data): self {
    $requiredFields = ['id', 'instructions', 'response', 'created_at'];
    foreach ($requiredFields as $field) {
      if (!isset($data[$field])) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }

    return new self(
      id: $data['id'],
      instructions: $data['instructions'],
      response: $data['response'],
      createdAt: new \DateTimeImmutable($data['created_at']),
      affectedSections: $data['affected_sections'] ?? [],
      userId: $data['user_id'] ?? NULL,
    );
  }

  /**
   * Creates a new RefinementEntry with a generated unique ID.
   *
   * @param string $instructions
   *   The user's refinement instructions.
   * @param string $response
   *   The AI's response.
   * @param array<string> $affectedSections
   *   IDs of affected sections.
   * @param string|null $userId
   *   The user ID.
   *
   * @return self
   *   A new RefinementEntry instance.
   */
  public static function create(
    string $instructions,
    string $response,
    array $affectedSections = [],
    ?string $userId = NULL,
  ): self {
    return new self(
      id: 'refinement_' . bin2hex(random_bytes(6)),
      instructions: $instructions,
      response: $response,
      createdAt: new \DateTimeImmutable(),
      affectedSections: $affectedSections,
      userId: $userId,
    );
  }

}
