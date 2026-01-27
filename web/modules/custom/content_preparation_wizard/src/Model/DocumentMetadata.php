<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Model;

/**
 * Immutable value object representing document metadata.
 *
 * Contains extracted metadata from processed documents such as title,
 * author, creation date, and structural information like headings.
 */
final class DocumentMetadata {

  /**
   * Constructs a DocumentMetadata object.
   *
   * @param string|null $title
   *   The document title.
   * @param string|null $author
   *   The document author.
   * @param \DateTimeImmutable|null $createdDate
   *   The document creation date.
   * @param string|null $language
   *   The document language code (e.g., 'en', 'de').
   * @param array<string> $headings
   *   Array of heading texts extracted from the document.
   * @param array<string, mixed> $customProperties
   *   Additional custom metadata properties.
   */
  public function __construct(
    public readonly ?string $title = NULL,
    public readonly ?string $author = NULL,
    public readonly ?\DateTimeImmutable $createdDate = NULL,
    public readonly ?string $language = NULL,
    public readonly array $headings = [],
    public readonly array $customProperties = [],
  ) {}

  /**
   * Creates a new instance with an updated title.
   *
   * @param string|null $title
   *   The new title.
   *
   * @return self
   *   A new instance with the updated title.
   */
  public function withTitle(?string $title): self {
    return new self(
      $title,
      $this->author,
      $this->createdDate,
      $this->language,
      $this->headings,
      $this->customProperties,
    );
  }

  /**
   * Creates a new instance with an additional custom property.
   *
   * @param string $key
   *   The property key.
   * @param mixed $value
   *   The property value.
   *
   * @return self
   *   A new instance with the added property.
   */
  public function withCustomProperty(string $key, mixed $value): self {
    $properties = $this->customProperties;
    $properties[$key] = $value;

    return new self(
      $this->title,
      $this->author,
      $this->createdDate,
      $this->language,
      $this->headings,
      $properties,
    );
  }

  /**
   * Gets a custom property value.
   *
   * @param string $key
   *   The property key.
   * @param mixed $default
   *   Default value if property doesn't exist.
   *
   * @return mixed
   *   The property value or default.
   */
  public function getCustomProperty(string $key, mixed $default = NULL): mixed {
    return $this->customProperties[$key] ?? $default;
  }

  /**
   * Checks if the metadata has any content.
   *
   * @return bool
   *   TRUE if any metadata field is populated.
   */
  public function isEmpty(): bool {
    return $this->title === NULL
      && $this->author === NULL
      && $this->createdDate === NULL
      && $this->language === NULL
      && empty($this->headings)
      && empty($this->customProperties);
  }

  /**
   * Converts the metadata to an array for serialization.
   *
   * @return array<string, mixed>
   *   The metadata as an associative array.
   */
  public function toArray(): array {
    return [
      'title' => $this->title,
      'author' => $this->author,
      'created_date' => $this->createdDate?->format(\DateTimeInterface::RFC3339),
      'language' => $this->language,
      'headings' => $this->headings,
      'custom_properties' => $this->customProperties,
    ];
  }

  /**
   * Creates a DocumentMetadata instance from an array.
   *
   * @param array<string, mixed> $data
   *   The data array from serialization.
   *
   * @return self
   *   A new DocumentMetadata instance.
   */
  public static function fromArray(array $data): self {
    $createdDate = NULL;
    if (!empty($data['created_date'])) {
      $createdDate = new \DateTimeImmutable($data['created_date']);
    }

    return new self(
      title: $data['title'] ?? NULL,
      author: $data['author'] ?? NULL,
      createdDate: $createdDate,
      language: $data['language'] ?? NULL,
      headings: $data['headings'] ?? [],
      customProperties: $data['custom_properties'] ?? [],
    );
  }

}
