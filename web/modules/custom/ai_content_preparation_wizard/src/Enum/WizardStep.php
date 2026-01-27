<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Enum;

/**
 * Enum representing wizard steps in the content preparation process.
 *
 * This enum defines the sequential steps a user progresses through
 * when using the Content Preparation Wizard.
 */
enum WizardStep: int {

  /**
   * Upload step - user uploads source content.
   */
  case UPLOAD = 1;

  /**
   * Plan step - AI generates and user reviews content plan.
   */
  case PLAN = 2;

  /**
   * Create step - content is created based on approved plan.
   */
  case CREATE = 3;

  /**
   * Gets a human-readable label for the wizard step.
   *
   * @return string
   *   The human-readable label.
   */
  public function label(): string {
    return match ($this) {
      self::UPLOAD => 'Upload Content',
      self::PLAN => 'Review Plan',
      self::CREATE => 'Create Content',
    };
  }

  /**
   * Gets a description of what happens in this step.
   *
   * @return string
   *   The step description.
   */
  public function description(): string {
    return match ($this) {
      self::UPLOAD => 'Upload your source documents or provide URLs to import.',
      self::PLAN => 'Review and approve the AI-generated content plan.',
      self::CREATE => 'Generate and review the final content.',
    };
  }

  /**
   * Gets the next wizard step, if available.
   *
   * @return self|null
   *   The next WizardStep enum case, or NULL if this is the last step.
   */
  public function next(): ?self {
    return match ($this) {
      self::UPLOAD => self::PLAN,
      self::PLAN => self::CREATE,
      self::CREATE => NULL,
    };
  }

  /**
   * Gets the previous wizard step, if available.
   *
   * @return self|null
   *   The previous WizardStep enum case, or NULL if this is the first step.
   */
  public function previous(): ?self {
    return match ($this) {
      self::UPLOAD => NULL,
      self::PLAN => self::UPLOAD,
      self::CREATE => self::PLAN,
    };
  }

  /**
   * Checks if this is the first step.
   *
   * @return bool
   *   TRUE if this is the first step, FALSE otherwise.
   */
  public function isFirst(): bool {
    return $this === self::UPLOAD;
  }

  /**
   * Checks if this is the last step.
   *
   * @return bool
   *   TRUE if this is the last step, FALSE otherwise.
   */
  public function isLast(): bool {
    return $this === self::CREATE;
  }

  /**
   * Gets the progress percentage for this step.
   *
   * @return int
   *   The progress percentage (0-100).
   */
  public function getProgress(): int {
    return match ($this) {
      self::UPLOAD => 33,
      self::PLAN => 66,
      self::CREATE => 100,
    };
  }

}
