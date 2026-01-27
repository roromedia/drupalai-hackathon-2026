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
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Exception\CommonMarkException;
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
    // If a template_id is provided in options, delegate to createFromTemplate.
    if (!empty($options['template_id'])) {
      $templateId = $options['template_id'];
      unset($options['template_id']);
      return $this->createFromTemplate($plan, $templateId, $options);
    }

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
    // Canvas uses "collapsed" format - just the raw value, not wrapped.
    switch ($section->componentType) {
      case 'hero':
        $inputs['title'] = $section->title;
        $inputs['text'] = $section->content;
        break;

      case 'heading':
        $inputs['text'] = $section->title;
        if (isset($section->componentConfig['level'])) {
          $inputs['level'] = $section->componentConfig['level'];
        }
        break;

      case 'list':
        $inputs['title'] = $section->title;
        // Parse content into list items if it contains line breaks.
        $items = $this->parseListItems($section->content);
        $inputs['items'] = $items;
        break;

      case 'cta':
        $inputs['title'] = $section->title;
        $inputs['text'] = $section->content;
        if (isset($section->componentConfig['button_text'])) {
          $inputs['button_text'] = $section->componentConfig['button_text'];
        }
        if (isset($section->componentConfig['button_url'])) {
          $inputs['button_url'] = $section->componentConfig['button_url'];
        }
        break;

      case 'quote':
        $inputs['quote'] = $section->content;
        if (isset($section->componentConfig['attribution'])) {
          $inputs['attribution'] = $section->componentConfig['attribution'];
        }
        break;

      case 'image':
        if (isset($section->componentConfig['media_id'])) {
          $inputs['image'] = $section->componentConfig['media_id'];
        }
        if (!empty($section->content)) {
          $inputs['alt'] = $section->content;
        }
        break;

      case 'text':
      default:
        // Default text component mapping.
        if (!empty($section->title)) {
          $inputs['title'] = $section->title;
        }
        $inputs['text'] = $section->content;
        break;
    }

    // Merge any additional component config as inputs.
    foreach ($section->componentConfig as $key => $value) {
      if (!isset($inputs[$key])) {
        $inputs[$key] = $value;
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

  /**
   * {@inheritdoc}
   */
  public function createFromTemplate(ContentPlan $plan, string|int $templateId, array $options = []): EntityInterface {
    try {
      // Verify Canvas module is available.
      if (!$this->isCanvasAvailable()) {
        throw new CanvasCreationException(
          'Canvas module is not available or not properly configured.',
          $plan->title
        );
      }

      // Load the template page.
      $storage = $this->entityTypeManager->getStorage(self::CANVAS_PAGE_ENTITY_TYPE);
      $templatePage = $storage->load($templateId);

      if ($templatePage === NULL) {
        throw new CanvasCreationException(
          sprintf('Template Canvas page with ID "%s" not found.', $templateId),
          $plan->title
        );
      }

      // Clone the template page.
      /** @var \Drupal\canvas\Entity\Page $newPage */
      $newPage = $templatePage->createDuplicate();

      // Update the new page with plan data and options.
      $newPage->set('title', $options['title'] ?? $plan->title);
      $newPage->set('status', $options['status'] ?? FALSE);

      // Set description/meta if provided.
      if (isset($options['description'])) {
        $newPage->set('description', $options['description']);
      }
      elseif (!empty($plan->summary)) {
        $newPage->set('description', $plan->summary);
      }

      // Set owner if provided.
      if (isset($options['owner'])) {
        $newPage->set('owner', $options['owner']);
      }

      // Set URL alias if provided, otherwise let Pathauto generate one.
      if (isset($options['alias']) && $newPage->hasField('path') && !empty($options['alias'])) {
        $newPage->set('path', ['alias' => $options['alias'], 'pathauto' => 0]);
      }
      elseif ($newPage->hasField('path')) {
        // Enable Pathauto to generate a new alias for the cloned page.
        $newPage->set('path', ['pathauto' => 1]);
      }

      // Get the component tree from the cloned page and fill with section content.
      $componentTree = $newPage->getComponentTree();
      if ($componentTree !== NULL) {
        $filledComponents = $this->fillComponentsWithSectionContent($componentTree, $plan);
        $this->setComponentTree($newPage, $filledComponents);
      }

      // Optimize component inputs to ensure collapsed format for validation.
      // This is necessary because templates may have been saved before
      // the collapsed format validation was enforced.
      $finalTree = $newPage->getComponentTree();
      foreach ($finalTree as $item) {
        $item->optimizeInputs();
      }

      // Validate the entity before saving.
      $violations = $newPage->validate();
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

      // Save the new page.
      $newPage->save();

      $this->logger->info('Created Canvas page "@title" (ID: @id) from template @template_id and content plan @plan_id.', [
        '@title' => $newPage->label(),
        '@id' => $newPage->id(),
        '@template_id' => $templateId,
        '@plan_id' => $plan->id,
      ]);

      // Dispatch the creation event.
      $event = new CanvasPageCreatedEvent($newPage, $plan);
      $this->eventDispatcher->dispatch($event, CanvasPageCreatedEvent::EVENT_NAME);

      return $newPage;
    }
    catch (CanvasCreationException $e) {
      // Re-throw CanvasCreationException as-is.
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create Canvas page from template @template_id and content plan @plan_id: @message', [
        '@template_id' => $templateId,
        '@plan_id' => $plan->id,
        '@message' => $e->getMessage(),
      ]);

      throw new CanvasCreationException(
        sprintf('Failed to create Canvas page from template: %s', $e->getMessage()),
        $plan->title,
        NULL,
        0,
        $e
      );
    }
  }

  /**
   * Fills component tree with content from plan sections.
   *
   * Iterates through the component tree and fills text-based input fields
   * with content from plan sections. Sections are matched to components
   * in order of their appearance.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $componentTree
   *   The component tree from the template.
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan with sections to fill.
   *
   * @return array<int, array<string, mixed>>
   *   The modified component tree data with filled content.
   */
  protected function fillComponentsWithSectionContent($componentTree, ContentPlan $plan): array {
    $components = $componentTree->getValue();
    $sections = $plan->sections;

    // Build a flat list of sections for sequential matching.
    $flatSections = [];
    foreach ($sections as $section) {
      foreach ($section->flatten() as $flatSection) {
        $flatSections[] = $flatSection;
      }
    }

    // Index for tracking which section to use next.
    $sectionIndex = 0;
    $totalSections = count($flatSections);

    // Process each component in the tree.
    foreach ($components as $key => &$component) {
      // Skip components without inputs.
      if (!isset($component['inputs'])) {
        continue;
      }

      // Decode JSON inputs if stored as string.
      $inputs = $component['inputs'];
      if (is_string($inputs)) {
        $inputs = json_decode($inputs, TRUE) ?? [];
      }
      if (!is_array($inputs)) {
        continue;
      }

      $componentId = $component['component_id'] ?? '';

      // Check if this component can receive text content.
      if (!$this->isTextBasedComponent($componentId, $inputs)) {
        continue;
      }

      // Get the next available section.
      if ($sectionIndex >= $totalSections) {
        // No more sections available; preserve existing content.
        continue;
      }

      $section = $flatSections[$sectionIndex];
      $sectionIndex++;

      // Fill the component inputs with section content.
      $filledInputs = $this->fillComponentInputsFromSection($inputs, $section, $componentId);

      // Re-encode as JSON string if it was originally a string.
      if (is_string($component['inputs'])) {
        $component['inputs'] = json_encode($filledInputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      }
      else {
        $component['inputs'] = $filledInputs;
      }

      // Update label if section has a title and component supports labels.
      if (!empty($section->title)) {
        $component['label'] = $section->title;
      }

      // Generate new UUID for the component to avoid duplicates.
      $oldUuid = $component['uuid'] ?? '';
      $newUuid = $this->uuid->generate();
      $component['uuid'] = $newUuid;

      // Update parent_uuid references in child components.
      $this->updateParentReferences($components, $oldUuid, $newUuid);
    }

    return $components;
  }

  /**
   * Checks if a component is text-based and can receive content.
   *
   * @param string $componentId
   *   The component ID.
   * @param array<string, mixed> $inputs
   *   The component inputs.
   *
   * @return bool
   *   TRUE if the component can receive text content.
   */
  protected function isTextBasedComponent(string $componentId, array $inputs): bool {
    // Check for common text input fields.
    $textInputFields = ['text', 'content', 'body', 'description', 'paragraph', 'quote', 'title', 'heading', 'heading_text', 'label', 'name'];

    foreach ($textInputFields as $field) {
      if (isset($inputs[$field])) {
        return TRUE;
      }
    }

    // Check component ID for text-related components.
    $textComponentPatterns = [
      'text', 'paragraph', 'heading', 'title', 'quote', 'blockquote',
      'rich_text', 'wysiwyg', 'content', 'body', 'hero', 'cta',
      'card', 'banner', 'callout', 'alert', 'message',
    ];

    $componentIdLower = strtolower($componentId);
    foreach ($textComponentPatterns as $pattern) {
      if (str_contains($componentIdLower, $pattern)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Fills component inputs from a plan section.
   *
   * @param array<string, mixed> $inputs
   *   The existing component inputs.
   * @param \Drupal\ai_content_preparation_wizard\Model\PlanSection $section
   *   The plan section with content.
   * @param string $componentId
   *   The component ID for context.
   *
   * @return array<string, mixed>
   *   The filled component inputs.
   */
  protected function fillComponentInputsFromSection(array $inputs, PlanSection $section, string $componentId): array {
    $filledInputs = $inputs;
    $contentFilled = FALSE;
    $titleFilled = FALSE;

    // Primary text content fields to fill with section content (long text).
    $contentFields = ['text', 'content', 'body', 'description', 'paragraph', 'quote'];

    // Title/heading fields to fill with section title (short text).
    $titleFields = ['title', 'heading', 'heading_text', 'name', 'label'];

    // Fill title fields first (they often need the title).
    foreach ($titleFields as $field) {
      if (isset($inputs[$field]) && !$titleFilled && !empty($section->title)) {
        $filledInputs[$field] = $this->fillFieldValue($inputs[$field], $section->title);
        $titleFilled = TRUE;
      }
    }

    // Fill content fields with body content (converted from markdown to HTML).
    foreach ($contentFields as $field) {
      if (isset($inputs[$field]) && !$contentFilled && !empty($section->content)) {
        $htmlContent = $this->convertMarkdownToHtml($section->content);
        $filledInputs[$field] = $this->fillFieldValue($inputs[$field], $htmlContent);
        $contentFilled = TRUE;
      }
    }

    // If no content field was found but we have a title field with content, use it.
    // This handles components that only have a heading_text or title field.
    if (!$contentFilled && !$titleFilled && !empty($section->content)) {
      // Only try known safe text fields - do NOT use fallback heuristics
      // as they can match config fields like 'align', 'flex_position', etc.
      foreach ($titleFields as $field) {
        if (isset($inputs[$field])) {
          $filledInputs[$field] = $this->fillFieldValue($inputs[$field], $section->title ?: $section->content);
          break;
        }
      }
    }

    return $filledInputs;
  }

  /**
   * Fills a field value, preserving existing structure if present.
   *
   * Some fields (like 'text') have complex structures like:
   * {"value": "content", "format": "canvas_html_block"}
   * This method preserves that structure and only updates the value.
   *
   * @param mixed $existingValue
   *   The existing field value.
   * @param string $newContent
   *   The new content to set.
   *
   * @return mixed
   *   The updated field value.
   */
  protected function fillFieldValue(mixed $existingValue, string $newContent): mixed {
    // If existing value is an array with 'value' key, preserve structure.
    if (is_array($existingValue) && array_key_exists('value', $existingValue)) {
      $existingValue['value'] = $newContent;
      return $existingValue;
    }

    // Otherwise, just return the new content directly (collapsed format).
    return $newContent;
  }

  /**
   * Returns a value in collapsed static format for Canvas component inputs.
   *
   * Canvas uses "collapsed" format for static values, which means just the
   * raw value without any wrapper. The expanded format ['static' => $value]
   * is only used internally and must be collapsed before saving.
   *
   * @param string $value
   *   The value to return.
   *
   * @return string
   *   The value in collapsed format (just the raw value).
   */
  protected function wrapStaticValue(string $value): string {
    // Canvas expects collapsed format - just the raw value, not wrapped.
    return $value;
  }

  /**
   * Checks if an input is likely a text input based on key name and value.
   *
   * @param string $key
   *   The input key name.
   * @param mixed $value
   *   The input value.
   *
   * @return bool
   *   TRUE if this is likely a text input.
   */
  protected function isLikelyTextInput(string $key, mixed $value): bool {
    // Skip inputs that are likely not text.
    $nonTextKeys = [
      'image', 'media', 'file', 'video', 'audio', 'icon',
      'link', 'url', 'href', 'src',
      'color', 'background',
      'enabled', 'disabled', 'visible', 'hidden',
      'count', 'number', 'amount', 'size', 'width', 'height',
      'id', 'class', 'style',
    ];

    $keyLower = strtolower($key);
    foreach ($nonTextKeys as $nonText) {
      if (str_contains($keyLower, $nonText)) {
        return FALSE;
      }
    }

    // Check if the existing value structure looks like text.
    // Canvas uses collapsed format - strings are stored directly.
    if (is_string($value)) {
      return TRUE;
    }

    // Also handle expanded format (legacy or dynamic sources).
    if (is_array($value) && isset($value['static']) && is_string($value['static'])) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Updates parent_uuid references after UUID regeneration.
   *
   * @param array<int, array<string, mixed>> &$components
   *   The component tree (passed by reference).
   * @param string $oldUuid
   *   The old UUID to replace.
   * @param string $newUuid
   *   The new UUID to use.
   */
  protected function updateParentReferences(array &$components, string $oldUuid, string $newUuid): void {
    foreach ($components as &$component) {
      if (isset($component['parent_uuid']) && $component['parent_uuid'] === $oldUuid) {
        $component['parent_uuid'] = $newUuid;
      }
    }
  }

  /**
   * Converts markdown content to HTML.
   *
   * @param string $markdown
   *   The markdown content to convert.
   *
   * @return string
   *   The HTML output.
   */
  protected function convertMarkdownToHtml(string $markdown): string {
    // Return empty string if no content.
    if (empty(trim($markdown))) {
      return '';
    }

    // Check if CommonMark converter is available.
    if (!class_exists(CommonMarkConverter::class)) {
      $this->logger->warning('CommonMark converter not available. Returning raw markdown.');
      return nl2br(htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8'));
    }

    try {
      $converter = new CommonMarkConverter([
        'html_input' => 'strip',
        'allow_unsafe_links' => FALSE,
        'max_nesting_level' => 10,
      ]);

      $html = (string) $converter->convert($markdown);
      $html = trim($html);

      // Strip outer <p> tags from simple single-paragraph content.
      // This prevents fields that don't render HTML from showing raw tags.
      if (preg_match('/^<p>(.+)<\/p>$/s', $html, $matches)) {
        // Only strip if there's no other HTML inside (simple paragraph).
        $inner = $matches[1];
        if (!preg_match('/<[a-z][\s\S]*>/i', $inner)) {
          return $inner;
        }
      }

      return $html;
    }
    catch (CommonMarkException $e) {
      $this->logger->error('Markdown conversion failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      // Fall back to simple HTML escaping with newlines preserved.
      return nl2br(htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8'));
    }
  }

}
