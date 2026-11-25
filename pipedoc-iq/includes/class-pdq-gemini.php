<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Gemini integration. All calls server-side; key never leaves PHP. */
class PDQ_Gemini {
	const BASE = 'https://generativelanguage.googleapis.com/v1beta';

	private static function row() {
		global $wpdb;
		$t = PDQ_Registry::table( 'ai_settings' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE workspace_id = %d AND user_id = 0 AND deleted_at IS NULL LIMIT 1", PDQ_DB::workspace_id() ), ARRAY_A );
		return $r;
	}
	private static function upsert( $data ) {
		global $wpdb;
		$t = PDQ_Registry::table( 'ai_settings' ); $r = self::row(); $now = current_time( 'mysql' );
		if ( $r ) { $wpdb->update( $t, $data + array( 'updated_at' => $now ), array( 'id' => $r['id'] ) ); }
		else { $wpdb->insert( $t, $data + array( 'workspace_id' => PDQ_DB::workspace_id(), 'owner_id' => PDQ_DB::owner_id(), 'user_id' => 0, 'created_at' => $now, 'updated_at' => $now ) ); }
	}

	/** Safe public view: never includes the key. */
	public static function settings() {
		$r = self::row();
		return array(
			'has_key' => (bool) ( $r && $r['api_key_enc'] ), 'key_hint' => $r['key_hint'] ?? '', 'model' => $r['model'] ?? '',
			'enabled' => (bool) ( $r['enabled'] ?? 0 ), 'consent_given' => (bool) ( $r['consent_given'] ?? 0 ),
			'usage_requests' => (int) ( $r['usage_requests'] ?? 0 ), 'usage_errors' => (int) ( $r['usage_errors'] ?? 0 ), 'last_error' => $r['last_error'] ?? '',
		);
	}

	public static function save_key( $key ) {
		$key = trim( (string) $key, " \t\n\r\"'" );
		// Google key formats vary (AIza… and newer AQ.… keys contain dots/other symbols) — only require printable, whitespace-free text.
		if ( ! preg_match( '/^[\x21-\x7E]{20,400}$/', $key ) ) { return new WP_Error( 'pdq_bad_key', 'The key must be 20+ visible characters with no spaces. Re-copy it from Google AI Studio.', array( 'status' => 422 ) ); }
		self::upsert( array( 'api_key_enc' => PDQ_Crypto::encrypt( $key ), 'key_hint' => '…' . substr( $key, -4 ), 'last_error' => null ) );
		PDQ_Activity::log( 'ai_key_saved', 'ai_settings', 0, 'Gemini API key saved (encrypted).' );
		return self::settings();
	}
	public static function remove_key() {
		self::upsert( array( 'api_key_enc' => null, 'key_hint' => null, 'enabled' => 0 ) );
		PDQ_Activity::log( 'ai_key_removed', 'ai_settings', 0, 'Gemini API key removed.' );
		return self::settings();
	}
	public static function update( $in ) {
		$d = array();
		if ( isset( $in['model'] ) ) { $d['model'] = sanitize_text_field( $in['model'] ); }
		if ( isset( $in['enabled'] ) ) { $d['enabled'] = $in['enabled'] ? 1 : 0; }
		if ( isset( $in['consent_given'] ) ) { $d['consent_given'] = $in['consent_given'] ? 1 : 0; }
		if ( $d ) { self::upsert( $d ); }
		return self::settings();
	}

	private static function key() { $r = self::row(); return $r && $r['api_key_enc'] ? PDQ_Crypto::decrypt( $r['api_key_enc'] ) : ''; }

	private static function request( $method, $path, $body = null ) {
		$key = self::key();
		if ( ! $key ) { return new WP_Error( 'pdq_no_key', 'No Gemini API key configured.', array( 'status' => 400 ) ); }
		$args = array( 'method' => $method, 'timeout' => 60, 'headers' => array( 'x-goog-api-key' => $key, 'Content-Type' => 'application/json' ) );
		if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }
		$res = wp_remote_request( self::BASE . $path, $args );
		if ( is_wp_error( $res ) ) { $m = $res->get_error_message(); self::fail( $m ); return new WP_Error( 'pdq_ai_http', 'Could not reach Gemini: ' . $m . ' (check internet access / SSL certificates of this PHP install).', array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $res ); $j = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 300 ) {
			$msg = $j['error']['message'] ?? ( 'HTTP ' . $code ); $msg = str_replace( $key, '[redacted]', $msg );
			self::fail( $msg ); return new WP_Error( 'pdq_ai_error', $msg, array( 'status' => 502 ) );
		}
		return $j;
	}
	private static function fail( $msg ) {
		global $wpdb; $t = PDQ_Registry::table( 'ai_settings' );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET usage_errors = usage_errors + 1, last_error = %s WHERE workspace_id = %d AND user_id = 0", substr( $msg, 0, 500 ), PDQ_DB::workspace_id() ) );
		PDQ_Activity::log( 'ai_error', 'ai_settings', 0, substr( $msg, 0, 190 ) );
	}
	private static function count() {
		global $wpdb; $t = PDQ_Registry::table( 'ai_settings' );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET usage_requests = usage_requests + 1 WHERE workspace_id = %d AND user_id = 0", PDQ_DB::workspace_id() ) );
	}

	/** Dynamically list models that support generateContent. */
	public static function models() {
		$j = self::request( 'GET', '/models?pageSize=200' ); if ( is_wp_error( $j ) ) { return $j; }
		$out = array();
		foreach ( (array) ( $j['models'] ?? array() ) as $m ) {
			if ( in_array( 'generateContent', (array) ( $m['supportedGenerationMethods'] ?? array() ), true ) ) {
				$out[] = array( 'id' => preg_replace( '#^models/#', '', $m['name'] ), 'display' => $m['displayName'] ?? $m['name'] );
			}
		}
		return $out;
	}
	public static function test() {
		$m = self::models(); if ( is_wp_error( $m ) ) { return $m; }
		return array( 'ok' => true, 'model_count' => count( $m ), 'models' => $m );
	}

	/** Core generation. Requires enabled + consent + model. $json => ask for JSON output. */
	public static function generate( $prompt, $json = true ) {
		$s = self::settings();
		if ( ! $s['enabled'] ) { return new WP_Error( 'pdq_ai_off', 'AI is disabled. Core features work without it.', array( 'status' => 400 ) ); }
		if ( ! $s['consent_given'] ) { return new WP_Error( 'pdq_ai_consent', 'Consent is required before sending document data to Gemini.', array( 'status' => 403 ) ); }
		if ( ! $s['model'] ) { return new WP_Error( 'pdq_ai_model', 'Select a Gemini model first.', array( 'status' => 400 ) ); }
		$body = array( 'contents' => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ) );
		if ( $json ) { $body['generationConfig'] = array( 'responseMimeType' => 'application/json', 'temperature' => 0 ); }
		self::count();
		$j = self::request( 'POST', '/models/' . rawurlencode( $s['model'] ) . ':generateContent', $body );
		if ( is_wp_error( $j ) ) { return $j; }
		$txt = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if ( $json ) { $d = json_decode( $txt, true ); return null === $d ? new WP_Error( 'pdq_ai_parse', 'Gemini returned non-JSON output.', array( 'status' => 502 ) ) : $d; }
		return $txt;
	}

	const GUARD = "You are a parser for an industrial documentation system. NEVER invent technical specifications, chemical composition, mechanical properties, heat numbers, inspection results, dimensions, or approvals. If a value is not stated, return null. Output JSON only.";

	/** Natural language -> structured generation request (validated later by deterministic services). */
	public static function parse_instruction( $text ) {
		$prompt = self::GUARD . "\nConvert this instruction into JSON with keys: documents (array of document type names), product (string|null), nb (number|null), pressure_class (string|null), schedule (string|null), facing (string|null), abbreviation (string|null), quantity (number|null), heat_number (string|null), po_number (string|null), notes (string|null).\nInstruction: " . wp_json_encode( $text );
		return self::generate( $prompt, true );
	}

	/** Suggest placeholder mappings for template cells/fields. Returns suggestions only; user must confirm. */
	public static function suggest_mappings( array $fields, array $known_placeholders ) {
		$prompt = self::GUARD . "\nGiven these template labels/cells, suggest which known placeholder each corresponds to. Return JSON: {\"mappings\":[{\"location\":string,\"placeholder\":string|null,\"confidence\":0-1}]}. Known placeholders: " . wp_json_encode( $known_placeholders ) . "\nTemplate fields: " . wp_json_encode( $fields );
		return self::generate( $prompt, true );
	}
}
