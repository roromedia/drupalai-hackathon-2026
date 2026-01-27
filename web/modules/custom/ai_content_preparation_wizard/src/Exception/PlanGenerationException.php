<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Exception;

/**
 * Exception thrown when AI plan generation fails.
 *
 * This exception is thrown when the AI service fails to generate a content
 * plan, whether due to provider errors, invalid responses, or configuration
 * issues.
 *
 * @see \Drupal\ai_content_preparation_wizard\Service\ContentPlanGenerator
 */
class PlanGenerationException extends \RuntimeException {

  /**
   * Constructs a PlanGenerationException.
   *
   * @param string $message
   *   The exception message.
   * @param string|null $aiProvider
   *   The AI provider that was used when the error occurred.
   * @param string|null $aiModel
   *   The AI model that was used when the error occurred.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous throwable used for exception chaining.
   */
  public function __construct(
    string $message,
    public readonly ?string $aiProvider = NULL,
    public readonly ?string $aiModel = NULL,
    int $code = 0,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $code, $previous);
  }

}
