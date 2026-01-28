<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Content Preparation Wizard settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed configuration manager.
   * @param \Drupal\ai\AiProviderPluginManager $ai_provider_manager
   *   The AI provider plugin manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    AiProviderPluginManager $ai_provider_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->aiProviderManager = $ai_provider_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai.provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_content_preparation_wizard_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_content_preparation_wizard.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_content_preparation_wizard.settings');

    // Document Processing Settings.
    $form['document_processing'] = [
      '#type' => 'details',
      '#title' => $this->t('Document Processing'),
      '#open' => TRUE,
    ];

    $default_processors = "pdf|/usr/bin/pdftotext\ntxt,md,docx|/usr/bin/pandoc";
    $form['document_processing']['document_processors'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Document processors'),
      '#description' => $this->t('One processor per line. Format: extension(s)|path_to_executable. Example: pdf|/usr/bin/pdftotext'),
      '#default_value' => $config->get('document_processors') ?? $default_processors,
      '#rows' => 5,
    ];

    $form['document_processing']['enable_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable document conversion logging'),
      '#description' => $this->t('Enable logging of converted documents to private://ai_content_preparation_wizard/logs'),
      '#default_value' => $config->get('enable_logging') ?? FALSE,
    ];

    $form['document_processing']['max_file_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum file size'),
      '#description' => $this->t('Maximum allowed file size in bytes. Default is 52428800 (50 MB).'),
      '#default_value' => $config->get('max_file_size') ?? 52428800,
      '#min' => 1024,
      '#max' => 524288000,
      '#required' => TRUE,
    ];

    $form['document_processing']['allowed_extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed file extensions'),
      '#description' => $this->t('Comma-separated list of allowed file extensions (without dots). Example: txt,docx,pdf'),
      '#default_value' => $config->get('allowed_extensions') ?? 'txt,docx,pdf',
      '#required' => TRUE,
    ];

    // AI Settings.
    $form['ai_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Settings'),
      '#open' => TRUE,
    ];

    $form['ai_settings']['default_ai_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Default AI provider'),
      '#description' => $this->t('Select the default AI provider for content plan generation.'),
      '#options' => $this->getAiProviderOptions(),
      '#default_value' => $config->get('default_ai_provider') ?? '',
      '#empty_option' => $this->t('- Select provider -'),
    ];

    $form['ai_settings']['default_ai_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default AI model'),
      '#description' => $this->t('The model identifier to use with the selected provider. Example: gpt-4, claude-3-opus'),
      '#default_value' => $config->get('default_ai_model') ?? '',
    ];

    // Session Settings.
    $form['session_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Session Settings'),
      '#open' => TRUE,
    ];

    $form['session_settings']['session_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Session timeout'),
      '#description' => $this->t('Time in seconds before wizard session data expires. Default is 3600 (1 hour).'),
      '#default_value' => $config->get('session_timeout') ?? 3600,
      '#min' => 300,
      '#max' => 86400,
      '#required' => TRUE,
      '#field_suffix' => $this->t('seconds'),
    ];

    // Webpage Scraping Settings.
    $form['webpage_scraping'] = [
      '#type' => 'details',
      '#title' => $this->t('Webpage Scraping'),
      '#open' => TRUE,
    ];

    $form['webpage_scraping']['webpage_processor_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Webpage processor mode'),
      '#description' => $this->t('Select how webpages should be fetched and converted to markdown.'),
      '#options' => [
        'basic' => $this->t('Basic (built-in PHP processor)'),
        'advanced' => $this->t('Advanced (external binary)'),
      ],
      '#default_value' => $config->get('webpage_processor_mode') ?? 'basic',
    ];

    $form['webpage_scraping']['webpage_processor_binary'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webpage processor binary path'),
      '#description' => $this->t('Path to external scraping tool (e.g., /usr/local/bin/trafilatura, /usr/bin/readability-cli). The binary should accept a URL as argument and output markdown to stdout.'),
      '#default_value' => $config->get('webpage_processor_binary') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="webpage_processor_mode"]' => ['value' => 'advanced'],
        ],
        'required' => [
          ':input[name="webpage_processor_mode"]' => ['value' => 'advanced'],
        ],
      ],
    ];

    $form['webpage_scraping']['webpage_processor_arguments'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Additional arguments'),
      '#description' => $this->t('Additional command-line arguments to pass to the binary. Use {url} as placeholder for the URL. Example: --output-format markdown {url}'),
      '#default_value' => $config->get('webpage_processor_arguments') ?? '{url}',
      '#states' => [
        'visible' => [
          ':input[name="webpage_processor_mode"]' => ['value' => 'advanced'],
        ],
      ],
    ];

    $form['webpage_scraping']['webpage_processor_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Processor timeout'),
      '#description' => $this->t('Maximum time in seconds to wait for webpage processing.'),
      '#default_value' => $config->get('webpage_processor_timeout') ?? 30,
      '#min' => 5,
      '#max' => 120,
      '#field_suffix' => $this->t('seconds'),
    ];

    // Refinement Settings.
    $form['refinement_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Plan Refinement'),
      '#open' => TRUE,
    ];

    $form['refinement_settings']['enable_refinement'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable plan refinement'),
      '#description' => $this->t('Allow users to request AI refinement of generated content plans.'),
      '#default_value' => $config->get('enable_refinement') ?? TRUE,
    ];

    $form['refinement_settings']['max_refinement_iterations'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum refinement iterations'),
      '#description' => $this->t('Maximum number of times a user can refine a content plan.'),
      '#default_value' => $config->get('max_refinement_iterations') ?? 5,
      '#min' => 1,
      '#max' => 20,
      '#states' => [
        'visible' => [
          ':input[name="enable_refinement"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="enable_refinement"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate document processors.
    $document_processors = $form_state->getValue('document_processors');
    if (!empty($document_processors)) {
      $lines = array_filter(array_map('trim', explode("\n", $document_processors)));
      foreach ($lines as $line_number => $line) {
        // Skip empty lines.
        if (empty($line)) {
          continue;
        }

        // Validate format: extension(s)|path.
        if (!str_contains($line, '|')) {
          $form_state->setErrorByName('document_processors', $this->t('Line @line: Invalid format. Each line must follow the format: extension(s)|path_to_executable', [
            '@line' => $line_number + 1,
          ]));
          continue;
        }

        $parts = explode('|', $line, 2);
        if (count($parts) !== 2) {
          $form_state->setErrorByName('document_processors', $this->t('Line @line: Invalid format. Each line must follow the format: extension(s)|path_to_executable', [
            '@line' => $line_number + 1,
          ]));
          continue;
        }

        [$extensions, $path] = $parts;
        $extensions = trim($extensions);
        $path = trim($path);

        // Validate extensions format.
        if (empty($extensions)) {
          $form_state->setErrorByName('document_processors', $this->t('Line @line: Extensions cannot be empty.', [
            '@line' => $line_number + 1,
          ]));
          continue;
        }

        $extension_list = array_map('trim', explode(',', $extensions));
        foreach ($extension_list as $ext) {
          if (!preg_match('/^[a-zA-Z0-9]+$/', $ext)) {
            $form_state->setErrorByName('document_processors', $this->t('Line @line: Invalid extension "@ext". Extensions should only contain alphanumeric characters.', [
              '@line' => $line_number + 1,
              '@ext' => $ext,
            ]));
            break;
          }
        }

        // Validate path is not empty.
        if (empty($path)) {
          $form_state->setErrorByName('document_processors', $this->t('Line @line: Executable path cannot be empty.', [
            '@line' => $line_number + 1,
          ]));
          continue;
        }

        // Validate executable exists and is executable.
        if (!file_exists($path)) {
          $form_state->setErrorByName('document_processors', $this->t('Line @line: The specified executable path does not exist: @path', [
            '@line' => $line_number + 1,
            '@path' => $path,
          ]));
        }
        elseif (!is_executable($path)) {
          $form_state->setErrorByName('document_processors', $this->t('Line @line: The specified path exists but is not executable: @path', [
            '@line' => $line_number + 1,
            '@path' => $path,
          ]));
        }
      }
    }

    // Validate allowed extensions format.
    $extensions = $form_state->getValue('allowed_extensions');
    if (!empty($extensions)) {
      $extension_list = array_map('trim', explode(',', $extensions));
      foreach ($extension_list as $ext) {
        if (!preg_match('/^[a-zA-Z0-9]+$/', $ext)) {
          $form_state->setErrorByName('allowed_extensions', $this->t('Invalid extension format: @ext. Extensions should only contain alphanumeric characters.', [
            '@ext' => $ext,
          ]));
          break;
        }
      }
    }

    // Validate webpage processor settings.
    $processorMode = $form_state->getValue('webpage_processor_mode');
    if ($processorMode === 'advanced') {
      $binaryPath = trim($form_state->getValue('webpage_processor_binary') ?? '');
      if (empty($binaryPath)) {
        $form_state->setErrorByName('webpage_processor_binary', $this->t('Binary path is required when using Advanced mode.'));
      }
      elseif (!file_exists($binaryPath)) {
        $form_state->setErrorByName('webpage_processor_binary', $this->t('The specified binary does not exist: @path', [
          '@path' => $binaryPath,
        ]));
      }
      elseif (!is_executable($binaryPath)) {
        $form_state->setErrorByName('webpage_processor_binary', $this->t('The specified binary is not executable: @path', [
          '@path' => $binaryPath,
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_content_preparation_wizard.settings')
      ->set('document_processors', $form_state->getValue('document_processors'))
      ->set('enable_logging', (bool) $form_state->getValue('enable_logging'))
      ->set('max_file_size', (int) $form_state->getValue('max_file_size'))
      ->set('allowed_extensions', $form_state->getValue('allowed_extensions'))
      ->set('default_ai_provider', $form_state->getValue('default_ai_provider'))
      ->set('default_ai_model', $form_state->getValue('default_ai_model'))
      ->set('session_timeout', (int) $form_state->getValue('session_timeout'))
      ->set('enable_refinement', (bool) $form_state->getValue('enable_refinement'))
      ->set('max_refinement_iterations', (int) $form_state->getValue('max_refinement_iterations'))
      ->set('webpage_processor_mode', $form_state->getValue('webpage_processor_mode'))
      ->set('webpage_processor_binary', $form_state->getValue('webpage_processor_binary'))
      ->set('webpage_processor_arguments', $form_state->getValue('webpage_processor_arguments'))
      ->set('webpage_processor_timeout', (int) $form_state->getValue('webpage_processor_timeout'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get available AI provider options.
   *
   * @return array
   *   An array of provider options keyed by provider ID.
   */
  protected function getAiProviderOptions(): array {
    $options = [];

    try {
      // Get providers that support the 'chat' operation type.
      $providers = $this->aiProviderManager->getProvidersForOperationType('chat', TRUE);
      foreach ($providers as $id => $definition) {
        $options[$id] = $definition['label'] ?? $id;
      }
    }
    catch (\Exception $e) {
      // If there's an error getting providers, return empty options.
      // This allows the form to still load even if AI module has issues.
    }

    return $options;
  }

}
