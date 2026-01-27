<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Enum;

/**
 * Document processing providers.
 */
enum ProcessingProvider: string {

  case PANDOC = 'pandoc';
  case PHP_NATIVE = 'php_native';
  case SMALOT_PDF = 'smalot_pdf';
  case PHPWORD = 'phpword';
  case AI_VISION = 'ai_vision';
  case CUSTOM = 'custom';

  /**
   * Gets a human-readable label for the provider.
   */
  public function label(): string {
    return match ($this) {
      self::PANDOC => 'Pandoc',
      self::PHP_NATIVE => 'PHP Native',
      self::SMALOT_PDF => 'Smalot PDF Parser',
      self::PHPWORD => 'PHPWord',
      self::AI_VISION => 'AI Vision OCR',
      self::CUSTOM => 'Custom Processor',
    };
  }

  /**
   * Gets a description of the provider.
   */
  public function description(): string {
    return match ($this) {
      self::PANDOC => 'Universal document converter using Pandoc CLI',
      self::PHP_NATIVE => 'Native PHP file reading for plain text files',
      self::SMALOT_PDF => 'PHP library for PDF text extraction',
      self::PHPWORD => 'PHP library for Word document processing',
      self::AI_VISION => 'AI-powered OCR for scanned documents',
      self::CUSTOM => 'Custom implementation provided by a plugin',
    };
  }

  /**
   * Checks if the provider requires external dependencies.
   */
  public function requiresExternalDependency(): bool {
    return match ($this) {
      self::PANDOC => TRUE,
      self::PHP_NATIVE => FALSE,
      self::SMALOT_PDF => FALSE,
      self::PHPWORD => FALSE,
      self::AI_VISION => TRUE,
      self::CUSTOM => FALSE,
    };
  }

}
