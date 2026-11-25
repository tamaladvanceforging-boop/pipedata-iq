<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Deterministic engineering calculations. No AI involved.
 * All inputs are explicit; nothing is invented. Results are labelled "calculated".
 */
class PDQ_Calc {
	const MM_PER_INCH = 25.4;
	/** Density of carbon/low-alloy steel used ONLY when caller supplies none (kg/m3). Callers should pass the grade's verified density. */
	const DEFAULT_STEEL_DENSITY = 7850.0;

	public static function mm_to_inch( $mm ) { return (float) $mm / self::MM_PER_INCH; }
	public static function inch_to_mm( $in ) { return (float) $in * self::MM_PER_INCH; }

	/** Inside diameter (mm) = OD - 2 x WT. */
	public static function inside_diameter( $od, $wt ) {
		$id = (float) $od - 2 * (float) $wt;
		return $id > 0 ? round( $id, 3 ) : null;
	}

	/** Pipe weight per metre (kg/m) = pi * (OD - WT) * WT * density / 1e6 (OD, WT in mm; density kg/m3). Approximate (nominal wall). */
	public static function weight_per_meter( $od, $wt, $density = null ) {
		$rho = $density ? (float) $density : self::DEFAULT_STEEL_DENSITY;
		if ( $od <= 0 || $wt <= 0 || $wt * 2 >= $od ) { return null; }
		return round( M_PI * ( $od - $wt ) * $wt * $rho / 1e6, 3 );
	}

	/** Totals: lines = [ [qty, unit_weight, packing_weight?] ]. */
	public static function totals( array $lines, $packing_weight = 0 ) {
		$qty = 0.0; $net = 0.0;
		foreach ( $lines as $l ) {
			$q = (float) ( $l['qty'] ?? 0 ); $w = (float) ( $l['unit_weight'] ?? 0 );
			$qty += $q; $net += $q * $w;
		}
		return array( 'total_qty' => round( $qty, 4 ), 'net_weight' => round( $net, 3 ), 'packing_weight' => round( (float) $packing_weight, 3 ), 'gross_weight' => round( $net + (float) $packing_weight, 3 ) );
	}

	/** Tolerance check. Returns pass/fail with limits; null actual => 'pending' (never fabricated). */
	public static function check_tolerance( $nominal, $actual, $tol_min = null, $tol_max = null, $tol_pct = null ) {
		if ( null === $actual || '' === $actual ) { return array( 'result' => 'pending', 'message' => 'No actual measurement entered.' ); }
		$nominal = (float) $nominal; $actual = (float) $actual;
		if ( null !== $tol_pct && '' !== $tol_pct ) {
			$lo = $nominal * ( 1 - abs( (float) $tol_pct ) / 100 ); $hi = $nominal * ( 1 + abs( (float) $tol_pct ) / 100 );
		} else {
			if ( null === $tol_min && null === $tol_max ) { return array( 'result' => 'pending', 'message' => 'No tolerance limits supplied.' ); }
			$lo = null !== $tol_min && '' !== $tol_min ? $nominal + (float) $tol_min : -INF;
			$hi = null !== $tol_max && '' !== $tol_max ? $nominal + (float) $tol_max : INF;
		}
		$ok = $actual >= $lo - 1e-9 && $actual <= $hi + 1e-9;
		return array( 'result' => $ok ? 'pass' : 'fail', 'lower' => is_finite( $lo ) ? round( $lo, 4 ) : null, 'upper' => is_finite( $hi ) ? round( $hi, 4 ) : null, 'actual' => $actual );
	}

	/** Limit check for chemical/mechanical values (min/max absolute limits). */
	public static function check_limits( $actual, $min = null, $max = null ) {
		if ( null === $actual || '' === $actual ) { return array( 'result' => 'pending' ); }
		$a = (float) $actual;
		if ( ( null === $min || '' === $min ) && ( null === $max || '' === $max ) ) { return array( 'result' => 'pending' ); }
		if ( null !== $min && '' !== $min && $a < (float) $min ) { return array( 'result' => 'fail', 'reason' => 'below minimum' ); }
		if ( null !== $max && '' !== $max && $a > (float) $max ) { return array( 'result' => 'fail', 'reason' => 'above maximum' ); }
		return array( 'result' => 'pass' );
	}

	public static function po_balance( $ordered, $dispatched ) {
		$b = (float) $ordered - (float) $dispatched;
		return array( 'ordered' => (float) $ordered, 'dispatched' => (float) $dispatched, 'balance' => round( $b, 4 ), 'over_dispatched' => $b < 0 );
	}

	/** Length conversion between mm, cm, m, in, ft. */
	public static function convert_length( $value, $from, $to ) {
		$f = array( 'mm' => 1, 'cm' => 10, 'm' => 1000, 'in' => 25.4, 'ft' => 304.8 );
		if ( ! isset( $f[ $from ], $f[ $to ] ) ) { return null; }
		return round( (float) $value * $f[ $from ] / $f[ $to ], 6 );
	}
}
