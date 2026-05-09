<?php
/**
 * Plugin Name: Duck Kint Debugger
 * Plugin URI: https://strongplugins.com/
 * Description: Dump variables and traces in an organized and interactive display. Works with Debug Bar.
 * Version: 2.0.2
 * Author: Brian Fegter, Chris Dillon
 * Author URI: https://strongplugins.com
 * GitHub Plugin URI: https://github.com/DuckDivers/kint-debugger
 * Requires: 5.0
 * License: Dual license GPL-2.0+ & MIT (Kint is licensed MIT)
 *
 * Copyright 2012-2019 Brian Fegter (brian@fegter.com), Chris Wallace (chris@liftux.com), Chris Dillon (chris@strongwp.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Load Kint after this plugin to ensure our modified d() function override.
 *
 * @since 1.1
 */
function kint_debug_load_kint() {
	require 'vendor/kint/Kint.class.php';
}
add_action( 'plugins_loaded', 'kint_debug_load_kint' );

/**
 * Enqueue Kint assets when the output is shown inside Debug Bar or Query Monitor.
 *
 * Query Monitor renders Debug Bar panels through its own interface. In newer
 * versions, scripts embedded in the captured panel output are not guaranteed to
 * execute, so Kint's expander controls need to be loaded as normal assets.
 *
 * @since 2.0.2
 */
function kint_debug_enqueue_assets() {
	if ( ! class_exists( 'Kint' ) ) {
		return;
	}

	$base_path = plugin_dir_path( __FILE__ ) . 'vendor/kint/view/compiled/';
	$base_url  = plugin_dir_url( __FILE__ ) . 'vendor/kint/view/compiled/';
	$theme     = Kint::$theme;
	$css_file  = $base_path . $theme . '.css';

	if ( ! is_readable( $css_file ) ) {
		$theme    = 'original';
		$css_file = $base_path . 'original.css';
	}

	wp_enqueue_style(
		'kint-debugger-kint',
		$base_url . $theme . '.css',
		array(),
		filemtime( $css_file )
	);

	wp_enqueue_script(
		'kint-debugger-kint',
		$base_url . 'kint.js',
		array(),
		filemtime( $base_path . 'kint.js' ),
		true
	);

	wp_add_inline_script( 'kint-debugger-kint', kint_debug_toggle_bridge_script(), 'after' );
}
add_action( 'debug_bar_enqueue_scripts', 'kint_debug_enqueue_assets' );

/**
 * Add a capture-phase toggle bridge for Kint dumps inside Query Monitor.
 *
 * Kint's bundled script listens on window during the bubble phase. Query
 * Monitor's current panel UI can intercept clicks before they reach it.
 *
 * @return string
 */
function kint_debug_toggle_bridge_script() {
	return <<<'JS'
(function () {
	if (window.kintDebuggerToggleBridge) {
		return;
	}

	window.kintDebuggerToggleBridge = true;

	function closest(element, selector) {
		while (element && element.nodeType === 1) {
			if (element.matches && element.matches(selector)) {
				return element;
			}
			element = element.parentElement;
		}

		return null;
	}

	function nextDefinition(element) {
		element = element.nextElementSibling;

		while (element && element.nodeName.toLowerCase() !== 'dd') {
			element = element.nextElementSibling;
		}

		return element;
	}

	function toggle(element, hide) {
		var definition = nextDefinition(element);

		if (!definition) {
			return;
		}

		if (typeof hide === 'undefined') {
			hide = element.classList.contains('kint-show');
		}

		element.classList.toggle('kint-show', !hide);
	}

	function toggleChildren(element) {
		var definition = nextDefinition(element);
		var hide = element.classList.contains('kint-show');
		var children;
		var i;

		if (!definition) {
			return;
		}

		children = definition.getElementsByClassName('kint-parent');

		for (i = children.length - 1; i >= 0; i--) {
			toggle(children[i], hide);
		}

		toggle(element, hide);
	}

	document.addEventListener('click', function (event) {
		var target = event.target;
		var parent;
		var footer;

		if (!closest(target, '.kint')) {
			return;
		}

		if (target.nodeName && target.nodeName.toLowerCase() === 'nav') {
			footer = closest(target, 'footer');

			if (footer && closest(footer, '.kint')) {
				footer.classList.toggle('kint-show');
			} else {
				parent = closest(target, 'dt.kint-parent');
				if (parent) {
					toggleChildren(parent);
				}
			}

			event.preventDefault();
			event.stopPropagation();
			if (event.stopImmediatePropagation) {
				event.stopImmediatePropagation();
			}
			return;
		}

		parent = closest(target, 'dt.kint-parent');
		if (parent && closest(parent, '.kint')) {
			toggle(parent);

			event.preventDefault();
			event.stopPropagation();
			if (event.stopImmediatePropagation) {
				event.stopImmediatePropagation();
			}
		}
	}, true);
}());
JS;
}

/**
 * Preserve the class name Kint expects when cloning assets into popup windows.
 *
 * @param string $tag    Script tag.
 * @param string $handle Script handle.
 *
 * @return string
 */
function kint_debug_script_loader_tag( $tag, $handle ) {
	if ( 'kint-debugger-kint' !== $handle ) {
		return $tag;
	}

	return str_replace( '<script ', '<script class="-kint-js" ', $tag );
}
add_filter( 'script_loader_tag', 'kint_debug_script_loader_tag', 10, 2 );

/**
 * Print the toggle bridge directly as a fallback for Query Monitor's footer UI.
 *
 * @return void
 */
function kint_debug_print_toggle_bridge_script() {
	global $kint_debug;

	if ( empty( $kint_debug ) || ! class_exists( 'Debug_Bar' ) ) {
		return;
	}

	if ( function_exists( 'wp_print_inline_script_tag' ) ) {
		wp_print_inline_script_tag(
			kint_debug_toggle_bridge_script(),
			array( 'id' => 'kint-debugger-toggle-bridge' )
		);
		return;
	}

	echo '<script id="kint-debugger-toggle-bridge">' . kint_debug_toggle_bridge_script() . '</script>';
}
add_action( 'wp_footer', 'kint_debug_print_toggle_bridge_script', 9999 );
add_action( 'admin_footer', 'kint_debug_print_toggle_bridge_script', 9999 );

/**
 * Generic data dump.
 *
 * @since 1.1
 */
if ( ! function_exists( 'dump_this' ) ) {
	function dump_this( $var, $inline = false ) {
		/**
		 * Some hooks send WP objects which then get passed as $inline
		 * so check type too.
		 */
		if ( true === $inline ) {
			$_ = array( $var );
			echo call_user_func_array( array( 'Kint', 'dump' ), $_ );
		}
		else {
			d( $var );
		}
	}
}

/**
 * Helper functions.
 */

if ( ! function_exists( 'dump_wp_query' ) ) {
	function dump_wp_query( $inline = false ) {
		global $wp_query;
		dump_this( $wp_query, $inline );
	}
}

if ( ! function_exists( 'dump_wp' ) ) {
	function dump_wp( $inline = false ) {
		global $wp;
		dump_this( $wp, $inline );
	}
}

if ( ! function_exists( 'dump_post' ) ) {
	function dump_post( $inline = false ) {
		global $post;
		dump_this( $post, $inline );
	}
}

/* Override can be prevented using config constant. */
if ( ! defined( 'KINT_TO_DEBUG_BAR' ) || KINT_TO_DEBUG_BAR ) {
	/* An mu-plugin can still override the function. */
	if ( ! function_exists( 'd' ) ) {
		/**
		 * Alias of Kint::dump()
		 *
		 * This sends Kint output to Debug Bar if active.
		 *
		 * Can be prevented by declaring the function first in an mu-plugin
		 *   (but not a theme due to WordPress load sequence).
		 *
		 * @return string
		 */
		function d() {
			/** @noinspection PhpUndefinedClassInspection */
			if ( ! Kint::enabled() ) {
				return '';
			}
			$_ = func_get_args();
			if ( class_exists( 'Debug_Bar' ) ) {
				ob_start( 'kint_debug_ob' );
				echo call_user_func_array( array( 'Kint', 'dump' ), $_ );
				ob_end_flush();
			} else {
				return call_user_func_array( array( 'Kint', 'dump' ), $_ );
			}

			return '';
		}
	}
}

/**
 * Output buffer callback.
 *
 * @param $buffer
 *
 * @return string
 */
function kint_debug_ob( $buffer ) {
	global $kint_debug;

	if ( class_exists( 'Debug_Bar' ) ) {
		$buffer = preg_replace(
			'#<script\b[^>]*class=(["\'])-kint-js\1[^>]*>.*?</script>\s*#is',
			'',
			$buffer
		);
		$buffer = kint_debug_add_inline_toggles( $buffer );
	}

	$kint_debug[] = $buffer;
	if ( class_exists( 'Debug_Bar' ) ) {
		return '';
	}

	return $buffer;
}

/**
 * Add self-contained toggle handlers to Kint's expander elements.
 *
 * Query Monitor can render Debug Bar panel markup without running embedded or
 * enqueued scripts. Inline handlers keep the legacy Kint markup interactive in
 * that environment.
 *
 * @param string $buffer Kint HTML.
 *
 * @return string
 */
function kint_debug_add_inline_toggles( $buffer ) {
	$toggle = "event.preventDefault();event.stopPropagation();var d=this.parentNode;if(d&&d.classList){d.classList.toggle('kint-show');}";

	$buffer = preg_replace(
		'#<dt class="kint-parent"><span class="kint-popup-trigger"([^>]*)>.*?</span><nav></nav>#',
		'<dt class="kint-parent"><span class="kint-popup-trigger"$1>&rarr;</span><nav onclick="' . esc_attr( $toggle ) . '"></nav>',
		$buffer
	);

	$buffer = preg_replace(
		'#<footer><span class="kint-popup-trigger"([^>]*)>.*?</span> <nav></nav>#',
		'<footer><span class="kint-popup-trigger"$1>&rarr;</span> <nav onclick="' . esc_attr( $toggle ) . '"></nav>',
		$buffer
	);

	return $buffer;
}

/**
 * Add our Debug Bar panel.
 *
 * @param $panels
 *
 * @return array
 */
function kint_debug_bar_panel( $panels ) {

	if ( ! class_exists( 'Kint_Debug_Bar_Panel' ) ) {
		require_once 'includes/class-kint-debug-bar-panel.php';
	}

	$panels[] = new Kint_Debug_Bar_Panel;

	return $panels;
}
add_filter( 'debug_bar_panels', 'kint_debug_bar_panel' );
