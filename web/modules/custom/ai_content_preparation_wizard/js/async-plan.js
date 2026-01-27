/**
 * @file
 * JavaScript for async content plan generation in the Content Preparation Wizard.
 */

(function (Drupal, drupalSettings) {

  'use strict';

  /**
   * Async plan loading behavior.
   */
  Drupal.behaviors.asyncPlanGeneration = {
    attach: function (context, settings) {
      // Find loading element.
      var loadingEl = document.getElementById('plan-loading');

      // If no loading element or already processed, skip.
      if (!loadingEl || loadingEl.dataset.asyncLoaded === 'true') {
        return;
      }

      // Mark as loading to prevent duplicate calls.
      loadingEl.dataset.asyncLoaded = 'true';

      // Get endpoint from settings.
      var endpoint = null;
      if (settings && settings.aiContentPreparationWizard) {
        endpoint = settings.aiContentPreparationWizard.asyncPlanEndpoint;
      }
      if (!endpoint && drupalSettings && drupalSettings.aiContentPreparationWizard) {
        endpoint = drupalSettings.aiContentPreparationWizard.asyncPlanEndpoint;
      }

      if (!endpoint) {
        return;
      }

      // Start loading the plan.
      this.loadPlanAsync(endpoint, context);
    },

    /**
     * Loads the content plan asynchronously.
     */
    loadPlanAsync: function (endpoint, context) {
      var self = this;
      var statusEl = document.getElementById('plan-loading-status');
      var loadingEl = document.getElementById('plan-loading');

      // Update status messages during loading.
      var statusMessages = [
        Drupal.t('Analyzing document content...'),
        Drupal.t('Identifying key sections...'),
        Drupal.t('Selecting appropriate components...'),
        Drupal.t('Generating section content...'),
        Drupal.t('Finalizing content plan...')
      ];

      var messageIndex = 0;
      var statusInterval = setInterval(function () {
        if (statusEl && messageIndex < statusMessages.length) {
          statusEl.textContent = statusMessages[messageIndex];
          messageIndex++;
        }
      }, 3000);

      // Make the AJAX request.
      fetch(endpoint, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
      })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Network response was not ok: ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        clearInterval(statusInterval);

        if (data.success) {
          self.renderPlan(data.plan, data.componentOptions, context);
        } else {
          self.showError(data.error || Drupal.t('Failed to generate plan.'));
        }
      })
      .catch(function (error) {
        clearInterval(statusInterval);
        console.error('Plan generation error:', error);
        self.showError(Drupal.t('An error occurred while generating the plan. Please try again.'));
      });
    },

    /**
     * Renders the plan content.
     */
    renderPlan: function (plan, componentOptions, context) {
      var loadingEl = document.getElementById('plan-loading');
      var sectionsContainer = document.getElementById('plan-sections-container');
      var titleField = document.getElementById('edit-title-async');
      var asyncContent = document.getElementById('plan-async-content');

      // Hide loading spinner.
      if (loadingEl) {
        loadingEl.style.display = 'none';
      }

      // Show and populate title field.
      if (titleField) {
        titleField.value = plan.title;
        titleField.style.display = '';
        titleField.closest('.js-form-item')?.style && (titleField.closest('.js-form-item').style.display = '');

        // Add title label if missing.
        var titleWrapper = titleField.closest('.js-form-item');
        if (titleWrapper && !titleWrapper.querySelector('label')) {
          var label = document.createElement('label');
          label.setAttribute('for', 'edit-title-async');
          label.textContent = Drupal.t('Page Title');
          titleWrapper.insertBefore(label, titleField);
        }
      }

      // Add summary and metadata.
      if (asyncContent) {
        asyncContent.innerHTML = '';

        // Summary.
        var summaryDiv = document.createElement('div');
        summaryDiv.className = 'js-form-item form-item';
        summaryDiv.innerHTML = '<label>' + Drupal.t('Summary') + '</label><p>' + this.escapeHtml(plan.summary) + '</p>';
        asyncContent.appendChild(summaryDiv);

        // Metadata.
        var metadataDiv = document.createElement('div');
        metadataDiv.className = 'plan-metadata';
        metadataDiv.innerHTML =
          '<div><strong>' + Drupal.t('Target Audience:') + '</strong> ' + this.escapeHtml(plan.targetAudience) + '</div>' +
          '<div><strong>' + Drupal.t('Estimated Read Time:') + '</strong> ' + plan.estimatedReadTime + ' ' + Drupal.t('minutes') + '</div>';
        asyncContent.appendChild(metadataDiv);

        asyncContent.style.display = '';
      }

      // Render sections one by one with animation.
      if (sectionsContainer && plan.sections) {
        sectionsContainer.innerHTML = '';

        plan.sections.forEach(function (section, index) {
          setTimeout(function () {
            this.renderSection(sectionsContainer, section, componentOptions);
          }.bind(this), index * 150); // Stagger section appearance.
        }.bind(this));
      }

      // Enable refinement controls.
      var refinementSection = document.getElementById('refinement-section');
      if (refinementSection) {
        var textarea = refinementSection.querySelector('textarea');
        var button = refinementSection.querySelector('input[type="submit"]');
        if (textarea) textarea.disabled = false;
        if (button) button.disabled = false;
      }

      // Enable navigation buttons.
      var nextButton = document.getElementById('edit-next-step2');
      if (nextButton) {
        nextButton.disabled = false;
        nextButton.classList.remove('is-disabled');
      }
    },

    /**
     * Renders a single section with animation.
     */
    renderSection: function (container, section, componentOptions) {
      var sectionId = section.id;
      var content = section.content;
      var previewLength = 300;
      var needsReadMore = content.length > previewLength;
      var preview = needsReadMore ? content.substring(0, previewLength) + '...' : content;

      // Create details element.
      var details = document.createElement('details');
      details.className = 'plan-section-item section-animate-in';
      details.setAttribute('data-section-id', sectionId);

      // Create summary (title).
      var summary = document.createElement('summary');
      summary.setAttribute('role', 'button');
      summary.setAttribute('aria-expanded', 'false');
      summary.textContent = section.title;
      details.appendChild(summary);

      // Create inner content wrapper.
      var innerDiv = document.createElement('div');
      innerDiv.className = 'details-wrapper';

      // Component type dropdown.
      var selectWrapper = document.createElement('div');
      selectWrapper.className = 'js-form-item form-item js-form-type-select form-type--select';

      var selectLabel = document.createElement('label');
      selectLabel.textContent = Drupal.t('Component Type');
      selectWrapper.appendChild(selectLabel);

      var select = document.createElement('select');
      select.name = 'sections[' + sectionId + '][component_type]';
      select.className = 'section-component-select form-select';

      // Populate options.
      for (var optionKey in componentOptions) {
        if (componentOptions.hasOwnProperty(optionKey)) {
          var option = document.createElement('option');
          option.value = optionKey;
          option.textContent = componentOptions[optionKey];
          if (optionKey === section.componentType) {
            option.selected = true;
          }
          select.appendChild(option);
        }
      }
      selectWrapper.appendChild(select);
      innerDiv.appendChild(selectWrapper);

      // Content textarea (editable).
      var contentWrapper = document.createElement('div');
      contentWrapper.className = 'js-form-item form-item js-form-type-textarea form-type--textarea';

      var contentLabel = document.createElement('label');
      contentLabel.textContent = Drupal.t('Section Content');
      contentWrapper.appendChild(contentLabel);

      var textarea = document.createElement('textarea');
      textarea.name = 'sections[' + sectionId + '][content]';
      textarea.className = 'section-content-textarea form-textarea';
      textarea.rows = 6;
      textarea.value = content;
      contentWrapper.appendChild(textarea);

      innerDiv.appendChild(contentWrapper);

      // Hidden index field.
      var hiddenInput = document.createElement('input');
      hiddenInput.type = 'hidden';
      hiddenInput.name = 'sections[' + sectionId + '][original_index]';
      hiddenInput.value = section.index;
      innerDiv.appendChild(hiddenInput);

      details.appendChild(innerDiv);
      container.appendChild(details);

      // Trigger animation.
      requestAnimationFrame(function () {
        details.classList.add('section-visible');
      });
    },

    /**
     * Shows an error message.
     */
    showError: function (message) {
      var loadingEl = document.getElementById('plan-loading');
      if (loadingEl) {
        loadingEl.className = 'plan-error-container';
        loadingEl.innerHTML =
          '<div class="messages messages--error">' +
          '<h3>' + Drupal.t('Failed to generate content plan') + '</h3>' +
          '<p>' + this.escapeHtml(message) + '</p>' +
          '<p><button type="button" class="button" onclick="location.reload()">' + Drupal.t('Retry') + '</button></p>' +
          '</div>';
      }
    },

    /**
     * Escapes HTML entities.
     */
    escapeHtml: function (text) {
      var div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
  };

})(Drupal, drupalSettings);
