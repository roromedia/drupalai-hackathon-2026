<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException;
use Psr\Log\LoggerInterface;

/**
 * Service for converting documents using the Pandoc CLI.
 */
class PandocConverter implements PandocConverterInterface {

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Cached availability status.
   *
   * @var bool|null
   */
  protected ?bool $available = NULL;

  /**
   * Cached version string.
   *
   * @var string|null
   */
  protected ?string $version = NULL;

  /**
   * Constructs a new PandocConverter instance.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_content_preparation_wizard');
  }

  /**
   * {@inheritdoc}
   */
  public function convertToMarkdown(string $filePath, string $format): string {
    if (!$this->isAvailable()) {
      throw new DocumentProcessingException(
        'Pandoc is not available on this system.',
        basename($filePath),
        'pandoc'
      );
    }

    if (!file_exists($filePath)) {
      throw new DocumentProcessingException(
        sprintf('File not found: %s', $filePath),
        basename($filePath),
        'pandoc'
      );
    }

    $pandocPath = $this->getPandocPath();
    $escapedFile = escapeshellarg($filePath);
    $escapedFormat = escapeshellarg($format);

    // Build the pandoc command.
    $command = sprintf(
      '%s -f %s -t markdown --wrap=none %s 2>&1',
      escapeshellcmd($pandocPath),
      $escapedFormat,
      $escapedFile
    );

    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
      $errorMessage = implode("\n", $output);
      $this->logger->error('Pandoc conversion failed for @file: @error', [
        '@file' => basename($filePath),
        '@error' => $errorMessage,
      ]);
      throw new DocumentProcessingException(
        sprintf('Pandoc conversion failed: %s', $errorMessage),
        basename($filePath),
        'pandoc'
      );
    }

    return implode("\n", $output);
  }

  /**
   * {@inheritdoc}
   */
  public function extractMetadata(string $filePath, string $format): array {
    if (!$this->isAvailable()) {
      return [];
    }

    if (!file_exists($filePath)) {
      return [];
    }

    $pandocPath = $this->getPandocPath();
    $escapedFile = escapeshellarg($filePath);
    $escapedFormat = escapeshellarg($format);

    // Extract metadata using pandoc's template feature.
    $command = sprintf(
      '%s -f %s -t plain --template=%s %s 2>/dev/null',
      escapeshellcmd($pandocPath),
      $escapedFormat,
      escapeshellarg('$meta-json$'),
      $escapedFile
    );

    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);

    if ($returnCode !== 0 || empty($output)) {
      // Try an alternative approach using Lua filter for metadata.
      return $this->extractMetadataFallback($filePath, $format);
    }

    $jsonOutput = implode('', $output);
    $metadata = json_decode($jsonOutput, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      return $this->extractMetadataFallback($filePath, $format);
    }

    return $this->normalizeMetadata($metadata);
  }

  /**
   * Fallback metadata extraction using pandoc without template.
   *
   * @param string $filePath
   *   The file path.
   * @param string $format
   *   The file format.
   *
   * @return array
   *   Extracted metadata array.
   */
  protected function extractMetadataFallback(string $filePath, string $format): array {
    $pandocPath = $this->getPandocPath();
    $escapedFile = escapeshellarg($filePath);
    $escapedFormat = escapeshellarg($format);

    // Use standalone mode which includes metadata in output.
    $command = sprintf(
      '%s -f %s -t markdown -s %s 2>/dev/null | head -50',
      escapeshellcmd($pandocPath),
      $escapedFormat,
      $escapedFile
    );

    $output = [];
    exec($command, $output);
    $content = implode("\n", $output);

    $metadata = [];

    // Parse YAML front matter if present.
    if (preg_match('/^---\s*\n(.+?)\n---/s', $content, $matches)) {
      $yamlContent = $matches[1];
      $lines = explode("\n", $yamlContent);
      foreach ($lines as $line) {
        if (preg_match('/^(\w+):\s*(.+)$/', trim($line), $lineMatch)) {
          $key = strtolower($lineMatch[1]);
          $value = trim($lineMatch[2], '"\'');
          $metadata[$key] = $value;
        }
      }
    }

    return $metadata;
  }

  /**
   * Normalizes metadata from pandoc output.
   *
   * @param array $metadata
   *   Raw metadata array.
   *
   * @return array
   *   Normalized metadata.
   */
  protected function normalizeMetadata(array $metadata): array {
    $normalized = [];

    // Map common pandoc metadata fields.
    $fieldMap = [
      'title' => 'title',
      'author' => 'author',
      'date' => 'createdDate',
      'lang' => 'language',
      'language' => 'language',
      'subject' => 'subject',
      'keywords' => 'keywords',
      'description' => 'description',
    ];

    foreach ($fieldMap as $pandocKey => $normalizedKey) {
      if (isset($metadata[$pandocKey])) {
        $value = $metadata[$pandocKey];
        // Handle array values (like multiple authors).
        if (is_array($value)) {
          $value = implode(', ', $value);
        }
        $normalized[$normalizedKey] = $value;
      }
    }

    // Store any remaining metadata as custom properties.
    $customProperties = [];
    foreach ($metadata as $key => $value) {
      if (!isset($fieldMap[$key])) {
        $customProperties[$key] = is_array($value) ? implode(', ', $value) : $value;
      }
    }

    if (!empty($customProperties)) {
      $normalized['customProperties'] = $customProperties;
    }

    return $normalized;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    if ($this->available !== NULL) {
      return $this->available;
    }

    $pandocPath = $this->getPandocPath();
    $command = sprintf('%s --version 2>/dev/null', escapeshellcmd($pandocPath));

    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);

    $this->available = ($returnCode === 0 && !empty($output));

    if ($this->available && !empty($output[0])) {
      // Parse version from first line (e.g., "pandoc 2.19.2").
      if (preg_match('/pandoc\s+([\d.]+)/', $output[0], $matches)) {
        $this->version = $matches[1];
      }
    }

    return $this->available;
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion(): ?string {
    // Ensure isAvailable() is called to populate version.
    $this->isAvailable();
    return $this->version;
  }

  /**
   * {@inheritdoc}
   */
  public function getPandocPath(): string {
    $config = $this->configFactory->get('ai_content_preparation_wizard.settings');

    // First try the new document_processors config.
    $documentProcessors = $config->get('document_processors');
    if (!empty($documentProcessors)) {
      // Look for a line matching pandoc-supported extensions (docx, odt, rtf).
      $path = $this->getExecutableForExtension($documentProcessors, 'docx');
      if ($path !== NULL) {
        return $path;
      }
    }

    // Fall back to legacy pandoc_path config.
    return $config->get('pandoc_path') ?: 'pandoc';
  }

  /**
   * Gets the executable path for a given extension from document_processors.
   *
   * @param string $documentProcessors
   *   The document_processors config value (multi-line string).
   * @param string $extension
   *   The file extension to look up.
   *
   * @return string|null
   *   The executable path or NULL if not found.
   */
  protected function getExecutableForExtension(string $documentProcessors, string $extension): ?string {
    $lines = preg_split('/\r\n|\r|\n/', $documentProcessors);

    foreach ($lines as $line) {
      $line = trim($line);

      // Skip empty lines and comments.
      if (empty($line) || str_starts_with($line, '#')) {
        continue;
      }

      // Parse format: extensions|path.
      if (!str_contains($line, '|')) {
        continue;
      }

      [$extensions, $path] = explode('|', $line, 2);
      $extensions = trim($extensions);
      $path = trim($path);

      if (empty($extensions) || empty($path)) {
        continue;
      }

      // Handle multiple extensions separated by comma.
      $extList = array_map('trim', explode(',', $extensions));
      $extList = array_map('strtolower', $extList);

      if (in_array(strtolower($extension), $extList, TRUE)) {
        return $path;
      }
    }

    return NULL;
  }

}
