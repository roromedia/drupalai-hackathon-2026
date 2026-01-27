<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_preparation_wizard\Attribute\DocumentProcessor;
use Drupal\ai_content_preparation_wizard\Enum\FileType;
use Drupal\ai_content_preparation_wizard\Enum\ProcessingProvider;
use Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException;
use Drupal\ai_content_preparation_wizard\Model\DocumentMetadata;
use Drupal\ai_content_preparation_wizard\Model\ProcessedDocument;
use Drupal\file\FileInterface;

/**
 * Document processor for plain text files.
 *
 * This is a simple processor that reads plain text files directly.
 * It has no external dependencies and works with .txt files.
 */
#[DocumentProcessor(
  id: 'plain_text',
  label: new TranslatableMarkup('Plain Text Processor'),
  description: new TranslatableMarkup('Reads plain text files directly. No external dependencies required.'),
  supported_extensions: ['txt'],
  weight: 10,
)]
class PlainTextProcessor extends DocumentProcessorBase {

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): bool {
    // Plain text processing has no external requirements.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequirementErrors(): array {
    // No requirements to check.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function process(FileInterface $file): ProcessedDocument {
    // Validate file access.
    if (!$this->validateFileAccess($file)) {
      throw new DocumentProcessingException(
        $this->t('File is not accessible: @filename', [
          '@filename' => $file->getFilename(),
        ])->render(),
        $file->getFilename(),
        $this->getPluginId()
      );
    }

    $realPath = $this->getRealPath($file);
    if ($realPath === FALSE) {
      throw new DocumentProcessingException(
        $this->t('Could not resolve file path for: @filename', [
          '@filename' => $file->getFilename(),
        ])->render(),
        $file->getFilename(),
        $this->getPluginId()
      );
    }

    $this->logInfo('Processing plain text file', $file);

    try {
      // Read the file content directly.
      $content = file_get_contents($realPath);

      if ($content === FALSE) {
        throw new DocumentProcessingException(
          $this->t('Failed to read file: @filename', [
            '@filename' => $file->getFilename(),
          ])->render(),
          $file->getFilename(),
          $this->getPluginId()
        );
      }

      // Convert to UTF-8 if necessary.
      $content = $this->ensureUtf8($content);

      // Normalize line endings.
      $content = $this->normalizeLineEndings($content);

      // Extract metadata.
      $metadata = $this->extractMetadata($file);

      // Create the processed document.
      return ProcessedDocument::create(
        fileId: (int) $file->id(),
        fileName: $file->getFilename(),
        fileType: FileType::TXT,
        markdownContent: $content,
        metadata: $metadata,
        provider: ProcessingProvider::PHP_NATIVE,
      );
    }
    catch (DocumentProcessingException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logError('Plain text processing failed: @error', $file, [
        '@error' => $e->getMessage(),
      ]);
      throw new DocumentProcessingException(
        $this->t('Failed to process document: @error', [
          '@error' => $e->getMessage(),
        ])->render(),
        $file->getFilename(),
        $this->getPluginId(),
        0,
        $e
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function extractMetadata(FileInterface $file): DocumentMetadata {
    if (!$this->validateFileAccess($file)) {
      return DocumentMetadata::empty();
    }

    $realPath = $this->getRealPath($file);
    if ($realPath === FALSE) {
      return DocumentMetadata::empty();
    }

    try {
      $content = file_get_contents($realPath);
      if ($content === FALSE) {
        return DocumentMetadata::empty();
      }

      $content = $this->ensureUtf8($content);

      // Calculate basic statistics.
      $wordCount = str_word_count($content);
      $characterCount = mb_strlen($content);
      $lineCount = substr_count($content, "\n") + 1;

      // Try to extract a title from the first non-empty line.
      $title = $this->extractTitleFromContent($content);

      // Detect language if possible.
      $language = $this->detectLanguage($content);

      return new DocumentMetadata(
        title: $title,
        author: NULL,
        createdDate: $this->getFileCreationDate($realPath),
        language: $language,
        headings: [],
        customProperties: [
          'word_count' => $wordCount,
          'character_count' => $characterCount,
          'line_count' => $lineCount,
          'file_size' => $this->getFileSize($file),
        ],
      );
    }
    catch (\Exception $e) {
      $this->logWarning('Metadata extraction failed: @error', $file, [
        '@error' => $e->getMessage(),
      ]);
      return DocumentMetadata::empty();
    }
  }

  /**
   * Ensures content is valid UTF-8.
   *
   * @param string $content
   *   The content to check.
   *
   * @return string
   *   UTF-8 encoded content.
   */
  protected function ensureUtf8(string $content): string {
    // Check if already valid UTF-8.
    if (mb_check_encoding($content, 'UTF-8')) {
      return $content;
    }

    // Try to detect encoding and convert.
    $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], TRUE);

    if ($encoding !== FALSE && $encoding !== 'UTF-8') {
      $converted = mb_convert_encoding($content, 'UTF-8', $encoding);
      if ($converted !== FALSE) {
        return $converted;
      }
    }

    // Last resort: strip invalid characters.
    return mb_convert_encoding($content, 'UTF-8', 'UTF-8');
  }

  /**
   * Normalizes line endings to Unix style.
   *
   * @param string $content
   *   The content to normalize.
   *
   * @return string
   *   Content with normalized line endings.
   */
  protected function normalizeLineEndings(string $content): string {
    // Convert Windows (CRLF) and old Mac (CR) line endings to Unix (LF).
    return str_replace(["\r\n", "\r"], "\n", $content);
  }

  /**
   * Extracts a title from the first non-empty line of content.
   *
   * @param string $content
   *   The content to extract from.
   *
   * @return string|null
   *   The extracted title, or NULL if none found.
   */
  protected function extractTitleFromContent(string $content): ?string {
    $lines = explode("\n", $content);

    foreach ($lines as $line) {
      $line = trim($line);
      if (!empty($line)) {
        // Limit title length.
        if (mb_strlen($line) > 200) {
          $line = mb_substr($line, 0, 197) . '...';
        }
        return $line;
      }
    }

    return NULL;
  }

  /**
   * Attempts to detect the language of the content.
   *
   * @param string $content
   *   The content to analyze.
   *
   * @return string|null
   *   The detected language code, or NULL if unknown.
   */
  protected function detectLanguage(string $content): ?string {
    // Simple heuristic: check for common language patterns.
    // This is a basic implementation - could be enhanced with a language
    // detection library.

    // Take a sample of the content for analysis.
    $sample = mb_substr($content, 0, 1000);

    // Check for common language indicators.
    $patterns = [
      'en' => ['/\bthe\b/i', '/\band\b/i', '/\bof\b/i'],
      'de' => ['/\bund\b/i', '/\bder\b/i', '/\bdie\b/i'],
      'fr' => ['/\bet\b/i', '/\ble\b/i', '/\bla\b/i'],
      'es' => ['/\by\b/i', '/\bel\b/i', '/\bla\b/i'],
      'nl' => ['/\ben\b/i', '/\bde\b/i', '/\bhet\b/i'],
    ];

    $scores = [];
    foreach ($patterns as $lang => $langPatterns) {
      $scores[$lang] = 0;
      foreach ($langPatterns as $pattern) {
        $scores[$lang] += preg_match_all($pattern, $sample);
      }
    }

    // Return the language with the highest score if it's significant.
    arsort($scores);
    $topLang = array_key_first($scores);

    if ($scores[$topLang] >= 5) {
      return $topLang;
    }

    return NULL;
  }

  /**
   * Gets the file creation date.
   *
   * @param string $realPath
   *   The real filesystem path.
   *
   * @return \DateTimeImmutable|null
   *   The creation date, or NULL if unavailable.
   */
  protected function getFileCreationDate(string $realPath): ?\DateTimeImmutable {
    $mtime = filemtime($realPath);
    if ($mtime === FALSE) {
      return NULL;
    }

    try {
      return (new \DateTimeImmutable())->setTimestamp($mtime);
    }
    catch (\Exception) {
      return NULL;
    }
  }

}
