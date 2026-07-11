# Plan Maestro Final: Reinvención del Módulo "Cupones y Promociones" de OmniDiscount (discount-rules-woo v1.4.1)

---

## Nota editorial: qué le faltaba a cada propuesta antes de fusionarlas

Antes de presentar el plan unificado, como editor en jefe dejo constancia de las brechas que cada propuesta tenía por separado, porque la versión final existe precisamente para cerrarlas:

**A la propuesta Opus le faltaba:**
- Un modelo de datos propio para la capa de marketing. Proponía volcar TODO —incluidas metadatos puramente comerciales como "mostrar en portada", "mensaje en el carrito", badge visual— directamente como filas de `wp_drw_rules`, una tabla diseñada para condiciones/ajustes técnicos del motor. Esto contamina el esquema del motor de Reglas con columnas que no le pertenecen y complica la página "Reglas avanzadas" (tendría que filtrar `source != 'promo'` en todas partes).
- Tratamiento débil de la migración de datos existentes: la menciona ("migración one-shot") pero no especifica verificación de integridad ni ventana de convivencia con el JSON legacy más allá de un backup.
- No profundiza en el "modo sandbox"/simulador para probar una promo antes de publicarla — una idea que si aparece en Fable y que cierra directamente la queja de mercado más citada ("la regla no funciona").
- Su mapeo de iconos incluye un ícono no verificable en el catálogo real de Material Symbols para algunos casos y no distingue claramente el eje FILL para animación de estado dentro de la tabla misma.
- No aborda con suficiente detalle qué pasa si el comerciante ya tiene 500 productos y activa el buscador — no habla de paginación/debounce en el `ProductCategoryPicker`.

**A la propuesta Fable le faltaba:**
- Un mecanismo explícito de "recipe" declarativo por tipo tan claro como el de Opus (Opus nombra explícitamente la tabla tipo→adjustment+conditions; Fable lo da por sentado dentro de `PromoBridgeController` sin tabularlo).
- Tratamiento de multisitio menos desarrollado que Opus en el detalle de `switch_to_blog` durante activación de red.
- No propone explícitamente un flag/columna para identificar visualmente en el listado de "Reglas avanzadas" cuáles filas de `wp_drw_rules` fueron generadas por una Promo (Opus sí lo hace con `source='promo'`).
- Su cobertura de "modo dual wizard/experto" es mencionada en tendencias pero no aterrizada como funcionalidad concreta con nombre de componente.
- Comparativa competitiva no incluye explícitamente a Shopify/YayPricing como referencia de patrones de UX (sí lo hace en la investigación de mercado adjunta, pero no lo cruza dentro de su propia propuesta).

**Vacíos que NINGUNA de las dos propuestas cerró bien y que llenamos en este documento:**
1. **Compatibilidad exhaustiva con otros plugins de cupones** — ambas la tocan tangencialmente; aquí se especifica un flujo concreto de detección + resolución de colisiones.
2. **Migración de datos con criterios de éxito verificables** (conteo de filas, checksum, rollback).
3. **Mapeo de iconos unificado y verificado** contra nombres reales de Material Symbols (se corrige un nombre inventado de Fable: `delivery_truck_speed` no existe en el catálogo oficial; se reemplaza por `local_shipping` consistentemente, y se resuelve el choque `money_off` vs `attach_money` favoreciendo `attach_money` para "descuento fijo en dinero" reservando `money_off` para tipos de tipo "liquidación/rebaja").
4. **Paginación/rendimiento del selector de productos** para tiendas grandes.
5. **Política de borrado no destructivo** (papelera con undo) generalizada a todo el módulo, no solo mencionada de pasada.

---

## Resumen ejecutivo

El módulo "Cupones y Promociones" de OmniDiscount es, hoy, una **ficción funcional**: `src/Controllers/PromosController.php` ofrece un CRUD REST pulido (`drw/v1/promos`) que persiste todo en un único blob JSON en `wp_options('drw_promos')`, pero **ningún hook de WooCommerce lee esa opción**. Los hooks reales de precio —`woocommerce_before_calculate_totals`, `woocommerce_cart_calculate_fees`, `woocommerce_get_shop_coupon_data`, registrados en el `CartController`— solo consumen `RulesEngine::get_active_rules()` sobre la tabla `wp_drw_rules`. **Un comerciante que crea una "promoción" en este panel hoy no cambia un solo centavo en el checkout.**

En paralelo, el plugin ya posee un motor de descuentos maduro, probado y enganchado correctamente (`RulesEngine.php`, `Conditions/*.php`, `Adjustments/*.php`: `percentage`, `fixed`, `bulk`, `bogo`, `free_shipping`, `bundle_set`). El plan maestro no reinventa ese motor: lo **conecta**.

La estrategia final combina lo mejor de ambas propuestas en tres movimientos:

1. **VERDAD ECONÓMICA** — nueva tabla dedicada `wp_drw_promos` (capa de marketing/metadatos) + un `PromoBridgeController` que traduce cada promoción en (a) un `WC_Coupon` nativo cuando requiere código, o (b) una fila compilada de `wp_drw_rules` (marcada `source='promo'`) cuando es automática — sin tocar ni un hook del motor existente, sin duplicar lógica de cálculo de precios.
2. **SIMPLICIDAD RADICAL** — el formulario de una sola columna con 15 botones y todos los campos visibles se sustituye por un **wizard de 4 pasos** organizado por objetivo de negocio, con plantillas de 1 clic, selector real de productos/categorías, validación inline, vista previa en vivo con datos reales de la tienda y un modo simulador ("Probar en mi tienda") que cierra la queja #1 del mercado.
3. **CONFIANZA VERIFICABLE** — iconografía Material Symbols Rounded auto-hospedada, compatibilidad probada con HPOS, Cart/Checkout Blocks, multisitio, multi-moneda y coexistencia con otros plugins de cupones, detector de conflictos pre-publicación, y métricas/benchmarks publicados que ningún competidor ofrece hoy.

**Meta medible:** pasar de **0% de promociones con efecto económico real** a **100%**, con un tiempo hasta la primera promoción publicada por debajo de **90 segundos** (el mejor competidor evaluado, Disco de Flycart, tarda ~3 minutos).

---

## Diagnóstico crítico del estado actual

### Hallazgo #1 (bloqueante, silencioso): las promociones no aplican ningún descuento real

- `PromosController::save_promos()` escribe/reescribe el array completo en `wp_options('drw_promos')`.
- No existe ningún `CartController`, `RulesEngine` ni hook que lea la clave `drw_promos`.
- Los hooks reales de precio (`woocommerce_before_calculate_totals`, `woocommerce_cart_calculate_fees`, `woocommerce_get_shop_coupon_data` en `CartController.php`) solo consumen `wp_drw_rules` vía `RulesEngine::get_active_rules()`.
- **Consecuencia de negocio:** un comerciante configura "20% off en categoría Zapatos", ve la tarjeta bonita en el listado, publica la campaña en redes... y en el checkout real no pasa absolutamente nada. Este es el defecto de producto más grave que puede tener un plugin de descuentos, y hoy afecta al 100% de las promociones creadas por este módulo.

### Hallazgo #2: almacenamiento fràgil con condición de carrera

`save_promos()` sigue el patrón *read-modify-write* sobre un único string JSON: carga el array completo, lo modifica, lo vuelve a guardar entero. Dos guardados concurrentes (dos pestañas del admin, un toggle mientras se edita, un cron y un humano a la vez) se pisan silenciosamente y pierden datos. No hay tabla propia, no hay índices, no hay claves únicas, no escala más allá de unas pocas docenas de promociones antes de volverse lento (cada lectura deserializa TODO el JSON).

### Hallazgo #3: catálogo de tipos duplicado y desincronizado

`PromosController::type_definitions()` (PHP, iconos estilo "percent", "dollar-sign", "rocket") y `PROMO_TYPES` en `admin-promos.js` (JS, dashicons "tag", "star-filled", "archive", "car", "cart", "update", "groups") son **dos catálogos independientes que ya divergen** en iconos, y potencialmente en `needsCode` y colores. El endpoint `GET /promos/types` existe pero **nunca se consume desde el frontend** — código muerto que finge ser la fuente de verdad.

### Hallazgo #4: el campo "Aplica a" es decorativo

Cuatro strings fijos ("Todo el carrito", "Productos marca propia", "Primera compra", "Clientes recurrentes") que se guardan como texto plano, sin ligarse a productos/categorías reales — mientras que el motor de Reglas ya tiene `Conditions/` reales (`UserRole`, `PurchaseHistory`, filtros `product_ids`/`category_ids`) que hacen exactamente esto de verdad. Los tipos "Regalo por compra" y "Bundle" no permiten elegir productos reales, aunque `Adjustments/Bogo.php` y `Adjustments/BundleSet.php` ya lo soportan en el motor.

### Hallazgo #5: el contador de canjes ("uses") es una mentira piadosa

Se inicializa en 0 y se preserva en cada edición, pero **ningún hook de pedido lo incrementa jamás**. Las KPIs de "canjes totales" y las barras de progreso de uso en `PromoCard` muestran siempre datos falsos. El motor de Reglas sí tiene el patrón correcto (`RuleModel::increment_usage()`, `wp_drw_rules.used_count`) — Promos simplemente vive fuera del ciclo de vida real de un pedido.

### Hallazgo #6: validación de backend insuficiente

`validate_promo()` no verifica `end >= start`, no garantiza unicidad de código (ni contra otras promos ni contra `WC_Coupon` existentes de otro plugin), y no obliga el código cuando `needsCode=true`.

### Hallazgo #7: UX de alta carga cognitiva

Un modal de una sola columna y scroll infinito muestra los 15 tipos de oferta y **todos** los campos a la vez, sin importar el tipo elegido: no hay wizard, no hay plantillas, no hay vista previa, no hay validación inline (solo se deshabilita el botón final), no hay duplicar, no hay búsqueda/orden en la grilla, no hay agrupación por objetivo.

### Hallazgo #8: iconografía anticuada

Dashicons: set cerrado, sin variantes de peso/relleno/tamaño óptico, imposible de animar entre estados (activa/pausada) sin cambiar de glifo, visualmente detrás de la competencia moderna (YayPricing, Shopify).

### Hallazgo #9: los 15 tipos de promo no mapean 1:1 con los 6 ajustes reales del motor

`percentage`, `fixed`, `bulk`, `bogo`, `free_shipping`, `bundle_set` son los únicos ajustes que el motor ejecuta hoy. Tipos como `tiered`, `second_unit`, `cashback`, `flash`, `launch`, `welcome`, `data_capture` no tienen, hoy, ninguna forma de ejecutarse aunque se resuelva el almacenamiento — se necesita una capa de traducción explícita (ver arquitectura).

### Hallazgo #10: riesgo de coexistencia con otros plugins de cupones

`woocommerce_get_shop_coupon_data` ya "inventa" un `WC_Coupon` virtual con un ID aleatorio (`99999900 + rand`) que puede colisionar con IDs de cupones de otros plugins (Advanced Coupons, Smart Coupons) y solo cubre condiciones `cart_coupon`. Las promociones con código no participan de esta ruta hoy: un código de promo escrito en el carrito no hace nada.

---

## Análisis competitivo

| Plugin / referencia | Fortaleza documentada | Por qué OmniDiscount lo supera con este plan |
|---|---|---|
| **Advanced Coupons** | Cart Conditions con grupos AND/OR, BOGO nativo, Store Credit, Coupon Templates, IA generativa (StoreAgent) para generar cupones desde texto | Todo incluido en un solo plugin GPLv3 sin fragmentar en add-ons de pago (Coupons/Loyalty/Gift Cards/Promo Kit); nuestro "Describe tu promo" da el 80% del valor de StoreAgent sin coste ni dependencia de IA externa; nuestro detector de conflictos previene exactamente su bug documentado de múltiples BOGO auto-aplicados chocando entre sí |
| **Smart Coupons (StoreApps)** | Un solo plan con todo incluido, generador masivo de códigos (Bulk Coupon Generator) | Somos gratuitos (vs. $129/año sin capa gratis); flujo de wizard reduce el "demasiados pasos para actualizar cupones" que citan sus reseñas; cubrimos su fortaleza de generación masiva con el tipo de roadmap `bulk_codes` |
| **Discount Rules for WooCommerce / Disco (Flycart)** | Líder en instalaciones (100k+), flujo de 4 pasos en una pantalla, ~3 min hasta la primera regla | Bajamos el tiempo a <90s con plantillas precargadas + vista previa que elimina el ciclo "guardar y comprobar en la tienda real"; resolvemos su deuda documentada en Cart/Checkout Blocks (v2.6.4 "en desarrollo") construyendo sobre Store API desde el día uno; publicamos benchmark de rendimiento (su queja #1 documentada es 1s→17s con WPML+caché) |
| **YITH Dynamic Pricing and Discounts** | Constructor de condiciones anidadas maduro, rediseño 2.0 con plantillas | Gratuito (vs. licencia anual con incremento en renovación 129,99€→149,99€); tratamos multi-moneda como caso de primera clase (su bug documentado: "solo funciona bien en USD"); progressive disclosure evita la densidad que su propio fabricante admitió que resultaba "complicada" antes del 2.0 |
| **Advanced Dynamic Pricing for WooCommerce** | Actualización de precio en vivo en producto, buena capa gratuita | Resolvemos su issue público en GitHub (#42562, incompatible con Cart/Checkout Blocks) vía Store API nativa; su bug de "suma el carrito completo" al combinar reglas por producto se previene con nuestro `ConflictChecker` |
| **FunnelKit (WooFunnels)** | Barra de progreso gamificada en carrito lateral, cupones dinámicos por email en recuperación de carrito | Integramos la barra de progreso y gamificación directamente en el motor de descuentos (no en un embudo separado de pago); sin el modelo de precios "innecesariamente complicado" que citan sus reseñas ni los tiempos de soporte de 12-84h |
| **Iconic (IconicWP)** | Plugins de propósito único muy pulidos (checkout, cross-sell) | No compite de frente, pero su consolidación forzosa dentro de Liquid Web (mayo 2026) generó incertidumbre de marca; capitalizamos ofreciendo continuidad como plugin independiente, GPLv3, sin riesgo de fusión corporativa |
| **Conditional Discounts / Booster Plus** | Gratuitos, descuentos por rol sin paywall (Conditional Discounts); amplitud de módulos (Booster) | Conditional Discounts está en modo legacy incompatible con HPOS — nosotros ya lo declaramos; Booster es "buenas funciones, mal codificado, soporte lento" según sus propias reseñas — nosotros tenemos un motor especializado con caché e índices, no una navaja suiza genérica |
| **YayPricing** | UI moderna en tarjetas, vista previa de precio en vivo, plantillas como primer paso | Igualamos su vista previa en vivo pero con **producto real de la tienda** (no valores genéricos) y la extendemos a un mini-carrito simulado; añadimos modo sandbox y analítica de canjes verídica, ambas ausentes en su capa gratuita |
| **Shopify (descuentos nativos)** | Summary Card en vivo, selección por tarjetas, casillas de combinación explícitas | Traemos ese estándar de pulido al admin de WordPress usando componentes nativos `@wordpress/components`, sin exigir código (Shopify sí lo exige para lógica avanzada vía Functions) |
| **Klaviyo / Omnisend** (referencia UX) | Modo dual wizard/canvas, librería de 100+ plantillas | Adoptamos el modo dual (wizard guiado + "modo experto" que abre el motor de Reglas avanzado) sin abandonar el contexto WooCommerce |

---

## Arquitectura técnica objetivo

### 1. Nueva tabla de marketing: `wp_drw_promos`

Se crea siguiendo el mismo patrón de `wp_drw_rules` en `src/Models/Database.php::create_tables()`, vía `dbDelta`:

```
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
name            VARCHAR(191)
code            VARCHAR(64) NULL, UNIQUE KEY code_unique (code)
type            VARCHAR(32)              -- referencia a PromoTypeRegistry
value           DECIMAL(10,4)
scope           LONGTEXT                 -- JSON {target: all|products|categories, ids: []}
min_amount      DECIMAL(10,4) NULL
limit_global    INT NULL
limit_user      INT NULL
uses            INT NOT NULL DEFAULT 0
date_from       DATETIME NULL
date_to         DATETIME NULL
active          TINYINT(1) NOT NULL DEFAULT 1
home            TINYINT(1) NOT NULL DEFAULT 0
status          VARCHAR(16) DEFAULT 'draft'  -- draft|active|paused|archived
cart_message    VARCHAR(255) NULL
gift_config     LONGTEXT NULL            -- para gift/bundle: product_ids, cantidades
tier_config     LONGTEXT NULL            -- para tiered: tabla de tramos
wc_coupon_id    BIGINT UNSIGNED NULL     -- espejo si needsCode
rule_id         BIGINT UNSIGNED NULL     -- fila compilada en wp_drw_rules si automática
template_origin VARCHAR(64) NULL
created_at      DATETIME
modified_at     DATETIME
deleted_at      DATETIME NULL            -- borrado suave (papelera con undo)
KEY active_idx (active, deleted_at)
KEY dates_idx (date_from, date_to)
KEY type_idx (type)
```

Se crea `src/Models/PromoModel.php` (espejo de `RuleModel.php`): `insert()`, `update()`, `delete()` (soft), `restore()`, `increment_usage()` — todas operaciones **atómicas por fila**, eliminando por completo la condición de carrera de `save_promos()`.

*Por qué una tabla nueva y no reusar `wp_drw_rules` directamente (como sugería una de las propuestas de origen):* `wp_drw_rules` es el esquema del **motor técnico** (condiciones/ajustes); forzar en él campos puramente de marketing (`home`, `cart_message`, `gift_config`, `template_origin`) contaminaría ese esquema y complicaría el listado de "Reglas avanzadas", que tendría que filtrar constantemente `source != 'promo'`. La tabla dedicada mantiene los dos dominios (marketing vs. motor) separados pero conectados.

### 2. El puente: `src/Controllers/PromoBridgeController.php` (nuevo)

Es la pieza que convierte "verdad visual" en "verdad económica", con **dos vías de materialización** según el catálogo canónico de tipos:

**Vía A — Tipos con código (`needsCode=true`): descuento porcentual, fijo, bienvenida, envío gratis con cupón, captura de datos, cashback-código.**
Se sincroniza un `WC_Coupon` nativo (`post_type=shop_coupon`) en cada create/update/delete/toggle:
- `wc_get_coupon_id_by_code()` para idempotencia (nunca duplicar códigos).
- `discount_type` (`percent`/`fixed_cart`/`fixed_product`), `amount`, `date_expires`, `usage_limit`, `usage_limit_per_user`, `minimum_amount`, `free_shipping`.
- `product_ids` / `product_categories` derivados del **scope real** (ver punto 4).
- Meta `_drw_promo_id` en el cupón; `wc_coupon_id` en la fila de `wp_drw_promos` (espejo bidireccional).
- Beneficio: WooCommerce aplica el descuento con su propio motor probado — funciona automáticamente con Cart/Checkout Blocks, emails transaccionales, URLs de auto-aplicación, y aparece en WooCommerce Analytics > Coupons.

**Vía B — Tipos automáticos sin código: 2x1, 3x2, segunda unidad, escalonado, bundle, regalo, envío gratis con umbral, flash/lanzamiento sin código.**
Se compilan como fila de `wp_drw_rules` (columna nueva `source` + `promo_id` añadida vía `dbDelta`, con `KEY source_idx (source)`), reutilizando el vocabulario ya existente:
- `Adjustments/Bogo.php` → 2x1, 3x2, segunda unidad, regalo por compra (get_product_type='different').
- `Adjustments/BundleSet.php` → bundle/combo con productos reales.
- `Adjustments/FreeShipping.php` → envío gratis con umbral (+ `Conditions/CartSubtotal.php`).
- Ajuste `bulk` existente → escalonado por monto/cantidad (tabla de tramos, `tier_config`).
- `Conditions/OrderDate.php` → ventana de vigencia (flash, lanzamiento).
- `Conditions/PurchaseHistory.php` → "primera compra"/"clientes recurrentes" (reemplazando el string decorativo).
- `Conditions/UserRole.php` → segmentación por rol.

`RulesEngine::get_active_rules()` y `CartController.php` **no cambian su lógica de cálculo**: solo empiezan a encontrar también filas `source='promo'`. Cero hooks nuevos, cero motor nuevo — se reutiliza el camino de precios ya probado en producción.

**Fallback legacy:** `woocommerce_get_shop_coupon_data` se conserva solo como respaldo para casos de borde, pero se reemplaza el `id` aleatorio (`99999900 + mt_rand()`) por un **id determinístico derivado de `promo_id`**, eliminando el riesgo de colisión con cupones virtuales de terceros.

### 3. Catálogo único de tipos: `PromoTypeRegistry`

`PromosController::type_definitions()` se convierte en la única fuente de verdad: registro estático PHP con `id`, `label` (i18n), descripción de una línea, `group` (objetivo de negocio), icono Material Symbol, color, `needsCode`, `valueType`, campos requeridos, valores por defecto de plantilla, y el **"recipe"** declarativo de mapeo a adjustment+conditions (ver tabla de tipos más abajo). Se inyecta al frontend vía `wp_localize_script` en `AdminController.php` (precarga sin round-trip) y `GET /drw/v1/promos/types` queda como endpoint de refresco. **Se elimina por completo `PROMO_TYPES` de `admin-promos.js`.** Los mismos "campos requeridos por tipo" alimentan tanto la validación inline en el wizard como `validate_promo()` en PHP — una sola definición, dos consumidores.

### 4. Scope real: reemplazo del campo "Aplica a"

Nueva estructura `{target: 'all'|'products'|'categories', ids: [...]}` persistida en `wp_drw_promos.scope`, con un endpoint de búsqueda de productos/categorías (proxy paginado sobre `wc/v3/products` y `wc/v3/products/categories`, con debounce y paginación en el frontend para tiendas con catálogos grandes). Este scope alimenta directamente los `filters.product_ids`/`category_ids` que el motor de Reglas ya entiende.

### 5. Validación de backend endurecida

`validate_promo()` gana: unicidad de código contra `wp_drw_promos` (índice `UNIQUE`) **y** contra `shop_coupon` existente (`wc_get_coupon_id_by_code`), `needsCode` obligatorio según el catálogo canónico, `end >= start`, validación de existencia real de productos/categorías del scope, y validación dirigida por tipo (bundle exige ≥2 productos, gift exige producto de regalo, tiered exige tramos crecientes). Errores estructurados por campo (`{field, code, message}`) para alimentar la validación inline del wizard.

### 6. Migración de datos existentes (criterios de éxito verificables)

Hook en `Updater.php` al detectar bump de versión de esquema:
1. Leer `wp_options('drw_promos')`.
2. Por cada entrada: crear fila en `wp_drw_promos` vía `PromoModel::insert()` + ejecutar `PromoBridgeController::compile()` para materializarla (WC_Coupon o fila de `wp_drw_rules`).
3. Conservar el JSON original íntegro en `wp_options('drw_promos_legacy_backup')` (no se borra hasta pasar 2 versiones mayores).
4. **Criterio de éxito verificable:** `COUNT(*)` de `wp_drw_promos` tras migración == `count($json_original)`; checksum de nombres/códigos migrados registrado en log de activación; si el conteo no coincide, la migración se marca `incompleta` y se muestra un aviso de admin con botón "reintentar migración" (no se borra el legacy hasta que el conteo cuadre).
5. Multisitio: si `is_multisite()`, iterar por blog con `switch_to_blog()` durante la migración y en `wpmu_new_blog`/activación de red para creación de tablas.

### 7. Tracking de canjes real

Hooks HPOS-safe (solo APIs `WC_Order`, nunca acceso directo a postmeta): `woocommerce_order_status_processing`/`completed` → para promos-cupón, leer `$order->get_coupon_codes()` y resolver por `wc_coupon_id`; para promos automáticas, extender el ya existente `save_line_item_metadata` (enganchado a `woocommerce_checkout_create_order_line_item`) para incluir `promo_id`. En ambos casos: `UPDATE wp_drw_promos SET uses = uses + 1 WHERE id = %d` (atómico a nivel de MySQL) + fila en `wp_drw_order_discounts` (tabla ya existente) para analítica real. Endpoint nuevo `GET /promos/<id>/stats`.

### 8. Rendimiento y caché

Snapshot de "promos activas compiladas" en transient (`drw_active_promos_v{hash}`), invalidado quirúrgicamente solo en save/toggle/delete + cron diario para expiraciones por fecha. Short-circuit: si no hay promos automáticas activas, cero trabajo adicional en `woocommerce_before_calculate_totals` (bail-out antes de instanciar condiciones). Se publica un benchmark reproducible vía WP-CLI (50 reglas / 500 productos, p95 del hook) en el README — ningún competidor evaluado publica cifras propias.

### 9. Compatibilidad universal

- **HPOS:** ya declarado en `discount-rules-woo.php`; toda escritura de canjes usa `WC_Order`/`$order->get_meta()`, nunca `wp_posts` directo.
- **Multisitio:** `wp_drw_promos` y `wp_drw_rules` usan `$wpdb->prefix` (por sitio); creación de tabla replicada en altas de red.
- **Multi-moneda:** valores almacenados en la moneda base de la tienda; los hooks de precio corren con prioridad posterior a los currency switchers (Aelia, WOOCS, WPML) ya en `CartController`; se añaden pruebas explícitas contra estos tres plugins (el bug documentado de YITH — "solo funciona en USD" — se convierte en argumento de venta explícito de que nosotros sí lo probamos).
- **Cart/Checkout Blocks:** los descuentos de línea/fees ya funcionan bajo bloques porque operan sobre `WC_Cart`; se extiende `StoreApiController.php` (ya existe) con `extensionCartUpdate`/`ExtendSchema` para exponer badge, mensaje de carrito y barra de progreso vía Store API, y se emite el `cart_message` como Cart Notice en Blocks.
- **Coexistencia con otros plugins de cupones (Advanced/Smart Coupons):** al preferir siempre el `WC_Coupon` persistido sobre el virtual, las promos aparecen en Marketing > Cupones (con columna "Gestionado por OmniDiscount" y candado de edición); se respeta `individual_use`/`exclude_sale_items` nativos; pantalla de diagnóstico en Ajustes que detecta otros plugins de descuento activos y muestra el orden de ejecución de hooks para depurar solapamientos; el generador de código verifica unicidad contra `shop_coupon` de CUALQUIER origen, no solo el propio.

---

## Rediseño de UX

### Listado de promociones (PromosPage) — el centro de mando

Se conserva la base (`PromoCard`, KPIs, chips de filtro Todas/Activas/Pausadas/En portada/Con código/Automáticas) y se añade:
- Buscador por nombre/código y ordenación (recientes / más canjeadas / próximas a expirar).
- Badges de estado reales: **Activa / Programada / Expirada / Borrador / Archivada / Conflicto**.
- Toggle activar/pausar directamente en la tarjeta, con animación del ícono Material Symbol vía eje `FILL` (0→1).
- Menú kebab: **Duplicar** (clona todo, sufija código con "-2", abre el wizard en el paso 3 para revisar), **Guardar como plantilla propia**, **Archivar** (papelera con undo de 5 segundos — reemplaza el borrado destructivo actual).
- KPIs superiores ahora con datos **verídicos** (alimentados por el tracking real de canjes) + sparkline de 30 días.
- Estado vacío minimalista: ícono Material Symbol a 48px + titular breve + una frase + CTA primario "Elegir plantilla" que enlaza directo al Paso 0 del wizard (patrón recomendado por la guía oficial de WooCommerce: sin ilustraciones de marca pesadas).
- Modo dual: botón secundario "Modo experto" que abre el motor de Reglas avanzado completo (grupos AND/OR, prioridad, exclusividad) para power users, sin que el wizard sea una camisa de fuerza (patrón Klaviyo).

### Paso 0 — Galería de plantillas por objetivo

Al pulsar "+ Nueva promoción" **no se abre un formulario**: se abre una galería agrupada por lo que el comerciante quiere lograr, no por mecánica técnica:
- **Descuento directo:** porcentual, fijo, precio de lanzamiento, oferta flash.
- **Vender más por pedido:** 2x1, 3x2, segunda unidad, escalonado, bundle, envío gratis con umbral.
- **Envío:** envío gratis con cupón, envío gratis con umbral.
- **Captar y fidelizar:** cupón de bienvenida, regalo por compra, cashback/puntos, captura de datos.
- **Avanzado:** acceso directo al motor de Reglas completo (modo experto).

Cada tarjeta: ícono Material Symbol + título + descripción de una línea + micro-ejemplo ("Ej: 20% en toda la tienda"). Fila superior de **plantillas de 1 clic con valores precargados editables**: "Black Friday -20% toda la tienda", "3x2 en categoría", "Envío gratis desde $50", "Cupón de bienvenida 10%", "Oferta flash 24h". Buscador de texto libre y opción explícita "Empezar en blanco (modo experto)". Elegir una plantilla salta directo al Paso 2 con todo prellenado — una promoción funcional está a 2 clics de publicarse.

### Paso 1 — Definir a QUÉ aplica (scope real)

Reemplaza el "Aplica a" decorativo: tres pestañas — **Toda la tienda** / **Productos específicos** (buscador asíncrono con miniatura, precio y stock, multi-selección con chips removibles, paginado y con debounce) / **Categorías específicas** (árbol con contadores de productos). Sección opcional "Solo para" tras el botón "Añadir condición" (progressive disclosure): rol de cliente, primera compra/recurrente, ubicación, rango de fechas/horas. Para 2x1/gift/bundle, aquí se eligen los productos "compra" y "lleva"/regalo/combo reales.

### Paso 2 — Definir el descuento y las reglas de uso

Solo se muestran los campos relevantes al tipo elegido (dirigido por el catálogo canónico):
- **Valor:** % o monto en la moneda real de la tienda (`get_woocommerce_currency_symbol`).
- **Editores especiales:** `TieredTableEditor` para escalonado, `BogoBuyGetPicker` para 2x1/3x2/segunda unidad/regalo, `BundleBuilder` para combos con precio de set.
- **Bloque "Límites"** (colapsable, con resumen en la cabecera): compra mínima, límite total de canjes, canjes por cliente, exclusividad.
- **Bloque "Vigencia":** fechas con presets ("Este fin de semana", "Todo el mes", "Sin fin"); contador para flash.
- **Bloque "Código":** toggle "Requiere código" → generador automático + verificación de unicidad **en vivo** contra promos y contra `WC_Coupon` nativos de cualquier plugin, con aviso inmediato si el código ya existe.
- **Validación inline por campo** en evento `blur` (no on-keystroke), con `aria-describedby`/`aria-invalid` y foco automático al primer error — nunca solo el botón final deshabilitado.
- Panel lateral persistente de **vista previa en vivo** ya visible desde este paso (ver componente `LivePreviewPanel`).

### Paso 3 — Revisar y publicar

- **Resumen en lenguaje natural** generado desde la configuración real: *"2x1 en la categoría Camisetas, automático, para clientes que hayan comprado antes, del 15 al 30 de julio, máx. 1 vez por cliente. Mensaje en el carrito: ¡Llevas un regalo!"*.
- **Vista previa final** en dos contextos: tarjeta de producto con badge + mini-carrito simulado con el descuento aplicado, recalculado por el mismo `RulesEngine` que corre en producción (endpoint de simulación *dry-run* que no persiste).
- **"Probar en mi tienda" (modo sandbox):** activa la promo solo para el admin en el frontend real vía cookie firmada, sin exponerla a clientes — cierra el ciclo prueba-error que hoy obliga a "guardar y comprobar en la tienda".
- **Chequeo automático pre-publicación (`ConflictChecker`, semáforo):** solapamiento con otras promos activas sobre los mismos productos/fechas, código duplicado (propio o de terceros), fechas incoherentes, descuento mayor al precio (margen negativo), scope vacío.
- CTA doble: **"Guardar borrador"** (estado `draft`) o **"Publicar"**. Al publicar se ejecuta `PromoBridgeController::compile()`. Toast final con enlaces "Ver en la tienda" y "Duplicar para otra categoría".
- El stepper superior permite volver atrás en cualquier momento **sin perder datos** (cumple el criterio "Redundant Entry": nunca se vuelve a pedir un dato ya provisto).

### Componentes nuevos (frontend, `wp.element`, sin build step, consistentes con el stack actual)

| Componente | Función |
|---|---|
| `PromoWizard` + `StepperNav` | Contenedor de 4 pasos, navegación atrás sin pérdida, autoguardado de borrador |
| `TemplateGallery` | Galería de plantillas por objetivo, con plantillas de temporada y búsqueda |
| `ProductCategoryPicker` | Buscador asíncrono de productos/categorías con miniaturas, chips, paginación y debounce (reutilizable también en Reglas avanzadas) |
| `ConditionRow` | Fila dinámica campo+operador+valor, agrupable AND/OR, para scope avanzado |
| `TieredTableEditor` | Editor de tramos con vista previa en vivo |
| `BogoBuyGetPicker` / `BundleBuilder` / `GiftPicker` | Selectores de producto real para 2x1/3x2/segunda unidad/bundle/regalo |
| `CodeInput` | Generador de código + verificación de unicidad en vivo (promos + `WC_Coupon` de cualquier origen) |
| `InlineField` | Input con label programático, validación en blur, error con `aria-describedby`/`aria-invalid` |
| `LivePreviewPanel` / `SummaryCard` | Recalcula precio antes/después sobre producto real, badge, mini-carrito y resumen en lenguaje natural |
| `ConflictChecker` | Semáforo pre-publicación |
| `SandboxMode` | Activación de la promo solo para el admin vía cookie firmada |
| `MaterialIcon` | Renderiza `<span class="material-symbols-rounded">` con ejes `FILL`/`wght`/`GRAD`/`opsz` vía `font-variation-settings` |
| `PromoToolbar` / `PromoCardMenu` | Búsqueda/orden/filtros del listado; Duplicar/Guardar como plantilla/Archivar con undo |
| `PromoStatsPanel` | Analítica real por promoción (canjes, descuento acumulado, ingresos asistidos, sparkline 30 días) |
| `NaturalLanguageSummary` | Traduce la configuración a una frase legible (usada en Paso 3 y en el detalle de cada promo) |

**Accesibilidad transversal (WCAG 2.2 AA), aplicada a todo el flujo, no solo mencionada:** targets interactivos mínimos 24×24px, foco nunca oculto por cabeceras sticky del modal (Focus Not Obscured 2.4.11), alternativa por botones a cualquier reordenamiento por arrastre (Dragging Movements 2.5.7), validación en `blur` con foco al primer error, `aria-live`/`role="alert"` solo para errores de envío (no en cada tecla). Auditoría manual con teclado y lector de pantalla (NVDA/VoiceOver) sobre el wizard completo, no solo axe-core (que detecta ~30% de los problemas reales).

---

## Sistema de iconografía Material Symbols

Implementación: Material Symbols **Rounded** (mejor encaje visual con el admin nativo de WordPress que Sharp/Outlined), fuente variable **auto-hospedada** en `assets/fonts/` (subset con solo los íconos usados, sin CDN externo — cumple directrices de WordPress.org y funciona offline). Ejes: `FILL` 0→1 para animar estado inactivo/activo (reemplaza cambiar de glifo), `wght` 400 por defecto, `GRAD` -25 sobre fondos de color (badges de estado), `opsz` 20-24 en tablas y menús, 48 en estados vacíos.

| Tipo de promoción | Ícono Material Symbol (Rounded) |
|---|---|
| Descuento porcentual | `percent` |
| Descuento fijo | `attach_money` |
| Precio de lanzamiento | `rocket_launch` |
| 2x1 | `counter_2` |
| 3x2 | `counter_3` |
| Segunda unidad con descuento | `stacks` |
| Escalonado por monto/cantidad | `stairs` |
| Bundle / combo | `package_2` |
| Envío gratis con umbral | `sports_score` |
| Envío gratis con cupón | `local_shipping` |
| Cupón de bienvenida | `waving_hand` |
| Regalo por compra | `card_giftcard` |
| Puntos / cashback | `loyalty` |
| Oferta flash con contador | `bolt` |
| Descuento por captura de datos | `alternate_email` |
| **Nuevo** — Cupón por URL/QR | `qr_code_2` |
| **Nuevo** — Crédito de tienda | `account_balance_wallet` |
| **Nuevo** — BOGO cruzado (compra X, lleva Y distinto) | `add_shopping_cart` |
| **Nuevo** — Cumpleaños/aniversario | `cake` |
| **Nuevo** — Barra de progreso hacia recompensa | `timeline` |
| **Nuevo** — Recuperación de carrito abandonado | `remove_shopping_cart` |
| **Nuevo** — Precio por rol / mayorista (VIP) | `workspace_premium` |
| **Nuevo** — Códigos únicos en lote | `confirmation_number` |
| **Nuevo** — Liquidación de temporada | `sell` |
| **Nuevo** — Referidos | `diversity_3` |
| **Nuevo** — Ruleta gamificada (spin-the-wheel) | `casino` |
| — Módulo "Promociones" en el menú admin | `featured_seasonal_and_gifts` |
| — Estado: programada | `schedule` |
| — Estado: borrador | `draft` |
| — Acción: duplicar | `content_copy` |
| — Acción: archivar | `archive` |
| — Acción: buscar | `search` |
| — Alerta: conflicto | `warning` |
| — Analítica/estadísticas | `monitoring` |

*Nota de corrección editorial:* se descarta el nombre `delivery_truck_speed` propuesto en uno de los borradores por no existir en el catálogo oficial de Material Symbols; se unifica en `local_shipping` para todo lo relacionado con envío. Se resuelve también el choque entre `money_off` y `attach_money` para "descuento fijo": se reserva `attach_money` para el tipo de promoción (dinero de descuento) y `money_off`/`sell` quedan disponibles para conceptos de "liquidación"/"rebaja" en el roadmap, evitando ambigüedad visual entre dos tipos distintos con el mismo glifo.

---

## Plantillas prediseñadas recomendadas

| Nombre de plantilla | Tipo base | Configuración precargada sugerida |
|---|---|---|
| Black Friday -20% toda la tienda | Descuento porcentual | 20%, scope "Toda la tienda", fechas del último fin de semana de noviembre, sin código, badge "BLACK FRIDAY" |
| 3x2 en categoría | Segunda/tercera unidad (bulk) | Compra 3, paga 2, scope "Categorías específicas" (vacío, a elegir), sin límite de usos |
| Envío gratis desde $50 | Envío gratis con umbral | Umbral = moneda base equivalente a $50 USD, mensaje de carrito "¡Te faltan {monto} para envío gratis!", barra de progreso activada |
| Cupón de bienvenida 10% | Descuento porcentual con código | 10%, `needsCode=true`, condición "primera compra" (PurchaseHistory), 1 uso por cliente, código autogenerado "BIENVENIDO10" |
| Oferta flash 24h | Oferta flash con contador | Valor a elegir, ventana de 24h desde publicación, contador visible en frontend, badge "FLASH" |
| Regalo por compra +$80 | Regalo por compra (BOGO distinto) | Compra mínima = equivalente a $80, producto de regalo a elegir, 1 por cliente |
| Escalonado por cantidad | Escalonado (bulk) | Tabla precargada: 3-5 uds → 10%, 6+ uds → 15% |
| Combo ahorra 15% | Bundle/combo | 2+ productos a elegir, precio de set = -15% sobre la suma individual |
| Liquidación de temporada | Descuento porcentual | 30-50%, scope "Categorías específicas", badge "LIQUIDACIÓN", sin fecha de fin fija |
| VIP mayorista -12% | Precio por rol (nuevo tipo, roadmap) | Rol "wholesale_customer", 12%, sin código, sin límite |

---

## Roadmap de implementación por fases

### Quick wins (1-2 semanas)

- Unificar el catálogo de tipos: enriquecer `PromosController::type_definitions()` → `PromoTypeRegistry` (grupo, ícono Material Symbol, campos requeridos, defaults); **eliminar** `PROMO_TYPES` de `admin-promos.js`; inyectar vía `wp_localize_script` en `AdminController.php`.
- Endurecer `validate_promo()`: unicidad de código (contra promos y `shop_coupon`), `needsCode` obligatorio, `end >= start`, errores estructurados por campo.
- Crear `wp_drw_promos` en `Database.php::create_tables()` (dbDelta, índices, `UNIQUE(code)`) + `src/Models/PromoModel.php`.
- Migración automática desde `wp_options('drw_promos')` con backup legacy y verificación de conteo/checksum (ver criterios arriba).
- Reescribir `PromosController` para operar por fila vía `PromoModel` (fin de la condición de carrera), manteniendo el contrato REST `drw/v1/promos` intacto.
- Soporte multisitio en la creación de tablas (activación de red + `wpmu_new_blog`).

### Mediano plazo (4-7 semanas)

- **Semanas 1-3 — Puente al motor:** crear `src/Controllers/PromoBridgeController.php` (Vía A: sincronización `WC_Coupon`; Vía B: compilación a `wp_drw_rules` con columnas `source`/`promo_id`); reemplazar scope de texto por `{target, ids}` estructurado + endpoint de búsqueda paginado de productos/categorías; tracking real de canjes (hooks HPOS-safe, `increment_usage`, `wp_drw_order_discounts`); snapshot cacheado de promos activas + bail-out temprano; candado "Editar en OmniDiscount" en Marketing > Cupones; tests de integración por tipo (carrito real con descuento verificable).
- **Semanas 4-7 — Wizard, plantillas e iconografía:** auto-hospedar Material Symbols Rounded + componente `MaterialIcon`; reemplazar todos los dashicons; construir `TemplateGallery`, `PromoWizard`/`StepperNav`, `ProductCategoryPicker`, `TieredTableEditor`, `BundleBuilder`/`GiftPicker`, `CodeInput`; listado con búsqueda/orden/Duplicar/Archivar con undo; auditoría de accesibilidad WCAG 2.2 AA manual (teclado + NVDA/VoiceOver).

### Largo plazo (8-16 semanas, iterativo)

- **Confianza (semanas 8-10):** `LivePreviewPanel`/`SummaryCard` con datos reales vía endpoint *dry-run*; `NaturalLanguageSummary`; `ConflictChecker` pre-publicación; `SandboxMode` ("Probar en mi tienda"); `PromoStatsPanel` (canjes, ingresos asistidos, sparkline); tarjeta de "Salud de promociones" (zombis, expiradas visibles, códigos sin uso).
- **Universalidad verificable (semanas 11-12):** extender `StoreApiController.php` para Cart/Checkout Blocks (badge, mensaje, barra de progreso); suite E2E (checkout clásico + Blocks, HPOS on/off); tests de coexistencia con Smart/Advanced Coupons y con Aelia/WOOCS/WPML; benchmark WP-CLI publicado; pantalla de diagnóstico de plugins de descuento activos.
- **Diferenciación continua (semanas 13-16+):** tipos nuevos del catálogo (`url_coupon`, `bulk_codes`, `cart_goal`/barra de progreso, `store_credit`, `birthday`, `role_pricing`, `winback`, `clearance`); parser local de lenguaje natural ("Describe tu promo") sin dependencia de IA de pago; ruleta gamificada (`spin_wheel`) que emite `WC_Coupon` espejo real; calendario de campañas estacionales con recordatorios; import/export de promociones + CLI (`wp drw promo create`) para agencias; crédito de tienda como `Adjustment` nuevo y referidos (según tracción).

---

## Diferenciadores frente al mercado

1. **Vista previa WYSIWYG con datos reales de la tienda dentro del admin** — ningún competidor evaluado (Flycart, YITH, Advanced Coupons, YayPricing) muestra el precio antes/después sobre un producto real del catálogo mientras se edita, recalculado por el **mismo** motor que corre en producción.
2. **Modo Simulador ("Probar en mi tienda")** — activa la promo solo para el admin vía cookie firmada; convierte la prueba en parte del flujo, eliminando la queja #1 del mercado ("la regla no funciona").
3. **Doble materialización transparente** — cada promo con código ES un `WC_Coupon` real (interopera con emails, URLs, reportes nativos, otros plugins); cada promo automática ES una fila probada del motor de Reglas. Nadie en la competencia analizada ofrece este puente honesto entre "panel de marketing" y "motor de precios".
4. **Detector de conflictos pre-publicación** — previene exactamente la clase de bugs documentados en Advanced Coupons (colisión de múltiples BOGO auto-aplicados) y en Flycart (bundles cuyo total no refleja el descuento).
5. **"Describe tu promo" en lenguaje natural → reglas reales**, sin coste ni IA de pago (vs. StoreAgent de Advanced Coupons, atado a su editor y a su suscripción).
6. **Compatibilidad con Cart/Checkout Blocks como argumento de venta verificable** — mientras Advanced Dynamic Pricing tiene un issue público abierto en GitHub y Flycart lo etiqueta "en desarrollo" en su v3.
7. **Benchmark de rendimiento publicado y reproducible (WP-CLI)** — ningún competidor evaluado publica cifras propias, y la queja de rendimiento es la más citada del nicho.
8. **Multi-moneda correcto de fábrica**, probado explícitamente contra Aelia/WOOCS/WPML (vs. el bug documentado de YITH, "solo funciona en USD").
9. **Todo incluido, gratis, GPLv3, sin fragmentación en add-ons de pago** — respuesta directa a la crítica más repetida contra Advanced Coupons (Coupons/Loyalty/Gift Cards/Promo Kit separados) y a la incertidumbre de marca dejada por la consolidación de IconicWP en Liquid Web.
10. **Modo dual wizard/experto** — el 90% de los casos vía wizard guiado; power users acceden sin fricción al motor de Reglas avanzado (grupos AND/OR, prioridad, exclusividad) ya existente, sin duplicar motor.
11. **Accesibilidad WCAG 2.2 AA real y auditada manualmente** — ningún competidor analizado la declara.
12. **Gamificación nativa en el mismo motor** (barra de progreso hacia envío gratis/regalo, ruleta con captura de email) — sin necesidad de un plugin/add-on aparte como exige la competencia adyacente (FunnelKit, WPLoyalty).

---

## Métricas de éxito

- **Verdad funcional:** 100% de las promociones activas producen un descuento verificable en `WC_Cart` (test automatizado por tipo, gate de release en CI). Línea base actual: 0%.
- **Tiempo hasta la primera promoción publicada (TTFP):** < 90 segundos usando plantilla (benchmark a batir: ~3 min de Disco/Flycart).
- **Tasa de finalización del wizard:** > 80% (abandono < 20%); > 60% de promociones creadas desde plantilla.
- **Cero pérdidas de datos por concurrencia:** 0 incidencias reproducibles tras migrar a `wp_drw_promos` (prueba de escrituras paralelas en CI).
- **Rendimiento:** overhead p95 de `woocommerce_before_calculate_totals` < 30ms con 50 promos activas y 500 productos; overhead < 1ms cuando no hay promos automáticas activas (bail-out); cifra publicada en README.
- **Exactitud de analítica:** el contador de canjes coincide al 100% con pedidos reales (conciliación `wp_drw_order_discounts` vs. WooCommerce Analytics > Coupons). Línea base actual: el contador nunca sube.
- **Cobertura de mapeo:** 15/15 tipos actuales ejecutables por el motor real + ≥10 tipos nuevos, todos con "recipe" declarativo probado.
- **Fuente de verdad única:** 0 divergencias entre catálogo PHP y JS (el array JS deja de existir), verificado en CI.
- **Compatibilidad:** verde en matriz HPOS on/off, multisitio, checkout clásico y Cart/Checkout Blocks, y con Advanced/Smart Coupons coexistiendo sin colisión de IDs.
- **Accesibilidad:** 0 errores críticos en axe-core + auditoría manual de teclado/lector de pantalla aprobada (WCAG 2.2 AA).
- **Migración de datos:** 100% de las promociones legacy migradas con conteo/checksum verificado; 0 pérdidas reportadas.
- **Soporte/confianza:** reducción > 50% de tickets tipo "la promoción no aplica" tras el lanzamiento (línea base: defecto silencioso afecta al 100% hoy).
- **Negocio (tras fase de gamificación):** incremento medible de AOV con barra de progreso hacia envío gratis (objetivo +8-12%); tasa de captura de email por ruleta > 8% de sesiones que la ven; uso de "Duplicar" > 0% de cuentas activas al mes 3 (hoy imposible, la función no existe).