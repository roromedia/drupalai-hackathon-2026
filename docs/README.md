# Content Preparation Wizard - Documentation

A Drupal module providing a 3-step wizard for preparing content from uploaded documents using AI-assisted planning and Canvas page creation.

## Documentation Index

| Document | Description |
|----------|-------------|
| [ADR-0001: Architecture](./adr/0001-content-preparation-wizard-architecture.md) | Architecture Decision Record with design decisions and rationale |
| [DDD Architecture](./architecture/content_preparation_wizard_ddd_architecture.md) | Domain-Driven Design architecture with bounded contexts, entities, and services |
| [UI/UX Specification](./content-preparation-wizard-uiux-spec.md) | Complete UI/UX technical specification with wireframes and user flows |
| [Compliance Report](./compliance-report.md) | Drupal coding standards compliance analysis |

---

## Module Overview

### Purpose

The Content Preparation Wizard streamlines the process of converting various document formats into structured Canvas pages. It provides:

1. **Document Upload** - Drag & drop interface for TXT, DOCX, PDF files
2. **AI Planning** - Intelligent content structure generation
3. **Canvas Creation** - One-click page generation

### Wizard Steps

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Step 1: Upload          Step 2: Plan          Step 3: Create          │
│  ───────────────         ────────────          ─────────────           │
│                                                                         │
│  [Drag & Drop Files]  →  [AI Content Plan]  →  [Canvas Preview]        │
│  [Select Contexts]       [Refine Plan]         [Create Page]           │
│  [Choose Template]       [Regenerate]                                  │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Key Features

### Pluggable Document Processors

The module uses Drupal's Plugin API for extensible document processing:

| Processor | File Types | Description |
|-----------|------------|-------------|
| `PandocProcessor` | DOCX, PDF | Default processor using pandoc for conversion |
| `PlainTextProcessor` | TXT, MD | Simple text extraction |
| `WebScraperProcessor` | URLs | Future submodule for web content |

### AI Integration

- Integrates with the `ai` module for provider-agnostic AI access
- Supports configurable AI contexts (site structure, brand guidelines, SEO)
- Template-based prompting for consistent output

### Canvas Integration

- Creates Canvas pages with component trees
- Supports multiple page types
- Configurable component mapping

---

## Architecture Summary

### Bounded Contexts

1. **Document Processing** - File upload, format detection, markdown conversion
2. **AI Planning** - Content plan generation and refinement
3. **Canvas Creation** - Final page creation from approved plans

### Core Services

| Service | Responsibility |
|---------|----------------|
| `DocumentProcessingService` | Orchestrates file conversion via plugins |
| `WizardSessionManager` | Manages wizard state in private tempstore |
| `ContentPlanGenerator` | Interfaces with AI for plan generation |
| `CanvasCreator` | Creates Canvas pages from plans |

### Module Dependencies

```
content_preparation_wizard
├── drupal:file
├── ai:ai
├── canvas:canvas
└── ctools:ctools (for wizard framework)
```

---

## Configuration

### Settings Page

`/admin/config/content/preparation-wizard`

- **Allowed Extensions**: TXT, DOCX, PDF (configurable)
- **Max File Size**: 10MB default
- **Default Processor**: Pandoc
- **Per-Extension Mapping**: Configure processor per file type
- **AI Provider**: Select from available ai module providers
- **AI Template**: Default prompt template for content planning

---

## Extension Points

### Custom Document Processors

```php
<?php

namespace Drupal\my_module\Plugin\DocumentProcessor;

use Drupal\content_preparation_wizard\Attribute\DocumentProcessor;
use Drupal\content_preparation_wizard\Plugin\DocumentProcessor\DocumentProcessorBase;

#[DocumentProcessor(
  id: 'my_custom_processor',
  label: new TranslatableMarkup('Custom Processor'),
  supported_extensions: ['xyz'],
)]
class CustomProcessor extends DocumentProcessorBase {

  public function process(string $file_path): string {
    // Custom processing logic
  }

}
```

### Custom AI Context Providers

Extend AI planning with specialized contexts for different content types.

---

## Future Development

### Planned Submodules

1. **content_preparation_wizard_scraper** - Web scraping for URL-based content
2. **content_preparation_wizard_batch** - Batch processing for multiple documents
3. **content_preparation_wizard_api** - REST API for external integrations

---

## Quick Start

1. Enable the module: `drush en content_preparation_wizard`
2. Configure settings: `/admin/config/content/preparation-wizard`
3. Access wizard: `/admin/content/preparation-wizard`
4. Upload documents, review AI plan, create Canvas page

---

## Related Modules

- [AI](https://www.drupal.org/project/ai) - AI provider abstraction
- [Canvas](https://www.drupal.org/project/canvas) - Page builder
- [CTools](https://www.drupal.org/project/ctools) - Wizard framework
