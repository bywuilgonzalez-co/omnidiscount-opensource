/* global drwAnalyticsData, wp */
(function () {
    'use strict';

    var apiRoot = (drwAnalyticsData && drwAnalyticsData.apiRoot) ? drwAnalyticsData.apiRoot : '';
    var nonce   = (drwAnalyticsData && drwAnalyticsData.nonce)   ? drwAnalyticsData.nonce   : '';
    var appEl   = document.getElementById('drw-analytics-app');
    var currentDays = 30;

    var DAY_OPTIONS = [
        { value: 7,   label: 'Últimos 7 días' },
        { value: 30,  label: 'Últimos 30 días' },
        { value: 90,  label: 'Últimos 90 días' },
        { value: 365, label: 'Últimos 365 días' },
    ];

    function buildUrl(days) {
        var sep = apiRoot.indexOf('?') === -1 ? '?' : '&';
        return apiRoot + sep + 'days=' + encodeURIComponent(days) + '&top_limit=5';
    }

    function fetchAnalytics(days) {
        if (!appEl) { return; }
        renderLoading();

        var url = buildUrl(days);

        // Prefer wp.apiFetch when available (already sets nonce via middleware)
        if (typeof wp !== 'undefined' && wp.apiFetch) {
            wp.apiFetch({ url: url })
                .then(function (data) { renderDashboard(data); })
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
            .then(function (data) { renderDashboard(data); })
            .catch(function (err) { renderError(err); });
    }

    /* ------------------------------------------------------------ */
    /* States                                                        */
    /* ------------------------------------------------------------ */

    function renderLoading() {
        appEl.innerHTML =
            toolbarHtml() +
            '<div class="drw-an-stats">' +
                skeleton(84) + skeleton(84) + skeleton(84) + skeleton(84) +
            '</div>' +
            '<div class="drw-an-panel">' + skeleton(180) + '</div>' +
            '<div class="drw-an-rankings">' +
                '<div class="drw-an-panel">' + skeleton(160) + '</div>' +
                '<div class="drw-an-panel">' + skeleton(160) + '</div>' +
            '</div>';
        wireToolbar();
        appEl.setAttribute('aria-busy', 'true');
    }

    function skeleton(height) {
        return '<div class="drw-an-skeleton" style="height:' + height + 'px;" aria-hidden="true"></div>';
    }

    function renderError(err) {
        var msg = (err && err.message) ? err.message : String(err);
        appEl.innerHTML =
            toolbarHtml() +
            '<div class="drw-an-error" role="alert">' +
                'No se pudieron cargar las analíticas. ' + escHtml(msg) +
            '</div>';
        wireToolbar();
        appEl.removeAttribute('aria-busy');
    }

    /* ------------------------------------------------------------ */
    /* Toolbar                                                       */
    /* ------------------------------------------------------------ */

    function toolbarHtml() {
        var options = DAY_OPTIONS.map(function (opt) {
            return '<option value="' + opt.value + '"' + (opt.value === currentDays ? ' selected' : '') + '>' +
                escHtml(opt.label) + '</option>';
        }).join('');

        return (
            '<div class="drw-an-toolbar">' +
                '<div class="drw-an-toolbar-field">' +
                    '<label for="drw-days-select">Rango de tiempo</label>' +
                    '<select id="drw-days-select" class="drw-an-select">' + options + '</select>' +
                '</div>' +
                '<span class="drw-an-meta" id="drw-an-live" aria-live="polite"></span>' +
            '</div>'
        );
    }

    function wireToolbar() {
        var select = document.getElementById('drw-days-select');
        if (select) {
            select.addEventListener('change', function () {
                currentDays = parseInt(this.value, 10);
                fetchAnalytics(currentDays);
            });
        }
    }

    /* ------------------------------------------------------------ */
    /* Dashboard                                                      */
    /* ------------------------------------------------------------ */

    function renderDashboard(data) {
        // get_woocommerce_currency_symbol() already returns an HTML entity
        // (e.g. "&#36;") ready for direct markup insertion; escaping it again
        // here would double-encode the "&" and render the literal entity
        // text instead of the symbol.
        var symbol = data.currency_symbol || '';

        var html =
            toolbarHtml() +
            statsHtml(data, symbol) +
            chartPanelHtml(data, symbol) +
            rankingsHtml(data, symbol);

        appEl.innerHTML = html;
        wireToolbar();
        appEl.removeAttribute('aria-busy');

        var live = document.getElementById('drw-an-live');
        if (live) {
            live.textContent = 'Analíticas actualizadas para los últimos ' + escHtml(String(data.days)) + ' días.';
        }
    }

    function statsHtml(data, symbol) {
        return (
            '<div class="drw-an-stats">' +
                statTile('Descuento total otorgado', symbol + money(data.total_discount)) +
                statTile('Pedidos con descuento', intFmt(data.orders_with_discounts)) +
                statTile('Descuento promedio por pedido', symbol + money(data.average_discount)) +
                statTile('Envíos gratis desbloqueados', intFmt(data.free_shipping_orders)) +
            '</div>'
        );
    }

    function statTile(label, value) {
        return (
            '<div class="drw-an-stat">' +
                '<p class="drw-an-stat-label">' + escHtml(label) + '</p>' +
                '<div class="drw-an-stat-value">' + value + '</div>' +
            '</div>'
        );
    }

    function chartPanelHtml(data, symbol) {
        var series = Array.isArray(data.timeseries) ? data.timeseries : [];
        var granularity = data.timeseries_granularity === 'week' ? 'semana' : 'día';

        var body;
        if (series.length === 0) {
            body = '<div class="drw-an-empty">No hay datos de tendencia para el rango seleccionado.</div>';
        } else {
            body = buildBarChart(series, symbol);
        }

        return (
            '<div class="drw-an-panel">' +
                '<div class="drw-an-panel-header">' +
                    '<h2 class="drw-an-panel-title">Tendencia de descuento otorgado</h2>' +
                    '<span class="drw-an-panel-caption">Agrupado por ' + granularity + '</span>' +
                '</div>' +
                body +
            '</div>'
        );
    }

    function buildBarChart(series, symbol) {
        var max = series.reduce(function (m, pt) { return Math.max(m, pt.discount_amount); }, 0);
        if (max <= 0) { max = 1; }

        var n = series.length;
        var gap = 1;
        var barWidth = n > 0 ? (100 - gap * (n - 1)) / n : 100;

        var bars = series.map(function (pt, i) {
            var h = Math.max(1, (pt.discount_amount / max) * 100);
            var x = i * (barWidth + gap);
            var y = 100 - h;
            var title = escHtml(pt.date) + ': ' + symbol + money(pt.discount_amount) +
                ' en ' + intFmt(pt.orders_count) + (pt.orders_count === 1 ? ' pedido' : ' pedidos');
            return (
                '<rect class="drw-an-bar" x="' + x.toFixed(2) + '" y="' + y.toFixed(2) + '" ' +
                'width="' + barWidth.toFixed(2) + '" height="' + h.toFixed(2) + '" rx="1.5">' +
                '<title>' + title + '</title>' +
                '</rect>'
            );
        }).join('');

        var first = series[0] ? formatDateShort(series[0].date) : '';
        var last  = series[n - 1] ? formatDateShort(series[n - 1].date) : '';
        var mid   = n > 2 ? formatDateShort(series[Math.floor((n - 1) / 2)].date) : '';

        return (
            '<div class="drw-an-chart-wrap">' +
                '<svg class="drw-an-chart-svg" viewBox="0 0 100 100" preserveAspectRatio="none" role="img" ' +
                    'aria-label="Descuento otorgado por período, de ' + escHtml(first) + ' a ' + escHtml(last) + '">' +
                    bars +
                '</svg>' +
                '<div class="drw-an-chart-axis">' +
                    '<span>' + escHtml(first) + '</span>' +
                    (mid ? '<span>' + escHtml(mid) + '</span>' : '') +
                    '<span>' + escHtml(last) + '</span>' +
                '</div>' +
            '</div>'
        );
    }

    function rankingsHtml(data, symbol) {
        return (
            '<div class="drw-an-rankings">' +
                // Redemption counts (used_count / uses) are lifetime cumulative
                // counters from AnalyticsController::get_top_*_by_redemptions(),
                // independent of the toolbar's date range. Labeled explicitly so
                // it doesn't read as "no data" vs "plenty of data" next to the
                // range-scoped stat tiles above, which can otherwise both show
                // on screen at once (e.g. a fresh "últimos 7 días" range with
                // $0 totals sitting right above triple-digit lifetime counts).
                rankPanel('Reglas y promos con más canjes', 'Total histórico', symbol, [
                    { title: 'Reglas', rows: rankRowsRedemptions(data.top_rules_by_redemptions, 'used_count', 'usage_limit') },
                    { title: 'Promos', rows: rankRowsRedemptions(data.top_promos_by_redemptions, 'uses', 'limit_global') },
                ]) +
                // Amount-attributed rankings ARE scoped to the selected range
                // (see AnalyticsController::get_top_by_amount()'s $since filter),
                // matching the stat tiles above; labeled for the same reason.
                rankPanel('Reglas y promos que más ahorro generaron', 'Rango seleccionado', symbol, [
                    { title: 'Reglas', rows: rankRowsAmount(data.top_rules_by_amount, symbol) },
                    { title: 'Promos', rows: rankRowsAmount(data.top_promos_by_amount, symbol) },
                ])
            + '</div>'
        );
    }

    function rankPanel(title, caption, symbol, groups) {
        var groupsHtml = groups.map(function (g) {
            return (
                '<h3 class="drw-an-rank-group-title">' + escHtml(g.title) + '</h3>' +
                g.rows
            );
        }).join('');

        return (
            '<div class="drw-an-panel">' +
                '<div class="drw-an-panel-header">' +
                    '<h2 class="drw-an-panel-title">' + escHtml(title) + '</h2>' +
                    (caption ? '<span class="drw-an-panel-caption">' + escHtml(caption) + '</span>' : '') +
                '</div>' +
                groupsHtml +
            '</div>'
        );
    }

    function rankRowsRedemptions(list, countKey, limitKey) {
        if (!Array.isArray(list) || list.length === 0) {
            return '<p class="drw-an-empty">Todavía no hay canjes registrados.</p>';
        }
        var rows = list.map(function (item, i) {
            var limit = item[limitKey];
            var sub = limit ? (intFmt(item[countKey]) + ' de ' + intFmt(limit) + ' usos') : 'Sin límite de usos';
            return rankRow(i + 1, item.title, sub, intFmt(item[countKey]), item.deleted);
        }).join('');
        return '<ul class="drw-an-rank-list">' + rows + '</ul>';
    }

    function rankRowsAmount(list, symbol) {
        if (!Array.isArray(list) || list.length === 0) {
            return '<p class="drw-an-empty">Sin descuentos atribuidos en este rango.</p>';
        }
        var rows = list.map(function (item, i) {
            var sub = intFmt(item.orders_count) + (item.orders_count === 1 ? ' pedido' : ' pedidos');
            return rankRow(i + 1, item.title, sub, symbol + money(item.amount), item.deleted);
        }).join('');
        return '<ul class="drw-an-rank-list">' + rows + '</ul>';
    }

    // Rules/promos that were later deleted still really produced their past
    // redemptions and discount volume, so the backend keeps them in these
    // rankings instead of hiding real history; this tag makes it clear the
    // entry no longer exists rather than implying it is still live.
    function rankRow(position, title, sub, value, deleted) {
        var safeTitle = title ? escHtml(title) : '(sin título)';
        var deletedTag = deleted ? ' <span class="drw-an-rank-deleted">Eliminada</span>' : '';
        return (
            '<li class="drw-an-rank-row">' +
                '<span class="drw-an-rank-badge">' + position + '</span>' +
                '<span class="drw-an-rank-info">' +
                    '<span class="drw-an-rank-title" title="' + safeTitle + '">' + safeTitle + '</span>' + deletedTag + '<br/>' +
                    '<span class="drw-an-rank-sub">' + sub + '</span>' +
                '</span>' +
                '<span class="drw-an-rank-value">' + value + '</span>' +
            '</li>'
        );
    }

    /* ------------------------------------------------------------ */
    /* Formatting helpers                                             */
    /* ------------------------------------------------------------ */

    function money(n) {
        return (parseFloat(n) || 0).toFixed(2);
    }

    function intFmt(n) {
        return String(parseInt(n, 10) || 0);
    }

    function formatDateShort(isoDate) {
        // isoDate is either 'YYYY-MM-DD' (day bucket) or the Monday of a
        // week bucket, both already in that format from the REST response.
        var parts = String(isoDate).split('-');
        if (parts.length !== 3) { return String(isoDate); }
        return parts[2] + '/' + parts[1];
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
