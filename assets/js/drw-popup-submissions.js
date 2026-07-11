/* global drwPopupSubmissionsData, wp */
/**
 * DRW Popup Submissions
 * ------------------------------------------------------------------
 * Renders the "Registros del popup" admin table (PopupController's
 * admin_menu submenu). Deliberately vanilla DOM/innerHTML, NOT wp.element —
 * this mirrors what drw-analytics.js actually does (the closest existing
 * precedent for a standalone OmniDiscount submenu page backed by its own
 * REST GET route), which itself does not load wp-element as a script
 * dependency. Reusing that real, already-audited pattern here avoids
 * pulling in a dependency this screen does not otherwise need.
 */
(function () {
    'use strict';

    var apiRoot  = (drwPopupSubmissionsData && drwPopupSubmissionsData.apiRoot)  ? drwPopupSubmissionsData.apiRoot  : '';
    var nonce    = (drwPopupSubmissionsData && drwPopupSubmissionsData.nonce)    ? drwPopupSubmissionsData.nonce    : '';
    var perPage  = (drwPopupSubmissionsData && drwPopupSubmissionsData.perPage)  ? parseInt(drwPopupSubmissionsData.perPage, 10) : 20;
    var exportUrl = (drwPopupSubmissionsData && drwPopupSubmissionsData.exportUrl) ? drwPopupSubmissionsData.exportUrl : '';
    var couponEditUrlBase = (drwPopupSubmissionsData && drwPopupSubmissionsData.couponEditUrlBase) ? drwPopupSubmissionsData.couponEditUrlBase : '';
    var appEl    = document.getElementById('drw-popup-submissions-app');
    var currentPage = 1;

    var STATUS_LABELS = {
        issued:  'Emitido',
        pending: 'Pendiente de confirmación',
        claimed: 'Reservado'
    };

    var MODE_LABELS = {
        instant:   'Instantáneo',
        confirmed: 'Confirmación por correo'
    };

    function buildUrl(page) {
        var sep = apiRoot.indexOf('?') === -1 ? '?' : '&';
        return apiRoot + sep + 'page=' + encodeURIComponent(page) + '&per_page=' + encodeURIComponent(perPage);
    }

    function fetchSubmissions(page) {
        if (!appEl) { return; }
        renderLoading();

        var url = buildUrl(page);

        if (typeof wp !== 'undefined' && wp.apiFetch) {
            wp.apiFetch({ url: url })
                .then(function (data) { renderTable(data); })
                .catch(function (err) { renderError(err); });
            return;
        }

        fetch(url, {
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json'
            }
        })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (data) { renderTable(data); })
            .catch(function (err) { renderError(err); });
    }

    /* ------------------------------------------------------------ */
    /* States                                                        */
    /* ------------------------------------------------------------ */

    function renderLoading() {
        appEl.innerHTML =
            toolbarHtml() +
            '<div class="drw-pop-table-wrap"><div class="drw-pop-empty">Cargando registros...</div></div>';
        appEl.setAttribute('aria-busy', 'true');
    }

    function renderError(err) {
        var msg = (err && err.message) ? err.message : String(err);
        appEl.innerHTML =
            toolbarHtml() +
            '<div class="drw-an-error" role="alert">No se pudieron cargar los registros. ' + escHtml(msg) + '</div>';
        appEl.removeAttribute('aria-busy');
    }

    /* ------------------------------------------------------------ */
    /* Toolbar                                                        */
    /* ------------------------------------------------------------ */

    function toolbarHtml() {
        var exportHtml = exportUrl
            ? '<a class="drw-pop-export-link" href="' + escAttr(exportUrl) + '">Exportar CSV</a>'
            : '';
        return (
            '<div class="drw-pop-toolbar">' +
                '<span class="drw-pop-meta" id="drw-pop-live" aria-live="polite"></span>' +
                exportHtml +
            '</div>'
        );
    }

    /* ------------------------------------------------------------ */
    /* Table                                                          */
    /* ------------------------------------------------------------ */

    function renderTable(data) {
        currentPage = data.page || 1;
        var items = Array.isArray(data.items) ? data.items : [];
        var totalPages = data.total_pages || 1;

        var body;
        if (items.length === 0) {
            body = '<div class="drw-pop-empty">Todavía no hay registros del popup.</div>';
        } else {
            body =
                '<div class="drw-pop-table-wrap"><table class="drw-pop-table">' +
                '<thead><tr>' +
                    '<th>Correo</th><th>Estado</th><th>Modo</th><th>Código</th>' +
                    '<th>IP</th><th>Registrado</th><th>Confirmado</th><th>Revelado</th>' +
                '</tr></thead>' +
                '<tbody>' + items.map(renderRow).join('') + '</tbody>' +
                '</table></div>' +
                paginationHtml(currentPage, totalPages);
        }

        appEl.innerHTML = toolbarHtml() + body;
        wirePagination();
        appEl.removeAttribute('aria-busy');

        var live = document.getElementById('drw-pop-live');
        if (live) {
            live.textContent = intFmt(data.total) + ' registro(s) en total.';
        }
    }

    function renderRow(row) {
        var statusLabel = STATUS_LABELS[row.status] || row.status;
        var modeLabel = MODE_LABELS[row.reveal_mode] || row.reveal_mode;
        var badgeClass = 'drw-pop-badge-' + (row.status === 'issued' ? 'issued' : (row.status === 'pending' ? 'pending' : 'claimed'));

        var codeCell = '&#8212;';
        if (row.coupon_code) {
            codeCell = row.coupon_id && couponEditUrlBase
                ? '<a class="drw-pop-code" href="' + escAttr(couponEditUrlBase + encodeURIComponent(row.coupon_id)) + '">' + escHtml(row.coupon_code) + '</a>'
                : '<span class="drw-pop-code">' + escHtml(row.coupon_code) + '</span>';
        }

        // ip_hash is already a salted sha256 (never the raw IP — see
        // PopupModel/PopupController); truncated further here purely for
        // display compactness, matching the CSV export's own truncation.
        var ipCell = row.ip_hash ? escHtml(String(row.ip_hash).slice(0, 12)) + '…' : '&#8212;';

        return (
            '<tr>' +
                '<td class="drw-pop-email">' + escHtml(row.email) + '</td>' +
                '<td><span class="drw-pop-badge ' + badgeClass + '">' + escHtml(statusLabel) + '</span></td>' +
                '<td>' + escHtml(modeLabel) + '</td>' +
                '<td>' + codeCell + '</td>' +
                '<td class="drw-pop-ip">' + ipCell + '</td>' +
                '<td>' + escHtml(row.created_at || '') + '</td>' +
                '<td>' + (row.confirmed_at ? escHtml(row.confirmed_at) : '&#8212;') + '</td>' +
                '<td>' + (row.revealed_at ? escHtml(row.revealed_at) : '&#8212;') + '</td>' +
            '</tr>'
        );
    }

    function paginationHtml(page, totalPages) {
        if (totalPages <= 1) { return ''; }
        return (
            '<div class="drw-pop-pagination">' +
                '<button type="button" id="drw-pop-prev" ' + (page <= 1 ? 'disabled' : '') + '>&larr; Anterior</button>' +
                '<span class="drw-pop-meta">Página ' + intFmt(page) + ' de ' + intFmt(totalPages) + '</span>' +
                '<button type="button" id="drw-pop-next" ' + (page >= totalPages ? 'disabled' : '') + '>Siguiente &rarr;</button>' +
            '</div>'
        );
    }

    function wirePagination() {
        var prev = document.getElementById('drw-pop-prev');
        var next = document.getElementById('drw-pop-next');
        if (prev) {
            prev.addEventListener('click', function () { fetchSubmissions(currentPage - 1); });
        }
        if (next) {
            next.addEventListener('click', function () { fetchSubmissions(currentPage + 1); });
        }
    }

    /* ------------------------------------------------------------ */
    /* Formatting helpers                                             */
    /* ------------------------------------------------------------ */

    function intFmt(n) {
        return String(parseInt(n, 10) || 0);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escAttr(str) {
        return escHtml(str);
    }

    // Boot on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { fetchSubmissions(currentPage); });
    } else {
        fetchSubmissions(currentPage);
    }
}());
