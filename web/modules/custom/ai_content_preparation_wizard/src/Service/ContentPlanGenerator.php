<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\ai_content_preparation_wizard\Exception\PlanGenerationException;
use Drupal\ai_content_preparation_wizard\Model\AIContext;
use Drupal\ai_content_preparation_wizard\Model\ContentPlan;
use Drupal\ai_content_preparation_wizard\Model\PlanSection;
use Drupal\ai_content_preparation_wizard\Model\ProcessedDocument;
use Drupal\ai_content_preparation_wizard\Model\ProcessedWebpage;
use Drupal\ai_content_preparation_wizard\Model\RefinementEntry;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for generating and refining content plans using AI.
 *
 * This service integrates with the Drupal AI module to generate structured
 * content plans from processed documents and contextual information.
 */
class ContentPlanGenerator implements ContentPlanGeneratorInterface {

  /**
   * The maximum number of retry attempts for JSON parsing failures.
   */
  private const MAX_RETRIES = 3;

  /**
   * The operation type for AI chat.
   */
  private const OPERATION_TYPE = 'chat';

  /**
   * Maximum content length per document (in characters) before truncation.
   */
  private const MAX_DOCUMENT_CONTENT_LENGTH = 50000;

  /**
   * Maximum total content length for all documents combined.
   */
  private const MAX_TOTAL_CONTENT_LENGTH = 100000;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a ContentPlanGenerator.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProviderManager
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\canvas_ai\CanvasAiPageBuilderHelper $pageBuilderHelper
   *   The Canvas AI page builder helper for component descriptions.
   */
  public function __construct(
    protected readonly AiProviderPluginManager $aiProviderManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly UuidInterface $uuid,
    protected readonly TimeInterface $time,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly CanvasAiPageBuilderHelper $pageBuilderHelper,
  ) {
    $this->logger = $this->loggerFactory->get('ai_content_preparation_wizard');
  }

  /**
   * {@inheritdoc}
   */
  public function generate(array $documents, array $contexts = [], ?string $templateId = NULL, array $options = []): ContentPlan {
    // Get webpages from options if provided.
    $webpages = $options['webpages'] ?? [];

    // Validate we have content to process.
    if (empty($documents) && empty($webpages)) {
      throw new PlanGenerationException('No documents or webpages provided for plan generation.');
    }

    // Get AI provider and model.
    $providerInfo = $this->getAiProvider();
    if ($providerInfo === NULL) {
      throw new PlanGenerationException(
        'No AI provider configured. Please configure an AI provider in the AI module settings.'
      );
    }

    [$provider, $modelId] = $providerInfo;

    // Build the document content.
    $documentContent = $this->buildDocumentContent($documents);

    // Build the webpage content.
    $webpageContent = $this->buildWebpageContent($webpages);

    // Build the context content.
    $contextContent = $this->buildContextContent($contexts);

    // Build the system prompt.
    $systemPrompt = $this->buildGenerationSystemPrompt($templateId, $options);

    // Build the user message.
    $userMessage = $this->buildGenerationUserMessage($documentContent, $webpageContent, $contextContent, $options);

    // Execute the AI call with retries.
    $responseData = $this->executeAiCallWithRetries(
      $provider,
      $modelId,
      $systemPrompt,
      $userMessage
    );

    // Parse response into ContentPlan.
    $sourceDocumentIds = array_map(
      fn(ProcessedDocument $doc): string => $doc->id,
      $documents
    );

    return $this->parseContentPlanResponse($responseData, $sourceDocumentIds, $templateId);
  }

  /**
   * {@inheritdoc}
   */
  public function refine(ContentPlan $plan, string $refinementPrompt, array $contexts = [], array $options = []): ContentPlan {
    if (!$this->canRefine($plan)) {
      throw new PlanGenerationException(
        sprintf(
          'Plan has reached maximum refinement iterations (%d).',
          $this->getMaxRefinementIterations()
        )
      );
    }

    // Get AI provider and model.
    $providerInfo = $this->getAiProvider();
    if ($providerInfo === NULL) {
      throw new PlanGenerationException(
        'No AI provider configured. Please configure an AI provider in the AI module settings.'
      );
    }

    [$provider, $modelId] = $providerInfo;

    // Build the context content.
    $contextContent = $this->buildContextContent($contexts);

    // Build the refinement prompt.
    $systemPrompt = $this->buildRefinementSystemPrompt();
    $userMessage = $this->buildRefinementUserMessage($plan, $refinementPrompt, $contextContent);

    // Execute the AI call with retries.
    $responseData = $this->executeAiCallWithRetries(
      $provider,
      $modelId,
      $systemPrompt,
      $userMessage
    );

    // Parse the refined plan response.
    $refinedPlan = $this->parseRefinedPlanResponse($responseData, $plan);

    // Add refinement entry to history.
    $refinementEntry = RefinementEntry::create(
      instructions: $refinementPrompt,
      response: $responseData['refinement_summary'] ?? 'Plan refined based on instructions.',
      affectedSections: $responseData['affected_sections'] ?? [],
    );

    return $refinedPlan->withRefinement($refinementEntry);
  }

  /**
   * {@inheritdoc}
   */
  public function canRefine(ContentPlan $plan): bool {
    return $plan->getRefinementCount() < $this->getMaxRefinementIterations();
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxRefinementIterations(): int {
    $config = $this->configFactory->get('ai_content_preparation_wizard.settings');
    return (int) ($config->get('max_refinement_iterations') ?? 5);
  }

  /**
   * Gets the AI provider and model for chat operations.
   *
   * @return array|null
   *   An array containing [provider, model_id] or NULL if unavailable.
   */
  protected function getAiProvider(): ?array {
    // Check if any providers are available for chat.
    if (!$this->aiProviderManager->hasProvidersForOperationType(self::OPERATION_TYPE)) {
      return NULL;
    }

    // Get configured provider from module settings.
    $config = $this->configFactory->get('ai_content_preparation_wizard.settings');
    $defaultProvider = $config->get('default_ai_provider');
    $defaultModel = $config->get('default_ai_model');

    // Build simple option format if both provider and model are configured.
    $preferredModel = NULL;
    if (!empty($defaultProvider) && !empty($defaultModel)) {
      $preferredModel = $defaultProvider . '__' . $defaultModel;
    }

    try {
      $providerData = $this->aiProviderManager->getSetProvider(self::OPERATION_TYPE, $preferredModel);
      return [$providerData['provider_id'], $providerData['model_id']];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get AI provider: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Builds combined content from all processed documents.
   *
   * @param array<\Drupal\ai_content_preparation_wizard\Model\ProcessedDocument> $documents
   *   The processed documents.
   *
   * @return string
   *   The combined markdown content.
   */
  protected function buildDocumentContent(array $documents): string {
    $parts = [];

    foreach ($documents as $document) {
      if (!$document instanceof ProcessedDocument) {
        continue;
      }

      // Clean and truncate the markdown content.
      $content = $this->cleanMarkdownContent($document->markdownContent);

      // Truncate if exceeds per-document limit.
      if (mb_strlen($content) > self::MAX_DOCUMENT_CONTENT_LENGTH) {
        $content = mb_substr($content, 0, self::MAX_DOCUMENT_CONTENT_LENGTH);
        $content .= "\n\n[Content truncated due to length...]";
      }

      $parts[] = sprintf(
        "## Document: %s\n\n%s",
        $document->fileName,
        $content
      );
    }

    $combined = implode("\n\n---\n\n", $parts);

    // Truncate total content if exceeds limit.
    if (mb_strlen($combined) > self::MAX_TOTAL_CONTENT_LENGTH) {
      $combined = mb_substr($combined, 0, self::MAX_TOTAL_CONTENT_LENGTH);
      $combined .= "\n\n[Total content truncated due to length...]";
    }

    return $combined;
  }

  /**
   * Cleans markdown content to reduce token usage.
   *
   * Removes excessive whitespace, base64 images, and other unnecessary content.
   *
   * @param string $content
   *   The raw markdown content.
   *
   * @return string
   *   The cleaned markdown content.
   */
  protected function cleanMarkdownContent(string $content): string {
    // Remove base64-encoded images (can be very large).
    $content = preg_replace('/!\[([^\]]*)\]\(data:image[^)]+\)/', '[$1]', $content);

    // Remove excessive blank lines (more than 2 consecutive).
    $content = preg_replace('/\n{3,}/', "\n\n", $content);

    // Remove excessive spaces (more than 2 consecutive).
    $content = preg_replace('/[ \t]{3,}/', '  ', $content);

    // Remove HTML comments.
    $content = preg_replace('/<!--.*?-->/s', '', $content);

    // Remove zero-width characters and other invisible unicode.
    $content = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $content);

    // Trim each line to remove trailing whitespace.
    $lines = explode("\n", $content);
    $lines = array_map('rtrim', $lines);
    $content = implode("\n", $lines);

    return trim($content);
  }

  /**
   * Maximum content length per webpage (in characters) before truncation.
   */
  private const MAX_WEBPAGE_CONTENT_LENGTH = 30000;

  /**
   * Builds combined content from all processed webpages.
   *
   * @param array<\Drupal\ai_content_preparation_wizard\Model\ProcessedWebpage> $webpages
   *   The processed webpages.
   *
   * @return string
   *   The combined markdown content from webpages.
   */
  protected function buildWebpageContent(array $webpages): string {
    if (empty($webpages)) {
      return '';
    }

    $parts = [];

    foreach ($webpages as $webpage) {
      if (!$webpage instanceof ProcessedWebpage) {
        continue;
      }

      // Clean and truncate the markdown content.
      $content = $this->cleanMarkdownContent($webpage->markdownContent);

      // Truncate if exceeds per-webpage limit.
      if (mb_strlen($content) > self::MAX_WEBPAGE_CONTENT_LENGTH) {
        $content = mb_substr($content, 0, self::MAX_WEBPAGE_CONTENT_LENGTH);
        $content .= "\n\n[Content truncated due to length...]";
      }

      $parts[] = sprintf(
        "## Referenced Webpage: %s\n**Source URL:** %s\n\n%s",
        $webpage->title,
        $webpage->url,
        $content
      );
    }

    if (empty($parts)) {
      return '';
    }

    $combined = implode("\n\n---\n\n", $parts);

    // Apply total content limit for webpages (half of document limit).
    $maxTotal = (int) (self::MAX_TOTAL_CONTENT_LENGTH / 2);
    if (mb_strlen($combined) > $maxTotal) {
      $combined = mb_substr($combined, 0, $maxTotal);
      $combined .= "\n\n[Total webpage content truncated due to length...]";
    }

    return $combined;
  }

  /**
   * Builds combined content from all AI contexts.
   *
   * @param array<\Drupal\ai_content_preparation_wizard\Model\AIContext|string> $contexts
   *   The AI contexts. Can be either AIContext objects or string context IDs.
   *
   * @return string
   *   The combined context content.
   */
  protected function buildContextContent(array $contexts): string {
    if (empty($contexts)) {
      return '';
    }

    // Convert string context IDs to AIContext objects.
    $aiContexts = [];
    foreach ($contexts as $context) {
      if ($context instanceof AIContext) {
        $aiContexts[] = $context;
      }
      elseif (is_string($context) && !empty($context)) {
        // Create AIContext from string ID with descriptive content.
        $aiContexts[] = $this->createContextFromId($context);
      }
    }

    if (empty($aiContexts)) {
      return '';
    }

    return AIContext::combineForPrompt($aiContexts, TRUE);
  }

  /**
   * Creates an AIContext object from a context ID string.
   *
   * @param string $contextId
   *   The context ID (e.g., 'site_structure', 'brand_guidelines').
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\AIContext
   *   The created AIContext object.
   */
  protected function createContextFromId(string $contextId): AIContext {
    // Try to load from ai_context entity storage.
    try {
      $contextStorage = $this->entityTypeManager->getStorage('ai_context');
      $entity = $contextStorage->load($contextId);

      if ($entity !== NULL) {
        return AIContext::create(
          type: $contextId,
          label: $entity->label(),
          content: $entity->get('content') ?? '',
          priority: 50,
          metadata: ['tags' => $entity->get('tags') ?? []],
        );
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to load ai_context entity @id: @message', [
        '@id' => $contextId,
        '@message' => $e->getMessage(),
      ]);
    }

    // Fallback for unknown context IDs.
    return AIContext::create(
      type: $contextId,
      label: ucwords(str_replace('_', ' ', $contextId)),
      content: sprintf('Apply %s considerations when generating content.', str_replace('_', ' ', $contextId)),
      priority: 50,
    );
  }

  /**
   * Builds the system prompt for content plan generation.
   *
   * @param string|null $templateId
   *   Optional template ID.
   * @param array<string, mixed> $options
   *   Generation options.
   *
   * @return string
   *   The system prompt.
   */
  protected function buildGenerationSystemPrompt(?string $templateId = NULL, array $options = []): string {
    // Build component type instruction with filtered SDC components.
    $componentTypeInstruction = $this->buildComponentTypeInstruction('');

    $prompt = <<<PROMPT
You are a content planning assistant specializing in creating structured content plans for web pages. Your task is to analyze the provided source materials and create a comprehensive content plan.

**Source Materials:** The content you receive may include:
- **Uploaded Documents**: Files uploaded by the user (e.g., PDFs, Word documents, text files)
- **Referenced Webpages**: Content extracted from web pages the user has referenced

When both documents and webpages are provided, synthesize information from all sources to create a cohesive content plan. Treat webpage content as supplementary reference material that can inform and enhance the content derived from uploaded documents.

Your response MUST be valid JSON matching this schema:
{
  "title": "string - The suggested page title",
  "summary": "string - A 2-3 sentence summary of the planned content",
  "target_audience": "string - Description of the intended audience",
  "estimated_read_time": "integer - Estimated reading time in minutes",
  "sections": [
    {
      "id": "string - Unique section identifier using machine names (e.g., section_001, hero_intro)",
      "title": "string - Human-readable section heading that will be displayed to users (e.g., 'Welcome to Our Story', NOT 'hero_introduction')",
      "content": "string - The planned content for this section",
      "component_type": "string - MUST be one of the exact component IDs listed below",
      "order": "integer - Display order (starting from 1)",
      "component_config": "object - Optional component configuration",
      "children": "array - Nested child sections (same structure)"
    }
  ]
}

{$componentTypeInstruction}

Guidelines:
1. Create logical content sections that flow naturally
2. CRITICAL: The component_type for each section MUST be one of the exact component IDs listed above - do not invent new IDs
3. Analyze each section's content and select the component whose description best matches its purpose
4. Keep sections focused and digestible
5. Include clear headings for navigation
6. Consider the target audience when structuring content
7. Preserve important information from the source materials (documents and/or webpages)
8. Use hierarchy (children) for complex nested content
9. When webpage content is provided, use it to supplement and enrich the content plan
PROMPT;

    // Add template-specific instructions if provided.
    if ($templateId) {
      $templateAnalysis = $this->analyzeTemplate($templateId);
      if ($templateAnalysis) {
        $prompt .= $this->buildTemplateInstructions($templateAnalysis);
      }
      else {
        $prompt .= "\n\nNote: Template ID {$templateId} specified but could not be analyzed.";
      }
    }

    // Add tone instructions from options.
    if (!empty($options['tone'])) {
      $prompt .= sprintf("\n\nWrite in a %s tone.", $options['tone']);
    }

    // Add max sections constraint.
    if (!empty($options['max_sections'])) {
      $prompt .= sprintf("\n\nLimit the plan to a maximum of %d top-level sections.", $options['max_sections']);
    }

    return $prompt;
  }

  /**
   * Gets filtered SDC component data for the AI prompt.
   *
   * Only returns Single Directory Components (SDC), excluding blocks and JS components.
   *
   * @return array
   *   Array of SDC components with their metadata, or empty array if unavailable.
   */
  protected function getSdcComponentsForPrompt(): array {
    try {
      // Get all components keyed by source.
      $allComponents = $this->pageBuilderHelper->getAllComponentsKeyedBySource();

      // Filter to only SDC components (source ID = 'sdc').
      $sdcComponents = $allComponents['sdc']['components'] ?? [];

      if (empty($sdcComponents)) {
        $this->logger->warning('No SDC components available for AI component matching.');
        return [];
      }

      return $sdcComponents;
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to get SDC component context for AI: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds the component type instruction for the AI prompt.
   *
   * Creates a clear, structured prompt with actual component IDs and descriptions
   * to help the AI select the most appropriate component for each section.
   *
   * @param string $componentContext
   *   Legacy parameter (unused). Component data is fetched directly.
   *
   * @return string
   *   The formatted instruction text with available component options.
   */
  protected function buildComponentTypeInstruction(string $componentContext): string {
    // Get filtered SDC components.
    $sdcComponents = $this->getSdcComponentsForPrompt();

    if (empty($sdcComponents)) {
      // Fallback to basic component types if no SDC components available.
      return <<<TEXT
Available component_type values: heading, text, rich_text, image, list, quote, callout

Select the component_type that best matches the content of each section.
TEXT;
    }

    // Build a structured list of available components with clear IDs and descriptions.
    $componentList = [];
    $componentIds = [];

    foreach ($sdcComponents as $componentId => $componentData) {
      $componentIds[] = $componentId;

      $name = $componentData['name'] ?? $componentId;
      $description = $componentData['description'] ?? $name;

      // Build a clear component entry.
      $entry = "- **{$componentId}**: {$name}";
      if ($description !== $name) {
        $entry .= " - {$description}";
      }

      // Add prop hints if available.
      if (!empty($componentData['props']) && is_array($componentData['props'])) {
        $propNames = array_keys($componentData['props']);
        if (count($propNames) > 0 && count($propNames) <= 5) {
          $entry .= " (props: " . implode(', ', $propNames) . ")";
        }
      }

      $componentList[] = $entry;
    }

    $componentListText = implode("\n", $componentList);
    $validIds = implode(', ', array_map(fn($id) => "\"{$id}\"", array_slice($componentIds, 0, 10)));

    return <<<TEXT
## Available Components (Single Directory Components only)

You MUST select component_type from ONLY the following component IDs. The component_type value must EXACTLY match one of these IDs:

{$componentListText}

### VALID component_type VALUES:
{$validIds}

### Component Selection Rules:
1. **CRITICAL**: The component_type MUST be one of the exact IDs listed above (e.g., if "canvas_text" is listed, use "canvas_text", NOT "text")
2. Match based on the component's description and purpose
3. For text-heavy sections, look for components with "text", "paragraph", or "content" in the name
4. For headings/titles, look for components with "heading", "title", or "hero" in the name
5. For lists, look for components with "list", "accordion", or "faq" in the name
6. For images/media, look for components with "image", "media", or "gallery" in the name
7. For quotes/testimonials, look for components with "quote", "testimonial", or "blockquote" in the name
8. If unsure, choose the component whose description best matches the section's content purpose
TEXT;
  }

  /**
   * Analyzes a Canvas page template to understand its component structure.
   *
   * @param string $templateId
   *   The Canvas page entity ID to analyze.
   *
   * @return array{
   *   title: string,
   *   total_components: int,
   *   fillable_components: int,
   *   structure: array<int, array{component_id: string, name: string, slot: ?string, has_text_inputs: bool, text_fields: array<string>}>
   * }|null
   *   Analysis of the template structure, or null if template not found.
   */
  protected function analyzeTemplate(string $templateId): ?array {
    try {
      $storage = $this->entityTypeManager->getStorage('canvas_page');
      $template = $storage->load($templateId);

      if ($template === NULL) {
        $this->logger->warning('Template @id not found for analysis.', ['@id' => $templateId]);
        return NULL;
      }

      $componentTree = $template->getComponentTree();
      $components = $componentTree->getValue();

      $analysis = [
        'title' => $template->label(),
        'total_components' => count($components),
        'fillable_components' => 0,
        'structure' => [],
      ];

      // Text-related input field names.
      $textFields = ['text', 'content', 'body', 'description', 'paragraph', 'quote', 'title', 'heading', 'heading_text', 'label', 'name'];

      foreach ($components as $index => $component) {
        $componentId = $component['component_id'] ?? 'unknown';
        $inputs = $component['inputs'] ?? '';

        // Decode JSON inputs if needed.
        if (is_string($inputs)) {
          $inputs = json_decode($inputs, TRUE) ?? [];
        }

        // Check which text fields this component has.
        $componentTextFields = [];
        foreach ($textFields as $field) {
          if (isset($inputs[$field])) {
            $componentTextFields[] = $field;
          }
        }

        $hasTextInputs = !empty($componentTextFields);
        if ($hasTextInputs) {
          $analysis['fillable_components']++;
        }

        // Get component name from registry if available.
        $componentName = $this->getComponentName($componentId);

        $analysis['structure'][] = [
          'position' => $index + 1,
          'component_id' => $componentId,
          'name' => $componentName,
          'slot' => $component['slot'] ?? NULL,
          'parent' => $component['parent_uuid'] ?? NULL,
          'has_text_inputs' => $hasTextInputs,
          'text_fields' => $componentTextFields,
        ];
      }

      return $analysis;
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to analyze template @id: @message', [
        '@id' => $templateId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets a human-readable component name from its ID.
   *
   * @param string $componentId
   *   The component ID (e.g., 'sdc.mercury.hero-billboard').
   *
   * @return string
   *   The component name or formatted ID.
   */
  protected function getComponentName(string $componentId): string {
    // Try to get from SDC components.
    $sdcComponents = $this->getSdcComponentsForPrompt();
    if (isset($sdcComponents[$componentId]['name'])) {
      return $sdcComponents[$componentId]['name'];
    }

    // Format the component ID as a readable name.
    $parts = explode('.', $componentId);
    $name = end($parts);
    return ucwords(str_replace(['-', '_'], ' ', $name));
  }

  /**
   * Builds template structure instructions for the AI prompt.
   *
   * @param array $analysis
   *   The template analysis from analyzeTemplate().
   *
   * @return string
   *   Formatted instructions about the template structure.
   */
  protected function buildTemplateInstructions(array $analysis): string {
    $instructions = "\n\n### TARGET TEMPLATE STRUCTURE\n";
    $instructions .= "You are creating content to fill a specific page template. ";
    $instructions .= "The template \"{$analysis['title']}\" has {$analysis['total_components']} components, ";
    $instructions .= "of which {$analysis['fillable_components']} can display text content.\n\n";

    $instructions .= "**IMPORTANT**: Generate exactly {$analysis['fillable_components']} sections to fill each text component in order.\n\n";

    $instructions .= "Template component structure (in order):\n";

    $sectionNum = 0;
    foreach ($analysis['structure'] as $component) {
      $indent = $component['parent'] ? '  └─ ' : '';
      $slotInfo = $component['slot'] ? " (in slot: {$component['slot']})" : '';

      if ($component['has_text_inputs']) {
        $sectionNum++;
        $fields = implode(', ', $component['text_fields']);
        $instructions .= "{$indent}{$sectionNum}. **{$component['name']}** ({$component['component_id']}){$slotInfo}\n";
        $instructions .= "{$indent}   → Fillable fields: {$fields}\n";
        $instructions .= "{$indent}   → Create section #{$sectionNum} for this component\n";
      }
      else {
        $instructions .= "{$indent}• {$component['name']} (non-text component, skip)\n";
      }
    }

    $instructions .= "\n**Section Mapping Rules**:\n";
    $instructions .= "1. Create EXACTLY {$analysis['fillable_components']} sections (one per fillable component)\n";
    $instructions .= "2. Match section order to component order in the template\n";
    $instructions .= "3. Section 1 fills component 1, section 2 fills component 2, etc.\n";
    $instructions .= "4. Use the component_type that matches each template component\n";
    $instructions .= "5. Tailor content length and style to each component's purpose\n";
    $instructions .= "\n**CRITICAL - Section Titles**:\n";
    $instructions .= "- Section titles MUST be human-readable headings (e.g., 'Welcome to Our Story', 'Get Started Today')\n";
    $instructions .= "- NEVER use machine names, snake_case, or component names as titles (e.g., NOT 'hero_introduction', NOT 'cta_button_explore')\n";
    $instructions .= "- Titles should be compelling, descriptive, and appropriate for the content\n";
    $instructions .= "- The section 'id' field can use machine names (e.g., 'section_001'), but 'title' must be human-readable\n";

    return $instructions;
  }

  /**
   * Builds the user message for content plan generation.
   *
   * @param string $documentContent
   *   The combined document content.
   * @param string $webpageContent
   *   The combined webpage content.
   * @param string $contextContent
   *   The combined context content.
   * @param array<string, mixed> $options
   *   Generation options.
   *
   * @return string
   *   The user message.
   */
  protected function buildGenerationUserMessage(string $documentContent, string $webpageContent, string $contextContent, array $options = []): string {
    $hasDocuments = !empty(trim($documentContent));
    $hasWebpages = !empty(trim($webpageContent));

    // Build intro based on what content sources are available.
    if ($hasDocuments && $hasWebpages) {
      $message = "Please create a content plan based on the following uploaded documents AND referenced webpages:\n\n";
    }
    elseif ($hasDocuments) {
      $message = "Please create a content plan for the following documents:\n\n";
    }
    elseif ($hasWebpages) {
      $message = "Please create a content plan based on the following referenced webpages:\n\n";
    }
    else {
      $message = "Please create a content plan:\n\n";
    }

    // Add document content.
    if ($hasDocuments) {
      $message .= "# Uploaded Documents\n\n";
      $message .= $documentContent;
    }

    // Add webpage content.
    if ($hasWebpages) {
      if ($hasDocuments) {
        $message .= "\n\n---\n\n";
      }
      $message .= "# Referenced Webpages\n\n";
      $message .= "The following content was extracted from referenced web pages. Use this information alongside any uploaded documents to inform the content plan.\n\n";
      $message .= $webpageContent;
    }

    if (!empty($contextContent)) {
      $message .= "\n\n## Additional Context\n\n" . $contextContent;
    }

    if (!empty($options['target_audience'])) {
      $message .= sprintf("\n\nTarget audience: %s", $options['target_audience']);
    }

    $message .= "\n\nRespond with only valid JSON, no additional text.";

    return $message;
  }

  /**
   * Builds the system prompt for plan refinement.
   *
   * @return string
   *   The system prompt.
   */
  protected function buildRefinementSystemPrompt(): string {
    // Build component type instruction with filtered SDC components.
    $componentTypeInstruction = $this->buildComponentTypeInstruction('');

    return <<<PROMPT
You are a content planning assistant helping to refine an existing content plan based on user feedback.

Your response MUST be valid JSON matching this schema:
{
  "title": "string - The page title (updated if requested)",
  "summary": "string - Updated summary",
  "target_audience": "string - Target audience",
  "estimated_read_time": "integer - Estimated reading time in minutes",
  "sections": [
    {
      "id": "string - Section identifier (preserve existing IDs when possible)",
      "title": "string - Section heading",
      "content": "string - Section content",
      "component_type": "string - MUST be one of the exact component IDs listed below",
      "order": "integer - Display order",
      "component_config": "object - Optional configuration",
      "children": "array - Nested sections"
    }
  ],
  "refinement_summary": "string - Brief description of changes made",
  "affected_sections": ["array of section IDs that were modified"]
}

{$componentTypeInstruction}

Guidelines:
1. Preserve the overall structure unless changes are specifically requested
2. Only modify sections that need to change based on the instructions
3. Maintain section IDs for unchanged sections
4. Create new IDs for newly added sections
5. Provide a clear summary of what was changed
6. CRITICAL: The component_type for each section MUST be one of the exact component IDs listed above
PROMPT;
  }

  /**
   * Builds the user message for plan refinement.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The existing plan.
   * @param string $refinementPrompt
   *   The user's refinement instructions.
   * @param string $contextContent
   *   The formatted context content to include.
   *
   * @return string
   *   The user message.
   */
  protected function buildRefinementUserMessage(ContentPlan $plan, string $refinementPrompt, string $contextContent = ''): string {
    // Build a minimal plan representation without computed fields and history.
    $minimalPlan = $this->buildMinimalPlanForRefinement($plan);
    $currentPlanJson = Json::encode($minimalPlan);

    $message = sprintf(
      "Current plan:\n%s\n\nRefinement instructions:\n%s",
      $currentPlanJson,
      $refinementPrompt
    );

    if (!empty($contextContent)) {
      $message .= "\n\n## Context Guidelines\n\nWhen refining the plan, ensure the changes align with these guidelines:\n\n" . $contextContent;
    }

    $message .= "\n\nRespond with only valid JSON, no additional text.";

    return $message;
  }

  /**
   * Builds a minimal plan representation for refinement to reduce token usage.
   *
   * Excludes refinement_history, computed totals, and truncates long content.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The full content plan.
   *
   * @return array
   *   A minimal array representation of the plan.
   */
  protected function buildMinimalPlanForRefinement(ContentPlan $plan): array {
    $sections = [];
    foreach ($plan->sections as $section) {
      // Truncate section content for refinement (AI doesn't need full content).
      $content = $section->content;
      if (mb_strlen($content) > 500) {
        $content = mb_substr($content, 0, 500) . '...';
      }

      $sectionData = [
        'id' => $section->id,
        'title' => $section->title,
        'content' => $content,
        'component_type' => $section->componentType,
        'order' => $section->order,
      ];

      // Only include children if they exist.
      if (!empty($section->children)) {
        $sectionData['children'] = array_map(function ($child) {
          $childContent = $child->content;
          if (mb_strlen($childContent) > 300) {
            $childContent = mb_substr($childContent, 0, 300) . '...';
          }
          return [
            'id' => $child->id,
            'title' => $child->title,
            'content' => $childContent,
            'component_type' => $child->componentType,
            'order' => $child->order,
          ];
        }, $section->children);
      }

      $sections[] = $sectionData;
    }

    return [
      'title' => $plan->title,
      'summary' => $plan->summary,
      'target_audience' => $plan->targetAudience,
      'estimated_read_time' => $plan->estimatedReadTime,
      'sections' => $sections,
      // Exclude: refinement_history, total_section_count, total_word_count, status, etc.
    ];
  }

  /**
   * Executes an AI call with retry logic for JSON parsing failures.
   *
   * @param mixed $provider
   *   The AI provider instance.
   * @param string $modelId
   *   The model ID to use.
   * @param string $systemPrompt
   *   The system prompt.
   * @param string $userMessage
   *   The user message.
   *
   * @return array<string, mixed>
   *   The parsed JSON response.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\PlanGenerationException
   *   When AI call or JSON parsing fails after all retries.
   */
  protected function executeAiCallWithRetries(mixed $provider, string $modelId, string $systemPrompt, string $userMessage): array {
    $lastException = NULL;
    $providerLabel = method_exists($provider, 'getPluginId') ? $provider->getPluginId() : 'unknown';

    for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
      try {
        // Create chat input.
        $chatInput = new ChatInput([
          new ChatMessage('user', $userMessage),
        ]);
        $chatInput->setSystemPrompt($systemPrompt);

        // Execute chat.
        $response = $provider->chat($chatInput, $modelId, ['ai_content_preparation_wizard']);

        // Get the response text.
        $normalized = $response->getNormalized();
        $responseText = $normalized->getText();

        // Parse JSON response.
        $data = $this->parseJsonResponse($responseText);

        $this->logger->info('Successfully generated content plan on attempt @attempt', [
          '@attempt' => $attempt,
        ]);

        return $data;
      }
      catch (PlanGenerationException $e) {
        // JSON parsing failed, retry.
        $lastException = $e;
        $this->logger->warning('JSON parsing failed on attempt @attempt: @message', [
          '@attempt' => $attempt,
          '@message' => $e->getMessage(),
        ]);
      }
      catch (\Exception $e) {
        // AI provider error.
        throw new PlanGenerationException(
          sprintf('AI provider error: %s', $e->getMessage()),
          $providerLabel,
          $modelId,
          0,
          $e
        );
      }
    }

    throw new PlanGenerationException(
      sprintf('Failed to parse AI response after %d attempts.', self::MAX_RETRIES),
      $providerLabel,
      $modelId,
      0,
      $lastException
    );
  }

  /**
   * Parses a JSON response from the AI.
   *
   * @param string $responseText
   *   The raw response text.
   *
   * @return array<string, mixed>
   *   The parsed JSON data.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\PlanGenerationException
   *   When JSON parsing fails.
   */
  protected function parseJsonResponse(string $responseText): array {
    // Clean the response - remove markdown code blocks if present.
    $cleaned = $responseText;

    // Remove markdown code fence if present.
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $cleaned, $matches)) {
      $cleaned = $matches[1];
    }

    $cleaned = trim($cleaned);

    // Try to parse JSON.
    $data = Json::decode($cleaned);

    if ($data === NULL && json_last_error() !== JSON_ERROR_NONE) {
      throw new PlanGenerationException(
        sprintf('Invalid JSON in AI response: %s', json_last_error_msg())
      );
    }

    if (!is_array($data)) {
      throw new PlanGenerationException('AI response did not contain a valid object.');
    }

    return $data;
  }

  /**
   * Parses a content plan response from AI data.
   *
   * @param array<string, mixed> $data
   *   The parsed JSON data.
   * @param array<string> $sourceDocumentIds
   *   IDs of source documents.
   * @param string|null $templateId
   *   The template ID used.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\ContentPlan
   *   The created content plan.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\PlanGenerationException
   *   When required fields are missing.
   */
  protected function parseContentPlanResponse(array $data, array $sourceDocumentIds, ?string $templateId): ContentPlan {
    // Validate required fields.
    $requiredFields = ['title', 'summary', 'sections'];
    foreach ($requiredFields as $field) {
      if (empty($data[$field])) {
        throw new PlanGenerationException(
          sprintf('AI response missing required field: %s', $field)
        );
      }
    }

    // Parse sections.
    $sections = [];
    foreach ($data['sections'] as $index => $sectionData) {
      $sections[] = $this->parseSectionData($sectionData, $index);
    }

    return ContentPlan::create(
      title: $data['title'],
      summary: $data['summary'],
      sections: $sections,
      targetAudience: $data['target_audience'] ?? 'General audience',
      estimatedReadTime: (int) ($data['estimated_read_time'] ?? 5),
      sourceDocumentIds: $sourceDocumentIds,
      templateId: $templateId,
    );
  }

  /**
   * Parses section data from AI response.
   *
   * @param array<string, mixed> $sectionData
   *   The section data.
   * @param int $index
   *   The section index for default ordering.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\PlanSection
   *   The parsed section.
   */
  protected function parseSectionData(array $sectionData, int $index): PlanSection {
    // Handle children recursively.
    $children = [];
    if (!empty($sectionData['children'])) {
      foreach ($sectionData['children'] as $childIndex => $childData) {
        $children[] = $this->parseSectionData($childData, $childIndex);
      }
    }

    // Use provided ID or generate one.
    $id = $sectionData['id'] ?? sprintf('section_%03d', $index + 1);

    // Ensure content is a string (AI may return array).
    $content = $sectionData['content'] ?? '';
    if (is_array($content)) {
      $content = implode("\n\n", array_map(function ($item) {
        return is_array($item) ? json_encode($item) : (string) $item;
      }, $content));
    }

    // Ensure title is a string.
    $title = $sectionData['title'] ?? 'Untitled Section';
    if (is_array($title)) {
      $title = implode(' ', $title);
    }

    return new PlanSection(
      id: $id,
      title: (string) $title,
      content: (string) $content,
      componentType: $sectionData['component_type'] ?? 'text',
      order: (int) ($sectionData['order'] ?? $index + 1),
      componentConfig: $sectionData['component_config'] ?? [],
      children: $children,
    );
  }

  /**
   * Parses a refined plan response from AI data.
   *
   * @param array<string, mixed> $data
   *   The parsed JSON data.
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $originalPlan
   *   The original plan being refined.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\ContentPlan
   *   The refined content plan.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\PlanGenerationException
   *   When parsing fails.
   */
  protected function parseRefinedPlanResponse(array $data, ContentPlan $originalPlan): ContentPlan {
    // Parse sections.
    $sections = [];
    if (!empty($data['sections'])) {
      foreach ($data['sections'] as $index => $sectionData) {
        $sections[] = $this->parseSectionData($sectionData, $index);
      }
    }

    // Create new plan with refined data, preserving original metadata.
    return new ContentPlan(
      id: $originalPlan->id,
      title: $data['title'] ?? $originalPlan->title,
      summary: $data['summary'] ?? $originalPlan->summary,
      sections: !empty($sections) ? $sections : $originalPlan->sections,
      targetAudience: $data['target_audience'] ?? $originalPlan->targetAudience,
      estimatedReadTime: (int) ($data['estimated_read_time'] ?? $originalPlan->estimatedReadTime),
      generatedAt: $originalPlan->generatedAt,
      status: $originalPlan->status,
      refinementHistory: $originalPlan->refinementHistory,
      sourceDocumentIds: $originalPlan->sourceDocumentIds,
      templateId: $originalPlan->templateId,
    );
  }

}
