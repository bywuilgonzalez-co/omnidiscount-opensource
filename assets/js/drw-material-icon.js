/* global wp */
/**
 * OmniDiscount — MaterialIcon component
 *
 * Reusable wp.element wrapper around Google's "Material Symbols Rounded"
 * variable icon font, self-hosted at assets/fonts/material-symbols-rounded.woff2
 * and loaded via assets/css/drw-material-icons.css (see that file's header for
 * font provenance notes).
 *
 * SCOPE: this file only defines the component. Nothing in the admin UI is
 * wired to it yet — admin-promos.js keeps using its dashicons-based `Icon()`
 * helper for now. A later phase will do the icon-by-icon swap and enqueue
 * drw-material-icons.css alongside this script.
 *
 * Usage (once enqueued with `wp-element` as a script dependency):
 *
 *   var el = wp.element.createElement;
 *   var MaterialIcon = window.DrwMaterialIcon;
 *
 *   el(MaterialIcon, { name: 'delete', size: 18 });
 *   el(MaterialIcon, { name: 'favorite', size: 20, fill: 1, weight: 500 });
 *
 * Renders:
 *
 *   <span class="material-symbols-rounded" aria-hidden="true"
 *         style="font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; font-size: 24px;">
 *     delete
 *   </span>
 *
 * @param {Object} props
 * @param {string} props.name        Material Symbols icon name/ligature, e.g. "delete".
 * @param {number} [props.size]      Glyph size in px. Also used as the default `opsz` when
 *                                    `opsz` is not given. Default 24.
 * @param {number} [props.fill]      Fill axis: 0 = outline, 1 = filled. Default 0.
 * @param {number} [props.weight]    Weight axis, 100-700. Default 400.
 * @param {number} [props.grade]     Grade axis (emphasis), -50 to 200. Default 0.
 * @param {number} [props.opsz]      Optical size axis, 20-48. Defaults to `size` (or 24).
 * @param {string} [props.className] Extra class name(s) appended after "material-symbols-rounded".
 * @param {Object} [props.style]     Extra inline styles merged on top of the computed
 *                                    font-variation-settings/font-size.
 * @return {Object|null} wp.element node, or null if wp.element is unavailable.
 */
(function () {
	'use strict';

	if (typeof wp === 'undefined' || !wp.element) {
		return;
	}

	var el = wp.element.createElement;

	function MaterialIcon(props) {
		props = props || {};

		var name = props.name || '';
		var size = props.size || 24;
		var fill = props.fill || 0;
		var weight = props.weight || 400;
		var grade = props.grade || 0;
		var opsz = props.opsz || size || 24;

		var style = Object.assign({
			fontVariationSettings: "'FILL' " + fill + ", 'wght' " + weight + ", 'GRAD' " + grade + ", 'opsz' " + opsz,
			fontSize: size + 'px'
		}, props.style || {});

		var className = 'material-symbols-rounded' + (props.className ? ' ' + props.className : '');

		return el('span', {
			className: className,
			style: style,
			// Icons are decorative by default (the visible label/aria-label lives on
			// the surrounding control); callers can override via props['aria-hidden'].
			'aria-hidden': props['aria-hidden'] !== undefined ? props['aria-hidden'] : true,
			title: props.title
		}, name);
	}

	window.DrwMaterialIcon = MaterialIcon;

})();
