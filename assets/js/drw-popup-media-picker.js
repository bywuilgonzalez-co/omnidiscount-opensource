/**
 * DRW Popup Media Picker
 * ------------------------------------------------------------------
 * Single-purpose wp.element component wrapping wp.media() (the WP media
 * library modal) to pick ONE image URL — used by the Popup tab in
 * Configuración Global (assets/js/admin-app.js's GlobalSettings) to set
 * popup.image_url. Same "self-contained component exposed on window"
 * pattern as drw-product-category-picker.js's DrwProductCategoryPicker,
 * scaled down to this plugin's first (and, as of this phase, only) media
 * picker.
 *
 * wp.media() itself is only available once wp_enqueue_media() has run
 * (AdminController::enqueue_admin_assets()) — this component checks for it
 * defensively and renders a plain URL field as a graceful fallback instead
 * of throwing if that script somehow is not loaded on the current screen.
 */
(function () {
    'use strict';

    if (!window.wp || !wp.element) {
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useRef = wp.element.useRef;

    var Button = (window.wp.components && wp.components.Button) || null;
    var TextControl = (window.wp.components && wp.components.TextControl) || null;

    /**
     * PopupMediaPicker({ value, onChange, label, help })
     *
     * @param {string}   props.value    Current image URL (may be '').
     * @param {function} props.onChange Called with the new URL string
     *                                  ('' when removed).
     * @param {string}   [props.label]
     * @param {string}   [props.help]
     */
    function PopupMediaPicker(props) {
        var value = props.value || '';
        var onChange = typeof props.onChange === 'function' ? props.onChange : function () {};
        var frameRef = useRef(null);

        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];

        function openLibrary() {
            if (!window.wp || !wp.media) {
                setError('La biblioteca de medios de WordPress no está disponible en esta pantalla.');
                return;
            }
            setError('');

            // Reuse a single frame instance across opens (wp.media()'s own
            // documented pattern) rather than constructing a fresh one on
            // every click.
            if (!frameRef.current) {
                frameRef.current = wp.media({
                    title: 'Seleccionar imagen del popup',
                    button: { text: 'Usar esta imagen' },
                    library: { type: 'image' },
                    multiple: false
                });

                frameRef.current.on('select', function () {
                    var selection = frameRef.current.state().get('selection').first();
                    if (!selection) {
                        return;
                    }
                    var attachment = selection.toJSON();
                    var url = (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url)
                        || attachment.url;
                    onChange(url || '');
                });
            }

            frameRef.current.open();
        }

        function removeImage() {
            onChange('');
        }

        return el('div', { className: 'drw-popup-media-picker' },
            props.label && el('p', { className: 'drw-settings-label' }, props.label),
            props.help && el('p', { className: 'drw-help-text', style: { marginTop: 0 } }, props.help),

            value
                ? el('div', { className: 'drw-popup-media-preview' },
                    el('img', { src: value, alt: '', className: 'drw-popup-media-preview-img' }),
                    el('div', { className: 'drw-popup-media-preview-actions' },
                        Button
                            ? el(Button, { type: 'button', className: 'drw-secondary-btn', onClick: openLibrary }, 'Cambiar imagen')
                            : null,
                        Button
                            ? el(Button, { type: 'button', className: 'drw-danger-btn', onClick: removeImage }, 'Quitar imagen')
                            : null
                    )
                )
                : el('div', { className: 'drw-popup-media-empty' },
                    Button
                        ? el(Button, { type: 'button', className: 'drw-secondary-btn', onClick: openLibrary }, 'Seleccionar imagen')
                        : (TextControl && el(TextControl, {
                            label: 'URL de la imagen',
                            value: value,
                            onChange: onChange
                        }))
                ),

            error && el('p', { className: 'drw-help-text', style: { color: '#dc2626' } }, error)
        );
    }

    window.DrwPopupMediaPicker = PopupMediaPicker;
})();
