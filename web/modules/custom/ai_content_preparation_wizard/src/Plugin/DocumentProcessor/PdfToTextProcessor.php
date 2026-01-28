<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_preparation_wizard\Attribute\DocumentProcessor;
use Drupal\ai_content_preparation_wizard\Enum\FileType;
use Drupal\ai_content_preparation_wizard\Enum\ProcessingProvider;
use Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException;
use Drupal\ai_content_preparation_wizard\Model\DocumentMetadata;
use Drupal\ai_content_preparation_wizard\Model\ProcessedDocument;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Document processor using the pdftotext CLI tool from Poppler.
 *
 * This processor extracts text from PDF files using the pdftotext command-line
 * tool. It's a lightweight alternative to Pandoc for PDF-only processing.
 * Requires poppler-utils to be installed on the server.
 */
#[DocumentProcessor(
  id: 'pdftotext',
  label: new TranslatableMarkup('pdftotext Processor'),
  description: new TranslatableMarkup('Extracts text from PDF files using the pdftotext CLI tool (Poppler). Lightweight PDF-specific processor.'),
  supported_extensions: ['pdf'],
  weight: -5,
)]
class PdfToTextProcessor extends DocumentProcessorBase {

  /**
   * The path to the pdftotext binary.
   *
   * @var string
   */
  protected string $pdftotextPath;

  /**
   * The path to the pdfinfo binary for metadata extraction.
   *
   * @var string
   */
  protected string $pdfinfoPath;

  /**
   * Constructs a PdfToTextProcessor object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param string $pdftotext_path
   *   Path to the pdftotext binary.
   * @param string $pdfinfo_path
   *   Path to the pdfinfo binary.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    string $pdftotext_path = 'pdftotext',
    string $pdfinfo_path = 'pdfinfo',
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $file_system, $logger_factory);
    $this->pdftotextPath = $pdftotext_path;
    $this->pdfinfoPath = $pdfinfo_path;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    // Get paths from configuration if available.
    $config = $container->get('config.factory')->get('ai_content_preparation_wizard.settings');

    // Try new document_processors config first, then fall back to legacy config.
    $pdftotextPath = static::getExecutableForExtensionStatic(
      $config->get('document_processors') ?? '',
      'pdf'
    );
    if ($pdftotextPath === NULL) {
      $pdftotextPath = $config->get('pdftotext_path') ?: 'pdftotext';
    }

    // pdfinfo path - derive from pdftotext path if in same directory.
    $pdfinfoPath = $config->get('pdfinfo_path');
    if (empty($pdfinfoPath) && $pdftotextPath !== 'pdftotext') {
      // Try to find pdfinfo in the same directory as pdftotext.
      $potentialPath = dirname($pdftotextPath) . '/pdfinfo';
      $pdfinfoPath = file_exists($potentialPath) ? $potentialPath : 'pdfinfo';
    }
    else {
      $pdfinfoPath = $pdfinfoPath ?: 'pdfinfo';
    }

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('file_system'),
      $container->get('logger.factory'),
      $pdftotextPath,
      $pdfinfoPath,
    );
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
  protected static function getExecutableForExtensionStatic(string $documentProcessors, string $extension): ?string {
    if (empty($documentProcessors)) {
      return NULL;
    }

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

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): bool {
    return $this->isPdftotextAvailable();
  }

  /**
   * {@inheritdoc}
   */
  public function getRequirementErrors(): array {
    $errors = [];

    if (!$this->isPdftotextAvailable()) {
      $errors[] = [
        'type' => 'binary',
        'name' => 'pdftotext',
        'description' => $this->t('pdftotext is not available. Please install poppler-utils (e.g., apt-get install poppler-utils or brew install poppler).'),
      ];
    }

    return $errors;
  }

  /**
   * Checks if pdftotext binary is available.
   *
   * @return bool
   *   TRUE if pdftotext is available, FALSE otherwise.
   */
  protected function isPdftotextAvailable(): bool {
    try {
      $process = new Process([$this->pdftotextPath, '-v']);
      $process->run();
      return $process->isSuccessful() || str_contains($process->getErrorOutput(), 'pdftotext');
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Checks if pdfinfo binary is available.
   *
   * @return bool
   *   TRUE if pdfinfo is available, FALSE otherwise.
   */
  protected function isPdfinfoAvailable(): bool {
    try {
      $process = new Process([$this->pdfinfoPath, '-v']);
      $process->run();
      return $process->isSuccessful() || str_contains($process->getErrorOutput(), 'pdfinfo');
    }
    catch (\Exception $e) {
      return FALSE;
    }
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

    // Check requirements before processing.
    if (!$this->checkRequirements()) {
      $errors = $this->getRequirementErrors();
      $errorMessage = !empty($errors) ? $errors[0]['description'] : 'pdftotext is not available';
      throw new DocumentProcessingException(
        (string) $errorMessage,
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

    $this->logInfo('Processing PDF with pdftotext', $file);

    try {
      // Extract text using pdftotext.
      $textContent = $this->extractText($realPath);

      // Convert to basic markdown format.
      $markdownContent = $this->convertToMarkdown($textContent, $file->getFilename());

      // Extract metadata.
      $metadata = $this->extractMetadata($file);

      // Create the processed document.
      return ProcessedDocument::create(
        fileId: (int) $file->id(),
        fileName: $file->getFilename(),
        fileType: FileType::PDF,
        markdownContent: $markdownContent,
        metadata: $metadata,
        provider: ProcessingProvider::PDFTOTEXT,
      );
    }
    catch (DocumentProcessingException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logError('pdftotext processing failed: @error', $file, [
        '@error' => $e->getMessage(),
      ]);
      throw new DocumentProcessingException(
        $this->t('Failed to process PDF: @error', [
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
   * Extracts text from a PDF file using pdftotext.
   *
   * @param string $filePath
   *   The path to the PDF file.
   *
   * @return string
   *   The extracted text content.
   *
   * @throws \Symfony\Component\Process\Exception\ProcessFailedException
   *   If the pdftotext process fails.
   */
  protected function extractText(string $filePath): string {
    // Use -layout to preserve layout, output to stdout with '-'.
    $process = new Process([
      $this->pdftotextPath,
      '-layout',
      '-enc', 'UTF-8',
      $filePath,
      '-',
    ]);

    $process->setTimeout(120);
    $process->run();

    if (!$process->isSuccessful()) {
      throw new ProcessFailedException($process);
    }

    return $process->getOutput();
  }

  /**
   * Converts extracted text to basic markdown format.
   *
   * @param string $text
   *   The raw text extracted from PDF.
   * @param string $filename
   *   The original filename for the title.
   *
   * @return string
   *   The text formatted as markdown.
   */
  protected function convertToMarkdown(string $text, string $filename): string {
    // Clean up the text.
    $text = trim($text);

    if (empty($text)) {
      return '';
    }

    // Normalize line endings.
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Remove excessive blank lines (more than 2 consecutive).
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    // Try to detect and format headings (lines that are all caps or short lines followed by blank lines).
    $lines = explode("\n", $text);
    $formatted = [];
    $prevLineBlank = TRUE;

    foreach ($lines as $i => $line) {
      $trimmedLine = trim($line);

      // Skip empty lines but track them.
      if (empty($trimmedLine)) {
        $formatted[] = '';
        $prevLineBlank = TRUE;
        continue;
      }

      // Detect potential headings: short lines (< 80 chars) that are:
      // - All caps, or
      // - Followed by a blank line and preceded by a blank line.
      $isShortLine = mb_strlen($trimmedLine) < 80;
      $isAllCaps = $trimmedLine === mb_strtoupper($trimmedLine) && preg_match('/[A-Z]/', $trimmedLine);
      $nextLineBlank = !isset($lines[$i + 1]) || trim($lines[$i + 1]) === '';

      if ($prevLineBlank && $isShortLine && ($isAllCaps || $nextLineBlank) && !preg_match('/^[\d\.\-\*]/', $trimmedLine)) {
        // Format as heading.
        if ($isAllCaps && mb_strlen($trimmedLine) < 50) {
          $formatted[] = '## ' . ucwords(strtolower($trimmedLine));
        }
        else {
          $formatted[] = '### ' . $trimmedLine;
        }
      }
      else {
        $formatted[] = $trimmedLine;
      }

      $prevLineBlank = FALSE;
    }

    $markdown = implode("\n", $formatted);

    // Add document title as H1 if we can extract it from filename.
    $title = pathinfo($filename, PATHINFO_FILENAME);
    $title = str_replace(['_', '-'], ' ', $title);
    $title = ucwords($title);

    return "# {$title}\n\n" . $markdown;
  }

  /**
   * {@inheritdoc}
   */
  public function extractMetadata(FileInterface $file): DocumentMetadata {
    if (!$this->validateFileAccess($file)) {
      return new DocumentMetadata();
    }

    $realPath = $this->getRealPath($file);
    if ($realPath === FALSE) {
      return new DocumentMetadata();
    }

    // If pdfinfo is not available, return basic metadata.
    if (!$this->isPdfinfoAvailable()) {
      return new DocumentMetadata(
        title: pathinfo($file->getFilename(), PATHINFO_FILENAME),
      );
    }

    try {
      $process = new Process([$this->pdfinfoPath, $realPath]);
      $process->setTimeout(30);
      $process->run();

      if (!$process->isSuccessful()) {
        return new DocumentMetadata(
          title: pathinfo($file->getFilename(), PATHINFO_FILENAME),
        );
      }

      $output = $process->getOutput();
      $metadata = $this->parsePdfInfo($output);

      return new DocumentMetadata(
        title: $metadata['title'] ?? pathinfo($file->getFilename(), PATHINFO_FILENAME),
        author: $metadata['author'] ?? NULL,
        createdDate: isset($metadata['creation_date']) ? $this->parseDate($metadata['creation_date']) : NULL,
        customProperties: [
          'pages' => $metadata['pages'] ?? NULL,
          'pdf_version' => $metadata['pdf_version'] ?? NULL,
          'producer' => $metadata['producer'] ?? NULL,
          'creator' => $metadata['creator'] ?? NULL,
        ],
      );
    }
    catch (\Exception $e) {
      $this->logWarning('Metadata extraction failed: @error', $file, [
        '@error' => $e->getMessage(),
      ]);
      return new DocumentMetadata(
        title: pathinfo($file->getFilename(), PATHINFO_FILENAME),
      );
    }
  }

  /**
   * Parses pdfinfo output into an associative array.
   *
   * @param string $output
   *   The pdfinfo command output.
   *
   * @return array
   *   Parsed metadata as key-value pairs.
   */
  protected function parsePdfInfo(string $output): array {
    $metadata = [];
    $lines = explode("\n", $output);

    foreach ($lines as $line) {
      if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
        $key = strtolower(trim($matches[1]));
        $key = str_replace(' ', '_', $key);
        $value = trim($matches[2]);

        if (!empty($value)) {
          $metadata[$key] = $value;
        }
      }
    }

    return $metadata;
  }

  /**
   * Attempts to parse a date string from pdfinfo.
   *
   * @param string $dateString
   *   The date string from pdfinfo.
   *
   * @return \DateTimeImmutable|null
   *   The parsed date or NULL on failure.
   */
  protected function parseDate(string $dateString): ?\DateTimeImmutable {
    try {
      return new \DateTimeImmutable($dateString);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
