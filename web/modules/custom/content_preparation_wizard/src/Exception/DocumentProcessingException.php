<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Exception;

/**
 * Exception thrown when document processing fails.
 *
 * This exception is thrown when a document processor encounters an error
 * during file parsing, content extraction, or format conversion.
 *
 * @see \Drupal\content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface
 */
class DocumentProcessingException extends \RuntimeException {

  /**
   * Constructs a DocumentProcessingException.
   *
   * @param string $message
   *   The exception message.
   * @param string|null $fileName
   *   The name of the file that failed processing.
   * @param string|null $processorId
   *   The ID of the processor that encountered the error.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous throwable used for exception chaining.
   */
  public function __construct(
    string $message,
    public readonly ?string $fileName = NULL,
    public readonly ?string $processorId = NULL,
    int $code = 0,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $code, $previous);
  }

}
