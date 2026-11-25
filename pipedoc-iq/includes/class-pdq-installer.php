<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Activation, schema creation and versioned migrations. */
class PDQ_Installer {
	const DB_VERSION = '1.0.0';

	public static function activate() {
		self::migrate();
		PDQ_Files::base_dir();
	}

	public static function maybe_migrate() {
		if ( get_option( 'pdq_db_version' ) !== self::DB_VERSION ) { self::migrate(); }
	}

	public static function migrate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		foreach ( PDQ_Registry::schema_sql( $wpdb->prefix, $charset ) as $sql ) { dbDelta( $sql ); }
		$from = get_option( 'pdq_db_version', '0' );
		// Versioned data migrations: add steps here as `if ( version_compare( $from, 'x.y.z', '<' ) ) { ... }`.
		do_action( 'pdq_migrate', $from, self::DB_VERSION );
		self::seed();
		update_option( 'pdq_db_version', self::DB_VERSION, false );
	}

	/** Seed structure only: workspace, product category names, settings. No engineering values. */
	private static function seed() {
		global $wpdb;
		$w = PDQ_Registry::table( 'workspaces' );
		if ( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM $w" ) ) {
			$wpdb->insert( $w, array( 'id' => 1, 'name' => 'Default Workspace', 'slug' => 'default', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		}
		$c = PDQ_Registry::table( 'product_categories' );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $c WHERE workspace_id = %d", 1 ) ) ) {
			foreach ( PDQ_Meta::categories() as $name ) {
				$family = preg_match( '/Pipe/i', $name ) ? 'pipe' : ( preg_match( '/Flange|Blind|Spade/i', $name ) ? 'flange' : 'fitting' );
				$wpdb->insert( $c, array( 'workspace_id' => 1, 'name' => $name, 'slug' => sanitize_title( $name ), 'family' => $family, 'is_active' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
			}
		}
	}
}
