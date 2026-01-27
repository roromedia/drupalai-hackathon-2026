<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a DocumentProcessor plugin attribute.
 *
 * Plugin Namespace: Plugin\DocumentProcessor.
 *
 * Document processors are responsible for extracting content and metadata
 * from various file types (PDF, DOCX, etc.) and converting them to a
 * standardized format for the Content Preparation Wizard.
 *
 * @see \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface
 * @see \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorBase
 * @see \Drupal\ai_content_preparation_wizard\PluginManager\DocumentProcessorPluginManager
 * @see plugin_api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DocumentProcessor extends Plugin {

  /**
   * Constructs a new DocumentProcessor instance.
   *
   * @param string $id
   *   The plugin ID. Must be unique across all DocumentProcessor plugins.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable name of the plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   A brief description of what this processor does.
   * @param array $supported_extensions
   *   Array of file extensions this processor supports (e.g., ['pdf', 'docx']).
   *   Extensions should be lowercase without dots.
   * @param int $weight
   *   The weight of the processor for ordering. Lower weights are processed
   *   first when multiple processors can handle a file. Defaults to 0.
   * @param array $requirements
   *   Array of requirements for this processor. Each requirement should be
   *   an associative array with keys:
   *   - 'type': The type of requirement ('php_extension', 'library', 'module',
   *     'class').
   *   - 'name': The name of the requirement (extension name, class name, etc.).
   *   - 'description': Optional human-readable description of the requirement.
   * @param class-string|null $deriver
   *   (optional) The deriver class for creating derivative plugins.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly TranslatableMarkup $description,
    public readonly array $supported_extensions = [],
    public readonly int $weight = 0,
    public readonly array $requirements = [],
    public readonly ?string $deriver = NULL,
  ) {}

}
