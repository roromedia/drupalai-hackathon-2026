<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

/**
 * Interface for the Pandoc converter service.
 */
interface PandocConverterInterface {

  /**
   * Converts a file to markdown using Pandoc.
   *
   * @param string $filePath
   *   The path to the file to convert.
   * @param string $format
   *   The input format (e.g., 'docx', 'pdf', 'odt', 'rtf').
   *
   * @return string
   *   The converted markdown content.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException
   *   If conversion fails.
   */
  public function convertToMarkdown(string $filePath, string $format): string;

  /**
   * Extracts metadata from a file using Pandoc.
   *
   * @param string $filePath
   *   The path to the file.
   * @param string $format
   *   The input format.
   *
   * @return array
   *   An array of metadata key-value pairs.
   */
  public function extractMetadata(string $filePath, string $format): array;

  /**
   * Checks if the Pandoc binary is available.
   *
   * @return bool
   *   TRUE if Pandoc is available, FALSE otherwise.
   */
  public function isAvailable(): bool;

  /**
   * Gets the Pandoc version.
   *
   * @return string|null
   *   The Pandoc version string, or NULL if not available.
   */
  public function getVersion(): ?string;

  /**
   * Gets the path to the Pandoc binary.
   *
   * @return string
   *   The configured Pandoc binary path.
   */
  public function getPandocPath(): string;

}
