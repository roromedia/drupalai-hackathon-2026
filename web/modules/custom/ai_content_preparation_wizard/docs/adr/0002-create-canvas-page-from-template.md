# ADR-002: Create Canvas Page from Template Architecture

Date: 2026-01-28

Issue: Create Canvas Page Feature - Step 3 Implementation

## Status

Proposed

## Context

The AI Content Preparation Wizard Step 3 needs a "Create Canvas Page" feature that:

1. Duplicates a selected canvas_page entity (the template chosen in Step 1)
2. Extracts text content from Step 2 sections (content plan with headings and body text)
3. Maps and fills section content into appropriate components of the duplicated page

### Current State Analysis

**Existing Components:**

1. **WizardSession** (`/src/Model/WizardSession.php`):
   - Stores `templateId` (the selected canvas_page ID from Step 1)
   - Stores `ContentPlan` with `PlanSection[]` containing title, content, componentType

2. **ContentPlan** (`/src/Model/ContentPlan.php`):
   - Contains `sections: PlanSection[]` with hierarchical content
   - Each `PlanSection` has: id, title, content, componentType, order, componentConfig, children

3. **CanvasCreator** (`/src/Service/CanvasCreator.php`):
   - Current implementation creates NEW pages from scratch using `mapToComponents()`
   - Generates component tree from `ContentPlan.sections`
   - Does NOT support template-based duplication

4. **Canvas Page Entity** (`canvas/src/Entity/Page.php`):
   - Uses `ComponentTreeItemList` for components field
   - `setComponentTree(array $values)` method available
   - Each `ComponentTreeItem` has: uuid, component_id, component_version, inputs, parent_uuid, slot, label

5. **ComponentTreeItem** (`canvas/src/Plugin/Field/FieldType/ComponentTreeItem.php`):
   - `inputs` field stores component prop values as JSON
   - Props use "static" source type: `{"propName": {"static": "value"}}`

### Key Requirements

1. **Template Duplication**: Clone the selected canvas_page preserving component structure
2. **Content Mapping**: Fill template components with content plan section text
3. **Mapping Strategy**: Match sections to components intelligently
4. **Edge Case Handling**: Handle mismatches between sections and components
5. **AJAX vs Submit**: Determine interaction pattern
6. **Error Recovery**: Handle partial failures gracefully

## Decision Drivers

1. **Preserve Template Structure**: The duplicated page should maintain the component hierarchy of the template
2. **Content Fidelity**: Section content must be accurately mapped to component inputs
3. **User Experience**: Provide clear feedback on mapping success/failures
4. **Extensibility**: Support future component types and mapping rules
5. **Drupal Patterns**: Follow established Drupal entity and form patterns

## Considered Options

### Option 1: Entity Clone via createDuplicate()

Drupal's `ContentEntityBase::createDuplicate()` method creates a deep clone of an entity.

```php
$template = $storage->load($templateId);
$newPage = $template->createDuplicate();
$newPage->set('title', $plan->title);
// Modify component inputs...
$newPage->save();
```

**Pros:**
- Built-in Drupal method, well-tested
- Automatically handles entity references and field values
- Preserves component tree structure including parent_uuid relationships
- Handles revisions correctly

**Cons:**
- Generates new UUIDs for components (may affect parent_uuid references)
- Need to manually update inputs after duplication
- Less control over what gets cloned

### Option 2: Manual Component Tree Reconstruction

Build the component tree manually by reading the template and reconstructing with new values.

```php
$template = $storage->load($templateId);
$componentTree = $template->getComponentTree()->getValue();
$mappedTree = $this->remapComponentTree($componentTree, $plan->sections);
$newPage = $storage->create([...]);
$newPage->setComponentTree($mappedTree);
```

**Pros:**
- Full control over component structure
- Can selectively include/exclude components
- Easier to inject new content

**Cons:**
- Must manually preserve parent-child relationships
- Risk of breaking component tree integrity
- More complex implementation

### Option 3: Hybrid Approach (Recommended)

Use `createDuplicate()` for entity cloning, then iterate through the component tree to update inputs.

```php
$template = $storage->load($templateId);
$newPage = $template->createDuplicate();
$newPage->set('title', $plan->title);
$newPage->set('status', $options['status']);

// Get component tree and update inputs
$componentTree = $newPage->getComponentTree();
$this->fillComponentInputs($componentTree, $plan->sections);

$newPage->save();
```

**Pros:**
- Leverages Drupal's entity duplication for safety
- Maintains component tree integrity
- Clear separation: duplication vs content filling
- Easier to test each concern separately

**Cons:**
- Two-step process (duplicate then modify)
- Slight overhead of loading twice

## Decision

**Adopt Option 3: Hybrid Approach** - Use `createDuplicate()` for cloning the template entity, then update component inputs using the content plan sections.

### Architecture Design

#### 1. Service Layer: CanvasCreatorInterface Extension

Add a new method to `CanvasCreatorInterface`:

```php
interface CanvasCreatorInterface {
  // Existing method
  public function create(ContentPlan $plan, array $options = []): EntityInterface;

  // New method for template-based creation
  public function createFromTemplate(
    ContentPlan $plan,
    string $templateId,
    array $options = []
  ): EntityInterface;

  public function mapToComponents(ContentPlan $plan): array;
}
```

#### 2. Component Mapping Strategy

**Mapping Algorithm (Order-Based with Type Validation):**

```
1. Get all "fillable" components from template (components with text-type inputs)
2. Get all sections from content plan (flattened, in order)
3. Match sections to components by position (0 to 0, 1 to 1, etc.)
4. Validate component type compatibility (section.componentType matches component_id)
5. Fill component inputs with section content
```

**Fillable Component Detection:**

Components are fillable if they have inputs of these types:
- `text` / `rich_text` / `string` - plain text content
- `title` / `heading` - heading text
- `body` / `content` - body text

**Input Mapping Rules:**

| Section Field | Component Input Candidates |
|---------------|---------------------------|
| `title` | `title`, `heading`, `text`, `label` |
| `content` | `text`, `body`, `content`, `rich_text`, `description` |

#### 3. Edge Case Handling

**More Sections than Components:**
- Fill all available components
- Log warning about unmapped sections
- Store unmapped sections in page description or skip

**More Components than Sections:**
- Fill components up to section count
- Leave remaining components with template defaults
- Do NOT remove unfilled components (preserve template structure)

**Component Type Mismatch:**
- If section.componentType differs from component_id, log warning but proceed
- Use flexible input mapping to find best match

#### 4. Form Integration

**Step 3 Form Changes:**

```php
// In ContentPreparationWizardForm::buildStep3()
$form['step3']['page_settings']['create_mode'] = [
  '#type' => 'radios',
  '#title' => $this->t('Creation Mode'),
  '#options' => [
    'template' => $this->t('Use selected template (recommended)'),
    'scratch' => $this->t('Create from scratch'),
  ],
  '#default_value' => $session->getTemplateId() ? 'template' : 'scratch',
  '#access' => (bool) $session->getTemplateId(),
];
```

**AJAX vs Full Submit:**

Use **AJAX with progress indicator** for better UX:
- Show spinner during page creation
- Display success message with link to new page
- On error, show error message without page redirect

```php
$form['actions']['submit'] = [
  '#type' => 'submit',
  '#value' => $this->t('Create Canvas Page'),
  '#button_type' => 'primary',
  '#ajax' => [
    'callback' => '::ajaxCreatePage',
    'wrapper' => 'wizard-form-wrapper',
    'progress' => [
      'type' => 'throbber',
      'message' => $this->t('Creating page...'),
    ],
  ],
];
```

#### 5. Service Implementation

```php
// CanvasCreator::createFromTemplate()
public function createFromTemplate(
  ContentPlan $plan,
  string $templateId,
  array $options = []
): EntityInterface {
  // 1. Load template
  $template = $this->entityTypeManager
    ->getStorage(self::CANVAS_PAGE_ENTITY_TYPE)
    ->load($templateId);

  if (!$template) {
    throw new CanvasCreationException("Template not found: $templateId");
  }

  // 2. Duplicate template
  $page = $template->createDuplicate();

  // 3. Update page metadata
  $page->set('title', $options['title'] ?? $plan->title);
  $page->set('status', $options['status'] ?? FALSE);
  if (isset($options['description'])) {
    $page->set('description', $options['description']);
  }

  // 4. Get component tree and fill with content
  $componentTree = $page->getComponentTree();
  $this->fillComponentTreeFromPlan($componentTree, $plan);

  // 5. Validate and save
  $violations = $page->validate();
  if ($violations->count() > 0) {
    throw new CanvasCreationException(
      'Validation failed',
      $plan->title,
      $this->formatViolations($violations)
    );
  }

  $page->save();

  // 6. Dispatch event
  $event = new CanvasPageCreatedEvent($page, $plan);
  $this->eventDispatcher->dispatch($event);

  return $page;
}
```

#### 6. Component Input Filling

```php
protected function fillComponentTreeFromPlan(
  ComponentTreeItemList $componentTree,
  ContentPlan $plan
): void {
  // Flatten sections for sequential mapping
  $sections = $this->flattenSections($plan->sections);

  // Get fillable components (root level first, then by order)
  $fillableComponents = $this->getFillableComponents($componentTree);

  // Map sections to components
  $sectionIndex = 0;
  foreach ($fillableComponents as $component) {
    if ($sectionIndex >= count($sections)) {
      break; // No more sections to fill
    }

    $section = $sections[$sectionIndex];
    $this->fillComponentFromSection($component, $section);
    $sectionIndex++;
  }

  // Log any unmapped sections
  if ($sectionIndex < count($sections)) {
    $this->logger->notice('Unmapped sections: @count', [
      '@count' => count($sections) - $sectionIndex,
    ]);
  }
}

protected function fillComponentFromSection(
  ComponentTreeItem $component,
  PlanSection $section
): void {
  $inputs = $component->getInputs() ?? [];

  // Map section title to appropriate input
  if (!empty($section->title)) {
    $titleInputs = ['title', 'heading', 'label', 'name'];
    foreach ($titleInputs as $inputName) {
      if (isset($inputs[$inputName])) {
        $inputs[$inputName] = ['static' => $section->title];
        break;
      }
    }
  }

  // Map section content to appropriate input
  if (!empty($section->content)) {
    $contentInputs = ['text', 'body', 'content', 'rich_text', 'description'];
    foreach ($contentInputs as $inputName) {
      if (isset($inputs[$inputName])) {
        $inputs[$inputName] = ['static' => $section->content];
        break;
      }
    }
  }

  $component->setInput($inputs);
}
```

### Data Flow Diagram

```
Step 1 (Upload)          Step 2 (Plan)           Step 3 (Create)
     |                        |                        |
     v                        v                        v
[Select Template] --> [Generate Plan] --> [Click "Create Canvas Page"]
     |                        |                        |
     v                        v                        v
templateId stored      ContentPlan with         createFromTemplate()
in WizardSession       PlanSection[] stored          |
                                                     v
                                              1. Load template
                                              2. createDuplicate()
                                              3. Fill component inputs
                                              4. Validate & save
                                                     |
                                                     v
                                              [Redirect to new page]
```

### Error Handling Strategy

| Error Type | Handling |
|------------|----------|
| Template not found | CanvasCreationException, show user error, stay on Step 3 |
| Duplication fails | CanvasCreationException, log error, show generic message |
| Validation fails | Show validation errors, allow user to adjust settings |
| Component filling fails | Log warning, continue with unfilled components |
| Save fails | Roll back, show error, preserve form state |

### Testing Strategy

1. **Unit Tests:**
   - `CanvasCreator::createFromTemplate()` with mock template
   - `fillComponentTreeFromPlan()` mapping logic
   - Edge cases: empty sections, empty components, mismatches

2. **Kernel Tests:**
   - Full entity duplication and save
   - Component tree integrity after filling

3. **Functional Tests:**
   - Complete wizard flow with template selection
   - AJAX form submission
   - Error handling display

## Consequences

### Positive

1. **Template Preservation**: Using `createDuplicate()` ensures component tree integrity
2. **Content Accuracy**: Order-based mapping provides predictable content placement
3. **User Control**: Users can see template preview and understand what will be created
4. **Extensibility**: Mapping logic can be enhanced without changing core flow
5. **Error Recovery**: Partial failures don't corrupt the wizard state

### Negative

1. **Mapping Limitations**: Order-based mapping may not always produce optimal results
2. **Component Dependency**: Requires template to have compatible components
3. **Complexity**: More complex than simple page creation from scratch

### Mitigations

| Risk | Mitigation |
|------|------------|
| Poor mapping results | Add future AI-assisted mapping option |
| Template incompatibility | Validate template compatibility before Step 3 |
| Lost content | Store unmapped sections for manual addition |
| Performance with large templates | Add async processing option for complex templates |

## Implementation Checklist

### Phase 1: Core Implementation

- [ ] Add `createFromTemplate()` method to `CanvasCreatorInterface`
- [ ] Implement `createFromTemplate()` in `CanvasCreator`
- [ ] Implement `fillComponentTreeFromPlan()` helper
- [ ] Implement `getFillableComponents()` helper
- [ ] Add `ComponentMapping` model class for mapping results

### Phase 2: Form Integration

- [ ] Update `ContentPreparationWizardForm::buildStep3()` with create mode selection
- [ ] Update `ContentPreparationWizardForm::submitForm()` to use appropriate method
- [ ] Add AJAX callback for page creation
- [ ] Add success/error messaging

### Phase 3: Testing

- [ ] Unit tests for CanvasCreator methods
- [ ] Kernel tests for entity operations
- [ ] Functional tests for wizard flow

### Phase 4: Polish

- [ ] Add mapping preview in Step 3 UI
- [ ] Add unmapped content warning
- [ ] Documentation updates

## References

- ADR-001: Content Preparation Wizard Architecture
- Canvas Entity Documentation: `/web/modules/contrib/canvas/docs/data-model.md`
- Drupal Entity API: https://www.drupal.org/docs/drupal-apis/entity-api
- ComponentTreeItem Field Type: `/web/modules/contrib/canvas/src/Plugin/Field/FieldType/ComponentTreeItem.php`
