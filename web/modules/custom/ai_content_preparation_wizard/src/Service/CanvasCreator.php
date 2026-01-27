<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\ai_content_preparation_wizard\Event\CanvasPageCreatedEvent;
use Drupal\ai_content_preparation_wizard\Exception\CanvasCreationException;
use Drupal\ai_content_preparation_wizard\Model\ContentPlan;
use Drupal\ai_content_preparation_wizard\Model\PlanSection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Service for creating Canvas pages from content plans.
 *
 * This service transforms ContentPlan objects into Canvas page entities,
 * mapping plan sections to appropriate Canvas components and building
 * the component tree structure.
 */
class CanvasCreator implements CanvasCreatorInterface {

  /**
   * The Canvas page entity type ID.
   */
  protected const CANVAS_PAGE_ENTITY_TYPE = 'canvas_page';

  /**
   * Default component type mappings from plan section types to Canvas components.
   *
   * @var array<string, string>
   */
  protected const DEFAULT_COMPONENT_MAPPINGS = [
    'hero' => 'canvas:hero',
    'text' => 'canvas:text',
    'list' => 'canvas:list',
    'cta' => 'canvas:cta',
    'heading' => 'canvas:heading',
    'image' => 'canvas:image',
    'quote' => 'canvas:quote',
    'section' => 'canvas:section',
  ];

  /**
   * The logger instance.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a CanvasCreator service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator service.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly UuidInterface $uuid,
  ) {
    $this->logger = $loggerFactory->get('ai_content_preparation_wizard');
  }

  /**
   * {@inheritdoc}
   */
  public function create(ContentPlan $plan, array $options = []): EntityInterface {
    try {
      // Verify Canvas module is available.
      if (!$this->isCanvasAvailable()) {
        throw new CanvasCreationException(
          'Canvas module is not available or not properly configured.',
          $plan->title
        );
      }

      // Create the Canvas page entity.
      $storage = $this->entityTypeManager->getStorage(self::CANVAS_PAGE_ENTITY_TYPE);

      $values = [
        'title' => $options['title'] ?? $plan->title,
        'status' => $options['status'] ?? FALSE,
      ];

      // Set description/meta if provided.
      if (isset($options['description'])) {
        $values['description'] = $options['description'];
      }
      elseif (!empty($plan->summary)) {
        $values['description'] = $plan->summary;
      }

      // Set owner if provided.
      if (isset($options['owner'])) {
        $values['owner'] = $options['owner'];
      }

      /** @var \Drupal\Core\Entity\ContentEntityInterface $page */
      $page = $storage->create($values);

      // Build and set the component tree.
      $components = $this->mapToComponents($plan);
      if (!empty($components)) {
        $this->setComponentTree($page, $components);
      }

      // Set URL alias if provided.
      if (isset($options['alias']) && $page->hasField('path')) {
        $page->set('path', ['alias' => $options['alias']]);
      }

      // Validate the entity before saving.
      $violations = $page->validate();
      if ($violations->count() > 0) {
        $errors = [];
        foreach ($violations as $violation) {
          $errors[] = sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage());
        }
        throw new CanvasCreationException(
          'Canvas page validation failed.',
          $plan->title,
          $errors
        );
      }

      // Save the entity.
      $page->save();

      $this->logger->info('Created Canvas page "@title" (ID: @id) from content plan @plan_id.', [
        '@title' => $page->label(),
        '@id' => $page->id(),
        '@plan_id' => $plan->id,
      ]);

      // Dispatch the creation event.
      $event = new CanvasPageCreatedEvent($page, $plan);
      $this->eventDispatcher->dispatch($event, CanvasPageCreatedEvent::EVENT_NAME);

      return $page;
    }
    catch (CanvasCreationException $e) {
      // Re-throw CanvasCreationException as-is.
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create Canvas page from content plan @plan_id: @message', [
        '@plan_id' => $plan->id,
        '@message' => $e->getMessage(),
      ]);

      throw new CanvasCreationException(
        sprintf('Failed to create Canvas page: %s', $e->getMessage()),
        $plan->title,
        NULL,
        0,
        $e
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function mapToComponents(ContentPlan $plan): array {
    $components = [];
    $order = 0;

    foreach ($plan->sections as $section) {
      $sectionComponents = $this->mapSectionToComponents($section, $order);
      $components = array_merge($components, $sectionComponents);
      $order += count($sectionComponents);
    }

    return $components;
  }

  /**
   * Maps a single plan section and its children to component structures.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\PlanSection $section
   *   The plan section to map.
   * @param int $order
   *   The starting order index.
   * @param string|null $parentUuid
   *   The parent component UUID if this is a child section.
   * @param string|null $slot
   *   The slot name in the parent component.
   *
   * @return array<int, array<string, mixed>>
   *   Array of component structures.
   */
  protected function mapSectionToComponents(
    PlanSection $section,
    int $order,
    ?string $parentUuid = NULL,
    ?string $slot = NULL
  ): array {
    $components = [];
    $componentUuid = $this->uuid->generate();

    // Get the Canvas component ID for this section type.
    $componentId = $this->resolveComponentId($section->componentType);

    // Build the component structure.
    $component = [
      'uuid' => $componentUuid,
      'component_id' => $componentId,
      'inputs' => $this->buildComponentInputs($section),
    ];

    // Add parent reference for nested components.
    if ($parentUuid !== NULL) {
      $component['parent_uuid'] = $parentUuid;
    }
    if ($slot !== NULL) {
      $component['slot'] = $slot;
    }

    // Add label if section has a title.
    if (!empty($section->title)) {
      $component['label'] = $section->title;
    }

    $components[] = $component;

    // Process child sections recursively.
    if ($section->hasChildren()) {
      foreach ($section->children as $childIndex => $child) {
        $childComponents = $this->mapSectionToComponents(
          $child,
          $order + count($components),
          $componentUuid,
          'content'
        );
        $components = array_merge($components, $childComponents);
      }
    }

    return $components;
  }

  /**
   * Resolves the Canvas component ID for a given section type.
   *
   * @param string $sectionType
   *   The plan section component type.
   *
   * @return string
   *   The Canvas component entity ID.
   */
  protected function resolveComponentId(string $sectionType): string {
    // Check if we have a direct mapping.
    if (isset(self::DEFAULT_COMPONENT_MAPPINGS[$sectionType])) {
      return self::DEFAULT_COMPONENT_MAPPINGS[$sectionType];
    }

    // Check if the section type already looks like a component ID.
    if (str_contains($sectionType, ':')) {
      return $sectionType;
    }

    // Default to text component for unknown types.
    $this->logger->notice('Unknown section type "@type", defaulting to text component.', [
      '@type' => $sectionType,
    ]);

    return self::DEFAULT_COMPONENT_MAPPINGS['text'];
  }

  /**
   * Builds the component inputs from section data.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\PlanSection $section
   *   The plan section.
   *
   * @return array<string, mixed>
   *   The component inputs structure.
   */
  protected function buildComponentInputs(PlanSection $section): array {
    $inputs = [];

    // Map section content based on component type.
    switch ($section->componentType) {
      case 'hero':
        $inputs['title'] = ['static' => $section->title];
        $inputs['text'] = ['static' => $section->content];
        break;

      case 'heading':
        $inputs['text'] = ['static' => $section->title];
        if (isset($section->componentConfig['level'])) {
          $inputs['level'] = ['static' => $section->componentConfig['level']];
        }
        break;

      case 'list':
        $inputs['title'] = ['static' => $section->title];
        // Parse content into list items if it contains line breaks.
        $items = $this->parseListItems($section->content);
        $inputs['items'] = ['static' => $items];
        break;

      case 'cta':
        $inputs['title'] = ['static' => $section->title];
        $inputs['text'] = ['static' => $section->content];
        if (isset($section->componentConfig['button_text'])) {
          $inputs['button_text'] = ['static' => $section->componentConfig['button_text']];
        }
        if (isset($section->componentConfig['button_url'])) {
          $inputs['button_url'] = ['static' => $section->componentConfig['button_url']];
        }
        break;

      case 'quote':
        $inputs['quote'] = ['static' => $section->content];
        if (isset($section->componentConfig['attribution'])) {
          $inputs['attribution'] = ['static' => $section->componentConfig['attribution']];
        }
        break;

      case 'image':
        if (isset($section->componentConfig['media_id'])) {
          $inputs['image'] = ['static' => $section->componentConfig['media_id']];
        }
        if (!empty($section->content)) {
          $inputs['alt'] = ['static' => $section->content];
        }
        break;

      case 'text':
      default:
        // Default text component mapping.
        if (!empty($section->title)) {
          $inputs['title'] = ['static' => $section->title];
        }
        $inputs['text'] = ['static' => $section->content];
        break;
    }

    // Merge any additional component config as inputs.
    foreach ($section->componentConfig as $key => $value) {
      if (!isset($inputs[$key])) {
        $inputs[$key] = ['static' => $value];
      }
    }

    return $inputs;
  }

  /**
   * Parses content into list items.
   *
   * @param string $content
   *   The content to parse.
   *
   * @return array<string>
   *   Array of list items.
   */
  protected function parseListItems(string $content): array {
    // Split by newlines and filter empty lines.
    $lines = preg_split('/\r?\n/', $content);
    if ($lines === FALSE) {
      return [$content];
    }

    $items = [];
    foreach ($lines as $line) {
      $line = trim($line);
      // Remove common list prefixes.
      $line = preg_replace('/^[\-\*\+]\s*/', '', $line);
      $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);
      if (!empty($line)) {
        $items[] = $line;
      }
    }

    return !empty($items) ? $items : [$content];
  }

  /**
   * Sets the component tree on a Canvas page entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The Canvas page entity.
   * @param array<int, array<string, mixed>> $components
   *   The component tree data.
   */
  protected function setComponentTree(EntityInterface $page, array $components): void {
    // Check if the entity has the components field.
    if (!method_exists($page, 'hasField') || !$page->hasField('components')) {
      $this->logger->warning('Canvas page entity does not have a components field.');
      return;
    }

    // Use setComponentTree method if available (preferred).
    if (method_exists($page, 'setComponentTree')) {
      $page->setComponentTree($components);
      return;
    }

    // Fallback to direct field setting.
    if (method_exists($page, 'set')) {
      $page->set('components', $components);
    }
  }

  /**
   * Checks if the Canvas module is available.
   *
   * @return bool
   *   TRUE if Canvas module is available, FALSE otherwise.
   */
  protected function isCanvasAvailable(): bool {
    try {
      $definition = $this->entityTypeManager->getDefinition(self::CANVAS_PAGE_ENTITY_TYPE, FALSE);
      return $definition !== NULL;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

}
