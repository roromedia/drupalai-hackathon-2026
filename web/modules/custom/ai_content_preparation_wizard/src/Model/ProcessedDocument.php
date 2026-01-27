<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Model;

use Drupal\ai_content_preparation_wizard\Enum\FileType;
use Drupal\ai_content_preparation_wizard\Enum\ProcessingProvider;

/**
 * Immutable value object representing a processed document.
 *
 * Contains the extracted content and metadata from a document that has
 * been processed by a DocumentProcessor plugin.
 */
final class ProcessedDocument {

  /**
   * Constructs a ProcessedDocument object.
   *
   * @param string $id
   *   Unique identifier for this processed document.
   * @param int $fileId
   *   The Drupal file entity ID of the source file.
   * @param string $fileName
   *   The original filename.
   * @param \Drupal\ai_content_preparation_wizard\Enum\FileType $fileType
   *   The type of file that was processed.
   * @param string $markdownContent
   *   The extracted content in Markdown format.
   * @param \Drupal\ai_content_preparation_wizard\Model\DocumentMetadata $metadata
   *   Extracted document metadata.
   * @param \Drupal\ai_content_preparation_wizard\Enum\ProcessingProvider $provider
   *   The processor that was used.
   * @param \DateTimeImmutable $processedAt
   *   When the document was processed.
   */
  public function __construct(
    public readonly string $id,
    public readonly int $fileId,
    public readonly string $fileName,
    public readonly FileType $fileType,
    public readonly string $markdownContent,
    public readonly DocumentMetadata $metadata,
    public readonly ProcessingProvider $provider,
    public readonly \DateTimeImmutable $processedAt,
  ) {}

  /**
   * Gets the word count of the markdown content.
   *
   * @return int
   *   The number of words in the content.
   */
  public function getWordCount(): int {
    // Strip markdown formatting for accurate count.
    $plainText = strip_tags($this->markdownContent);
    $plainText = preg_replace('/[#*_`\[\]()>-]+/', ' ', $plainText);
    $words = preg_split('/\s+/', trim($plainText), -1, PREG_SPLIT_NO_EMPTY);
    return count($words);
  }

  /**
   * Gets the character count of the markdown content.
   *
   * @return int
   *   The number of characters in the content.
   */
  public function getCharacterCount(): int {
    return mb_strlen($this->markdownContent);
  }

  /**
   * Gets the estimated reading time in minutes.
   *
   * @param int $wordsPerMinute
   *   Average reading speed (default 200 words per minute).
   *
   * @return int
   *   Estimated reading time in minutes.
   */
  public function getEstimatedReadTime(int $wordsPerMinute = 200): int {
    return (int) ceil($this->getWordCount() / $wordsPerMinute);
  }

  /**
   * Checks if the document has content.
   *
   * @return bool
   *   TRUE if the document has non-empty content.
   */
  public function hasContent(): bool {
    return trim($this->markdownContent) !== '';
  }

  /**
   * Gets a summary of the document content.
   *
   * @param int $maxLength
   *   Maximum length of the summary.
   *
   * @return string
   *   A truncated summary of the content.
   */
  public function getSummary(int $maxLength = 200): string {
    $plainText = strip_tags($this->markdownContent);
    $plainText = preg_replace('/[#*_`\[\]()>-]+/', ' ', $plainText);
    $plainText = preg_replace('/\s+/', ' ', trim($plainText));

    if (mb_strlen($plainText) <= $maxLength) {
      return $plainText;
    }

    return mb_substr($plainText, 0, $maxLength - 3) . '...';
  }

  /**
   * Converts the document to an array for serialization.
   *
   * @return array<string, mixed>
   *   The document as an associative array.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'file_id' => $this->fileId,
      'file_name' => $this->fileName,
      'file_type' => $this->fileType->value,
      'markdown_content' => $this->markdownContent,
      'metadata' => $this->metadata->toArray(),
      'provider' => $this->provider->value,
      'processed_at' => $this->processedAt->format(\DateTimeInterface::RFC3339),
      'word_count' => $this->getWordCount(),
      'character_count' => $this->getCharacterCount(),
    ];
  }

  /**
   * Creates a ProcessedDocument instance from an array.
   *
   * @param array<string, mixed> $data
   *   The data array from serialization.
   *
   * @return self
   *   A new ProcessedDocument instance.
   *
   * @throws \InvalidArgumentException
   *   If required data is missing or invalid.
   */
  public static function fromArray(array $data): self {
    $requiredFields = ['id', 'file_id', 'file_name', 'file_type', 'markdown_content', 'provider', 'processed_at'];
    foreach ($requiredFields as $field) {
      if (!isset($data[$field])) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }

    $fileType = FileType::tryFrom($data['file_type']);
    if ($fileType === NULL) {
      throw new \InvalidArgumentException("Invalid file type: {$data['file_type']}");
    }

    $provider = ProcessingProvider::tryFrom($data['provider']);
    if ($provider === NULL) {
      throw new \InvalidArgumentException("Invalid provider: {$data['provider']}");
    }

    return new self(
      id: $data['id'],
      fileId: (int) $data['file_id'],
      fileName: $data['file_name'],
      fileType: $fileType,
      markdownContent: $data['markdown_content'],
      metadata: isset($data['metadata']) ? DocumentMetadata::fromArray($data['metadata']) : new DocumentMetadata(),
      provider: $provider,
      processedAt: new \DateTimeImmutable($data['processed_at']),
    );
  }

  /**
   * Creates a new ProcessedDocument with a generated unique ID.
   *
   * @param int $fileId
   *   The Drupal file entity ID.
   * @param string $fileName
   *   The original filename.
   * @param \Drupal\ai_content_preparation_wizard\Enum\FileType $fileType
   *   The file type.
   * @param string $markdownContent
   *   The extracted markdown content.
   * @param \Drupal\ai_content_preparation_wizard\Model\DocumentMetadata $metadata
   *   The extracted metadata.
   * @param \Drupal\ai_content_preparation_wizard\Enum\ProcessingProvider $provider
   *   The provider used for processing.
   *
   * @return self
   *   A new ProcessedDocument instance.
   */
  public static function create(
    int $fileId,
    string $fileName,
    FileType $fileType,
    string $markdownContent,
    DocumentMetadata $metadata,
    ProcessingProvider $provider,
  ): self {
    return new self(
      id: 'doc_' . bin2hex(random_bytes(8)),
      fileId: $fileId,
      fileName: $fileName,
      fileType: $fileType,
      markdownContent: $markdownContent,
      metadata: $metadata,
      provider: $provider,
      processedAt: new \DateTimeImmutable(),
    );
  }

}
