# AI Content Preparation Wizard

Transform documents and web content into structured Canvas pages using AI-powered content planning.

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [External Tools Setup](#external-tools-setup)
  - [Installing Pandoc](#installing-pandoc)
  - [Installing pdftotext (Poppler)](#installing-pdftotext-poppler)
- [Configuration](#configuration)
- [Usage](#usage)
- [Permissions](#permissions)
- [Architecture](#architecture)
- [Extending the Module](#extending-the-module)
- [Events & Integration](#events--integration)
- [Troubleshooting](#troubleshooting)
- [API Reference](#api-reference)

---

## Overview

The **AI Content Preparation Wizard** is a Drupal module that bridges the gap between raw documents and polished, structured web content. It provides a two-step workflow to:

1. **Upload** documents (PDF, DOCX, TXT, MD) or scrape content from URLs
2. **Review** an AI-generated content plan and create Canvas pages

The module leverages your site's AI provider to analyze content, detect logical sections, suggest appropriate Canvas components, and generate structured pages—all with iterative refinement support.

---

## Features

### Document Processing

| Format | Extension | Processing Method | Requirements |
|--------|-----------|-------------------|--------------|
| Markdown | `.md` | Native parsing | None |
| Plain Text | `.txt` | Direct extraction | None |
| Word Documents | `.docx` | Pandoc conversion | Pandoc |
| OpenDocument | `.odt` | Pandoc conversion | Pandoc |
| Rich Text | `.rtf` | Pandoc conversion | Pandoc |
| PDF Files | `.pdf` | pdftotext extraction | Poppler Utils |

### Web Page Scraping

- Automatic content area detection (article, main, content containers)
- HTML cleaning (removes navigation, scripts, ads, footers)
- Metadata extraction (title, description, author, language)
- Encoding detection with UTF-8 normalization
- Basic mode (built-in PHP) and Advanced mode (external binary)

### AI-Powered Content Planning

- **Smart Section Detection**: AI identifies logical content sections and hierarchy
- **Component Mapping**: Sections are mapped to Canvas SDC components (hero, text, list, CTA, etc.)
- **Metadata Generation**: Word count, estimated read time, target audience
- **AI-Generated Summaries**: Capture the essence of your content automatically

### AI Contexts Integration

Integrate with Drupal's AI Context system to influence content generation:

- **Brand Guidelines**: Apply tone-of-voice and style contexts
- **Audience Personas**: Target specific audiences for content optimization
- **Domain Expertise**: Add industry-specific knowledge
- **Custom Instructions**: Pass any context to influence AI decisions

### Template-Based Page Creation

- Select existing Canvas pages as templates
- Smart cloning with structure preservation
- Intelligent content filling into text-based components
- UUID regeneration for new pages

### Plan Editing & Refinement

- **Live Editing**: Edit section titles, content, and component types in-browser
- **Refinement Instructions**: Tell the AI what to change ("make it more casual", "add a FAQ")
- **Refinement History**: Track all iterations with timestamps
- **Configurable Limits**: Set maximum refinement rounds

### Asynchronous Processing

- Non-blocking UI during AI operations
- Progress indicator with rotating status messages
- AJAX form updates without full page reloads

### Session Persistence

- Private TempStore for user-specific sessions
- Step navigation without losing progress
- Configurable timeout (default: 1 hour)
- Automatic cleanup after completion

---

## Requirements

### Drupal Version

- Drupal **10.3** or later
- Drupal **11.x** supported

### PHP Version

- PHP **8.1** or later

### Required Modules

| Module | Machine Name | Purpose |
|--------|--------------|---------|
| AI | `ai` | AI provider abstraction |
| Canvas | `canvas` | Canvas page entities |
| Canvas AI | `canvas_ai` | Canvas AI integration helpers |
| File | `file` (core) | File upload management |

### External Tools (Optional but Recommended)

| Tool | Purpose | Required For |
|------|---------|--------------|
| [Pandoc](https://pandoc.org/) | Document conversion | DOCX, ODT, RTF support |
| [pdftotext](https://poppler.freedesktop.org/) | PDF text extraction | PDF support |

---

## Installation

### Via Composer (Recommended)

```bash
# If the module is available via Composer
composer require drupal/ai_content_preparation_wizard
```

### Manual Installation

1. Download or clone the module to `web/modules/custom/ai_content_preparation_wizard`
2. Enable the module:

```bash
drush en ai_content_preparation_wizard -y
```

Or via the Drupal admin UI at `/admin/modules`.

### Verify Installation

```bash
drush pm:list --filter=ai_content_preparation_wizard
```

---

## External Tools Setup

### Installing Pandoc

Pandoc is required for processing Word documents (.docx), OpenDocument (.odt), and Rich Text (.rtf) files.

#### macOS

```bash
# Using Homebrew
brew install pandoc

# Verify installation
pandoc --version
which pandoc  # Usually /usr/local/bin/pandoc or /opt/homebrew/bin/pandoc
```

#### Ubuntu/Debian

```bash
sudo apt-get update
sudo apt-get install pandoc

# Verify installation
pandoc --version
which pandoc  # Usually /usr/bin/pandoc
```

#### RHEL/CentOS/Fedora

```bash
sudo dnf install pandoc
# or
sudo yum install pandoc

# Verify installation
pandoc --version
```

#### Windows

1. Download the installer from [pandoc.org/installing.html](https://pandoc.org/installing.html)
2. Run the installer
3. Add Pandoc to your PATH if not done automatically
4. Verify: `pandoc --version` in Command Prompt

#### Docker/DDEV

For DDEV environments, add to `.ddev/config.yaml`:

```yaml
webimage_extra_packages:
  - pandoc
```

Then restart: `ddev restart`

---

### Installing pdftotext (Poppler)

pdftotext is part of the Poppler utilities and is required for PDF text extraction.

#### macOS

```bash
# Using Homebrew
brew install poppler

# Verify installation
pdftotext -v
which pdftotext  # Usually /usr/local/bin/pdftotext or /opt/homebrew/bin/pdftotext
```

#### Ubuntu/Debian

```bash
sudo apt-get update
sudo apt-get install poppler-utils

# Verify installation
pdftotext -v
which pdftotext  # Usually /usr/bin/pdftotext
```

#### RHEL/CentOS/Fedora

```bash
sudo dnf install poppler-utils
# or
sudo yum install poppler-utils

# Verify installation
pdftotext -v
```

#### Windows

1. Download Poppler for Windows from [github.com/oschwartz10612/poppler-windows](https://github.com/oschwartz10612/poppler-windows/releases)
2. Extract to a directory (e.g., `C:\poppler`)
3. Add the `bin` folder to your PATH
4. Verify: `pdftotext -v` in Command Prompt

#### Docker/DDEV

For DDEV environments, add to `.ddev/config.yaml`:

```yaml
webimage_extra_packages:
  - poppler-utils
```

Then restart: `ddev restart`

---

## Configuration

### Access Settings

Navigate to: **Administration → Configuration → Content → Content Preparation Wizard**

URL: `/admin/config/content/content-preparation-wizard`

### Available Settings

| Setting | Description | Default |
|---------|-------------|---------|
| **Document Processors** | Extension-to-binary mapping | See below |
| **Enable Logging** | Log document conversions | No |
| **Max File Size** | Maximum upload size in bytes | 10485760 (10 MB) |
| **Allowed Extensions** | Permitted file extensions | txt, md, docx, pdf |
| **Default AI Provider** | Override site's default AI provider | (site default) |
| **Default AI Model** | Override provider's default model | (provider default) |
| **Session Timeout** | Session duration in seconds | 3600 (1 hour) |
| **Enable Refinement** | Allow iterative plan refinement | Yes |
| **Max Refinement Iterations** | Maximum refinement rounds | 5 |
| **Webpage Processor Mode** | basic (PHP) or advanced (external) | basic |
| **Webpage Processor Binary** | Path to external scraper | (empty) |
| **Webpage Processor Arguments** | Binary arguments template | `{url}` |
| **Webpage Processor Timeout** | Scraper timeout in seconds | 30 |

### Document Processor Configuration

The `document_processors` setting maps file extensions to processing binaries:

```
pdf|/usr/bin/pdftotext
txt,md,docx,odt,rtf|/usr/bin/pandoc
```

Format: `extension(s)|/path/to/binary`

Multiple extensions can be comma-separated.

### Example Configuration via Drush

```bash
# Set Pandoc path
drush config:set ai_content_preparation_wizard.settings pandoc_path /usr/local/bin/pandoc

# Set pdftotext path
drush config:set ai_content_preparation_wizard.settings pdftotext_path /usr/local/bin/pdftotext

# Set max file size to 20MB
drush config:set ai_content_preparation_wizard.settings max_file_size 20971520

# Enable logging
drush config:set ai_content_preparation_wizard.settings enable_logging true
```

---

## Usage

### Accessing the Wizard

Navigate to: **Administration → Content → Content Preparation Wizard**

URL: `/admin/content/preparation-wizard`

### Step 1: Upload & Configure

1. **Upload Documents**: Drag-and-drop or click to upload files (PDF, DOCX, TXT, MD)
2. **Add Web Pages**: Enter URLs (one per line) to scrape content from websites
3. **Select AI Contexts**: Choose brand guidelines, audience personas, or custom contexts
4. **Choose Template**: Optionally select an existing Canvas page as a template

Click **Continue** to proceed.

### Step 2: Review & Create

The screen is split into two panels:

**Left Panel - Source Content Preview**
- Tabbed view of all uploaded documents and scraped web pages
- Markdown rendering with proper formatting
- Content type badges (document vs. webpage)

**Right Panel - Content Plan**
- AI-generated plan with sections
- Editable title field
- Sections with editable:
  - Title
  - Content (textarea)
  - Component type (dropdown)
- Refinement instructions input
- Regenerate button for iterative improvement

**Creating the Canvas Page**
1. Review and edit the content plan as needed
2. Enter refinement instructions if changes are needed
3. Click **Regenerate Plan** to apply refinements
4. Set the page title and URL alias
5. Choose publication status (draft or published)
6. Click **Create Canvas Page Now**

The wizard creates the Canvas page and redirects you to the new page.

---

## Permissions

| Permission | Machine Name | Description |
|------------|--------------|-------------|
| Use the Content Preparation Wizard | `access content preparation wizard` | Access the wizard to upload documents and generate plans |
| Administer Content Preparation Wizard | `administer content preparation wizard` | Access and modify wizard settings |
| Create Canvas pages from wizard | `create canvas from wizard` | Generate Canvas pages from content plans |

### Assign Permissions

Navigate to: **Administration → People → Permissions**

URL: `/admin/people/permissions`

Filter by "content preparation wizard" to find relevant permissions.

---

## Architecture

### Module Structure

```
ai_content_preparation_wizard/
├── ai_content_preparation_wizard.info.yml      # Module definition
├── ai_content_preparation_wizard.module        # Hook implementations
├── ai_content_preparation_wizard.services.yml  # Service definitions
├── ai_content_preparation_wizard.routing.yml   # Route definitions
├── ai_content_preparation_wizard.permissions.yml
├── ai_content_preparation_wizard.libraries.yml
├── config/
│   ├── install/                                # Default configuration
│   └── schema/                                 # Configuration schema
├── css/
│   ├── content-preparation-wizard.css
│   └── document-tabs.css
├── js/
│   ├── content-preparation-wizard.js           # Main wizard behaviors
│   ├── async-plan.js                           # Async plan generation
│   └── document-tabs.js                        # Tabbed document preview
└── src/
    ├── Annotation/                             # Plugin annotations
    ├── Attribute/                              # PHP 8 attributes
    ├── Controller/
    │   └── WizardAjaxController.php            # AJAX endpoints
    ├── Enum/
    │   ├── FileType.php
    │   ├── PlanStatus.php
    │   ├── ProcessingProvider.php
    │   ├── WizardStatus.php
    │   └── WizardStep.php
    ├── Event/
    │   ├── CanvasPageCreatedEvent.php
    │   ├── DocumentProcessedEvent.php
    │   └── WizardStepChangedEvent.php
    ├── Exception/
    │   ├── CanvasCreationException.php
    │   ├── DocumentProcessingException.php
    │   ├── InvalidWizardStateException.php
    │   └── PlanGenerationException.php
    ├── Form/
    │   ├── ContentPreparationWizardForm.php    # Main wizard form
    │   ├── SettingsForm.php                    # Admin settings
    │   ├── Step1UploadForm.php                 # Upload step
    │   └── Step2PlanForm.php                   # Plan/create step
    ├── Model/
    │   ├── AIContext.php
    │   ├── ComponentMapping.php
    │   ├── ContentPlan.php
    │   ├── DocumentMetadata.php
    │   ├── PlanSection.php
    │   ├── ProcessedDocument.php
    │   ├── ProcessedWebpage.php
    │   ├── RefinementEntry.php
    │   └── WizardSession.php
    ├── Plugin/
    │   └── DocumentProcessor/
    │       ├── DocumentProcessorBase.php
    │       ├── DocumentProcessorInterface.php
    │       ├── MarkdownProcessor.php
    │       ├── PandocProcessor.php
    │       ├── PdfToTextProcessor.php
    │       └── PlainTextProcessor.php
    ├── PluginManager/
    │   └── DocumentProcessorPluginManager.php
    └── Service/
        ├── CanvasCreator.php
        ├── CanvasCreatorInterface.php
        ├── ContentPlanGenerator.php
        ├── ContentPlanGeneratorInterface.php
        ├── DocumentProcessingService.php
        ├── DocumentProcessingServiceInterface.php
        ├── PandocConverter.php
        ├── PandocConverterInterface.php
        ├── WebpageProcessor.php
        ├── WebpageProcessorInterface.php
        ├── WizardSessionManager.php
        └── WizardSessionManagerInterface.php
```

### Design Patterns

| Pattern | Implementation |
|---------|----------------|
| **Immutable Value Objects** | All model classes use `readonly` properties and `with*()` methods |
| **Plugin Architecture** | Extensible document processors with attribute-based discovery |
| **Event-Driven** | Events dispatched for integration points |
| **Service Layer** | Business logic in injectable services |
| **Dependency Injection** | Services wired via `services.yml` |
| **Enum Status Tracking** | Type-safe workflow states |

---

## Extending the Module

### Creating Custom Document Processors

Add support for new file formats by creating processor plugins.

#### 1. Create the Plugin Class

```php
<?php

namespace Drupal\my_module\Plugin\DocumentProcessor;

use Drupal\ai_content_preparation_wizard\Attribute\DocumentProcessor;
use Drupal\ai_content_preparation_wizard\Model\ProcessedDocument;
use Drupal\ai_content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;

#[DocumentProcessor(
  id: 'my_custom_processor',
  label: new TranslatableMarkup('My Custom Format'),
  description: new TranslatableMarkup('Processes .xyz files'),
  supported_extensions: ['xyz', 'abc'],
  weight: 0
)]
class MyCustomProcessor extends DocumentProcessorBase {

  /**
   * {@inheritdoc}
   */
  public function canProcess(FileInterface $file): bool {
    $extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
    return in_array(strtolower($extension), ['xyz', 'abc']);
  }

  /**
   * {@inheritdoc}
   */
  public function process(FileInterface $file): ProcessedDocument {
    $content = file_get_contents($file->getFileUri());

    // Your custom processing logic here
    $markdown = $this->convertToMarkdown($content);

    return new ProcessedDocument(
      fileId: (int) $file->id(),
      filename: $file->getFilename(),
      markdownContent: $markdown,
      metadata: $this->extractMetadata($file),
      fileType: FileType::Document,
      provider: ProcessingProvider::Document,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return ['xyz', 'abc'];
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): bool {
    // Return true if all requirements are met
    return true;
  }

}
```

#### 2. Register the Plugin

The plugin manager automatically discovers plugins in the `Plugin/DocumentProcessor` namespace of any enabled module. No additional registration needed.

#### 3. Configure the Processor

Add the new extensions to the allowed extensions in settings:

```bash
drush config:set ai_content_preparation_wizard.settings allowed_extensions 'txt,md,docx,pdf,xyz,abc'
```

---

## Events & Integration

The module dispatches events at key points in the workflow for custom integrations.

### Available Events

| Event | Constant | When Dispatched |
|-------|----------|-----------------|
| `DocumentProcessedEvent` | `DocumentProcessedEvent::EVENT_NAME` | After a document is successfully processed |
| `CanvasPageCreatedEvent` | `CanvasPageCreatedEvent::EVENT_NAME` | After a Canvas page is created |
| `WizardStepChangedEvent` | `WizardStepChangedEvent::EVENT_NAME` | When the wizard transitions between steps |

### Subscribing to Events

#### 1. Create an Event Subscriber

```php
<?php

namespace Drupal\my_module\EventSubscriber;

use Drupal\ai_content_preparation_wizard\Event\CanvasPageCreatedEvent;
use Drupal\ai_content_preparation_wizard\Event\DocumentProcessedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class WizardEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      DocumentProcessedEvent::EVENT_NAME => 'onDocumentProcessed',
      CanvasPageCreatedEvent::EVENT_NAME => 'onCanvasCreated',
    ];
  }

  /**
   * Handles document processing completion.
   */
  public function onDocumentProcessed(DocumentProcessedEvent $event): void {
    $document = $event->getProcessedDocument();
    // Custom logic: log, notify, trigger workflows, etc.
    \Drupal::logger('my_module')->info('Document processed: @filename', [
      '@filename' => $document->getFilename(),
    ]);
  }

  /**
   * Handles Canvas page creation.
   */
  public function onCanvasCreated(CanvasPageCreatedEvent $event): void {
    $canvas = $event->getCanvas();
    $plan = $event->getContentPlan();
    // Custom logic: notify editors, trigger publishing workflow, etc.
    \Drupal::logger('my_module')->info('Canvas page created: @title', [
      '@title' => $canvas->label(),
    ]);
  }

}
```

#### 2. Register the Subscriber

In `my_module.services.yml`:

```yaml
services:
  my_module.wizard_event_subscriber:
    class: Drupal\my_module\EventSubscriber\WizardEventSubscriber
    tags:
      - { name: event_subscriber }
```

---

## Troubleshooting

### Common Issues

#### "Pandoc not found" or DOCX files not processing

**Cause**: Pandoc binary not installed or path incorrect.

**Solution**:
1. Verify Pandoc is installed: `pandoc --version`
2. Find the path: `which pandoc`
3. Update the path in module settings

#### "pdftotext not found" or PDF files not processing

**Cause**: Poppler utilities not installed or path incorrect.

**Solution**:
1. Verify pdftotext is installed: `pdftotext -v`
2. Find the path: `which pdftotext`
3. Update the path in module settings

#### "Permission denied" when running external tools

**Cause**: Web server user cannot execute binaries.

**Solution**:
1. Check permissions on the binary: `ls -la /usr/bin/pandoc`
2. Ensure the web server user (www-data, apache, nginx) has execute permission
3. For SELinux systems, check security contexts

#### AI plan generation fails or times out

**Cause**: AI provider not configured or response timeout.

**Solution**:
1. Verify AI module is configured at `/admin/config/ai`
2. Check AI provider credentials
3. Try a smaller document to test
4. Check Drupal logs for detailed error messages

#### Session data lost between steps

**Cause**: Session timeout or TempStore issues.

**Solution**:
1. Increase session timeout in settings
2. Check PHP session configuration
3. Verify PrivateTempStore is working: clear caches and retry

#### Web page scraping returns empty content

**Cause**: JavaScript-rendered content or scraping blocked.

**Solution**:
1. Try "advanced" mode with a headless browser
2. Some sites block automated scraping
3. Check if the URL is accessible from the server

### Debug Logging

Enable logging in module settings, then check logs:

```bash
drush watchdog:show --filter="ai_content_preparation_wizard"
```

Or via the admin UI: **Administration → Reports → Recent log messages**

---

## API Reference

### Services

| Service ID | Interface | Description |
|------------|-----------|-------------|
| `ai_content_preparation_wizard.document_processing` | `DocumentProcessingServiceInterface` | Orchestrates document processing |
| `ai_content_preparation_wizard.content_plan_generator` | `ContentPlanGeneratorInterface` | AI plan generation and refinement |
| `ai_content_preparation_wizard.wizard_session_manager` | `WizardSessionManagerInterface` | Session state management |
| `ai_content_preparation_wizard.canvas_creator` | `CanvasCreatorInterface` | Canvas page creation |
| `ai_content_preparation_wizard.webpage_processor` | `WebpageProcessorInterface` | URL content extraction |
| `ai_content_preparation_wizard.pandoc_converter` | `PandocConverterInterface` | Pandoc integration |
| `plugin.manager.document_processor` | `DocumentProcessorPluginManager` | Plugin discovery |

### Example: Using Services Programmatically

```php
<?php

// Get the document processing service
$processor = \Drupal::service('ai_content_preparation_wizard.document_processing');

// Process a file
$file = File::load($fid);
$processed = $processor->process($file);
$markdown = $processed->getMarkdownContent();

// Generate a content plan
$generator = \Drupal::service('ai_content_preparation_wizard.content_plan_generator');
$plan = $generator->generate(
  documents: [$processed],
  contexts: [],
  templateId: NULL,
  options: []
);

// Create a Canvas page
$creator = \Drupal::service('ai_content_preparation_wizard.canvas_creator');
$canvas = $creator->create($plan, [
  'title' => 'My New Page',
  'status' => TRUE,
]);
```

---

## Support

For issues and feature requests, please use the project's issue queue.

---

## License

This module is licensed under the GNU General Public License v2.0 or later.

---

*Built for Drupal. Powered by AI. Designed for content teams.*
