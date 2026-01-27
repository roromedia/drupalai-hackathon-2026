<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Plugin\DocumentProcessor;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for document processor plugins.
 *
 * Provides common functionality and dependency injection for all
 * document processor implementations.
 *
 * @see \Drupal\content_preparation_wizard\Attribute\DocumentProcessor
 * @see \Drupal\content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorInterface
 */
abstract class DocumentProcessorBase extends PluginBase implements DocumentProcessorInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Constructs a DocumentProcessorBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('content_preparation_wizard');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('file_system'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function canProcess(FileInterface $file): bool {
    if (!$this->checkRequirements()) {
      return FALSE;
    }

    $extension = $this->getFileExtension($file);
    return in_array($extension, $this->getSupportedExtensions(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return $this->pluginDefinition['supported_extensions'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): bool {
    return empty($this->getRequirementErrors());
  }

  /**
   * {@inheritdoc}
   */
  public function getRequirementErrors(): array {
    $errors = [];
    $requirements = $this->pluginDefinition['requirements'] ?? [];

    foreach ($requirements as $requirement) {
      $type = $requirement['type'] ?? 'unknown';
      $name = $requirement['name'] ?? '';
      $description = $requirement['description'] ?? '';

      switch ($type) {
        case 'php_extension':
          if (!extension_loaded($name)) {
            $errors[] = [
              'type' => $type,
              'name' => $name,
              'description' => $description ?: $this->t('PHP extension @name is not installed.', ['@name' => $name]),
            ];
          }
          break;

        case 'library':
          if (!$this->checkLibraryExists($name)) {
            $errors[] = [
              'type' => $type,
              'name' => $name,
              'description' => $description ?: $this->t('Library @name is not available.', ['@name' => $name]),
            ];
          }
          break;

        case 'module':
          if (!\Drupal::moduleHandler()->moduleExists($name)) {
            $errors[] = [
              'type' => $type,
              'name' => $name,
              'description' => $description ?: $this->t('Drupal module @name is not enabled.', ['@name' => $name]),
            ];
          }
          break;

        case 'class':
          if (!class_exists($name)) {
            $errors[] = [
              'type' => $type,
              'name' => $name,
              'description' => $description ?: $this->t('Class @name is not available.', ['@name' => $name]),
            ];
          }
          break;

        default:
          $this->logger->warning('Unknown requirement type: @type', ['@type' => $type]);
      }
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) ($this->pluginDefinition['label'] ?? $this->pluginId);
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return (string) ($this->pluginDefinition['description'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return (int) ($this->pluginDefinition['weight'] ?? 0);
  }

  /**
   * Gets the lowercase file extension from a file entity.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return string
   *   The lowercase file extension without the dot.
   */
  protected function getFileExtension(FileInterface $file): string {
    $filename = $file->getFilename();
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    return strtolower($extension);
  }

  /**
   * Gets the real filesystem path of a file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return string|false
   *   The real path to the file, or FALSE on failure.
   */
  protected function getRealPath(FileInterface $file): string|false {
    $uri = $file->getFileUri();
    return $this->fileSystem->realpath($uri);
  }

  /**
   * Validates that a file exists and is readable.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to validate.
   *
   * @return bool
   *   TRUE if the file exists and is readable, FALSE otherwise.
   */
  protected function validateFileAccess(FileInterface $file): bool {
    $path = $this->getRealPath($file);
    if ($path === FALSE) {
      $this->logger->error('Could not resolve real path for file @fid', [
        '@fid' => $file->id(),
      ]);
      return FALSE;
    }

    if (!file_exists($path)) {
      $this->logger->error('File does not exist at path: @path', [
        '@path' => $path,
      ]);
      return FALSE;
    }

    if (!is_readable($path)) {
      $this->logger->error('File is not readable: @path', [
        '@path' => $path,
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Gets the file size in bytes.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return int
   *   The file size in bytes, or 0 if unable to determine.
   */
  protected function getFileSize(FileInterface $file): int {
    return (int) $file->getSize();
  }

  /**
   * Gets the MIME type of a file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return string
   *   The MIME type of the file.
   */
  protected function getMimeType(FileInterface $file): string {
    return $file->getMimeType() ?? 'application/octet-stream';
  }

  /**
   * Checks if a library is available.
   *
   * Override this method in subclasses for custom library detection.
   *
   * @param string $library_name
   *   The name of the library to check.
   *
   * @return bool
   *   TRUE if the library is available, FALSE otherwise.
   */
  protected function checkLibraryExists(string $library_name): bool {
    // Default implementation checks for class existence.
    // Subclasses can override for more specific checks.
    return class_exists($library_name);
  }

  /**
   * Logs a processing error.
   *
   * @param string $message
   *   The error message.
   * @param \Drupal\file\FileInterface $file
   *   The file being processed.
   * @param array $context
   *   Additional context for the log message.
   */
  protected function logError(string $message, FileInterface $file, array $context = []): void {
    $context += [
      '@fid' => $file->id(),
      '@filename' => $file->getFilename(),
      '@processor' => $this->getPluginId(),
    ];
    $this->logger->error($message . ' (File: @filename, ID: @fid, Processor: @processor)', $context);
  }

  /**
   * Logs a processing warning.
   *
   * @param string $message
   *   The warning message.
   * @param \Drupal\file\FileInterface $file
   *   The file being processed.
   * @param array $context
   *   Additional context for the log message.
   */
  protected function logWarning(string $message, FileInterface $file, array $context = []): void {
    $context += [
      '@fid' => $file->id(),
      '@filename' => $file->getFilename(),
      '@processor' => $this->getPluginId(),
    ];
    $this->logger->warning($message . ' (File: @filename, ID: @fid, Processor: @processor)', $context);
  }

  /**
   * Logs a processing info message.
   *
   * @param string $message
   *   The info message.
   * @param \Drupal\file\FileInterface $file
   *   The file being processed.
   * @param array $context
   *   Additional context for the log message.
   */
  protected function logInfo(string $message, FileInterface $file, array $context = []): void {
    $context += [
      '@fid' => $file->id(),
      '@filename' => $file->getFilename(),
      '@processor' => $this->getPluginId(),
    ];
    $this->logger->info($message . ' (File: @filename, ID: @fid, Processor: @processor)', $context);
  }

}
