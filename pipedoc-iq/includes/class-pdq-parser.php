<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Product description recognition ("100NB CL150 WNRF FLANGE" => structured filters). */
class PDQ_Parser {
	/** NB(mm) => NPS (inch) per ASME B36.10M / ISO 6708 nominal conversion. */
	public static $nb_to_nps = array( 6=>'1/8',8=>'1/4',10=>'3/8',15=>'1/2',20=>'3/4',25=>'1',32=>'1-1/4',40=>'1-1/2',50=>'2',65=>'2-1/2',80=>'3',90=>'3-1/2',100=>'4',125=>'5',150=>'6',200=>'8',250=>'10',300=>'12',350=>'14',400=>'16',450=>'18',500=>'20',550=>'22',600=>'24',650=>'26',700=>'28',750=>'30',800=>'32',850=>'34',900=>'36' );

	public static $abbr = array(
		'WNRF' => array( 'Weld Neck Flange', 'Raised Face' ), 'WNFF' => array( 'Weld Neck Flange', 'Flat Face' ), 'WNRTJ' => array( 'Weld Neck Flange', 'RTJ' ),
		'SORF' => array( 'Slip-On Flange', 'Raised Face' ), 'SOFF' => array( 'Slip-On Flange', 'Flat Face' ),
		'BLRF' => array( 'Blind Flange', 'Raised Face' ), 'BLFF' => array( 'Blind Flange', 'Flat Face' ),
		'SWRF' => array( 'Socket Weld Flange', 'Raised Face' ), 'THRF' => array( 'Threaded Flange', 'Raised Face' ), 'LJRF' => array( 'Lap Joint Flange', 'Raised Face' ),
	);
	public static $modifiers = array( 'LR' => 'Long Radius', 'SR' => 'Short Radius', 'BW' => 'Butt Weld', 'SW' => 'Socket Weld', 'THD' => 'Threaded', 'SCRD' => 'Threaded', 'CS' => 'Carbon Steel', 'SS' => 'Stainless Steel', 'AS' => 'Alloy Steel', 'STD' => 'STD', 'XS' => 'XS', 'XXS' => 'XXS' );
	public static $products = array(
		'weld neck' => 'Weld Neck Flange', 'weldneck' => 'Weld Neck Flange', 'slip on' => 'Slip-On Flange', 'slip-on' => 'Slip-On Flange', 'blind' => 'Blind Flange', 'socket weld flange' => 'Socket Weld Flange', 'lap joint' => 'Lap Joint Flange',
		'elbow' => 'Elbow', 'tee' => 'Tee', 'reducer' => 'Reducer', 'cap' => 'End Cap', 'coupling' => 'Coupling', 'stub end' => 'Stub End', 'olet' => 'Olet', 'nipple' => 'Nipple', 'union' => 'Union', 'bush' => 'Bushing', 'plug' => 'Plug', 'bend' => 'Return Bend', 'pipe' => 'Pipe', 'flange' => 'Flange',
	);

	public static function parse( $text ) {
		$raw = trim( (string) $text );
		$u = strtoupper( $raw );
		$out = array( 'raw' => $raw, 'product' => null, 'facing' => null, 'abbreviation' => null, 'nb' => null, 'nps' => null, 'pressure_class' => null, 'schedule' => null, 'modifiers' => array(), 'angle' => null, 'material_grade' => null, 'confidence' => 0 );
		// Abbreviation tokens (WNRF, SORF...).
		foreach ( self::$abbr as $ab => $info ) {
			if ( preg_match( '/\b' . preg_quote( $ab, '/' ) . '\b/', $u ) ) { $out['abbreviation'] = $ab; $out['product'] = $info[0]; $out['facing'] = $info[1]; break; }
		}
		// NB / DN / NPS.
		if ( preg_match( '/\b(\d{1,4})\s*(?:NB|DN|MM)\b/', $u, $m ) ) {
			$out['nb'] = (int) $m[1]; $out['nps'] = self::$nb_to_nps[ (int) $m[1] ] ?? null;
		} elseif ( preg_match( '/\bNPS\s*(\d+(?:-\d\/\d|\/\d)?(?:\.\d+)?)/', $u, $m ) ) {
			$out['nps'] = $m[1]; $nb = array_search( $m[1], self::$nb_to_nps, true ); $out['nb'] = false === $nb ? null : $nb;
		} elseif ( preg_match( '/\b(\d+(?:-\d\/\d|\/\d)?)\s*(?:"|IN\b|INCH)/', $u, $m ) ) {
			$out['nps'] = $m[1]; $nb = array_search( $m[1], self::$nb_to_nps, true ); $out['nb'] = false === $nb ? null : $nb;
		}
		// Pressure class.
		if ( preg_match( '/\b(?:CL(?:ASS)?|#)\s*[-:]?\s*(150|300|400|600|900|1500|2500|3000|6000|9000)\b/', $u, $m ) || preg_match( '/\b(150|300|600|900|1500|2500|3000|6000|9000)\s*(?:LBS?|#|CL(?:ASS)?)(?!\w)/', $u, $m ) ) { $out['pressure_class'] = $m[1]; }
		// Schedule.
		if ( preg_match( '/\bSCH(?:EDULE)?\.?\s*[-:]?\s*(\d{1,3}S?|STD|XS|XXS)\b/', $u, $m ) ) { $out['schedule'] = $m[1]; }
		elseif ( preg_match( '/\b(XXS|XS|STD)\b/', $u, $m ) ) { $out['schedule'] = $m[1]; }
		// Angle.
		if ( preg_match( '/\b(45|90|180)\s*(?:°|DEG(?:REE)?)/', $u, $m ) ) { $out['angle'] = (int) $m[1]; }
		// Modifiers.
		foreach ( array( 'LR', 'SR', 'BW', 'SW', 'THD', 'SCRD', 'CS', 'SS', 'AS' ) as $mod ) {
			if ( preg_match( '/\b' . $mod . '\b/', $u ) ) { $out['modifiers'][] = self::$modifiers[ $mod ]; }
		}
		// Product words, if not found via abbreviation.
		if ( ! $out['product'] ) {
			$l = strtolower( $raw );
			foreach ( self::$products as $kw => $name ) { if ( false !== strpos( $l, $kw ) ) { $out['product'] = $name; break; } }
		}
		if ( ! $out['facing'] ) {
			if ( preg_match( '/RAISED\s*FACE|\bRF\b/', $u ) ) { $out['facing'] = 'Raised Face'; }
			elseif ( preg_match( '/FLAT\s*FACE|\bFF\b/', $u ) ) { $out['facing'] = 'Flat Face'; }
			elseif ( preg_match( '/\bRTJ\b/', $u ) ) { $out['facing'] = 'RTJ'; }
		}
		// Material grade tokens (ASTM style).
		if ( preg_match( '/\b(A\s?105N?|A\s?106\s?(?:GR\.?\s?)?[ABC]|A\s?234\s?WP\s?[A-Z0-9]+|A\s?182\s?F\s?\d+[A-Z]?|A\s?350\s?LF\s?\d|A\s?333\s?(?:GR\.?\s?)?\d+|A\s?312\s?TP\s?\d{3}[A-Z]?|A\s?403\s?WP\s?\d{3}[A-Z]?|API\s?5L\s?(?:GR\.?\s?)?[A-Z0-9]+|IS\s?\d+)\b/', $u, $m ) ) { $out['material_grade'] = preg_replace( '/\s+/', ' ', $m[1] ); }
		$score = 0; foreach ( array( 'product', 'nps', 'pressure_class', 'facing' ) as $k ) { if ( $out[ $k ] ) { $score += 25; } }
		$out['confidence'] = $score;
		return $out;
	}

	/** Map parsed result to engineering_dimensions filters + LIKE terms. */
	public static function to_query( $p ) {
		$q = array( 'terms' => array(), 'filters' => array() );
		if ( $p['nps'] ) { $q['filters']['nps'] = $p['nps']; }
		if ( $p['pressure_class'] ) { $q['filters']['pressure_class'] = $p['pressure_class']; }
		if ( $p['schedule'] ) { $q['filters']['schedule'] = $p['schedule']; }
		if ( $p['product'] ) { $q['terms'][] = $p['product']; }
		if ( $p['facing'] ) { $q['terms'][] = $p['facing']; }
		return $q;
	}

	/**
	 * Search engineering DB from free text. Returns matches and whether user confirmation is required.
	 */
	public static function search_items( $text, $limit = 25 ) {
		global $wpdb;
		$p = self::parse( $text );
		$q = self::to_query( $p );
		$t = PDQ_Registry::table( 'engineering_dimensions' );
		$where = array( 'workspace_id = %d', 'deleted_at IS NULL' ); $params = array( PDQ_DB::workspace_id() );
		foreach ( $q['filters'] as $c => $v ) { $where[] = "$c = %s"; $params[] = $v; }
		foreach ( $q['terms'] as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$base = trim( preg_replace( '/\b(Flange|Face)\b/i', '', $term ) );
			$where[] = '(category LIKE %s OR item_type LIKE %s OR item_description LIKE %s)'; array_push( $params, $like, $like, $like );
		}
		if ( ! $q['filters'] && ! $q['terms'] ) { // Fallback to plain keyword search.
			$like = '%' . $wpdb->esc_like( (string) $text ) . '%';
			$where[] = '(item_code LIKE %s OR item_description LIKE %s OR category LIKE %s)'; array_push( $params, $like, $like, $like );
		}
		$params[] = (int) $limit;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $t . ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT %d', $params ), ARRAY_A );
		$variants = array();
		foreach ( array( 'material_grade', 'schedule', 'dimensional_standard', 'standard_revision' ) as $k ) { $variants[ $k ] = count( array_unique( array_filter( array_column( $rows, $k ) ) ) ); }
		$needs = count( $rows ) > 1 && max( $variants ) > 1;
		return array( 'parsed' => $p, 'matches' => $rows, 'needs_confirmation' => $needs, 'ambiguous_on' => array_keys( array_filter( $variants, function ( $n ) { return $n > 1; } ) ) );
	}
}
