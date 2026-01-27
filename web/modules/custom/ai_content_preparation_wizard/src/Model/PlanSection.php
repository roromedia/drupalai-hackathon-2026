<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Model;

/**
 * Immutable value object representing a section within a content plan.
 *
 * Each section corresponds to a logical division of the planned content,
 * which will map to Canvas components during content creation.
 */
final class PlanSection {

  /**
   * Constructs a PlanSection object.
   *
   * @param string $id
   *   Unique identifier for this section.
   * @param string $title
   *   The section title/heading.
   * @param string $content
   *   The planned content for this section.
   * @param string $componentType
   *   The suggested Canvas component type (e.g., 'text', 'heading', 'image').
   * @param int $order
   *   The display order of this section.
   * @param array<string, mixed> $componentConfig
   *   Additional configuration for the Canvas component.
   * @param array<self> $children
   *   Nested child sections for hierarchical content.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $title,
    public readonly string $content,
    public readonly string $componentType,
    public readonly int $order,
    public readonly array $componentConfig = [],
    public readonly array $children = [],
  ) {}

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
      $this->title,
      $content,
      $this->componentType,
      $this->order,
      $this->componentConfig,
      $this->children,
    );
  }

  /**
   * Creates a new instance with an added child section.
   *
   * @param self $child
   *   The child section to add.
   *
   * @return self
   *   A new instance with the added child.
   */
  public function withChild(self $child): self {
    $children = $this->children;
    $children[] = $child;

    return new self(
      $this->id,
      $this->title,
      $this->content,
      $this->componentType,
      $this->order,
      $this->componentConfig,
      $children,
    );
  }

  /**
   * Creates a new instance with updated component configuration.
   *
   * @param array<string, mixed> $config
   *   The new component configuration.
   *
   * @return self
   *   A new instance with updated configuration.
   */
  public function withComponentConfig(array $config): self {
    return new self(
      $this->id,
      $this->title,
      $this->content,
      $this->componentType,
      $this->order,
      array_merge($this->componentConfig, $config),
      $this->children,
    );
  }

  /**
   * Checks if this section has children.
   *
   * @return bool
   *   TRUE if this section has child sections.
   */
  public function hasChildren(): bool {
    return !empty($this->children);
  }

  /**
   * Gets the total word count including children.
   *
   * @return int
   *   The total word count.
   */
  public function getTotalWordCount(): int {
    $words = str_word_count(strip_tags($this->content));

    foreach ($this->children as $child) {
      $words += $child->getTotalWordCount();
    }

    return $words;
  }

  /**
   * Flattens the section hierarchy to a single-level array.
   *
   * @return array<self>
   *   Array of all sections in order.
   */
  public function flatten(): array {
    $sections = [$this];

    foreach ($this->children as $child) {
      $sections = array_merge($sections, $child->flatten());
    }

    return $sections;
  }

  /**
   * Converts the section to an array for serialization.
   *
   * @return array<string, mixed>
   *   The section as an associative array.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'title' => $this->title,
      'content' => $this->content,
      'component_type' => $this->componentType,
      'order' => $this->order,
      'component_config' => $this->componentConfig,
      'children' => array_map(
        fn(self $child): array => $child->toArray(),
        $this->children
      ),
    ];
  }

  /**
   * Creates a PlanSection instance from an array.
   *
   * @param array<string, mixed> $data
   *   The data array from serialization.
   *
   * @return self
   *   A new PlanSection instance.
   *
   * @throws \InvalidArgumentException
   *   If required data is missing.
   */
  public static function fromArray(array $data): self {
    $requiredFields = ['id', 'title', 'content', 'component_type', 'order'];
    foreach ($requiredFields as $field) {
      if (!isset($data[$field])) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }

    $children = [];
    if (!empty($data['children'])) {
      foreach ($data['children'] as $childData) {
        $children[] = self::fromArray($childData);
      }
    }

    return new self(
      id: $data['id'],
      title: $data['title'],
      content: $data['content'],
      componentType: $data['component_type'],
      order: (int) $data['order'],
      componentConfig: $data['component_config'] ?? [],
      children: $children,
    );
  }

  /**
   * Creates a new PlanSection with a generated unique ID.
   *
   * @param string $title
   *   The section title.
   * @param string $content
   *   The section content.
   * @param string $componentType
   *   The Canvas component type.
   * @param int $order
   *   The display order.
   * @param array<string, mixed> $componentConfig
   *   Optional component configuration.
   *
   * @return self
   *   A new PlanSection instance.
   */
  public static function create(
    string $title,
    string $content,
    string $componentType,
    int $order,
    array $componentConfig = [],
  ): self {
    return new self(
      id: 'section_' . bin2hex(random_bytes(6)),
      title: $title,
      content: $content,
      componentType: $componentType,
      order: $order,
      componentConfig: $componentConfig,
      children: [],
    );
  }

}
