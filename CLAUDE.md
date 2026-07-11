# CLAUDE.md — discount-rules-woo (OmniDiscount)

Instrucciones obligatorias para cualquier agente (principal o subagente) que trabaje en este repositorio.

## 1. Skills obligatorias — no opcionales

Ya están instaladas en `.claude/skills/`. Esto exige su **invocación**, no su instalación.

### Cambios de UI/CSS/JS visual (SIEMPRE, antes de tocar cualquier `.css`/`.js` de admin o frontend)

Invocar en este orden antes de escribir código:

1. `web-design-guidelines` — auditar contra Web Interface Guidelines.
2. `frontend-design` — dirección estética, tipografía, decisiones no genéricas.
3. `design-taste-frontend` / `design-an-interface` — criterio de diseño, explorar variantes cuando la decisión no sea obvia.
4. `emil-design-eng` — pulido de componentes, animaciones y microinteracciones.
5. `review-animations` — si el cambio incluye transiciones/animaciones.
6. `impeccable` — corre automáticamente post-Edit/Write vía hook. Sus hallazgos son bloqueantes: no cerrar una tarea de UI con hallazgos "impeccable" sin resolver o justificar explícitamente por qué se descartan.

### Cambios de backend PHP/WooCommerce (SIEMPRE)

1. `woocommerce-backend-dev` — obligatoria antes de escribir PHP nuevo, modificar clases/hooks existentes, o escribir cualquier test PHP. **Nota de esta sesión:** esta skill está redactada para el *core* de WooCommerce (namespace `Automattic\WooCommerce`, `WC_Unit_Test_Case`, PHPUnit). Este plugin es una extensión de terceros con sus propias convenciones (namespace `Drw\App`, tests standalone en `tests/*.php` sin framework). Aplica el *criterio* de la skill (WPCS, seguridad, integridad de datos, documentación) pero sigue las convenciones YA establecidas del repo, no las plantillas literales de esa skill cuando entren en conflicto.
2. `woocommerce-code-review` — antes de dar por cerrado cualquier cambio backend.
3. `woocommerce-copy-guidelines` — para cualquier string visible al comerciante o al cliente final (labels, notices, emails, meta de línea de pedido).
4. `woocommerce-dev-cycle` — para features que abarcan varias fases (implementación → test → review).
5. `woocommerce` (skill genérica) — referencia transversal cuando ninguna de las anteriores cubre el caso puntual.

**Regla dura:** ningún cambio que toque `src/Controllers/*.php`, `src/Models/*.php`, `src/Conditions/*.php` o `src/Adjustments/*.php` se considera completo sin haber pasado por `woocommerce-backend-dev` (autoría) y `woocommerce-code-review` (revisión) en la misma sesión.

## 2. Patrón de "ronda de test" — 10 rondas de auditoría-reparación vía Workflow

Para cualquier feature no trivial (más de ~1 archivo, o con superficie visual/funcional significativa):

1. Usar el Workflow tool (o `TaskCreate`/`TaskUpdate` si no aplica un Workflow completo) para trackear el trabajo como tareas discretas, no como una sola tarea monolítica. Cada tarea queda `completed` solo con evidencia verificable (test corrido, `php -l` limpio, captura de navegador real), nunca autodeclarada.
2. Al terminar la implementación de una fase, ejecutar **10 rondas** de auditoría-reparación:
   - Cada ronda = 1 pase de revisión (código + visual si aplica) que produce hallazgos, seguido de un pase de reparación de esos hallazgos.
   - Las rondas se detienen antes de la 10 solo si **dos rondas consecutivas** no producen hallazgos nuevos (criterio de convergencia) — nunca antes, y nunca se fuerzan hallazgos artificiales solo para "llenar" una ronda.
   - Los hallazgos de tipo visual (CSS/layout) requieren verificación en navegador real — nunca se cierran solo por lectura de código. Este proyecto tiene un sitio Local (WordPress + WooCommerce reales) ya preparado para esto; úsalo antes de dar cualquier cambio visual por cerrado.
3. Distinguir explícitamente, en el informe final: qué se verificó por ejecución real vs. qué quedó solo revisado estáticamente por falta de entorno — nunca reclamar éxito de ejecución sin haberla hecho.

## 3. Enrutamiento de modelo por tipo de tarea

| Tipo de tarea | Modelo | Ejemplos en este repo |
|---|---|---|
| Backend crítico / seguridad / integridad de datos | **Opus** | `RulesEngine.php`, `PromoBridgeController.php`, `CartController.php`, cambios de esquema (`Database.php`), cualquier cosa que toque `$wpdb`/SQL directo, validación/sanitización, lógica de precios y descuentos |
| UI / componentes rutinarios | **Sonnet** | Componentes React (`wp.element`) de `admin-app.js`/`drw-*.js`, CSS, wiring de props, refactors mecánicos de i18n, revisiones de regresión |
| Copywriting / microcopy / texto de UX | **Fable** | Labels visibles al comerciante o cliente, mensajes de error, texto de notices/emails, `NaturalLanguageSummary`, nombres de plantillas, cualquier string que pase por `woocommerce-copy-guidelines` |

Regla de desempate: si una tarea mezcla backend crítico + copy (p. ej. un endpoint nuevo con mensajes de error visibles), usar Opus para la lógica y aplicar criterio de Fable al copy final antes de cerrar.

## 4. Nomenclatura y branding

- El nombre visible del plugin es siempre **"OmniDiscount"** — nunca "Discount Rules for WooCommerce" a secas ni "Dynamic Pricing & Discount Rules for WooCommerce" sin el prefijo "OmniDiscount —". Verificar cualquier string nuevo de UI contra este estándar antes de cerrar una tarea.
- Nombres técnicos internos (slug de carpeta `discount-rules-woo`, textdomain, namespace `Drw\App`, prefijo de BD `drw_`) **no cambian** — son infraestructura, no marca.
- Cualquier string que pueda llegar a ser visible al **cliente final** (no solo al comerciante en el admin) — meta de línea de pedido, emails, notices de carrito/checkout — se trata como copy de marca de alto riesgo y pasa obligatoriamente por `woocommerce-copy-guidelines`.

## 5. Contratos que no se rompen sin decisión explícita del usuario

- Ningún endpoint REST existente (`drw/v1/*`) cambia su path o el shape de su request/response en el camino feliz. Cambios son aditivos (campos nuevos opcionales, códigos de estado nuevos siguiendo el patrón `{message, code, field}` de `validation_error_response()`).
- La lógica de cálculo de `Adjustments/*`/`Conditions/*`/`RulesEngine.php` no se toca salvo aprobación explícita — es el motor que sostiene descuentos reales en producción.
- Antes de cualquier cambio de esquema de base de datos o migración de datos reales: avisar y esperar confirmación explícita.
