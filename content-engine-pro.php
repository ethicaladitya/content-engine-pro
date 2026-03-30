<?php
/**
 * Plugin Name:       Content Engine Pro
 * Plugin URI:        https://example.com/content-engine-pro
 * Description:       Settings-driven AI-powered content publication engine. Crawls sources, scores signals, generates AI content, manages affiliates and reviews — all configurable via WordPress Admin.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Content Engine Pro
 * License:           GPL-2.0+
 * Text Domain:       content-engine-pro
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'CEP_VERSION', '1.0.0' );
define( 'CEP_DB_VERSION', '1.1.0' );
define( 'CEP_FILE', __FILE__ );
define( 'CEP_DIR', plugin_dir_path( __FILE__ ) );
define( 'CEP_URL', plugin_dir_url( __FILE__ ) );
define( 'CEP_SLUG', 'content-engine-pro' );

// Autoloader
require_once CEP_DIR . 'includes/class-autoloader.php';
\ContentEnginePro\Autoloader::register();

// Helpers
require_once CEP_DIR . 'helpers/functions.php';

// Activation / Deactivation hooks
register_activation_hook( __FILE__, [ '\ContentEnginePro\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\ContentEnginePro\Deactivator', 'deactivate' ] );

// Boot the plugin
add_action( 'plugins_loaded', function () {
	\ContentEnginePro\Plugin::get_instance()->run();
} );
