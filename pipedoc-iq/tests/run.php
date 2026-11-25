<?php
// Standalone tests for the deterministic engines (no WordPress needed): php tests/run.php
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-pdq-calc.php';
require __DIR__ . '/../includes/class-pdq-parser.php';
$f = 0; $n = 0;
function t( $name, $ok ) { global $f, $n; $n++; if ( ! $ok ) { $f++; echo "FAIL: $name\n"; } else { echo "ok:   $name\n"; } }
t( 'mm->in', abs( PDQ_Calc::mm_to_inch( 25.4 ) - 1 ) < 1e-9 );
t( 'in->mm', abs( PDQ_Calc::inch_to_mm( 2 ) - 50.8 ) < 1e-9 );
t( 'ID', PDQ_Calc::inside_diameter( 114.3, 6.02 ) === 102.26 );
t( 'ID invalid', PDQ_Calc::inside_diameter( 10, 6 ) === null );
// 114.3 x 6.02 steel: ~16.07 kg/m (published nominal for 4" SCH 80 is 16.07)
t( 'weight/m 4in XS', abs( PDQ_Calc::weight_per_meter( 114.3, 8.56 ) - 22.3 ) < 0.2 );
t( 'weight/m invalid', PDQ_Calc::weight_per_meter( 10, 6 ) === null );
$tt = PDQ_Calc::totals( array( array( 'qty' => 50, 'unit_weight' => 2.5 ), array( 'qty' => 10, 'unit_weight' => 1 ) ), 5 );
t( 'totals', $tt['total_qty'] == 60 && $tt['net_weight'] == 135 && $tt['gross_weight'] == 140 );
t( 'tol pass', PDQ_Calc::check_tolerance( 114.3, 114.9, -0.8, 1.6 )['result'] === 'pass' );
t( 'tol fail', PDQ_Calc::check_tolerance( 114.3, 116.5, -0.8, 1.6 )['result'] === 'fail' );
t( 'tol pending (no actual => never invented)', PDQ_Calc::check_tolerance( 114.3, null, -0.8, 1.6 )['result'] === 'pending' );
t( 'limits pass', PDQ_Calc::check_limits( 0.2, null, 0.35 )['result'] === 'pass' );
t( 'limits fail', PDQ_Calc::check_limits( 0.4, null, 0.35 )['result'] === 'fail' );
t( 'limits pending', PDQ_Calc::check_limits( null, 0, 1 )['result'] === 'pending' );
t( 'balance', PDQ_Calc::po_balance( 100, 40 )['balance'] == 60 && PDQ_Calc::po_balance( 10, 12 )['over_dispatched'] );
t( 'length conv', PDQ_Calc::convert_length( 1, 'ft', 'mm' ) == 304.8 );
$p = PDQ_Parser::parse( '100NB CL150 FLANGE WELDNECK RAISED FACE FLANGE (WNRF)' );
t( 'parse product', $p['product'] === 'Weld Neck Flange' );
t( 'parse NB/NPS', $p['nb'] === 100 && $p['nps'] === '4' );
t( 'parse class', $p['pressure_class'] === '150' );
t( 'parse facing', $p['facing'] === 'Raised Face' && $p['abbreviation'] === 'WNRF' );
$p = PDQ_Parser::parse( '2" SCH 40 90 deg LR elbow BW A234 WPB' );
t( 'parse elbow', $p['product'] === 'Elbow' && $p['nps'] === '2' && $p['schedule'] === '40' && $p['angle'] === 90 && in_array( 'Long Radius', $p['modifiers'] ) );
t( 'parse grade', strpos( $p['material_grade'], 'A234' ) === 0 );
$p = PDQ_Parser::parse( 'NPS 6 SORF 300#' );
t( 'parse SORF', $p['product'] === 'Slip-On Flange' && $p['nb'] === 150 && $p['pressure_class'] === '300' );
echo "\n$n tests, $f failed\n"; exit( $f ? 1 : 0 );
