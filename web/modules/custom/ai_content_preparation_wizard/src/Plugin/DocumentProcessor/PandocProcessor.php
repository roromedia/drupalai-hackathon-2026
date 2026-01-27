<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_preparation_wizard\Attribute\DocumentProcessor;
use Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException;
use Drupal\ai_content_preparation_wizard\Model\DocumentMetadata;
use Drupal\ai_content_preparation_wizard\Model\ProcessedDocument;
use Drupal\ai_content_preparation_wizard\Service\PandocConverterInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Document processor using the external Pandoc binary.
 *
 * This is the default processor for converting complex document formats
 * (DOCX, PDF, ODT, RTF) to markdown. It requires Pandoc to be installed
 * on the server.
 */
#[DocumentProcessor(
  id: 'pandoc',
  label: new TranslatableMarkup('Pandoc Processor'),
  description: new TranslatableMarkup('Converts documents to markdown using the Pandoc CLI tool. Supports DOCX, PDF, ODT, and RTF formats.'),
  supported_extensions: ['docx', 'pdf', 'odt', 'rtf'],
  weight: 0,
)]
class PandocProcessor extends DocumentProcessorBase {

  /**
   * The Pandoc converter service.
   *
   * @var \Drupal\ai_content_preparation_wizard\Service\PandocConverterInterface
   */
  protected PandocConverterInterface $pandocConverter;

  /**
   * Mapping of file extensions to Pandoc format identifiers.
   *
   * @var array
   */
  protected const FORMAT_MAP = [
    'docx' => 'docx',
    'pdf' => 'pdf',
    'odt' => 'odt',
    'rtf' => 'rtf',
  ];

  /**
   * Constructs a PandocProcessor object.
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
   * @param \Drupal\ai_content_preparation_wizard\Service\PandocConverterInterface $pandoc_converter
   *   The Pandoc converter service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    PandocConverterInterface $pandoc_converter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $file_system, $logger_factory);
    $this->pandocConverter = $pandoc_converter;
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
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('file_system'),
      $container->get('logger.factory'),
      $container->get('ai_content_preparation_wizard.pandoc_converter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): bool {
    return $this->pandocConverter->isAvailable();
  }

  /**
   * {@inheritdoc}
   */
  public function getRequirementErrors(): array {
    $errors = [];

    if (!$this->pandocConverter->isAvailable()) {
      $pandocPath = $this->pandocConverter->getPandocPath();
      $errors[] = [
        'type' => 'binary',
        'name' => 'pandoc',
        'description' => $this->t('Pandoc is not available at @path. Please install Pandoc or configure the correct path.', [
          '@path' => $pandocPath,
        ]),
      ];
    }

    return $errors;
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
      $errorMessage = !empty($errors) ? $errors[0]['description'] : 'Pandoc is not available';
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

    $extension = $this->getFileExtension($file);
    $format = self::FORMAT_MAP[$extension] ?? $extension;

    $this->logInfo('Processing file with Pandoc', $file, ['@format' => $format]);

    try {
      // Convert document to markdown.
      $markdownContent = $this->pandocConverter->convertToMarkdown($realPath, $format);

      // Extract metadata.
      $metadata = $this->extractMetadata($file);

      // Create the processed document.
      return new ProcessedDocument(
        id: \Drupal::service('uuid')->generate(),
        originalFilename: $file->getFilename(),
        content: $markdownContent,
        processorId: $this->getPluginId(),
        metadata: $metadata->toArray(),
        processedAt: (int) \Drupal::time()->getRequestTime(),
        fileId: (int) $file->id(),
      );
    }
    catch (DocumentProcessingException $e) {
      // Re-throw DocumentProcessingException as-is.
      throw $e;
    }
    catch (\Exception $e) {
      $this->logError('Pandoc processing failed: @error', $file, [
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

    $extension = $this->getFileExtension($file);
    $format = self::FORMAT_MAP[$extension] ?? $extension;

    try {
      $rawMetadata = $this->pandocConverter->extractMetadata($realPath, $format);
      return DocumentMetadata::fromArray($rawMetadata);
    }
    catch (\Exception $e) {
      $this->logWarning('Metadata extraction failed: @error', $file, [
        '@error' => $e->getMessage(),
      ]);
      return DocumentMetadata::empty();
    }
  }

}
