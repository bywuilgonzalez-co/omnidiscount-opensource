/* global drwAnalyticsData, wp */
(function () {
    'use strict';

    var apiRoot  = (drwAnalyticsData && drwAnalyticsData.apiRoot) ? drwAnalyticsData.apiRoot : '';
    var nonce    = (drwAnalyticsData && drwAnalyticsData.nonce)   ? drwAnalyticsData.nonce   : '';
    var appEl    = document.getElementById('drw-analytics-app');
    var currentDays = 30;

    function buildUrl(days) {
        var sep = apiRoot.indexOf('?') === -1 ? '?' : '&';
        return apiRoot + sep + 'days=' + encodeURIComponent(days);
    }

    function fetchAnalytics(days) {
        if (!appEl) { return; }
        renderLoading();

        var url = buildUrl(days);

        // Prefer wp.apiFetch when available (already sets nonce via middleware)
        if (typeof wp !== 'undefined' && wp.apiFetch) {
            wp.apiFetch({ url: url })
                .then(function (data) { renderTable(data); })
                .catch(function (err) { renderError(err); });
            return;
        }

        // Plain fetch fallback
        fetch(url, {
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json',
            },
        })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (data) { renderTable(data); })
            .catch(function (err) { renderError(err); });
    }

    function renderLoading() {
        appEl.innerHTML = '<p>Cargando analíticas…</p>';
    }

    function renderError(err) {
        var msg = (err && err.message) ? err.message : String(err);
        appEl.innerHTML = '<p style="color:#c00;">Error al cargar las analíticas: ' + escHtml(msg) + '</p>';
    }

    function renderTable(data) {
        var symbol = escHtml(data.currency_symbol || '');

        var html =
            '<div id="drw-analytics-controls" style="margin-bottom:16px;">' +
                '<label for="drw-days-select" style="font-weight:600;margin-right:8px;">Rango de tiempo:</label>' +
                '<select id="drw-days-select">' +
                    '<option value="7"'   + sel(7)   + '>Últimos 7 días</option>'  +
                    '<option value="30"'  + sel(30)  + '>Últimos 30 días</option>' +
                    '<option value="90"'  + sel(90)  + '>Últimos 90 días</option>' +
                    '<option value="365"' + sel(365) + '>Últimos 365 días</option>' +
                '</select>' +
            '</div>' +
            '<table class="widefat striped" style="max-width:540px;">' +
                '<thead>' +
                    '<tr>' +
                        '<th>Métrica</th>' +
                        '<th>Valor</th>' +
                    '</tr>' +
                '</thead>' +
                '<tbody>' +
                    row('Periodo',                     escHtml(String(data.days)) + ' días') +
                    row('Pedidos con descuentos',      escHtml(String(data.orders_with_discounts))) +
                    row('Descuento total',             symbol + escHtml(toFixed2(data.total_discount))) +
                    row('Descuento promedio',          symbol + escHtml(toFixed2(data.average_discount))) +
                    row('Pedidos con envío gratis',    escHtml(String(data.free_shipping_orders))) +
                '</tbody>' +
            '</table>';

        appEl.innerHTML = html;

        var select = document.getElementById('drw-days-select');
        if (select) {
            select.addEventListener('change', function () {
                currentDays = parseInt(this.value, 10);
                fetchAnalytics(currentDays);
            });
        }
    }

    function row(label, value) {
        return '<tr><td><strong>' + label + '</strong></td><td>' + value + '</td></tr>';
    }

    function sel(days) {
        return days === currentDays ? ' selected' : '';
    }

    function toFixed2(n) {
        return (parseFloat(n) || 0).toFixed(2);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Boot on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { fetchAnalytics(currentDays); });
    } else {
        fetchAnalytics(currentDays);
    }
}());
