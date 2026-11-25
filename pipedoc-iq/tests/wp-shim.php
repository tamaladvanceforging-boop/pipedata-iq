<?php
// Minimal WordPress compatibility shim for integration tests against a real MySQL/MariaDB. NOT shipped for production use.
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'MINUTE_IN_SECONDS', 60 );
class WP_Error { public $code; public $msg; public $data; function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $s ) ), '-' ); }
function sanitize_file_name( $s ) { return $s; }
function wp_unslash( $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $t ) { return date( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 1; }
function apply_filters( $n, $v ) { return $v; }
function do_action() {}
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function wp_salt( $s = '' ) { return 'test-salt-' . $s; }
function wp_remote_request() { return new WP_Error( 'offline' ); }
class wpdb {
	public $prefix = 'wp_'; public $insert_id = 0; public $last_error = ''; private $m; private $sup = false;
	function __construct() { mysqli_report( MYSQLI_REPORT_OFF ); $this->m = new mysqli( '127.0.0.1', 'wp', 'wp', 'wp' ); }
	function suppress_errors( $b ) { $this->sup = $b; }
	function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	function prepare( $q, ...$a ) {
		if ( count( $a ) === 1 && is_array( $a[0] ) ) { $a = $a[0]; }
		$q = str_replace( '%%', '{{PCT}}', $q ); $i = 0;
		$q = preg_replace_callback( '/%([sdf])/', function ( $m ) use ( &$a, &$i ) { $v = $a[ $i++ ] ?? null; if ( 'd' === $m[1] ) { return (string) (int) $v; } if ( 'f' === $m[1] ) { return (string) (float) $v; } return null === $v ? 'NULL' : "'" . $this->m->real_escape_string( (string) $v ) . "'"; }, $q );
		return str_replace( '{{PCT}}', '%', $q );
	}
	function query( $q ) { $r = $this->m->query( $q ); if ( false === $r ) { $this->last_error = $this->m->error; if ( ! $this->sup ) { fwrite( STDERR, "SQL ERROR: {$this->m->error}\n$q\n" ); } return false; } $this->insert_id = $this->m->insert_id; return true === $r ? $this->m->affected_rows : $r->num_rows; }
	private function rows( $q ) { $r = $this->m->query( $q ); if ( false === $r ) { $this->last_error = $this->m->error; fwrite( STDERR, "SQL ERROR: {$this->m->error}\n$q\n" ); return array(); } return $r->fetch_all( MYSQLI_ASSOC ); }
	function get_var( $q ) { $r = $this->rows( $q ); return $r ? array_values( $r[0] )[0] : null; }
	function get_row( $q, $o = null ) { $r = $this->rows( $q ); return $r ? $r[0] : null; }
	function get_results( $q, $o = null ) { return $this->rows( $q ); }
	function get_col( $q ) { return array_map( function ( $r ) { return array_values( $r )[0]; }, $this->rows( $q ) ); }
	private function val( $v ) { return null === $v ? 'NULL' : "'" . $this->m->real_escape_string( (string) $v ) . "'"; }
	function insert( $t, $d, $f = null ) { $this->last_error = ''; $ok = $this->query( "INSERT INTO $t (" . implode( ',', array_keys( $d ) ) . ') VALUES (' . implode( ',', array_map( [ $this, 'val' ], $d ) ) . ')' ); return false === $ok ? false : 1; }
	function update( $t, $d, $w, $f = null, $wf = null ) { $this->last_error = ''; $s = array(); foreach ( $d as $k => $v ) { $s[] = "$k=" . $this->val( $v ); } $c = array(); foreach ( $w as $k => $v ) { $c[] = "$k=" . $this->val( $v ); } return $this->query( "UPDATE $t SET " . implode( ',', $s ) . ' WHERE ' . implode( ' AND ', $c ) ); }
	function delete( $t, $w, $f = null ) { $c = array(); foreach ( $w as $k => $v ) { $c[] = "$k=" . $this->val( $v ); } return $this->query( "DELETE FROM $t WHERE " . implode( ' AND ', $c ) ); }
	function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'; }
}
$wpdb = new wpdb();
