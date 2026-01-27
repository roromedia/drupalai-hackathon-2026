# Content Preparation Wizard - UI/UX Technical Specification

## Document Information

| Attribute | Value |
|-----------|-------|
| Module Name | `content_preparation_wizard` |
| Version | 1.0.0 |
| Drupal Compatibility | 10.x, 11.x |
| Dependencies | `ai`, `canvas`, `file` |
| Last Updated | 2026-01-27 |

---

## Table of Contents

1. [Overview](#1-overview)
2. [Architecture](#2-architecture)
3. [User Flow Diagram](#3-user-flow-diagram)
4. [Step 1: Document Upload Interface](#4-step-1-document-upload-interface)
5. [Step 2: AI Plan Review](#5-step-2-ai-plan-review)
6. [Step 3: Final Preview](#6-step-3-final-preview)
7. [AJAX Interactions](#7-ajax-interactions)
8. [Form API Implementation](#8-form-api-implementation)
9. [JavaScript Components](#9-javascript-components)
10. [Accessibility Requirements](#10-accessibility-requirements)
11. [Error Handling](#11-error-handling)
12. [Performance Considerations](#12-performance-considerations)
13. [Security Considerations](#13-security-considerations)
14. [Appendices](#14-appendices)

---

## 1. Overview

### 1.1 Purpose

The Content Preparation Wizard provides a guided, multi-step interface for content editors to upload source documents, leverage AI to generate a content plan, and create Canvas pages. The wizard simplifies the content creation process by automating document parsing, AI-driven content structuring, and Canvas component tree generation.

### 1.2 User Personas

| Persona | Description | Primary Goals |
|---------|-------------|---------------|
| Content Editor | Day-to-day content creator | Quickly transform documents into web pages |
| Content Manager | Oversees content strategy | Ensure brand/SEO compliance |
| Site Administrator | Technical oversight | Configure AI templates and contexts |

### 1.3 Key Features

- Multi-file drag-and-drop upload with progress indication
- Configurable AI context injection (site structure, brand guidelines, etc.)
- AI-powered content plan generation with iterative refinement
- Live Canvas page preview before creation
- AJAX-driven step navigation without full page reloads

---

## 2. Architecture

### 2.1 Module Structure

```
content_preparation_wizard/
├── content_preparation_wizard.info.yml
├── content_preparation_wizard.module
├── content_preparation_wizard.routing.yml
├── content_preparation_wizard.services.yml
├── content_preparation_wizard.libraries.yml
├── content_preparation_wizard.permissions.yml
├── config/
│   ├── install/
│   │   └── content_preparation_wizard.settings.yml
│   └── schema/
│       └── content_preparation_wizard.schema.yml
├── src/
│   ├── Controller/
│   │   └── WizardController.php
│   ├── Form/
│   │   ├── ContentPreparationWizard.php
│   │   ├── Step/
│   │   │   ├── DocumentUploadForm.php
│   │   │   ├── AiPlanReviewForm.php
│   │   │   └── FinalPreviewForm.php
│   │   └── SettingsForm.php
│   ├── Service/
│   │   ├── DocumentParserService.php
│   │   ├── AiPlanGeneratorService.php
│   │   ├── CanvasPageBuilderService.php
│   │   └── ContextProviderService.php
│   ├── Plugin/
│   │   └── AiContext/
│   │       ├── AiContextInterface.php
│   │       ├── AiContextBase.php
│   │       ├── SiteStructureContext.php
│   │       ├── BrandGuidelinesContext.php
│   │       ├── SeoRequirementsContext.php
│   │       └── AccessibilityStandardsContext.php
│   └── Ajax/
│       └── WizardAjaxHandler.php
├── templates/
│   ├── wizard-step-indicator.html.twig
│   ├── file-upload-area.html.twig
│   ├── content-plan-display.html.twig
│   └── canvas-preview.html.twig
├── js/
│   ├── wizard.js
│   ├── file-upload.js
│   ├── plan-refinement.js
│   └── preview.js
└── css/
    └── wizard.css
```

### 2.2 Dependencies Diagram

```
┌─────────────────────────────────────┐
│     Content Preparation Wizard      │
└──────────────┬──────────────────────┘
               │
    ┌──────────┼──────────┐
    │          │          │
    ▼          ▼          ▼
┌───────┐ ┌────────┐ ┌────────┐
│  AI   │ │ Canvas │ │  File  │
│Module │ │ Module │ │ Module │
└───────┘ └────────┘ └────────┘
    │          │
    ▼          ▼
┌───────────────────────────────────┐
│     Drupal Core Services          │
│ (TempStore, Form API, AJAX, etc.) │
└───────────────────────────────────┘
```

### 2.3 Data Flow

```
Documents → Parser → AI Context + Template → AI Generation → Plan → Refinement → Canvas Page
```

---

## 3. User Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           WIZARD FLOW                                    │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌─────────┐      ┌─────────┐      ┌─────────┐      ┌─────────┐        │
│  │ START   │─────▶│ STEP 1  │─────▶│ STEP 2  │─────▶│ STEP 3  │        │
│  │         │      │ Upload  │      │ Review  │      │ Preview │        │
│  └─────────┘      └────┬────┘      └────┬────┘      └────┬────┘        │
│                        │                │                 │             │
│                   ┌────▼────┐      ┌────▼────┐      ┌────▼────┐        │
│                   │Validate │      │ Refine  │◀──┐  │ Create  │        │
│                   │ Files   │      │  Plan   │───┘  │  Page   │        │
│                   └────┬────┘      └─────────┘      └────┬────┘        │
│                        │                                 │             │
│                   ┌────▼────┐                       ┌────▼────┐        │
│                   │ Process │                       │ SUCCESS │        │
│                   │with AI  │                       │ Redirect│        │
│                   └─────────┘                       └─────────┘        │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### 3.1 State Transitions

| Current State | Action | Next State | Conditions |
|---------------|--------|------------|------------|
| Entry | Access wizard | Step 1 | User has permission |
| Step 1 | Submit files | Step 2 | At least 1 valid file |
| Step 1 | Cancel | Exit | - |
| Step 2 | Accept plan | Step 3 | Plan generated |
| Step 2 | Regenerate | Step 2 | Refinement text provided |
| Step 2 | Back | Step 1 | - |
| Step 3 | Create page | Success | Valid preview |
| Step 3 | Back | Step 2 | - |
| Success | - | Canvas edit | Page created |

---

## 4. Step 1: Document Upload Interface

### 4.1 Visual Layout

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Content Preparation Wizard                                   Step 1 of 3│
│  ═══════════════════════════════════════════════════════════════════════│
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │                                                                   │  │
│  │              ╔═══════════════════════════════════╗                │  │
│  │              ║                                   ║                │  │
│  │              ║     [Folder Icon]                 ║                │  │
│  │              ║                                   ║                │  │
│  │              ║   Drag & Drop Files Here          ║                │  │
│  │              ║   or click to browse              ║                │  │
│  │              ║                                   ║                │  │
│  │              ║   Supported: TXT, DOCX, PDF       ║                │  │
│  │              ║   Max file size: 10MB             ║                │  │
│  │              ╚═══════════════════════════════════╝                │  │
│  │                                                                   │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │ Uploaded Files:                                                   │  │
│  ├───────────────────────────────────────────────────────────────────┤  │
│  │ [DOC]  document1.docx         2.3 MB    ✓ Processed   [Remove]   │  │
│  │ [TXT]  notes.txt              45 KB     ✓ Processed   [Remove]   │  │
│  │ [PDF]  report.pdf             1.8 MB    [░░░░░  60%]             │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ─────────────────────────────────────────────────────────────────────  │
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │ AI Contexts:                                                      │  │
│  │                                                                   │  │
│  │   ☑ Site structure          ☑ Brand guidelines                   │  │
│  │   ☐ SEO requirements        ☐ Accessibility standards            │  │
│  │                                                                   │  │
│  │ AI Template:                                                      │  │
│  │   ┌─────────────────────────────────────────────────────────┐    │  │
│  │   │ [Select template...]                                  ▼ │    │  │
│  │   └─────────────────────────────────────────────────────────┘    │  │
│  │                                                                   │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│                                                          ┌─────────────┐│
│                                                          │   Next  →   ││
│                                                          └─────────────┘│
└─────────────────────────────────────────────────────────────────────────┘
```

### 4.2 Form Elements Specification

#### 4.2.1 File Upload Area

| Property | Value |
|----------|-------|
| Element Type | `managed_file` (extended) |
| Field Name | `source_documents` |
| Cardinality | Multiple |
| Accept Types | `.txt`, `.docx`, `.pdf` |
| Max File Size | 10MB (configurable) |
| Upload Location | `private://content_preparation_wizard/` |
| Progress Indicator | `bar` |

```php
$form['source_documents'] = [
  '#type' => 'managed_file',
  '#title' => $this->t('Source Documents'),
  '#description' => $this->t('Upload TXT, DOCX, or PDF files. Maximum file size: @size.', [
    '@size' => format_size(Environment::getUploadMaxSize()),
  ]),
  '#upload_location' => 'private://content_preparation_wizard/',
  '#upload_validators' => [
    'FileExtension' => ['extensions' => 'txt docx pdf'],
    'FileSizeLimit' => ['fileLimit' => 10 * 1024 * 1024],
  ],
  '#multiple' => TRUE,
  '#required' => TRUE,
  '#attributes' => [
    'class' => ['cpw-file-upload'],
    'data-drag-drop' => 'true',
  ],
];
```

#### 4.2.2 AI Contexts Checkboxes

| Property | Value |
|----------|-------|
| Element Type | `checkboxes` |
| Field Name | `ai_contexts` |
| Default Values | `['site_structure', 'brand_guidelines']` |

```php
$form['ai_contexts'] = [
  '#type' => 'checkboxes',
  '#title' => $this->t('AI Contexts'),
  '#description' => $this->t('Select which contextual information to include in AI processing.'),
  '#options' => $this->contextProvider->getAvailableContexts(),
  '#default_value' => ['site_structure', 'brand_guidelines'],
  '#ajax' => [
    'callback' => '::updateContextPreview',
    'wrapper' => 'context-preview-wrapper',
    'event' => 'change',
  ],
];
```

**Available Context Options:**

| Machine Name | Label | Description |
|--------------|-------|-------------|
| `site_structure` | Site structure | Current site navigation and content hierarchy |
| `brand_guidelines` | Brand guidelines | Tone, voice, and style requirements |
| `seo_requirements` | SEO requirements | Keyword targets and meta requirements |
| `accessibility_standards` | Accessibility standards | WCAG compliance requirements |

#### 4.2.3 AI Template Select

| Property | Value |
|----------|-------|
| Element Type | `select` |
| Field Name | `ai_template` |
| Required | Yes |

```php
$form['ai_template'] = [
  '#type' => 'select',
  '#title' => $this->t('AI Template'),
  '#description' => $this->t('Select the AI template to use for content generation.'),
  '#options' => $this->getAiTemplateOptions(),
  '#required' => TRUE,
  '#empty_option' => $this->t('- Select template -'),
  '#ajax' => [
    'callback' => '::updateTemplateDescription',
    'wrapper' => 'template-description-wrapper',
  ],
];
```

### 4.3 Validation Rules

| Field | Rule | Error Message |
|-------|------|---------------|
| `source_documents` | Required, min 1 file | "Please upload at least one document." |
| `source_documents` | File extension | "Invalid file type. Allowed: TXT, DOCX, PDF." |
| `source_documents` | File size | "File @filename exceeds maximum size of @maxsize." |
| `ai_template` | Required | "Please select an AI template." |
| `ai_contexts` | At least 1 selected | "Please select at least one AI context." (warning only) |

### 4.4 Step 1 AJAX Behaviors

| Trigger | Event | Callback | Wrapper | Effect |
|---------|-------|----------|---------|--------|
| File upload | `change` | `uploadFile` | `file-list-wrapper` | Add file to list |
| File remove | `click` | `removeFile` | `file-list-wrapper` | Remove from list |
| Context change | `change` | `updateContextPreview` | `context-preview-wrapper` | Update preview |
| Template change | `change` | `updateTemplateDescription` | `template-description-wrapper` | Show description |

---

## 5. Step 2: AI Plan Review

### 5.1 Visual Layout

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Content Preparation Wizard                                   Step 2 of 3│
│  ═══════════════════════════════════════════════════════════════════════│
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │ [Plan Icon] Content Plan                                          │  │
│  │ Based on your documents and selected contexts                     │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │                                                                   │  │
│  │  1. Introduction Section                                          │  │
│  │     ├─ Overview from document1.docx                               │  │
│  │     ├─ Key points extracted                                       │  │
│  │     └─ Suggested components: Hero, Text Block                     │  │
│  │                                                                   │  │
│  │  2. Main Content                                                  │  │
│  │     ├─ Technical details from report.pdf                          │  │
│  │     ├─ Supporting notes from notes.txt                            │  │
│  │     └─ Suggested components: Accordion, Image Gallery             │  │
│  │                                                                   │  │
│  │  3. Summary & Call to Action                                      │  │
│  │     ├─ Conclusion synthesis                                       │  │
│  │     └─ Suggested components: CTA Banner, Contact Form             │  │
│  │                                                                   │  │
│  │  ─────────────────────────────────────────────────────────────   │  │
│  │  Estimated reading time: 5 minutes                                │  │
│  │  Word count: ~1,200 words                                         │  │
│  │  SEO score: 85/100 ████████░░                                    │  │
│  │                                                                   │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │ Refine the plan:                                                  │  │
│  │ ┌─────────────────────────────────────────────────────────────┐  │  │
│  │ │                                                             │  │  │
│  │ │ Type instructions to adjust the plan...                     │  │  │
│  │ │                                                             │  │  │
│  │ └─────────────────────────────────────────────────────────────┘  │  │
│  │                                                                   │  │
│  │ ┌─────────────────┐                                              │  │
│  │ │ Regenerate Plan │                                              │  │
│  │ └─────────────────┘                                              │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ┌─────────────┐                                        ┌─────────────┐│
│  │  ← Back     │                                        │   Next  →   ││
│  └─────────────┘                                        └─────────────┘│
└─────────────────────────────────────────────────────────────────────────┘
```

### 5.2 Form Elements Specification

#### 5.2.1 Content Plan Display

| Property | Value |
|----------|-------|
| Element Type | `container` (read-only display) |
| Field Name | `content_plan_display` |
| AJAX Wrapper | `content-plan-wrapper` |

```php
$form['content_plan_display'] = [
  '#type' => 'container',
  '#attributes' => [
    'id' => 'content-plan-wrapper',
    'class' => ['cpw-content-plan'],
  ],
  'plan' => [
    '#theme' => 'content_plan_display',
    '#plan' => $this->aiPlanGenerator->generatePlan($cached_values),
    '#documents' => $cached_values['processed_documents'] ?? [],
    '#contexts' => $cached_values['ai_contexts'] ?? [],
  ],
];
```

#### 5.2.2 Plan Metrics Display

```php
$form['plan_metrics'] = [
  '#type' => 'container',
  '#attributes' => ['class' => ['cpw-plan-metrics']],
  'reading_time' => [
    '#markup' => $this->t('Estimated reading time: @time minutes', [
      '@time' => $plan['metrics']['reading_time'],
    ]),
  ],
  'word_count' => [
    '#markup' => $this->t('Word count: ~@count words', [
      '@count' => $plan['metrics']['word_count'],
    ]),
  ],
  'seo_score' => [
    '#theme' => 'progress_bar',
    '#percent' => $plan['metrics']['seo_score'],
    '#label' => $this->t('SEO score'),
  ],
];
```

#### 5.2.3 Refinement Textarea

| Property | Value |
|----------|-------|
| Element Type | `textarea` |
| Field Name | `refinement_instructions` |
| Rows | 4 |
| Placeholder | "Type instructions to adjust the plan..." |

```php
$form['refinement_instructions'] = [
  '#type' => 'textarea',
  '#title' => $this->t('Refine the plan'),
  '#title_display' => 'invisible',
  '#rows' => 4,
  '#placeholder' => $this->t('Type instructions to adjust the plan...'),
  '#attributes' => [
    'class' => ['cpw-refinement-input'],
  ],
];
```

#### 5.2.4 Regenerate Button

| Property | Value |
|----------|-------|
| Element Type | `button` |
| Field Name | `regenerate_plan` |
| AJAX Callback | `::regeneratePlan` |

```php
$form['regenerate_plan'] = [
  '#type' => 'button',
  '#value' => $this->t('Regenerate Plan'),
  '#ajax' => [
    'callback' => '::regeneratePlan',
    'wrapper' => 'content-plan-wrapper',
    'progress' => [
      'type' => 'throbber',
      'message' => $this->t('Regenerating plan with AI...'),
    ],
  ],
  '#attributes' => [
    'class' => ['cpw-regenerate-btn'],
  ],
  '#states' => [
    'enabled' => [
      ':input[name="refinement_instructions"]' => ['filled' => TRUE],
    ],
  ],
];
```

### 5.3 Content Plan Data Structure

```php
/**
 * Content Plan structure returned by AiPlanGeneratorService.
 */
$plan = [
  'title' => 'Generated Page Title',
  'sections' => [
    [
      'id' => 'section_1',
      'title' => 'Introduction Section',
      'description' => 'Overview from document1.docx',
      'source_documents' => ['document1.docx'],
      'key_points' => [
        'Key point 1 extracted',
        'Key point 2 extracted',
      ],
      'suggested_components' => [
        ['type' => 'sdc.theme.hero', 'label' => 'Hero'],
        ['type' => 'sdc.theme.text_block', 'label' => 'Text Block'],
      ],
      'content_preview' => 'Lorem ipsum dolor sit amet...',
    ],
    // Additional sections...
  ],
  'metrics' => [
    'reading_time' => 5,
    'word_count' => 1200,
    'seo_score' => 85,
    'accessibility_score' => 92,
  ],
  'canvas_tree' => [
    // Pre-generated Canvas component tree structure
  ],
];
```

### 5.4 Step 2 AJAX Behaviors

| Trigger | Event | Callback | Wrapper | Effect |
|---------|-------|----------|---------|--------|
| Regenerate button | `click` | `regeneratePlan` | `content-plan-wrapper` | Regenerate with refinements |
| Plan loaded | `load` | - | - | Animate section appearance |
| Section expand | `click` | `expandSection` | `section-details-{id}` | Show section details |

### 5.5 AI Integration

```php
/**
 * Service method for plan generation.
 */
public function generatePlan(array $documents, array $contexts, ?string $template, ?string $refinement = NULL): array {
  // Build prompt with contexts
  $prompt = $this->buildPrompt($documents, $contexts, $template);

  if ($refinement) {
    $prompt .= "\n\nUser refinement request: " . $refinement;
  }

  // Call AI module
  $response = $this->aiProvider->chat()
    ->setSystemPrompt($this->getSystemPrompt())
    ->addUserMessage($prompt)
    ->generate();

  return $this->parseAiResponse($response);
}
```

---

## 6. Step 3: Final Preview

### 6.1 Visual Layout

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Content Preparation Wizard                                   Step 3 of 3│
│  ═══════════════════════════════════════════════════════════════════════│
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │ [Preview Icon] Final Content Preview                              │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │ ┌─────────────────────────────────────────────────────────────┐  │  │
│  │ │                                                             │  │  │
│  │ │  ╔═══════════════════════════════════════════════════════╗ │  │  │
│  │ │  ║                                                       ║ │  │  │
│  │ │  ║             [HERO COMPONENT]                          ║ │  │  │
│  │ │  ║                                                       ║ │  │  │
│  │ │  ║     Generated Page Title                              ║ │  │  │
│  │ │  ║     Subtitle from document analysis                   ║ │  │  │
│  │ │  ║                                                       ║ │  │  │
│  │ │  ╚═══════════════════════════════════════════════════════╝ │  │  │
│  │ │                                                             │  │  │
│  │ │  ┌─────────────────────────────────────────────────────┐   │  │  │
│  │ │  │                                                     │   │  │  │
│  │ │  │  [TEXT BLOCK COMPONENT]                             │   │  │  │
│  │ │  │                                                     │   │  │  │
│  │ │  │  Introduction content generated from your           │   │  │  │
│  │ │  │  documents with AI assistance...                    │   │  │  │
│  │ │  │                                                     │   │  │  │
│  │ │  └─────────────────────────────────────────────────────┘   │  │  │
│  │ │                                                             │  │  │
│  │ │  ┌─────────────────────────────────────────────────────┐   │  │  │
│  │ │  │  [ACCORDION COMPONENT]                              │   │  │  │
│  │ │  │  ▼ Section 1: Technical Details                     │   │  │  │
│  │ │  │  ► Section 2: Supporting Information                │   │  │  │
│  │ │  │  ► Section 3: Additional Resources                  │   │  │  │
│  │ │  └─────────────────────────────────────────────────────┘   │  │  │
│  │ │                                                             │  │  │
│  │ └─────────────────────────────────────────────────────────────┘  │  │
│  │                                                                   │  │
│  │  [Desktop]  [Tablet]  [Mobile]          Zoom: [100% ▼]           │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │ Target:                                                           │  │
│  │ ┌─────────────────────────────────────────────────────────────┐  │  │
│  │ │ Canvas page type                                         ▼ │  │  │
│  │ └─────────────────────────────────────────────────────────────┘  │  │
│  │                                                                   │  │
│  │ Page title:                                                       │  │
│  │ ┌─────────────────────────────────────────────────────────────┐  │  │
│  │ │ Generated Page Title                                        │  │  │
│  │ └─────────────────────────────────────────────────────────────┘  │  │
│  │                                                                   │  │
│  │ URL alias:                                                        │  │
│  │ ┌─────────────────────────────────────────────────────────────┐  │  │
│  │ │ /generated-page-title                                       │  │  │
│  │ └─────────────────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  ┌─────────────┐                              ┌───────────────────────┐│
│  │  ← Back     │                              │ Create Canvas Page Now ││
│  └─────────────┘                              └───────────────────────┘│
└─────────────────────────────────────────────────────────────────────────┘
```

### 6.2 Form Elements Specification

#### 6.2.1 Preview Container

| Property | Value |
|----------|-------|
| Element Type | `container` |
| Field Name | `preview_container` |
| Theme | `canvas_preview` |

```php
$form['preview_container'] = [
  '#type' => 'container',
  '#attributes' => [
    'id' => 'canvas-preview-wrapper',
    'class' => ['cpw-preview-container'],
  ],
  'preview_frame' => [
    '#type' => 'inline_template',
    '#template' => '<div class="cpw-preview-frame" data-viewport="{{ viewport }}">
      <iframe src="{{ preview_url }}" title="{{ title }}"></iframe>
    </div>',
    '#context' => [
      'preview_url' => $this->getPreviewUrl($cached_values),
      'viewport' => 'desktop',
      'title' => $this->t('Canvas page preview'),
    ],
  ],
  'viewport_controls' => [
    '#type' => 'radios',
    '#title' => $this->t('Preview viewport'),
    '#title_display' => 'invisible',
    '#options' => [
      'desktop' => $this->t('Desktop'),
      'tablet' => $this->t('Tablet'),
      'mobile' => $this->t('Mobile'),
    ],
    '#default_value' => 'desktop',
    '#attributes' => ['class' => ['cpw-viewport-controls']],
  ],
];
```

#### 6.2.2 Target Page Type Select

| Property | Value |
|----------|-------|
| Element Type | `select` |
| Field Name | `target_page_type` |
| Options | Canvas page bundles |

```php
$form['target_page_type'] = [
  '#type' => 'select',
  '#title' => $this->t('Target'),
  '#description' => $this->t('Select the Canvas page type for the generated content.'),
  '#options' => $this->getCanvasPageTypes(),
  '#required' => TRUE,
  '#default_value' => $cached_values['target_page_type'] ?? 'canvas_page',
];
```

#### 6.2.3 Page Title Field

| Property | Value |
|----------|-------|
| Element Type | `textfield` |
| Field Name | `page_title` |
| Max Length | 255 |

```php
$form['page_title'] = [
  '#type' => 'textfield',
  '#title' => $this->t('Page title'),
  '#required' => TRUE,
  '#maxlength' => 255,
  '#default_value' => $cached_values['generated_title'] ?? '',
];
```

#### 6.2.4 URL Alias Field

| Property | Value |
|----------|-------|
| Element Type | `path` |
| Field Name | `url_alias` |

```php
$form['url_alias'] = [
  '#type' => 'textfield',
  '#title' => $this->t('URL alias'),
  '#description' => $this->t('Optionally specify an alternative URL path.'),
  '#default_value' => $this->pathAutoGenerator->generateAlias(
    $cached_values['generated_title'] ?? ''
  ),
  '#field_prefix' => $this->requestContext->getCompleteBaseUrl() . '/',
];
```

#### 6.2.5 Create Button

```php
$form['actions']['submit'] = [
  '#type' => 'submit',
  '#value' => $this->t('Create Canvas Page Now'),
  '#button_type' => 'primary',
  '#attributes' => [
    'class' => ['cpw-create-btn'],
  ],
];
```

### 6.3 Preview Rendering

The preview uses Canvas's built-in preview mechanism with a temporary component tree.

```php
/**
 * Generate preview URL for the wizard.
 */
protected function getPreviewUrl(array $cached_values): string {
  // Store temporary component tree
  $preview_id = $this->tempStore->set('preview_' . $this->getMachineName(), [
    'component_tree' => $cached_values['canvas_tree'],
    'page_type' => $cached_values['target_page_type'],
  ]);

  return Url::fromRoute('content_preparation_wizard.preview', [
    'preview_id' => $preview_id,
  ])->toString();
}
```

### 6.4 Canvas Page Creation

```php
/**
 * Create the Canvas page from wizard data.
 */
protected function createCanvasPage(array $cached_values): EntityInterface {
  $page_type = $cached_values['target_page_type'];
  $storage = $this->entityTypeManager->getStorage('canvas_page');

  $page = $storage->create([
    'type' => $page_type,
    'title' => $cached_values['page_title'],
    'path' => ['alias' => $cached_values['url_alias']],
    'field_canvas' => $this->buildComponentTreeField($cached_values['canvas_tree']),
    'status' => FALSE, // Draft by default
  ]);

  $page->save();

  return $page;
}
```

---

## 7. AJAX Interactions

### 7.1 AJAX Response Handling

All AJAX interactions follow Drupal's standard AJAX framework with proper response commands.

```php
/**
 * AJAX callback for file upload.
 */
public function uploadFileCallback(array &$form, FormStateInterface $form_state): AjaxResponse {
  $response = new AjaxResponse();

  // Get uploaded files info
  $files = $form_state->getValue('source_documents');

  // Update file list
  $response->addCommand(new ReplaceCommand(
    '#file-list-wrapper',
    $this->renderFileList($files)
  ));

  // Show success message
  $response->addCommand(new MessageCommand(
    $this->t('File uploaded successfully.'),
    NULL,
    ['type' => 'status']
  ));

  return $response;
}
```

### 7.2 AJAX Commands Used

| Command | Purpose | Example Usage |
|---------|---------|---------------|
| `ReplaceCommand` | Replace element content | Update file list, plan display |
| `AppendCommand` | Add content to element | Add new file to list |
| `RemoveCommand` | Remove element | Remove file from list |
| `InvokeCommand` | Call JavaScript method | Trigger animations |
| `MessageCommand` | Show status message | Success/error feedback |
| `RedirectCommand` | Navigate to URL | After page creation |
| `SettingsCommand` | Update drupalSettings | Pass data to JS |

### 7.3 AJAX Error Handling

```php
/**
 * Handle AJAX errors gracefully.
 */
protected function handleAjaxError(\Exception $e, AjaxResponse $response): AjaxResponse {
  $this->logger->error('Wizard AJAX error: @message', ['@message' => $e->getMessage()]);

  $response->addCommand(new MessageCommand(
    $this->t('An error occurred. Please try again.'),
    NULL,
    ['type' => 'error']
  ));

  // Re-enable form
  $response->addCommand(new InvokeCommand(
    '.cpw-form',
    'removeClass',
    ['is-loading']
  ));

  return $response;
}
```

### 7.4 Progress Indicators

```php
// Throbber for quick operations
'#ajax' => [
  'progress' => [
    'type' => 'throbber',
    'message' => $this->t('Processing...'),
  ],
],

// Progress bar for long operations (AI generation)
'#ajax' => [
  'progress' => [
    'type' => 'bar',
    'url' => Url::fromRoute('content_preparation_wizard.progress', [
      'wizard_id' => $this->getMachineName(),
    ]),
    'interval' => 500,
    'message' => $this->t('Generating content plan with AI...'),
  ],
],
```

---

## 8. Form API Implementation

### 8.1 Wizard Base Class

```php
<?php

namespace Drupal\content_preparation_wizard\Form;

use Drupal\ctools\Wizard\FormWizardBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Content Preparation Wizard main form.
 */
class ContentPreparationWizard extends FormWizardBase {

  /**
   * {@inheritdoc}
   */
  public function getWizardLabel(): string {
    return $this->t('Content Preparation Wizard');
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineLabel(): string {
    return $this->t('Wizard ID');
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations($cached_values): array {
    return [
      'upload' => [
        'form' => DocumentUploadForm::class,
        'title' => $this->t('Upload Documents'),
      ],
      'review' => [
        'form' => AiPlanReviewForm::class,
        'title' => $this->t('Review Plan'),
      ],
      'preview' => [
        'form' => FinalPreviewForm::class,
        'title' => $this->t('Final Preview'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteName(): string {
    return 'content_preparation_wizard.wizard';
  }

  /**
   * {@inheritdoc}
   */
  protected function customizeForm(array $form, FormStateInterface $form_state): array {
    $form = parent::customizeForm($form, $form_state);

    // Add wizard-specific CSS and JS
    $form['#attached']['library'][] = 'content_preparation_wizard/wizard';

    // Add step indicator
    $cached_values = $form_state->getTemporaryValue('wizard');
    $form['step_indicator'] = [
      '#theme' => 'wizard_step_indicator',
      '#steps' => $this->getOperations($cached_values),
      '#current_step' => $this->getStep($cached_values),
      '#weight' => -100,
    ];

    return $form;
  }
}
```

### 8.2 Step Form Implementation Pattern

```php
<?php

namespace Drupal\content_preparation_wizard\Form\Step;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Document upload step form.
 */
class DocumentUploadForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cpw_document_upload_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $cached_values = $form_state->getTemporaryValue('wizard');

    // File upload element
    $form['source_documents'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Source Documents'),
      '#upload_location' => 'private://content_preparation_wizard/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'txt docx pdf'],
        'FileSizeLimit' => ['fileLimit' => 10 * 1024 * 1024],
      ],
      '#multiple' => TRUE,
      '#required' => TRUE,
      '#default_value' => $cached_values['source_documents'] ?? [],
    ];

    // AI contexts
    $form['ai_contexts'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('AI Contexts'),
      '#options' => $this->getContextOptions(),
      '#default_value' => $cached_values['ai_contexts'] ?? ['site_structure', 'brand_guidelines'],
    ];

    // AI template
    $form['ai_template'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Template'),
      '#options' => $this->getTemplateOptions(),
      '#required' => TRUE,
      '#default_value' => $cached_values['ai_template'] ?? NULL,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $files = $form_state->getValue('source_documents');

    if (empty($files)) {
      $form_state->setErrorByName('source_documents', $this->t('Please upload at least one document.'));
    }

    // Validate file processing
    foreach ($files as $fid) {
      if (!$this->documentParser->canProcess($fid)) {
        $form_state->setErrorByName('source_documents',
          $this->t('Unable to process file @file.', ['@file' => $fid]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $cached_values = $form_state->getTemporaryValue('wizard');

    // Store form values
    $cached_values['source_documents'] = $form_state->getValue('source_documents');
    $cached_values['ai_contexts'] = array_filter($form_state->getValue('ai_contexts'));
    $cached_values['ai_template'] = $form_state->getValue('ai_template');

    // Process documents
    $cached_values['processed_documents'] = $this->documentParser->processDocuments(
      $cached_values['source_documents']
    );

    $form_state->setTemporaryValue('wizard', $cached_values);
  }
}
```

### 8.3 Routing Configuration

```yaml
# content_preparation_wizard.routing.yml

content_preparation_wizard.wizard:
  path: '/admin/content/prepare/{machine_name}/{step}'
  defaults:
    _wizard: '\Drupal\content_preparation_wizard\Form\ContentPreparationWizard'
    _title: 'Content Preparation Wizard'
    machine_name: NULL
    step: NULL
  requirements:
    _permission: 'use content preparation wizard'
  options:
    parameters:
      machine_name:
        type: 'string'
      step:
        type: 'string'

content_preparation_wizard.start:
  path: '/admin/content/prepare'
  defaults:
    _controller: '\Drupal\content_preparation_wizard\Controller\WizardController::start'
    _title: 'Prepare Content'
  requirements:
    _permission: 'use content preparation wizard'

content_preparation_wizard.preview:
  path: '/admin/content/prepare/preview/{preview_id}'
  defaults:
    _controller: '\Drupal\content_preparation_wizard\Controller\WizardController::preview'
  requirements:
    _permission: 'use content preparation wizard'

content_preparation_wizard.progress:
  path: '/admin/content/prepare/progress/{wizard_id}'
  defaults:
    _controller: '\Drupal\content_preparation_wizard\Controller\WizardController::progress'
  requirements:
    _permission: 'use content preparation wizard'
```

---

## 9. JavaScript Components

### 9.1 Main Wizard JavaScript

```javascript
/**
 * @file
 * Content Preparation Wizard JavaScript behaviors.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Initialize wizard behaviors.
   */
  Drupal.behaviors.contentPreparationWizard = {
    attach: function (context, settings) {
      // Initialize file upload drag-drop
      once('cpw-file-upload', '.cpw-file-upload', context).forEach(function (element) {
        Drupal.contentPreparationWizard.initDragDrop(element);
      });

      // Initialize viewport controls
      once('cpw-viewport', '.cpw-viewport-controls', context).forEach(function (element) {
        Drupal.contentPreparationWizard.initViewportControls(element);
      });

      // Initialize plan section toggles
      once('cpw-section-toggle', '.cpw-plan-section', context).forEach(function (element) {
        Drupal.contentPreparationWizard.initSectionToggle(element);
      });
    }
  };

  /**
   * Wizard namespace.
   */
  Drupal.contentPreparationWizard = {

    /**
     * Initialize drag and drop file upload.
     */
    initDragDrop: function (element) {
      const dropZone = element.querySelector('.cpw-dropzone');

      if (!dropZone) return;

      ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, this.preventDefaults, false);
      });

      ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
          dropZone.classList.add('is-dragover');
        }, false);
      });

      ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
          dropZone.classList.remove('is-dragover');
        }, false);
      });

      dropZone.addEventListener('drop', (e) => {
        const files = e.dataTransfer.files;
        this.handleFiles(files, element);
      }, false);
    },

    /**
     * Prevent default drag behaviors.
     */
    preventDefaults: function (e) {
      e.preventDefault();
      e.stopPropagation();
    },

    /**
     * Handle dropped files.
     */
    handleFiles: function (files, element) {
      const fileInput = element.querySelector('input[type="file"]');

      // Create a new DataTransfer to set files
      const dataTransfer = new DataTransfer();
      Array.from(files).forEach(file => {
        dataTransfer.items.add(file);
      });

      fileInput.files = dataTransfer.files;

      // Trigger change event
      const event = new Event('change', { bubbles: true });
      fileInput.dispatchEvent(event);
    },

    /**
     * Initialize viewport controls for preview.
     */
    initViewportControls: function (element) {
      const radios = element.querySelectorAll('input[type="radio"]');
      const previewFrame = document.querySelector('.cpw-preview-frame');

      radios.forEach(radio => {
        radio.addEventListener('change', (e) => {
          const viewport = e.target.value;
          previewFrame.setAttribute('data-viewport', viewport);

          // Update frame dimensions
          const dimensions = {
            desktop: { width: '100%', maxWidth: 'none' },
            tablet: { width: '768px', maxWidth: '768px' },
            mobile: { width: '375px', maxWidth: '375px' }
          };

          Object.assign(previewFrame.style, {
            width: dimensions[viewport].width,
            maxWidth: dimensions[viewport].maxWidth
          });
        });
      });
    },

    /**
     * Initialize section toggle for plan review.
     */
    initSectionToggle: function (element) {
      const header = element.querySelector('.cpw-section-header');
      const content = element.querySelector('.cpw-section-content');

      header.addEventListener('click', () => {
        const isExpanded = element.classList.contains('is-expanded');
        element.classList.toggle('is-expanded');
        header.setAttribute('aria-expanded', !isExpanded);
        content.setAttribute('aria-hidden', isExpanded);
      });
    }
  };

})(Drupal, drupalSettings, once);
```

### 9.2 File Upload JavaScript

```javascript
/**
 * @file
 * File upload progress and validation.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * File upload behaviors.
   */
  Drupal.behaviors.cpwFileUpload = {
    attach: function (context, settings) {
      const uploadInputs = context.querySelectorAll('.cpw-file-upload input[type="file"]');

      uploadInputs.forEach(input => {
        input.addEventListener('change', this.validateFiles.bind(this));
      });
    },

    /**
     * Validate files before upload.
     */
    validateFiles: function (e) {
      const files = e.target.files;
      const allowedExtensions = ['txt', 'docx', 'pdf'];
      const maxSize = 10 * 1024 * 1024; // 10MB
      const errors = [];

      Array.from(files).forEach(file => {
        const ext = file.name.split('.').pop().toLowerCase();

        if (!allowedExtensions.includes(ext)) {
          errors.push(Drupal.t('Invalid file type: @name', { '@name': file.name }));
        }

        if (file.size > maxSize) {
          errors.push(Drupal.t('File too large: @name', { '@name': file.name }));
        }
      });

      if (errors.length > 0) {
        e.preventDefault();
        Drupal.announce(errors.join(' '), 'assertive');

        const messageArea = document.querySelector('.cpw-messages');
        if (messageArea) {
          messageArea.innerHTML = errors.map(err =>
            `<div class="messages messages--error">${err}</div>`
          ).join('');
        }
      }
    }
  };

})(Drupal, drupalSettings);
```

### 9.3 Library Definition

```yaml
# content_preparation_wizard.libraries.yml

wizard:
  version: VERSION
  css:
    component:
      css/wizard.css: {}
  js:
    js/wizard.js: {}
    js/file-upload.js: {}
  dependencies:
    - core/drupal
    - core/drupalSettings
    - core/once
    - core/drupal.announce

plan-refinement:
  version: VERSION
  js:
    js/plan-refinement.js: {}
  dependencies:
    - content_preparation_wizard/wizard
    - core/drupal.ajax

preview:
  version: VERSION
  js:
    js/preview.js: {}
  dependencies:
    - content_preparation_wizard/wizard
```

---

## 10. Accessibility Requirements

### 10.1 WCAG 2.1 AA Compliance

| Criterion | Requirement | Implementation |
|-----------|-------------|----------------|
| 1.1.1 Non-text Content | All images have alt text | Icon fonts have aria-label |
| 1.3.1 Info and Relationships | Proper heading hierarchy | Use h2 for step titles |
| 1.4.3 Contrast | 4.5:1 minimum ratio | CSS custom properties |
| 2.1.1 Keyboard | All functionality keyboard accessible | Tab order, focus management |
| 2.4.4 Link Purpose | Links describe destination | Button text is descriptive |
| 4.1.2 Name, Role, Value | ARIA attributes for custom widgets | aria-expanded, aria-hidden |

### 10.2 Keyboard Navigation

| Key | Action | Context |
|-----|--------|---------|
| Tab | Move to next focusable element | All steps |
| Shift+Tab | Move to previous focusable element | All steps |
| Enter | Activate button/submit | Buttons, links |
| Space | Toggle checkbox, expand section | Checkboxes, accordions |
| Escape | Cancel current operation | Modals, dialogs |
| Arrow keys | Navigate radio options | Viewport controls |

### 10.3 Screen Reader Announcements

```php
// Announce file upload success
$response->addCommand(new InvokeCommand(
  NULL,
  'drupalAnnounce',
  [$this->t('File @name uploaded successfully.', ['@name' => $filename])]
));

// Announce plan generation progress
$response->addCommand(new InvokeCommand(
  NULL,
  'drupalAnnounce',
  [$this->t('Content plan generated. Review the @count sections below.', ['@count' => $section_count])],
  'polite'
));

// Announce errors
$response->addCommand(new InvokeCommand(
  NULL,
  'drupalAnnounce',
  [$this->t('Error: @message', ['@message' => $error])],
  'assertive'
));
```

### 10.4 Focus Management

```javascript
/**
 * Manage focus after AJAX operations.
 */
Drupal.contentPreparationWizard.manageFocus = function (targetSelector, delay) {
  delay = delay || 100;

  setTimeout(function () {
    const target = document.querySelector(targetSelector);
    if (target) {
      target.focus();
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }, delay);
};

// Usage in AJAX callbacks
$(document).ajaxComplete(function (event, xhr, settings) {
  if (settings.url && settings.url.includes('content-preparation-wizard')) {
    // Focus first error or next step
    const firstError = document.querySelector('.form-item--error-message');
    const nextStep = document.querySelector('.cpw-step.is-current');

    if (firstError) {
      Drupal.contentPreparationWizard.manageFocus('.form-item--error-message');
    } else if (nextStep) {
      Drupal.contentPreparationWizard.manageFocus('.cpw-step.is-current h2');
    }
  }
});
```

---

## 11. Error Handling

### 11.1 Error Types and Messages

| Error Type | User Message | Technical Action |
|------------|--------------|------------------|
| File too large | "The file @name exceeds the maximum size of 10MB." | Prevent upload, show error |
| Invalid file type | "The file type @type is not supported. Please use TXT, DOCX, or PDF." | Prevent upload, show error |
| AI service unavailable | "The AI service is temporarily unavailable. Please try again in a few minutes." | Log error, show retry button |
| AI generation failed | "Unable to generate content plan. Please check your documents and try again." | Log error, show support link |
| Canvas creation failed | "Unable to create the page. Please contact your administrator." | Log error, preserve wizard state |
| Session expired | "Your session has expired. Please start the wizard again." | Clear tempstore, redirect |
| Permission denied | "You do not have permission to perform this action." | Log attempt, show message |

### 11.2 Validation Error Display

```php
/**
 * Custom error handler for wizard forms.
 */
protected function displayErrors(FormStateInterface $form_state): void {
  $errors = $form_state->getErrors();

  if (empty($errors)) {
    return;
  }

  // Group errors by field
  $grouped = [];
  foreach ($errors as $name => $message) {
    $field_name = explode('][', $name)[0];
    $grouped[$field_name][] = $message;
  }

  // Add error summary
  $this->messenger()->addError($this->t('Please correct the following errors:'));

  foreach ($grouped as $field => $messages) {
    foreach ($messages as $message) {
      $this->messenger()->addError($message);
    }
  }
}
```

### 11.3 Recovery Strategies

```php
/**
 * Handle wizard state recovery.
 */
public function recoverWizardState(string $machine_name): ?array {
  try {
    $cached_values = $this->tempStore->get($machine_name);

    if (!$cached_values) {
      // Try to recover from backup
      $backup = $this->tempStore->get($machine_name . '_backup');
      if ($backup) {
        $this->tempStore->set($machine_name, $backup);
        return $backup;
      }
      return NULL;
    }

    // Validate cached values are still valid
    if (!$this->validateCachedValues($cached_values)) {
      $this->logger->warning('Invalid cached values for wizard @id', ['@id' => $machine_name]);
      return NULL;
    }

    return $cached_values;

  } catch (\Exception $e) {
    $this->logger->error('Error recovering wizard state: @message', ['@message' => $e->getMessage()]);
    return NULL;
  }
}
```

---

## 12. Performance Considerations

### 12.1 File Upload Optimization

| Strategy | Implementation |
|----------|----------------|
| Chunked uploads | Split large files into 1MB chunks |
| Client-side validation | Validate before upload starts |
| Parallel processing | Process multiple files concurrently |
| Compression | Accept gzip-compressed uploads |

### 12.2 AI Request Optimization

```php
/**
 * Optimized AI request handling.
 */
public function generatePlanAsync(array $documents, array $contexts): string {
  // Create batch job for long-running AI requests
  $batch_id = $this->batchManager->create([
    'operation' => 'ai_plan_generation',
    'data' => [
      'documents' => $documents,
      'contexts' => $contexts,
    ],
  ]);

  // Return batch ID for progress polling
  return $batch_id;
}

/**
 * Poll for AI generation progress.
 */
public function checkProgress(string $batch_id): array {
  $batch = $this->batchManager->get($batch_id);

  return [
    'status' => $batch->getStatus(),
    'progress' => $batch->getProgress(),
    'message' => $batch->getMessage(),
    'result' => $batch->isComplete() ? $batch->getResult() : NULL,
  ];
}
```

### 12.3 Caching Strategy

| Data | Cache Bin | TTL | Invalidation |
|------|-----------|-----|--------------|
| AI templates | `data` | 1 hour | On template update |
| Context plugins | `discovery` | 1 day | On cache rebuild |
| Parsed documents | `wizard_temp` | Session | On wizard complete |
| Generated plans | `wizard_temp` | Session | On regenerate |

### 12.4 Asset Loading

```yaml
# Lazy load preview assets
preview:
  version: VERSION
  js:
    js/preview.js: { preprocess: false }
  dependencies:
    - content_preparation_wizard/wizard
  # Load only when needed
```

```php
// Conditionally attach libraries
if ($this->getStep($cached_values) === 'preview') {
  $form['#attached']['library'][] = 'content_preparation_wizard/preview';
}
```

---

## 13. Security Considerations

### 13.1 Input Validation

| Input | Validation | Sanitization |
|-------|------------|--------------|
| Uploaded files | Extension, MIME type, size, content scan | None (binary) |
| Refinement text | Length limit (5000 chars) | `Xss::filter()` |
| Page title | Length limit (255 chars) | `Html::escape()` |
| URL alias | Path format, uniqueness | `PathValidator::validate()` |
| AI template | Valid plugin ID | Enum check |

### 13.2 File Security

```php
/**
 * Validate uploaded file security.
 */
protected function validateFileSecurity(FileInterface $file): bool {
  // Check MIME type matches extension
  $actual_mime = $this->mimeTypeGuesser->guessMimeType($file->getFileUri());
  $expected_mime = $this->getMimeTypeForExtension($file->getFilename());

  if ($actual_mime !== $expected_mime) {
    $this->logger->warning('MIME type mismatch for file @file', ['@file' => $file->getFilename()]);
    return FALSE;
  }

  // Scan for malicious content (if antivirus available)
  if ($this->antivirusScanner && !$this->antivirusScanner->scan($file->getFileUri())) {
    $this->logger->error('Malicious content detected in file @file', ['@file' => $file->getFilename()]);
    return FALSE;
  }

  return TRUE;
}
```

### 13.3 CSRF Protection

All forms automatically include Drupal's CSRF token via Form API. AJAX requests include the token in headers.

```javascript
// AJAX requests include CSRF token
Drupal.ajax.prototype.beforeSend = function (xmlhttprequest, options) {
  xmlhttprequest.setRequestHeader('X-CSRF-Token', drupalSettings.csrfToken);
};
```

### 13.4 Permission Checks

```php
/**
 * Access check for wizard operations.
 */
public function access(AccountInterface $account, string $operation = 'view'): AccessResultInterface {
  $permissions = [
    'view' => 'use content preparation wizard',
    'create' => 'create canvas content',
    'ai' => 'use ai services',
  ];

  $required = $permissions[$operation] ?? $permissions['view'];

  return AccessResult::allowedIfHasPermission($account, $required)
    ->cachePerPermissions();
}
```

---

## 14. Appendices

### 14.1 API Reference

#### Services

| Service ID | Class | Description |
|------------|-------|-------------|
| `content_preparation_wizard.parser` | `DocumentParserService` | Parse uploaded documents |
| `content_preparation_wizard.ai_generator` | `AiPlanGeneratorService` | Generate content plans |
| `content_preparation_wizard.canvas_builder` | `CanvasPageBuilderService` | Build Canvas pages |
| `content_preparation_wizard.context_provider` | `ContextProviderService` | Provide AI contexts |

#### Events

| Event Name | Event Class | Triggered |
|------------|-------------|-----------|
| `cpw.documents.processed` | `DocumentsProcessedEvent` | After file parsing |
| `cpw.plan.generated` | `PlanGeneratedEvent` | After AI plan creation |
| `cpw.plan.refined` | `PlanRefinedEvent` | After plan regeneration |
| `cpw.page.created` | `PageCreatedEvent` | After Canvas page saved |

### 14.2 Configuration Schema

```yaml
# config/schema/content_preparation_wizard.schema.yml

content_preparation_wizard.settings:
  type: config_object
  label: 'Content Preparation Wizard settings'
  mapping:
    max_file_size:
      type: integer
      label: 'Maximum file size in bytes'
    allowed_extensions:
      type: sequence
      label: 'Allowed file extensions'
      sequence:
        type: string
    default_contexts:
      type: sequence
      label: 'Default AI contexts'
      sequence:
        type: string
    ai_timeout:
      type: integer
      label: 'AI request timeout in seconds'
    default_page_type:
      type: string
      label: 'Default Canvas page type'
```

### 14.3 Template Variables

#### wizard-step-indicator.html.twig

| Variable | Type | Description |
|----------|------|-------------|
| `steps` | array | List of wizard steps |
| `current_step` | string | Current step machine name |
| `completed_steps` | array | List of completed step names |

#### content-plan-display.html.twig

| Variable | Type | Description |
|----------|------|-------------|
| `plan` | array | Generated content plan |
| `documents` | array | Source document info |
| `sections` | array | Plan sections |
| `metrics` | array | Plan metrics (word count, etc.) |

#### canvas-preview.html.twig

| Variable | Type | Description |
|----------|------|-------------|
| `preview_url` | string | URL to preview iframe |
| `viewport` | string | Current viewport (desktop/tablet/mobile) |
| `page_title` | string | Generated page title |

### 14.4 Hooks

```php
/**
 * Alter available AI contexts.
 *
 * @param array &$contexts
 *   Array of context plugin definitions.
 */
function hook_content_preparation_wizard_contexts_alter(array &$contexts): void {
  // Add custom context
  $contexts['custom_context'] = [
    'id' => 'custom_context',
    'label' => t('Custom Context'),
    'class' => CustomContext::class,
  ];
}

/**
 * Alter the generated content plan.
 *
 * @param array &$plan
 *   The generated content plan.
 * @param array $context
 *   Context including documents and settings.
 */
function hook_content_preparation_wizard_plan_alter(array &$plan, array $context): void {
  // Modify plan sections
  foreach ($plan['sections'] as &$section) {
    $section['custom_field'] = 'custom_value';
  }
}

/**
 * React to Canvas page creation.
 *
 * @param \Drupal\canvas\Entity\CanvasPageInterface $page
 *   The created Canvas page.
 * @param array $wizard_data
 *   Data from the wizard.
 */
function hook_content_preparation_wizard_page_created(CanvasPageInterface $page, array $wizard_data): void {
  // Send notification, log analytics, etc.
}
```

### 14.5 Drush Commands

```php
/**
 * Drush commands for Content Preparation Wizard.
 */
class ContentPreparationWizardCommands extends DrushCommands {

  /**
   * Clear all wizard temporary data.
   *
   * @command cpw:clear
   * @aliases cpw-clear
   */
  public function clearWizardData(): void {
    $this->tempStoreFactory->get('content_preparation_wizard')->deleteAll();
    $this->logger()->success(dt('Wizard temporary data cleared.'));
  }

  /**
   * List available AI templates.
   *
   * @command cpw:templates
   * @aliases cpw-templates
   */
  public function listTemplates(): void {
    $templates = $this->templateManager->getDefinitions();
    foreach ($templates as $id => $template) {
      $this->io()->writeln("[$id] {$template['label']}");
    }
  }
}
```

---

## Document Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-27 | AI Assistant | Initial specification |

---

**End of Document**
