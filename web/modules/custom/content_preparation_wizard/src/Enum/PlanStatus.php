<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Enum;

/**
 * Status values for content plans.
 */
enum PlanStatus: string {

  case DRAFT = 'draft';
  case GENERATING = 'generating';
  case READY = 'ready';
  case REFINING = 'refining';
  case APPROVED = 'approved';
  case CREATING = 'creating';
  case COMPLETED = 'completed';
  case FAILED = 'failed';

  /**
   * Gets a human-readable label for the status.
   */
  public function label(): string {
    return match ($this) {
      self::DRAFT => 'Draft',
      self::GENERATING => 'Generating',
      self::READY => 'Ready for Review',
      self::REFINING => 'Refining',
      self::APPROVED => 'Approved',
      self::CREATING => 'Creating Content',
      self::COMPLETED => 'Completed',
      self::FAILED => 'Failed',
    };
  }

  /**
   * Checks if the plan can be refined in this status.
   */
  public function canRefine(): bool {
    return match ($this) {
      self::DRAFT, self::READY, self::APPROVED => TRUE,
      default => FALSE,
    };
  }

  /**
   * Checks if the plan can proceed to content creation.
   */
  public function canCreate(): bool {
    return match ($this) {
      self::READY, self::APPROVED => TRUE,
      default => FALSE,
    };
  }

  /**
   * Checks if the status represents a terminal state.
   */
  public function isTerminal(): bool {
    return match ($this) {
      self::COMPLETED, self::FAILED => TRUE,
      default => FALSE,
    };
  }

  /**
   * Checks if the status represents an in-progress state.
   */
  public function isProcessing(): bool {
    return match ($this) {
      self::GENERATING, self::REFINING, self::CREATING => TRUE,
      default => FALSE,
    };
  }

}
