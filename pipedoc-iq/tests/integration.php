<?php
// Integration test: php tests/integration.php  (needs MySQL/MariaDB db=wp user=wp pass=wp on 127.0.0.1)
require __DIR__ . '/wp-shim.php';
define( 'PDQ_VERSION', 't' );
foreach ( array( 'registry', 'meta', 'activity', 'db', 'calc', 'parser', 'crypto', 'import', 'dashboard' ) as $f ) { require __DIR__ . "/../includes/class-pdq-$f.php"; }
$n = 0; $fail = 0;
function t( $name, $ok ) { global $n, $fail; $n++; if ( ! $ok ) { $fail++; echo "FAIL: $name\n"; } else { echo "ok:   $name\n"; } }
global $wpdb;
// Schema
foreach ( PDQ_Registry::schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) as $k => $sql ) { $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pdq_' . $k ); if ( false === $wpdb->query( $sql ) ) { echo "SCHEMA FAIL $k\n"; $fail++; } }
$tables = $wpdb->get_col( "SHOW TABLES LIKE 'wp_pdq_%'" );
t( 'all tables created (' . count( $tables ) . ')', count( $tables ) === count( PDQ_Registry::entities() ) + 1 );
$wpdb->insert( 'wp_pdq_workspaces', array( 'id' => 1, 'name' => 'Default', 'slug' => 'default' ) );
// Crypto
$c = PDQ_Crypto::encrypt( 'AIzaSyTESTKEY1234567890abcdef' );
t( 'crypto roundtrip', PDQ_Crypto::decrypt( $c ) === 'AIzaSyTESTKEY1234567890abcdef' && false === strpos( $c, 'AIza' ) );
// CRUD
$r = PDQ_DB::create( 'customers', array( 'name' => 'Test Customer', 'gstin' => '19ABCDE1234F1Z5' ) );
t( 'create customer', ! is_wp_error( $r ) && $r['name'] === 'Test Customer' );
t( 'validation required', is_wp_error( PDQ_DB::create( 'customers', array( 'city' => 'x' ) ) ) );
$u = PDQ_DB::update( 'customers', $r['id'], array( 'city' => 'Howrah' ) );
t( 'update', $u['city'] === 'Howrah' );
$po = PDQ_DB::create( 'purchase_orders', array( 'po_number' => 'PO-1', 'customer_id' => $r['id'], 'po_date' => '2026-11-01' ) );
t( 'create PO', ! is_wp_error( $po ) );
t( 'PO unique constraint', 'pdq_duplicate' === ( PDQ_DB::create( 'purchase_orders', array( 'po_number' => 'PO-1' ) )->code ?? '' ) );
$e1 = PDQ_DB::create( 'engineering_dimensions', array( 'item_code' => 'X1', 'category' => 'Weld Neck Flanges', 'nps' => '4', 'pressure_class' => '150', 'outside_diameter' => '228.6', 'verification_status' => 'unverified' ) );
t( 'engineering create', ! is_wp_error( $e1 ) );
t( 'version history', count( PDQ_DB::versions( 'engineering_dimensions', $e1['id'] ) ) === 1 );
PDQ_DB::update( 'engineering_dimensions', $e1['id'], array( 'outside_diameter' => '229' ) );
t( 'version history grows', count( PDQ_DB::versions( 'engineering_dimensions', $e1['id'] ) ) === 2 );
// Import via mapping + duplicate detection
$rows = array( array( 'Item Code' => 'X2', 'Category' => 'Weld Neck Flanges', 'NPS' => '6', 'Class' => '150', 'OD' => '279.4' ), array( 'Item Code' => 'X2', 'Category' => 'Weld Neck Flanges', 'NPS' => '6', 'Class' => '150', 'OD' => '279.4' ) );
$map = PDQ_Import::auto_map( 'engineering_dimensions', array_keys( $rows[0] ) );
t( 'auto map', $map['OD'] === 'outside_diameter' && $map['Class'] === 'pressure_class' && $map['Item Code'] === 'item_code' );
$res = PDQ_Import::commit( 'engineering_dimensions', $rows, $map, 'skip_duplicates' );
t( 'import inserted 1 skipped 1', $res['inserted'] === 1 && $res['skipped'] === 1 );
$res = PDQ_Import::commit( 'engineering_dimensions', $rows, $map, 'insert_all' );
t( 'dup detection finds group', count( PDQ_DB::find_duplicates() ) === 1 );
// Search + pagination + parser search
$l = PDQ_DB::list_rows( 'engineering_dimensions', array( 'search' => 'Weld', 'per_page' => 2, 'page' => 1 ) );
t( 'pagination/search', $l['total'] === 4 && count( $l['items'] ) === 2 && $l['pages'] === 2 );
$l = PDQ_DB::list_rows( 'engineering_dimensions', array( 'search' => "'; DROP TABLE x;--" ) );
t( 'sql injection safe', $l['total'] === 0 );
$s = PDQ_Parser::search_items( '150NB CL150 WNRF' );
t( 'parser search (no 150NB row => none)', is_array( $s['matches'] ) && count( $s['matches'] ) === 0 );
$s = PDQ_Parser::search_items( '4" CL150 weld neck' );
t( 'parser search finds NPS 4', count( $s['matches'] ) === 1 && $s['matches'][0]['item_code'] === 'X1' );
// Heat duplicate => unverified
$h = PDQ_DB::create( 'heat_numbers', array( 'heat_number' => 'H100', 'verification_status' => 'verified', 'quantity' => 10 ) );
$hd = PDQ_DB::duplicate( 'heat_numbers', $h['id'], array( 'heat_number' => 'H101', 'quantity' => 5 ) );
t( 'duplicated heat must be re-verified', $hd['verification_status'] === 'unverified' && $hd['heat_number'] === 'H101' && (int) $hd['source_heat_id'] === (int) $h['id'] );
// Soft delete / restore / purge rules
t( 'purge requires archive', 'pdq_not_archived' === PDQ_DB::purge( 'customers', $r['id'] )->code );
PDQ_DB::trash( 'customers', $r['id'] );
t( 'archived hidden', PDQ_DB::list_rows( 'customers' )['total'] === 0 && PDQ_DB::list_rows( 'customers', array( 'trashed' => 1 ) )['total'] === 1 );
PDQ_DB::restore( 'customers', $r['id'] ); t( 'restore', PDQ_DB::list_rows( 'customers' )['total'] === 1 );
// Immutable documents
$d = PDQ_DB::create( 'documents', array( 'doc_number' => 'TC-0001', 'doc_type' => 'Test Certificate (TC)', 'immutable' => 1, 'status' => 'issued', 'data_snapshot' => array( 'a' => 1 ) ) );
t( 'document json snapshot', $d['data_snapshot'] === array( 'a' => 1 ) );
t( 'issued doc immutable', 'pdq_immutable' === PDQ_DB::update( 'documents', $d['id'], array( 'status' => 'draft' ) )->code );
// Chem test uniqueness
$ct = PDQ_DB::create( 'chemical_tests', array( 'heat_id' => $h['id'], 'element' => 'C', 'actual_value' => '0.2' ) );
t( 'unique heat/element', 'pdq_duplicate' === PDQ_DB::create( 'chemical_tests', array( 'heat_id' => $h['id'], 'element' => 'C' ) )->code );
// Dashboard from real data
$dash = PDQ_Dashboard::data();
t( 'dashboard KPIs real', $dash['kpi']['engineering_records'] === 4 && $dash['kpi']['documents'] === 1 && $dash['kpi']['test_certificates'] === 1 && count( $dash['monthly'] ) === 12 );
t( 'dashboard alerts', count( $dash['alerts'] ) >= 1 );
// Activity log
t( 'audit log written', PDQ_DB::list_rows( 'activity_logs' )['total'] > 5 );
echo "\n$n tests, $fail failed\n"; exit( $fail ? 1 : 0 );
