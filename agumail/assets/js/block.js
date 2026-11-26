/**
 * The agumail/form block, for the block editor. Plain JavaScript on the wp.*
 * globals WordPress already loads in the editor, so there is no build step.
 *
 * The block only chooses a form and how it appears; the page itself is built
 * on the server (agumail_form_block_render in includes/forms.php), exactly as
 * the [agumail_form] shortcode is. The form picker reads the store's own list
 * of forms from /agumail/v1/forms, which AguMail refreshes daily.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element || !wp.components) {
		return;
	}

	var element = wp.element;
	var components = wp.components;
	var el = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (text) { return text; };
	var sprintf = wp.i18n && wp.i18n.sprintf ? wp.i18n.sprintf : function (text, value) { return text.replace('%s', value); };
	var editor = wp.blockEditor || wp.editor;
	var useBlockProps = editor.useBlockProps;

	// One request per editor session, shared by every AguMail block on the page.
	var formsRequest = null;
	function loadForms() {
		if (!formsRequest) {
			formsRequest = wp.apiFetch({ path: '/agumail/v1/forms' }).catch(function () {
				formsRequest = null;
				return { forms: [], ready: false, failed: true };
			});
		}
		return formsRequest;
	}

	/** A whole number within [min, max], or undefined for an empty field. */
	function toNumber(value, min, max) {
		if (value === '' || value === null || value === undefined) {
			return undefined;
		}
		var number = parseInt(value, 10);
		if (isNaN(number)) {
			return undefined;
		}
		return Math.min(max, Math.max(min, number));
	}

	function fieldValue(value) {
		return value === undefined || value === null ? '' : String(value);
	}

	function formOptions(forms) {
		return [{ value: '', label: __('Choose a form', 'agumail') }].concat(forms.map(function (form) {
			return { value: form.slug, label: form.name };
		}));
	}

	function editForm(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var state = useState(null);
		var data = state[0];
		var setData = state[1];

		useEffect(function () {
			var live = true;
			loadForms().then(function (result) {
				if (live) {
					setData(result && result.forms ? result : { forms: [], ready: false });
				}
			});
			return function () { live = false; };
		}, []);

		var blockProps = useBlockProps ? useBlockProps() : {};
		var forms = data ? data.forms : [];
		var chosen = forms.filter(function (form) { return form.slug === attributes.slug; })[0];
		var popup = attributes.mode === 'popup';

		var picker = el(components.SelectControl, {
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			label: __('Form', 'agumail'),
			value: attributes.slug,
			options: formOptions(forms),
			onChange: function (slug) { setAttributes({ slug: slug }); }
		});

		var settings = el(editor.InspectorControls, null,
			el(components.PanelBody, { title: __('Form settings', 'agumail'), initialOpen: true },
				picker,
				el(components.SelectControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __('Show it', 'agumail'),
					value: popup ? 'popup' : 'inline',
					options: [
						{ value: 'inline', label: __('In the page', 'agumail') },
						{ value: 'popup', label: __('As a popup', 'agumail') }
					],
					onChange: function (mode) { setAttributes({ mode: mode }); }
				}),
				popup ? el(element.Fragment, null,
					el(components.TextControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __('Delay in seconds', 'agumail'),
						type: 'number',
						min: 0,
						value: fieldValue(attributes.delay),
						help: __('Leave empty to show it after a few seconds.', 'agumail'),
						onChange: function (value) { setAttributes({ delay: toNumber(value, 0, 3600) }); }
					}),
					el(components.TextControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __('Scroll depth in %', 'agumail'),
						type: 'number',
						min: 0,
						max: 100,
						value: fieldValue(attributes.scroll),
						onChange: function (value) { setAttributes({ scroll: toNumber(value, 0, 100) }); }
					}),
					el(components.ToggleControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __('Show when the visitor moves to leave', 'agumail'),
						help: __('Desktop only.', 'agumail'),
						checked: !!attributes.exitIntent,
						onChange: function (checked) { setAttributes({ exitIntent: !!checked }); }
					}),
					el(components.TextControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __('Show again after (days)', 'agumail'),
						type: 'number',
						min: 1,
						max: 365,
						value: fieldValue(attributes.frequencyDays),
						help: __('Leave empty for 7 days.', 'agumail'),
						onChange: function (value) { setAttributes({ frequencyDays: toNumber(value, 1, 365) }); }
					})
				) : null
			)
		);

		var body;
		if (!data) {
			body = el(components.Placeholder, { icon: 'email-alt', label: __('AguMail form', 'agumail') }, el(components.Spinner, null));
		} else if (!forms.length) {
			body = el(components.Placeholder, {
				icon: 'email-alt',
				label: __('AguMail form', 'agumail'),
				instructions: data.failed
					? __('Your forms could not be loaded. Reload the editor to try again.', 'agumail')
					: __('No published forms yet. Publish a form in AguMail, then press Refresh under WooCommerce > AguMail.', 'agumail')
			});
		} else {
			body = el(components.Placeholder, {
				icon: 'email-alt',
				label: __('AguMail form', 'agumail'),
				instructions: chosen
					? sprintf(popup ? __('Opens "%s" as a popup on this page.', 'agumail') : __('Shows "%s" here on the published page.', 'agumail'), chosen.name)
					: __('Choose one of your published forms.', 'agumail')
			}, picker);
		}

		return el('div', blockProps, settings, body);
	}

	wp.blocks.registerBlockType('agumail/form', {
		apiVersion: 3,
		title: __('AguMail form', 'agumail'),
		description: __('A signup form from your AguMail account, in the page or as a popup.', 'agumail'),
		category: 'widgets',
		icon: 'email-alt',
		keywords: ['signup', 'newsletter', 'subscribe', 'email'],
		supports: { html: false },
		attributes: {
			slug: { type: 'string', default: '' },
			mode: { type: 'string', default: 'inline' },
			delay: { type: 'number' },
			scroll: { type: 'number' },
			exitIntent: { type: 'boolean', default: false },
			frequencyDays: { type: 'number' }
		},
		edit: editForm,
		save: function () { return null; }
	});
})(window.wp);
