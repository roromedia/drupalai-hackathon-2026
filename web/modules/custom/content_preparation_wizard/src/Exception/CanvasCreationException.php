<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Exception;

/**
 * Exception thrown when Canvas page creation fails.
 *
 * This exception is thrown when the wizard fails to create a Canvas page,
 * whether due to validation errors, component tree building failures, or
 * entity save issues.
 *
 * @see \Drupal\content_preparation_wizard\Service\CanvasPageCreator
 */
class CanvasCreationException extends \RuntimeException {

  /**
   * Constructs a CanvasCreationException.
   *
   * @param string $message
   *   The exception message.
   * @param string|null $pageTitle
   *   The title of the page that failed to be created.
   * @param array|null $validationErrors
   *   An array of validation errors that caused the failure.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous throwable used for exception chaining.
   */
  public function __construct(
    string $message,
    public readonly ?string $pageTitle = NULL,
    public readonly ?array $validationErrors = NULL,
    int $code = 0,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $code, $previous);
  }

}
