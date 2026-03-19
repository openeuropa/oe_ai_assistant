/**
 * @file
 * Drupal behavior that initialises the AI Editorial Assistant React app.
 *
 * Reads configuration from drupalSettings and calls the app's init()
 * function on the mount point element.
 */
(function (Drupal, drupalSettings) {
  Drupal.behaviors.oeAiAssistant = {
    attach: function (context) {
      var container = context.querySelector('#oe-ai-assistant');
      if (!container || container.dataset.initialized) return;
      container.dataset.initialized = 'true';

      var config = drupalSettings.oeAiAssistant;
      AiEditorialAssistant.init('#oe-ai-assistant', config);
    }
  };
})(Drupal, drupalSettings);
