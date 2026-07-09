/**
 * DRW Product / Category Picker
 * ------------------------------------------------------------------
 * Reusable async multi-select widget for choosing WooCommerce products
 * or product categories from anywhere in the admin app.
 *
 * REAL endpoint reused (see src/Controllers/ApiController.php):
 *   Namespace: drw/v1   (registered in ApiController::register_routes())
 *   Route:     GET /drw/v1/products   -> full path: /wp-json/drw/v1/products
 *   Query args accepted by the server (ApiController::search_products()):
 *     - search   (string)            free text, matched against WP_Query 's'
 *     - include  (string, CSV ids)   returns only these ids, in that order
 *     - page     (int, default 1)
 *     - per_page (int, default 20, hard-capped at 50 server-side)
 *   Response shape returned by the server:
 *     { items: [ { id, name, sku, type, text } ], page }
 *   Notes on that shape:
 *     - There is NO total/found_rows field (the query runs with
 *       'no_found_rows' => true for performance), so this component
 *       infers "has more pages" heuristically: if a page came back with
 *       a full page of results (length === per_page) we assume there
 *       might be another page.
 *     - The endpoint does NOT currently return thumbnail/price/stock.
 *       This component renders those fields defensively (only if
 *       present on the item), so it will pick them up for free if the
 *       endpoint is ever enhanced to include e.g. `thumbnail`,
 *       `price`/`price_html`, `stock_status`/`stock_quantity`.
 *
 * KNOWN LIMITATION (categories):
 *   There is NO REST endpoint for category search in this plugin.
 *   AdminController::enqueue_admin_assets() only localizes a flat,
 *   unpaginated list of ALL `product_cat` terms as
 *   `window.drwAdminData.categories = [{ id, name }, ...]`
 *   (src/Controllers/AdminController.php, ~lines 101-113). That list
 *   has no built-in search, pagination, or product-count field.
 *   Per the "reuse, don't invent a new endpoint" constraint, this
 *   component does NOT call a fake `/drw/v1/categories` REST route.
 *   Instead, in `mode: 'categories'` it performs search/filter and
 *   "load more" pagination entirely client-side against that
 *   pre-loaded list (the 300ms debounce is applied the same way, even
 *   though it's not strictly needed for a local array — it keeps the
 *   UX identical between both modes and avoids re-filtering on every
 *   keystroke). If a category shows a count it's only because the
 *   caller passed richer data in via the `categoriesData` prop; the
 *   default `drwAdminData.categories` list has no `count`. To get real
 *   server-side category search/pagination/counts, a new
 *   `GET /drw/v1/categories` route would need to be added to
 *   ApiController.php first (WP_Term objects from get_terms() already
 *   expose ->count, so AdminController would just need to stop
 *   dropping it when it builds the `categories` array).
 */
(function () {
    'use strict';

    if (!window.wp || !wp.element || !wp.components) {
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;

    var Button = wp.components.Button;
    var TextControl = wp.components.TextControl;
    var Spinner = wp.components.Spinner;

    var apiFetch = wp.apiFetch;

    var PRODUCTS_PATH = '/drw/v1/products';
    var PER_PAGE = 20;
    var DEBOUNCE_MS = 300;
    var HYDRATE_CHUNK = 50; // server hard-caps per_page/include batches at 50

    /**
     * Coerce any value into a de-duplicated array of positive integer ids.
     */
    function normalizeIds(ids) {
        if (!Array.isArray(ids)) {
            return [];
        }
        var seen = {};
        var out = [];
        ids.forEach(function (raw) {
            var id = parseInt(raw, 10);
            if (id > 0 && !seen[id]) {
                seen[id] = true;
                out.push(id);
            }
        });
        return out;
    }

    function getLocalCategories(override) {
        if (Array.isArray(override)) {
            return override;
        }
        var data = window.drwAdminData || {};
        return Array.isArray(data.categories) ? data.categories : [];
    }

    function stripHtml(html) {
        return String(html || '').replace(/<[^>]*>/g, '').trim();
    }

    /**
     * Only renders a price if the endpoint (or caller) actually provided one.
     */
    function formatPrice(item) {
        if (item.price_html) {
            return stripHtml(item.price_html);
        }
        if (item.price !== undefined && item.price !== null && item.price !== '') {
            return '$' + item.price;
        }
        return '';
    }

    /**
     * Only renders stock info if the endpoint (or caller) actually provided it.
     */
    function formatStock(item) {
        var STATUS_LABELS = {
            instock: 'En stock',
            outofstock: 'Agotado',
            onbackorder: 'Sobre pedido'
        };
        var hasQty = item.stock_quantity !== undefined && item.stock_quantity !== null && item.stock_quantity !== '';
        if (item.stock_status) {
            var label = STATUS_LABELS[item.stock_status] || item.stock_status;
            return hasQty ? label + ' (' + item.stock_quantity + ')' : label;
        }
        if (hasQty) {
            return item.stock_quantity + ' disp.';
        }
        return '';
    }

    function getThumbnail(item) {
        if (item.thumbnail) {
            return item.thumbnail;
        }
        if (item.image) {
            return item.image;
        }
        if (Array.isArray(item.images) && item.images[0]) {
            return typeof item.images[0] === 'string' ? item.images[0] : item.images[0].src;
        }
        return '';
    }

    function itemLabel(mode, item) {
        if (mode === 'categories') {
            var count = item.count !== undefined && item.count !== null ? ' (' + item.count + ')' : '';
            return (item.name || ('Categoría #' + item.id)) + count;
        }
        return item.text || item.name || ('Producto #' + item.id);
    }

    /**
     * ProductCategoryPicker({ value, onChange, mode })
     *
     * @param {number[]} props.value      Currently selected ids.
     * @param {function} props.onChange   Called with the new array of ids.
     * @param {'products'|'categories'} props.mode
     * @param {string}   [props.label]
     * @param {string}   [props.help]
     * @param {string}   [props.placeholder]
     * @param {string}   [props.className]
     * @param {number}   [props.perPage]        Page size (default 20).
     * @param {Array}    [props.categoriesData] Override for window.drwAdminData.categories
     *                                          (mostly for tests / custom sources).
     */
    function ProductCategoryPicker(props) {
        var mode = props.mode === 'categories' ? 'categories' : 'products';
        var value = props.value;
        var onChange = typeof props.onChange === 'function' ? props.onChange : function () {};
        var perPage = props.perPage || PER_PAGE;

        var idsKey = normalizeIds(value).join(',');

        var searchState = useState('');
        var search = searchState[0];
        var setSearch = searchState[1];

        var debouncedState = useState('');
        var debouncedSearch = debouncedState[0];
        var setDebouncedSearch = debouncedState[1];

        var pageState = useState(1);
        var page = pageState[0];
        var setPage = pageState[1];

        var resultsState = useState([]);
        var results = resultsState[0];
        var setResults = resultsState[1];

        var hasMoreState = useState(false);
        var hasMore = hasMoreState[0];
        var setHasMore = hasMoreState[1];

        var loadingState = useState(false);
        var loading = loadingState[0];
        var setLoading = loadingState[1];

        var loadingMoreState = useState(false);
        var loadingMore = loadingMoreState[0];
        var setLoadingMore = loadingMoreState[1];

        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];

        var selectedMetaState = useState([]);
        var selectedMeta = selectedMetaState[0];
        var setSelectedMeta = selectedMetaState[1];

        // --- Client-side debounce (300ms) -------------------------------
        // Applies to BOTH modes. For 'products' this is what prevents an
        // apiFetch call on every keystroke (the server has no debounce of
        // its own — each request is answered independently). For
        // 'categories' there is no network call at all, but we still
        // debounce so the local list isn't re-filtered on every keystroke
        // and the UX matches the products mode exactly.
        useEffect(function () {
            var timer = setTimeout(function () {
                setDebouncedSearch(search.trim());
            }, DEBOUNCE_MS);
            return function () {
                clearTimeout(timer);
            };
        }, [search]);

        // --- Fetch / filter first page whenever the debounced query changes
        useEffect(function () {
            setPage(1);
            if (mode === 'products') {
                fetchProductsPage(debouncedSearch, 1, false);
            } else {
                filterCategoriesPage(debouncedSearch, 1, false);
            }
            // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [debouncedSearch, mode]);

        // --- Hydrate chips for ids that arrive from outside (e.g. editing
        // an existing rule) whenever the selected id list actually changes.
        useEffect(function () {
            var ids = normalizeIds(value);

            if (ids.length === 0) {
                setSelectedMeta([]);
                return;
            }

            if (mode === 'categories') {
                var all = getLocalCategories(props.categoriesData);
                setSelectedMeta(ids.map(function (id) {
                    var found = all.filter(function (c) {
                        return parseInt(c.id, 10) === id;
                    })[0];
                    return found || { id: id, name: 'Categoría #' + id };
                }));
                return;
            }

            // products: only fetch ids we don't already have metadata for.
            setSelectedMeta(function (prev) {
                var known = {};
                prev.forEach(function (item) {
                    known[parseInt(item.id, 10)] = item;
                });

                var missing = ids.filter(function (id) {
                    return !known[id];
                });

                if (missing.length === 0) {
                    return ids.map(function (id) {
                        return known[id];
                    });
                }

                var chunks = [];
                for (var i = 0; i < missing.length; i += HYDRATE_CHUNK) {
                    chunks.push(missing.slice(i, i + HYDRATE_CHUNK));
                }

                Promise.all(chunks.map(function (chunk) {
                    return apiFetch({
                        path: PRODUCTS_PATH + '?include=' + encodeURIComponent(chunk.join(',')) + '&per_page=' + chunk.length
                    })
                        .then(function (data) {
                            return (data && data.items) || [];
                        })
                        .catch(function () {
                            return [];
                        });
                })).then(function (chunkResults) {
                    var fetched = [].concat.apply([], chunkResults);
                    fetched.forEach(function (item) {
                        known[parseInt(item.id, 10)] = item;
                    });
                    setSelectedMeta(ids.map(function (id) {
                        return known[id] || { id: id, name: 'Producto #' + id };
                    }));
                });

                // Return current best-effort list synchronously; the promise
                // above will refine it once the fetch resolves.
                return ids.map(function (id) {
                    return known[id] || { id: id, name: 'Producto #' + id };
                });
            });
            // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [idsKey, mode]);

        function fetchProductsPage(query, pageNum, append) {
            setLoading(!append);
            setLoadingMore(append);

            var qs = ['page=' + pageNum, 'per_page=' + perPage];
            if (query) {
                qs.push('search=' + encodeURIComponent(query));
            }

            apiFetch({ path: PRODUCTS_PATH + '?' + qs.join('&') })
                .then(function (data) {
                    var items = (data && data.items) || [];
                    setResults(function (prev) {
                        return append ? prev.concat(items) : items;
                    });
                    // No found_rows from the server (no_found_rows => true),
                    // so "more pages likely exist" is inferred from a full page.
                    setHasMore(items.length === perPage);
                    setError('');
                })
                .catch(function (err) {
                    setError((err && err.message) || 'No se pudieron cargar los productos.');
                    if (!append) {
                        setResults([]);
                    }
                    setHasMore(false);
                })
                .then(function () {
                    setLoading(false);
                    setLoadingMore(false);
                });
        }

        function filterCategoriesPage(query, pageNum, append) {
            var all = getLocalCategories(props.categoriesData);
            var q = query.toLowerCase();
            var filtered = q
                ? all.filter(function (c) {
                    return String(c.name || '').toLowerCase().indexOf(q) !== -1;
                })
                : all;

            var end = pageNum * perPage;
            var slice = filtered.slice(0, end);

            setResults(slice);
            setHasMore(filtered.length > end);
            setError('');
            setLoading(false);
            setLoadingMore(false);

            // Silence unused-param lint; kept for signature symmetry with
            // fetchProductsPage (append doesn't change local filtering, the
            // slice already grows from 0..end each call).
            void append;
        }

        function handleLoadMore() {
            var nextPage = page + 1;
            setPage(nextPage);
            if (mode === 'products') {
                fetchProductsPage(debouncedSearch, nextPage, true);
            } else {
                filterCategoriesPage(debouncedSearch, nextPage, true);
            }
        }

        function addItem(item) {
            var id = parseInt(item.id, 10);
            var ids = normalizeIds(value);
            if (ids.indexOf(id) === -1) {
                onChange(ids.concat([id]));
            }
            setSelectedMeta(function (prev) {
                var exists = prev.some(function (p) {
                    return parseInt(p.id, 10) === id;
                });
                return exists ? prev : prev.concat([item]);
            });
        }

        function removeItem(id) {
            var ids = normalizeIds(value).filter(function (existing) {
                return existing !== id;
            });
            onChange(ids);
            setSelectedMeta(function (prev) {
                return prev.filter(function (p) {
                    return parseInt(p.id, 10) !== id;
                });
            });
        }

        var selectedIds = normalizeIds(value);

        function renderRow(item) {
            var id = parseInt(item.id, 10);
            var isSelected = selectedIds.indexOf(id) !== -1;
            var label = itemLabel(mode, item);

            if (mode === 'categories') {
                return el('li', { key: id },
                    el(Button, {
                        type: 'button',
                        disabled: isSelected,
                        onClick: function () { addItem(item); }
                    }, isSelected ? label + ' — seleccionada' : label)
                );
            }

            var thumb = getThumbnail(item);
            var priceLabel = formatPrice(item);
            var stockLabel = formatStock(item);

            return el('li', { key: id },
                el(Button, {
                    type: 'button',
                    disabled: isSelected,
                    style: { display: 'flex', alignItems: 'center', gap: '8px' },
                    onClick: function () { addItem(item); }
                },
                    thumb && el('img', {
                        src: thumb,
                        alt: '',
                        style: { width: '28px', height: '28px', objectFit: 'cover', borderRadius: '4px', flex: '0 0 auto' }
                    }),
                    el('span', { style: { display: 'flex', flexDirection: 'column', flex: '1 1 auto', minWidth: 0 } },
                        el('span', { style: { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } },
                            isSelected ? label + ' — seleccionado' : label),
                        (priceLabel || stockLabel) && el('span', { style: { fontSize: '11px', color: '#64748b', display: 'flex', gap: '8px' } },
                            priceLabel && el('span', null, priceLabel),
                            stockLabel && el('span', null, stockLabel)
                        )
                    )
                )
            );
        }

        return el('div', { className: 'drw-product-search' + (props.className ? ' ' + props.className : '') },
            el(TextControl, {
                label: props.label || (mode === 'categories' ? 'Buscar categorías' : 'Buscar productos'),
                type: 'search',
                value: search,
                help: props.help || (mode === 'categories'
                    ? 'Filtra las categorías por nombre.'
                    : 'Escribe para buscar en el catálogo por nombre o SKU.'),
                placeholder: props.placeholder || (mode === 'categories' ? 'Buscar categoría...' : 'Buscar productos por nombre o SKU...'),
                onChange: setSearch
            }),

            loading && !loadingMore && el('div', { className: 'drw-product-search-status' },
                el(Spinner),
                el('span', null, mode === 'categories' ? 'Filtrando categorías...' : 'Buscando productos...')
            ),

            error && el('div', { className: 'drw-product-search-error' }, error),

            results.length > 0 && el('ul', { className: 'drw-product-search-results' },
                results.map(renderRow)
            ),

            !loading && results.length === 0 && el('p', { style: { fontSize: '12px', color: '#64748b' } },
                'Sin resultados.'),

            hasMore && el('div', { style: { marginTop: '6px' } },
                el(Button, {
                    type: 'button',
                    className: 'drw-secondary-btn',
                    isBusy: loadingMore,
                    disabled: loadingMore,
                    onClick: handleLoadMore
                }, loadingMore ? 'Cargando...' : 'Cargar más')
            ),

            selectedMeta.length > 0 && el('div', { className: 'drw-selected-products' },
                selectedMeta.map(function (item) {
                    var id = parseInt(item.id, 10);
                    var label = itemLabel(mode, item);
                    return el('span', { key: id, className: 'drw-selected-product' },
                        label,
                        el(Button, {
                            type: 'button',
                            className: 'drw-selected-product-remove',
                            onClick: function () { removeItem(id); },
                            label: 'Quitar ' + label
                        }, '×')
                    );
                })
            )
        );
    }

    window.DrwProductCategoryPicker = ProductCategoryPicker;
})();
