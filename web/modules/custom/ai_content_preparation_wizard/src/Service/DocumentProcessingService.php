<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\ai_content_preparation_wizard\Event\DocumentProcessedEvent;
use Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException;
use Drupal\ai_content_preparation_wizard\Model\ProcessedDocument;
use Drupal\ai_content_preparation_wizard\PluginManager\DocumentProcessorPluginManager;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Service for processing documents through document processor plugins.
 *
 * This service orchestrates the document processing workflow by finding
 * appropriate processor plugins, delegating processing, and dispatching
 * events upon completion.
 */
final class DocumentProcessingService implements DocumentProcessingServiceInterface {

  use StringTranslationTrait;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * Constructs a DocumentProcessingService.
   *
   * @param \Drupal\ai_content_preparation_wizard\PluginManager\DocumentProcessorPluginManager $processorPluginManager
   *   The document processor plugin manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator.
   */
  public function __construct(
    private readonly DocumentProcessorPluginManager $processorPluginManager,
    private readonly FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly UuidInterface $uuid,
  ) {
    $this->logger = $loggerFactory->get('ai_content_preparation_wizard');
  }

  /**
   * {@inheritdoc}
   */
  public function process(FileInterface $file): ProcessedDocument {
    $filename = $file->getFilename();
    $this->logger->info('Starting document processing for file: @filename', [
      '@filename' => $filename,
    ]);

    // Find the best processor for this file.
    $processor = $this->processorPluginManager->getBestProcessorForFile($file);

    if ($processor === NULL) {
      $extension = pathinfo($filename, PATHINFO_EXTENSION);
      $this->logger->error('No suitable processor found for file @filename with extension @ext', [
        '@filename' => $filename,
        '@ext' => $extension,
      ]);
      throw new DocumentProcessingException(
        sprintf('No processor available for file type: %s', $extension),
        $filename,
        NULL,
      );
    }

    $processorId = $processor->getPluginId();
    $this->logger->debug('Using processor @processor for file @filename', [
      '@processor' => $processorId,
      '@filename' => $filename,
    ]);

    try {
      // Process the document using the plugin.
      $result = $processor->process($file);

      // Ensure we have a ProcessedDocument.
      if (!$result instanceof ProcessedDocument) {
        // The plugin returned a generic object; create a proper ProcessedDocument.
        $processedDocument = new ProcessedDocument(
          id: $this->uuid->generate(),
          originalFilename: $filename,
          content: $result->content ?? '',
          processorId: $processorId,
          metadata: $result->metadata ?? [],
          processedAt: time(),
          fileId: (int) $file->id(),
        );
      }
      else {
        $processedDocument = $result;
      }

      $this->logger->info('Successfully processed file @filename using @processor', [
        '@filename' => $filename,
        '@processor' => $processorId,
      ]);

      // Dispatch the document processed event.
      $event = new DocumentProcessedEvent($processedDocument, $file, $processorId);
      $this->eventDispatcher->dispatch($event, DocumentProcessedEvent::EVENT_NAME);

      return $processedDocument;
    }
    catch (DocumentProcessingException $e) {
      // Re-throw DocumentProcessingException as-is.
      throw $e;
    }
    catch (\Throwable $e) {
      $this->logger->error('Error processing file @filename: @error', [
        '@filename' => $filename,
        '@error' => $e->getMessage(),
      ]);
      throw new DocumentProcessingException(
        sprintf('Failed to process file %s: %s', $filename, $e->getMessage()),
        $filename,
        $processorId,
        0,
        $e,
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return $this->processorPluginManager->getAllSupportedExtensions();
  }

  /**
   * {@inheritdoc}
   */
  public function validate(FileInterface $file): array {
    $errors = [];
    $filename = $file->getFilename();

    // Check if the file exists and is readable.
    $uri = $file->getFileUri();
    $realpath = $this->fileSystem->realpath($uri);

    if ($realpath === FALSE || !file_exists($realpath)) {
      $errors[] = (string) $this->t('File @filename does not exist or is not accessible.', [
        '@filename' => $filename,
      ]);
      return $errors;
    }

    if (!is_readable($realpath)) {
      $errors[] = (string) $this->t('File @filename is not readable.', [
        '@filename' => $filename,
      ]);
      return $errors;
    }

    // Check file extension.
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $supportedExtensions = $this->getSupportedExtensions();

    if (!in_array($extension, $supportedExtensions, TRUE)) {
      $errors[] = (string) $this->t('File type "@ext" is not supported. Supported types: @types.', [
        '@ext' => $extension,
        '@types' => implode(', ', $supportedExtensions),
      ]);
      return $errors;
    }

    // Check if a processor is available.
    $processor = $this->processorPluginManager->getBestProcessorForFile($file);
    if ($processor === NULL) {
      $errors[] = (string) $this->t('No processor is available to handle file @filename.', [
        '@filename' => $filename,
      ]);
      return $errors;
    }

    // Check processor requirements.
    if (!$processor->checkRequirements()) {
      $requirementErrors = $processor->getRequirementErrors();
      foreach ($requirementErrors as $error) {
        $errors[] = (string) $this->t('Processor requirement not met: @description', [
          '@description' => $error['description'] ?? $error['name'] ?? 'Unknown requirement',
        ]);
      }
    }

    // Check file size (optional - could be made configurable).
    $fileSize = $file->getSize();
    $maxSize = 50 * 1024 * 1024; // 50MB default.
    if ($fileSize > $maxSize) {
      $errors[] = (string) $this->t('File @filename exceeds the maximum allowed size of @max.', [
        '@filename' => $filename,
        '@max' => format_size($maxSize),
      ]);
    }

    // Check if file is empty.
    if ($fileSize === 0) {
      $errors[] = (string) $this->t('File @filename is empty.', [
        '@filename' => $filename,
      ]);
    }

    return $errors;
  }

}
