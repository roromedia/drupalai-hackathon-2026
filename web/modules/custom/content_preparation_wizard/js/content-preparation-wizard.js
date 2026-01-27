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
      once('content-preparation-wizard', '.content-preparation-wizard', context).forEach(function (element) {
        // Initialize wizard functionality.
      });
    }
  };

})(Drupal, drupalSettings, once);
