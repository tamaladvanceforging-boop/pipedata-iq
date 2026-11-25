<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Template filling engine. Works on a COPY of the uploaded template; never executes macros.
 * DOCX: {{placeholders}} (even when Word split them across runs) + repeating table rows ({{item_*}} / {{items.*}}).
 * XLSX: {{placeholders}} in shared/inline strings. PDF conversion through optional LibreOffice (soffice).
 */
class PDQ_Generator {

	/** Placeholders that, when found inside a table row, make that row repeat once per item. */
	public static function is_item_key( $k ) { return 0 === strpos( $k, 'item_' ) || 0 === strpos( $k, 'items.' ) || in_array( $k, array( 'line_no', 'sr_no', 'quantity', 'heat_number', 'material_grade', 'outside_diameter', 'wall_thickness', 'schedule', 'pressure_class', 'unit_weight', 'line_weight' ), true ); }

	private static function esc( $v ) { return htmlspecialchars( (string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' ); }

	private static function subst( $text, array $vals ) {
		return preg_replace_callback( '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ( $m ) use ( $vals ) {
			$k = strtolower( $m[1] );
			if ( ! array_key_exists( $k, $vals ) || null === $vals[ $k ] ) { return ''; } // Missing => blank, never invented.
			return is_array( $vals[ $k ] ) ? wp_json_encode( $vals[ $k ] ) : (string) $vals[ $k ];
		}, $text );
	}

	/** Replace placeholders paragraph-by-paragraph in WordprocessingML, tolerating split runs. */
	private static function fill_paragraphs( $xml, array $vals ) {
		return preg_replace_callback( '#<w:p[ >].*?</w:p>#s', function ( $pm ) use ( $vals ) {
			$p = $pm[0]; $plain = html_entity_decode( strip_tags( $p ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
			if ( false === strpos( $plain, '{{' ) ) { return $p; }
			$new = self::subst( $plain, $vals ); $first = true;
			return preg_replace_callback( '#(<w:t(?: [^>]*)?>)(.*?)(</w:t>)#s', function ( $tm ) use ( &$first, $new ) {
				if ( $first ) { $first = false; return '<w:t xml:space="preserve">' . self::esc( $new ) . '</w:t>'; }
				return '<w:t></w:t>';
			}, $p );
		}, $xml );
	}

	/** Repeat table rows that hold item placeholders. */
	private static function repeat_rows( $xml, array $items, array $vals ) {
		return preg_replace_callback( '#<w:tr[ >].*?</w:tr>#s', function ( $rm ) use ( $items, $vals ) {
			$row = $rm[0]; $plain = html_entity_decode( strip_tags( $row ) );
			if ( ! preg_match_all( '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', $plain, $m ) ) { return $row; }
			$has = false; foreach ( $m[1] as $k ) { if ( self::is_item_key( strtolower( $k ) ) ) { $has = true; } }
			if ( ! $has ) { return $row; }
			if ( ! $items ) { return self::fill_paragraphs( $row, $vals ); }
			$out = '';
			foreach ( $items as $i => $it ) { $iv = array_merge( $vals, $it, array( 'line_no' => $i + 1, 'sr_no' => $i + 1 ) ); foreach ( $it as $k => $v ) { $iv[ 'items.' . $k ] = $v; $iv[ 'item_' . $k ] = $v; } $out .= self::fill_paragraphs( $row, $iv ); }
			return $out;
		}, $xml );
	}

	/** Build output docx. Returns path or WP_Error. */
	public static function docx( $template_full, $out_full, array $vals, array $items ) {
		if ( ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'pdq_zip', 'PHP ZipArchive is required.', array( 'status' => 500 ) ); }
		if ( ! copy( $template_full, $out_full ) ) { return new WP_Error( 'pdq_copy', 'Cannot create output file.', array( 'status' => 500 ) ); }
		$z = new ZipArchive(); if ( true !== $z->open( $out_full ) ) { return new WP_Error( 'pdq_zip', 'Template could not be opened.', array( 'status' => 422 ) ); }
		for ( $i = 0; $i < $z->numFiles; $i++ ) {
			$n = $z->getNameIndex( $i );
			if ( ! preg_match( '#^word/(document|header\d*|footer\d*)\.xml$#', $n ) ) { continue; }
			$x = $z->getFromIndex( $i ); $x = self::repeat_rows( $x, $items, $vals ); $x = self::fill_paragraphs( $x, $vals ); $z->addFromString( $n, $x );
		}
		$z->deleteName( 'word/vbaProject.bin' ); $z->close();
		return $out_full;
	}

	public static function xlsx( $template_full, $out_full, array $vals, array $items ) {
		if ( ! copy( $template_full, $out_full ) ) { return new WP_Error( 'pdq_copy', 'Cannot create output file.', array( 'status' => 500 ) ); }
		$z = new ZipArchive(); if ( true !== $z->open( $out_full ) ) { return new WP_Error( 'pdq_zip', 'Template could not be opened.', array( 'status' => 422 ) ); }
		$ss = $z->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss ) {
			$ss = preg_replace_callback( '#<t(?: [^>]*)?>(.*?)</t>#s', function ( $m ) use ( $vals ) { $t = html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1 ); return false === strpos( $t, '{{' ) ? $m[0] : '<t xml:space="preserve">' . self::esc( self::subst( $t, $vals ) ) . '</t>'; }, $ss );
			$z->addFromString( 'xl/sharedStrings.xml', $ss );
		}
		$z->deleteName( 'xl/vbaProject.bin' ); $z->close();
		return $out_full;
	}

	/** Optional PDF conversion via LibreOffice headless. */
	public static function pdf( $src_full ) {
		if ( ! function_exists( 'exec' ) || ! self::soffice() ) { return new WP_Error( 'pdq_no_soffice', 'PDF conversion needs LibreOffice (soffice) on the server. Download the DOCX/XLSX and print, or install LibreOffice.', array( 'status' => 501 ) ); }
		$dir = dirname( $src_full ); $cmd = escapeshellarg( self::soffice() ) . ' --headless --norestore --convert-to pdf --outdir ' . escapeshellarg( $dir ) . ' ' . escapeshellarg( $src_full ) . ' 2>&1';
		exec( $cmd, $o, $rc ); $pdf = preg_replace( '/\.\w+$/', '.pdf', $src_full );
		return ( 0 === $rc && file_exists( $pdf ) ) ? $pdf : new WP_Error( 'pdq_pdf', 'PDF conversion failed.', array( 'status' => 500 ) );
	}
	public static function soffice() {
		foreach ( array( 'soffice', 'libreoffice', '/usr/bin/soffice', '/Applications/LibreOffice.app/Contents/MacOS/soffice', 'C:\\Program Files\\LibreOffice\\program\\soffice.exe' ) as $c ) {
			if ( file_exists( $c ) ) { return $c; }
			if ( false === strpos( $c, '/' ) && false === strpos( $c, '\\' ) && function_exists( 'exec' ) ) { $w = array(); @exec( ( stripos( PHP_OS, 'WIN' ) === 0 ? 'where ' : 'which ' ) . $c . ' 2>/dev/null', $w ); if ( ! empty( $w[0] ) ) { return trim( $w[0] ); } }
		}
		return false;
	}

	/** Gather values for a document from DB records (verified data only). */
	public static function collect( array $ctx ) {
		$v = array(); $warn = array();
		if ( ! empty( $ctx['company_id'] ) ) { $c = PDQ_DB::get( 'company_profiles', $ctx['company_id'] ); if ( ! is_wp_error( $c ) ) { $v['company_name'] = $c['company_name']; $v['company_address'] = $c['registered_address']; $v['company_factory_address'] = $c['factory_address']; $v['company_gstin'] = $c['gstin']; $v['company_pan'] = $c['pan']; $v['company_phone'] = $c['contact_number']; $v['company_email'] = $c['email']; $v['company_website'] = $c['website']; $v['signatory_name'] = $c['signatory_name']; $v['signatory_designation'] = $c['signatory_designation']; } }
		if ( ! empty( $ctx['po_id'] ) ) { $p = PDQ_DB::get( 'purchase_orders', $ctx['po_id'] ); if ( ! is_wp_error( $p ) ) { $v['po_number'] = $p['po_number']; $v['po_date'] = $p['po_date']; $v['consignee'] = $p['consignee']; $v['project'] = $p['project']; if ( $p['customer_id'] && empty( $ctx['customer_id'] ) ) { $ctx['customer_id'] = $p['customer_id']; } } }
		if ( ! empty( $ctx['customer_id'] ) ) { $c = PDQ_DB::get( 'customers', $ctx['customer_id'] ); if ( ! is_wp_error( $c ) ) { $v['customer_name'] = $c['name']; $v['customer_address'] = $c['address']; $v['customer_gstin'] = $c['gstin']; } }
		if ( ! empty( $ctx['heat_id'] ) ) {
			$h = PDQ_DB::get( 'heat_numbers', $ctx['heat_id'] );
			if ( ! is_wp_error( $h ) ) {
				$v['heat_number'] = $h['heat_number']; $v['batch_number'] = $h['batch_number']; $v['material_grade'] = $h['material_grade']; $v['specification'] = $h['specification']; $v['mtc_number'] = $h['mtc_number'];
				if ( 'verified' !== $h['verification_status'] ) { $warn[] = 'Heat number ' . $h['heat_number'] . ' is NOT verified. Final issuance is blocked until its test results are verified.'; }
				$chem = PDQ_DB::list_rows( 'chemical_tests', array( 'filters' => array( 'heat_id' => $h['id'] ), 'per_page' => 100, 'orderby' => 'id', 'order' => 'ASC' ) );
				$mech = PDQ_DB::list_rows( 'mechanical_tests', array( 'filters' => array( 'heat_id' => $h['id'] ), 'per_page' => 100, 'orderby' => 'id', 'order' => 'ASC' ) );
				$v['chemical_composition'] = implode( '; ', array_map( function ( $r ) { return $r['element'] . ' ' . $r['actual_value']; }, $chem['items'] ) );
				$v['mechanical_properties'] = implode( '; ', array_map( function ( $r ) { return $r['property'] . ' ' . $r['actual_value'] . ' ' . $r['unit']; }, $mech['items'] ) );
  // NABL: one placeholder per chemical element / mechanical property (value, _min, _max).
  $nf = function ( $x ) { if ( null === $x || '' === $x ) { return ''; } $s = (string) $x; if ( false !== strpos( $s, '.' ) ) { $s = rtrim( rtrim( $s, '0' ), '.' ); } return '' === $s ? '0' : $s; };
  foreach ( $chem['items'] as $r ) {
   $k = 'chem_' . preg_replace( '/[^A-Za-z0-9]/', '', $r['element'] );
   $v[ $k ] = $nf( $r['actual_value'] ); $v[ $k . '_min' ] = $nf( $r['min_limit'] ); $v[ $k . '_max' ] = $nf( $r['max_limit'] );
  }
  foreach ( $mech['items'] as $r ) {
   $k = 'mech_' . trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( $r['property'] ) ), '_' );
   $v[ $k ] = $nf( $r['actual_value'] ); $v[ $k . '_min' ] = $nf( $r['min_limit'] ); $v[ $k . '_max' ] = $nf( $r['max_limit'] );
  }
				if ( ! $chem['items'] ) { $warn[] = 'No chemical test results recorded for this heat.'; }
				if ( ! $mech['items'] ) { $warn[] = 'No mechanical test results recorded for this heat.'; }
				foreach ( array_merge( $chem['items'], $mech['items'] ) as $t ) { if ( 'fail' === ( $t['result'] ?? '' ) ) { $warn[] = 'A recorded test result is marked FAIL (' . ( $t['element'] ?? $t['property'] ) . ').'; } }
			}
		}
		if ( ! empty( $ctx['dimension_id'] ) ) {
			$d = PDQ_DB::get( 'engineering_dimensions', $ctx['dimension_id'] );
			if ( ! is_wp_error( $d ) ) {
				foreach ( array( 'item_description', 'outside_diameter', 'wall_thickness', 'schedule', 'pressure_class', 'unit_weight', 'nps', 'nb', 'dimensional_standard', 'manufacturing_standard' ) as $k ) { $v[ $k ] = $d[ $k ]; }
				if ( 'verified' !== $d['verification_status'] ) { $warn[] = 'Engineering record ' . $d['item_code'] . ' is not verified.'; }
				if ( null === $d['unit_weight'] || '' === $d['unit_weight'] ) { $warn[] = 'No unit weight in the engineering record; total weight left blank.'; }
			}
		}
		return array( 'values' => $v, 'warnings' => $warn );
	}
}
