<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Audit trail writer. */
class PDQ_Activity {
	public static function log( $action, $entity = '', $entity_id = 0, $summary = '', $detail = null ) {
		global $wpdb;
		$wpdb->insert( PDQ_Registry::table( 'activity_logs' ), array(
			'workspace_id' => PDQ_DB::workspace_id(), 'owner_id' => PDQ_DB::owner_id(), 'user_id' => get_current_user_id(),
			'action' => substr( sanitize_key( $action ), 0, 60 ), 'entity' => substr( sanitize_key( $entity ), 0, 60 ), 'entity_id' => (int) $entity_id,
			'summary' => substr( sanitize_text_field( $summary ), 0, 190 ), 'detail' => $detail ? wp_json_encode( $detail ) : null,
			'ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 45 ) : '',
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		) );
	}
}
