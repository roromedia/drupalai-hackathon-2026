# Content Preparation Wizard - DDD Architecture Document

## Executive Summary

This document defines the Domain-Driven Design (DDD) architecture for the `content_preparation_wizard` Drupal module. The module implements a 3-step wizard that processes uploaded documents into AI-ready content for Canvas page creation, following Drupal coding standards and integrating with the existing AI ecosystem (ai, ai_agents, canvas, canvas_ai modules).

---

## 1. Strategic Design

### 1.1 Domain Overview

The Content Preparation Wizard domain addresses the problem of transforming raw documents (TXT, DOCX, PDF) into structured content plans that can be rendered as Canvas pages. This involves three distinct phases:

1. **Document Ingestion** - Upload and convert documents to markdown
2. **AI Planning** - Generate and refine content plans using AI
3. **Canvas Creation** - Create the final Canvas page from the approved plan

### 1.2 Bounded Contexts

```
+-------------------------------------------------------------------+
|                    CONTENT PREPARATION WIZARD                      |
+-------------------------------------------------------------------+
|                                                                   |
|  +---------------------+  +---------------------+  +-------------+|
|  | Document Processing |  |    AI Planning      |  |   Canvas    ||
|  |      Context        |  |      Context        |  |  Creation   ||
|  |                     |  |                     |  |   Context   ||
|  | - File upload       |  | - Plan generation   |  | - Page      ||
|  | - Format detection  |  | - Plan refinement   |  |   creation  ||
|  | - MD conversion     |  | - Context selection |  | - Component ||
|  | - Metadata extract  |  | - Section mgmt      |  |   mapping   ||
|  +---------------------+  +---------------------+  +-------------+|
|            |                        |                    |        |
|            v                        v                    v        |
|  +---------------------+  +---------------------+  +-------------+|
|  |   ProcessedDocument |  |    ContentPlan      |  |   Canvas    ||
|  |     Aggregate       |  |     Aggregate       |  |    Page     ||
|  +---------------------+  +---------------------+  +-------------+|
|                                                                   |
+-------------------------------------------------------------------+
```

#### 1.2.1 Document Processing Context

**Responsibility**: Handles file uploads, format detection, and conversion to markdown format.

**Key Concepts**:
- ProcessedDocument (Entity)
- FileType (Value Object)
- ProcessingProvider (Value Object)
- DocumentProcessorPlugin (Plugin Type)

**Integration Points**:
- Drupal File API
- Pandoc (external service)
- Media Library

#### 1.2.2 AI Planning Context

**Responsibility**: Generates and refines content plans based on processed documents and selected AI contexts.

**Key Concepts**:
- ContentPlan (Entity)
- PlanSection (Value Object)
- AIContext (Value Object)
- CanvasTemplate (Entity)

**Integration Points**:
- AI Module (ai)
- AI Agents Module (ai_agents)
- Prompt Management

#### 1.2.3 Canvas Creation Context

**Responsibility**: Creates the final Canvas page from the approved content plan.

**Key Concepts**:
- CanvasPage (External Entity)
- ComponentMapping (Value Object)
- PageStructure (Value Object)

**Integration Points**:
- Canvas Module
- Canvas AI Module

---

## 2. Tactical Design

### 2.1 Domain Model

#### 2.1.1 Entities

```php
/**
 * WizardSession Entity
 *
 * Root aggregate that tracks the entire wizard workflow state.
 * Stored in user's private tempstore for session persistence.
 */
WizardSession {
  - id: string (UUID)
  - userId: int
  - currentStep: WizardStep
  - processedDocuments: ProcessedDocument[]
  - selectedContexts: AIContext[]
  - contentPlan: ?ContentPlan
  - selectedTemplate: ?CanvasTemplate
  - createdAt: DateTimeImmutable
  - updatedAt: DateTimeImmutable
  - status: WizardStatus
}

/**
 * ProcessedDocument Entity
 *
 * Represents a document that has been uploaded and converted.
 */
ProcessedDocument {
  - id: string (UUID)
  - originalFile: File (Drupal entity reference)
  - fileName: string
  - fileType: FileType
  - markdownContent: string
  - metadata: DocumentMetadata
  - processingProvider: ProcessingProvider
  - processedAt: DateTimeImmutable
  - wordCount: int
  - characterCount: int
}

/**
 * ContentPlan Entity
 *
 * The AI-generated plan with sections and structure.
 */
ContentPlan {
  - id: string (UUID)
  - title: string
  - summary: string
  - sections: PlanSection[]
  - targetAudience: string
  - estimatedReadTime: int
  - generatedAt: DateTimeImmutable
  - refinementHistory: RefinementEntry[]
  - status: PlanStatus (draft|approved|rejected)
}

/**
 * CanvasTemplate Entity (Config Entity)
 *
 * Template configuration for AI context and page structure.
 */
CanvasTemplate {
  - id: string (machine_name)
  - label: string
  - description: string
  - systemPrompt: string
  - componentMappings: ComponentMapping[]
  - defaultStructure: array
  - weight: int
}
```

#### 2.1.2 Value Objects

```php
/**
 * FileType Value Object
 */
enum FileType: string {
  case TXT = 'txt';
  case DOCX = 'docx';
  case PDF = 'pdf';
  case MD = 'md';
  case HTML = 'html';
}

/**
 * ProcessingProvider Value Object
 */
enum ProcessingProvider: string {
  case PANDOC = 'pandoc';
  case PLAIN_TEXT = 'plain_text';
  case WEB_SCRAPER = 'web_scraper';
  case CUSTOM = 'custom';
}

/**
 * WizardStep Value Object
 */
enum WizardStep: int {
  case UPLOAD = 1;
  case PLAN = 2;
  case CREATE = 3;
}

/**
 * WizardStatus Value Object
 */
enum WizardStatus: string {
  case IN_PROGRESS = 'in_progress';
  case COMPLETED = 'completed';
  case ABANDONED = 'abandoned';
}

/**
 * PlanStatus Value Object
 */
enum PlanStatus: string {
  case DRAFT = 'draft';
  case APPROVED = 'approved';
  case REJECTED = 'rejected';
  case NEEDS_REFINEMENT = 'needs_refinement';
}

/**
 * AIContext Value Object
 *
 * Selected context modules that influence AI planning.
 */
final class AIContext {
  public function __construct(
    public readonly string $id,
    public readonly string $label,
    public readonly string $description,
    public readonly array $systemPromptAdditions,
  ) {}
}

/**
 * PlanSection Value Object
 */
final class PlanSection {
  public function __construct(
    public readonly string $id,
    public readonly string $title,
    public readonly string $content,
    public readonly int $order,
    public readonly string $componentType,
    public readonly array $metadata,
  ) {}
}

/**
 * DocumentMetadata Value Object
 */
final class DocumentMetadata {
  public function __construct(
    public readonly ?string $title,
    public readonly ?string $author,
    public readonly ?DateTimeImmutable $createdDate,
    public readonly ?string $language,
    public readonly array $headings,
    public readonly array $customProperties,
  ) {}
}

/**
 * ComponentMapping Value Object
 */
final class ComponentMapping {
  public function __construct(
    public readonly string $sectionType,
    public readonly string $componentId,
    public readonly array $propMappings,
  ) {}
}

/**
 * RefinementEntry Value Object
 */
final class RefinementEntry {
  public function __construct(
    public readonly string $userPrompt,
    public readonly string $aiResponse,
    public readonly DateTimeImmutable $timestamp,
  ) {}
}
```

### 2.2 Aggregates

```
                    +------------------+
                    | WizardSession    |  <-- Aggregate Root
                    +------------------+
                           |
          +----------------+----------------+
          |                |                |
          v                v                v
+------------------+ +-------------+ +---------------+
|ProcessedDocument | | ContentPlan | |CanvasTemplate |
+------------------+ +-------------+ +---------------+
          |                |
          v                v
+------------------+ +-------------+
|DocumentMetadata  | | PlanSection |
+------------------+ +-------------+
```

**Invariants**:
1. A WizardSession cannot proceed to Step 2 without at least one ProcessedDocument
2. A WizardSession cannot proceed to Step 3 without an approved ContentPlan
3. ContentPlan must have at least one PlanSection
4. ProcessedDocument must have non-empty markdownContent

---

## 3. Module Architecture

### 3.1 Directory Structure

```
web/modules/custom/content_preparation_wizard/
|-- content_preparation_wizard.info.yml
|-- content_preparation_wizard.module
|-- content_preparation_wizard.services.yml
|-- content_preparation_wizard.routing.yml
|-- content_preparation_wizard.permissions.yml
|-- content_preparation_wizard.links.menu.yml
|-- content_preparation_wizard.libraries.yml
|
|-- config/
|   |-- install/
|   |   |-- content_preparation_wizard.settings.yml
|   |   |-- content_preparation_wizard.canvas_template.default.yml
|   |
|   |-- schema/
|       |-- content_preparation_wizard.schema.yml
|
|-- src/
|   |-- Controller/
|   |   |-- WizardController.php
|   |
|   |-- Entity/
|   |   |-- CanvasTemplate.php
|   |   |-- CanvasTemplateInterface.php
|   |   |-- CanvasTemplateListBuilder.php
|   |
|   |-- Enum/
|   |   |-- FileType.php
|   |   |-- ProcessingProvider.php
|   |   |-- WizardStep.php
|   |   |-- WizardStatus.php
|   |   |-- PlanStatus.php
|   |
|   |-- Event/
|   |   |-- DocumentProcessedEvent.php
|   |   |-- ContentPlanGeneratedEvent.php
|   |   |-- CanvasPageCreatedEvent.php
|   |   |-- WizardStepChangedEvent.php
|   |
|   |-- EventSubscriber/
|   |   |-- WizardEventSubscriber.php
|   |
|   |-- Exception/
|   |   |-- DocumentProcessingException.php
|   |   |-- PlanGenerationException.php
|   |   |-- CanvasCreationException.php
|   |   |-- InvalidWizardStateException.php
|   |
|   |-- Form/
|   |   |-- ContentPreparationWizard.php          # Main wizard form (ctools)
|   |   |-- Step1UploadForm.php
|   |   |-- Step2PlanForm.php
|   |   |-- Step3CreateForm.php
|   |   |-- CanvasTemplateForm.php
|   |   |-- SettingsForm.php
|   |
|   |-- Model/
|   |   |-- WizardSession.php
|   |   |-- ProcessedDocument.php
|   |   |-- ContentPlan.php
|   |   |-- PlanSection.php
|   |   |-- DocumentMetadata.php
|   |   |-- AIContext.php
|   |   |-- ComponentMapping.php
|   |   |-- RefinementEntry.php
|   |
|   |-- Plugin/
|   |   |-- DocumentProcessor/
|   |   |   |-- DocumentProcessorInterface.php
|   |   |   |-- DocumentProcessorBase.php
|   |   |   |-- PandocProcessor.php
|   |   |   |-- PlainTextProcessor.php
|   |   |   |-- MarkdownProcessor.php
|   |   |
|   |   |-- AIContextProvider/
|   |       |-- AIContextProviderInterface.php
|   |       |-- AIContextProviderBase.php
|   |       |-- TechnicalDocumentContext.php
|   |       |-- MarketingContentContext.php
|   |       |-- EducationalContentContext.php
|   |
|   |-- PluginManager/
|   |   |-- DocumentProcessorPluginManager.php
|   |   |-- AIContextProviderPluginManager.php
|   |
|   |-- Attribute/
|   |   |-- DocumentProcessor.php
|   |   |-- AIContextProvider.php
|   |
|   |-- Repository/
|   |   |-- WizardSessionRepositoryInterface.php
|   |   |-- WizardSessionRepository.php
|   |
|   |-- Service/
|   |   |-- DocumentProcessingService.php
|   |   |-- DocumentProcessingServiceInterface.php
|   |   |-- WizardSessionManager.php
|   |   |-- WizardSessionManagerInterface.php
|   |   |-- ContentPlanGenerator.php
|   |   |-- ContentPlanGeneratorInterface.php
|   |   |-- CanvasCreator.php
|   |   |-- CanvasCreatorInterface.php
|   |   |-- PandocConverter.php
|   |   |-- PandocConverterInterface.php
|   |   |-- MetadataExtractor.php
|   |   |-- MetadataExtractorInterface.php
|   |
|   |-- Validation/
|       |-- DocumentValidator.php
|       |-- PlanValidator.php
|
|-- prompts/
|   |-- content_plan_generator/
|   |   |-- generatePlan.yml
|   |   |-- refinePlan.yml
|   |   |-- determineSections.yml
|   |
|   |-- canvas_creator/
|       |-- mapToComponents.yml
|       |-- generateContent.yml
|
|-- templates/
|   |-- content-preparation-wizard.html.twig
|   |-- wizard-step-indicator.html.twig
|   |-- processed-document-preview.html.twig
|   |-- content-plan-preview.html.twig
|
|-- css/
|   |-- wizard.css
|
|-- js/
|   |-- wizard.js
|
|-- tests/
    |-- src/
        |-- Unit/
        |   |-- Model/
        |   |   |-- WizardSessionTest.php
        |   |   |-- ProcessedDocumentTest.php
        |   |   |-- ContentPlanTest.php
        |   |
        |   |-- Service/
        |       |-- DocumentProcessingServiceTest.php
        |       |-- ContentPlanGeneratorTest.php
        |
        |-- Kernel/
        |   |-- DocumentProcessorPluginTest.php
        |   |-- WizardSessionRepositoryTest.php
        |
        |-- Functional/
            |-- WizardFormTest.php
            |-- CanvasCreationTest.php
```

### 3.2 Plugin Type Definitions

#### 3.2.1 DocumentProcessor Plugin

```php
<?php

namespace Drupal\content_preparation_wizard\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a DocumentProcessor plugin attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class DocumentProcessor extends Plugin {

  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly TranslatableMarkup $description,
    public readonly array $supported_extensions = [],
    public readonly int $weight = 0,
    public readonly array $requirements = [],
  ) {}

}
```

```php
<?php

namespace Drupal\content_preparation_wizard\Plugin\DocumentProcessor;

use Drupal\Core\Plugin\PluginBase;
use Drupal\content_preparation_wizard\Model\ProcessedDocument;
use Drupal\content_preparation_wizard\Model\DocumentMetadata;
use Drupal\file\FileInterface;

/**
 * Interface for DocumentProcessor plugins.
 */
interface DocumentProcessorInterface {

  /**
   * Checks if this processor can handle the given file.
   */
  public function canProcess(FileInterface $file): bool;

  /**
   * Processes the file and returns a ProcessedDocument.
   */
  public function process(FileInterface $file): ProcessedDocument;

  /**
   * Extracts metadata from the file.
   */
  public function extractMetadata(FileInterface $file): DocumentMetadata;

  /**
   * Returns the supported file extensions.
   */
  public function getSupportedExtensions(): array;

  /**
   * Checks if requirements are met for this processor.
   */
  public function checkRequirements(): bool;

}
```

#### 3.2.2 AIContextProvider Plugin

```php
<?php

namespace Drupal\content_preparation_wizard\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an AIContextProvider plugin attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AIContextProvider extends Plugin {

  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly TranslatableMarkup $description,
    public readonly string $category = 'general',
    public readonly int $weight = 0,
  ) {}

}
```

### 3.3 Service Definitions

```yaml
# content_preparation_wizard.services.yml

services:
  # Plugin Managers
  plugin.manager.document_processor:
    class: Drupal\content_preparation_wizard\PluginManager\DocumentProcessorPluginManager
    parent: default_plugin_manager

  plugin.manager.ai_context_provider:
    class: Drupal\content_preparation_wizard\PluginManager\AIContextProviderPluginManager
    parent: default_plugin_manager

  # Core Services
  content_preparation_wizard.document_processing:
    class: Drupal\content_preparation_wizard\Service\DocumentProcessingService
    arguments:
      - '@plugin.manager.document_processor'
      - '@file_system'
      - '@logger.factory'
      - '@event_dispatcher'

  content_preparation_wizard.wizard_session_manager:
    class: Drupal\content_preparation_wizard\Service\WizardSessionManager
    arguments:
      - '@tempstore.private'
      - '@current_user'
      - '@uuid'
      - '@datetime.time'
      - '@event_dispatcher'

  content_preparation_wizard.content_plan_generator:
    class: Drupal\content_preparation_wizard\Service\ContentPlanGenerator
    arguments:
      - '@ai.provider'
      - '@plugin.manager.ai_context_provider'
      - '@config.factory'
      - '@extension.path.resolver'
      - '@ai.prompt_json_decoder'
      - '@logger.factory'
      - '@event_dispatcher'

  content_preparation_wizard.canvas_creator:
    class: Drupal\content_preparation_wizard\Service\CanvasCreator
    arguments:
      - '@entity_type.manager'
      - '@content_preparation_wizard.content_plan_generator'
      - '@logger.factory'
      - '@event_dispatcher'

  # Supporting Services
  content_preparation_wizard.pandoc_converter:
    class: Drupal\content_preparation_wizard\Service\PandocConverter
    arguments:
      - '@config.factory'
      - '@logger.factory'

  content_preparation_wizard.metadata_extractor:
    class: Drupal\content_preparation_wizard\Service\MetadataExtractor
    arguments:
      - '@file_system'

  # Repository
  content_preparation_wizard.session_repository:
    class: Drupal\content_preparation_wizard\Repository\WizardSessionRepository
    arguments:
      - '@tempstore.private'
      - '@current_user'
```

### 3.4 Config Schema

```yaml
# config/schema/content_preparation_wizard.schema.yml

content_preparation_wizard.settings:
  type: config_object
  label: 'Content Preparation Wizard settings'
  mapping:
    pandoc_path:
      type: string
      label: 'Path to Pandoc executable'
    max_file_size:
      type: integer
      label: 'Maximum file size in bytes'
    allowed_extensions:
      type: sequence
      label: 'Allowed file extensions'
      sequence:
        type: string
    default_ai_provider:
      type: string
      label: 'Default AI provider'
    default_ai_model:
      type: string
      label: 'Default AI model'
    session_timeout:
      type: integer
      label: 'Session timeout in seconds'
    enable_refinement:
      type: boolean
      label: 'Enable plan refinement'
    max_refinement_iterations:
      type: integer
      label: 'Maximum refinement iterations'

content_preparation_wizard.canvas_template.*:
  type: config_entity
  label: 'Canvas Template'
  mapping:
    id:
      type: string
      label: 'Machine name'
    label:
      type: label
      label: 'Label'
    uuid:
      type: string
      label: 'UUID'
    description:
      type: text
      label: 'Description'
    system_prompt:
      type: text
      label: 'System prompt for AI'
    component_mappings:
      type: sequence
      label: 'Component mappings'
      sequence:
        type: mapping
        mapping:
          section_type:
            type: string
            label: 'Section type'
          component_id:
            type: string
            label: 'Canvas component ID'
          prop_mappings:
            type: sequence
            label: 'Property mappings'
            sequence:
              type: mapping
              mapping:
                source:
                  type: string
                  label: 'Source field'
                target:
                  type: string
                  label: 'Target prop'
    default_structure:
      type: sequence
      label: 'Default page structure'
      sequence:
        type: ignore
    weight:
      type: weight
      label: 'Weight'
    status:
      type: boolean
      label: 'Status'
```

---

## 4. Integration Architecture

### 4.1 AI Module Integration

```
+-------------------+     +------------------+     +-------------------+
|  Content Plan     |---->|   AI Provider    |---->|  OpenAI/Anthropic |
|   Generator       |     |   (ai module)    |     |   API             |
+-------------------+     +------------------+     +-------------------+
         |                         |
         v                         v
+-------------------+     +------------------+
|  Prompt Templates |     |  Chat Interface  |
|  (YAML files)     |     |  (ChatInput)     |
+-------------------+     +------------------+
```

### 4.2 Canvas Module Integration

```
+-------------------+     +------------------+     +-------------------+
|  Canvas Creator   |---->|  Canvas Entity   |---->|  Canvas Page      |
|   Service         |     |  Type Manager    |     |  (config entity)  |
+-------------------+     +------------------+     +-------------------+
         |                         |
         v                         v
+-------------------+     +------------------+
|  Component        |     |  JS Component    |
|   Mapping         |     |  (canvas)        |
+-------------------+     +------------------+
```

### 4.3 Event Flow

```
Document Upload
       |
       v
DocumentProcessedEvent -----> [Subscribers]
       |
       v
Content Plan Generation
       |
       v
ContentPlanGeneratedEvent -----> [Subscribers]
       |
       v
Plan Approval
       |
       v
Canvas Page Creation
       |
       v
CanvasPageCreatedEvent -----> [Subscribers]
```

---

## 5. Sequence Diagrams

### 5.1 Step 1: Document Upload Flow

```
User          Step1Form      DocService      ProcessorPlugin     TempStore
  |               |               |                 |                 |
  |--upload------>|               |                 |                 |
  |               |--process----->|                 |                 |
  |               |               |--canProcess?--->|                 |
  |               |               |<--true----------|                 |
  |               |               |--process------->|                 |
  |               |               |<--markdown------|                 |
  |               |               |--extractMeta--->|                 |
  |               |               |<--metadata------|                 |
  |               |<--ProcessedDoc|                 |                 |
  |               |--storeSession----------------------->|            |
  |<--preview-----|               |                 |                 |
```

### 5.2 Step 2: AI Planning Flow

```
User          Step2Form      PlanGenerator    AIProvider      TempStore
  |               |               |                |                |
  |--contexts---->|               |                |                |
  |               |--loadSession------------------>|                |
  |               |<--session-----|----------------|----------------|
  |               |--generatePlan>|                |                |
  |               |               |--loadPrompt--->|                |
  |               |               |--chat--------->|                |
  |               |               |<--response-----|                |
  |               |               |--parseJson---->|                |
  |               |<--ContentPlan-|                |                |
  |               |--updateSession---------------->|                |
  |<--planPreview-|               |                |                |
  |               |               |                |                |
  |--refine------>|               |                |                |
  |               |--refinePlan-->|                |                |
  |               |               |--chat--------->|                |
  |               |               |<--response-----|                |
  |               |<--updatedPlan-|                |                |
```

### 5.3 Step 3: Canvas Creation Flow

```
User          Step3Form      CanvasCreator    CanvasModule     TempStore
  |               |               |                |                |
  |--approve----->|               |                |                |
  |               |--loadSession------------------>|                |
  |               |<--session-----|----------------|----------------|
  |               |--createPage-->|                |                |
  |               |               |--loadTemplate->|                |
  |               |               |--mapComponents>|                |
  |               |               |--createPage--->|                |
  |               |               |<--pageEntity---|                |
  |               |<--CanvasPage--|                |                |
  |               |--clearSession----------------->|                |
  |<--redirect----|               |                |                |
```

---

## 6. Data Flow Contracts

### 6.1 Prompt Templates (YAML)

```yaml
# prompts/content_plan_generator/generatePlan.yml

prompt:
  introduction: |
    You are a content planning assistant that creates structured content plans
    from source documents. Your task is to analyze the provided markdown content
    and create a comprehensive content plan suitable for a web page.

  formats:
    - title: "string - The page title"
    - summary: "string - A brief summary (2-3 sentences)"
    - target_audience: "string - The intended audience"
    - sections:
        - id: "string - unique section identifier"
          title: "string - section heading"
          content: "string - section content in markdown"
          component_type: "string - suggested component (hero|text|image|cta|list)"
          order: "integer - display order"
    - estimated_read_time: "integer - minutes to read"

  one_shot_learning_examples:
    - title: "Getting Started with Drupal"
      summary: "A comprehensive guide for beginners..."
      target_audience: "New Drupal developers"
      sections:
        - id: "intro"
          title: "Introduction"
          content: "Welcome to Drupal..."
          component_type: "hero"
          order: 1
      estimated_read_time: 5

validation:
  - json_exists

retries: 3
output_format: json
preferred_llm: openai
preferred_model: gpt-4
```

### 6.2 Service Interfaces

```php
<?php

namespace Drupal\content_preparation_wizard\Service;

use Drupal\content_preparation_wizard\Model\ProcessedDocument;
use Drupal\file\FileInterface;

/**
 * Interface for document processing service.
 */
interface DocumentProcessingServiceInterface {

  /**
   * Processes an uploaded file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The uploaded file.
   *
   * @return \Drupal\content_preparation_wizard\Model\ProcessedDocument
   *   The processed document.
   *
   * @throws \Drupal\content_preparation_wizard\Exception\DocumentProcessingException
   */
  public function process(FileInterface $file): ProcessedDocument;

  /**
   * Gets all supported file extensions.
   *
   * @return array
   *   Array of supported extensions.
   */
  public function getSupportedExtensions(): array;

  /**
   * Validates a file before processing.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to validate.
   *
   * @return array
   *   Array of validation errors, empty if valid.
   */
  public function validate(FileInterface $file): array;

}
```

```php
<?php

namespace Drupal\content_preparation_wizard\Service;

use Drupal\content_preparation_wizard\Model\ContentPlan;
use Drupal\content_preparation_wizard\Model\ProcessedDocument;
use Drupal\content_preparation_wizard\Model\AIContext;

/**
 * Interface for content plan generation service.
 */
interface ContentPlanGeneratorInterface {

  /**
   * Generates a content plan from processed documents.
   *
   * @param \Drupal\content_preparation_wizard\Model\ProcessedDocument[] $documents
   *   Array of processed documents.
   * @param \Drupal\content_preparation_wizard\Model\AIContext[] $contexts
   *   Array of selected AI contexts.
   * @param string|null $templateId
   *   Optional template ID.
   *
   * @return \Drupal\content_preparation_wizard\Model\ContentPlan
   *   The generated content plan.
   *
   * @throws \Drupal\content_preparation_wizard\Exception\PlanGenerationException
   */
  public function generate(
    array $documents,
    array $contexts,
    ?string $templateId = NULL
  ): ContentPlan;

  /**
   * Refines an existing content plan based on user feedback.
   *
   * @param \Drupal\content_preparation_wizard\Model\ContentPlan $plan
   *   The current plan.
   * @param string $refinementPrompt
   *   User's refinement instructions.
   *
   * @return \Drupal\content_preparation_wizard\Model\ContentPlan
   *   The refined content plan.
   */
  public function refine(ContentPlan $plan, string $refinementPrompt): ContentPlan;

}
```

```php
<?php

namespace Drupal\content_preparation_wizard\Service;

use Drupal\content_preparation_wizard\Model\ContentPlan;
use Drupal\content_preparation_wizard\Entity\CanvasTemplateInterface;
use Drupal\canvas\Entity\Page;

/**
 * Interface for Canvas page creation service.
 */
interface CanvasCreatorInterface {

  /**
   * Creates a Canvas page from a content plan.
   *
   * @param \Drupal\content_preparation_wizard\Model\ContentPlan $plan
   *   The approved content plan.
   * @param \Drupal\content_preparation_wizard\Entity\CanvasTemplateInterface $template
   *   The canvas template to use.
   *
   * @return \Drupal\canvas\Entity\Page
   *   The created Canvas page.
   *
   * @throws \Drupal\content_preparation_wizard\Exception\CanvasCreationException
   */
  public function create(ContentPlan $plan, CanvasTemplateInterface $template): Page;

  /**
   * Maps plan sections to Canvas components.
   *
   * @param \Drupal\content_preparation_wizard\Model\ContentPlan $plan
   *   The content plan.
   * @param \Drupal\content_preparation_wizard\Entity\CanvasTemplateInterface $template
   *   The canvas template.
   *
   * @return array
   *   Array of component tree structure.
   */
  public function mapToComponents(ContentPlan $plan, CanvasTemplateInterface $template): array;

}
```

---

## 7. Security Considerations

### 7.1 Permissions

```yaml
# content_preparation_wizard.permissions.yml

access content preparation wizard:
  title: 'Access Content Preparation Wizard'
  description: 'Allow users to use the content preparation wizard.'

administer content preparation wizard:
  title: 'Administer Content Preparation Wizard'
  description: 'Configure wizard settings and templates.'
  restrict access: true

create canvas from wizard:
  title: 'Create Canvas pages from wizard'
  description: 'Allow users to create Canvas pages from approved plans.'
```

### 7.2 Input Validation

1. **File Upload Validation**
   - MIME type verification
   - File size limits
   - Extension whitelist
   - Malware scanning hook

2. **AI Response Validation**
   - JSON schema validation
   - Content sanitization
   - XSS prevention

3. **Session Management**
   - User-scoped tempstore
   - Session timeout
   - CSRF protection

---

## 8. Extension Points

### 8.1 Custom Document Processors

Developers can add custom document processors by implementing the `DocumentProcessorInterface` and using the `#[DocumentProcessor]` attribute:

```php
<?php

namespace Drupal\mymodule\Plugin\DocumentProcessor;

use Drupal\content_preparation_wizard\Attribute\DocumentProcessor;
use Drupal\content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[DocumentProcessor(
  id: 'web_scraper',
  label: new TranslatableMarkup('Web Scraper'),
  description: new TranslatableMarkup('Scrapes web pages and converts to markdown'),
  supported_extensions: ['url'],
)]
class WebScraperProcessor extends DocumentProcessorBase {
  // Implementation
}
```

### 8.2 Custom AI Context Providers

```php
<?php

namespace Drupal\mymodule\Plugin\AIContextProvider;

use Drupal\content_preparation_wizard\Attribute\AIContextProvider;
use Drupal\content_preparation_wizard\Plugin\AIContextProvider\AIContextProviderBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[AIContextProvider(
  id: 'legal_document',
  label: new TranslatableMarkup('Legal Document Context'),
  description: new TranslatableMarkup('Optimized for legal document processing'),
  category: 'professional',
)]
class LegalDocumentContext extends AIContextProviderBase {
  // Implementation
}
```

### 8.3 Event Subscribers

```php
<?php

namespace Drupal\mymodule\EventSubscriber;

use Drupal\content_preparation_wizard\Event\ContentPlanGeneratedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MyPlanSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents() {
    return [
      ContentPlanGeneratedEvent::class => 'onPlanGenerated',
    ];
  }

  public function onPlanGenerated(ContentPlanGeneratedEvent $event) {
    // Custom logic after plan generation
  }

}
```

---

## 9. Technology Decisions

### 9.1 Architecture Decision Records

#### ADR-001: Use ctools Wizard Framework

**Context**: Need a multi-step form wizard with state persistence.

**Decision**: Use ctools FormWizardBase for wizard implementation.

**Rationale**:
- Battle-tested in Drupal ecosystem
- Built-in tempstore integration
- AJAX support out of the box
- Step navigation handled automatically

**Consequences**:
- Dependency on ctools module
- Some customization constraints

#### ADR-002: Private TempStore for Session

**Context**: Need to persist wizard state across requests.

**Decision**: Use Drupal's private tempstore (user-scoped).

**Rationale**:
- User-specific data isolation
- Automatic cleanup on session expiry
- No database schema required
- Standard Drupal pattern

**Consequences**:
- Data lost if user session expires
- Cannot resume across devices

#### ADR-003: Plugin System for Document Processors

**Context**: Need extensible document processing for different file types.

**Decision**: Implement as Drupal plugin type with attribute discovery.

**Rationale**:
- Standard Drupal extension pattern
- Easy for other modules to extend
- Clean separation of concerns
- Supports dependency injection

**Consequences**:
- More initial setup
- Plugin manager overhead

#### ADR-004: Value Objects as PHP Enums and Classes

**Context**: Need type-safe domain model representation.

**Decision**: Use PHP 8.1 enums for fixed values, readonly classes for complex value objects.

**Rationale**:
- Type safety at compile time
- Immutability guarantees
- IDE autocomplete support
- Self-documenting code

**Consequences**:
- Requires PHP 8.1+
- Need serialization handling for tempstore

---

## 10. Quality Attributes

### 10.1 Performance Requirements

| Metric | Target | Strategy |
|--------|--------|----------|
| File processing | <5s for 10MB DOCX | Async processing option |
| AI plan generation | <30s | Streaming responses |
| Page load | <2s | Cached templates |
| Memory usage | <256MB | Chunked file reading |

### 10.2 Scalability Considerations

1. **File Processing**: Support queue-based processing for large files
2. **AI Requests**: Rate limiting and retry logic
3. **Session Storage**: Consider Redis for high-traffic sites

### 10.3 Reliability

1. **Error Recovery**: Graceful degradation if Pandoc unavailable
2. **AI Fallback**: Alternative prompts for different AI providers
3. **Data Integrity**: Validation at each step transition

---

## 11. Future Considerations

### 11.1 Planned Submodules

1. **content_preparation_wizard_web_scraper** - Web page to markdown conversion
2. **content_preparation_wizard_batch** - Bulk document processing
3. **content_preparation_wizard_templates** - Additional canvas templates
4. **content_preparation_wizard_api** - REST API for headless usage

### 11.2 Potential Integrations

1. **Search API** - Index processed documents
2. **Workflow** - Content moderation for plans
3. **AI Agents** - Automated refinement agents
4. **Analytics** - Usage tracking and optimization

---

## 12. Glossary

| Term | Definition |
|------|------------|
| **Bounded Context** | A logical boundary defining a specific domain area |
| **Aggregate** | A cluster of domain objects treated as a single unit |
| **Value Object** | An immutable object defined by its attributes |
| **Entity** | An object with identity that persists over time |
| **Plugin** | Drupal's extension mechanism for swappable implementations |
| **TempStore** | Drupal's key-value storage for temporary data |
| **Canvas** | Drupal module for component-based page building |
| **Pandoc** | Universal document converter CLI tool |

---

## Document Metadata

- **Version**: 1.0.0
- **Created**: 2026-01-27
- **Author**: System Architecture Designer
- **Status**: Draft
- **Review Required**: Technical Lead, AI Module Maintainer
