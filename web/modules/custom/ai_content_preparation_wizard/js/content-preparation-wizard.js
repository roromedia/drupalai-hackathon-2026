/**
 * @file
 * JavaScript behaviors for the Content Preparation Wizard.
 */

(function (Drupal, drupalSettings, once) {

  'use strict';

  /**
   * Content Preparation Wizard behaviors.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the content preparation wizard behaviors.
   */
  Drupal.behaviors.contentPreparationWizard = {
    attach: function (context, settings) {
      // Add loading spinner to submit buttons when clicked.
      // Note: ::after pseudo-elements don't work on input elements,
      // so we create a wrapper with a spinner element.
      once('wizard-submit-spinner', '.content-preparation-wizard input[type="submit"]', context).forEach(function (button) {
        // Wrap button in a container for spinner positioning.
        var wrapper = document.createElement('span');
        wrapper.className = 'wizard-button-wrapper';
        button.parentNode.insertBefore(wrapper, button);
        wrapper.appendChild(button);

        // Create spinner element.
        var spinner = document.createElement('span');
        spinner.className = 'wizard-button-spinner';
        wrapper.appendChild(spinner);

        button.addEventListener('click', function () {
          // Add loading state after a tiny delay to let AJAX initialize.
          setTimeout(function () {
            wrapper.classList.add('is-loading');
          }, 10);
        });
      });

      // Remove loading state when AJAX completes.
      if (typeof jQuery !== 'undefined') {
        jQuery(once('wizard-ajax-reset', 'body', context)).on('ajaxComplete', function () {
          var wrappers = document.querySelectorAll('.content-preparation-wizard .wizard-button-wrapper.is-loading');
          wrappers.forEach(function (wrapper) {
            wrapper.classList.remove('is-loading');
          });
        });
      }

      // Handle "Read more..." toggle for section previews.
      once('section-read-more', '.section-read-more', context).forEach(function (link) {
        link.addEventListener('click', function (e) {
          e.preventDefault();
          var wrapper = link.closest('.section-content-wrapper');
          var preview = wrapper.querySelector('.section-preview');
          var full = wrapper.querySelector('.section-full');

          if (full.style.display === 'none') {
            preview.style.display = 'none';
            full.style.display = 'block';
            link.textContent = Drupal.t('Show less');
          } else {
            preview.style.display = 'block';
            full.style.display = 'none';
            link.textContent = Drupal.t('Read more...');
          }
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
