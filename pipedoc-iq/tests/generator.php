<?php
require __DIR__ . '/wp-shim.php';
foreach ( array( 'registry', 'meta', 'files', 'generator' ) as $f ) { require __DIR__ . "/../includes/class-pdq-$f.php"; }
$n = 0; $fail = 0; function t( $name, $ok ) { global $n, $fail; $n++; if ( ! $ok ) { $fail++; echo "FAIL: $name\n"; } else { echo "ok:   $name\n"; } }
$tpl = __DIR__ . '/sample-tc.docx'; $out = sys_get_temp_dir() . '/pdq-out.docx';
$a = PDQ_Files::analyze( $tpl, 'docx' );
$names = array_column( $a['placeholders'], 'name' );
t( 'analyzer finds split-run placeholder', in_array( 'company_name', $names ) && in_array( 'po_number', $names ) );
t( 'analyzer counts table', $a['tables'] === 1 );
t( 'analyzer page A4 portrait/letter detected', ! empty( $a['page']['width_mm'] ) );
$r = PDQ_Generator::docx( $tpl, $out, array( 'company_name' => 'Acme & Co', 'po_number' => 'PO-9', 'certificate_date' => '18-11-2026', 'heat_number' => 'H100' ), array( array( 'item_description' => '4" WNRF', 'quantity' => 5 ), array( 'item_description' => '6" SORF', 'quantity' => 2 ) ) );
t( 'docx generated', $r === $out && file_exists( $out ) );
$z = new ZipArchive(); $z->open( $out ); $x = $z->getFromName( 'word/document.xml' ); $z->close();
$plain = html_entity_decode( strip_tags( str_replace( '</w:p>', "\n", $x ) ) );
t( 'values substituted (escaped &)', false !== strpos( $plain, 'Acme & Co PO PO-9' ) );
t( 'no placeholders left', false === strpos( $plain, '{{' ) );
t( 'repeating rows expanded', substr_count( $x, '<w:tr' ) === 3 && false !== strpos( $plain, '6" SORF' ) && false !== strpos( $plain, '4" WNRF' ) );
t( 'serial numbers', preg_match( '/1\s*\n?\s*4" WNRF/', $plain ) === 1 );
$z = new ZipArchive(); t( 'output valid zip', $z->open( $out ) === true );
echo "\n$n tests, $fail failed\n"; exit( $fail ? 1 : 0 );
