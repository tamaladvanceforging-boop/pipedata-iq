<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Bulk import/export for any registry entity: CSV, JSON, XLSX (if PhpSpreadsheet is installed). */
class PDQ_Import {

	/** Minimal .xlsx reader (first sheet) used when PhpSpreadsheet is not installed. Returns rows of cell strings or null. */
	private static function read_xlsx_basic( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) { return null; }
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) { return null; }
		$shared = array();
		$ss = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss ) {
			$x = simplexml_load_string( $ss );
			if ( $x ) { foreach ( $x->si as $si ) { $t = ''; if ( isset( $si->t ) ) { $t .= (string) $si->t; } foreach ( $si->r as $r ) { $t .= (string) $r->t; } $shared[] = $t; } }
		}
		$target = 'xl/worksheets/sheet1.xml';
		$wb   = simplexml_load_string( (string) $zip->getFromName( 'xl/workbook.xml' ) );
		$rels = simplexml_load_string( (string) $zip->getFromName( 'xl/_rels/workbook.xml.rels' ) );
		if ( $wb && $rels && isset( $wb->sheets->sheet[0] ) ) {
			$rid = (string) $wb->sheets->sheet[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )['id'];
			foreach ( $rels->Relationship as $rel ) { if ( (string) $rel['Id'] === $rid ) { $t = ltrim( (string) $rel['Target'], '/' ); $target = 0 === strpos( $t, 'xl/' ) ? $t : 'xl/' . $t; } }
		}
		$xml = simplexml_load_string( (string) $zip->getFromName( $target ) );
		$zip->close();
		if ( ! $xml ) { return null; }
		$rows = array(); $max = 0;
		foreach ( $xml->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $c ) {
				if ( ! preg_match( '/^([A-Z]+)/', (string) $c['r'], $m ) ) { continue; }
				$idx = 0; foreach ( str_split( $m[1] ) as $ch ) { $idx = $idx * 26 + ( ord( $ch ) - 64 ); } $idx--;
				$t = (string) $c['t'];
				if ( 's' === $t ) { $v = isset( $shared[ (int) $c->v ] ) ? $shared[ (int) $c->v ] : ''; } elseif ( 'inlineStr' === $t ) { $v = (string) $c->is->t; } else { $v = (string) $c->v; }
				$cells[ $idx ] = $v; $max = max( $max, $idx );
			}
			$rows[] = $cells;
		}
		$out = array();
		foreach ( $rows as $cells ) { $line = array(); for ( $i = 0; $i <= $max; $i++ ) { $line[] = isset( $cells[ $i ] ) ? $cells[ $i ] : ''; } $out[] = $line; }
		return $out;
	}

	/** Read a file into rows [ {header=>value} ]. */
	public static function read_file( $path, $ext ) {
		$ext = strtolower( $ext );
		if ( 'json' === $ext ) {
			$j = json_decode( file_get_contents( $path ), true );
			if ( ! is_array( $j ) ) { return new WP_Error( 'pdq_bad_json', 'Invalid JSON file.', array( 'status' => 422 ) ); }
			return isset( $j['rows'] ) ? $j['rows'] : $j;
		}
		if ( 'csv' === $ext || 'txt' === $ext ) {
			$fh = fopen( $path, 'r' ); if ( ! $fh ) { return new WP_Error( 'pdq_read', 'Cannot read file.', array( 'status' => 500 ) ); }
			$head = null; $rows = array();
			while ( ( $r = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
				if ( null === $head ) { $head = array_map( function ( $h ) { return trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ); }, $r ); continue; }
				if ( ! array_filter( $r, 'strlen' ) ) { continue; }
				$rows[] = array_combine( $head, array_pad( array_slice( $r, 0, count( $head ) ), count( $head ), '' ) );
			}
			fclose( $fh ); return $rows;
		}
		if ( in_array( $ext, array( 'xlsx', 'xls', 'xlsm' ), true ) ) {
			$sheet = null; if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) { $sheet = in_array( $ext, array( 'xlsx', 'xlsm' ), true ) ? self::read_xlsx_basic( $path ) : null; if ( null === $sheet ) { return new WP_Error( 'pdq_no_xlsx', 'Could not read this Excel file. Please save it as .xlsx, or export it as CSV.', array( 'status' => 422 ) ); } }
			if ( null === $sheet ) try {
				$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile( $path ); $reader->setReadDataOnly( true ); // Never loads/executes macros.
				$sheet = $reader->load( $path )->getActiveSheet()->toArray( null, true, true, false );
			} catch ( \Throwable $e ) { return new WP_Error( 'pdq_xlsx', 'Could not read the Excel file.', array( 'status' => 422 ) ); }
			$head = array_map( 'trim', array_map( 'strval', array_shift( $sheet ) ) ); $rows = array();
			foreach ( $sheet as $r ) { if ( ! array_filter( $r, function ( $x ) { return null !== $x && '' !== $x; } ) ) { continue; } $rows[] = array_combine( $head, array_pad( array_slice( $r, 0, count( $head ) ), count( $head ), '' ) ); }
			return $rows;
		}
		return new WP_Error( 'pdq_ext', 'Unsupported file type. Use CSV, JSON or XLSX.', array( 'status' => 415 ) );
	}

	private static function norm( $s ) { return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $s ) ); }

	/** Auto-map source headers to entity columns (by name or alias). */
	public static function auto_map( $entity, array $headers ) {
		$d = PDQ_Registry::get( $entity ); $cols = array_keys( $d['columns'] );
		$alias = array( 'sizeininches' => 'size_inch', 'od' => 'outside_diameter', 'outsidediameter' => 'outside_diameter', 'id' => 'inside_diameter', 'insidediameter' => 'inside_diameter', 'wt' => 'wall_thickness', 'wallthickness' => 'wall_thickness', 'sch' => 'schedule', 'class' => 'pressure_class', 'pressureclass' => 'pressure_class', 'lbs' => 'lbs_rating', 'centertoend' => 'center_to_end', 'endtoend' => 'end_to_end', 'flangeoutsidediameter' => 'flange_od', 'flangethickness' => 'flange_thickness', 'bcd' => 'bolt_circle_dia', 'boltcirclediameter' => 'bolt_circle_dia', 'bolthole diameter' => 'bolt_hole_dia', 'numberofboltholes' => 'bolt_holes', 'raisedfacediameter' => 'raised_face_dia', 'raisedfaceheight' => 'raised_face_height', 'hubdiameter' => 'hub_dia', 'hublength' => 'hub_length', 'borediameter' => 'bore_dia', 'unitweight' => 'unit_weight', 'weightpermeter' => 'weight_per_meter', 'applicabletolerances' => 'tolerances', 'sourcereference' => 'source_reference', 'standardrevision' => 'standard_revision', 'verificationstatus' => 'verification_status', 'itemdescription' => 'item_description', 'materialgrade' => 'material_grade', 'materialtype' => 'material_type', 'manufacturingstandard' => 'manufacturing_standard', 'dimensionalstandard' => 'dimensional_standard', 'itemtype' => 'item_type' );
		$map = array(); $byn = array(); foreach ( $cols as $c ) { $byn[ self::norm( $c ) ] = $c; }
		foreach ( $headers as $h ) { $n = self::norm( $h ); if ( isset( $byn[ $n ] ) ) { $map[ $h ] = $byn[ $n ]; } elseif ( isset( $alias[ $n ] ) && isset( $d['columns'][ $alias[ $n ] ] ) ) { $map[ $h ] = $alias[ $n ]; } else { $map[ $h ] = null; } }
		return $map;
	}

	/** Preview: mapping + first rows + validation, no writes. */
	public static function preview( $entity, array $rows ) {
		if ( ! PDQ_Registry::get( $entity ) ) { return new WP_Error( 'pdq_unknown_entity', 'Unknown entity.', array( 'status' => 404 ) ); }
		$headers = $rows ? array_keys( $rows[0] ) : array();
		return array( 'total_rows' => count( $rows ), 'headers' => $headers, 'mapping' => self::auto_map( $entity, $headers ), 'sample' => array_slice( $rows, 0, 10 ), 'columns' => array_keys( PDQ_Registry::get( $entity )['columns'] ) );
	}

	/**
	 * Commit import inside a transaction.
	 * $mode: skip_duplicates | update | insert_all
	 */
	public static function commit( $entity, array $rows, array $mapping, $mode = 'skip_duplicates' ) {
		global $wpdb;
		$d = PDQ_Registry::get( $entity ); if ( ! $d ) { return new WP_Error( 'pdq_unknown_entity', 'Unknown entity.', array( 'status' => 404 ) ); }
		$res = array( 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
		$wpdb->query( 'START TRANSACTION' );
		foreach ( $rows as $i => $src ) {
			$rec = array();
			foreach ( $mapping as $h => $col ) { if ( $col && array_key_exists( $h, $src ) && '' !== $src[ $h ] ) { $rec[ $col ] = is_string( $src[ $h ] ) ? trim( $src[ $h ] ) : $src[ $h ]; } }
			if ( 'engineering_dimensions' === $entity ) {
				if ( empty( $rec['item_code'] ) ) { $rec['item_code'] = 'IMP-' . strtoupper( substr( md5( wp_json_encode( $rec ) ), 0, 10 ) ); }
				if ( empty( $rec['verification_status'] ) ) { $rec['verification_status'] = 'unverified'; }
				$hash = PDQ_DB::dim_hash( $rec );
				$ex = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . PDQ_Registry::table( $entity ) . ' WHERE workspace_id = %d AND row_hash = %s AND deleted_at IS NULL LIMIT 1', PDQ_DB::workspace_id(), $hash ) );
				if ( $ex && 'insert_all' !== $mode ) {
					if ( 'update' === $mode ) { $r = PDQ_DB::update( $entity, $ex, $rec ); is_wp_error( $r ) ? $res['errors'][] = array( 'row' => $i + 2, 'error' => $r->get_error_message() ) : $res['updated']++; }
					else { $res['skipped']++; }
					continue;
				}
			}
			$r = PDQ_DB::create( $entity, $rec );
			if ( is_wp_error( $r ) ) {
				if ( 'pdq_duplicate' === $r->get_error_code() && 'skip_duplicates' === $mode ) { $res['skipped']++; } else { $res['errors'][] = array( 'row' => $i + 2, 'error' => $r->get_error_message() ); }
			} else { $res['inserted']++; }
			if ( count( $res['errors'] ) > 200 ) { break; }
		}
		$wpdb->query( 'COMMIT' );
		PDQ_Activity::log( 'import', $entity, 0, sprintf( 'Imported %d, updated %d, skipped %d, errors %d', $res['inserted'], $res['updated'], $res['skipped'], count( $res['errors'] ) ) );
		return $res;
	}

	/** Export entity rows (all non-archived) as array. */
	public static function export_rows( $entity, $filters = array(), $search = '' ) {
		$all = array(); $page = 1;
		do {
			$r = PDQ_DB::list_rows( $entity, array( 'page' => $page, 'per_page' => 200, 'filters' => $filters, 'search' => $search, 'orderby' => 'id', 'order' => 'ASC' ) );
			if ( is_wp_error( $r ) ) { return $r; }
			$all = array_merge( $all, $r['items'] ); $page++;
		} while ( $page <= $r['pages'] && $page < 500 );
		return $all;
	}

	public static function to_csv( array $rows ) {
		$fh = fopen( 'php://temp', 'r+' ); if ( ! $rows ) { return ''; }
		$keys = array_keys( $rows[0] ); fputcsv( $fh, $keys, ',', '"', '\\' );
		foreach ( $rows as $r ) {
			$line = array(); foreach ( $keys as $k ) {
				$v = is_array( $r[ $k ] ) ? wp_json_encode( $r[ $k ] ) : (string) $r[ $k ];
				if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $v ) ) { $v = "'" . $v; } // CSV formula-injection guard.
				$line[] = $v;
			}
			fputcsv( $fh, $line, ',', '"', '\\' );
		}
		rewind( $fh ); $s = stream_get_contents( $fh ); fclose( $fh ); return $s;
	}
}
