<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Exception;

/**
 * Exception thrown for invalid wizard state transitions.
 *
 * This exception is thrown when the wizard attempts an invalid state
 * transition, such as jumping to a step without completing prerequisites
 * or when required session data is missing.
 *
 * @see \Drupal\ai_content_preparation_wizard\Form\ContentPreparationWizardForm
 */
class InvalidWizardStateException extends \RuntimeException {

  /**
   * Constructs an InvalidWizardStateException.
   *
   * @param string $message
   *   The exception message.
   * @param int|null $currentStep
   *   The current step number when the error occurred.
   * @param int|null $targetStep
   *   The target step number that was attempted.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous throwable used for exception chaining.
   */
  public function __construct(
    string $message,
    public readonly ?int $currentStep = NULL,
    public readonly ?int $targetStep = NULL,
    int $code = 0,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $code, $previous);
  }

}
