<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Private file storage, upload validation and template analysis. Uploaded files are untrusted and never executed. */
class PDQ_Files {
	private static $allowed = array(
		'image'    => array( 'png' => array( 'image/png' ), 'jpg' => array( 'image/jpeg' ), 'jpeg' => array( 'image/jpeg' ), 'svg' => array( 'image/svg+xml', 'text/plain', 'text/xml', 'application/xml' ) ),
		'letterhead' => array( 'png' => array( 'image/png' ), 'jpg' => array( 'image/jpeg' ), 'jpeg' => array( 'image/jpeg' ), 'pdf' => array( 'application/pdf' ), 'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream' ) ),
		'template' => array( 'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream' ), 'xlsx' => array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream' ), 'xlsm' => array( 'application/vnd.ms-excel.sheet.macroEnabled.12', 'application/zip', 'application/octet-stream' ), 'pdf' => array( 'application/pdf' ), 'doc' => array( 'application/msword', 'application/octet-stream' ), 'xls' => array( 'application/vnd.ms-excel', 'application/octet-stream' ) ),
		'attachment' => array( 'pdf' => array( 'application/pdf' ), 'png' => array( 'image/png' ), 'jpg' => array( 'image/jpeg' ), 'jpeg' => array( 'image/jpeg' ), 'docx' => array( 'application/zip', 'application/octet-stream' ), 'xlsx' => array( 'application/zip', 'application/octet-stream' ), 'csv' => array( 'text/csv', 'text/plain' ) ),
	);
	const MAX_BYTES = 15728640; // 15 MB

	public static function base_dir() {
		$u = wp_upload_dir(); $dir = trailingslashit( $u['basedir'] ) . 'pdq-private';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
			file_put_contents( $dir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
		}
		return $dir;
	}

	/** Validate and store an uploaded file. Returns relative path + metadata. */
	public static function store( array $file, $kind ) {
		if ( ! isset( self::$allowed[ $kind ] ) ) { return new WP_Error( 'pdq_kind', 'Invalid upload kind.', array( 'status' => 422 ) ); }
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { return new WP_Error( 'pdq_upload', 'No file uploaded.', array( 'status' => 422 ) ); }
		if ( $file['size'] > self::MAX_BYTES ) { return new WP_Error( 'pdq_size', 'File exceeds the 15 MB limit.', array( 'status' => 413 ) ); }
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! isset( self::$allowed[ $kind ][ $ext ] ) ) { return new WP_Error( 'pdq_ext', 'File type not allowed here: .' . $ext, array( 'status' => 415 ) ); }
		$mime = function_exists( 'finfo_open' ) ? finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $file['tmp_name'] ) : $file['type'];
		if ( ! in_array( $mime, self::$allowed[ $kind ][ $ext ], true ) ) { return new WP_Error( 'pdq_mime', 'File content does not match its extension.', array( 'status' => 415 ) ); }
		if ( 'svg' === $ext ) {
			$s = file_get_contents( $file['tmp_name'] );
			if ( preg_match( '/<script|on\w+\s*=|javascript:|<foreignObject|<!ENTITY/i', $s ) ) { return new WP_Error( 'pdq_svg', 'SVG contains active content and was rejected.', array( 'status' => 422 ) ); }
		}
		$sub = gmdate( 'Y/m' ); $dir = self::base_dir() . '/' . $kind . '/' . $sub; wp_mkdir_p( $dir );
		$name = bin2hex( random_bytes( 12 ) ) . '.' . $ext;
		if ( ! move_uploaded_file( $file['tmp_name'], $dir . '/' . $name ) ) { return new WP_Error( 'pdq_move', 'Could not store the file.', array( 'status' => 500 ) ); }
		return array( 'path' => $kind . '/' . $sub . '/' . $name, 'ext' => $ext, 'size' => (int) $file['size'], 'original_name' => sanitize_file_name( $file['name'] ), 'sha256' => hash_file( 'sha256', $dir . '/' . $name ) );
	}

	/** Resolve a stored relative path safely. */
	public static function resolve( $rel ) {
		$base = realpath( self::base_dir() ); $full = realpath( $base . '/' . ltrim( $rel, '/' ) );
		return ( $full && 0 === strpos( $full, $base . DIRECTORY_SEPARATOR ) && is_file( $full ) ) ? $full : false;
	}

	/** Analyze a DOCX/XLSX/XLSM template without executing anything. */
	public static function analyze( $full, $ext ) {
		$ext = strtolower( $ext );
		$res = array( 'format' => $ext, 'placeholders' => array(), 'tables' => 0, 'merged_cells' => 0, 'images' => 0, 'sheets' => array(), 'named_ranges' => array(), 'page' => array(), 'headers_footers' => 0, 'warnings' => array(), 'text_fields' => array() );
		if ( in_array( $ext, array( 'pdf', 'doc', 'xls' ), true ) ) {
			$res['warnings'][] = 'pdf' === $ext ? 'PDF is used as a fixed-layout reference/overlay; fields cannot be auto-detected.' : 'Legacy format: convert to DOCX/XLSX (optional LibreOffice conversion) for field detection.';
			return $res;
		}
		if ( ! class_exists( 'ZipArchive' ) ) { $res['warnings'][] = 'PHP ZipArchive extension is missing.'; return $res; }
		$z = new ZipArchive();
		if ( true !== $z->open( $full ) ) { $res['warnings'][] = 'File could not be opened as an Office document.'; return $res; }
		if ( $z->numFiles > 3000 ) { $z->close(); $res['warnings'][] = 'Archive has too many entries; analysis aborted.'; return $res; }
		$total = 0; $names = array();
		for ( $i = 0; $i < $z->numFiles; $i++ ) { $st = $z->statIndex( $i ); $total += $st['size']; $names[] = $st['name']; }
		if ( $total > 200 * 1024 * 1024 ) { $z->close(); $res['warnings'][] = 'Uncompressed size is unusually large; analysis aborted.'; return $res; }
		foreach ( $names as $n ) {
			if ( preg_match( '#vbaProject\.bin$#i', $n ) ) { $res['warnings'][] = 'Embedded macros detected. They are never executed and will be dropped from generated output.'; }
			if ( preg_match( '#/(charts|diagrams|embeddings|activeX)/#i', $n ) ) { $res['warnings'][] = 'Unsupported feature present (' . basename( dirname( $n ) ) . '): layout may differ after generation.'; }
			if ( preg_match( '#/media/#i', $n ) ) { $res['images']++; }
		}
		$res['warnings'] = array_values( array_unique( $res['warnings'] ) );
		$ph = array();
		if ( 'docx' === $ext ) {
			foreach ( $names as $n ) {
				if ( ! preg_match( '#^word/(document|header\d*|footer\d*)\.xml$#', $n ) ) { continue; }
				$x = $z->getFromName( $n ); if ( false === $x ) { continue; }
				if ( 'word/document.xml' !== $n ) { $res['headers_footers']++; } else {
					$res['tables'] = substr_count( $x, '<w:tbl>' ); $res['merged_cells'] = substr_count( $x, '<w:gridSpan' ) + substr_count( $x, '<w:vMerge' );
					if ( preg_match( '/<w:pgSz[^>]*w:w="(\d+)"[^>]*w:h="(\d+)"([^>]*)/', $x, $m ) ) { $res['page'] = array( 'width_mm' => round( $m[1] / 56.7, 1 ), 'height_mm' => round( $m[2] / 56.7, 1 ), 'orientation' => false !== strpos( $m[3], 'landscape' ) ? 'landscape' : 'portrait' ); }
				}
				preg_match_all( '#<w:p[ >].*?</w:p>#s', $x, $paras );
				foreach ( $paras[0] as $p ) { $text = html_entity_decode( strip_tags( $p ) ); self::collect( $text, $n, $ph, $res ); }
			}
		} elseif ( in_array( $ext, array( 'xlsx', 'xlsm' ), true ) ) {
			$wb = $z->getFromName( 'xl/workbook.xml' );
			if ( $wb ) {
				preg_match_all( '#<sheet [^>]*name="([^"]+)"#', $wb, $s ); $res['sheets'] = array_map( 'html_entity_decode', $s[1] );
				preg_match_all( '#<definedName [^>]*name="([^"]+)"[^>]*>([^<]*)<#', $wb, $dn, PREG_SET_ORDER );
				foreach ( $dn as $d ) { $res['named_ranges'][] = array( 'name' => $d[1], 'ref' => html_entity_decode( $d[2] ) ); }
			}
			$ss = $z->getFromName( 'xl/sharedStrings.xml' );
			if ( $ss ) { preg_match_all( '#<si>(.*?)</si>#s', $ss, $si ); foreach ( $si[1] as $s ) { self::collect( html_entity_decode( strip_tags( $s ) ), 'sharedStrings', $ph, $res ); } }
			foreach ( $names as $n ) {
				if ( preg_match( '#^xl/worksheets/sheet\d+\.xml$#', $n ) ) {
					$x = $z->getFromName( $n );
					if ( preg_match( '/<mergeCells[^>]*count="(\d+)"/', $x, $m ) ) { $res['merged_cells'] += (int) $m[1]; }
					if ( preg_match( '/<pageSetup[^>]*orientation="(\w+)"/', $x, $m ) ) { $res['page']['orientation'] = $m[1]; }
					if ( preg_match( '/<pageSetup[^>]*paperSize="(\d+)"/', $x, $m ) ) { $res['page']['paper_size_code'] = (int) $m[1]; if ( 9 !== (int) $m[1] ) { $res['warnings'][] = 'Sheet is not set to A4 (paper size code 9).'; } }
				}
				if ( preg_match( '#^xl/tables/#', $n ) ) { $res['tables']++; }
			}
		}
		$z->close();
		foreach ( $ph as $name => $locs ) { $res['placeholders'][] = array( 'name' => $name, 'locations' => array_values( array_unique( $locs ) ), 'known' => in_array( $name, PDQ_Meta::placeholders(), true ) ); }
		$res['warnings'] = array_values( array_unique( $res['warnings'] ) );
		return $res;
	}

	private static function collect( $text, $loc, &$ph, &$res ) {
		if ( preg_match_all( '/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $m ) ) { foreach ( $m[1] as $p ) { $ph[ strtolower( $p ) ][] = $loc; } }
		$t = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( $t && strlen( $t ) < 80 && count( $res['text_fields'] ) < 300 && ! in_array( $t, $res['text_fields'], true ) ) { $res['text_fields'][] = $t; }
	}
}
