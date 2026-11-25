<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** REST API: namespace pdq/v1. Cookie auth + X-WP-Nonce (CSRF) + capability check. */
class PDQ_REST {
	const NS = 'pdq/v1';

	public static function init() { add_action( 'rest_api_init', array( __CLASS__, 'routes' ) ); }

	/** Single permission gate; swap via filter for future RBAC. */
	public static function can( $request = null ) {
		$ok = current_user_can( 'manage_options' );
		$ok = apply_filters( 'pdq_user_can', $ok, $request );
		if ( ! $ok ) { return false; }
		return self::rate_limit( $request );
	}

	private static function rate_limit( $request ) {
		$ai = $request && 0 === strpos( (string) $request->get_route(), '/' . self::NS . '/ai/' );
		$limit = $ai ? 30 : 600; $key = 'pdq_rl_' . ( $ai ? 'ai_' : 'gen_' ) . get_current_user_id();
		$n = (int) get_transient( $key ); if ( $n >= $limit ) { return new WP_Error( 'pdq_rate', 'Rate limit exceeded. Try again in a minute.', array( 'status' => 429 ) ); }
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS ); return true;
	}

	public static function routes() {
		$p = array( __CLASS__, 'can' ); $ent = '(?P<entity>[a-z_]+)';
		$r = function ( $route, $methods, $cb, $extra = array() ) use ( $p ) {
			register_rest_route( self::NS, $route, array_merge( array( 'methods' => $methods, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $p ), $extra ) );
		};
		$r( '/bootstrap', 'GET', 'bootstrap' );
		$r( '/dashboard', 'GET', 'dashboard' );
		$r( "/e/$ent", 'GET', 'list_items' );
		$r( "/e/$ent", 'POST', 'create_item' );
		$r( "/e/$ent/(?P<id>\d+)", 'GET', 'get_item' );
		$r( "/e/$ent/(?P<id>\d+)", 'PUT,PATCH', 'update_item' );
		$r( "/e/$ent/(?P<id>\d+)", 'DELETE', 'delete_item' );
		$r( "/e/$ent/(?P<id>\d+)/restore", 'POST', 'restore_item' );
		$r( "/e/$ent/(?P<id>\d+)/purge", 'POST', 'purge_item' );
		$r( "/e/$ent/(?P<id>\d+)/duplicate", 'POST', 'duplicate_item' );
		$r( "/e/$ent/(?P<id>\d+)/versions", 'GET', 'versions' );
		$r( '/engineering/duplicates', 'GET', 'duplicates' );
		$r( '/search/parse', 'GET', 'parse' );
		$r( '/search/items', 'GET', 'search_items' );
		$r( '/calc/(?P<op>[a-z_]+)', 'POST', 'calc' );
		$r( "/import/$ent/preview", 'POST', 'import_preview' );
		$r( "/import/$ent/commit", 'POST', 'import_commit' );
		$r( "/export/$ent", 'GET', 'export' );
		$r( '/ai/settings', 'GET', 'ai_settings' );
		$r( '/ai/settings', 'POST', 'ai_update' );
		$r( '/ai/key', 'POST', 'ai_key' );
		$r( '/ai/key', 'DELETE', 'ai_key_delete' );
		$r( '/ai/test', 'POST', 'ai_test' );
		$r( '/ai/models', 'GET', 'ai_models' );
		$r( '/ai/instruction', 'POST', 'ai_instruction' );
		$r( '/ai/errors', 'GET', 'ai_errors' );
		$r( '/templates/upload', 'POST', 'template_upload' );
		$r( '/templates/(?P<id>\d+)/versions', 'GET', 'template_versions' );
		$r( '/templates/version/(?P<vid>\d+)/mappings', 'GET', 'mappings_get' );
		$r( '/templates/version/(?P<vid>\d+)/mappings', 'POST', 'mappings_save' );
		$r( '/templates/version/(?P<vid>\d+)/ai-suggest', 'POST', 'mappings_ai' );
		$r( "/assets/$ent/(?P<id>\d+)/(?P<col>[a-z_]+)", 'POST', 'asset_upload' );
		$r( "/assets/$ent/(?P<id>\d+)/(?P<col>[a-z_]+)", 'GET', 'asset_get' );
		$r( '/backup', 'GET', 'backup' );
		$r( '/restore', 'POST', 'restore' );
		$r( '/po/(?P<id>\d+)/balance', 'GET', 'po_balance' );
		$r( '/documents/preview', 'POST', 'doc_preview' );
		$r( '/documents/generate', 'POST', 'doc_generate' );
		$r( '/documents/(?P<id>\d+)/file', 'GET', 'doc_file' );
		$r( '/documents/zip', 'GET', 'doc_zip' );
		$r( '/documents/(?P<id>\d+)/revise', 'POST', 'doc_revise' );
		$r( '/system/info', 'GET', 'sysinfo' );
		$r( '/demo/status', 'GET', 'demo_status' );
		$r( '/demo/load', 'POST', 'demo_load' );
		$r( '/demo/remove', 'POST', 'demo_remove' );
	}

	/* ---------- helpers ---------- */
	private static function res( $v ) { return is_wp_error( $v ) ? $v : rest_ensure_response( $v ); }
	private static function entity( $req ) { $e = $req['entity']; return PDQ_Registry::get( $e ) ? $e : false; }
	private static function bad() { return new WP_Error( 'pdq_unknown_entity', 'Unknown entity.', array( 'status' => 404 ) ); }
	private static function internal_blocked( $e ) { $d = PDQ_Registry::get( $e ); return ! empty( $d['internal'] ); }

	/* ---------- core ---------- */
	public static function bootstrap() {
		$ents = array();
		foreach ( PDQ_Registry::entities() as $k => $d ) {
			if ( ! empty( $d['internal'] ) ) { continue; }
			$cols = array(); foreach ( $d['columns'] as $c => $sql ) { $cols[] = array( 'name' => $c, 'sql' => $sql, 'required' => in_array( $c, $d['required'], true ), 'fk' => $d['fks'][ $c ] ?? null, 'json' => in_array( $c, $d['json'] ?? array(), true ) ); }
			$ents[ $k ] = array( 'label' => $d['label'], 'group' => $d['group'], 'list' => $d['list'], 'columns' => $cols, 'readonly' => ! empty( $d['readonly'] ) );
		}
		return rest_ensure_response( array( 'entities' => $ents, 'nav' => PDQ_Meta::nav(), 'doc_types' => PDQ_Meta::doc_types(), 'placeholders' => PDQ_Meta::placeholders(), 'categories' => PDQ_Meta::categories(), 'version' => PDQ_VERSION ) );
	}
	public static function dashboard() { return self::res( PDQ_Dashboard::data() ); }

	/* ---------- CRUD ---------- */
	public static function list_items( $req ) {
		$e = self::entity( $req ); if ( ! $e || self::internal_blocked( $e ) ) { return self::bad(); }
		$f = (array) $req->get_param( 'filters' );
		return self::res( PDQ_DB::list_rows( $e, array( 'page' => $req['page'], 'per_page' => $req['per_page'], 'search' => $req['search'], 'orderby' => $req['orderby'], 'order' => $req['order'], 'filters' => $f, 'trashed' => (bool) $req['trashed'] ) ) );
	}
	public static function get_item( $req ) { $e = self::entity( $req ); return ( $e && ! self::internal_blocked( $e ) ) ? self::res( PDQ_DB::get( $e, $req['id'] ) ) : self::bad(); }
	public static function create_item( $req ) {
		$e = self::entity( $req ); if ( ! $e || self::internal_blocked( $e ) ) { return self::bad(); }
		$in = (array) $req->get_json_params();
		if ( 'documents' === $e || 'heat_numbers' === $e ) { $chk = self::guard( $e, $in, null ); if ( is_wp_error( $chk ) ) { return $chk; } }
		$r = PDQ_DB::create( $e, $in ); if ( ! is_wp_error( $r ) && 'dispatch_items' === $e ) { PDQ_DB::recalc_dispatched( $r['po_item_id'] ); }
		return self::res( $r );
	}
	public static function update_item( $req ) {
		$e = self::entity( $req ); if ( ! $e || self::internal_blocked( $e ) ) { return self::bad(); }
		$in = (array) $req->get_json_params();
		$chk = self::guard( $e, $in, (int) $req['id'] ); if ( is_wp_error( $chk ) ) { return $chk; }
		$r = PDQ_DB::update( $e, $req['id'], $in );
		if ( ! is_wp_error( $r ) && 'documents' === $e && 'issued' === ( $in['status'] ?? '' ) ) {
			global $wpdb; $wpdb->update( PDQ_Registry::table( 'documents' ), array( 'immutable' => 1, 'issued_at' => current_time( 'mysql' ) ), array( 'id' => (int) $req['id'] ) );
			PDQ_Activity::log( 'issue', 'documents', (int) $req['id'], 'Document issued (snapshot locked)' ); $r = PDQ_DB::get( 'documents', $req['id'] );
		}
		if ( ! is_wp_error( $r ) && 'heat_numbers' === $e && ( $in['verification_status'] ?? '' ) === 'verified' ) {
			global $wpdb; $wpdb->update( PDQ_Registry::table( 'heat_numbers' ), array( 'verified_by' => get_current_user_id(), 'verified_at' => current_time( 'mysql' ) ), array( 'id' => (int) $req['id'] ) );
			PDQ_Activity::log( 'verify', 'heat_numbers', (int) $req['id'], 'Heat number verified' );
		}
		if ( ! is_wp_error( $r ) && 'dispatch_items' === $e ) { PDQ_DB::recalc_dispatched( $r['po_item_id'] ); }
		return self::res( $r );
	}
	/** Business rules: certificates may only be issued from verified heat data; issuing makes a snapshot immutable. */
	private static function guard( $e, $in, $id ) {
		if ( 'documents' !== $e ) { return true; }
		$status = $in['status'] ?? null;
		if ( in_array( $status, array( 'approved', 'issued' ), true ) ) {
			$heat = $in['heat_id'] ?? null; if ( ! $heat && $id ) { $cur = PDQ_DB::get( 'documents', $id ); $heat = is_wp_error( $cur ) ? null : $cur['heat_id']; }
			if ( $heat ) { $h = PDQ_DB::get( 'heat_numbers', $heat ); if ( is_wp_error( $h ) || 'verified' !== $h['verification_status'] ) { return new WP_Error( 'pdq_unverified_heat', 'Cannot approve/issue: linked heat number is not verified.', array( 'status' => 409 ) ); } }
		}
		return true;
	}
	public static function delete_item( $req ) { $e = self::entity( $req ); if ( ! $e || self::internal_blocked( $e ) ) { return self::bad(); } $old = 'dispatch_items' === $e ? PDQ_DB::get( $e, $req['id'] ) : null; $r = PDQ_DB::trash( $e, $req['id'] ); if ( $old && ! is_wp_error( $old ) ) { PDQ_DB::recalc_dispatched( $old['po_item_id'] ); } return self::res( $r ); }
	public static function restore_item( $req ) { $e = self::entity( $req ); return ( $e && ! self::internal_blocked( $e ) ) ? self::res( PDQ_DB::restore( $e, $req['id'] ) ) : self::bad(); }
	public static function purge_item( $req ) { $e = self::entity( $req ); return ( $e && ! self::internal_blocked( $e ) ) ? self::res( PDQ_DB::purge( $e, $req['id'] ) ) : self::bad(); }
	public static function duplicate_item( $req ) { $e = self::entity( $req ); return ( $e && ! self::internal_blocked( $e ) ) ? self::res( PDQ_DB::duplicate( $e, $req['id'], (array) $req->get_json_params() ) ) : self::bad(); }
	public static function versions( $req ) { $e = self::entity( $req ); return $e ? self::res( PDQ_DB::versions( $e, $req['id'] ) ) : self::bad(); }
	public static function duplicates() { return self::res( PDQ_DB::find_duplicates() ); }

	/* ---------- search + calc ---------- */
	public static function parse( $req ) { return self::res( PDQ_Parser::parse( (string) $req['q'] ) ); }
	public static function search_items( $req ) { return self::res( PDQ_Parser::search_items( (string) $req['q'] ) ); }
	public static function calc( $req ) {
		$p = (array) $req->get_json_params();
		switch ( $req['op'] ) {
			case 'inside_diameter': return self::res( array( 'value' => PDQ_Calc::inside_diameter( $p['od'] ?? 0, $p['wt'] ?? 0 ), 'unit' => 'mm', 'basis' => 'calculated' ) );
			case 'weight_per_meter': return self::res( array( 'value' => PDQ_Calc::weight_per_meter( (float) ( $p['od'] ?? 0 ), (float) ( $p['wt'] ?? 0 ), $p['density'] ?? null ), 'unit' => 'kg/m', 'basis' => 'calculated (approximate, nominal wall)' ) );
			case 'totals': return self::res( PDQ_Calc::totals( (array) ( $p['lines'] ?? array() ), $p['packing_weight'] ?? 0 ) );
			case 'tolerance': return self::res( PDQ_Calc::check_tolerance( $p['nominal'] ?? 0, $p['actual'] ?? null, $p['tol_min'] ?? null, $p['tol_max'] ?? null, $p['tol_pct'] ?? null ) );
			case 'limits': return self::res( PDQ_Calc::check_limits( $p['actual'] ?? null, $p['min'] ?? null, $p['max'] ?? null ) );
			case 'balance': return self::res( PDQ_Calc::po_balance( $p['ordered'] ?? 0, $p['dispatched'] ?? 0 ) );
			case 'convert': return self::res( array( 'value' => PDQ_Calc::convert_length( $p['value'] ?? 0, $p['from'] ?? 'mm', $p['to'] ?? 'in' ) ) );
		}
		return new WP_Error( 'pdq_calc', 'Unknown calculation.', array( 'status' => 404 ) );
	}
	public static function po_balance( $req ) {
		global $wpdb; $t = PDQ_Registry::table( 'purchase_order_items' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, line_no, item_description, ordered_qty, dispatched_qty FROM $t WHERE po_id = %d AND workspace_id = %d AND deleted_at IS NULL ORDER BY line_no", (int) $req['id'], PDQ_DB::workspace_id() ), ARRAY_A );
		foreach ( $rows as &$r ) { $r['balance'] = PDQ_Calc::po_balance( $r['ordered_qty'], $r['dispatched_qty'] ); }
		return self::res( $rows );
	}

	/* ---------- import / export ---------- */
	private static function upload_rows( $req ) {
		$f = $req->get_file_params()['file'] ?? null;
		if ( ! $f || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) { return new WP_Error( 'pdq_upload', 'No file uploaded.', array( 'status' => 422 ) ); }
		if ( $f['size'] > PDQ_Files::MAX_BYTES * 4 ) { return new WP_Error( 'pdq_size', 'File too large.', array( 'status' => 413 ) ); }
		return PDQ_Import::read_file( $f['tmp_name'], pathinfo( $f['name'], PATHINFO_EXTENSION ) );
	}
	public static function import_preview( $req ) {
		$e = self::entity( $req ); if ( ! $e || self::internal_blocked( $e ) ) { return self::bad(); }
		$rows = self::upload_rows( $req ); if ( is_wp_error( $rows ) ) { return $rows; }
		return self::res( PDQ_Import::preview( $e, $rows ) );
	}
	public static function import_commit( $req ) {
		$e = self::entity( $req ); if ( ! $e || self::internal_blocked( $e ) ) { return self::bad(); }
		$rows = self::upload_rows( $req ); if ( is_wp_error( $rows ) ) { return $rows; }
		$map = json_decode( (string) $req->get_param( 'mapping' ), true ); if ( ! is_array( $map ) ) { $map = PDQ_Import::auto_map( $e, $rows ? array_keys( $rows[0] ) : array() ); }
		$mode = in_array( $req['mode'], array( 'skip_duplicates', 'update', 'insert_all' ), true ) ? $req['mode'] : 'skip_duplicates';
		return self::res( PDQ_Import::commit( $e, $rows, $map, $mode ) );
	}
	public static function export( $req ) {
		$e = self::entity( $req ); if ( ! $e || self::internal_blocked( $e ) ) { return self::bad(); }
		$rows = PDQ_Import::export_rows( $e, (array) $req->get_param( 'filters' ), (string) $req['search'] ); if ( is_wp_error( $rows ) ) { return $rows; }
		$fmt = 'json' === $req['format'] ? 'json' : 'csv';
		PDQ_Activity::log( 'export', $e, 0, 'Exported ' . count( $rows ) . " rows as $fmt" );
		return self::res( array( 'filename' => "pdq-$e-" . gmdate( 'Ymd-His' ) . ".$fmt", 'mime' => 'json' === $fmt ? 'application/json' : 'text/csv', 'content' => 'json' === $fmt ? wp_json_encode( $rows ) : PDQ_Import::to_csv( $rows ), 'count' => count( $rows ) ) );
	}

	/* ---------- AI ---------- */
	public static function ai_settings() { return self::res( PDQ_Gemini::settings() ); }
	public static function ai_update( $req ) { return self::res( PDQ_Gemini::update( (array) $req->get_json_params() ) ); }
	public static function ai_key( $req ) { $p = (array) $req->get_json_params(); return self::res( PDQ_Gemini::save_key( $p['api_key'] ?? '' ) ); }
	public static function ai_key_delete() { return self::res( PDQ_Gemini::remove_key() ); }
	public static function ai_test() { return self::res( PDQ_Gemini::test() ); }
	public static function ai_models() { return self::res( PDQ_Gemini::models() ); }
	public static function ai_errors() { return self::res( PDQ_DB::list_rows( 'activity_logs', array( 'filters' => array( 'action' => 'ai_error' ), 'per_page' => 50 ) ) ); }
	/** NL instruction -> structured params -> validated lookup (never creates technical data itself). */
	public static function ai_instruction( $req ) {
		$p = (array) $req->get_json_params(); $text = (string) ( $p['text'] ?? '' );
		if ( '' === trim( $text ) ) { return new WP_Error( 'pdq_empty', 'Instruction is empty.', array( 'status' => 422 ) ); }
		$s = PDQ_Gemini::parse_instruction( $text ); if ( is_wp_error( $s ) ) { return $s; }
		$match = PDQ_Parser::search_items( trim( ( $s['nb'] ?? '' ) . 'NB ' . ( $s['pressure_class'] ? 'CL' . $s['pressure_class'] : '' ) . ' ' . ( $s['abbreviation'] ?? '' ) . ' ' . ( $s['product'] ?? '' ) ) );
		PDQ_Activity::log( 'ai_instruction', 'ai', 0, substr( $text, 0, 150 ) );
		return self::res( array( 'structured' => $s, 'matches' => $match['matches'], 'needs_confirmation' => true, 'note' => 'Review the parameters and matches, then continue in the Document Generator. No values were invented.' ) );
	}

	/* ---------- templates + assets ---------- */
	public static function template_upload( $req ) {
		global $wpdb;
		$f = $req->get_file_params()['file'] ?? null; if ( ! $f ) { return new WP_Error( 'pdq_upload', 'No file uploaded.', array( 'status' => 422 ) ); }
		$s = PDQ_Files::store( $f, 'template' ); if ( is_wp_error( $s ) ) { return $s; }
		$analysis = PDQ_Files::analyze( PDQ_Files::resolve( $s['path'] ), $s['ext'] );
		$tid = (int) $req['template_id'];
		if ( $tid ) { $tpl = PDQ_DB::get( 'templates', $tid ); if ( is_wp_error( $tpl ) ) { return $tpl; } $ver = (int) $tpl['current_version'] + 1; PDQ_DB::update( 'templates', $tid, array( 'current_version' => $ver, 'file_type' => $s['ext'] ) ); }
		else {
			$tpl = PDQ_DB::create( 'templates', array( 'name' => $req['name'] ?: pathinfo( $s['original_name'], PATHINFO_FILENAME ), 'doc_type' => $req['doc_type'] ?: 'Custom', 'category' => $req['category'], 'file_type' => $s['ext'], 'orientation' => $analysis['page']['orientation'] ?? 'portrait', 'status' => 'draft' ) );
			if ( is_wp_error( $tpl ) ) { return $tpl; } $tid = (int) $tpl['id']; $ver = 1;
		}
		$v = PDQ_DB::create( 'template_versions', array( 'template_id' => $tid, 'version_no' => $ver, 'file_path' => $s['path'], 'file_hash' => $s['sha256'], 'analysis' => $analysis, 'page_settings' => $analysis['page'], 'warnings' => $analysis['warnings'] ) );
		if ( is_wp_error( $v ) ) { return $v; }
		// Auto-create mappings for placeholders that match known data fields (exact name match only).
		foreach ( $analysis['placeholders'] as $ph ) { if ( $ph['known'] ) { PDQ_DB::create( 'template_field_mappings', array( 'template_version_id' => $v['id'], 'placeholder' => $ph['name'], 'location' => implode( ', ', array_slice( $ph['locations'], 0, 3 ) ), 'data_source' => $ph['name'], 'mapped_by' => 'auto' ) ); } }
		PDQ_Activity::log( 'template_upload', 'templates', $tid, 'Template uploaded v' . $ver, array( 'warnings' => $analysis['warnings'] ) );
		return self::res( array( 'template' => PDQ_DB::get( 'templates', $tid ), 'version' => $v, 'analysis' => $analysis ) );
	}
	public static function template_versions( $req ) { return self::res( PDQ_DB::list_rows( 'template_versions', array( 'filters' => array( 'template_id' => $req['id'] ), 'per_page' => 100, 'orderby' => 'version_no' ) ) ); }
	public static function mappings_get( $req ) { return self::res( PDQ_DB::list_rows( 'template_field_mappings', array( 'filters' => array( 'template_version_id' => $req['vid'] ), 'per_page' => 200, 'orderby' => 'id', 'order' => 'ASC' ) ) ); }
	public static function mappings_save( $req ) {
		global $wpdb; $vid = (int) $req['vid']; $v = PDQ_DB::get( 'template_versions', $vid ); if ( is_wp_error( $v ) ) { return $v; }
		$items = (array) ( $req->get_json_params()['mappings'] ?? array() ); $t = PDQ_Registry::table( 'template_field_mappings' );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE template_version_id = %d AND workspace_id = %d", $vid, PDQ_DB::workspace_id() ) );
		foreach ( $items as $m ) { $m['template_version_id'] = $vid; $r = PDQ_DB::create( 'template_field_mappings', $m ); if ( is_wp_error( $r ) ) { $wpdb->query( 'ROLLBACK' ); return $r; } }
		$wpdb->query( 'COMMIT' );
		if ( isset( $req->get_json_params()['verified'] ) ) { PDQ_DB::update( 'template_versions', $vid, array( 'mapping_verified' => $req->get_json_params()['verified'] ? 1 : 0 ) ); PDQ_DB::update( 'templates', $v['template_id'], array( 'status' => $req->get_json_params()['verified'] ? 'verified' : 'draft' ) ); }
		PDQ_Activity::log( 'mapping_saved', 'template_versions', $vid, 'Template mapping saved (' . count( $items ) . ' fields)' );
		return self::res( array( 'saved' => count( $items ) ) );
	}
	public static function mappings_ai( $req ) {
		$v = PDQ_DB::get( 'template_versions', $req['vid'] ); if ( is_wp_error( $v ) ) { return $v; }
		$s = PDQ_Gemini::settings(); if ( ! $s['consent_given'] ) { return new WP_Error( 'pdq_ai_consent', 'Consent is required before sending template labels to Gemini.', array( 'status' => 403 ) ); }
		$fields = (array) ( $v['analysis']['text_fields'] ?? array() ); // Only short labels are sent — not document bodies.
		return self::res( PDQ_Gemini::suggest_mappings( array_slice( $fields, 0, 150 ), PDQ_Meta::placeholders() ) );
	}

	private static function asset_cols( $e ) { return array( 'company_profiles' => array( 'logo_path', 'letterhead_path', 'stamp_path', 'signature_path', 'qc_stamp_path', 'inspection_stamp_path' ), 'heat_numbers' => array( 'mtc_path' ), 'signature_assets' => array( 'file_path' ), 'document_attachments' => array( 'file_path' ) )[ $e ] ?? array(); }
	public static function asset_upload( $req ) {
		$e = self::entity( $req ); $c = $req['col']; if ( ! $e || ! in_array( $c, self::asset_cols( $e ), true ) ) { return new WP_Error( 'pdq_asset', 'Invalid asset target.', array( 'status' => 422 ) ); }
		$cur = PDQ_DB::get( $e, $req['id'] ); if ( is_wp_error( $cur ) ) { return $cur; }
		$f = $req->get_file_params()['file'] ?? null; if ( ! $f ) { return new WP_Error( 'pdq_upload', 'No file uploaded.', array( 'status' => 422 ) ); }
		$kind = 'letterhead_path' === $c ? 'letterhead' : ( ( 'mtc_path' === $c || 'document_attachments' === $e ) ? 'attachment' : 'image' );
		$s = PDQ_Files::store( $f, $kind ); if ( is_wp_error( $s ) ) { return $s; }
		PDQ_DB::update( $e, $req['id'], array( $c => $s['path'] ) );
		PDQ_Activity::log( 'asset_upload', $e, (int) $req['id'], "Uploaded $c" );
		return self::res( array( 'path' => $s['path'] ) );
	}
	/** Streams a private file; every read of a signature/stamp is audited. */
	public static function asset_get( $req ) {
		$e = self::entity( $req ); $c = $req['col']; if ( ! $e || ! in_array( $c, self::asset_cols( $e ), true ) ) { return new WP_Error( 'pdq_asset', 'Invalid asset target.', array( 'status' => 422 ) ); }
		$cur = PDQ_DB::get( $e, $req['id'] ); if ( is_wp_error( $cur ) ) { return $cur; }
		$full = ! empty( $cur[ $c ] ) ? PDQ_Files::resolve( $cur[ $c ] ) : false; if ( ! $full ) { return new WP_Error( 'pdq_nofile', 'No file.', array( 'status' => 404 ) ); }
		if ( preg_match( '/signature|stamp/', $c ) ) { PDQ_Activity::log( 'signature_access', $e, (int) $req['id'], "Accessed $c" ); }
		$ext = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) ); $mimes = array( 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf' );
		nocache_headers(); header( 'Content-Type: ' . ( $mimes[ $ext ] ?? 'application/octet-stream' ) ); header( 'X-Content-Type-Options: nosniff' ); header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'" ); header( 'Content-Length: ' . filesize( $full ) );
		readfile( $full ); exit;
	}


	/* ---------- document generation ---------- */
	private static function next_number( $doc_type ) {
		global $wpdb; $t = PDQ_Registry::table( 'documents' );
		$fmt = $wpdb->get_var( $wpdb->prepare( 'SELECT setting_value FROM ' . PDQ_Registry::table( 'application_settings' ) . ' WHERE workspace_id = %d AND setting_key = %s', PDQ_DB::workspace_id(), 'numbering_format' ) ) ?: '{PFX}/{YYYY}/{SEQ4}';
		preg_match_all( '/\b[A-Za-z]/', preg_replace( '/\(.*?\)/', '', $doc_type ), $m ); $pfx = strtoupper( implode( '', array_slice( $m[0], 0, 3 ) ) ) ?: 'DOC';
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT doc_number) FROM $t WHERE workspace_id = %d AND doc_type = %s", PDQ_DB::workspace_id(), $doc_type ) ) + 1;
		do { $num = strtr( $fmt, array( '{PFX}' => $pfx, '{YYYY}' => gmdate( 'Y' ), '{YY}' => gmdate( 'y' ), '{MM}' => gmdate( 'm' ), '{SEQ}' => $n, '{SEQ3}' => str_pad( $n, 3, '0', STR_PAD_LEFT ), '{SEQ4}' => str_pad( $n, 4, '0', STR_PAD_LEFT ), '{SEQ5}' => str_pad( $n, 5, '0', STR_PAD_LEFT ) ) ); $ex = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE workspace_id = %d AND doc_number = %s", PDQ_DB::workspace_id(), $num ) ); $n++; } while ( $ex );
		return $num;
	}
	private static function build_values( array $p ) {
		$ctx = (array) ( $p['context'] ?? array() ); $col = PDQ_Generator::collect( $ctx ); $vals = $col['values']; $warn = $col['warnings'];
		foreach ( (array) ( $p['overrides'] ?? array() ) as $k => $v ) { if ( '' !== $v && null !== $v ) { $vals[ strtolower( sanitize_key( $k ) ) ] = is_scalar( $v ) ? sanitize_textarea_field( (string) $v ) : $v; } }
		$items = array(); $lines = array();
		foreach ( (array) ( $p['items'] ?? array() ) as $it ) {
			$it = (array) $it; $qty = isset( $it['quantity'] ) && '' !== $it['quantity'] ? (float) $it['quantity'] : null; $uw = isset( $it['unit_weight'] ) && '' !== $it['unit_weight'] ? (float) $it['unit_weight'] : null;
			if ( null !== $qty && null !== $uw ) { $it['line_weight'] = round( $qty * $uw, 3 ); $lines[] = array( 'qty' => $qty, 'unit_weight' => $uw ); }
			elseif ( null !== $qty ) { $lines[] = array( 'qty' => $qty, 'unit_weight' => 0 ); $warn[] = 'Item "' . ( $it['item_description'] ?? '?' ) . '" has no unit weight; weight left blank.'; }
			$items[] = array_map( function ( $x ) { return is_scalar( $x ) ? sanitize_text_field( (string) $x ) : $x; }, $it );
		}
		if ( $lines ) { $tot = PDQ_Calc::totals( $lines, $p['packing_weight'] ?? 0 ); $vals['quantity'] = $vals['quantity'] ?? $tot['total_qty']; $vals['total_weight'] = $tot['net_weight']; $vals['gross_weight'] = $tot['gross_weight']; $vals['packing_weight'] = $tot['packing_weight']; }
		$vals['certificate_date'] = $vals['certificate_date'] ?? gmdate( 'd-m-Y' );
		if ( empty( $items ) && ! empty( $ctx['dimension_id'] ) ) { $items[] = array( 'item_description' => $vals['item_description'] ?? '', 'quantity' => $vals['quantity'] ?? '', 'heat_number' => $vals['heat_number'] ?? '', 'material_grade' => $vals['material_grade'] ?? '', 'outside_diameter' => $vals['outside_diameter'] ?? '', 'wall_thickness' => $vals['wall_thickness'] ?? '', 'schedule' => $vals['schedule'] ?? '', 'pressure_class' => $vals['pressure_class'] ?? '', 'unit_weight' => $vals['unit_weight'] ?? '' ); }
		return array( 'values' => $vals, 'items' => $items, 'warnings' => array_values( array_unique( $warn ) ) );
	}
	public static function doc_preview( $req ) {
		$b = self::build_values( (array) $req->get_json_params() ); $tv = (int) ( $req->get_json_params()['template_version_id'] ?? 0 ); $missing = array();
		if ( $tv ) { $v = PDQ_DB::get( 'template_versions', $tv ); if ( ! is_wp_error( $v ) ) { foreach ( (array) ( $v['analysis']['placeholders'] ?? array() ) as $ph ) { if ( ! PDQ_Generator::is_item_key( $ph['name'] ) && ( ! isset( $b['values'][ $ph['name'] ] ) || '' === $b['values'][ $ph['name'] ] ) && ! in_array( $ph['name'], array( 'company_stamp', 'authorized_signature' ), true ) ) { $missing[] = $ph['name']; } } } }
		$b['missing'] = $missing; return self::res( $b );
	}
	public static function doc_generate( $req ) {
		global $wpdb; $p = (array) $req->get_json_params();
		$tvid = (int) ( $p['template_version_id'] ?? 0 ); $tv = PDQ_DB::get( 'template_versions', $tvid ); if ( is_wp_error( $tv ) ) { return $tv; }
		$tpl = PDQ_DB::get( 'templates', $tv['template_id'] ); if ( is_wp_error( $tpl ) ) { return $tpl; }
		$ctx = (array) ( $p['context'] ?? array() );
		if ( ! empty( $ctx['heat_id'] ) && 'issued' === ( $p['status'] ?? '' ) ) { $h = PDQ_DB::get( 'heat_numbers', $ctx['heat_id'] ); if ( is_wp_error( $h ) || 'verified' !== $h['verification_status'] ) { return new WP_Error( 'pdq_unverified_heat', 'Cannot issue: heat number is not verified.', array( 'status' => 409 ) ); } }
		$b = self::build_values( $p ); $full = $tv['file_path'] ? PDQ_Files::resolve( $tv['file_path'] ) : false;
		if ( ! $full ) { return new WP_Error( 'pdq_nofile', 'Template file is missing.', array( 'status' => 404 ) ); }
		$miss = array(); foreach ( (array) ( $tv['analysis']['placeholders'] ?? array() ) as $ph ) { if ( ! PDQ_Generator::is_item_key( $ph['name'] ) && ( ! isset( $b['values'][ $ph['name'] ] ) || '' === $b['values'][ $ph['name'] ] ) && ! in_array( $ph['name'], array( 'company_stamp', 'authorized_signature' ), true ) ) { $miss[] = $ph['name']; } }
		if ( $miss ) { $b['warnings'][] = count( $miss ) . ' placeholder(s) have no data and were left blank: ' . implode( ', ', array_slice( $miss, 0, 25 ) ) . ( count( $miss ) > 25 ? ' …' : '' ); }
		if ( empty( $b['items'] ) ) { $b['warnings'][] = 'No item lines were added, so item tables are empty. Add items in step 5 (or pick a PO / demo data).'; }
		$ext = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) ); $number = $p['doc_number'] ?? self::next_number( $tpl['doc_type'] ); $b['values']['certificate_number'] = $b['values']['certificate_number'] ?? $number;
		$dir = PDQ_Files::base_dir() . '/generated/' . gmdate( 'Y/m' ); wp_mkdir_p( $dir ); $out = $dir . '/' . bin2hex( random_bytes( 10 ) ) . '.' . ( 'xlsm' === $ext ? 'xlsx' : $ext );
		if ( 'docx' === $ext ) { $r = PDQ_Generator::docx( $full, $out, $b['values'], $b['items'] ); }
		elseif ( in_array( $ext, array( 'xlsx', 'xlsm' ), true ) ) { $r = PDQ_Generator::xlsx( $full, $out, $b['values'], $b['items'] ); $b['warnings'][] = 'Excel templates: repeating item rows are not expanded in this version; use DOCX for item tables or place single-value placeholders.'; }
		else { return new WP_Error( 'pdq_format', 'Only DOCX and XLSX templates can be filled. PDF templates are reference-only.', array( 'status' => 415 ) ); }
		if ( is_wp_error( $r ) ) { return $r; }
		$snapshot = array( 'values' => $b['values'], 'items' => $b['items'], 'warnings' => $b['warnings'], 'template_version_id' => $tvid, 'context' => $ctx, 'signature_applied' => false );
		$doc = PDQ_DB::create( 'documents', array( 'doc_number' => $number, 'doc_type' => $tpl['doc_type'], 'template_id' => $tpl['id'], 'template_version' => $tv['version_no'], 'company_id' => $ctx['company_id'] ?? null, 'customer_id' => $ctx['customer_id'] ?? null, 'po_id' => $ctx['po_id'] ?? null, 'heat_id' => $ctx['heat_id'] ?? null, 'batch_id' => $ctx['batch_id'] ?? null, 'status' => 'draft', 'data_snapshot' => $snapshot, 'file_path' => str_replace( PDQ_Files::base_dir() . '/', '', $out ), 'file_hash' => hash_file( 'sha256', $out ) ) );
		if ( is_wp_error( $doc ) ) { return $doc; }
		foreach ( $b['items'] as $i => $it ) { PDQ_DB::create( 'document_items', array( 'document_id' => $doc['id'], 'line_no' => $i + 1, 'heat_id' => $ctx['heat_id'] ?? null, 'quantity' => $it['quantity'] ?? null, 'item_data' => $it ) ); }
		PDQ_DB::create( 'document_revisions', array( 'document_id' => $doc['id'], 'revision' => 0, 'change_note' => 'Initial generation', 'snapshot' => $snapshot, 'file_path' => $doc['file_path'], 'file_hash' => $doc['file_hash'] ) );
		PDQ_Activity::log( 'generate', 'documents', (int) $doc['id'], 'Generated ' . $number );
		return self::res( array( 'document' => $doc, 'warnings' => $b['warnings'] ) );
	}
	public static function doc_file( $req ) {
		$d = PDQ_DB::get( 'documents', $req['id'] ); if ( is_wp_error( $d ) ) { return $d; }
		$full = $d['file_path'] ? PDQ_Files::resolve( $d['file_path'] ) : false; if ( ! $full ) { return new WP_Error( 'pdq_nofile', 'No file for this document.', array( 'status' => 404 ) ); }
		if ( 'pdf' === $req['format'] ) { $full = PDQ_Generator::pdf( $full ); if ( is_wp_error( $full ) ) { return $full; } }
		$ext = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) ); $mime = array( 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf' );
		PDQ_Activity::log( 'download', 'documents', (int) $d['id'], 'Downloaded ' . $d['doc_number'] );
		nocache_headers(); header( 'Content-Type: ' . ( $mime[ $ext ] ?? 'application/octet-stream' ) ); header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( str_replace( '/', '-', $d['doc_number'] ) . '-r' . $d['revision'] . '.' . $ext ) . '"' ); header( 'Content-Length: ' . filesize( $full ) ); header( 'X-Content-Type-Options: nosniff' );
		readfile( $full ); exit;
	}
	/** New revision: issued documents stay untouched; a new row (same number, revision+1) is created as draft. */
	public static function doc_revise( $req ) {
		global $wpdb; $cur = PDQ_DB::get( 'documents', $req['id'] ); if ( is_wp_error( $cur ) ) { return $cur; }
		$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(revision) FROM ' . PDQ_Registry::table( 'documents' ) . ' WHERE workspace_id = %d AND doc_number = %s', PDQ_DB::workspace_id(), $cur['doc_number'] ) );
		$copy = array(); foreach ( array( 'doc_number', 'doc_type', 'template_id', 'template_version', 'company_id', 'customer_id', 'po_id', 'heat_id', 'batch_id', 'data_snapshot', 'file_path', 'file_hash' ) as $k ) { $copy[ $k ] = $cur[ $k ]; }
		$copy['revision'] = $max + 1; $copy['status'] = 'draft'; $copy['parent_id'] = $cur['id']; $copy['immutable'] = 0;
		$new = PDQ_DB::create( 'documents', $copy ); if ( is_wp_error( $new ) ) { return $new; }
		PDQ_DB::create( 'document_revisions', array( 'document_id' => $new['id'], 'revision' => $new['revision'], 'change_note' => (string) ( $req->get_json_params()['note'] ?? 'Revision' ), 'snapshot' => $cur['data_snapshot'], 'file_path' => $cur['file_path'], 'file_hash' => $cur['file_hash'] ) );
		return self::res( $new );
	}

	/** Batch download as ZIP (files only; every access is audited). */
	public static function doc_zip( $req ) {
		$ids = array_slice( array_filter( array_map( 'intval', explode( ',', (string) $req['ids'] ) ) ), 0, 200 );
		if ( ! $ids || ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'pdq_zip', 'Nothing to zip.', array( 'status' => 422 ) ); }
		$tmp = wp_tempnam( 'pdq-zip' ); $z = new ZipArchive(); $z->open( $tmp, ZipArchive::OVERWRITE ); $n = 0;
		foreach ( $ids as $id ) { $d = PDQ_DB::get( 'documents', $id ); if ( is_wp_error( $d ) || ! $d['file_path'] ) { continue; } $f = PDQ_Files::resolve( $d['file_path'] ); if ( ! $f ) { continue; } $z->addFile( $f, sanitize_file_name( str_replace( '/', '-', $d['doc_number'] ) . '-r' . $d['revision'] . '.' . pathinfo( $f, PATHINFO_EXTENSION ) ) ); $n++; }
		$z->close(); if ( ! $n ) { @unlink( $tmp ); return new WP_Error( 'pdq_zip', 'No files available.', array( 'status' => 404 ) ); }
		PDQ_Activity::log( 'download_zip', 'documents', 0, "ZIP of $n document(s)" );
		nocache_headers(); header( 'Content-Type: application/zip' ); header( 'Content-Disposition: attachment; filename="pipedoc-iq-documents.zip"' ); header( 'Content-Length: ' . filesize( $tmp ) ); readfile( $tmp ); @unlink( $tmp ); exit;
	}
	public static function demo_status() { return self::res( PDQ_Demo::status() ); }
	public static function demo_load() { return self::res( PDQ_Demo::load() ); }
	public static function demo_remove() { return self::res( PDQ_Demo::remove() ); }
	public static function sysinfo() {
		return self::res( array( 'php' => PHP_VERSION, 'zip' => class_exists( 'ZipArchive' ), 'sodium' => function_exists( 'sodium_crypto_secretbox' ), 'phpspreadsheet' => class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ), 'libreoffice' => (bool) PDQ_Generator::soffice(), 'upload_max' => ini_get( 'upload_max_filesize' ), 'private_storage' => PDQ_Files::base_dir() ? true : false ) );
	}

	/* ---------- backup / restore ---------- */
	public static function backup() {
		global $wpdb; $out = array( 'app' => 'pipedoc-iq', 'db_version' => PDQ_Installer::DB_VERSION, 'created' => gmdate( 'c' ), 'tables' => array() );
		foreach ( array_merge( array_keys( PDQ_Registry::entities() ), array( 'record_versions' ) ) as $k ) {
			if ( 'ai_settings' === $k ) { continue; } // Never export secrets.
			$out['tables'][ $k ] = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'pdq_' . $k, ARRAY_A );
		}
		PDQ_Activity::log( 'backup', 'system', 0, 'Backup downloaded' );
		return self::res( array( 'filename' => 'pipedoc-iq-backup-' . gmdate( 'Ymd-His' ) . '.json', 'content' => wp_json_encode( $out ) ) );
	}
	public static function restore( $req ) {
		global $wpdb; $f = $req->get_file_params()['file'] ?? null;
		if ( ! $f || ! is_uploaded_file( $f['tmp_name'] ) ) { return new WP_Error( 'pdq_upload', 'No backup file uploaded.', array( 'status' => 422 ) ); }
		$j = json_decode( file_get_contents( $f['tmp_name'] ), true );
		if ( ! is_array( $j ) || ( $j['app'] ?? '' ) !== 'pipedoc-iq' || empty( $j['tables'] ) ) { return new WP_Error( 'pdq_bad_backup', 'Not a valid PipeDoc IQ backup.', array( 'status' => 422 ) ); }
		if ( 'yes' !== $req['confirm'] ) { return new WP_Error( 'pdq_confirm', 'Restore replaces current data. Send confirm=yes.', array( 'status' => 409 ) ); }
		$count = 0; $wpdb->query( 'START TRANSACTION' );
		foreach ( $j['tables'] as $k => $rows ) {
			if ( ! ( PDQ_Registry::get( $k ) || 'record_versions' === $k ) || 'ai_settings' === $k ) { continue; }
			$t = $wpdb->prefix . 'pdq_' . $k; $cols = $wpdb->get_col( "SHOW COLUMNS FROM $t" );
			$wpdb->query( "DELETE FROM $t" );
			foreach ( $rows as $row ) { $row = array_intersect_key( $row, array_flip( $cols ) ); if ( false === $wpdb->insert( $t, $row ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'pdq_restore', 'Restore failed and was rolled back.', array( 'status' => 500 ) ); } $count++; }
		}
		$wpdb->query( 'COMMIT' ); PDQ_Activity::log( 'restore_backup', 'system', 0, "Restored $count rows" );
		return self::res( array( 'restored_rows' => $count ) );
	}
}
