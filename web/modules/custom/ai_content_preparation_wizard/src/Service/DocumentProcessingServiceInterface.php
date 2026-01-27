<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\ai_content_preparation_wizard\Model\ProcessedDocument;
use Drupal\file\FileInterface;

/**
 * Interface for the document processing service.
 *
 * This service orchestrates document processing by finding appropriate
 * plugins and converting files to processed documents.
 */
interface DocumentProcessingServiceInterface {

  /**
   * Processes a file and extracts its content.
   *
   * Finds an appropriate document processor plugin for the file type
   * and delegates processing to it. Dispatches a DocumentProcessedEvent
   * upon successful processing.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to process.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\ProcessedDocument
   *   The processed document with extracted content.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException
   *   When no suitable processor is found or processing fails.
   */
  public function process(FileInterface $file): ProcessedDocument;

  /**
   * Gets all supported file extensions across all processors.
   *
   * Aggregates the supported extensions from all available document
   * processor plugins.
   *
   * @return array
   *   Array of supported file extensions (without dots), e.g., ['txt', 'docx', 'pdf'].
   */
  public function getSupportedExtensions(): array;

  /**
   * Validates a file before processing.
   *
   * Checks whether the file can be processed (extension supported,
   * file readable, size within limits, etc.).
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to validate.
   *
   * @return array
   *   An array of validation error messages. Empty if validation passes.
   */
  public function validate(FileInterface $file): array;

}
