# Content Preparation Wizard - Drupal Standards Compliance Report

## Executive Summary

This report analyzes the proposed **Content Preparation Wizard** module design against Drupal 10/11 coding standards, security best practices, and the patterns established in this codebase (particularly the `ai` module).

---

## 1. Module Structure Compliance

### Proposed Directory Structure

```
content_preparation_wizard/
├── content_preparation_wizard.info.yml
├── content_preparation_wizard.module
├── content_preparation_wizard.routing.yml
├── content_preparation_wizard.services.yml
├── content_preparation_wizard.permissions.yml
├── content_preparation_wizard.libraries.yml
├── config/
│   ├── install/
│   │   └── content_preparation_wizard.settings.yml
│   └── schema/
│       └── content_preparation_wizard.schema.yml
├── src/
│   ├── Attribute/
│   │   └── DocumentProcessor.php           # Plugin attribute
│   ├── DocumentProcessorPluginManager.php  # Plugin manager
│   ├── Exception/
│   │   └── ProcessingException.php         # Custom exception
│   ├── Form/
│   │   ├── ContentPreparationWizardForm.php
│   │   └── SettingsForm.php
│   ├── Plugin/
│   │   └── DocumentProcessor/
│   │       ├── DocumentProcessorInterface.php
│   │       ├── DocumentProcessorBase.php
│   │       ├── PandocProcessor.php
│   │       └── PlainTextProcessor.php
│   └── Service/
│       ├── DocumentProcessingService.php
│       ├── WizardSessionManager.php
│       └── ContentPlanGenerator.php
├── js/
│   └── dropzone.js
├── css/
│   └── wizard.css
└── templates/
    └── content-preparation-wizard.html.twig
```

### Structure Compliance Status

| Component | Status | Notes |
|-----------|--------|-------|
| `.info.yml` location | PASS | Root level is correct |
| `.module` location | PASS | Root level is correct |
| `.routing.yml` location | PASS | Root level is correct |
| `.services.yml` location | PASS | Root level is correct |
| `config/install/` | PASS | Correct for default configuration |
| `config/schema/` | PASS | Correct for configuration schema |
| `src/Form/` | PASS | PSR-4 compliant |
| `src/Plugin/` | PASS | PSR-4 compliant for plugin discovery |
| `src/Service/` | PASS | PSR-4 compliant |

**Verdict: COMPLIANT**

---

## 2. PSR-4 Autoloading

### Expected Namespaces

| File Path | Expected Namespace |
|-----------|-------------------|
| `src/Form/ContentPreparationWizardForm.php` | `Drupal\content_preparation_wizard\Form` |
| `src/Plugin/DocumentProcessor/*.php` | `Drupal\content_preparation_wizard\Plugin\DocumentProcessor` |
| `src/Service/*.php` | `Drupal\content_preparation_wizard\Service` |
| `src/Attribute/DocumentProcessor.php` | `Drupal\content_preparation_wizard\Attribute` |
| `src/DocumentProcessorPluginManager.php` | `Drupal\content_preparation_wizard` |

**Verdict: COMPLIANT** - Follows standard Drupal PSR-4 patterns

---

## 3. Plugin System Requirements

### Required Components

| Component | Status | Notes |
|-----------|--------|-------|
| Plugin Manager | REQUIRED | `DocumentProcessorPluginManager.php` |
| Plugin Attribute | REQUIRED | `Attribute/DocumentProcessor.php` (PHP 8+) |
| Plugin Interface | REQUIRED | `DocumentProcessorInterface.php` |
| Base Class | RECOMMENDED | `DocumentProcessorBase.php` |

### Plugin Attribute Pattern (from ai_agents)

```php
<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class DocumentProcessor extends AttributeBase {

  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly array $supported_extensions = [],
    public readonly int $weight = 0,
  ) {}

}
```

**Verdict: COMPLIANT** when all components are implemented

---

## 4. Security Considerations

### File Upload Validation (CRITICAL)

| Security Check | Implementation Required |
|----------------|------------------------|
| Extension validation | Use `file_validate_extensions` |
| MIME type validation | Implement custom validator |
| File size limit | Use `file_validate_size` |
| Path traversal prevention | Use Drupal's file system API only |
| Private file storage | Upload to `private://` not `public://` |

### Required Validation

```php
protected function validateUploadedFile(FileInterface $file): bool {
  $mime_type = $file->getMimeType();
  $allowed_mimes = [
    'text/plain',
    'text/markdown',
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  ];

  return in_array($mime_type, $allowed_mimes, TRUE);
}
```

### Permission Requirements

```yaml
# content_preparation_wizard.permissions.yml
administer content preparation wizard:
  title: 'Administer Content Preparation Wizard'
  description: 'Configure wizard settings and manage processors.'
  restrict access: true

use content preparation wizard:
  title: 'Use Content Preparation Wizard'
  description: 'Upload documents and generate content plans.'
```

**Verdict: REQUIRES ATTENTION** - Security must be properly implemented

---

## 5. Configuration Schema

### Required Schema

```yaml
# config/schema/content_preparation_wizard.schema.yml
content_preparation_wizard.settings:
  type: config_object
  label: 'Content Preparation Wizard Settings'
  mapping:
    allowed_extensions:
      type: sequence
      label: 'Allowed file extensions'
      sequence:
        type: string
    max_file_size:
      type: integer
      label: 'Maximum file size in bytes'
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
          processor:
            type: string
    ai_provider:
      type: string
      label: 'AI provider for content planning'
```

**Verdict: COMPLIANT** when schema is implemented

---

## 6. Libraries Definition

```yaml
# content_preparation_wizard.libraries.yml
wizard:
  version: VERSION
  css:
    component:
      css/wizard.css: {}
  js:
    js/dropzone.js: {}
  dependencies:
    - core/drupal
    - core/drupalSettings
    - core/once
```

**NOTE**: Use `Drupal.once()` instead of jQuery's `.once()` per global instructions.

---

## 7. Compliance Summary

| Category | Status | Issues |
|----------|--------|--------|
| Directory Structure | COMPLIANT | None |
| PSR-4 Autoloading | COMPLIANT | Verify implementation |
| Plugin System | NEEDS WORK | Add manager & attribute |
| Dependency Injection | COMPLIANT | Follow patterns shown |
| Configuration Schema | NEEDS WORK | Must be implemented |
| Form API | COMPLIANT | Follow multi-step pattern |
| Security | REQUIRES ATTENTION | See section 4 |
| Routing | COMPLIANT | Follow patterns shown |

---

## 8. Priority Action Items

1. **HIGH**: Add `DocumentProcessorPluginManager.php`
2. **HIGH**: Add `Attribute/DocumentProcessor.php`
3. **HIGH**: Implement proper file upload security validation
4. **HIGH**: Create configuration schema
5. **MEDIUM**: Add exception classes
6. **MEDIUM**: Add menu and task link definitions
7. **LOW**: Consider adding hook implementations in `.module` file

---

## 9. Code Style Requirements

### PHP Standards

- Use `declare(strict_types=1);` in all PHP files
- Follow Drupal PHP coding standards
- Use typed properties and return types (PHP 8.1+)
- Prefer constructor property promotion

### Example Compliant Service

```php
<?php

declare(strict_types=1);

namespace Drupal\content_preparation_wizard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\content_preparation_wizard\DocumentProcessorPluginManager;

/**
 * Handles document processing operations.
 */
final class DocumentProcessingService {

  public function __construct(
    protected DocumentProcessorPluginManager $processorManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function processDocument(string $file_path): string {
    // Implementation...
  }

}
```

---

## 10. Conclusion

The proposed Content Preparation Wizard module design is largely compliant with Drupal standards. Following the patterns established in the `ai` and `canvas` modules in this codebase will ensure consistency and maintainability.
