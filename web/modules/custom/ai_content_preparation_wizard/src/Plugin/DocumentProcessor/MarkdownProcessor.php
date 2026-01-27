<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_preparation_wizard\Attribute\DocumentProcessor;
use Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException;
use Drupal\ai_content_preparation_wizard\Model\DocumentMetadata;
use Drupal\ai_content_preparation_wizard\Model\ProcessedDocument;
use Drupal\file\FileInterface;

/**
 * Document processor for markdown files.
 *
 * This is a passthrough processor for markdown files. Since the wizard
 * works with markdown internally, this processor simply reads the file
 * content and parses any YAML frontmatter for metadata.
 */
#[DocumentProcessor(
  id: 'markdown',
  label: new TranslatableMarkup('Markdown Processor'),
  description: new TranslatableMarkup('Passthrough processor for markdown files. Parses YAML frontmatter for metadata.'),
  supported_extensions: ['md', 'markdown'],
  weight: 5,
)]
class MarkdownProcessor extends DocumentProcessorBase {

  /**
   * Regular expression for matching YAML frontmatter.
   */
  protected const FRONTMATTER_PATTERN = '/^---\s*\n(.+?)\n---\s*\n?/s';

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): bool {
    // Markdown processing has no external requirements.
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

    $this->logInfo('Processing markdown file', $file);

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

      // Ensure UTF-8 encoding.
      if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
      }

      // Normalize line endings.
      $content = str_replace(["\r\n", "\r"], "\n", $content);

      // Extract metadata (including from frontmatter).
      $metadata = $this->extractMetadata($file);

      // If there's frontmatter, we might want to strip it from the content
      // for processing purposes, but keep the markdown body.
      $bodyContent = $this->stripFrontmatter($content);

      // Create the processed document with the body content (no frontmatter).
      return new ProcessedDocument(
        id: \Drupal::service('uuid')->generate(),
        originalFilename: $file->getFilename(),
        content: $bodyContent,
        processorId: $this->getPluginId(),
        metadata: $metadata->toArray(),
        processedAt: (int) \Drupal::time()->getRequestTime(),
        fileId: (int) $file->id(),
      );
    }
    catch (DocumentProcessingException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logError('Markdown processing failed: @error', $file, [
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

      // Normalize line endings.
      $content = str_replace(["\r\n", "\r"], "\n", $content);

      // Parse YAML frontmatter if present.
      $frontmatterData = $this->parseFrontmatter($content);

      // Extract headings from the markdown body.
      $bodyContent = $this->stripFrontmatter($content);
      $headings = $this->extractHeadings($bodyContent);

      // Calculate statistics.
      $wordCount = str_word_count(strip_tags($bodyContent));
      $characterCount = mb_strlen(strip_tags($bodyContent));

      // Build metadata from frontmatter.
      $title = $frontmatterData['title'] ?? $this->extractTitleFromHeadings($headings);
      $author = $frontmatterData['author'] ?? $frontmatterData['authors'] ?? NULL;
      if (is_array($author)) {
        $author = implode(', ', $author);
      }

      $createdDate = NULL;
      if (!empty($frontmatterData['date'])) {
        try {
          $createdDate = new \DateTimeImmutable($frontmatterData['date']);
        }
        catch (\Exception) {
          // Invalid date format, ignore.
        }
      }

      $language = $frontmatterData['lang'] ?? $frontmatterData['language'] ?? NULL;

      // Collect additional frontmatter as custom properties.
      $customProperties = [
        'word_count' => $wordCount,
        'character_count' => $characterCount,
        'file_size' => $this->getFileSize($file),
        'has_frontmatter' => !empty($frontmatterData),
      ];

      // Add any extra frontmatter fields.
      $standardFields = ['title', 'author', 'authors', 'date', 'lang', 'language'];
      foreach ($frontmatterData as $key => $value) {
        if (!in_array($key, $standardFields, TRUE)) {
          $customProperties['frontmatter_' . $key] = is_array($value) ? implode(', ', $value) : $value;
        }
      }

      return new DocumentMetadata(
        title: $title,
        author: $author,
        createdDate: $createdDate,
        language: $language,
        headings: $headings,
        customProperties: $customProperties,
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
   * Parses YAML frontmatter from markdown content.
   *
   * @param string $content
   *   The markdown content.
   *
   * @return array
   *   Parsed frontmatter data, or empty array if none found.
   */
  protected function parseFrontmatter(string $content): array {
    if (!preg_match(self::FRONTMATTER_PATTERN, $content, $matches)) {
      return [];
    }

    $yamlContent = $matches[1];

    // Parse YAML manually since we might not have Symfony YAML component.
    return $this->parseSimpleYaml($yamlContent);
  }

  /**
   * Strips YAML frontmatter from markdown content.
   *
   * @param string $content
   *   The markdown content.
   *
   * @return string
   *   Content without frontmatter.
   */
  protected function stripFrontmatter(string $content): string {
    return preg_replace(self::FRONTMATTER_PATTERN, '', $content);
  }

  /**
   * Parses simple YAML frontmatter.
   *
   * This is a basic YAML parser for frontmatter. It handles simple
   * key-value pairs and basic lists.
   *
   * @param string $yaml
   *   The YAML content.
   *
   * @return array
   *   Parsed data.
   */
  protected function parseSimpleYaml(string $yaml): array {
    // Try using Symfony YAML if available.
    if (class_exists('Symfony\Component\Yaml\Yaml')) {
      try {
        return \Symfony\Component\Yaml\Yaml::parse($yaml) ?? [];
      }
      catch (\Exception) {
        // Fall through to manual parsing.
      }
    }

    // Manual parsing for simple frontmatter.
    $data = [];
    $currentKey = NULL;
    $lines = explode("\n", $yaml);

    foreach ($lines as $line) {
      // Skip empty lines.
      if (trim($line) === '') {
        continue;
      }

      // Check for list item.
      if (preg_match('/^\s+-\s*(.+)$/', $line, $matches) && $currentKey !== NULL) {
        if (!is_array($data[$currentKey])) {
          $data[$currentKey] = [];
        }
        $data[$currentKey][] = $this->cleanYamlValue($matches[1]);
        continue;
      }

      // Check for key: value.
      if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*):\s*(.*)$/', $line, $matches)) {
        $currentKey = $matches[1];
        $value = $this->cleanYamlValue($matches[2]);

        // Empty value might indicate a list follows.
        if ($value === '') {
          $data[$currentKey] = [];
        }
        else {
          $data[$currentKey] = $value;
        }
      }
    }

    return $data;
  }

  /**
   * Cleans a YAML value by removing quotes and trimming.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   The cleaned value.
   */
  protected function cleanYamlValue(string $value): string {
    $value = trim($value);

    // Remove surrounding quotes.
    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
        (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
      $value = substr($value, 1, -1);
    }

    return $value;
  }

  /**
   * Extracts headings from markdown content.
   *
   * @param string $content
   *   The markdown content.
   *
   * @return array
   *   Array of headings with level and text.
   */
  protected function extractHeadings(string $content): array {
    $headings = [];
    $lines = explode("\n", $content);

    foreach ($lines as $line) {
      // Match ATX-style headings (# Heading).
      if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
        $headings[] = [
          'level' => strlen($matches[1]),
          'text' => trim($matches[2]),
        ];
      }
    }

    return $headings;
  }

  /**
   * Extracts a title from the first heading.
   *
   * @param array $headings
   *   Array of headings.
   *
   * @return string|null
   *   The first H1 heading text, or NULL if none found.
   */
  protected function extractTitleFromHeadings(array $headings): ?string {
    foreach ($headings as $heading) {
      // Prefer H1 headings.
      if ($heading['level'] === 1) {
        return $heading['text'];
      }
    }

    // Fall back to any heading.
    if (!empty($headings)) {
      return $headings[0]['text'];
    }

    return NULL;
  }

}
