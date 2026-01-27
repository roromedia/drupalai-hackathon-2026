<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Event;

use Drupal\content_preparation_wizard\Model\ProcessedDocument;
use Drupal\file\FileInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event dispatched after a document has been processed.
 *
 * This event allows subscribers to react to document processing completion,
 * enabling tasks such as logging, analytics, or additional processing.
 */
final class DocumentProcessedEvent extends Event {

  /**
   * The event name.
   *
   * @var string
   */
  public const EVENT_NAME = 'content_preparation_wizard.document_processed';

  /**
   * Constructs a DocumentProcessedEvent.
   *
   * @param \Drupal\content_preparation_wizard\Model\ProcessedDocument $processedDocument
   *   The processed document result.
   * @param \Drupal\file\FileInterface $originalFile
   *   The original file that was processed.
   * @param string $processorId
   *   The plugin ID of the processor that was used.
   */
  public function __construct(
    public readonly ProcessedDocument $processedDocument,
    public readonly FileInterface $originalFile,
    public readonly string $processorId,
  ) {}

  /**
   * Gets the processed document.
   *
   * @return \Drupal\content_preparation_wizard\Model\ProcessedDocument
   *   The processed document.
   */
  public function getProcessedDocument(): ProcessedDocument {
    return $this->processedDocument;
  }

  /**
   * Gets the original file.
   *
   * @return \Drupal\file\FileInterface
   *   The original file.
   */
  public function getOriginalFile(): FileInterface {
    return $this->originalFile;
  }

  /**
   * Gets the processor ID.
   *
   * @return string
   *   The processor plugin ID.
   */
  public function getProcessorId(): string {
    return $this->processorId;
  }

}
