<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Model;

/**
 * Immutable value object representing a mapping from plan section to Canvas component.
 *
 * Defines how a content plan section should be transformed into a
 * Canvas component during content creation.
 */
final class ComponentMapping {

  /**
   * Constructs a ComponentMapping object.
   *
   * @param string $id
   *   Unique identifier for this mapping.
   * @param string $sectionId
   *   The ID of the PlanSection this maps.
   * @param string $componentType
   *   The Canvas component type ID.
   * @param string $componentBundle
   *   The component bundle/variant if applicable.
   * @param array<string, mixed> $fieldMappings
   *   Mapping of component fields to content sources.
   * @param array<string, mixed> $componentSettings
   *   Additional component-specific settings.
   * @param int $weight
   *   The weight for ordering in the component tree.
   * @param string|null $parentMappingId
   *   Parent mapping ID for nested components.
   * @param string|null $region
   *   The region within the parent component.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $sectionId,
    public readonly string $componentType,
    public readonly string $componentBundle,
    public readonly array $fieldMappings,
    public readonly array $componentSettings = [],
    public readonly int $weight = 0,
    public readonly ?string $parentMappingId = NULL,
    public readonly ?string $region = NULL,
  ) {}

  /**
   * Creates a new instance with updated field mappings.
   *
   * @param array<string, mixed> $fieldMappings
   *   The new field mappings.
   *
   * @return self
   *   A new instance with updated field mappings.
   */
  public function withFieldMappings(array $fieldMappings): self {
    return new self(
      $this->id,
      $this->sectionId,
      $this->componentType,
      $this->componentBundle,
      array_merge($this->fieldMappings, $fieldMappings),
      $this->componentSettings,
      $this->weight,
      $this->parentMappingId,
      $this->region,
    );
  }

  /**
   * Creates a new instance with updated component settings.
   *
   * @param array<string, mixed> $settings
   *   The new settings to merge.
   *
   * @return self
   *   A new instance with updated settings.
   */
  public function withComponentSettings(array $settings): self {
    return new self(
      $this->id,
      $this->sectionId,
      $this->componentType,
      $this->componentBundle,
      $this->fieldMappings,
      array_merge($this->componentSettings, $settings),
      $this->weight,
      $this->parentMappingId,
      $this->region,
    );
  }

  /**
   * Creates a new instance with a parent mapping.
   *
   * @param string $parentMappingId
   *   The parent mapping ID.
   * @param string|null $region
   *   The region within the parent.
   *
   * @return self
   *   A new instance with the parent reference.
   */
  public function withParent(string $parentMappingId, ?string $region = NULL): self {
    return new self(
      $this->id,
      $this->sectionId,
      $this->componentType,
      $this->componentBundle,
      $this->fieldMappings,
      $this->componentSettings,
      $this->weight,
      $parentMappingId,
      $region,
    );
  }

  /**
   * Creates a new instance with updated weight.
   *
   * @param int $weight
   *   The new weight.
   *
   * @return self
   *   A new instance with updated weight.
   */
  public function withWeight(int $weight): self {
    return new self(
      $this->id,
      $this->sectionId,
      $this->componentType,
      $this->componentBundle,
      $this->fieldMappings,
      $this->componentSettings,
      $weight,
      $this->parentMappingId,
      $this->region,
    );
  }

  /**
   * Checks if this mapping has a parent.
   *
   * @return bool
   *   TRUE if this mapping has a parent.
   */
  public function hasParent(): bool {
    return $this->parentMappingId !== NULL;
  }

  /**
   * Gets a field mapping value.
   *
   * @param string $fieldName
   *   The field name.
   * @param mixed $default
   *   Default value if not mapped.
   *
   * @return mixed
   *   The mapped value or default.
   */
  public function getFieldMapping(string $fieldName, mixed $default = NULL): mixed {
    return $this->fieldMappings[$fieldName] ?? $default;
  }

  /**
   * Gets a component setting value.
   *
   * @param string $key
   *   The setting key.
   * @param mixed $default
   *   Default value if not set.
   *
   * @return mixed
   *   The setting value or default.
   */
  public function getComponentSetting(string $key, mixed $default = NULL): mixed {
    return $this->componentSettings[$key] ?? $default;
  }

  /**
   * Gets the full component identifier.
   *
   * @return string
   *   Component type and bundle combined.
   */
  public function getFullComponentId(): string {
    if ($this->componentBundle) {
      return $this->componentType . ':' . $this->componentBundle;
    }
    return $this->componentType;
  }

  /**
   * Converts the mapping to an array for serialization.
   *
   * @return array<string, mixed>
   *   The mapping as an associative array.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'section_id' => $this->sectionId,
      'component_type' => $this->componentType,
      'component_bundle' => $this->componentBundle,
      'field_mappings' => $this->fieldMappings,
      'component_settings' => $this->componentSettings,
      'weight' => $this->weight,
      'parent_mapping_id' => $this->parentMappingId,
      'region' => $this->region,
    ];
  }

  /**
   * Creates a ComponentMapping instance from an array.
   *
   * @param array<string, mixed> $data
   *   The data array from serialization.
   *
   * @return self
   *   A new ComponentMapping instance.
   *
   * @throws \InvalidArgumentException
   *   If required data is missing.
   */
  public static function fromArray(array $data): self {
    $requiredFields = ['id', 'section_id', 'component_type', 'component_bundle', 'field_mappings'];
    foreach ($requiredFields as $field) {
      if (!isset($data[$field])) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }

    return new self(
      id: $data['id'],
      sectionId: $data['section_id'],
      componentType: $data['component_type'],
      componentBundle: $data['component_bundle'],
      fieldMappings: $data['field_mappings'],
      componentSettings: $data['component_settings'] ?? [],
      weight: $data['weight'] ?? 0,
      parentMappingId: $data['parent_mapping_id'] ?? NULL,
      region: $data['region'] ?? NULL,
    );
  }

  /**
   * Creates a new ComponentMapping with a generated unique ID.
   *
   * @param string $sectionId
   *   The section ID to map.
   * @param string $componentType
   *   The Canvas component type.
   * @param string $componentBundle
   *   The component bundle.
   * @param array<string, mixed> $fieldMappings
   *   Field mappings.
   * @param int $weight
   *   Optional weight.
   *
   * @return self
   *   A new ComponentMapping instance.
   */
  public static function create(
    string $sectionId,
    string $componentType,
    string $componentBundle,
    array $fieldMappings,
    int $weight = 0,
  ): self {
    return new self(
      id: 'mapping_' . bin2hex(random_bytes(6)),
      sectionId: $sectionId,
      componentType: $componentType,
      componentBundle: $componentBundle,
      fieldMappings: $fieldMappings,
      componentSettings: [],
      weight: $weight,
      parentMappingId: NULL,
      region: NULL,
    );
  }

  /**
   * Creates default field mappings for common component types.
   *
   * @param \Drupal\content_preparation_wizard\Model\PlanSection $section
   *   The plan section.
   * @param string $componentType
   *   The component type.
   *
   * @return array<string, mixed>
   *   Default field mappings.
   */
  public static function getDefaultFieldMappings(PlanSection $section, string $componentType): array {
    return match ($componentType) {
      'text', 'rich_text' => [
        'body' => $section->content,
      ],
      'heading' => [
        'title' => $section->title,
        'level' => $section->componentConfig['heading_level'] ?? 2,
      ],
      'image' => [
        'alt' => $section->title,
        'caption' => $section->content,
      ],
      'accordion' => [
        'title' => $section->title,
        'content' => $section->content,
        'expanded' => FALSE,
      ],
      'card' => [
        'title' => $section->title,
        'body' => $section->content,
      ],
      default => [
        'content' => $section->content,
      ],
    };
  }

  /**
   * Sorts an array of ComponentMapping objects by weight.
   *
   * @param array<self> $mappings
   *   Array of mappings to sort.
   *
   * @return array<self>
   *   Sorted array.
   */
  public static function sortByWeight(array $mappings): array {
    usort($mappings, fn(self $a, self $b): int => $a->weight <=> $b->weight);
    return $mappings;
  }

  /**
   * Builds a tree structure from flat mappings.
   *
   * @param array<self> $mappings
   *   Flat array of mappings.
   *
   * @return array<string, array<string, mixed>>
   *   Tree structure with children nested under parents.
   */
  public static function buildTree(array $mappings): array {
    $tree = [];
    $mappingById = [];

    // Index by ID.
    foreach ($mappings as $mapping) {
      $mappingById[$mapping->id] = [
        'mapping' => $mapping,
        'children' => [],
      ];
    }

    // Build tree.
    foreach ($mappings as $mapping) {
      if ($mapping->parentMappingId === NULL) {
        $tree[$mapping->id] = &$mappingById[$mapping->id];
      }
      elseif (isset($mappingById[$mapping->parentMappingId])) {
        $region = $mapping->region ?? 'default';
        $mappingById[$mapping->parentMappingId]['children'][$region][] = &$mappingById[$mapping->id];
      }
    }

    return $tree;
  }

}
