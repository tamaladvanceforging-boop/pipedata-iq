<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Optional DEMO dataset so the whole workflow can be tried immediately.
 * Everything is prefixed "DEMO", created UNVERIFIED, and removable with one click.
 * Pipe OD/wall/weight values are published ASME B36.10M nominal figures; test results are FICTITIOUS demo values.
 */
class PDQ_Demo {
	const KEY = 'demo_ids';

	private static function stored() {
		global $wpdb; $t = PDQ_Registry::table( 'application_settings' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT id, setting_value FROM $t WHERE workspace_id = %d AND setting_key = %s", PDQ_DB::workspace_id(), self::KEY ), ARRAY_A );
		return $r ? array( (int) $r['id'], json_decode( $r['setting_value'], true ) ?: array() ) : array( 0, array() );
	}
	private static function save( $row_id, $ids ) {
		if ( $row_id ) { PDQ_DB::update( 'application_settings', $row_id, array( 'setting_value' => wp_json_encode( $ids ) ) ); }
		else { PDQ_DB::create( 'application_settings', array( 'setting_key' => self::KEY, 'setting_value' => wp_json_encode( $ids ) ) ); }
	}
	public static function status() { list( , $ids ) = self::stored(); return array( 'loaded' => ! empty( $ids ), 'counts' => array_map( 'count', $ids ) ); }

	public static function load() {
		list( $row, $ids ) = self::stored();
		if ( $ids ) { return new WP_Error( 'pdq_demo_exists', 'Demo data is already loaded. Remove it first to reload.', array( 'status' => 409 ) ); }
		$mk = function ( $entity, $data ) use ( &$ids ) { $r = PDQ_DB::create( $entity, $data ); if ( is_wp_error( $r ) ) { return $r; } $ids[ $entity ][] = (int) $r['id']; return $r; };
		$cust = $mk( 'customers', array( 'name' => 'DEMO Customer Pvt Ltd', 'code' => 'DEMO-C1', 'address' => 'DEMO Industrial Area, Kolkata, West Bengal', 'city' => 'Kolkata', 'state' => 'West Bengal' ) );
		if ( is_wp_error( $cust ) ) { return $cust; }
		$src = 'ASME B36.10M nominal (demo seed - verify before real use)';
		$pipes = array( array( '4', '100', '40', 114.3, 6.02, 16.07 ), array( '4', '100', '80', 114.3, 8.56, 22.32 ), array( '6', '150', '40', 168.3, 7.11, 28.26 ) );
		$dims = array();
		foreach ( $pipes as $p ) {
			$d = $mk( 'engineering_dimensions', array( 'item_code' => 'DEMO-SP-' . $p[0] . '-' . $p[2], 'category' => 'Seamless Pipes', 'item_type' => 'Seamless Pipe', 'item_description' => 'DEMO ' . $p[0] . '" NPS SCH ' . $p[2] . ' Seamless Pipe, 6 m length', 'material_type' => 'Carbon Steel', 'material_grade' => 'ASTM A106 Gr.B', 'manufacturing_standard' => 'ASTM A106', 'dimensional_standard' => 'ASME B36.10M', 'nps' => $p[0], 'nb' => $p[1], 'dn' => $p[1], 'size_inch' => $p[0], 'outside_diameter' => $p[3], 'wall_thickness' => $p[4], 'inside_diameter' => PDQ_Calc::inside_diameter( $p[3], $p[4] ), 'schedule' => $p[2], 'weight_per_meter' => $p[5], 'unit_weight' => round( $p[5] * 6, 3 ), 'unit' => 'mm', 'source_reference' => $src, 'verification_status' => 'unverified' ) );
			if ( is_wp_error( $d ) ) { return $d; } $dims[] = $d;
		}
		$po = $mk( 'purchase_orders', array( 'po_number' => 'DEMO-PO-001', 'po_type' => 'purchase', 'po_date' => gmdate( 'Y-m-d' ), 'customer_id' => $cust['id'], 'project' => 'DEMO Project', 'consignee' => 'DEMO Consignee, Howrah', 'delivery_location' => 'DEMO Site Store', 'inspection_requirements' => 'Visual & dimensional (demo)' ) );
		if ( is_wp_error( $po ) ) { return $po; }
		foreach ( array_slice( $dims, 0, 2 ) as $i => $d ) { $mk( 'purchase_order_items', array( 'po_id' => $po['id'], 'line_no' => $i + 1, 'material_code' => 'DEMO-M' . ( $i + 1 ), 'item_description' => $d['item_description'], 'material_grade' => 'ASTM A106 Gr.B', 'specification' => 'ASTM A106', 'ordered_qty' => ( $i + 1 ) * 50, 'uom' => 'Nos', 'unit_weight' => $d['unit_weight'], 'dimension_id' => $d['id'] ) ); }
		$heat = $mk( 'heat_numbers', array( 'heat_number' => 'DEMO-H-1001', 'batch_number' => 'DEMO-B-01', 'material_grade' => 'ASTM A106 Gr.B', 'specification' => 'ASTM A106', 'manufacturing_process' => 'Seamless', 'quantity' => 100, 'inspection_date' => gmdate( 'Y-m-d' ), 'po_id' => $po['id'], 'remarks' => 'DEMO ONLY - fictitious test values for trying the software. Never use for real certificates.', 'verification_status' => 'unverified' ) );
		if ( is_wp_error( $heat ) ) { return $heat; }
		// Limits = published ASTM A106 Gr.B chemistry/mechanical limits; ACTUAL values are fictitious demo numbers.
		foreach ( array( array( 'C', 0.22, null, 0.30 ), array( 'Mn', 0.85, 0.29, 1.06 ), array( 'P', 0.015, null, 0.035 ), array( 'S', 0.010, null, 0.035 ), array( 'Si', 0.25, 0.10, null ) ) as $c ) {
			$mk( 'chemical_tests', array( 'heat_id' => $heat['id'], 'element' => $c[0], 'actual_value' => $c[1], 'min_limit' => $c[2], 'max_limit' => $c[3], 'unit' => '%', 'standard_ref' => 'ASTM A106 Gr.B', 'result' => PDQ_Calc::check_limits( $c[1], $c[2], $c[3] )['result'] ) );
		}
		foreach ( array( array( 'Tensile Strength', 480, 415, null, 'MPa' ), array( 'Yield Strength', 310, 240, null, 'MPa' ) ) as $m ) {
			$mk( 'mechanical_tests', array( 'heat_id' => $heat['id'], 'property' => $m[0], 'actual_value' => $m[1], 'min_limit' => $m[2], 'max_limit' => $m[3], 'unit' => $m[4], 'test_method' => 'ASTM A370', 'result' => PDQ_Calc::check_limits( $m[1], $m[2], $m[3] )['result'] ) );
		}
		self::save( $row, $ids ); PDQ_Activity::log( 'demo_load', 'system', 0, 'Demo data loaded' );
		return self::status();
	}

	public static function remove() {
		global $wpdb; list( $row, $ids ) = self::stored();
		if ( ! $ids ) { return self::status(); }
		foreach ( array_reverse( array_keys( $ids ) ) as $entity ) { foreach ( $ids[ $entity ] as $id ) { $wpdb->delete( PDQ_Registry::table( $entity ), array( 'id' => (int) $id ), array( '%d' ) ); } }
		self::save( $row, array() ); PDQ_Activity::log( 'demo_remove', 'system', 0, 'Demo data removed' );
		return self::status();
	}
}
