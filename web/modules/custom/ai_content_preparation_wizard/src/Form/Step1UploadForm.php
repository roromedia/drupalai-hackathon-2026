<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Form;

use Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException;
use Drupal\ai_content_preparation_wizard\Service\DocumentProcessingServiceInterface;
use Drupal\ai_content_preparation_wizard\Service\WizardSessionManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Step 1 of the Content Preparation Wizard - Document Upload.
 *
 * This form allows users to upload documents, select AI contexts,
 * and choose a canvas template for content generation.
 */
class Step1UploadForm extends FormBase {

  /**
   * Constructs a Step1UploadForm object.
   *
   * @param \Drupal\ai_content_preparation_wizard\Service\WizardSessionManagerInterface $wizardSessionManager
   *   The wizard session manager.
   * @param \Drupal\ai_content_preparation_wizard\Service\DocumentProcessingServiceInterface $documentProcessingService
   *   The document processing service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly WizardSessionManagerInterface $wizardSessionManager,
    protected readonly DocumentProcessingServiceInterface $documentProcessingService,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_content_preparation_wizard.wizard_session_manager'),
      $container->get('ai_content_preparation_wizard.document_processing'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_content_preparation_wizard_step1';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->configFactory->get('ai_content_preparation_wizard.settings');

    // Get existing session data if available.
    $session = $this->wizardSessionManager->getSession();
    $existingFileIds = $session?->getUploadedFileIds() ?? [];
    $existingContexts = $session?->getSelectedContexts() ?? ['site_structure', 'brand_guidelines'];
    $existingTemplate = $session?->getTemplateId() ?? '';

    // Step indicator.
    $form['step_indicator'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard-step-indicator']],
      'markup' => [
        '#markup' => '<div class="step active"><span class="step-number">1</span> ' . $this->t('Upload Documents') . '</div>'
          . '<div class="step"><span class="step-number">2</span> ' . $this->t('Review Plan') . '</div>'
          . '<div class="step"><span class="step-number">3</span> ' . $this->t('Create Content') . '</div>',
      ],
    ];

    // File upload section.
    $form['upload_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Document Upload'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['upload-section']],
    ];

    // Get allowed extensions and max file size from config.
    $allowedExtensions = $config->get('allowed_extensions') ?? 'txt,docx,pdf';
    $maxFileSize = $config->get('max_file_size') ?? 52428800;

    // File upload field with drag and drop wrapper.
    $form['upload_section']['files_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['file-upload-wrapper', 'drag-drop-zone'],
      ],
    ];

    $form['upload_section']['files_wrapper']['files'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Documents'),
      '#description' => $this->t('Upload one or more documents to process. Allowed file types: @extensions. Maximum file size: @size.', [
        '@extensions' => $allowedExtensions,
        '@size' => format_size($maxFileSize),
      ]),
      '#multiple' => TRUE,
      '#upload_location' => 'private://ai_content_preparation_wizard',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => $allowedExtensions],
        'FileSizeLimit' => ['fileLimit' => $maxFileSize],
      ],
      '#default_value' => $existingFileIds,
      '#required' => FALSE,
    ];

    $form['upload_section']['files_wrapper']['drag_drop_help'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['drag-drop-help']],
      'markup' => [
        '#markup' => '<p class="drag-drop-text">' . $this->t('Drag and drop files here or click to browse') . '</p>',
      ],
    ];

    // AI Contexts section.
    $form['ai_contexts_section'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Processing Contexts'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['ai-contexts-section']],
    ];

    $form['ai_contexts_section']['ai_contexts'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select AI Contexts'),
      '#description' => $this->t('Choose which contextual information the AI should consider when processing your documents.'),
      '#options' => [
        'site_structure' => $this->t('Site Structure - Use the existing site architecture to guide content placement'),
        'brand_guidelines' => $this->t('Brand Guidelines - Apply brand voice and style guidelines'),
        'seo_requirements' => $this->t('SEO Requirements - Optimize content for search engines'),
        'accessibility_standards' => $this->t('Accessibility Standards - Ensure content meets accessibility requirements'),
      ],
      '#default_value' => $existingContexts,
    ];

    // AI Template section.
    $form['template_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Canvas Template'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['template-section']],
    ];

    $form['template_section']['ai_template'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Canvas Template'),
      '#description' => $this->t('Choose a canvas template to guide the AI in generating your content plan.'),
      '#empty_option' => $this->t('- Select template -'),
      '#options' => $this->getCanvasTemplateOptions(),
      '#default_value' => $existingTemplate,
    ];

    // Form actions.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process Documents'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['wizard-next-button']],
    ];

    // Attach library for styling.
    $form['#attached']['library'][] = 'ai_content_preparation_wizard/wizard';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Get uploaded file IDs.
    $fileIds = $form_state->getValue('files');

    // Filter out empty values (removed files).
    $fileIds = array_filter($fileIds ?? []);

    if (empty($fileIds)) {
      $form_state->setErrorByName('files', $this->t('Please upload at least one document to proceed.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Get form values.
    $fileIds = array_filter($form_state->getValue('files') ?? []);
    $aiContexts = array_filter($form_state->getValue('ai_contexts') ?? []);
    $templateId = $form_state->getValue('ai_template') ?? '';

    // Get or create a wizard session.
    $session = $this->wizardSessionManager->getSession();
    if ($session === NULL) {
      $session = $this->wizardSessionManager->createSession();
    }

    // Clear any existing processed documents to start fresh.
    $session->clearProcessedDocuments();

    // Store the uploaded file IDs and settings in session.
    $session->setUploadedFileIds(array_map('intval', $fileIds));
    $session->setSelectedContexts(array_keys($aiContexts));

    if (!empty($templateId)) {
      $session->setTemplateId($templateId);
    }

    // Update the session.
    $this->wizardSessionManager->updateSession($session);

    // Load and process each uploaded file.
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $processedCount = 0;
    $errorMessages = [];

    foreach ($fileIds as $fileId) {
      /** @var \Drupal\file\FileInterface|null $file */
      $file = $fileStorage->load($fileId);

      if (!$file instanceof FileInterface) {
        $errorMessages[] = $this->t('Could not load file with ID @id.', ['@id' => $fileId]);
        continue;
      }

      // Make the file permanent so it persists.
      if ($file->isTemporary()) {
        $file->setPermanent();
        $file->save();
      }

      try {
        // Process the document.
        $processedDocument = $this->documentProcessingService->process($file);

        // Add the processed document to the session.
        $this->wizardSessionManager->addProcessedDocument($processedDocument);
        $processedCount++;
      }
      catch (DocumentProcessingException $e) {
        $errorMessages[] = $this->t('Error processing @filename: @error', [
          '@filename' => $file->getFilename(),
          '@error' => $e->getMessage(),
        ]);
      }
      catch (\Exception $e) {
        $errorMessages[] = $this->t('Unexpected error processing @filename: @error', [
          '@filename' => $file->getFilename(),
          '@error' => $e->getMessage(),
        ]);
      }
    }

    // Display results to user.
    if ($processedCount > 0) {
      $this->messenger()->addStatus($this->formatPlural(
        $processedCount,
        'Successfully processed 1 document.',
        'Successfully processed @count documents.'
      ));
    }

    // Display any error messages.
    foreach ($errorMessages as $error) {
      $this->messenger()->addError($error);
    }

    // If we processed at least one document, proceed to next step.
    if ($processedCount > 0) {
      $this->wizardSessionManager->advanceStep();
      // Redirect to step 2 (plan review) when route is available.
      // For now, stay on wizard route which will show appropriate step.
      $form_state->setRedirect('ai_content_preparation_wizard.wizard');
    }
  }

  /**
   * Gets available canvas template options.
   *
   * @return array<string, string>
   *   An array of template options keyed by template ID.
   */
  protected function getCanvasTemplateOptions(): array {
    // Placeholder implementation - will be populated when canvas templates
    // are implemented. For now, return some default options.
    return [
      'landing_page' => $this->t('Landing Page'),
      'blog_article' => $this->t('Blog Article'),
      'product_page' => $this->t('Product Page'),
      'service_page' => $this->t('Service Page'),
      'about_page' => $this->t('About Page'),
      'contact_page' => $this->t('Contact Page'),
      'faq_page' => $this->t('FAQ Page'),
      'news_article' => $this->t('News Article'),
    ];
  }

}
