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

    $form['document_processing']['pandoc_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pandoc executable path'),
      '#description' => $this->t('The full path to the Pandoc executable. Leave empty to use the system PATH. Example: /usr/bin/pandoc'),
      '#default_value' => $config->get('pandoc_path') ?? '',
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

    // Validate pandoc path if provided.
    $pandoc_path = $form_state->getValue('pandoc_path');
    if (!empty($pandoc_path)) {
      if (!file_exists($pandoc_path)) {
        $form_state->setErrorByName('pandoc_path', $this->t('The specified Pandoc executable path does not exist: @path', [
          '@path' => $pandoc_path,
        ]));
      }
      elseif (!is_executable($pandoc_path)) {
        $form_state->setErrorByName('pandoc_path', $this->t('The specified Pandoc path exists but is not executable: @path', [
          '@path' => $pandoc_path,
        ]));
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
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_content_preparation_wizard.settings')
      ->set('pandoc_path', $form_state->getValue('pandoc_path'))
      ->set('max_file_size', (int) $form_state->getValue('max_file_size'))
      ->set('allowed_extensions', $form_state->getValue('allowed_extensions'))
      ->set('default_ai_provider', $form_state->getValue('default_ai_provider'))
      ->set('default_ai_model', $form_state->getValue('default_ai_model'))
      ->set('session_timeout', (int) $form_state->getValue('session_timeout'))
      ->set('enable_refinement', (bool) $form_state->getValue('enable_refinement'))
      ->set('max_refinement_iterations', (int) $form_state->getValue('max_refinement_iterations'))
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
