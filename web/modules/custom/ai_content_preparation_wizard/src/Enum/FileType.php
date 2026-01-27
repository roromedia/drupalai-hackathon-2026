<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Enum;

/**
 * Supported file types for document processing.
 */
enum FileType: string {

  case TXT = 'txt';
  case DOCX = 'docx';
  case PDF = 'pdf';
  case RTF = 'rtf';
  case ODT = 'odt';
  case HTML = 'html';
  case MARKDOWN = 'md';

  /**
   * Gets a human-readable label for the file type.
   */
  public function label(): string {
    return match ($this) {
      self::TXT => 'Plain Text',
      self::DOCX => 'Word Document',
      self::PDF => 'PDF Document',
      self::RTF => 'Rich Text Format',
      self::ODT => 'OpenDocument Text',
      self::HTML => 'HTML Document',
      self::MARKDOWN => 'Markdown',
    };
  }

  /**
   * Gets the MIME type for the file type.
   */
  public function mimeType(): string {
    return match ($this) {
      self::TXT => 'text/plain',
      self::DOCX => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      self::PDF => 'application/pdf',
      self::RTF => 'application/rtf',
      self::ODT => 'application/vnd.oasis.opendocument.text',
      self::HTML => 'text/html',
      self::MARKDOWN => 'text/markdown',
    };
  }

  /**
   * Creates a FileType from a file extension.
   *
   * @param string $extension
   *   The file extension without the leading dot.
   *
   * @return self|null
   *   The matching FileType or NULL if not supported.
   */
  public static function fromExtension(string $extension): ?self {
    $extension = strtolower(trim($extension, '.'));
    return self::tryFrom($extension);
  }

  /**
   * Gets all supported extensions.
   *
   * @return array<string>
   *   Array of supported file extensions.
   */
  public static function supportedExtensions(): array {
    return array_map(
      fn(self $type): string => $type->value,
      self::cases()
    );
  }

}
