<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Model;

/**
 * Immutable value object representing a processed webpage.
 *
 * Contains the extracted content and metadata from a webpage that has
 * been fetched and converted to markdown format for AI processing.
 */
final class ProcessedWebpage {

  /**
   * Constructs a ProcessedWebpage object.
   *
   * @param string $id
   *   Unique identifier for this processed webpage.
   * @param string $url
   *   The source URL of the webpage.
   * @param string $title
   *   The page title extracted from the HTML.
   * @param string $markdownContent
   *   The extracted content in Markdown format.
   * @param \DateTimeImmutable $processedAt
   *   When the webpage was processed.
   * @param array $metadata
   *   Additional metadata about the webpage.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $url,
    public readonly string $title,
    public readonly string $markdownContent,
    public readonly \DateTimeImmutable $processedAt,
    public readonly array $metadata = [],
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
   * Checks if the webpage has content.
   *
   * @return bool
   *   TRUE if the webpage has non-empty content.
   */
  public function hasContent(): bool {
    return trim($this->markdownContent) !== '';
  }

  /**
   * Gets a summary of the webpage content.
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
   * Gets the domain from the URL.
   *
   * @return string
   *   The domain name.
   */
  public function getDomain(): string {
    $parsed = parse_url($this->url);
    return $parsed['host'] ?? '';
  }

  /**
   * Converts the webpage to an array for serialization.
   *
   * @return array<string, mixed>
   *   The webpage as an associative array.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'url' => $this->url,
      'title' => $this->title,
      'markdown_content' => $this->markdownContent,
      'processed_at' => $this->processedAt->format(\DateTimeInterface::RFC3339),
      'metadata' => $this->metadata,
      'word_count' => $this->getWordCount(),
      'character_count' => $this->getCharacterCount(),
    ];
  }

  /**
   * Creates a ProcessedWebpage instance from an array.
   *
   * @param array<string, mixed> $data
   *   The data array from serialization.
   *
   * @return self
   *   A new ProcessedWebpage instance.
   *
   * @throws \InvalidArgumentException
   *   If required data is missing or invalid.
   */
  public static function fromArray(array $data): self {
    $requiredFields = ['id', 'url', 'title', 'markdown_content', 'processed_at'];
    foreach ($requiredFields as $field) {
      if (!isset($data[$field])) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }

    return new self(
      id: $data['id'],
      url: $data['url'],
      title: $data['title'],
      markdownContent: $data['markdown_content'],
      processedAt: new \DateTimeImmutable($data['processed_at']),
      metadata: $data['metadata'] ?? [],
    );
  }

  /**
   * Creates a new ProcessedWebpage with a generated unique ID.
   *
   * @param string $url
   *   The source URL.
   * @param string $title
   *   The page title.
   * @param string $markdownContent
   *   The extracted markdown content.
   * @param array $metadata
   *   Optional metadata.
   *
   * @return self
   *   A new ProcessedWebpage instance.
   */
  public static function create(
    string $url,
    string $title,
    string $markdownContent,
    array $metadata = [],
  ): self {
    return new self(
      id: 'webpage_' . bin2hex(random_bytes(8)),
      url: $url,
      title: $title,
      markdownContent: $markdownContent,
      processedAt: new \DateTimeImmutable(),
      metadata: $metadata,
    );
  }

}
