# AI Content Preparation Wizard

## Transform Documents into Structured Content — Instantly

The **AI Content Preparation Wizard** is a powerful Drupal module that bridges the gap between raw documents and polished, structured web content. Upload your files, scrape content from any URL, let AI analyze and plan your content, and generate ready-to-publish Canvas pages — all in three simple steps.

---

## The Problem

Content teams face a constant challenge: turning documents, PDFs, and markdown files into structured web content. The manual process involves:

- Reading and analyzing documents
- Planning content structure
- Mapping content to components
- Creating pages with proper hierarchy
- Formatting and styling

**This takes hours. The AI Content Preparation Wizard does it in minutes.**

---

## Key Features

### 1. Intelligent Document Processing

Upload any document and the wizard extracts, processes, and understands your content automatically.

| Supported Formats | Processing Method |
|-------------------|-------------------|
| Markdown (.md) | Native parsing with metadata extraction |
| Plain Text (.txt) | Direct text extraction |
| Word Documents (.docx) | Pandoc conversion to Markdown |
| PDF Files (.pdf) | Text extraction with layout preservation |

**Extensible Plugin Architecture**: Add support for new formats by creating custom `DocumentProcessor` plugins. The module discovers and loads processors automatically.

---

### 2. AI-Powered Content Planning

The wizard leverages your site's AI provider to analyze documents and generate structured content plans:

- **Smart Section Detection**: AI identifies logical content sections, headings, and hierarchy
- **Component Suggestions**: Each section is mapped to appropriate Canvas components (hero, text, list, CTA, etc.)
- **Metadata Generation**: Automatic word count, read time estimation, and audience targeting
- **Summary Creation**: AI-generated summaries capture the essence of your content

---

### 3. AI Contexts Integration

**This is where it gets powerful.** The wizard integrates with Drupal's AI Context system, allowing you to:

- **Apply Brand Guidelines**: Load tone-of-voice and style contexts
- **Target Audiences**: Include audience personas for content optimization
- **Domain Expertise**: Add industry-specific knowledge contexts
- **Custom Instructions**: Pass any context to influence AI decisions

Select one or multiple AI contexts in Step 1, and they're applied throughout the content planning process.

---

### 4. Iterative Plan Refinement

Content plans are rarely perfect on the first try. The wizard supports iterative refinement:

- **Refinement Instructions**: Tell the AI what to change ("make it more casual", "add a FAQ section")
- **Refinement History**: Track all changes with timestamps
- **Configurable Iterations**: Set maximum refinement rounds in settings
- **Side-by-Side Comparison**: View original documents alongside the evolving plan

---

### 5. Canvas Page Generation

Once your plan is approved, create production-ready Canvas pages:

- **Template Support**: Start from existing Canvas pages as templates
- **Component Mapping**: Sections automatically become Canvas components
- **URL Alias Generation**: Clean URLs based on content titles
- **Publish Control**: Save as draft or publish immediately
- **Event Hooks**: `CanvasPageCreatedEvent` for custom integrations

---

### 6. Session Persistence

Work at your own pace with robust session management:

- **Private TempStore**: User-specific sessions isolated securely
- **Step Navigation**: Move freely between steps without losing progress
- **Configurable Timeout**: Set session duration in admin settings
- **Clean Session Handling**: Automatic cleanup after completion

---

## Architecture Highlights

### Clean, Modular Design

The module follows Drupal best practices and modern PHP patterns:

| Pattern | Implementation |
|---------|----------------|
| **Immutable Value Objects** | 8 model classes (ContentPlan, PlanSection, ProcessedDocument, etc.) |
| **Plugin System** | Extensible document processors with auto-discovery |
| **Event-Driven** | 3 dispatched events for integration points |
| **Service Layer** | 7 services with dependency injection |
| **Enum Status Tracking** | Type-safe workflow states |

### File Structure

```
ai_content_preparation_wizard/
├── src/
│   ├── Controller/          # AJAX endpoints
│   ├── Enum/                 # Type-safe status enums
│   ├── Event/                # Dispatched events
│   ├── Exception/            # Custom exceptions
│   ├── Form/                 # Multi-step wizard forms
│   ├── Model/                # Immutable value objects
│   ├── Plugin/               # Document processors
│   ├── PluginManager/        # Plugin discovery
│   └── Service/              # Business logic
├── config/                   # Default settings & schema
├── js/                       # Async plan loading, tabs
└── css/                      # Wizard styling
```

---

## The 3-Step Workflow

### Step 1: Upload

- Drag-and-drop document upload
- Select AI contexts to apply
- Choose Canvas page template (optional)
- Configure processing options

### Step 2: Plan

- View extracted document content in tabs
- Review AI-generated content plan
- Edit sections, titles, and component mappings
- Refine with additional instructions
- Track refinement history

### Step 3: Create

- Final preview of page structure
- Set page title and URL alias
- Choose publish status
- Generate Canvas page with one click

---

## Configuration Options

Administrators have full control via the settings form:

| Setting | Description | Default |
|---------|-------------|---------|
| Pandoc Path | Path to Pandoc binary for DOCX/PDF | `/usr/bin/pandoc` |
| Max File Size | Maximum upload size | 10 MB |
| Allowed Extensions | Permitted file types | txt, md, docx, pdf |
| AI Provider | Override default AI provider | Site default |
| AI Model | Override default model | Provider default |
| Session Timeout | Session duration in seconds | 3600 (1 hour) |
| Enable Refinement | Allow plan refinement | Yes |
| Max Refinements | Maximum refinement iterations | 5 |

---

## Permissions

Granular access control with three permissions:

| Permission | Description |
|------------|-------------|
| `access content preparation wizard` | Use the wizard to create content plans |
| `administer content preparation wizard` | Configure wizard settings (admin only) |
| `create canvas from wizard` | Generate Canvas pages from plans |

---

## Events for Integration

Hook into the wizard workflow with dispatched events:

```php
// Subscribe to document processing
$dispatcher->addListener(
  DocumentProcessedEvent::EVENT_NAME,
  [$this, 'onDocumentProcessed']
);

// Subscribe to Canvas page creation
$dispatcher->addListener(
  CanvasPageCreatedEvent::EVENT_NAME,
  [$this, 'onCanvasCreated']
);

// Subscribe to wizard step changes
$dispatcher->addListener(
  WizardStepChangedEvent::EVENT_NAME,
  [$this, 'onStepChanged']
);
```

---

## Extending the Module

### Add Custom Document Processors

Create a new processor plugin:

```php
#[DocumentProcessor(
  id: 'my_custom_processor',
  label: new TranslatableMarkup('My Custom Format'),
  weight: 0
)]
class MyCustomProcessor extends DocumentProcessorBase {

  public function canProcess(FileInterface $file): bool {
    return $file->getMimeType() === 'application/x-custom';
  }

  public function process(FileInterface $file): ProcessedDocument {
    // Extract and return content
  }
}
```

The module automatically discovers and registers your processor.

---

## Technical Requirements

- **Drupal**: 10.3 or later
- **PHP**: 8.1 or later
- **Dependencies**: AI module, Canvas module, Canvas AI module
- **Optional**: Pandoc (for DOCX/PDF conversion)

---

## Why Choose This Module?

| Benefit | Description |
|---------|-------------|
| **Time Savings** | Hours of manual work reduced to minutes |
| **Consistency** | AI ensures consistent content structure |
| **Flexibility** | Support for multiple document formats |
| **Control** | Full refinement and approval workflow |
| **Integration** | Native Canvas and AI Context support |
| **Extensibility** | Plugin architecture for custom needs |
| **Enterprise-Ready** | Permission-based access, session management |

---

## Use Cases

### Marketing Teams
Upload campaign briefs, brand documents, and messaging guides. Generate consistent landing pages and promotional content.

### Documentation Teams
Transform technical documents into structured help pages. Maintain consistency across knowledge bases.

### Content Migration
Bulk-process existing documents into Canvas pages. Accelerate content migration projects.

### Editorial Workflows
Give editors a starting point. Upload drafts, generate structured plans, and refine collaboratively.

### Multi-Channel Publishing
Create content once, structure it properly, and publish across Canvas-powered experiences.

---

## Performance & Scalability

- **Async Processing**: Long-running AI operations use async JavaScript polling
- **Document Truncation**: Large documents are intelligently truncated to optimize AI costs
- **Session Isolation**: Each user gets isolated session storage
- **Caching**: Plugin definitions are cached for fast discovery
- **AJAX Updates**: Only changed content is rebuilt during form interactions

---

## Summary

The **AI Content Preparation Wizard** transforms how teams create structured content. By combining intelligent document processing, AI-powered planning, and seamless Canvas integration, it delivers:

- **Faster content creation**
- **Consistent content structure**
- **Full editorial control**
- **Extensible architecture**

Upload. Plan. Publish. It's that simple.

---

## Getting Started

1. Install the module and dependencies
2. Configure settings at `/admin/config/content/content-preparation-wizard`
3. Grant permissions to content teams
4. Access the wizard at `/admin/content/preparation-wizard`
5. Upload your first document and let AI do the heavy lifting

---

*Built for Drupal. Powered by AI. Designed for content teams.*
