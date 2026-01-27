<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Enum;

/**
 * Enum representing the overall status of a wizard session.
 *
 * This enum defines the possible states a wizard session can be in
 * throughout its lifecycle.
 */
enum WizardStatus: string {

  /**
   * Wizard session is currently in progress.
   */
  case IN_PROGRESS = 'in_progress';

  /**
   * Wizard session has been completed successfully.
   */
  case COMPLETED = 'completed';

  /**
   * Wizard session was abandoned by the user.
   */
  case ABANDONED = 'abandoned';

  /**
   * Gets a human-readable label for the status.
   *
   * @return string
   *   The human-readable label.
   */
  public function label(): string {
    return match ($this) {
      self::IN_PROGRESS => 'In Progress',
      self::COMPLETED => 'Completed',
      self::ABANDONED => 'Abandoned',
    };
  }

  /**
   * Gets a description of the status.
   *
   * @return string
   *   The status description.
   */
  public function description(): string {
    return match ($this) {
      self::IN_PROGRESS => 'The wizard session is currently active.',
      self::COMPLETED => 'The wizard session has finished successfully.',
      self::ABANDONED => 'The wizard session was cancelled or timed out.',
    };
  }

  /**
   * Checks if the wizard session is still active.
   *
   * @return bool
   *   TRUE if the session is active, FALSE otherwise.
   */
  public function isActive(): bool {
    return $this === self::IN_PROGRESS;
  }

  /**
   * Checks if the wizard session has ended.
   *
   * @return bool
   *   TRUE if the session has ended, FALSE otherwise.
   */
  public function isFinished(): bool {
    return $this === self::COMPLETED || $this === self::ABANDONED;
  }

  /**
   * Checks if the wizard session ended successfully.
   *
   * @return bool
   *   TRUE if the session completed successfully, FALSE otherwise.
   */
  public function isSuccessful(): bool {
    return $this === self::COMPLETED;
  }

}
