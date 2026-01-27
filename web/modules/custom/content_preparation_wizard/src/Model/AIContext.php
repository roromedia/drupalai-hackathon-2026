<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Model;

/**
 * Immutable value object representing AI context for content generation.
 *
 * Contains contextual information from the Drupal site that informs
 * the AI when generating content plans.
 */
final class AIContext {

  /**
   * Constructs an AIContext object.
   *
   * @param string $id
   *   Unique identifier for this context.
   * @param string $type
   *   The type of context (e.g., 'taxonomy', 'content_patterns', 'site_info').
   * @param string $label
   *   Human-readable label for this context.
   * @param string $content
   *   The actual context content/data.
   * @param int $priority
   *   Priority for ordering contexts (higher = more important).
   * @param array<string, mixed> $metadata
   *   Additional metadata about the context.
   * @param bool $enabled
   *   Whether this context is enabled for use.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $type,
    public readonly string $label,
    public readonly string $content,
    public readonly int $priority = 0,
    public readonly array $metadata = [],
    public readonly bool $enabled = TRUE,
  ) {}

  /**
   * Creates a new instance with enabled state toggled.
   *
   * @param bool $enabled
   *   The new enabled state.
   *
   * @return self
   *   A new instance with updated enabled state.
   */
  public function withEnabled(bool $enabled): self {
    return new self(
      $this->id,
      $this->type,
      $this->label,
      $this->content,
      $this->priority,
      $this->metadata,
      $enabled,
    );
  }

  /**
   * Creates a new instance with updated content.
   *
   * @param string $content
   *   The new content.
   *
   * @return self
   *   A new instance with updated content.
   */
  public function withContent(string $content): self {
    return new self(
      $this->id,
      $this->type,
      $this->label,
      $content,
      $this->priority,
      $this->metadata,
      $this->enabled,
    );
  }

  /**
   * Creates a new instance with additional metadata.
   *
   * @param string $key
   *   The metadata key.
   * @param mixed $value
   *   The metadata value.
   *
   * @return self
   *   A new instance with the added metadata.
   */
  public function withMetadata(string $key, mixed $value): self {
    $metadata = $this->metadata;
    $metadata[$key] = $value;

    return new self(
      $this->id,
      $this->type,
      $this->label,
      $this->content,
      $this->priority,
      $metadata,
      $this->enabled,
    );
  }

  /**
   * Gets a metadata value.
   *
   * @param string $key
   *   The metadata key.
   * @param mixed $default
   *   Default value if key doesn't exist.
   *
   * @return mixed
   *   The metadata value or default.
   */
  public function getMetadata(string $key, mixed $default = NULL): mixed {
    return $this->metadata[$key] ?? $default;
  }

  /**
   * Gets the content length in characters.
   *
   * @return int
   *   The content length.
   */
  public function getContentLength(): int {
    return mb_strlen($this->content);
  }

  /**
   * Gets a truncated version of the content for display.
   *
   * @param int $maxLength
   *   Maximum length.
   *
   * @return string
   *   Truncated content.
   */
  public function getContentPreview(int $maxLength = 200): string {
    if (mb_strlen($this->content) <= $maxLength) {
      return $this->content;
    }

    return mb_substr($this->content, 0, $maxLength - 3) . '...';
  }

  /**
   * Formats the context for inclusion in an AI prompt.
   *
   * @return string
   *   Formatted context string.
   */
  public function formatForPrompt(): string {
    return sprintf(
      "### %s (%s)\n%s\n",
      $this->label,
      $this->type,
      $this->content
    );
  }

  /**
   * Converts the context to an array for serialization.
   *
   * @return array<string, mixed>
   *   The context as an associative array.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'type' => $this->type,
      'label' => $this->label,
      'content' => $this->content,
      'priority' => $this->priority,
      'metadata' => $this->metadata,
      'enabled' => $this->enabled,
    ];
  }

  /**
   * Creates an AIContext instance from an array.
   *
   * @param array<string, mixed> $data
   *   The data array from serialization.
   *
   * @return self
   *   A new AIContext instance.
   *
   * @throws \InvalidArgumentException
   *   If required data is missing.
   */
  public static function fromArray(array $data): self {
    $requiredFields = ['id', 'type', 'label', 'content'];
    foreach ($requiredFields as $field) {
      if (!isset($data[$field])) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }

    return new self(
      id: $data['id'],
      type: $data['type'],
      label: $data['label'],
      content: $data['content'],
      priority: $data['priority'] ?? 0,
      metadata: $data['metadata'] ?? [],
      enabled: $data['enabled'] ?? TRUE,
    );
  }

  /**
   * Creates a new AIContext with a generated unique ID.
   *
   * @param string $type
   *   The context type.
   * @param string $label
   *   Human-readable label.
   * @param string $content
   *   The context content.
   * @param int $priority
   *   Priority value.
   * @param array<string, mixed> $metadata
   *   Optional metadata.
   *
   * @return self
   *   A new AIContext instance.
   */
  public static function create(
    string $type,
    string $label,
    string $content,
    int $priority = 0,
    array $metadata = [],
  ): self {
    return new self(
      id: 'context_' . bin2hex(random_bytes(6)),
      type: $type,
      label: $label,
      content: $content,
      priority: $priority,
      metadata: $metadata,
      enabled: TRUE,
    );
  }

  /**
   * Sorts an array of AIContext objects by priority.
   *
   * @param array<self> $contexts
   *   Array of contexts to sort.
   *
   * @return array<self>
   *   Sorted array with highest priority first.
   */
  public static function sortByPriority(array $contexts): array {
    usort($contexts, fn(self $a, self $b): int => $b->priority <=> $a->priority);
    return $contexts;
  }

  /**
   * Combines multiple contexts into a single prompt section.
   *
   * @param array<self> $contexts
   *   Array of contexts to combine.
   * @param bool $sortByPriority
   *   Whether to sort by priority first.
   *
   * @return string
   *   Combined context string for AI prompt.
   */
  public static function combineForPrompt(array $contexts, bool $sortByPriority = TRUE): string {
    $enabledContexts = array_filter($contexts, fn(self $c): bool => $c->enabled);

    if ($sortByPriority) {
      $enabledContexts = self::sortByPriority($enabledContexts);
    }

    $parts = array_map(
      fn(self $context): string => $context->formatForPrompt(),
      $enabledContexts
    );

    return implode("\n", $parts);
  }

}
