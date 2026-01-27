<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\ai_content_preparation_wizard\Exception\PlanGenerationException;
use Drupal\ai_content_preparation_wizard\Model\AIContext;
use Drupal\ai_content_preparation_wizard\Model\ContentPlan;
use Drupal\ai_content_preparation_wizard\Model\PlanSection;
use Drupal\ai_content_preparation_wizard\Model\ProcessedDocument;
use Drupal\ai_content_preparation_wizard\Model\RefinementEntry;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   */
  public function __construct(
    protected readonly AiProviderPluginManager $aiProviderManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly UuidInterface $uuid,
    protected readonly TimeInterface $time,
  ) {
    $this->logger = $this->loggerFactory->get('ai_content_preparation_wizard');
  }

  /**
   * {@inheritdoc}
   */
  public function generate(array $documents, array $contexts = [], ?string $templateId = NULL, array $options = []): ContentPlan {
    // Validate we have documents to process.
    if (empty($documents)) {
      throw new PlanGenerationException('No documents provided for plan generation.');
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

    // Build the context content.
    $contextContent = $this->buildContextContent($contexts);

    // Build the system prompt.
    $systemPrompt = $this->buildGenerationSystemPrompt($templateId, $options);

    // Build the user message.
    $userMessage = $this->buildGenerationUserMessage($documentContent, $contextContent, $options);

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
  public function refine(ContentPlan $plan, string $refinementPrompt, array $options = []): ContentPlan {
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

    // Build the refinement prompt.
    $systemPrompt = $this->buildRefinementSystemPrompt();
    $userMessage = $this->buildRefinementUserMessage($plan, $refinementPrompt);

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

      $parts[] = sprintf(
        "## Document: %s\n\n%s",
        $document->fileName,
        $document->markdownContent
      );
    }

    return implode("\n\n---\n\n", $parts);
  }

  /**
   * Builds combined content from all AI contexts.
   *
   * @param array<\Drupal\ai_content_preparation_wizard\Model\AIContext> $contexts
   *   The AI contexts.
   *
   * @return string
   *   The combined context content.
   */
  protected function buildContextContent(array $contexts): string {
    if (empty($contexts)) {
      return '';
    }

    return AIContext::combineForPrompt($contexts, TRUE);
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
    $prompt = <<<PROMPT
You are a content planning assistant specializing in creating structured content plans for web pages. Your task is to analyze the provided documents and create a comprehensive content plan.

Your response MUST be valid JSON matching this schema:
{
  "title": "string - The suggested page title",
  "summary": "string - A 2-3 sentence summary of the planned content",
  "target_audience": "string - Description of the intended audience",
  "estimated_read_time": "integer - Estimated reading time in minutes",
  "sections": [
    {
      "id": "string - Unique section identifier (e.g., section_001)",
      "title": "string - Section heading",
      "content": "string - The planned content for this section",
      "component_type": "string - One of: heading, text, rich_text, image, list, quote, callout",
      "order": "integer - Display order (starting from 1)",
      "component_config": "object - Optional component configuration",
      "children": "array - Nested child sections (same structure)"
    }
  ]
}

Guidelines:
1. Create logical content sections that flow naturally
2. Use appropriate component types for different content
3. Keep sections focused and digestible
4. Include clear headings for navigation
5. Consider the target audience when structuring content
6. Preserve important information from the source documents
7. Use hierarchy (children) for complex nested content
PROMPT;

    // Add template-specific instructions if provided.
    if ($templateId) {
      $prompt .= "\n\nUse template ID: {$templateId} for additional formatting guidance.";
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
   * Builds the user message for content plan generation.
   *
   * @param string $documentContent
   *   The combined document content.
   * @param string $contextContent
   *   The combined context content.
   * @param array<string, mixed> $options
   *   Generation options.
   *
   * @return string
   *   The user message.
   */
  protected function buildGenerationUserMessage(string $documentContent, string $contextContent, array $options = []): string {
    $message = "Please create a content plan for the following documents:\n\n";
    $message .= $documentContent;

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
      "component_type": "string - One of: heading, text, rich_text, image, list, quote, callout",
      "order": "integer - Display order",
      "component_config": "object - Optional configuration",
      "children": "array - Nested sections"
    }
  ],
  "refinement_summary": "string - Brief description of changes made",
  "affected_sections": ["array of section IDs that were modified"]
}

Guidelines:
1. Preserve the overall structure unless changes are specifically requested
2. Only modify sections that need to change based on the instructions
3. Maintain section IDs for unchanged sections
4. Create new IDs for newly added sections
5. Provide a clear summary of what was changed
PROMPT;
  }

  /**
   * Builds the user message for plan refinement.
   *
   * @param \Drupal\ai_content_preparation_wizard\Model\ContentPlan $plan
   *   The existing plan.
   * @param string $refinementPrompt
   *   The user's refinement instructions.
   *
   * @return string
   *   The user message.
   */
  protected function buildRefinementUserMessage(ContentPlan $plan, string $refinementPrompt): string {
    $currentPlanJson = Json::encode($plan->toArray());

    return sprintf(
      "Current plan:\n%s\n\nRefinement instructions:\n%s\n\nRespond with only valid JSON, no additional text.",
      $currentPlanJson,
      $refinementPrompt
    );
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

    return new PlanSection(
      id: $id,
      title: $sectionData['title'] ?? 'Untitled Section',
      content: $sectionData['content'] ?? '',
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
