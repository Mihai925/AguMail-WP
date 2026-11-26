/*
 * AguMail: the field map of the AguMail action in Elementor Pro's form editor.
 *
 * Elementor Pro's Fields_Map control starts empty and hidden; each of its own
 * integrations fills it with the fields its service takes when the merchant
 * opens the integration's section (see modules/forms/assets/js/editor in
 * Elementor Pro, the ConvertKit and MailerLite handlers). AguMail's fields
 * never change, so they come from the plugin with this script
 * (window.agumailElementor.fields) and are set as soon as the AguMail section
 * of a form widget opens. updateMap() keeps the choices already saved.
 *
 * Plain ES5, no build step. Runs in the editor only.
 */
(function (window, $) {
  'use strict';

  var agumailSection = 'section_agumail';
  var agumailControl = 'agumail_fields_map';

  function remoteFields() {
    var data = window.agumailElementor;
    return data && data.fields ? data.fields : [];
  }

  // The control's view in the panel page that is open now.
  function mapView() {
    var page = window.elementor.getPanelView().getCurrentPageView();
    if (!page || !page.collection || !page.children) {
      return null;
    }
    var model = page.collection.findWhere({ name: agumailControl });
    return model ? page.children.findByModelCid(model.cid) : null;
  }

  function fillMap() {
    var view = mapView();
    if (view && typeof view.updateMap === 'function') {
      view.updateMap(remoteFields());
    }
  }

  function isFormWidget(editor) {
    var view = editor && editor.getOption ? editor.getOption('editedElementView') : null;
    var model = view && view.getEditModel ? view.getEditModel() : null;
    return !!model && model.get('widgetType') === 'form';
  }

  function listen() {
    window.elementor.channels.editor.on('section:activated', function (sectionName, editor) {
      if (sectionName !== agumailSection || !isFormWidget(editor)) {
        return;
      }
      // After Elementor has drawn the section's controls, as its own integrations wait.
      window.setTimeout(fillMap, 10);
    });
  }

  $(window).on('elementor:init', listen);
})(window, jQuery);
