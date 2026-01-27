# ADR-001: Content Preparation Wizard Module Architecture

Date: 2026-01-27

Issue: (To be created on drupal.org)

## Status

Proposed

## Context

The Drupal ecosystem needs a streamlined way to prepare content from various document formats for publishing as Canvas pages. Content editors frequently work with external documents (Word documents, PDFs, plain text files) that need to be transformed into structured web content. Currently, this process involves manual copy-paste operations, formatting cleanup, and content restructuring - a time-consuming and error-prone workflow.

We need a Drupal module that provides a guided, multi-step wizard experience for:

1. **Document Ingestion**: Accept multiple file uploads via drag-and-drop interface supporting TXT, DOCX, and PDF formats initially, with an extensible architecture for additional formats.

2. **AI-Assisted Planning**: Generate intelligent content plans using AI, leveraging contextual information from the Drupal site and configurable AI templates.

3. **Content Creation**: Transform planned content into Canvas pages with preview capabilities and refinement options.

### Key Requirements

- **Pluggable Processor Architecture**: Document processing should be extensible. Pandoc serves as the default processor, but the system must support alternative processors (e.g., specialized PDF extractors, OCR engines, proprietary converters).

- **Context-Aware AI Integration**: The wizard must integrate with Drupal's AI module ecosystem, allowing selection of contextual information (taxonomy terms, existing content patterns, site structure) to inform the AI content plan.

- **Canvas Integration**: Final output targets the Canvas module's Page entity type, leveraging its component-tree architecture for flexible content composition.

- **Future Extensibility**: Architecture must accommodate a planned web scraping submodule for URL-based content ingestion.

### Decision Drivers

1. **Extensibility**: Plugin system for document processors, AI templates, and context providers
2. **Drupal Standards**: Follow Drupal coding standards, plugin patterns, and form API conventions
3. **User Experience**: Intuitive wizard flow with clear step progression and feedback
4. **Maintainability**: Clean separation of concerns between processing, planning, and content creation
5. **Testability**: Each component independently testable with mock implementations
6. **Performance**: Efficient handling of large documents and batch uploads
7. **Future-proofing**: Support for web scraping submodule and additional output targets

## Considered Options

### Option 1: Single Monolithic Form

A single form class handling all steps with conditional rendering based on form state.

**Pros:**
- Simple implementation
- Single file to maintain
- Easy to understand initial flow

**Cons:**
- Difficult to extend individual steps
- Tight coupling between concerns
- Poor testability
- Complex conditional logic
- Does not scale for additional steps or variants

### Option 2: Multi-Step Wizard Form with FormState

Drupal's built-in multi-step form pattern using FormBase with step tracking in FormState, combined with PrivateTempStore for session persistence.

**Pros:**
- Standard Drupal pattern, familiar to developers
- Built-in step validation and navigation
- Supports AJAX operations per step
- Clear separation between steps
- Compatible with Drupal's form cache

**Cons:**
- More complex than single form
- Requires careful state management
- FormState serialization constraints for complex objects

### Option 3: Separate Controller Routes per Step

Each wizard step implemented as a separate route with its own controller or form class.

**Pros:**
- Maximum separation of concerns
- Each step fully independent
- Easy to add/remove steps
- Clear URL structure for bookmarking/sharing

**Cons:**
- More infrastructure code
- Complex inter-step communication
- Potential for data loss on navigation
- Harder to implement smooth UX transitions
- URL manipulation could skip steps without validation

## Decision

**Adopt Option 2: Multi-Step Wizard Form with FormState**, implementing the wizard using Drupal's multi-step form pattern with the following architectural components:

### Core Architecture

```
ai_ai_content_preparation_wizard/
  ai_ai_content_preparation_wizard.info.yml
  ai_ai_content_preparation_wizard.module
  ai_ai_content_preparation_wizard.routing.yml
  ai_ai_content_preparation_wizard.services.yml
  ai_ai_content_preparation_wizard.permissions.yml
  ai_ai_content_preparation_wizard.links.menu.yml
  config/
    install/
      ai_ai_content_preparation_wizard.settings.yml
    schema/
      ai_ai_content_preparation_wizard.schema.yml
  src/
    Form/
      ContentPreparationWizardForm.php      # Main wizard form
      ContentPreparationSettingsForm.php    # Admin settings form
    Plugin/
      DocumentProcessor/
        DocumentProcessorInterface.php
        DocumentProcessorBase.php
        PandocProcessor.php                  # Default implementation
    Service/
      DocumentProcessorManager.php           # Plugin manager
      ContentPlanGenerator.php               # AI integration service
      CanvasPageCreator.php                  # Canvas integration
    Annotation/
      DocumentProcessor.php                  # Plugin annotation
  docs/
    adr/
      0001-content-preparation-wizard-architecture.md
```

### Form Architecture

The wizard form extends `FormBase` and implements step-based progression:

```php
class ContentPreparationWizardForm extends FormBase {

  /**
   * Steps enum for the wizard.
   */
  const STEP_UPLOAD = 1;
  const STEP_PLAN = 2;
  const STEP_CREATE = 3;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $step = $form_state->get('step') ?? self::STEP_UPLOAD;

    // Build step-specific form elements
    return match($step) {
      self::STEP_UPLOAD => $this->buildUploadStep($form, $form_state),
      self::STEP_PLAN => $this->buildPlanStep($form, $form_state),
      self::STEP_CREATE => $this->buildCreateStep($form, $form_state),
    };
  }
}
```

### Session Persistence

Use `PrivateTempStore` for cross-request wizard state:

```php
// Store wizard data
$this->tempStoreFactory->get('ai_ai_content_preparation_wizard')
  ->set('wizard_data', [
    'files' => $uploaded_files,
    'contexts' => $selected_contexts,
    'template' => $ai_template,
    'plan' => $generated_plan,
  ]);
```

### Document Processor Plugin System

Implement a custom plugin type for document processors:

```php
/**
 * Defines a DocumentProcessor plugin annotation.
 *
 * @Annotation
 */
class DocumentProcessor extends Plugin {
  public string $id;
  public TranslatableMarkup $label;
  public string $description;
  public array $supported_extensions;
  public int $weight;
}
```

Plugin interface:

```php
interface DocumentProcessorInterface extends PluginInspectionInterface {

  /**
   * Process a file and extract content.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to process.
   *
   * @return \Drupal\ai_ai_content_preparation_wizard\ProcessedDocument
   *   The processed document with extracted content.
   *
   * @throws \Drupal\ai_ai_content_preparation_wizard\Exception\ProcessingException
   */
  public function process(FileInterface $file): ProcessedDocument;

  /**
   * Check if this processor can handle the given file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to check.
   *
   * @return bool
   *   TRUE if this processor can handle the file.
   */
  public function applies(FileInterface $file): bool;

  /**
   * Get supported file extensions.
   *
   * @return array
   *   Array of supported extensions (without dots).
   */
  public function getSupportedExtensions(): array;
}
```

### Configuration Entity for Settings

```yaml
# config/schema/ai_ai_content_preparation_wizard.schema.yml
ai_ai_content_preparation_wizard.settings:
  type: config_object
  label: 'Content Preparation Wizard settings'
  mapping:
    default_processor:
      type: string
      label: 'Default document processor'
    processor_mapping:
      type: sequence
      label: 'File type to processor mapping'
      sequence:
        type: mapping
        mapping:
          extension:
            type: string
            label: 'File extension'
          processor:
            type: string
            label: 'Processor plugin ID'
    available_contexts:
      type: sequence
      label: 'Available AI context modules'
      sequence:
        type: string
    ai_templates:
      type: sequence
      label: 'AI template configurations'
      sequence:
        type: mapping
        mapping:
          id:
            type: string
          label:
            type: label
          prompt_template:
            type: text
```

## Technical Details

### Step 1: Document Upload

**Form Elements:**

```php
protected function buildUploadStep(array $form, FormStateInterface $form_state): array {
  $form['files'] = [
    '#type' => 'managed_file',
    '#title' => $this->t('Upload documents'),
    '#description' => $this->t('Drag and drop files or click to browse. Supported formats: TXT, DOCX, PDF.'),
    '#upload_location' => 'temporary://content_preparation/',
    '#upload_validators' => [
      'FileExtension' => ['extensions' => 'txt docx pdf'],
      'FileSizeLimit' => ['fileLimit' => '50MB'],
    ],
    '#multiple' => TRUE,
    '#required' => TRUE,
  ];

  $form['contexts'] = [
    '#type' => 'checkboxes',
    '#title' => $this->t('AI Context'),
    '#description' => $this->t('Select contextual information to include in AI processing.'),
    '#options' => $this->getAvailableContextOptions(),
  ];

  $form['template'] = [
    '#type' => 'select',
    '#title' => $this->t('AI Template'),
    '#description' => $this->t('Select the content plan template.'),
    '#options' => $this->getAiTemplateOptions(),
    '#required' => TRUE,
  ];

  $form['actions']['next'] = [
    '#type' => 'submit',
    '#value' => $this->t('Generate Plan'),
    '#submit' => ['::submitUploadStep'],
  ];

  return $form;
}
```

**Processing:**
- Files uploaded to temporary storage
- Metadata stored in PrivateTempStore
- File processing deferred to Step 2 entry

### Step 2: Content Plan Generation

**On Step Entry:**
- Process uploaded documents using configured processors
- Aggregate extracted content
- Generate AI prompt with selected contexts
- Call AI service for content plan generation

**Form Elements:**

```php
protected function buildPlanStep(array $form, FormStateInterface $form_state): array {
  $wizard_data = $this->tempStore->get('wizard_data');

  $form['plan_display'] = [
    '#type' => 'container',
    '#attributes' => ['class' => ['content-plan-display']],
  ];

  $form['plan_display']['plan'] = [
    '#type' => 'markup',
    '#markup' => $this->formatPlanForDisplay($wizard_data['plan']),
  ];

  $form['refinement'] = [
    '#type' => 'textarea',
    '#title' => $this->t('Refinement Instructions'),
    '#description' => $this->t('Provide additional instructions to refine the content plan.'),
    '#rows' => 4,
  ];

  $form['actions']['regenerate'] = [
    '#type' => 'submit',
    '#value' => $this->t('Regenerate Plan'),
    '#submit' => ['::submitRegeneratePlan'],
    '#ajax' => [
      'callback' => '::ajaxRegeneratePlan',
      'wrapper' => 'content-plan-display',
    ],
  ];

  $form['actions']['back'] = [
    '#type' => 'submit',
    '#value' => $this->t('Back'),
    '#submit' => ['::submitBackToUpload'],
    '#limit_validation_errors' => [],
  ];

  $form['actions']['next'] = [
    '#type' => 'submit',
    '#value' => $this->t('Preview & Create'),
    '#submit' => ['::submitPlanStep'],
  ];

  return $form;
}
```

**AI Integration:**

```php
// ContentPlanGenerator service
public function generatePlan(
  array $processed_documents,
  array $contexts,
  string $template_id,
  ?string $refinement = NULL
): ContentPlan {
  $template = $this->configFactory->get('ai_ai_content_preparation_wizard.settings')
    ->get('ai_templates')[$template_id];

  $prompt = $this->buildPrompt($template, $processed_documents, $contexts, $refinement);

  // Use Drupal AI module's chat operation
  $ai_provider = $this->aiProviderManager->getDefaultProvider('chat');
  $response = $ai_provider->chat(new ChatInput([
    new ChatMessage('user', $prompt),
  ]));

  return ContentPlan::fromAiResponse($response->getNormalized());
}
```

### Step 3: Preview and Create

**Form Elements:**

```php
protected function buildCreateStep(array $form, FormStateInterface $form_state): array {
  $wizard_data = $this->tempStore->get('wizard_data');
  $plan = $wizard_data['plan'];

  $form['preview'] = [
    '#type' => 'container',
    '#attributes' => ['class' => ['canvas-page-preview']],
  ];

  $form['preview']['content'] = $this->canvasPageCreator
    ->buildPreview($plan);

  $form['page_title'] = [
    '#type' => 'textfield',
    '#title' => $this->t('Page Title'),
    '#default_value' => $plan->getSuggestedTitle(),
    '#required' => TRUE,
  ];

  $form['page_path'] = [
    '#type' => 'textfield',
    '#title' => $this->t('URL Path'),
    '#default_value' => $plan->getSuggestedPath(),
    '#field_prefix' => $this->getRequest()->getSchemeAndHttpHost() . '/',
  ];

  $form['actions']['back'] = [
    '#type' => 'submit',
    '#value' => $this->t('Back to Plan'),
    '#submit' => ['::submitBackToPlan'],
    '#limit_validation_errors' => [],
  ];

  $form['actions']['create'] = [
    '#type' => 'submit',
    '#value' => $this->t('Create Canvas Page'),
    '#submit' => ['::submitCreatePage'],
  ];

  return $form;
}
```

**Canvas Integration:**

```php
// CanvasPageCreator service
public function createPage(ContentPlan $plan, string $title, string $path): Page {
  $page = Page::create([
    'title' => $title,
    'path' => ['alias' => '/' . ltrim($path, '/')],
    'description' => $plan->getDescription(),
  ]);

  // Build component tree from plan
  $component_tree = $this->buildComponentTree($plan);
  $page->set('component_tree', $component_tree);

  $page->save();

  return $page;
}
```

### Settings Page

Administrative interface at `/admin/config/content/content-preparation-wizard`:

```php
class ContentPreparationSettingsForm extends ConfigFormBase {

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_ai_content_preparation_wizard.settings');

    $form['processor_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Document Processor Settings'),
      '#open' => TRUE,
    ];

    // Per-filetype processor selection
    $form['processor_settings']['processor_mapping'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('File Extension'),
        $this->t('Processor'),
      ],
    ];

    foreach (['txt', 'docx', 'pdf'] as $ext) {
      $form['processor_settings']['processor_mapping'][$ext]['extension'] = [
        '#plain_text' => strtoupper($ext),
      ];
      $form['processor_settings']['processor_mapping'][$ext]['processor'] = [
        '#type' => 'select',
        '#options' => $this->getProcessorOptions($ext),
        '#default_value' => $config->get("processor_mapping.$ext") ?? 'pandoc',
      ];
    }

    $form['context_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Context Settings'),
      '#open' => TRUE,
    ];

    $form['context_settings']['available_contexts'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Available Context Modules'),
      '#options' => $this->getContextModuleOptions(),
      '#default_value' => $config->get('available_contexts') ?? [],
      '#description' => $this->t('Select which context sources are available in the wizard.'),
    ];

    return parent::buildForm($form, $form_state);
  }
}
```

## Consequences

### Positive

1. **Clean Wizard UX**: Users experience a clear, guided workflow with explicit step progression and the ability to navigate back to previous steps.

2. **Pluggable Processors**: New document formats can be supported by implementing a plugin without modifying core wizard code. Third-party modules can provide specialized processors.

3. **Standard Drupal Patterns**: The implementation uses familiar Drupal patterns (FormBase, Plugin API, Services, Configuration), making it accessible to Drupal developers.

4. **Testability**: Each component (processors, AI integration, Canvas creation) can be unit tested independently with mock dependencies.

5. **AI Flexibility**: The AI template system allows site builders to customize content plan generation without code changes.

6. **Canvas Integration**: Direct integration with Canvas's component tree architecture enables rich content output.

7. **Session Resilience**: PrivateTempStore ensures wizard state survives page refreshes and allows users to resume interrupted workflows.

### Negative

1. **Complexity**: The multi-step wizard with plugin architecture is more complex than a simple form, requiring understanding of multiple Drupal subsystems.

2. **State Management**: Careful attention required to ensure wizard state remains consistent across steps, especially with AJAX operations.

3. **External Dependency**: The default Pandoc processor requires Pandoc installation on the server, which may not be available in all hosting environments.

4. **AI Dependency**: Step 2 requires a configured AI provider, creating a hard dependency on the AI module ecosystem.

5. **Testing Infrastructure**: Full integration testing requires mock AI responses and Canvas page creation, increasing test complexity.

### Mitigations

| Risk | Mitigation |
|------|------------|
| Pandoc unavailable | Provide pure PHP fallback processors; clear error messaging when Pandoc missing |
| AI provider not configured | Graceful degradation with manual plan entry option |
| Large file uploads | Batch processing with progress indication; configurable size limits |
| Lost wizard state | Auto-save to tempstore; session timeout warnings |
| Complex form state | Comprehensive form state validation; step transition guards |

## Future Considerations

### Web Scraping Submodule

The architecture supports a `ai_ai_content_preparation_wizard_scraper` submodule that:

- Adds a "URL" input option alongside file upload in Step 1
- Implements `UrlProcessor` plugins analogous to `DocumentProcessor`
- Shares the same Step 2 and Step 3 workflow

### Additional Output Targets

While Canvas pages are the initial target, the `ContentPlanConsumer` interface could support:

- Node creation with specific content types
- Paragraph-based content structures
- Layout Builder layouts

### Batch Processing

For enterprise use cases, a batch processing queue could:

- Accept large document sets
- Process asynchronously via Queue API
- Provide status dashboard and notifications

## References

- [Drupal Form API](https://www.drupal.org/docs/drupal-apis/form-api)
- [Drupal Plugin API](https://www.drupal.org/docs/drupal-apis/plugin-api)
- [Canvas Module Documentation](/web/modules/contrib/canvas/docs/intro.md)
- [AI Module Documentation](/web/modules/contrib/ai/docs/documentation/index.md)
- [Pandoc Documentation](https://pandoc.org/MANUAL.html)
- [ADR Template](./templates/template.md)
