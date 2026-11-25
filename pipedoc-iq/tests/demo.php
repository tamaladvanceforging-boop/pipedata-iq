<?php
require __DIR__ . '/wp-shim.php';
foreach ( array( 'registry', 'meta', 'activity', 'db', 'calc', 'files', 'generator', 'demo' ) as $f ) { require __DIR__ . "/../includes/class-pdq-$f.php"; }
$n = 0; $fail = 0; function t( $name, $ok ) { global $n, $fail; $n++; if ( ! $ok ) { $fail++; echo "FAIL: $name\n"; } else { echo "ok:   $name\n"; } }
global $wpdb;
foreach ( PDQ_Registry::schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) as $k => $sql ) { $wpdb->query( 'DROP TABLE IF EXISTS wp_pdq_' . $k ); $wpdb->query( $sql ); }
$s = PDQ_Demo::load(); t( 'demo loads', ! is_wp_error( $s ) && $s['loaded'] );
t( 'demo counts', $s['counts']['engineering_dimensions'] === 3 && $s['counts']['chemical_tests'] === 5 && $s['counts']['purchase_order_items'] === 2 );
t( 'demo twice refused', is_wp_error( PDQ_Demo::load() ) );
$heat = PDQ_DB::list_rows( 'heat_numbers' )['items'][0]; $dim = PDQ_DB::list_rows( 'engineering_dimensions', array( 'orderby' => 'id', 'order' => 'ASC' ) )['items'][0];
t( 'demo heat unverified', $heat['verification_status'] === 'unverified' );
$chem = PDQ_DB::list_rows( 'chemical_tests', array( 'per_page' => 50 ) )['items']; t( 'demo chem all pass', count( array_filter( $chem, function ( $c ) { return $c['result'] === 'pass'; } ) ) === 5 );
$co = PDQ_DB::create( 'company_profiles', array( 'company_name' => 'Acme Forgings', 'registered_address' => 'Howrah', 'signatory_name' => 'A. Person' ) );
$po = PDQ_DB::list_rows( 'purchase_orders' )['items'][0];
$c = PDQ_Generator::collect( array( 'company_id' => $co['id'], 'po_id' => $po['id'], 'heat_id' => $heat['id'], 'dimension_id' => $dim['id'] ) );
$v = $c['values'];
t( 'collect values', $v['company_name'] === 'Acme Forgings' && $v['po_number'] === 'DEMO-PO-001' && $v['customer_name'] === 'DEMO Customer Pvt Ltd' && $v['heat_number'] === 'DEMO-H-1001' && $v['outside_diameter'] == 114.3 );
t( 'chemistry text', strpos( $v['chemical_composition'], 'C 0.22000' ) !== false || strpos( $v['chemical_composition'], 'C 0.22' ) !== false );
t( 'unverified warning', (bool) array_filter( $c['warnings'], function ( $w ) { return strpos( $w, 'NOT verified' ) !== false; } ) );
$v['certificate_number'] = 'TC/1'; $v['certificate_date'] = '21-11-2026'; $v['quantity'] = 5;
$out = sys_get_temp_dir() . '/demo-out.docx';
PDQ_Generator::docx( __DIR__ . '/../samples/Sample_Test_Certificate.docx', $out, $v, array( array( 'item_description' => $dim['item_description'], 'heat_number' => 'DEMO-H-1001', 'material_grade' => 'ASTM A106 Gr.B', 'quantity' => 5 ) ) );
$z = new ZipArchive(); $z->open( $out ); $x = strip_tags( str_replace( '</w:p>', "\n", $z->getFromName( 'word/document.xml' ) ) );
t( 'docx filled', strpos( $x, 'Acme Forgings' ) !== false && strpos( $x, 'DEMO-PO-001' ) !== false && strpos( $x, '6 m length' ) !== false && strpos( $x, 'Tensile Strength 480' ) !== false && strpos( $x, '{{' ) === false );
$r = PDQ_Demo::remove(); t( 'demo removed', ! $r['loaded'] && PDQ_DB::list_rows( 'heat_numbers' )['total'] === 0 && PDQ_DB::list_rows( 'engineering_dimensions' )['total'] === 0 );
echo "\n$n tests, $fail failed\n"; exit( $fail ? 1 : 0 );
