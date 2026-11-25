<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Dashboard aggregates — every number is a live SQL query. */
class PDQ_Dashboard {
	private static function count( $entity, $extra = '', $params = array() ) {
		global $wpdb; $t = PDQ_Registry::table( $entity );
		$sql = "SELECT COUNT(*) FROM $t WHERE workspace_id = %d AND deleted_at IS NULL" . ( $extra ? " AND $extra" : '' );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( PDQ_DB::workspace_id() ), $params ) ) );
	}
	public static function data() {
		global $wpdb;
		$ws = PDQ_DB::workspace_id(); $docs = PDQ_Registry::table( 'documents' );
		$disp = PDQ_Meta::doc_types()['dispatch']; $ph = implode( ',', array_fill( 0, count( $disp ), '%s' ) );
		$kpi = array(
			'engineering_records' => self::count( 'engineering_dimensions' ),
			'documents' => self::count( 'documents' ),
			'test_certificates' => self::count( 'documents', '(doc_type LIKE %s OR doc_type LIKE %s)', array( '%Test Certificate%', '%Mill Test%' ) ),
			'dispatch_documents' => self::count( 'documents', "doc_type IN ($ph)", $disp ),
			'templates' => self::count( 'templates' ),
			'active_companies' => self::count( 'company_profiles' ),
			'pending_reviews' => self::count( 'documents', 'status = %s', array( 'pending_review' ) ),
			'approved_documents' => self::count( 'documents', 'status = %s', array( 'approved' ) ),
		);
		$monthly = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at,'%%Y-%%m') m, COUNT(*) c FROM $docs WHERE workspace_id = %d AND deleted_at IS NULL AND created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH) GROUP BY m ORDER BY m", $ws ), ARRAY_A );
		$bymonth = array_column( $monthly, 'c', 'm' ); $chart = array();
		for ( $i = 11; $i >= 0; $i-- ) { $k = gmdate( 'Y-m', strtotime( "first day of -$i month" ) ); $chart[] = array( 'month' => $k, 'count' => (int) ( $bymonth[ $k ] ?? 0 ) ); }
		$cat = $wpdb->get_results( $wpdb->prepare( "SELECT doc_type name, COUNT(*) value FROM $docs WHERE workspace_id = %d AND deleted_at IS NULL GROUP BY doc_type ORDER BY value DESC LIMIT 12", $ws ), ARRAY_A );
		$act = $wpdb->get_results( $wpdb->prepare( 'SELECT id, action, entity, entity_id, summary, created_at FROM ' . PDQ_Registry::table( 'activity_logs' ) . ' WHERE workspace_id = %d ORDER BY id DESC LIMIT 10', $ws ), ARRAY_A );
		$tpl = $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.name, t.doc_type, MAX(d.created_at) last_used, COUNT(d.id) uses FROM {$wpdb->prefix}pdq_documents d JOIN {$wpdb->prefix}pdq_templates t ON t.id = d.template_id WHERE d.workspace_id = %d AND d.deleted_at IS NULL GROUP BY t.id ORDER BY last_used DESC LIMIT 5", $ws ), ARRAY_A );
		return array( 'kpi' => $kpi, 'monthly' => $chart, 'by_type' => $cat, 'recent_activity' => $act, 'recent_templates' => $tpl, 'alerts' => self::alerts() );
	}

	/** Data validation alerts from real records. */
	public static function alerts() {
		global $wpdb; $out = array();
		$eng = PDQ_Registry::table( 'engineering_dimensions' ); $ws = PDQ_DB::workspace_id();
		$n = self::count( 'engineering_dimensions', "verification_status = 'unverified'" );
		if ( $n ) { $out[] = array( 'level' => 'warning', 'message' => "$n engineering record(s) are unverified.", 'link' => 'engineering_dimensions' ); }
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $eng WHERE workspace_id = %d AND deleted_at IS NULL AND (outside_diameter IS NULL AND flange_od IS NULL)", $ws ) );
		if ( $n ) { $out[] = array( 'level' => 'info', 'message' => "$n engineering record(s) have no outside diameter / flange OD.", 'link' => 'engineering_dimensions' ); }
		$d = count( PDQ_DB::find_duplicates() ); if ( $d ) { $out[] = array( 'level' => 'warning', 'message' => "$d group(s) of duplicate engineering records detected.", 'link' => 'engineering_dimensions' ); }
		$n = self::count( 'heat_numbers', "verification_status <> 'verified'" );
		if ( $n ) { $out[] = array( 'level' => 'warning', 'message' => "$n heat number(s) are not verified — certificates cannot be issued from them.", 'link' => 'heat_numbers' ); }
		$poi = PDQ_Registry::table( 'purchase_order_items' );
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $poi WHERE workspace_id = %d AND deleted_at IS NULL AND dispatched_qty > ordered_qty", $ws ) );
		if ( $n ) { $out[] = array( 'level' => 'error', 'message' => "$n PO item(s) have dispatched quantity above ordered quantity.", 'link' => 'purchase_orders' ); }
		return $out;
	}
}
