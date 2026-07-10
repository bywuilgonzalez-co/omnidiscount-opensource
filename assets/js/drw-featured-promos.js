/* DRW Featured Promos — copy-code button (no dependencies) */
(function () {
    'use strict';

    var COPIED_MS = 1600;

    // Legacy fallback for browsers without the async Clipboard API.
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    // Swap the code label to "¡Copiado!" briefly; guarded so rapid clicks
    // don't stack timers or lose the original code.
    function flash(btn) {
        var codeEl = btn.querySelector('.drw-featured-promo-code');
        if (!codeEl || btn.classList.contains('is-copied')) return;
        var copied = btn.getAttribute('data-copied-label') || '¡Copiado!';
        var original = codeEl.textContent;
        codeEl.textContent = copied;
        btn.classList.add('is-copied');
        setTimeout(function () {
            codeEl.textContent = original;
            btn.classList.remove('is-copied');
        }, COPIED_MS);
    }

    function onClick(e) {
        var btn = e.currentTarget;
        var code = btn.getAttribute('data-code') || '';
        if (!code) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(
                function () { flash(btn); },
                function () { fallbackCopy(code); flash(btn); }
            );
        } else {
            fallbackCopy(code);
            flash(btn);
        }
    }

    var buttons = document.querySelectorAll('.drw-featured-promo-copy');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', onClick);
    }
})();
