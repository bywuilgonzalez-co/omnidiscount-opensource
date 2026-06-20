/* DRW Sale Items — infinite scroll + sort */
(function () {
    'use strict';

    if (typeof IntersectionObserver === 'undefined') return;

    function initWrap(wrap) {
        var raw = wrap.getAttribute('data-drw-config');
        if (!raw) return;
        var cfg;
        try { cfg = JSON.parse(raw); } catch (e) { return; }
        if (!cfg.ajaxUrl) return;

        var grid        = wrap.querySelector('.drw-sale-items-grid');
        var sentinel    = wrap.querySelector('.drw-sale-sentinel');
        var loader      = wrap.querySelector('.drw-sale-loading');
        var sortSel     = wrap.querySelector('.drw-sort-select');
        var catSel      = wrap.querySelector('.drw-cat-select');
        var clearBtn    = wrap.querySelector('.drw-clear-filters');
        var countEl     = wrap.querySelector('.drw-results-count');
        if (!grid || !sentinel) return;

        var busy = false;

        function updateCount() {
            if (!countEl) return;
            var shown = grid.querySelectorAll('.drw-sale-item').length;
            // textContent + unicode en-dash avoids innerHTML for plain text
            countEl.textContent = 'Mostrando 1–' + shown + ' de ' + cfg.total + ' resultados';
        }

        function setLoading(on) {
            if (loader) loader.style.display = on ? 'flex' : 'none';
        }

        function doFetch(page, replace) {
            if (busy) return;
            if (!replace && !cfg.hasMore) return;
            busy = true;
            setLoading(true);

            var fd = new FormData();
            fd.append('action',     'drw_sale_items');
            fd.append('nonce',      cfg.nonce);
            fd.append('page',       page);
            fd.append('per_page',   cfg.perPage);
            fd.append('category',   cfg.category  || '');
            fd.append('orderby',    cfg.orderby   || 'date');
            fd.append('scan_limit', cfg.scanLimit || 500);
            fd.append('ids',        cfg.ids       || '');

            fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        var data = resp.data;
                        if (replace) {
                            grid.innerHTML  = data.html || '';
                            cfg.total       = data.total;
                            cfg.page        = page;
                        } else {
                            grid.insertAdjacentHTML('beforeend', data.html || '');
                            cfg.page = page;
                        }
                        cfg.hasMore = !!data.has_more;
                        updateCount();
                        if (!cfg.hasMore) {
                            sentinel.style.display = 'none';
                        }
                    }
                    setLoading(false);
                    busy = false;
                })
                .catch(function () {
                    setLoading(false);
                    busy = false;
                });
        }

        // Infinite scroll
        var observer = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting && !busy && cfg.hasMore) {
                doFetch(cfg.page + 1, false);
            }
        }, { rootMargin: '300px' });
        observer.observe(sentinel);

        function syncClearBtn() {
            if (!clearBtn) return;
            clearBtn.style.display = cfg.category ? 'inline-flex' : 'none';
        }

        // Category filter
        if (catSel) {
            catSel.addEventListener('change', function () {
                cfg.category = this.value;
                cfg.hasMore  = true;
                sentinel.style.display = '';
                syncClearBtn();
                doFetch(1, true);
            });
        }

        // Clear filter button
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                cfg.category = '';
                if (catSel) catSel.value = '';
                cfg.hasMore = true;
                sentinel.style.display = '';
                syncClearBtn();
                doFetch(1, true);
            });
        }

        // Sync button state on init (in case category was pre-set via shortcode attr)
        syncClearBtn();

        // Sort dropdown
        if (sortSel) {
            sortSel.addEventListener('change', function () {
                cfg.orderby = this.value;
                cfg.hasMore = true;
                sentinel.style.display = '';
                doFetch(1, true);
            });
        }

        // Initial count
        updateCount();
    }

    // Init all grids present on the page
    var wraps = document.querySelectorAll('.drw-sale-wrap');
    for (var i = 0; i < wraps.length; i++) {
        initWrap(wraps[i]);
    }
})();
