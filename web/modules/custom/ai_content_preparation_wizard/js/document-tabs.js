/**
 * @file
 * JavaScript for document tabs in the Content Preparation Wizard.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Behavior for document tab switching.
   */
  Drupal.behaviors.documentTabs = {
    attach: function (context) {
      const tabContainers = once('document-tabs', '.document-tabs-nav', context);

      tabContainers.forEach(function (tabNav) {
        const tabs = tabNav.querySelectorAll('.document-tab');
        const panelsContainer = tabNav.nextElementSibling;

        if (!panelsContainer || !panelsContainer.classList.contains('document-tabs-content')) {
          return;
        }

        tabs.forEach(function (tab) {
          tab.addEventListener('click', function (e) {
            e.preventDefault();

            const targetId = this.getAttribute('data-target');
            if (!targetId) {
              return;
            }

            // Remove active class from all tabs.
            tabs.forEach(function (t) {
              t.classList.remove('active');
            });

            // Add active class to clicked tab.
            this.classList.add('active');

            // Hide all panels.
            const panels = panelsContainer.querySelectorAll('.document-tab-panel');
            panels.forEach(function (panel) {
              panel.style.display = 'none';
              panel.classList.remove('active');
            });

            // Show target panel.
            const targetPanel = document.getElementById(targetId);
            if (targetPanel) {
              targetPanel.style.display = 'block';
              targetPanel.classList.add('active');
            }
          });
        });
      });
    }
  };

})(Drupal, once);
