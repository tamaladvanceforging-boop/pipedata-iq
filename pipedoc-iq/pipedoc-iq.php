<?php
/**
 * Plugin Name:       PipeDoc IQ
 * Description:       Industrial document & report automation system — engineering database, heat traceability, template studio, document generation, Gemini-assisted workflows.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Tamal Roy Chowdhury
 * License:           GPL-2.0-or-later
 * Text Domain:       pipedoc-iq
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'PDQ_VERSION', '1.0.0' );
define( 'PDQ_FILE', __FILE__ );
define( 'PDQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'PDQ_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( PDQ_DIR . 'vendor/autoload.php' ) ) { require_once PDQ_DIR . 'vendor/autoload.php'; }
foreach ( array( 'registry', 'meta', 'activity', 'db', 'calc', 'parser', 'crypto', 'gemini', 'import', 'files', 'generator', 'demo', 'dashboard', 'installer', 'rest', 'app' ) as $pdq_f ) {
	require_once PDQ_DIR . "includes/class-pdq-$pdq_f.php";
}

register_activation_hook( __FILE__, array( 'PDQ_Installer', 'activate' ) );
add_action( 'plugins_loaded', array( 'PDQ_Installer', 'maybe_migrate' ) );
PDQ_REST::init();
PDQ_App::init();
