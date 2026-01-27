<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\PluginManager;

use Drupal\ai_content_preparation_wizard\Attribute\DocumentProcessor;
use Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\file\FileInterface;

/**
 * Provides a plugin manager for DocumentProcessor plugins.
 *
 * This manager discovers and manages DocumentProcessor plugins which are
 * responsible for extracting content and metadata from various file types.
 *
 * @see \Drupal\ai_content_preparation_wizard\Attribute\DocumentProcessor
 * @see \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface
 * @see \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorBase
 * @see plugin_api
 */
class DocumentProcessorPluginManager extends DefaultPluginManager {

  /**
   * Constructs a DocumentProcessorPluginManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/DocumentProcessor',
      $namespaces,
      $module_handler,
      DocumentProcessorInterface::class,
      DocumentProcessor::class,
    );
    $this->alterInfo('document_processor_info');
    $this->setCacheBackend($cache_backend, 'document_processor_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []): DocumentProcessorInterface {
    /** @var \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface $instance */
    $instance = parent::createInstance($plugin_id, $configuration);
    return $instance;
  }

  /**
   * Gets all available processors sorted by weight.
   *
   * @return \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface[]
   *   An array of processor instances, keyed by plugin ID and sorted by weight.
   */
  public function getProcessors(): array {
    $processors = [];
    $definitions = $this->getDefinitions();

    // Sort definitions by weight.
    uasort($definitions, function ($a, $b) {
      $weight_a = $a['weight'] ?? 0;
      $weight_b = $b['weight'] ?? 0;
      return $weight_a <=> $weight_b;
    });

    foreach ($definitions as $id => $definition) {
      $processors[$id] = $this->createInstance($id);
    }

    return $processors;
  }

  /**
   * Gets processors that can handle a specific file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to find processors for.
   *
   * @return \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface[]
   *   An array of processor instances that can handle the file,
   *   sorted by weight.
   */
  public function getProcessorsForFile(FileInterface $file): array {
    $compatible = [];

    foreach ($this->getProcessors() as $id => $processor) {
      if ($processor->canProcess($file)) {
        $compatible[$id] = $processor;
      }
    }

    return $compatible;
  }

  /**
   * Gets the best processor for a specific file.
   *
   * Returns the first processor (by weight) that can handle the file
   * and has all requirements met.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to find a processor for.
   *
   * @return \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface|null
   *   The best matching processor, or NULL if none found.
   */
  public function getBestProcessorForFile(FileInterface $file): ?DocumentProcessorInterface {
    $processors = $this->getProcessorsForFile($file);
    return reset($processors) ?: NULL;
  }

  /**
   * Gets processors that support a specific file extension.
   *
   * @param string $extension
   *   The file extension (without dot, e.g., 'pdf', 'docx').
   *
   * @return \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface[]
   *   An array of processor instances that support the extension.
   */
  public function getProcessorsForExtension(string $extension): array {
    $extension = strtolower($extension);
    $compatible = [];

    foreach ($this->getProcessors() as $id => $processor) {
      if (in_array($extension, $processor->getSupportedExtensions(), TRUE)) {
        $compatible[$id] = $processor;
      }
    }

    return $compatible;
  }

  /**
   * Gets all available processors that have met requirements.
   *
   * @return \Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface[]
   *   An array of processor instances with met requirements.
   */
  public function getAvailableProcessors(): array {
    $available = [];

    foreach ($this->getProcessors() as $id => $processor) {
      if ($processor->checkRequirements()) {
        $available[$id] = $processor;
      }
    }

    return $available;
  }

  /**
   * Gets all supported file extensions across all available processors.
   *
   * @return array
   *   An array of unique file extensions supported by available processors.
   */
  public function getAllSupportedExtensions(): array {
    $extensions = [];

    foreach ($this->getAvailableProcessors() as $processor) {
      $extensions = array_merge($extensions, $processor->getSupportedExtensions());
    }

    return array_unique($extensions);
  }

  /**
   * Checks if any processor can handle a specific extension.
   *
   * @param string $extension
   *   The file extension to check.
   *
   * @return bool
   *   TRUE if at least one available processor supports the extension.
   */
  public function isExtensionSupported(string $extension): bool {
    $extension = strtolower($extension);
    return in_array($extension, $this->getAllSupportedExtensions(), TRUE);
  }

  /**
   * Gets processors with unmet requirements.
   *
   * Useful for displaying status information to administrators.
   *
   * @return array
   *   An associative array where keys are plugin IDs and values are arrays
   *   containing:
   *   - 'label': The processor label.
   *   - 'errors': Array of requirement errors.
   */
  public function getUnavailableProcessors(): array {
    $unavailable = [];

    foreach ($this->getProcessors() as $id => $processor) {
      $errors = $processor->getRequirementErrors();
      if (!empty($errors)) {
        $unavailable[$id] = [
          'label' => $processor->label(),
          'errors' => $errors,
        ];
      }
    }

    return $unavailable;
  }

  /**
   * Gets a summary of all processors and their status.
   *
   * @return array
   *   An array of processor information, each containing:
   *   - 'id': Plugin ID.
   *   - 'label': Human-readable label.
   *   - 'description': Plugin description.
   *   - 'extensions': Supported file extensions.
   *   - 'weight': Processing weight.
   *   - 'available': Whether requirements are met.
   *   - 'errors': Array of requirement errors (if any).
   */
  public function getProcessorsSummary(): array {
    $summary = [];

    foreach ($this->getProcessors() as $id => $processor) {
      $errors = $processor->getRequirementErrors();
      $summary[$id] = [
        'id' => $id,
        'label' => $processor->label(),
        'description' => $processor->description(),
        'extensions' => $processor->getSupportedExtensions(),
        'weight' => $processor->getWeight(),
        'available' => empty($errors),
        'errors' => $errors,
      ];
    }

    return $summary;
  }

}
