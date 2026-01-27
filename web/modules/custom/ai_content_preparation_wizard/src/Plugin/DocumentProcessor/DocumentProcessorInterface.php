<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\file\FileInterface;

/**
 * Interface for document processor plugins.
 *
 * Document processors are responsible for extracting content and metadata
 * from various file types. Each processor handles specific file extensions
 * and provides methods for content extraction, metadata parsing, and
 * requirement validation.
 *
 * @see \Drupal\ai_content_preparation_wizard\Attribute\DocumentProcessor
 * @see \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorBase
 * @see \Drupal\ai_content_preparation_wizard\PluginManager\DocumentProcessorPluginManager
 */
interface DocumentProcessorInterface extends PluginInspectionInterface {

  /**
   * Determines if this processor can handle the given file.
   *
   * This method checks whether the processor supports the file based on
   * its extension, MIME type, or other characteristics.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to check.
   *
   * @return bool
   *   TRUE if this processor can handle the file, FALSE otherwise.
   */
  public function canProcess(FileInterface $file): bool;

  /**
   * Processes a file and extracts its content.
   *
   * This is the main processing method that extracts text content,
   * structure, and other relevant data from the file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to process.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\ProcessedDocument
   *   A ProcessedDocument object containing the extracted content.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException
   *   Thrown if the document cannot be processed.
   */
  public function process(FileInterface $file): object;

  /**
   * Extracts metadata from a file without full processing.
   *
   * This method performs a lightweight extraction of metadata such as
   * author, creation date, page count, etc. without extracting the
   * full content.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to extract metadata from.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\DocumentMetadata
   *   A DocumentMetadata object containing the extracted metadata.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\MetadataExtractionException
   *   Thrown if metadata cannot be extracted.
   */
  public function extractMetadata(FileInterface $file): object;

  /**
   * Returns the list of file extensions this processor supports.
   *
   * @return array
   *   An array of lowercase file extensions (without dots)
   *   that this processor can handle.
   */
  public function getSupportedExtensions(): array;

  /**
   * Checks if all requirements for this processor are met.
   *
   * This method validates that all necessary PHP extensions, libraries,
   * or other dependencies are available for this processor to function.
   *
   * @return bool
   *   TRUE if all requirements are met, FALSE otherwise.
   */
  public function checkRequirements(): bool;

  /**
   * Returns detailed information about unmet requirements.
   *
   * @return array
   *   An array of requirement problems, where each element contains:
   *   - 'type': The type of requirement that is not met.
   *   - 'name': The name of the missing requirement.
   *   - 'description': A human-readable description of the problem.
   *   Returns an empty array if all requirements are met.
   */
  public function getRequirementErrors(): array;

  /**
   * Returns the translated plugin label.
   *
   * @return string
   *   The human-readable plugin label.
   */
  public function label(): string;

  /**
   * Returns the translated plugin description.
   *
   * @return string
   *   The human-readable plugin description.
   */
  public function description(): string;

  /**
   * Returns the plugin weight for ordering.
   *
   * @return int
   *   The weight value. Lower weights are processed first.
   */
  public function getWeight(): int;

}
