/**
 * OmniDiscount — Popup de captura de email (drw-popup.js)
 *
 * Vanilla ES5 IIFE, sin dependencias — mismo estilo que drw-shortcode.js /
 * drw-featured-promos.js / drw-minicart-blocks.js. TODO el DOM del popup se
 * construye aquí en JS; no hay ningún marcado renderizado por PHP (ver
 * ShortcodeController::enqueue_public_assets(), que solo localiza
 * `drwPopupData` con la configuración y el token de permanencia mínima).
 *
 * Disparadores — AMBOS con setTimeout/listener de evento, NUNCA
 * requestAnimationFrame: lección ya documentada en drw-minicart-blocks.js —
 * rAF no se ejecuta mientras la pestaña está en segundo plano
 * (document.hidden === true), y el popup debe poder dispararse por retraso
 * aunque la pestaña no tenga foco.
 *   - Retraso: window.setTimeout(showPopup, delaySeconds * 1000).
 *   - Intento de salida: 'mouseout' en document con
 *     e.clientY <= 0 && e.relatedTarget === null (el cursor salió por el
 *     borde superior de la ventana, no hacia otro elemento interno).
 *
 * Control de frecuencia (localStorage, envuelto en try/catch — un
 * localStorage bloqueado/lleno nunca debe romper el popup ni el resto de la
 * página):
 *   - 'drw_popup_submitted'        → una vez enviado el formulario (en
 *     cualquier modo), no se vuelve a mostrar en este navegador.
 *   - 'drw_popup_dismissed_until'  → al cerrar con la X/backdrop/Escape sin
 *     enviar, no se vuelve a mostrar hasta dentro de
 *     popup.frequency_cap_days días.
 *
 * Confirmación por correo: PopupController::resolve_confirm_redirect_url()
 * redirige a home_url('/') + '?drw_popup_confirmed=1&code=X' (o '=0' si
 * falló). Este script lee esos query params al cargar, se salta los
 * disparadores normales y muestra el panel de revelado directamente, y
 * limpia la URL con history.replaceState() para que un refresh no repita el
 * mismo estado ni vuelva a "consumir" nada.
 */
(function () {
    'use strict';

    if (typeof document.createElement !== 'function' || typeof window.fetch !== 'function') {
        // Sin fetch no hay forma de enviar el formulario — no tiene sentido
        // construir el DOM del popup solo para que el envío falle siempre.
        return;
    }

    var cfg = window.drwPopupData;
    if (!cfg || typeof cfg !== 'object') {
        return;
    }

    var settings = (cfg.settings && typeof cfg.settings === 'object') ? cfg.settings : {};

    // ------------------------------------------------------------------
    // localStorage — frecuencia (nunca debe lanzar)
    // ------------------------------------------------------------------

    var STORAGE_SUBMITTED       = 'drw_popup_submitted';
    var STORAGE_DISMISSED_UNTIL = 'drw_popup_dismissed_until';
    var DAY_MS                  = 24 * 60 * 60 * 1000;

    function storageGet(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function storageSet(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            // Bloqueado, lleno, o modo privado de Safari — se ignora: el
            // popup simplemente podría reaparecer más veces de lo ideal,
            // nunca debe romper la navegación.
        }
    }

    function hasSubmitted() {
        return storageGet(STORAGE_SUBMITTED) === '1';
    }

    function isDismissed() {
        var until = parseInt(storageGet(STORAGE_DISMISSED_UNTIL), 10);
        return !isNaN(until) && Date.now() < until;
    }

    function markSubmitted() {
        storageSet(STORAGE_SUBMITTED, '1');
    }

    function markDismissed() {
        var days = Math.max(0, parseInt(settings.frequencyCapDays, 10) || 0);
        storageSet(STORAGE_DISMISSED_UNTIL, String(Date.now() + (days * DAY_MS)));
    }

    // ------------------------------------------------------------------
    // Helpers de construcción de DOM
    // ------------------------------------------------------------------

    function el(tag, className, attrs) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (attrs) {
            for (var k in attrs) {
                if (Object.prototype.hasOwnProperty.call(attrs, k)) {
                    node.setAttribute(k, attrs[k]);
                }
            }
        }
        return node;
    }

    function text(tag, className, content) {
        var node = el(tag, className);
        node.textContent = content;
        return node;
    }

    // Trunca un número a lo sumo 2 decimales y sin ceros sobrantes (10.0 -> "10").
    function trimNumber(value) {
        value = parseFloat(value);
        if (isNaN(value)) {
            return '0';
        }
        var rounded = Math.round(value * 100) / 100;
        return (rounded % 1 === 0) ? String(rounded) : String(rounded);
    }

    // ------------------------------------------------------------------
    // Estado del popup activo (a lo sumo una instancia visible a la vez)
    // ------------------------------------------------------------------

    var overlayEl  = null;
    var shown      = false;
    var delayTimer = null;
    var exitBound  = false;
    var submitting = false;

    function clearTriggers() {
        if (delayTimer) {
            window.clearTimeout(delayTimer);
            delayTimer = null;
        }
        if (exitBound) {
            document.removeEventListener('mouseout', onMouseOut);
            exitBound = false;
        }
    }

    function onMouseOut(e) {
        if (e.clientY <= 0 && e.relatedTarget === null) {
            showPopup();
        }
    }

    function onKeydown(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            closePopup(true);
        }
    }

    function scheduleTriggers() {
        if (hasSubmitted() || isDismissed()) {
            return;
        }
        if (settings.delayEnabled) {
            var seconds = Math.max(0, parseInt(settings.delaySeconds, 10) || 0);
            delayTimer = window.setTimeout(showPopup, seconds * 1000);
        }
        if (settings.exitIntentEnabled) {
            document.addEventListener('mouseout', onMouseOut);
            exitBound = true;
        }
    }

    // ------------------------------------------------------------------
    // Construcción del modal
    // ------------------------------------------------------------------

    function buildBadge() {
        var type  = ('fixed' === settings.discountType) ? 'fixed' : 'percent';
        var value = parseFloat(settings.discountValue);
        if (!value || value <= 0) {
            return null;
        }

        var badge = text('div', 'drw-popup-badge', '');
        if ('percent' === type) {
            badge.textContent = '-' + trimNumber(value) + '%';
        } else {
            var symbol = cfg.currencySymbol || '$';
            badge.textContent = '-' + symbol + trimNumber(value);
        }
        return badge;
    }

    // Ilustración de respaldo (sin wp.media todavía configurado, o el
    // comerciante no subió imagen): degradado de marca + icono de regalo,
    // en vez de dejar la columna vacía o repetir la imagen de producto.
    function buildMediaFallbackArt() {
        var art = el('div', 'drw-popup-media-art');
        art.innerHTML =
            '<svg viewBox="0 0 120 120" width="96" height="96" aria-hidden="true" focusable="false">' +
            '<rect x="18" y="46" width="84" height="58" rx="6" fill="none" stroke="currentColor" stroke-width="4"/>' +
            '<rect x="10" y="30" width="100" height="20" rx="5" fill="none" stroke="currentColor" stroke-width="4"/>' +
            '<line x1="60" y1="30" x2="60" y2="104" stroke="currentColor" stroke-width="4"/>' +
            '<path d="M60 30c-10-24-42-18-34-2 4 8 20 8 34 2Z" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round"/>' +
            '<path d="M60 30c10-24 42-18 34-2-4 8-20 8-34 2Z" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round"/>' +
            '</svg>';
        return art;
    }

    function buildMediaColumn() {
        var media = el('div', 'drw-popup-media');

        if (settings.imageUrl) {
            var img = el('img', 'drw-popup-image', {
                src: settings.imageUrl,
                alt: ''
            });
            img.setAttribute('aria-hidden', 'true');
            media.appendChild(img);
        } else {
            media.classList.add('drw-popup-media--fallback');
            media.appendChild(buildMediaFallbackArt());
        }

        var badge = buildBadge();
        if (badge) {
            media.appendChild(badge);
        }

        return media;
    }

    function buildErrorSlot() {
        return el('p', 'drw-popup-error', { 'aria-live': 'polite' });
    }

    function setError(errorEl, message) {
        errorEl.textContent = message || '';
        if (message) {
            errorEl.classList.add('is-visible');
        } else {
            errorEl.classList.remove('is-visible');
        }
    }

    function buildFormPanel() {
        var inner = el('div', 'drw-popup-panel-inner');

        inner.appendChild(text('h2', 'drw-popup-headline', settings.headline || '¡No te pierdas nuestras ofertas!'));

        if (settings.bodyText) {
            inner.appendChild(text('p', 'drw-popup-body', settings.bodyText));
        }

        var form = el('form', 'drw-popup-form', { novalidate: 'novalidate' });

        var fieldWrap = el('div', 'drw-popup-field');
        var label = el('label', 'drw-popup-visually-hidden', { for: 'drw-popup-email' });
        label.textContent = 'Correo electrónico';
        var input = el('input', 'drw-popup-input', {
            type: 'email',
            id: 'drw-popup-email',
            name: 'email',
            autocomplete: 'email',
            placeholder: 'tu@correo.com',
            required: 'required'
        });
        fieldWrap.appendChild(label);
        fieldWrap.appendChild(input);
        form.appendChild(fieldWrap);

        // Honeypot: invisible para una persona real, un bot que rellena todo
        // ciegamente lo completa. Nombre de campo dictado por el servidor
        // (PopupController::HONEYPOT_FIELD) para no duplicar la constante.
        var honeypotName = cfg.honeypotField || 'drw_popup_hp';
        var honeypot = el('input', 'drw-popup-honeypot', {
            type: 'text',
            name: honeypotName,
            tabindex: '-1',
            autocomplete: 'off',
            'aria-hidden': 'true'
        });
        form.appendChild(honeypot);

        // Token de permanencia mínima firmado: emitido en el RENDER de la
        // página (PopupController::issue_render_token(), localizado en
        // drwPopupData) — no se pide bajo demanda, o un bot podría obtenerlo
        // al instante y anular el propósito del chequeo.
        var renderedAtInput = el('input', null, {
            type: 'hidden',
            name: 'rendered_at',
            value: cfg.renderedAt || ''
        });
        var signatureInput = el('input', null, {
            type: 'hidden',
            name: 'render_signature',
            value: cfg.renderSignature || ''
        });
        form.appendChild(renderedAtInput);
        form.appendChild(signatureInput);

        var errorEl = buildErrorSlot();
        form.appendChild(errorEl);

        var submitBtn = el('button', 'drw-popup-submit', { type: 'submit' });
        submitBtn.textContent = settings.buttonLabel || 'Obtener mi descuento';
        form.appendChild(submitBtn);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (submitting) {
                return;
            }
            var email = input.value.trim();
            if (!email) {
                setError(errorEl, 'Ingresa un correo electrónico válido.');
                input.focus();
                return;
            }
            submitEmail(email, honeypotName, submitBtn, errorEl);
        });

        inner.appendChild(form);

        inner.appendChild(text(
            'p',
            'drw-popup-disclaimer',
            settings.disclaimerText || 'Al registrarte aceptas recibir correos promocionales. Puedes darte de baja cuando quieras.'
        ));

        return inner;
    }

    function buildRevealMarkup(inner, mode, code, message) {
        inner.innerHTML = '';

        var check = el('div', 'drw-popup-reveal-icon', { 'aria-hidden': 'true' });
        check.innerHTML =
            '<svg viewBox="0 0 24 24" width="26" height="26"><path d="M4 12.5l5 5L20 6.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        inner.appendChild(check);

        // Headline must key off whether a real CODE came back, not just the
        // mode -- the server can now return code=null in INSTANT mode too
        // (an anonymous re-submission of an already-registered email; see
        // PopupController::uniform_response_for_existing()), and claiming
        // "your discount is ready" with no code anywhere on screen would be
        // a straight text/state mismatch. 'confirmed' mode keeps its own
        // "check your email" wording since that email genuinely exists;
        // instant mode with no code gets a neutral headline instead, since
        // no confirmation email was ever sent on that path.
        var headline;
        if (code) {
            headline = '¡Tu descuento está listo!';
        } else if ('confirmed' === mode) {
            headline = 'Revisa tu correo';
        } else {
            headline = 'Ya tenemos tu registro';
        }
        inner.appendChild(text('h2', 'drw-popup-headline', headline));

        inner.appendChild(text('p', 'drw-popup-body', message || ''));

        if (code) {
            var block = el('div', 'drw-popup-code-block');
            var codeText = text('span', 'drw-popup-code-text', code);
            var copyBtn = el('button', 'drw-popup-copy-btn', { type: 'button', 'aria-label': 'Copiar código' });
            copyBtn.textContent = 'Copiar';
            block.appendChild(codeText);
            block.appendChild(copyBtn);
            inner.appendChild(block);

            copyBtn.addEventListener('click', function () {
                copyToClipboard(code, function () {
                    var original = copyBtn.textContent;
                    copyBtn.textContent = '¡Copiado!';
                    copyBtn.classList.add('is-copied');
                    window.setTimeout(function () {
                        copyBtn.textContent = original;
                        copyBtn.classList.remove('is-copied');
                    }, 1600);
                });
            });
        }

        var doneBtn = el('button', 'drw-popup-submit drw-popup-done', { type: 'button' });
        doneBtn.textContent = 'Cerrar';
        doneBtn.addEventListener('click', function () {
            closePopup(false);
        });
        inner.appendChild(doneBtn);
    }

    // Mismo patrón que drw-featured-promos.js: Clipboard API async con
    // fallback a un <textarea> temporal + execCommand('copy') para
    // navegadores viejos.
    function copyToClipboard(value, onDone) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(onDone, function () {
                fallbackCopy(value);
                onDone();
            });
            return;
        }
        fallbackCopy(value);
        onDone();
    }

    function fallbackCopy(value) {
        var ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
        } catch (e) {
            // Nada más que hacer — el código sigue visible en pantalla para copiar a mano.
        }
        document.body.removeChild(ta);
    }

    function buildOverlay() {
        var overlay = el('div', 'drw-popup-overlay');
        var modal   = el('div', 'drw-popup-modal', { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Descuento de bienvenida' });

        var closeBtn = el('button', 'drw-popup-close', { type: 'button', 'aria-label': 'Cerrar' });
        closeBtn.innerHTML = '<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="M4 4l12 12M16 4L4 16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
        closeBtn.addEventListener('click', function () {
            closePopup(true);
        });

        var panel = el('div', 'drw-popup-panel');
        panel.appendChild(buildFormPanel());

        modal.appendChild(closeBtn);
        modal.appendChild(buildMediaColumn());
        modal.appendChild(panel);
        overlay.appendChild(modal);

        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) {
                closePopup(true);
            }
        });

        return { overlay: overlay, panelInner: panel.firstChild, modal: modal };
    }

    function showPopup() {
        if (shown || hasSubmitted()) {
            return;
        }
        clearTriggers();
        shown = true;

        var built = buildOverlay();
        overlayEl = built.overlay;
        document.body.appendChild(overlayEl);
        document.addEventListener('keydown', onKeydown);

        var emailInput = overlayEl.querySelector('#drw-popup-email');
        if (emailInput) {
            emailInput.focus();
        }
    }

    function closePopup(dismissedByUser) {
        if (!overlayEl) {
            return;
        }
        if (dismissedByUser && !hasSubmitted()) {
            markDismissed();
        }
        var node = overlayEl;
        overlayEl = null;
        shown = false;
        document.removeEventListener('keydown', onKeydown);
        if (node.parentNode) {
            node.parentNode.removeChild(node);
        }
    }

    // ------------------------------------------------------------------
    // Envío
    // ------------------------------------------------------------------

    function submitEmail(email, honeypotName, submitBtn, errorEl) {
        submitting = true;
        setError(errorEl, '');
        var originalLabel = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-busy', 'true');
        submitBtn.textContent = 'Enviando…';

        var fd = new FormData();
        fd.append('action', 'drw_popup_submit');
        fd.append('nonce', cfg.nonce || '');
        fd.append('email', email);
        fd.append(honeypotName, '');
        fd.append('rendered_at', cfg.renderedAt || '');
        fd.append('render_signature', cfg.renderSignature || '');
        fd.append('source_url', window.location.href);

        window.fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                submitting = false;
                if (resp && resp.success) {
                    markSubmitted();
                    var data = resp.data || {};
                    if (overlayEl) {
                        var inner = overlayEl.querySelector('.drw-popup-panel-inner');
                        if (inner) {
                            buildRevealMarkup(inner, data.mode, data.code, data.message);
                        }
                    }
                    return;
                }

                var errData = (resp && resp.data) ? resp.data : {};
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-busy');
                submitBtn.textContent = originalLabel;
                setError(errorEl, errData.message || 'Ocurrió un error. Inténtalo de nuevo.');
            })
            ['catch'](function () {
                submitting = false;
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-busy');
                submitBtn.textContent = originalLabel;
                setError(errorEl, 'No pudimos conectar. Verifica tu conexión e inténtalo de nuevo.');
            });
    }

    // ------------------------------------------------------------------
    // Revelado por query param (retorno del link de confirmación por correo)
    // ------------------------------------------------------------------

    function handleConfirmRedirect() {
        if (window.location.search.indexOf('drw_popup_confirmed') === -1) {
            return false;
        }

        var params;
        try {
            params = new URLSearchParams(window.location.search);
        } catch (e) {
            return false;
        }
        if (!params.has('drw_popup_confirmed')) {
            return false;
        }

        var confirmed = params.get('drw_popup_confirmed') === '1';
        var code      = params.get('code');

        // Limpiar la URL SIEMPRE, incluso si confirmed=0 — un refresh no debe
        // repetir este estado ni dejar el query param pegado en la barra de
        // direcciones.
        params.delete('drw_popup_confirmed');
        params.delete('code');
        var qs     = params.toString();
        var newUrl = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', newUrl);
        }

        if (!confirmed || !code) {
            return false;
        }

        markSubmitted();
        shown = true;
        var built = buildOverlay();
        overlayEl = built.overlay;
        document.body.appendChild(overlayEl);
        document.addEventListener('keydown', onKeydown);
        buildRevealMarkup(
            built.panelInner,
            'instant',
            code,
            '¡Gracias por confirmar tu correo! Aquí está tu código de descuento de bienvenida.'
        );

        return true;
    }

    // ------------------------------------------------------------------
    // Init
    // ------------------------------------------------------------------

    function init() {
        if (handleConfirmRedirect()) {
            return;
        }
        if (!settings.enabled) {
            return;
        }
        scheduleTriggers();
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
