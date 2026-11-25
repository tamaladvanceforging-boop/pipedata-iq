<?php
/**
 * Plugin Name: PipeDoc IQ Auto Pack
 * Description: Upload an items list (Excel / CSV / Word, no AI) or a PO / BOQ / spec sheet and get the full inspection & dispatch document pack. Report templates are stored in the PipeDoc IQ database (seeded automatically).
 * Version:     1.6.0
 * Author:      Tamal Roy Chowdhury
 * License:     GPL-2.0-or-later
 * Text Domain: pdq-autopack
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PDQAP {
	const VER = '1.6.0';
	const NS  = 'pdqap/v1';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_pdqap_zip', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_pdqap_template', array( __CLASS__, 'template_download' ) );
		add_action( 'admin_init', array( __CLASS__, 'app_buffer' ), 0 );
	}

	private static function ready() {
		return class_exists( 'PDQ_DB' ) && class_exists( 'PDQ_Files' ) && class_exists( 'PDQ_REST' ) && class_exists( 'PDQ_Generator' ) && class_exists( 'PDQ_Registry' );
	}

	/** Templates bundled with this add-on (file => metadata). */
	private static function catalogue() {
		return array(
			array( 'file' => 'Heat_Treatment_Certificate_TEMPLATE.docx', 'name' => 'Heat Treatment Certificate', 'doc_type' => 'Heat Treatment Certificate', 'category' => 'inspection' ),
			array( 'file' => 'Offer_List_TEMPLATE.docx', 'name' => 'Offer List', 'doc_type' => 'Offer List', 'category' => 'inspection' ),
			array( 'file' => 'Visual_Dimensional_INTERNAL_TEMPLATE.xlsx', 'name' => 'Visual & Dimensional Report — Internal', 'doc_type' => 'Visual & Dimensional Report', 'category' => 'inspection' ),
			array( 'file' => 'Visual_Dimensional_JOINT_TEMPLATE.xlsx', 'name' => 'Visual & Dimensional Report — Joint Witness', 'doc_type' => 'Visual & Dimensional Report', 'category' => 'inspection' ),
			array( 'file' => 'Marking_Dimensional_Certificate_TEMPLATE.docx', 'name' => 'Marking & Dimensional Verification Certificate', 'doc_type' => 'Marking Certificate', 'category' => 'inspection' ),
			array( 'file' => 'UT_Report_TEMPLATE.docx', 'name' => 'UT Report (UFDT) — Internal 100%', 'doc_type' => 'UT Report', 'category' => 'ndt' ),
			array( 'file' => 'UT_Joint_Witness_TEMPLATE.docx', 'name' => 'UT Report (UFDT) — Joint Witness 10%', 'doc_type' => 'UT Report', 'category' => 'ndt' ),
			array( 'file' => 'MPI_Internal_TEMPLATE.docx', 'name' => 'MPI Report — Internal 100%', 'doc_type' => 'MPI Report', 'category' => 'ndt' ),
			array( 'file' => 'MPI_Joint_Witness_TEMPLATE.docx', 'name' => 'MPI Report — Joint Witness 10%', 'doc_type' => 'MPI Report', 'category' => 'ndt' ),
			array( 'file' => 'LPT_DP_Test_TEMPLATE.xlsx', 'name' => 'LPI / DP Test Report — Joint Witness', 'doc_type' => 'LPI Report', 'category' => 'ndt' ),
			array( 'file' => 'Test_Piece_Identification_TEMPLATE.docx', 'name' => 'Test Sample Identification & Traceability', 'doc_type' => 'Test Piece Identification', 'category' => 'quality' ),
			array( 'file' => 'Preservation_Packing_Colour_TEMPLATE.docx', 'name' => 'Preservation, Packing & Colour Coding', 'doc_type' => 'Packing & Colour Coding', 'category' => 'dispatch' ),
			array( 'file' => 'MTC_Flange_TEMPLATE.xlsx', 'name' => 'Material Test Certificate (per heat)', 'doc_type' => 'MTC', 'category' => 'quality', 'mode' => 'heat' ),
			array( 'file' => 'NABL_Sample_Request_TEMPLATE.xlsx', 'name' => 'NABL Lab — Sample Test Request', 'doc_type' => 'Lab Request', 'category' => 'quality', 'mode' => 'po' ),
			array( 'file' => 'IBR_Form_III-C_TEMPLATE.docx', 'name' => 'IBR Form III-C (per BOQ item)', 'doc_type' => 'IBR Form III-C', 'category' => 'quality', 'mode' => 'item' ),
		);
	}

	/* ---------------------------------------------------------------- seeding */

	/** Version id for a seeded template file (supports old int format). */
	private static function vid( $e ) { return is_array( $e ) ? (int) ( isset( $e['v'] ) ? $e['v'] : 0 ) : (int) $e; }

	/** Per-workspace option name (workspace 1 keeps the original names). */
	private static function okey( $base ) { $w = (int) PDQ_DB::workspace_id(); return 1 === $w ? $base : $base . '_' . $w; }

	public static function provision() { self::maybe_seed( true ); }

	public static function maybe_seed( $force = false ) {
		if ( ! self::ready() || ( true !== $force && ! current_user_can( 'manage_options' ) ) ) { return; }
		$done = get_option( self::okey( 'pdqap_seeded' ), array() ); if ( ! is_array( $done ) ) { $done = array(); }
		self::ensure_templates();
		$changed = false;
		foreach ( self::catalogue() as $t ) {
			$hash = md5_file( __DIR__ . '/templates/' . $t['file'] );
			if ( ! empty( $done[ $t['file'] ] ) ) {
				$e = $done[ $t['file'] ];
				$v = PDQ_DB::get( 'template_versions', self::vid( $e ) );
				$same = is_array( $e ) && isset( $e['h'] ) && $e['h'] === $hash;
				if ( ! is_wp_error( $v ) && ! empty( $v ) && $same ) { continue; }
				if ( ! is_wp_error( $v ) && ! empty( $v ) && is_array( $e ) && ! empty( $e['t'] ) ) { PDQ_DB::trash( 'templates', (int) $e['t'] ); }
				elseif ( ! is_wp_error( $v ) && ! empty( $v ) && ! is_array( $e ) && ! empty( $v['template_id'] ) ) { PDQ_DB::trash( 'templates', (int) $v['template_id'] ); }
			}
			$r = self::seed_one( $t );
			if ( $r ) { $done[ $t['file'] ] = array( 'v' => $r['v'], 't' => $r['t'], 'h' => $hash ); $changed = true; }
		}
		if ( $changed ) {
			update_option( self::okey( 'pdqap_seeded' ), $done, false );
			// Retire superseded copies of our own seeded templates (never touches the user's own templates).
			global $wpdb; $keep = array(); foreach ( $done as $e ) { if ( is_array( $e ) && ! empty( $e['t'] ) ) { $keep[] = (int) $e['t']; } }
			if ( $keep ) {
				$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::tbl( 'templates' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND description LIKE %s', PDQ_DB::workspace_id(), 'Seeded by PipeDoc IQ Auto Pack%' ) );
				foreach ( (array) $ids as $id ) { if ( ! in_array( (int) $id, $keep, true ) ) { PDQ_DB::trash( 'templates', (int) $id ); } }
			}
		}
	}

	private static function ensure_templates() {
		if ( ! class_exists( 'ZipArchive' ) ) { return; }
		$dir = __DIR__ . '/templates/';
		foreach ( array( 'Visual_Dimensional_INTERNAL_TEMPLATE.xlsx', 'Visual_Dimensional_JOINT_TEMPLATE.xlsx' ) as $f ) { self::patch_vd( $dir . $f ); }
		self::patch_ibr( $dir . 'IBR_Form_III-C_TEMPLATE.docx' );
		$h = $dir . 'Heat_Treatment_Certificate_TEMPLATE.docx';
		if ( ! is_file( $h ) ) { self::build_ht( $h ); }
	}

	private static function obs_of( $it ) {
		$o = array();
		foreach ( array( 'obs_od', 'obs_thk', 'obs_pcd', 'obs_rf_dia', 'obs_rf_height', 'obs_holes', 'obs_hole_dia' ) as $k ) {
			if ( isset( $it[ $k ] ) && '' !== trim( (string) $it[ $k ] ) ) { $o[ $k ] = sanitize_text_field( $it[ $k ] ); }
		}
		return $o;
	}

	/** Observed-row cells (THK, RF height, holes, hole dia) become input placeholders instead of copies of the specified value. */
	private static function patch_vd( $p ) {
		if ( ! is_file( $p ) || ! is_writable( $p ) ) { return; }
		$z = new ZipArchive();
		if ( true !== $z->open( $p ) ) { return; }
		$sh = $z->getFromName( 'xl/worksheets/sheet1.xml' ); $ss = $z->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $sh || false === $ss ) { $z->close(); return; }
		if ( false !== strpos( $ss, 'obs_thk' ) && false === strpos( $sh, 'inlineStr' ) ) { $z->close(); return; }
		preg_match_all( '#<si>(.*?)</si>#s', $ss, $m ); $map = array();
		foreach ( $m[1] as $i => $si ) { if ( preg_match( '/\{\{item_(\d+)_obs_od\}\}/', strip_tags( $si ), $q ) ) { $map[ $i ] = (int) $q[1]; } }
		if ( ! $map ) { $z->close(); return; }
		$cols = array( 1 => 'thk', 4 => 'rf_height', 5 => 'holes', 6 => 'hole_dia' );
		if ( preg_match_all( '#<c r="([A-Z])(\d+)"[^>]*t="s"[^>]*><v>(\d+)</v></c>#', $sh, $cm, PREG_SET_ORDER ) ) {
			foreach ( $cm as $c ) {
				if ( ! isset( $map[ (int) $c[3] ] ) ) { continue; }
				foreach ( $cols as $off => $key ) {
					$ref = chr( ord( $c[1] ) + $off ) . $c[2]; $k = $map[ (int) $c[3] ];
					$sh = preg_replace_callback( '#<c r="' . $ref . '"((?:\s+[a-zA-Z]+="[^"]*")*)\s*(?:/>|>.*?</c>)#s', function ( $mm ) use ( $ref, $k, $key ) {
						$a = preg_replace( '/\s+t="[^"]*"/', '', $mm[1] );
						return '<c r="' . $ref . '"' . $a . ' t="inlineStr"><is><t>{{item_' . $k . '_obs_' . $key . '}}</t></is></c>';
					}, $sh, 1 );
				}
			}
		}
		$cnt = preg_match_all( '#<si[ >]#', $ss );
		$sh = preg_replace_callback( '#<c r="([A-Z]+\d+)"((?:\s+[a-zA-Z]+="[^"]*")*)\s*><is><t[^>]*>(.*?)</t></is></c>#s', function ( $mm ) use ( &$ss, &$cnt ) {
			$a = preg_replace( '/\s+t="[^"]*"/', '', $mm[2] );
			$ss = str_replace( '</sst>', '<si><t>' . $mm[3] . '</t></si></sst>', $ss );
			return '<c r="' . $mm[1] . '"' . $a . ' t="s"><v>' . ( $cnt++ ) . '</v></c>';
		}, $sh );
		$ss = preg_replace( '/\suniqueCount="\d+"/', ' uniqueCount="' . $cnt . '"', $ss, 1 );
		$ss = preg_replace( '/\scount="\d+"/', ' count="' . $cnt . '"', $ss, 1 );
		$z->addFromString( 'xl/sharedStrings.xml', $ss ); $z->addFromString( 'xl/worksheets/sheet1.xml', $sh ); $z->close();
	}

	/** IBR: add the raw-material maker name next to the heat reference. */
	private static function patch_ibr( $p ) {
		if ( ! is_file( $p ) || ! is_writable( $p ) ) { return; }
		$z = new ZipArchive();
		if ( true !== $z->open( $p ) ) { return; }
		$x = $z->getFromName( 'word/document.xml' );
		if ( false === $x || false !== strpos( $x, 'heat_maker' ) ) { $z->close(); return; }
		$pos = strrpos( $x, '{{heat_no}}' );
		if ( false !== $pos ) { $x = substr( $x, 0, $pos ) . '{{heat_no}}   Name of the Maker : {{heat_maker}}' . substr( $x, $pos + 11 ); $z->addFromString( 'word/document.xml', $x ); }
		$z->close();
	}

	/** Heat Treatment Certificate template, generated here so the app can produce it without an uploaded file. */
	private static function build_ht( $path ) {
		$run = function ( $t, $o = array() ) {
			return '<w:r><w:rPr>' . ( ! empty( $o['b'] ) ? '<w:b/>' : '' ) . '<w:sz w:val="' . ( isset( $o['sz'] ) ? $o['sz'] : 20 ) . '"/></w:rPr><w:t xml:space="preserve">' . $t . '</w:t></w:r>';
		};
		$para = function ( $t, $o = array() ) use ( $run ) {
			return '<w:p><w:pPr><w:spacing w:before="40" w:after="40"/>' . ( isset( $o['jc'] ) ? '<w:jc w:val="' . $o['jc'] . '"/>' : '' ) . '</w:pPr>' . $run( $t, $o ) . '</w:p>';
		};
		$cell = function ( $t, $w, $o = array() ) use ( $para ) {
			return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ( ! empty( $o['sh'] ) ? '<w:shd w:val="clear" w:color="auto" w:fill="E8ECF3"/>' : '' ) . '</w:tcPr>' . $para( $t, $o ) . '</w:tc>';
		};
		$tbl = function ( $widths, $rows ) {
			$g = ''; foreach ( $widths as $w ) { $g .= '<w:gridCol w:w="' . $w . '"/>'; }
			$b = ''; foreach ( array( 'top', 'left', 'bottom', 'right', 'insideH', 'insideV' ) as $e ) { $b .= '<w:' . $e . ' w:val="single" w:sz="4" w:space="0" w:color="000000"/>'; }
			return '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum( $widths ) . '" w:type="dxa"/><w:tblBorders>' . $b . '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>' . $g . '</w:tblGrid>' . implode( '', $rows ) . '</w:tbl>';
		};
		$kv = function ( $a, $b, $c, $d ) use ( $cell ) {
			return '<w:tr>' . $cell( $a, 2100, array( 'b' => 1, 'sh' => 1 ) ) . $cell( $b, 2850 ) . $cell( $c, 2100, array( 'b' => 1, 'sh' => 1 ) ) . $cell( $d, 2850 ) . '</w:tr>';
		};
		$info = $tbl( array( 2100, 2850, 2100, 2850 ), array(
			$kv( 'Certificate No.', '{{ht_cert_no}}', 'Date', '{{ht_date}}' ),
			$kv( 'Material received from', '{{ht_received_from}}', 'Purchase Order No. &amp; Date', '{{po_number}}  Dt. {{po_date}}' ),
			$kv( 'Drawing No.', '{{ht_drawing_no}}', 'Material Specification', '{{ht_spec}}' ),
			$kv( 'Customer', '{{customer_name}}', 'Heat treatment process', '{{ht_process}}' ),
			$kv( 'Furnace', '{{ht_furnace}}', 'Temperature (&#176;C)', '{{ht_temp}}' ),
			$kv( 'Soaking time', '{{ht_soak}}', 'Cooling', '{{ht_cooling}}' ),
		) );
		$w = array( 900, 5200, 1300, 2500 );
		$items = $tbl( $w, array(
			'<w:tr>' . $cell( 'Sr No.', 900, array( 'b' => 1, 'sh' => 1 ) ) . $cell( 'Description of material', 5200, array( 'b' => 1, 'sh' => 1 ) ) . $cell( 'Nos', 1300, array( 'b' => 1, 'sh' => 1 ) ) . $cell( 'Heat No.', 2500, array( 'b' => 1, 'sh' => 1 ) ) . '</w:tr>',
			'<w:tr>' . $cell( '{{item_line_no}}', 900 ) . $cell( '{{item_description}}', 5200 ) . $cell( '{{item_quantity}}', 1300 ) . $cell( '{{item_heat_number}}', 2500 ) . '</w:tr>',
		) );
		$body = $para( '{{ht_unit_name}}', array( 'b' => 1, 'sz' => 32, 'jc' => 'center' ) )
			. $para( '{{ht_unit_address}}', array( 'jc' => 'center' ) )
			. $para( 'HEAT TREATMENT CERTIFICATE', array( 'b' => 1, 'sz' => 28, 'jc' => 'center' ) )
			. $info . $para( '' ) . $items . $para( '' )
			. $para( 'Remarks : {{ht_remarks}}' )
			. $para( 'Certified that the above material has been heat treated as per the process stated above.' )
			. $para( '' ) . $para( 'For {{ht_unit_name}}', array( 'b' => 1, 'jc' => 'right' ) ) . $para( '{{ht_signatory}}', array( 'jc' => 'right' ) );
		$doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="900" w:right="900" w:bottom="900" w:left="900" w:header="400" w:footer="400" w:gutter="0"/></w:sectPr></w:body></w:document>';
		$z = new ZipArchive();
		if ( true !== $z->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) { return; }
		$z->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>' );
		$z->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>' );
		$z->addFromString( 'word/document.xml', $doc );
		$z->close();
	}

	public static function seed_one( array $t ) {
		$src = __DIR__ . '/templates/' . $t['file'];
		if ( ! is_file( $src ) ) { return 0; }
		$ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
		$rel = 'template/' . gmdate( 'Y/m' ) . '/' . bin2hex( random_bytes( 12 ) ) . '.' . $ext;
		$dst = PDQ_Files::base_dir() . '/' . $rel;
		wp_mkdir_p( dirname( $dst ) );
		if ( ! copy( $src, $dst ) ) { return 0; }
		$full = PDQ_Files::resolve( $rel ); if ( ! $full ) { return 0; }
		$an   = PDQ_Files::analyze( $full, $ext );
		$tpl  = PDQ_DB::create( 'templates', array( 'name' => $t['name'], 'doc_type' => $t['doc_type'], 'category' => $t['category'], 'file_type' => $ext, 'orientation' => isset( $an['page']['orientation'] ) ? $an['page']['orientation'] : 'portrait', 'status' => 'active', 'description' => 'Seeded by PipeDoc IQ Auto Pack ' . self::VER ) );
		if ( is_wp_error( $tpl ) ) { return 0; }
		$ver = PDQ_DB::create( 'template_versions', array( 'template_id' => $tpl['id'], 'version_no' => 1, 'file_path' => $rel, 'file_hash' => hash_file( 'sha256', $full ), 'analysis' => $an, 'page_settings' => isset( $an['page'] ) ? $an['page'] : array(), 'warnings' => isset( $an['warnings'] ) ? $an['warnings'] : array() ) );
		if ( is_wp_error( $ver ) ) { return 0; }
		foreach ( (array) $an['placeholders'] as $ph ) {
			PDQ_DB::create( 'template_field_mappings', array( 'template_version_id' => $ver['id'], 'placeholder' => $ph['name'], 'location' => implode( ', ', array_slice( (array) $ph['locations'], 0, 3 ) ), 'data_source' => $ph['name'], 'mapped_by' => 'autopack' ) );
		}
		return array( 'v' => (int) $ver['id'], 't' => (int) $tpl['id'] );
	}


	/** Small UX fixes inside the PipeDoc IQ application screen (the compiled app itself is not modified). */
	public static function app_buffer() {
		if ( ! isset( $_GET['page'] ) || 'pipedoc-iq' !== $_GET['page'] || empty( $_GET['pdq_app'] ) || ! current_user_can( 'manage_options' ) ) { return; }
		$js = self::app_js();
		ob_start( function ( $html ) use ( $js ) { $p = strripos( $html, '</body>' ); return false !== $p ? substr_replace( $html, $js, $p, 0 ) : $html . $js; } );
	}

	public static function app_js() {
		$u = wp_make_link_relative( admin_url( 'admin.php?page=pdqap' ) );
		ob_start();
		?>
		<script>
		(function(){
			var U=<?php echo wp_json_encode( $u ); ?>,busy=false;
			function fix(){
				if(busy)return;busy=true;
				try{
					var m=document.querySelector('main');
					if(m){
						var h=m.querySelector('h1,h2');
						if(h&&/^Test Certificates$/i.test(h.textContent.trim())){m.querySelectorAll('div,p,h3').forEach(function(n){if(n.children.length===0&&/^No document archive yet$/.test(n.textContent.trim()))n.textContent='No test certificates yet'})}
						var up=[].slice.call(m.querySelectorAll('button')).filter(function(b){return /Upload template/.test(b.textContent)})[0];
						if(up&&!m.querySelector('[data-pdqap]')){var a=document.createElement('a');a.href=U;a.setAttribute('data-pdqap','1');a.className=up.className;a.style.cssText='text-decoration:none;display:inline-flex;align-items:center;gap:6px;background:#14213d;color:#fff;border-color:#14213d';a.textContent='Auto Pack \u2014 upload a PO';up.parentNode.insertBefore(a,up.parentNode.firstChild)}
					}
					var side=document.querySelector('aside');
					if(side&&!side.querySelector('[data-pdqap]')){var g=[].slice.call(side.querySelectorAll('button')).filter(function(b){return /Document Generator/.test(b.getAttribute('aria-label')||b.title||b.textContent)})[0];
						if(g){var c=g.cloneNode(true);c.setAttribute('data-pdqap','1');c.removeAttribute('aria-current');c.className=c.className.replace(/\bbg-[^\s]+/g,'');var set=function(n){if(n.nodeType===3&&/Document Generator/.test(n.nodeValue))n.nodeValue=n.nodeValue.replace('Document Generator','Auto Pack (PO upload)');n.childNodes&&[].slice.call(n.childNodes).forEach(set)};set(c);if(c.getAttribute('aria-label'))c.setAttribute('aria-label','Auto Pack (PO upload)');if(c.title)c.title='Auto Pack (PO upload)';c.onclick=function(e){e.preventDefault();location.href=U};g.parentNode.insertBefore(c,g)}}
				}catch(e){}
				busy=false;
			}
			new MutationObserver(function(){fix()}).observe(document.body,{childList:true,subtree:true});fix();
		})();
		</script>
		<?php
		return ob_get_clean();
	}


	/* ------------------------------------------------------------------- menu */

	public static function menu() {
		add_submenu_page( 'pipedoc-iq', 'Auto Pack', 'Auto Pack', 'manage_options', 'pdqap', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		$nonce = wp_create_nonce( 'wp_rest' );
		$rest  = wp_make_link_relative( rest_url( self::NS . '/' ) );
		$zip   = wp_create_nonce( 'pdqap_zip' );
		$admin = wp_make_link_relative( admin_url( 'admin-post.php' ) );
		?>
		<style>
		#pdqap{max-width:1100px;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;color:#0f172a}
		#pdqap .pq-hero{background:linear-gradient(135deg,#14213d,#24386a);color:#fff;border-radius:14px;padding:22px 26px;margin:14px 0}
		#pdqap .pq-hero h2{color:#fff;margin:0 0 4px;font-size:24px;font-weight:800;letter-spacing:-.4px}#pdqap .pq-hero p{margin:0;color:#cbd5e1}
		#pdqap .pq-steps{display:flex;gap:8px;margin:14px 0 4px;flex-wrap:wrap}#pdqap .pq-steps span{background:rgba(255,255,255,.12);border-radius:99px;padding:5px 13px;font-size:13px;font-weight:600}#pdqap .pq-steps span.on{background:#f97316}
		#pdqap .pq-card{background:#fff;border:1px solid #e4e4e7;border-radius:14px;padding:20px;margin:14px 0}
		#pdqap .pq-card h2{margin:0 0 10px;font-size:18px}
		#pdqap .button-primary{background:#f97316;border-color:#ea580c;color:#fff;box-shadow:none}#pdqap .button-primary:hover,#pdqap .button-primary:focus{background:#ea580c;border-color:#c2410c;color:#fff}
		#pdqap .button-primary[disabled]{background:#fdba74!important;border-color:#fdba74!important;color:#fff!important}
		#pdqap .pq-drop{display:block;border:2px dashed #cbd5e1;border-radius:12px;padding:26px;text-align:center;background:#f8fafc;cursor:pointer;transition:.15s}#pdqap .pq-drop:hover,#pdqap .pq-drop.over{border-color:#f97316;background:#fff7ed}
		#pdqap .pq-drop b{display:block;font-size:15px}#pdqap .pq-drop small{color:#64748b}#pdqap .pq-drop input{display:none}
		#pdqap .pq-list{margin:10px 0 0;padding:0;list-style:none}#pdqap .pq-list li{display:flex;justify-content:space-between;gap:8px;background:#f1f5f9;border-radius:8px;padding:6px 10px;margin:4px 0;font-size:13px}
		#pdqap .form-table th,#pdqap .form-table td{padding:7px 10px}#pdqap .pq-opt{border:1px solid #e4e4e7;border-radius:12px;padding:14px 16px;margin:12px 0}#pdqap .pq-opt h3{margin:0 0 6px;font-size:15px}#pdqap .pq-opt h3 small{font-weight:400;color:#64748b}
			#pdqap .pq-ok{border:1px solid #bbf7d0;background:#f0fdf4}#pdqap .pq-ok h2{color:#166534}
		#pdqap .pq-docs{list-style:none;margin:10px 0;padding:0;columns:2}#pdqap .pq-docs li{padding:3px 0;break-inside:avoid}#pdqap .pq-docs li:before{content:"\2713";color:#16a34a;font-weight:800;margin-right:8px}
		#pdqap .pq-warn{border:1px solid #fed7aa;background:#fff7ed;border-radius:10px;padding:12px 16px;margin-top:12px}#pdqap .pq-warn h3{margin:0 0 6px;font-size:14px;color:#9a3412}#pdqap .pq-warn ul{margin:0;padding-left:18px}
		#pdqap input:focus,#pdqap select:focus,#pdqap textarea:focus{border-color:#f97316;box-shadow:0 0 0 2px rgba(249,115,22,.3);outline:0}
		#pdqap .pq-spin:after{content:"";display:inline-block;width:13px;height:13px;border:2px solid #f97316;border-top-color:transparent;border-radius:50%;margin-left:8px;vertical-align:-2px;animation:pqs .7s linear infinite}@keyframes pqs{to{transform:rotate(360deg)}}
		#pdqap{--o:#f97316;--n:#0f172a;--m:#64748b;--l:#e8ebf1}
		#pdqap .pq-hero{background:radial-gradient(1200px 300px at 90% -40%,rgba(249,115,22,.35),transparent 60%),linear-gradient(135deg,#0b1226,#1e2a4a);box-shadow:0 10px 30px -12px rgba(15,23,42,.5);position:relative;overflow:hidden}
		#pdqap .pq-hero h2{font-size:30px}
		#pdqap .pq-steps{gap:0;align-items:center}#pdqap .pq-steps span{border-radius:99px;transition:all .25s;position:relative}
		#pdqap .pq-steps span+span{margin-left:22px}#pdqap .pq-steps span+span:before{content:"";position:absolute;left:-20px;top:50%;width:18px;height:2px;background:rgba(255,255,255,.25)}
		#pdqap .pq-steps span.on{box-shadow:0 0 0 4px rgba(249,115,22,.25)}
		#pdqap .pq-card{border:1px solid var(--l);border-radius:18px;padding:24px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px -16px rgba(15,23,42,.18);animation:pqin .35s ease both}
		@keyframes pqin{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
		#pdqap .pq-card h2{font-size:19px;letter-spacing:-.2px;display:flex;align-items:center;gap:10px}
		#pdqap input[type=text],#pdqap input:not([type]),#pdqap select{border:1px solid #d6dbe6;border-radius:10px;padding:7px 11px;min-height:38px;transition:border-color .15s,box-shadow .15s;background:#fff}
		#pdqap input:focus,#pdqap select:focus{border-color:var(--o);box-shadow:0 0 0 3px rgba(249,115,22,.18);outline:0}
		#pdqap input::placeholder{color:#a8b0c0;font-style:italic}
		#pdqap #pdqap-review .form-table{display:block;margin:0}
		#pdqap #pdqap-head{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px 16px;padding:4px 0 10px}
		#pdqap #pdqap-head tr{display:flex;flex-direction:column;gap:5px}
		#pdqap #pdqap-head th,#pdqap #pdqap-head td{display:block;width:auto!important;padding:0!important;text-align:left}
		#pdqap #pdqap-head th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--m)}
		#pdqap #pdqap-head tr:has(th[colspan]){grid-column:1/-1;margin-top:10px}
		#pdqap #pdqap-head th[colspan]{font-size:13px;color:var(--n);padding:8px 0 6px!important;border-bottom:2px solid var(--o)!important;letter-spacing:.02em}
		#pdqap #pdqap-items{border:1px solid var(--l);border-radius:14px;border-collapse:separate;border-spacing:0;overflow:hidden;display:block;overflow-x:auto;background:#fff}
		#pdqap #pdqap-items th{background:#f6f8fb;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--m);padding:11px 10px;white-space:nowrap;border-bottom:1px solid var(--l);position:sticky;top:0}
		#pdqap #pdqap-items td{padding:8px 8px;border-bottom:1px solid #f0f2f6}
		#pdqap #pdqap-items tbody tr:hover td{background:#fffaf5}
		#pdqap #pdqap-items th:nth-child(n+7):not(:last-child){background:#fff1e6;color:#c2410c;border-top:3px solid var(--o)}
		#pdqap #pdqap-items td:nth-child(n+7):not(:last-child){background:#fffbf7}
		#pdqap #pdqap-items td input{border-radius:8px;min-height:34px}
		#pdqap #pdqap-items td:nth-child(n+7) input{min-width:112px!important}
		#pdqap .pq-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-left:6px;vertical-align:middle;background:#cbd5e1}
		#pdqap .pq-dot.p{background:#f59e0b}#pdqap .pq-dot.a{background:#22c55e}
		#pdqap .pq-legend{display:flex;gap:16px;flex-wrap:wrap;font-size:12.5px;color:var(--m);margin:6px 0 12px;padding:10px 14px;background:#f8fafc;border:1px dashed #d6dbe6;border-radius:12px}
		#pdqap .pq-legend b{color:var(--n)}
		#pdqap .pq-tq{display:flex;gap:14px;margin:0 0 8px;font-size:13px}#pdqap .pq-tq a{text-decoration:none;color:#c2410c;font-weight:600}
		#pdqap #pdqap-tpls{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:8px}
		#pdqap #pdqap-tpls label{margin:0!important;padding:10px 12px;border:1px solid var(--l);border-radius:12px;cursor:pointer;transition:all .15s;background:#fff;font-size:13.5px}
		#pdqap #pdqap-tpls label:hover{border-color:#fdba74}
		#pdqap #pdqap-tpls label:has(input:checked){background:#fff7ed;border-color:var(--o);box-shadow:0 0 0 3px rgba(249,115,22,.12)}
		#pdqap #pdqap-gen{position:sticky;bottom:18px;z-index:20;border-radius:14px!important;padding:6px 28px!important;box-shadow:0 12px 28px -8px rgba(249,115,22,.65)!important;transition:transform .15s}
		#pdqap #pdqap-gen:hover{transform:translateY(-2px)}
		#pdqap .pq-ok h2{color:#15803d}
		#pdqap .pq-docs li{background:#f8fafc;border:1px solid var(--l);border-radius:10px;padding:8px 12px;margin:0 0 6px;break-inside:avoid}
		#pdqap #pdqap-items{display:block;overflow:visible}
		#pdqap #pdqap-items thead{display:none}
		#pdqap #pdqap-items tbody{display:block}
		#pdqap #pdqap-items tbody tr{display:flex;flex-wrap:wrap;gap:10px 12px;padding:14px 16px;border-bottom:1px solid var(--l);align-items:flex-end;transition:background .15s}
		#pdqap #pdqap-items tbody tr:last-child{border-bottom:0}
		#pdqap #pdqap-items tbody tr:hover{background:#fffaf5}
		#pdqap #pdqap-items td{padding:0!important;border:0!important;background:transparent!important}
		#pdqap #pdqap-items td[data-l]:before{content:attr(data-l);display:block;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--m);margin-bottom:4px}
		#pdqap #pdqap-items td:first-child{flex:0 0 38px;align-self:center;font-weight:700;color:var(--n)}
		#pdqap #pdqap-items td:first-child:before{display:none}
		#pdqap #pdqap-items td:nth-child(2){flex:0 1 130px}
		#pdqap #pdqap-items td:nth-child(3){flex:1 1 300px}
		#pdqap #pdqap-items td:nth-child(4){flex:0 1 110px}
		#pdqap #pdqap-items td:nth-child(5){flex:0 1 90px}
		#pdqap #pdqap-items td:nth-child(6){flex:0 1 140px}
		#pdqap #pdqap-items td input{width:100%!important;min-width:0!important}
		#pdqap #pdqap-items td.pq-br{flex:1 0 100%;font-size:12px;font-weight:700;color:#c2410c;border-top:1px dashed #fdba74!important;padding-top:8px!important;margin-top:2px}
		#pdqap #pdqap-items td.pq-br:before{display:none}
		#pdqap #pdqap-items td.pq-ob{flex:1 1 118px}
		#pdqap #pdqap-items td.pq-ob input{background:#fffbf7}
		#pdqap #pdqap-items td:last-child{align-self:center;flex:0 0 auto;margin-left:auto}
		@media(max-width:700px){#pdqap .pq-docs{columns:1}}
		</style>
		<div class="wrap" id="pdqap">
			<hr class="wp-header-end" style="display:none">
			<div class="pq-hero"><h2>Auto Pack</h2><p>Upload your items list (Excel / Word) or the PO, BOQ and spec sheet. Items, heat numbers and every inspection document are prepared for you.</p><div class="pq-steps"><span id="pq-s1" class="on">1 &middot; Upload</span><span id="pq-s2">2 &middot; Review</span><span id="pq-s3">3 &middot; Download</span></div></div>
			<div id="pdqap-msg"></div>
			<div class="pq-card">
				<h2>1. Upload documents</h2>
				<p><label>Company profile&nbsp;<select id="pdqap-company"><option>Loading&hellip;</option></select></label></p>
				<details style="margin:0 0 10px"><summary style="cursor:pointer;font-weight:600">Company details &amp; letterhead (Word)</summary>
					<table class="form-table" style="margin:0"><tbody id="pdqap-co">
						<tr><th style="width:200px">GSTIN</th><td><input data-co="gstin" style="width:100%"></td></tr>
						<tr><th>Factory / works address</th><td><input data-co="factory_address" style="width:100%"></td></tr>
						<tr><th>Authorised signatory</th><td><input data-co="signatory_name" style="width:100%"></td></tr>
						<tr><th>Inspecting authority (IBR)</th><td><input data-co="inspecting_authority" style="width:100%"></td></tr>
						<tr><th>Letterhead (.docx)</th><td><input type="file" id="pdqap-lh" accept=".docx"> <small id="pdqap-lhs"></small> <a href="#" id="pdqap-lhrm" style="display:none">remove</a><br><small>Put your logo, name and address in the Word <b>Header</b> (and footer). Every Word report is issued on it. Excel reports show company name &amp; address as text.</small></td></tr>
					</tbody></table>
					<p><button class="button" id="pdqap-cosave">Save company details</button></p>
				</details>
				<div class="pq-opt"><h3>A. I have my items list (Excel / CSV / Word) <small>&mdash; no AI and no Gemini key needed</small></h3><p style="margin:0 0 8px">Columns: Sl No &middot; Material Code &middot; Item Title &middot; Quantity &middot; HEAT NO &middot; Remarks. Dimensions, tolerances and the heat test data are filled from the database. <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=pdqap_template&_wpnonce=' . wp_create_nonce( 'pdqap_tpl' ) ) ); ?>">Download the Excel template</a></p><p style="margin:0"><input type="file" id="pdqap-ifile" accept=".xlsx,.xlsm,.csv,.txt,.docx"> <button class="button button-primary" id="pdqap-iread">Read items list</button> <span id="pdqap-ibusy"></span></p></div>
				<div class="pq-opt"><h3>B. I have a PO / BOQ / spec sheet (PDF, image, Excel, Word) <small>&mdash; read with Gemini AI</small></h3>
				<label class="pq-drop" id="pdqap-drop"><b>Drop PO / BOQ / spec sheet here, or click to choose</b><small>PDF, Excel, CSV, Word or image &middot; up to 12 MB each</small><input type="file" id="pdqap-files" multiple accept=".pdf,.xlsx,.csv,.txt,.docx,.png,.jpg,.jpeg"></label><ul class="pq-list" id="pdqap-flist"></ul>
				<p><textarea id="pdqap-text" rows="3" style="width:100%" placeholder="Optional: paste PO / BOQ text here"></textarea></p>
				<p><button class="button button-primary" id="pdqap-extract">Read documents with AI</button> <span id="pdqap-busy"></span></p></div>
			</div>
			<div id="pdqap-review" class="pq-card" style="display:none">
				<h2>2. Review extracted data</h2>
				<p style="color:#52525b">Only values found in your file are filled. Blank = not found; fill it in. Heat numbers come from Heat Number Management (matched by material grade).</p>
				<table class="form-table" style="margin:0"><tbody id="pdqap-head"></tbody></table>
				<div style="overflow:auto"><table class="widefat striped" id="pdqap-items"><thead><tr><th>#</th><th>Material code</th><th>Description</th><th>Grade</th><th>Qty</th><th>Heat no.</th><th></th></tr></thead><tbody></tbody></table></div>
				<h2>3. Documents to generate</h2>
				<div id="pdqap-tpls"></div>
				<p><button class="button button-primary button-hero" id="pdqap-gen">Generate document pack</button></p>
			</div>
			<div id="pdqap-out"></div>
			<div class="pq-card">
				<details><summary style="cursor:pointer;font-weight:700;font-size:1.05em">Heat test data &mdash; optional, needed for MTC &amp; IBR Form III-C</summary>
					<p style="color:#52525b">Enter once per heat (or let AI read the mill TC / lab report). Chemical &amp; mechanical values are copied from your certificates only &mdash; never invented. Standard ASTM limits for A105 / F22 are built in.</p>
					<p><input type="file" id="pdqap-hfile" accept=".pdf,.xlsx,.docx,.png,.jpg,.jpeg,.csv,.txt"> <button class="button" id="pdqap-hread">Read test certificate with AI</button> <span id="pdqap-hbusy"></span></p>
					<div id="pdqap-hform"></div>
					<p><button class="button button-primary" id="pdqap-hsave">Save heat</button> <button class="button" id="pdqap-hdel">Delete heat</button></p>
					<div id="pdqap-hlist"></div>
				</details>
			</div>
		</div>
		<script>
		(function(){
			var REST=<?php echo wp_json_encode( $rest ); ?>,NONCE=<?php echo wp_json_encode( $nonce ); ?>,ZIP=<?php echo wp_json_encode( $zip ); ?>,ADMIN=<?php echo wp_json_encode( $admin ); ?>;
			var $=function(i){return document.getElementById(i)},data=null,ctx=null;
			function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
			function msg(t,ok){$('pdqap-msg').innerHTML='<div class="notice notice-'+(ok?'success':'error')+'"><p>'+esc(t)+'</p></div>'}
			function api(path,opt){opt=opt||{};opt.headers=Object.assign({'X-WP-Nonce':NONCE},opt.headers||{});return fetch(REST+path,opt).then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error(j.message||('HTTP '+r.status));return j})})}

				/* company extras + letterhead */
				function loadCo(){var id=$('pdqap-company').value;if(!id)return;api('company?company_id='+id).then(function(c){document.querySelectorAll('[data-co]').forEach(function(i){i.value=c[i.dataset.co]||''});$('pdqap-lhs').textContent=c.has_letterhead?'Letterhead on file (upload again to replace)':'No letterhead uploaded yet';$('pdqap-lhrm').style.display=c.has_letterhead?'inline-block':'none'}).catch(function(){})}
				$('pdqap-company').onchange=loadCo;
				$('pdqap-cosave').onclick=function(e){e.preventDefault();var fd=new FormData();fd.append('company_id',$('pdqap-company').value);document.querySelectorAll('[data-co]').forEach(function(i){fd.append(i.dataset.co,i.value)});if($('pdqap-lh').files[0])fd.append('letterhead',$('pdqap-lh').files[0]);api('company',{method:'POST',body:fd}).then(function(j){msg('Company details saved. '+(j.note||''),true);$('pdqap-lh').value='';loadCo()}).catch(function(e){msg(e.message,false)})};
				$('pdqap-lhrm').onclick=function(e){e.preventDefault();var fd=new FormData();fd.append('company_id',$('pdqap-company').value);fd.append('remove_letterhead','1');api('company',{method:'POST',body:fd}).then(function(){msg('Letterhead removed.',true);loadCo()}).catch(function(e){msg(e.message,false)})};
				/* heat test data */
				var EL=['c','si','mn','p','s','cr','mo','ni','cu','v','al','ce'],ME=['uts','ys','el','ra','hard','grain'],heats={};
				function hin(k,l,w){return '<label style="display:inline-block;margin:2px 8px 2px 0">'+l+'<br><input data-h="'+k+'" style="width:'+(w||110)+'px"></label>'}
				$('pdqap-hform').innerHTML=hin('heat_no','Heat no.')+hin('grade','Grade')+hin('spec','Specification',170)+hin('size','Size',120)+hin('ingot_tc','Ingot TC no./date',170)+hin('maker','Steel maker',140)+hin('process','Process',140)+hin('ht','Heat treatment',300)
					+'<br><b>Ladle (mill TC) %</b><br>'+EL.map(function(e){return hin('ladle.'+e,e.toUpperCase(),60)}).join('')
					+'<br><b>Observed (lab) %</b><br>'+EL.map(function(e){return hin('obs.'+e,e.toUpperCase(),60)}).join('')
					+'<br><b>Mechanical</b> (UTS N/mm&sup2;, YS, El %, RA %, Hardness, Grain)<br>'+ME.map(function(e){return hin('mech.'+e,e.toUpperCase(),80)}).join('');
				function hget(){var o={ladle:{},obs:{},mech:{}};document.querySelectorAll('[data-h]').forEach(function(i){var k=i.dataset.h.split('.');if(k.length>1)o[k[0]][k[1]]=i.value.trim();else o[k[0]]=i.value.trim()});return o}
				function hset(h){document.querySelectorAll('[data-h]').forEach(function(i){var k=i.dataset.h.split('.');i.value=(k.length>1?((h[k[0]]||{})[k[1]]):h[k[0]])||''})}
				function hlist(){var ks=Object.keys(heats);$('pdqap-hlist').innerHTML=ks.length?'<table class="widefat striped"><thead><tr><th>Heat</th><th>Grade</th><th>Chemical</th><th>Mechanical</th><th></th></tr></thead><tbody>'+ks.map(function(k){var h=heats[k];return '<tr><td>'+esc(k)+'</td><td>'+esc(h.grade)+'</td><td>'+(h.ladle&&h.ladle.c?'yes':'-')+'</td><td>'+(h.mech&&h.mech.uts?'yes':'-')+'</td><td><a href="#" data-edit="'+esc(k)+'">edit</a></td></tr>'}).join('')+'</tbody></table>':'<p><em>No heat data saved yet.</em></p>';
					document.querySelectorAll('[data-edit]').forEach(function(a){a.onclick=function(e){e.preventDefault();hset(heats[a.dataset.edit])}})}
				api('heat').then(function(j){heats=j.heats||{};if(Array.isArray(heats))heats={};hlist()}).catch(function(){});
				$('pdqap-hsave').onclick=function(){api('heat',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(hget())}).then(function(j){heats=j.heats;hlist();msg('Heat saved.',true)}).catch(function(e){msg(e.message,false)})};
				$('pdqap-hdel').onclick=function(){var h=hget();h.delete=1;api('heat',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(h)}).then(function(j){heats=j.heats;hlist();hset({});msg('Heat deleted.',true)}).catch(function(e){msg(e.message,false)})};
				$('pdqap-hread').onclick=function(){var f=$('pdqap-hfile').files[0];if(!f){msg('Choose the test certificate first.',false);return}var fd=new FormData();fd.append('file',f);$('pdqap-hbusy').textContent='Reading...';api('heat-extract',{method:'POST',body:fd}).then(function(j){var h=(j.heats||[])[0];if(!h){msg('Nothing found in that file.',false);return}hset(h);msg('Read '+(j.heats.length)+' heat(s). Check the values, then press Save heat.'+(j.heats.length>1?' (first heat shown; others: '+j.heats.map(function(x){return x.heat_no}).join(', ')+' - upload them one at a time)':''),true)}).catch(function(e){msg(e.message,false)}).then(function(){$('pdqap-hbusy').textContent=''})};
			window.pdqapTest=function(d){data=d;render();step(2)};
			function step(n){[1,2,3].forEach(function(i){$('pq-s'+i).className=i<=n?'on':''})}
			var dz=$('pdqap-drop'),fi=$('pdqap-files');
			function flist(){var fs=fi.files,h='';for(var i=0;i<fs.length;i++)h+='<li><span>'+esc(fs[i].name)+'</span><span>'+Math.max(1,Math.round(fs[i].size/1024))+' KB</span></li>';$('pdqap-flist').innerHTML=h}
			fi.onchange=flist;
			['dragenter','dragover'].forEach(function(ev){dz.addEventListener(ev,function(e){e.preventDefault();dz.classList.add('over')})});
			['dragleave','drop'].forEach(function(ev){dz.addEventListener(ev,function(e){e.preventDefault();dz.classList.remove('over')})});
			dz.addEventListener('drop',function(e){if(e.dataTransfer&&e.dataTransfer.files.length){fi.files=e.dataTransfer.files;flist()}});
			api('context').then(function(c){ctx=c;
				$('pdqap-company').innerHTML=c.companies.map(function(x){return '<option value="'+x.id+'">'+esc(x.company_name)+'</option>'}).join('')||'<option value="">— create a company profile first —</option>';
				loadCo();if(!c.seeded)msg('Templates are still being prepared — reload this page once.',false);
			}).catch(function(e){msg(e.message,false)});
			$('pdqap-extract').onclick=function(){
				var fd=new FormData(),n=0,fs=$('pdqap-files').files;for(var i=0;i<fs.length;i++){fd.append('file'+i,fs[i]);n++}
				fd.append('text',$('pdqap-text').value);
				if(!n&&!$('pdqap-text').value.trim()){msg('Choose at least one file or paste text.',false);return}
				$('pdqap-busy').className='pq-spin';$('pdqap-busy').textContent='Reading your documents…';$('pdqap-extract').disabled=true;
				api('extract',{method:'POST',body:fd}).then(function(j){data=j;render();step(2);msg('Documents read. Please review the details below, then generate.',true);$('pdqap-review').scrollIntoView({behavior:'smooth',block:'start'})}).catch(function(e){msg(e.message,false)}).then(function(){$('pdqap-busy').textContent='';$('pdqap-busy').className='';$('pdqap-extract').disabled=false});
			};
			$('pdqap-iread').onclick=function(){
					var f=$('pdqap-ifile').files[0];if(!f){msg('Choose your items list (Excel, CSV or Word) first.',false);return}
					if(!ctx){msg('The page is still loading - try again in a moment.',false);return}
					var fd=new FormData();fd.append('file',f);
					$('pdqap-ibusy').className='pq-spin';$('pdqap-ibusy').textContent='Reading your list…';$('pdqap-iread').disabled=true;
					api('items',{method:'POST',body:fd}).then(function(j){data=j;render();step(2);var w=j.warnings||[];msg('Read '+(j.items||[]).length+' items from your list. Fill in the PO details below, check the table, then generate.'+(w.length?' Please check: '+w.slice(0,6).join(' | ')+(w.length>6?' | ...and '+(w.length-6)+' more':''):''),!w.length);$('pdqap-review').scrollIntoView({behavior:'smooth',block:'start'})}).catch(function(e){msg(e.message,false)}).then(function(){$('pdqap-ibusy').textContent='';$('pdqap-ibusy').className='';$('pdqap-iread').disabled=false});
				};
				function field(k,l,v){return '<tr><th style="width:200px">'+l+'</th><td><input data-k="'+k+'" value="'+esc(v)+'" class="regular-text" style="width:100%"></td></tr>'}
			function enh(){
				var head=document.getElementById('pdqap-head'),items=document.getElementById('pdqap-items'),tp=document.getElementById('pdqap-tpls');
				function sync(){
					function on(v){var c=document.querySelector('.pdqap-t[value^="'+v+'"]');return !c||c.checked}
					var ht=on('Heat_Treatment'),mk=on('IBR_')||on('MTC_');
					head.querySelectorAll('tr').forEach(function(tr){var i=tr.querySelector('input[data-k]'),th=tr.querySelector('th'),k=i?i.dataset.k:'';
						if(/^x_ht_/.test(k))tr.style.display=ht?'':'none';
						else if(k==='x_heat_maker')tr.style.display=mk?'':'none';
						else if(!i&&th){var t=th.textContent;if(/Heat Treatment/.test(t))tr.style.display=ht?'':'none';if(/Raw material/.test(t))tr.style.display=mk?'':'none'}});
					var n=document.querySelectorAll('.pdqap-t:checked').length,g=document.getElementById('pdqap-gen');
					if(g&&!g.disabled)g.textContent='Generate '+n+' document'+(n===1?'':'s')+' →'}
				function dots(){document.querySelectorAll('#pdqap-items tbody tr').forEach(function(tr){var f=0,t=0;tr.querySelectorAll('input[data-c^=obs_]').forEach(function(i){t++;if(i.value.trim())f++});var td=tr.firstElementChild,d=td.querySelector('.pq-dot');if(!d){d=document.createElement('i');td.appendChild(d)}d.className='pq-dot '+(f===0?'z':f===t?'a':'p');d.title='Observed values entered: '+f+' of '+t})}
				var ths=[].slice.call(items.querySelectorAll('thead th')).map(function(t){return t.textContent.replace('Observed ','')});
				items.querySelectorAll('tbody tr').forEach(function(tr){if(tr.getAttribute('data-f'))return;tr.setAttribute('data-f','1');var tds=[].slice.call(tr.children);tds.forEach(function(td,i){td.setAttribute('data-l',ths[i]||'');if(td.querySelector('input[data-c^=obs_]'))td.className='pq-ob'});var br=document.createElement('td');br.className='pq-br';br.textContent='Observed values - measured by the inspector (grey hint = specified)';var first=tr.querySelector('td.pq-ob');if(first)tr.insertBefore(br,first)});
				if(!document.getElementById('pdqap-leg')){var lg=document.createElement('div');lg.id='pdqap-leg';lg.className='pq-legend';lg.innerHTML='<span><b>Orange columns</b> = inspector-measured (Observed) values. Leave blank to keep the cell empty - nothing is guessed.</span><span>Grey <b>Spec:</b> hint = value from the standard for that item.</span><span><i class="pq-dot z"></i> none <i class="pq-dot p"></i> partly <i class="pq-dot a"></i> all observed entered</span>';items.parentNode.insertBefore(lg,items)}
				if(tp&&!document.getElementById('pdqap-tq')){var q=document.createElement('div');q.id='pdqap-tq';q.className='pq-tq';q.innerHTML='<a href="#" data-a="1">Select all</a><a href="#" data-a="0">Clear</a>';tp.parentNode.insertBefore(q,tp);q.onclick=function(e){var a=e.target.closest('a');if(!a)return;e.preventDefault();tp.querySelectorAll('input').forEach(function(c){c.checked=a.dataset.a==='1'});sync()}}
				document.querySelectorAll('.pdqap-t').forEach(function(c){c.onchange=sync});
				if(!items.getAttribute('data-dots')){items.setAttribute('data-dots','1');items.addEventListener('input',dots)}
				dots();sync()}
			function render(){
				var p=data.po||{};
				$('pdqap-head').innerHTML=field('customer_name','Customer',p.customer_name)+field('po_number','PO number',p.po_number)+field('po_date','PO date (DD-MM-YYYY)',p.po_date)+field('qap_number','QAP reference',p.qap_number)+field('qap_number_2','QAP reference 2',p.qap_number_2)+field('consignee','Consignee',p.consignee)+field('inspection_agency','Inspection agency',p.inspection_agency)+'<tr><th colspan="2" style="padding-top:14px">Heat Treatment Certificate details (optional — leave blank to print blank)</th></tr>'+field('x_ht_unit_name','Heat treatment unit name',p.x_ht_unit_name)+field('x_ht_unit_address','Unit address',p.x_ht_unit_address)+field('x_ht_cert_no','Certificate no.',p.x_ht_cert_no)+field('x_ht_date','Certificate date',p.x_ht_date)+field('x_ht_received_from','Material received from',p.x_ht_received_from)+field('x_ht_drawing_no','Drawing no.',p.x_ht_drawing_no)+field('x_ht_spec','Material specification',p.x_ht_spec)+field('x_ht_process','Process (e.g. Normalizing)',p.x_ht_process)+field('x_ht_furnace','Furnace',p.x_ht_furnace)+field('x_ht_temp','Temperature (C)',p.x_ht_temp)+field('x_ht_soak','Soaking time',p.x_ht_soak)+field('x_ht_cooling','Cooling',p.x_ht_cooling)+field('x_ht_remarks','Remarks',p.x_ht_remarks)+field('x_ht_signatory','Signatory',p.x_ht_signatory)+'<tr><th colspan=\"2\" style=\"padding-top:14px\">Raw material (IBR / MTC)</th></tr>'+field('x_heat_maker','Steel maker - IBR Name of the Maker (used when the heat has none)',p.x_heat_maker);
				var hr=document.querySelector('#pdqap-items thead tr');if(hr&&!hr.getAttribute('data-obs')){hr.setAttribute('data-obs','1');var last=hr.lastElementChild;['Observed OD','Observed THK','Observed PCD','Observed RF dia','Observed RF height','Observed holes','Observed hole dia'].forEach(function(l){var th=document.createElement('th');th.textContent=l;th.title='Inspector-measured value for the Visual & Dimensional report (e.g. 318 - 318.4). Leave blank to keep the observed cell empty.';hr.insertBefore(th,last)})}var tb=document.querySelector('#pdqap-items tbody');tb.innerHTML='';
				(data.items||[]).forEach(function(it,i){addRow(it,i)});
				$('pdqap-tpls').innerHTML=ctx.templates.map(function(t){return '<label style="display:block;margin:2px 0"><input type="checkbox" class="pdqap-t" value="'+esc(t.file)+'" checked> '+esc(t.name)+'</label>'}).join('');
				$('pdqap-review').style.display='block';try{enh()}catch(e){}
			}
			function addRow(it,i){var tb=document.querySelector('#pdqap-items tbody'),tr=document.createElement('tr');
				var cols=['material_code','description','grade','quantity','heat_number','obs_od','obs_thk','obs_pcd','obs_rf_dia','obs_rf_height','obs_holes','obs_hole_dia'];
				tr.innerHTML='<td>'+(i+1)+'</td>'+cols.map(function(c){return '<td><input data-c="'+c+'" value="'+esc(it[c])+'" style="width:100%;min-width:'+(c==='description'?'280':'90')+'px"></td>'}).join('')+'<td><a href="#" class="pdqap-del">remove</a></td>';
				tr.querySelector('.pdqap-del').onclick=function(e){e.preventDefault();tr.remove()};tb.appendChild(tr);
				var lastD=null,dEl=tr.querySelector('[data-c=description]');
				function ld(){var dv=dEl.value;if(dv===lastD)return;lastD=dv;api('specs',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({descriptions:[dv]})}).then(function(j){var sp=(j.specs&&j.specs[0])||{};['od','thk','pcd','rf_dia','rf_height','holes','hole_dia'].forEach(function(k){var el=tr.querySelector('[data-c=obs_'+k+']');if(el){el.placeholder=sp[k]?'Spec: '+sp[k]:'Spec: n/a';el.title='Specified value: '+(sp[k]||'not found - enter manually')}})}).catch(function(){})}
				ld();dEl.addEventListener('change',ld)}
			$('pdqap-gen').onclick=function(){
				var po={};document.querySelectorAll('#pdqap-head input').forEach(function(x){po[x.dataset.k]=x.value.trim()});
				var items=[];document.querySelectorAll('#pdqap-items tbody tr').forEach(function(tr){var o={};tr.querySelectorAll('input').forEach(function(x){o[x.dataset.c]=x.value.trim()});if(o.description||o.material_code)items.push(o)});
				var tpls=[].slice.call(document.querySelectorAll('.pdqap-t:checked')).map(function(x){return x.value});
				if(!po.po_number){msg('PO number is required.',false);return}
				if(!items.length){msg('No items found.',false);return}
				$('pdqap-gen').disabled=true;$('pdqap-gen').textContent='Generating…';$('pdqap-gen').classList.add('pq-spin');
				api('generate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({company_id:$('pdqap-company').value,po:po,items:items,templates:tpls})}).then(function(j){
					var h='<div class="pq-card pq-ok"><h2>'+j.docs.length+' documents ready</h2><p><a class="button button-primary button-hero" href="'+ADMIN+'?action=pdqap_zip&token='+encodeURIComponent(j.token)+'&_wpnonce='+encodeURIComponent(ZIP)+'">Download all (ZIP)</a></p><ul class="pq-docs">'+j.docs.map(function(d){return '<li>'+esc(d.name)+' <small style="color:#64748b">'+esc(d.doc_number)+'</small></li>'}).join('')+'</ul>';
					if(j.warnings&&j.warnings.length)h+='<div class="pq-warn"><h3>Check before issuing</h3><ul>'+j.warnings.map(function(w){return '<li>'+esc(w)+'</li>'}).join('')+'</ul></div>';
					$('pdqap-out').innerHTML=h+'</div>';step(3);$('pdqap-out').scrollIntoView({behavior:'smooth',block:'start'});msg('Pack generated as DRAFT documents (see Document Archive).',true);
				}).catch(function(e){msg(e.message,false)}).then(function(){$('pdqap-gen').disabled=false;$('pdqap-gen').classList.remove('pq-spin');$('pdqap-gen').textContent='Generate document pack'});
			};
		})();
		</script>
		<?php
	}

	/* ------------------------------------------------------------------- REST */

	public static function routes() {
		$perm = function () { return current_user_can( 'manage_options' ); };
		register_rest_route( self::NS, '/specs', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'specs' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/context', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'context' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/extract', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'extract' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/items', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'items_file' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/heat', array( array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'heat_list' ), 'permission_callback' => $perm ), array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'heat_save' ), 'permission_callback' => $perm ) ) );
			register_rest_route( self::NS, '/heat-extract', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'heat_extract' ), 'permission_callback' => $perm ) );
			register_rest_route( self::NS, '/company', array( array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'company_get' ), 'permission_callback' => $perm ), array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'company_save' ), 'permission_callback' => $perm ) ) );
			register_rest_route( self::NS, '/generate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'generate' ), 'permission_callback' => $perm ) );
	}

	private static function tbl( $entity ) { return PDQ_Registry::table( $entity ); }

	public static function context() {
		global $wpdb;
		if ( ! self::ready() ) { return new WP_Error( 'pdqap_core', 'PipeDoc IQ core plugin is not active.', array( 'status' => 500 ) ); }
		$ws = PDQ_DB::workspace_id();
		$companies = $wpdb->get_results( $wpdb->prepare( 'SELECT id, company_name FROM ' . self::tbl( 'company_profiles' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL ORDER BY id', $ws ), ARRAY_A );
		$done = get_option( self::okey( 'pdqap_seeded' ), array() );
		$tpl  = array();
		foreach ( self::catalogue() as $t ) { if ( ! empty( $done[ $t['file'] ] ) ) { $tpl[] = array( 'file' => $t['file'], 'name' => $t['name'], 'mode' => isset( $t['mode'] ) ? $t['mode'] : 'po' ); } }
		foreach ( (array) $companies as &$c ) { $x = self::co( (int) $c['id'] ); $c['has_letterhead'] = ! empty( $x['letterhead'] ); } unset( $c );
			return array( 'companies' => (array) $companies, 'templates' => $tpl, 'seeded' => count( $tpl ) === count( self::catalogue() ) );
	}

	/** Turn an uploaded file into Gemini "parts". Spreadsheets / Word are converted to text locally. */
	private static function file_parts( $f ) {
		$ext = strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) );
		if ( empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) || $f['size'] > 12 * 1024 * 1024 ) { return array(); }
		$mime = array( 'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg' );
		if ( isset( $mime[ $ext ] ) ) { return array( array( 'inline_data' => array( 'mime_type' => $mime[ $ext ], 'data' => base64_encode( file_get_contents( $f['tmp_name'] ) ) ) ) ); }
		if ( in_array( $ext, array( 'csv', 'txt' ), true ) ) { return array( array( 'text' => "FILE {$f['name']}:\n" . mb_substr( file_get_contents( $f['tmp_name'] ), 0, 60000 ) ) ); }
		if ( 'xlsx' === $ext ) { return array( array( 'text' => "FILE {$f['name']}:\n" . self::xlsx_text( $f['tmp_name'] ) ) ); }
		if ( 'docx' === $ext ) { return array( array( 'text' => "FILE {$f['name']}:\n" . self::docx_text( $f['tmp_name'] ) ) ); }
		return array();
	}

	private static function docx_text( $path ) {
		$z = new ZipArchive(); if ( true !== $z->open( $path ) ) { return ''; }
		$x = $z->getFromName( 'word/document.xml' ); $z->close();
		if ( ! $x ) { return ''; }
		$x = preg_replace( '#</w:p>|</w:tr>#', "\n", $x ); $x = preg_replace( '#</w:tc>#', " | ", $x );
		return mb_substr( html_entity_decode( strip_tags( $x ), ENT_QUOTES | ENT_XML1, 'UTF-8' ), 0, 60000 );
	}

	private static function xlsx_text( $path ) {
		$z = new ZipArchive(); if ( true !== $z->open( $path ) ) { return ''; }
		$ss = array();
		$sx = $z->getFromName( 'xl/sharedStrings.xml' );
		if ( $sx ) { preg_match_all( '#<si>(.*?)</si>#s', $sx, $m ); foreach ( $m[1] as $si ) { preg_match_all( '#<t[^>]*>(.*?)</t>#s', $si, $tt ); $ss[] = html_entity_decode( implode( '', $tt[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' ); } }
		$out = '';
		for ( $i = 1; $i <= 6; $i++ ) {
			$sh = $z->getFromName( "xl/worksheets/sheet{$i}.xml" ); if ( ! $sh ) { continue; }
			$out .= "\n[sheet {$i}]\n";
			preg_match_all( '#<row[^>]*>(.*?)</row>#s', $sh, $rows );
			foreach ( $rows[1] as $row ) {
				preg_match_all( '#<c ([^>]*?)(?:/>|>(.*?)</c>)#s', $row, $cells, PREG_SET_ORDER ); $line = array();
				foreach ( $cells as $c ) {
					$v = ''; if ( isset( $c[2] ) && preg_match( '#<v>(.*?)</v>#s', $c[2], $vm ) ) { $v = $vm[1]; if ( false !== strpos( $c[1], 't="s"' ) ) { $v = isset( $ss[ (int) $v ] ) ? $ss[ (int) $v ] : ''; } }
					elseif ( isset( $c[2] ) && preg_match( '#<t[^>]*>(.*?)</t>#s', $c[2], $vm ) ) { $v = html_entity_decode( $vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ); }
					if ( '' !== trim( (string) $v ) ) { $line[] = trim( (string) $v ); }
				}
				if ( $line ) { $out .= implode( ' | ', $line ) . "\n"; }
			}
		}
		$z->close();
		return mb_substr( $out, 0, 60000 );
	}

	public static function extract( WP_REST_Request $req ) {
		if ( ! self::ready() ) { return new WP_Error( 'pdqap_core', 'PipeDoc IQ core plugin is not active.', array( 'status' => 500 ) ); }
		$parts = array();
		foreach ( $_FILES as $f ) { if ( is_array( $f ) && isset( $f['name'] ) && ! is_array( $f['name'] ) ) { $parts = array_merge( $parts, self::file_parts( $f ) ); } }
		$txt = trim( (string) $req->get_param( 'text' ) );
		if ( '' !== $txt ) { $parts[] = array( 'text' => "PASTED TEXT:\n" . mb_substr( $txt, 0, 60000 ) ); }
		if ( ! $parts ) { return new WP_Error( 'pdqap_nofile', 'No readable file or text received.', array( 'status' => 422 ) ); }

		$s = PDQ_Gemini::settings();
		if ( empty( $s['enabled'] ) || empty( $s['consent_given'] ) || empty( $s['model'] ) ) {
			return new WP_Error( 'pdqap_ai', 'Gemini AI is not set up, so a PO / PDF cannot be read. If you have your items in Excel, CSV or Word, use option A ("Read items list") - it needs no AI. To read PDFs, open "Gemini AI Settings" in PipeDoc IQ: add your API key, tick consent and select a model.', array( 'status' => 400 ) );
		}
		$prompt = "You read Indian industrial purchase-order / BOQ / spec-sheet documents for a flange and fittings manufacturer. Return ONLY JSON with this shape:\n"
			. '{"po":{"customer_name":null,"po_number":null,"po_date":"DD-MM-YYYY","qap_number":null,"qap_number_2":null,"consignee":null,"inspection_agency":null},"items":[{"line_no":1,"material_code":null,"description":"","grade":null,"quantity":0,"uom":"Nos"}]}' . "\n"
			. "Rules: copy values exactly as written; one entry per BOQ line; grade = material grade such as A105, A182 F22, A350 LF2 only if stated in the line or its spec; if a value is not stated return null. NEVER invent heat numbers, dimensions, test values or approvals.";
		array_unshift( $parts, array( 'text' => $prompt ) );
		$m = new ReflectionMethod( 'PDQ_Gemini', 'request' ); $m->setAccessible( true );
		$j = $m->invoke( null, 'POST', '/models/' . rawurlencode( $s['model'] ) . ':generateContent', array( 'contents' => array( array( 'parts' => $parts ) ), 'generationConfig' => array( 'responseMimeType' => 'application/json', 'temperature' => 0 ) ) );
		if ( is_wp_error( $j ) ) { return $j; }
		$raw = isset( $j['candidates'][0]['content']['parts'][0]['text'] ) ? $j['candidates'][0]['content']['parts'][0]['text'] : '';
		$d   = json_decode( $raw, true );
		if ( ! is_array( $d ) ) { return new WP_Error( 'pdqap_parse', 'Could not read structured data from the documents. Try an Excel/CSV BOQ or clearer PDF.', array( 'status' => 502 ) ); }
		$po = isset( $d['po'] ) && is_array( $d['po'] ) ? $d['po'] : array(); $items = array();
		foreach ( (array) ( isset( $d['items'] ) ? $d['items'] : array() ) as $i => $it ) {
			if ( ! is_array( $it ) ) { continue; }
			$g = isset( $it['grade'] ) ? (string) $it['grade'] : '';
			$items[] = array( 'material_code' => isset( $it['material_code'] ) ? (string) $it['material_code'] : '', 'description' => isset( $it['description'] ) ? (string) $it['description'] : '', 'grade' => $g, 'quantity' => isset( $it['quantity'] ) ? $it['quantity'] : '', 'heat_number' => '' );
		}
		$items = self::assign_heats( $items );
		return array( 'po' => $po, 'items' => $items );
	}

	/* ------------------------------------------------ items list upload (no AI) */

	private static function ent( $s ) { return html_entity_decode( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' ); }

	private static function col_index( $letters ) { $n = 0; foreach ( str_split( $letters ) as $ch ) { $n = $n * 26 + ord( $ch ) - 64; } return $n - 1; }

	private static function col_letter( $i ) { $s = ''; $i++; while ( $i > 0 ) { $m = ( $i - 1 ) % 26; $s = chr( 65 + $m ) . $s; $i = intdiv( $i - 1, 26 ); } return $s; }

	/** Make every row the same width (list of strings). */
	private static function dense( array $rows ) {
		$w = 0; foreach ( $rows as $r ) { if ( $r ) { $w = max( $w, max( array_keys( $r ) ) + 1 ); } }
		$out = array(); foreach ( $rows as $r ) { $d = array_fill( 0, max( $w, 1 ), '' ); foreach ( $r as $k => $v ) { $d[ $k ] = (string) $v; } $out[] = $d; }
		return $out;
	}

	/** Worksheets of an .xlsx as grids (list of rows of cell strings). No PhpSpreadsheet needed. */
	private static function xlsx_grids( $path ) {
		$z = new ZipArchive(); if ( true !== $z->open( $path ) ) { return array(); }
		$ss = array(); $sx = $z->getFromName( 'xl/sharedStrings.xml' );
		if ( $sx ) { preg_match_all( '#<si\b[^>]*>(.*?)</si>#s', $sx, $m ); foreach ( $m[1] as $si ) { preg_match_all( '#<t\b[^>]*>(.*?)</t>#s', preg_replace( '#<rPh\b.*?</rPh>#s', '', $si ), $tt ); $ss[] = self::ent( implode( '', $tt[1] ) ); } }
		$rel = array(); $wr = (string) $z->getFromName( 'xl/_rels/workbook.xml.rels' ); $wb = (string) $z->getFromName( 'xl/workbook.xml' );
		if ( preg_match_all( '#<Relationship\b[^>]*>#', $wr, $rl ) ) { foreach ( $rl[0] as $r ) { if ( preg_match( '#\bId="([^"]+)"#', $r, $i ) && preg_match( '#Target="([^"]+)"#', $r, $t ) ) { $tg = ltrim( $t[1], '/' ); $rel[ $i[1] ] = 0 === strpos( $tg, 'xl/' ) ? $tg : 'xl/' . $tg; } } }
		$paths = array();
		if ( preg_match_all( '#<sheet\b[^>]*>#', $wb, $sh ) ) { foreach ( $sh[0] as $s ) { if ( preg_match( '#r:id="([^"]+)"#', $s, $i ) && isset( $rel[ $i[1] ] ) ) { $paths[] = $rel[ $i[1] ]; } } }
		if ( ! $paths ) { for ( $i = 1; $i <= 5; $i++ ) { $paths[] = "xl/worksheets/sheet{$i}.xml"; } }
		$grids = array();
		foreach ( array_slice( $paths, 0, 6 ) as $p ) {
			$x = $z->getFromName( $p ); if ( false === $x ) { continue; }
			$rows = array();
			preg_match_all( '#<row\b[^>]*>(.*?)</row>#s', $x, $rm );
			foreach ( $rm[1] as $row ) {
				$line = array(); $pos = 0;
				preg_match_all( '#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $row, $cells, PREG_SET_ORDER );
				foreach ( $cells as $c ) {
					$attr = $c[1]; $body = isset( $c[2] ) ? $c[2] : '';
					$col = preg_match( '#\br="([A-Z]+)\d+"#', $attr, $cm ) ? self::col_index( $cm[1] ) : $pos; $pos = $col + 1;
					$v = '';
					if ( '' !== $body ) {
						if ( false !== strpos( $attr, 't="s"' ) && preg_match( '#<v>(.*?)</v>#s', $body, $vm ) ) { $v = isset( $ss[ (int) $vm[1] ] ) ? $ss[ (int) $vm[1] ] : ''; }
						elseif ( false !== strpos( $attr, 't="inlineStr"' ) ) { preg_match_all( '#<t\b[^>]*>(.*?)</t>#s', $body, $tt ); $v = self::ent( implode( '', $tt[1] ) ); }
						elseif ( preg_match( '#<v>(.*?)</v>#s', $body, $vm ) ) { $v = self::ent( $vm[1] ); }
					}
					$line[ $col ] = trim( $v );
				}
				$rows[] = $line;
			}
			if ( $rows ) { $grids[] = self::dense( $rows ); }
		}
		$z->close();
		return $grids;
	}

	/** Tables of a .docx as grids. */
	private static function docx_grids( $path ) {
		$z = new ZipArchive(); if ( true !== $z->open( $path ) ) { return array(); }
		$x = (string) $z->getFromName( 'word/document.xml' ); $z->close();
		$grids = array();
		preg_match_all( '#<w:tbl\b[^>]*>(.*?)</w:tbl>#s', $x, $tb );
		foreach ( $tb[1] as $t ) {
			$rows = array();
			preg_match_all( '#<w:tr\b[^>]*>(.*?)</w:tr>#s', $t, $tr );
			foreach ( $tr[1] as $r ) {
				$line = array(); preg_match_all( '#<w:tc\b[^>]*>(.*?)</w:tc>#s', $r, $tc );
				foreach ( $tc[1] as $c ) { $c = preg_replace( '#</w:p>#', ' ', $c ); $line[] = trim( preg_replace( '/\s+/', ' ', self::ent( strip_tags( $c ) ) ) ); }
				$rows[] = $line;
			}
			if ( $rows ) { $grids[] = self::dense( $rows ); }
		}
		return $grids;
	}

	private static function csv_grids( $path ) {
		$raw = file_get_contents( $path ); if ( false === $raw ) { return array(); }
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) { $raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' ); }
		$first = strtok( $raw, "\n" ); $best = ','; $bn = -1;
		foreach ( array( ',', ';', "\t" ) as $d ) { $n = substr_count( (string) $first, $d ); if ( $n > $bn ) { $bn = $n; $best = $d; } }
		$fh = fopen( 'php://temp', 'r+' ); fwrite( $fh, $raw ); rewind( $fh ); $rows = array();
		while ( ( $r = fgetcsv( $fh, 0, $best, '"', '\\' ) ) !== false ) { $rows[] = array_map( 'trim', array_map( 'strval', $r ) ); }
		fclose( $fh );
		return $rows ? array( self::dense( $rows ) ) : array();
	}

	/** Map a column heading to an item field ('' = not recognised). */
	private static function items_field( $h ) {
		$n = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $h ) ); if ( '' === $n ) { return ''; }
		if ( preg_match( '/heat|batch/', $n ) ) { return 'heat_number'; }
		if ( preg_match( '/qty|quantity|noofpc|orderedqty/', $n ) ) { return 'quantity'; }
		if ( preg_match( '/materialcode|matcode|itemcode|partno|partcode|materialno|productcode|sapcode|^code$/', $n ) ) { return 'material_code'; }
		if ( preg_match( '/^(sl|slno|sno|srno|sr|serial|serialno|lineno|line|itemno|poitem|no|sln)$/', $n ) ) { return 'line_no'; }
		if ( preg_match( '/^(grade|materialgrade|mtlgrade|matlgrade|materialspec)$/', $n ) ) { return 'grade'; }
		if ( preg_match( '/^(uom|unit|units)$/', $n ) ) { return 'uom'; }
		if ( preg_match( '/remark|note/', $n ) ) { return 'remarks'; }
		if ( preg_match( '/title|desc|itemname|particular|shorttext|^material$|^item$|^product$/', $n ) ) { return 'description'; }
		return '';
	}

	private static function grade_from_text( $s ) {
		if ( preg_match( '/\bA\s?-?182\s*(?:GR\.?\s*)?(F\s?\d+[A-Z]?)/i', $s, $m ) ) { return 'A182 ' . strtoupper( str_replace( ' ', '', $m[1] ) ); }
		if ( preg_match( '/\b(?:A\s?-?350\s*)?(LF\s?\d)\b/i', $s, $m ) ) { return 'A350 ' . strtoupper( str_replace( ' ', '', $m[1] ) ); }
		if ( preg_match( '/\b(?:ASTM\s*)?S?A\s?-?105N?\b/i', $s ) ) { return 'A105'; }
		if ( preg_match( '/\bF\s?-?(22|11|91|5|9|12|304L?|316L?|321|347)\b/i', $s, $m ) ) { return 'F' . strtoupper( $m[1] ); }
		return '';
	}

	/** Optional lines above the table such as "PO No: 4500123" or "Customer | ABC Ltd". */
	private static function items_header_block( array $rows ) {
		$rules = array(
			'po_number' => '/^(pono|ponumber|poref|poreference|purchaseorder|purchaseorderno|purchaseordernumber|orderno|ordernumber)$/',
			'po_date' => '/^(podate|orderdate|dateofpo|date)$/',
			'customer_name' => '/^(customer|customername|buyer|client|clientname)$/',
			'consignee' => '/^(consignee|shipto|deliverto)$/',
			'qap_number' => '/^(qap|qapno|qapnumber|qapreference|qapref)$/',
			'qap_number_2' => '/^(qap2|qapno2|qapnumber2|qapreference2)$/',
			'inspection_agency' => '/^(inspection|inspectionagency|inspectionby|tpi|tpiagency|inspectingagency)$/',
		);
		$po = array();
		foreach ( $rows as $row ) {
			foreach ( $row as $ci => $cell ) {
				$cell = trim( (string) $cell ); if ( '' === $cell ) { continue; }
				$val = '';
				if ( false !== strpos( $cell, ':' ) ) { list( $lab, $val ) = array_map( 'trim', explode( ':', $cell, 2 ) ); } else { $lab = $cell; }
				if ( '' === $val ) { for ( $j = $ci + 1; $j < count( $row ); $j++ ) { if ( '' !== trim( (string) $row[ $j ] ) ) { $val = trim( (string) $row[ $j ] ); break; } } }
				$n = preg_replace( '/[^a-z0-9]/', '', strtolower( $lab ) );
				foreach ( $rules as $k => $re ) { if ( empty( $po[ $k ] ) && '' !== $val && preg_match( $re, $n ) ) { $po[ $k ] = $val; break; } }
			}
		}
		if ( ! empty( $po['po_date'] ) && preg_match( '/^\d{5}(\.\d+)?$/', $po['po_date'] ) && (float) $po['po_date'] > 20000 && (float) $po['po_date'] < 70000 ) { $po['po_date'] = gmdate( 'd-m-Y', (int) round( ( (float) $po['po_date'] - 25569 ) * 86400 ) ); }
		return $po;
	}

	/** Find the header row and read the item rows. Returns null when no items table is found. */
	private static function parse_items( array $grid ) {
		$hr = -1; $map = array();
		foreach ( array_slice( $grid, 0, 40, true ) as $ri => $row ) {
			$m = array();
			foreach ( $row as $ci => $cell ) { $f = self::items_field( $cell ); if ( $f && ! in_array( $f, $m, true ) ) { $m[ $ci ] = $f; } }
			$v = array_values( $m );
			if ( in_array( 'description', $v, true ) && ( in_array( 'quantity', $v, true ) || in_array( 'heat_number', $v, true ) ) ) { $hr = $ri; $map = $m; break; }
		}
		if ( $hr < 0 ) { return null; }
		$items = array(); $warn = array();
		foreach ( array_slice( $grid, $hr + 1 ) as $k => $row ) {
			$rn = $hr + 2 + $k;
			$r = array( 'line_no' => '', 'material_code' => '', 'description' => '', 'grade' => '', 'quantity' => '', 'heat_number' => '', 'remarks' => '' );
			foreach ( $map as $ci => $f ) { $r[ $f ] = isset( $row[ $ci ] ) ? trim( (string) $row[ $ci ] ) : ''; }
			$r['description'] = trim( preg_replace( '/\s+/', ' ', $r['description'] ) );
			if ( '' === $r['description'] && '' === $r['material_code'] ) { continue; }
			if ( preg_match( '/^(grand\s+)?total\b/i', $r['description'] ) ) { continue; }
			if ( 0 === stripos( $r['remarks'], 'SAMPLE ROW' ) ) { continue; }
			$q = str_replace( ',', '', $r['quantity'] );
			if ( preg_match( '/-?\d+(?:\.\d+)?/', $q, $qm ) ) { $qv = (float) $qm[0]; $r['quantity'] = floor( $qv ) == $qv ? (string) (int) $qv : (string) $qv; }
			else { $warn[] = 'Row ' . $rn . ': quantity ' . ( '' === $q ? 'is missing.' : '"' . $q . '" is not a number.' ); $r['quantity'] = ''; }
			if ( preg_match( '/[,;\r\n]/', $r['heat_number'] ) ) { $warn[] = 'Row ' . $rn . ': more than one heat number in one line - put one heat number per row.'; }
			$r['heat_number'] = trim( preg_replace( '/\s+/', ' ', $r['heat_number'] ) );
			if ( '' === $r['grade'] ) { $r['grade'] = self::grade_from_text( $r['description'] . ' ' . $r['remarks'] ); }
			$items[] = array( 'material_code' => $r['material_code'], 'description' => $r['description'], 'grade' => $r['grade'], 'quantity' => $r['quantity'], 'heat_number' => $r['heat_number'] );
			if ( count( $items ) >= 2000 ) { $warn[] = 'Only the first 2000 rows were read.'; break; }
		}
		return array( 'po' => self::items_header_block( array_slice( $grid, 0, $hr ) ), 'items' => $items, 'warnings' => $warn );
	}

	/** Items list upload: Excel / CSV / Word table, read locally - no AI, no Gemini key. */
	public static function items_file( WP_REST_Request $req ) {
		if ( ! self::ready() ) { return new WP_Error( 'pdqap_core', 'PipeDoc IQ core plugin is not active.', array( 'status' => 500 ) ); }
		$f = null; foreach ( $_FILES as $x ) { if ( is_array( $x ) && isset( $x['name'] ) && ! is_array( $x['name'] ) ) { $f = $x; break; } }
		if ( ! $f || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) { return new WP_Error( 'pdqap_nofile', 'Choose your items list (Excel, CSV or Word) first.', array( 'status' => 422 ) ); }
		if ( $f['size'] > 12 * 1024 * 1024 ) { return new WP_Error( 'pdqap_big', 'File is larger than 12 MB.', array( 'status' => 422 ) ); }
		$ext = strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'xls', 'doc' ), true ) ) { return new WP_Error( 'pdqap_old', 'Old .xls / .doc files cannot be read. Open the file and use Save As .xlsx (Excel) or .docx (Word).', array( 'status' => 415 ) ); }
		if ( in_array( $ext, array( 'xlsx', 'xlsm' ), true ) ) { $grids = self::xlsx_grids( $f['tmp_name'] ); }
		elseif ( 'docx' === $ext ) { $grids = self::docx_grids( $f['tmp_name'] ); }
		elseif ( in_array( $ext, array( 'csv', 'txt' ), true ) ) { $grids = self::csv_grids( $f['tmp_name'] ); }
		else { return new WP_Error( 'pdqap_ext', 'Use an Excel (.xlsx), CSV or Word (.docx) file.', array( 'status' => 415 ) ); }
		$res = null; $hdr = false; foreach ( $grids as $g ) { $r = self::parse_items( $g ); if ( $r ) { $hdr = true; } if ( $r && $r['items'] ) { $res = $r; break; } }
		if ( ! $res && $hdr ) { return new WP_Error( 'pdqap_noitems', 'The header row was found but there are no item rows. Delete the two SAMPLE ROW lines and type your items under the header.', array( 'status' => 422 ) ); }
		if ( ! $res ) { return new WP_Error( 'pdqap_noitems', 'No items table found. The header row must have these columns: Sl No, Material Code, Item Title, Quantity, HEAT NO, Remarks. Use "Download the Excel template".', array( 'status' => 422 ) ); }
		// Fill only blank heat numbers from Heat Number Management (by grade); heat numbers typed in the file are never changed.
		$blank = array(); foreach ( $res['items'] as $i => $it ) { if ( '' === $it['heat_number'] ) { $blank[ $i ] = $it; } }
		if ( $blank ) { foreach ( self::assign_heats( $blank ) as $i => $it ) { $res['items'][ $i ] = $it; } }
		$left = 0; foreach ( $res['items'] as $it ) { if ( '' === $it['heat_number'] ) { $left++; } }
		if ( $left ) { $res['warnings'][] = $left . ' item(s) have no heat number - type it in the table below (MTC and traceability need it).'; }
		return array( 'po' => $res['po'], 'items' => array_values( $res['items'] ), 'warnings' => $res['warnings'], 'source' => 'file' );
	}

	/** Build a minimal .xlsx (inline strings) from [ sheet name => rows ]. */
	private static function xlsx_build( array $sheets ) {
		$tmp = tempnam( sys_get_temp_dir(), 'pdqap' ); $z = new ZipArchive();
		if ( true !== $z->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) { return ''; }
		$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		$wbs = ''; $wbr = ''; $n = 0;
		foreach ( $sheets as $name => $rows ) {
			$n++; $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wbs .= '<sheet name="' . htmlspecialchars( (string) $name, ENT_XML1 ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$wbr .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$sx = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="8" customWidth="1"/><col min="2" max="2" width="18" customWidth="1"/><col min="3" max="3" width="70" customWidth="1"/><col min="4" max="4" width="12" customWidth="1"/><col min="5" max="5" width="18" customWidth="1"/><col min="6" max="6" width="30" customWidth="1"/></cols><sheetData>';
			foreach ( array_values( $rows ) as $ri => $row ) {
				$sx .= '<row r="' . ( $ri + 1 ) . '">';
				foreach ( array_values( $row ) as $ci => $v ) { if ( '' === (string) $v ) { continue; } $sx .= '<c r="' . self::col_letter( $ci ) . ( $ri + 1 ) . '"' . ( 0 === $ri ? ' s="1"' : '' ) . ' t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars( (string) $v, ENT_XML1 ) . '</t></is></c>'; }
				$sx .= '</row>';
			}
			$z->addFromString( 'xl/worksheets/sheet' . $n . '.xml', $sx . '</sheetData></worksheet>' );
		}
		$z->addFromString( '[Content_Types].xml', $ct . '</Types>' );
		$z->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$z->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbs . '</sheets></workbook>' );
		$z->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbr . '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
		$z->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFED7AA"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>' );
		$z->close(); $b = (string) file_get_contents( $tmp ); @unlink( $tmp );
		return $b;
	}

	public static function template_download() {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : '', 'pdqap_tpl' ) ) { wp_die( 'Not allowed', 403 ); }
		$b = self::xlsx_build( array(
			'Items' => array(
				array( 'Sl No', 'Material Code', 'Item Title', 'Quantity', 'HEAT NO', 'Remarks' ),
				array( '1', '', 'FLANGE, WELD NECK RAISED FACE, 6 INCH, CLASS 150, ASTM A105', '10', 'HEAT-001', 'SAMPLE ROW - delete this row' ),
				array( '2', '', 'FLANGE, BLIND, 4 INCH, CLASS 300, ASTM A105', '5', 'HEAT-001', 'SAMPLE ROW - delete this row' ),
			),
			'Read me' => array(
				array( 'How to use' ),
				array( 'Keep the header row of the Items sheet. One row per item. Delete the two SAMPLE ROW lines.' ),
				array( 'Item Title: write the size, class and type (for example: WELD NECK, 6 INCH, CLASS 150, ASTM A105) so dimensions can be found in the database.' ),
				array( 'HEAT NO: one heat number per row. If you leave it blank, it is taken from Heat Number Management by material grade.' ),
				array( 'Optional: above the header row you may add lines such as "PO No" in one cell and the value in the next cell (also Customer, PO Date, Consignee, QAP No, Inspection Agency).' ),
			),
		) );
		if ( '' === $b ) { wp_die( 'Could not build the template.', 500 ); }
		nocache_headers(); header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ); header( 'Content-Disposition: attachment; filename="PipeDoc-IQ-Items-Template.xlsx"' ); header( 'Content-Length: ' . strlen( $b ) );
		echo $b; exit; // phpcs:ignore
	}


	/** Heat numbers are never invented: pick from Heat Number Management by material grade. */
	private static function assign_heats( array $items ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT heat_number, material_grade, verification_status FROM ' . self::tbl( 'heat_numbers' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL ORDER BY (verification_status = %s) DESC, id DESC', PDQ_DB::workspace_id(), 'verified' ), ARRAY_A );
		foreach ( $items as &$it ) {
			$tok = self::grade_token( $it['grade'] . ' ' . $it['description'] );
			if ( ! $tok ) { continue; }
			foreach ( (array) $rows as $r ) { if ( false !== stripos( (string) $r['material_grade'], $tok ) ) { $it['heat_number'] = $r['heat_number']; break; } }
		}
		return $items;
	}

	private static function grade_token( $s ) {
		if ( preg_match( '/\bF\s?(22|11|91|5|9|12)\b/i', $s, $m ) ) { return 'F' . $m[1]; }
		if ( preg_match( '/LF\s?2/i', $s ) ) { return 'LF2'; }
		if ( preg_match( '/F\s?(304L?|316L?|321|347)/i', $s, $m ) ) { return $m[1]; }
		if ( preg_match( '/A?\s?105/i', $s ) ) { return '105'; }
		return '';
	}


	/* ------------------------------------------------ ASME B16.5 dimensions */

	private static function nb_to_nps() {
		return array( 15 => '1/2', 20 => '3/4', 25 => '1', 32 => '1 1/4', 40 => '1 1/2', 50 => '2', 65 => '2 1/2', 80 => '3', 90 => '3 1/2', 100 => '4', 125 => '5', 150 => '6', 200 => '8', 250 => '10', 300 => '12', 350 => '14', 400 => '16', 450 => '18', 500 => '20', 600 => '24' );
	}

	/** Look up published ASME B16.5 flange dimensions for a BOQ line. Returns [] when the line cannot be parsed unambiguously. */
	public static function specs( WP_REST_Request $r ) {
		$out = array();
		foreach ( (array) $r->get_param( 'descriptions' ) as $d ) { $out[] = (object) self::dims_for( sanitize_text_field( (string) $d ) ); }
		return array( 'specs' => $out );
	}

	private static function dims_for( $desc ) {
		static $db = null;
		if ( null === $db ) { $f = __DIR__ . '/data/flange_b165.json'; $db = is_file( $f ) ? json_decode( file_get_contents( $f ), true ) : array(); }
		if ( empty( $db['dims'] ) ) { return array(); }
		$d = strtoupper( $desc );
		if ( preg_match( '/BL(?:IN)?N?D|BLIND|\bBL\b/', $d ) ) { $type = 'blind'; }
		elseif ( preg_match( '/SOW|SORF|SOFF|SLIP\s?-?ON/', $d ) ) { $type = 'slip-on'; }
		elseif ( preg_match( '/WNRF|WNFF|WELD\s?NECK|\bWN\b/', $d ) ) { $type = 'weld-neck'; }
		else { return array(); }
		$cl = 0;
		if ( preg_match( '/CL\s?(\d{3,4})/', $d, $m ) ) { $cl = (int) $m[1]; }
		elseif ( preg_match( '/(\d{3,4})\s?RATING|RATING\s?(\d{3,4})/', $d, $m ) ) { $cl = (int) ( $m[1] ? $m[1] : $m[2] ); }
		$nps = '';
		if ( preg_match( '/(\d{2,3})\s?NB/', $d, $m ) ) { $map = self::nb_to_nps(); $nps = isset( $map[ (int) $m[1] ] ) ? $map[ (int) $m[1] ] : ''; }
		elseif ( preg_match( '/(\d+(?:\.\d+)?)\s?(?:INCH|NPS|IN\b|")/', $d, $m ) ) {
			$v = (float) $m[1];
			foreach ( (array) $db['nps'] as $k => $dec ) { if ( abs( (float) $dec - $v ) < 0.001 ) { $nps = (string) $k; break; } }
		}
		$key = $type . '|' . $cl . '|' . $nps;
		if ( ! $cl || '' === $nps || ! isset( $db['dims'][ $key ] ) ) { return array(); }
		$v = $db['dims'][ $key ];
		return array( 'od' => $v[0], 'thk' => $v[1], 'pcd' => $v[2], 'rf_dia' => $v[3], 'rf_height' => $v[4], 'holes' => $v[5], 'hole_dia' => $v[6], 'dim_std' => 'ASME B16.5' );
	}

	private static function iso_date( $d ) {
		$d = trim( (string) $d ); if ( '' === $d ) { return null; }
		if ( preg_match( '#^(\d{1,2})[-./](\d{1,2})[-./](\d{4})$#', $d, $m ) ) { return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] ); }
		$t = strtotime( $d ); return $t ? gmdate( 'Y-m-d', $t ) : null;
	}

	private static function find_or_create( $entity, $col, $val, array $extra ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . self::tbl( $entity ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND {$col} = %s LIMIT 1", PDQ_DB::workspace_id(), $val ), ARRAY_A );
		if ( $row ) { return (int) $row['id']; }
		$c = PDQ_DB::create( $entity, array_merge( array( $col => $val ), $extra ) );
		return is_wp_error( $c ) ? $c : (int) $c['id'];
	}

	/* ------------------------------------------- per-company extras + letterhead */

	private static function co( $id ) { $all = get_option( 'pdqap_company', array() ); return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array(); }

	public static function company_get( WP_REST_Request $req ) {
		$x = self::co( (int) $req->get_param( 'company_id' ) );
		return array( 'gstin' => isset( $x['gstin'] ) ? $x['gstin'] : '', 'factory_address' => isset( $x['factory_address'] ) ? $x['factory_address'] : '', 'signatory_name' => isset( $x['signatory_name'] ) ? $x['signatory_name'] : '', 'inspecting_authority' => isset( $x['inspecting_authority'] ) ? $x['inspecting_authority'] : '', 'has_letterhead' => ! empty( $x['letterhead'] ) );
	}

	public static function company_save( WP_REST_Request $req ) {
		if ( ! self::ready() ) { return new WP_Error( 'pdqap_core', 'PipeDoc IQ core plugin is not active.', array( 'status' => 500 ) ); }
		$id = (int) $req->get_param( 'company_id' ); if ( ! $id ) { return new WP_Error( 'pdqap_company', 'Select a company first.', array( 'status' => 422 ) ); }
		$all = get_option( 'pdqap_company', array() ); if ( ! is_array( $all ) ) { $all = array(); }
		$x = isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
		foreach ( array( 'gstin', 'factory_address', 'signatory_name', 'inspecting_authority' ) as $k ) { if ( null !== $req->get_param( $k ) ) { $x[ $k ] = sanitize_text_field( (string) $req->get_param( $k ) ); } }
		$note = '';
		if ( $req->get_param( 'remove_letterhead' ) ) { if ( ! empty( $x['letterhead'] ) ) { @unlink( PDQ_Files::base_dir() . '/' . $x['letterhead'] ); } unset( $x['letterhead'] ); $all[ $id ] = $x; update_option( 'pdqap_company', $all, false ); return array( 'ok' => true, 'has_letterhead' => false ); }
		$f = isset( $_FILES['letterhead'] ) ? $_FILES['letterhead'] : null;
		if ( $f && ! empty( $f['tmp_name'] ) && is_uploaded_file( $f['tmp_name'] ) ) {
			if ( 'docx' !== strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) ) || $f['size'] > 15 * 1024 * 1024 ) { return new WP_Error( 'pdqap_lh', 'Letterhead must be a Word (.docx) file under 15 MB.', array( 'status' => 422 ) ); }
			$z = new ZipArchive(); if ( true !== $z->open( $f['tmp_name'] ) ) { return new WP_Error( 'pdqap_lh', 'That file is not a valid Word document.', array( 'status' => 422 ) ); }
			$has = false; for ( $i = 0; $i < $z->numFiles; $i++ ) { if ( preg_match( '#^word/(header|footer)\d*\.xml$#', $z->getNameIndex( $i ) ) ) { $has = true; break; } } $z->close();
			if ( ! $has ) { return new WP_Error( 'pdqap_lh', 'No header/footer found in this Word file. Put the letterhead (logo, name, address) in the Word Header (and footer) and upload again.', array( 'status' => 422 ) ); }
			$rel = 'letterhead/' . $id . '_' . bin2hex( random_bytes( 8 ) ) . '.docx'; $dst = PDQ_Files::base_dir() . '/' . $rel; wp_mkdir_p( dirname( $dst ) );
			if ( ! move_uploaded_file( $f['tmp_name'], $dst ) ) { return new WP_Error( 'pdqap_lh', 'Could not store the letterhead.', array( 'status' => 500 ) ); }
			if ( ! empty( $x['letterhead'] ) ) { @unlink( PDQ_Files::base_dir() . '/' . $x['letterhead'] ); }
			$x['letterhead'] = $rel; $note = 'Letterhead saved.';
		}
		$all[ $id ] = $x; update_option( 'pdqap_company', $all, false );
		return array( 'ok' => true, 'note' => $note, 'has_letterhead' => ! empty( $x['letterhead'] ) );
	}

	/** Replace the header/footer of a generated .docx with those of the company's letterhead .docx. */
	private static function apply_letterhead( $docx, $lh ) {
		$a = new ZipArchive(); $b = new ZipArchive();
		if ( true !== $b->open( $lh ) ) { return false; }
		if ( true !== $a->open( $docx ) ) { $b->close(); return false; }
		$ldoc = (string) $b->getFromName( 'word/document.xml' ); $lrel = (string) $b->getFromName( 'word/_rels/document.xml.rels' );
		$ddoc = (string) $a->getFromName( 'word/document.xml' ); $drel = (string) $a->getFromName( 'word/_rels/document.xml.rels' ); $ct = (string) $a->getFromName( '[Content_Types].xml' ); $lct = (string) $b->getFromName( '[Content_Types].xml' );
		if ( '' === $ldoc || '' === $ddoc || '' === $drel || '' === $ct ) { $a->close(); $b->close(); return false; }
		// last sectPr of the letterhead
		preg_match_all( '#<w:sectPr\b.*?</w:sectPr>#s', $ldoc, $sm ); $lsect = $sm[0] ? end( $sm[0] ) : '';
		preg_match_all( '#<w:(header|footer)Reference\b[^>]*>#', $lsect, $refs, PREG_SET_ORDER );
		$map = array(); preg_match_all( '#<Relationship\b[^>]*>#', $lrel, $rr ); foreach ( $rr[0] as $r ) { if ( preg_match( '#Id="([^"]+)"#', $r, $i ) && preg_match( '#Target="([^"]+)"#', $r, $t ) ) { $map[ $i[1] ] = $t[1]; } }
		$newrels = ''; $refxml = ''; $n = 0;
		foreach ( $refs as $rf ) {
			if ( ! preg_match( '#r:id="([^"]+)"#', $rf[0], $i ) || ! isset( $map[ $i[1] ] ) ) { continue; }
			$type = preg_match( '#w:type="([^"]+)"#', $rf[0], $ty ) ? $ty[1] : 'default'; $tg = ltrim( $map[ $i[1] ], '/' ); $tg = preg_replace( '#^word/#', '', $tg ); $base = basename( $tg );
			$xml = $b->getFromName( 'word/' . $tg ); if ( false === $xml ) { continue; }
			$n++; $newname = 'lh' . $n . '_' . $base; $kind = $rf[1];
			$a->addFromString( 'word/' . $newname, $xml );
			$hr = $b->getFromName( 'word/_rels/' . $base . '.rels' );
			if ( $hr ) {
				$hr = preg_replace_callback( '#Target="(media/[^"]+)"#', function ( $m ) use ( $b, $a, $n ) { $nm = 'media/lh' . $n . '_' . basename( $m[1] ); $bin = $b->getFromName( 'word/' . $m[1] ); if ( false !== $bin ) { $a->addFromString( 'word/' . $nm, $bin ); } return 'Target="' . $nm . '"'; }, $hr );
				$a->addFromString( 'word/_rels/' . $newname . '.rels', $hr );
			}
			$rid = 'rIdLH' . $n;
			$newrels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/' . $kind . '" Target="' . $newname . '"/>';
			$refxml .= '<w:' . $kind . 'Reference w:type="' . $type . '" r:id="' . $rid . '"/>';
			$ctype = $kind === 'header' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml';
			$ct = str_replace( '</Types>', '<Override PartName="/word/' . $newname . '" ContentType="' . $ctype . '"/></Types>', $ct );
		}
		if ( ! $n ) { $a->close(); $b->close(); return false; }
		// media content types
		foreach ( array( 'png' => 'image/png', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'emf' => 'image/x-emf', 'wmf' => 'image/x-wmf', 'svg' => 'image/svg+xml' ) as $ext => $mt ) { if ( false === stripos( $ct, 'Extension="' . $ext . '"' ) ) { $ct = str_replace( '<Override ', '<Default Extension="' . $ext . '" ContentType="' . $mt . '"/><Override ', $ct ); } }
		// drop the template's own header/footer references, add the letterhead's
		$ddoc = preg_replace( '#<w:(?:header|footer)Reference\b[^>]*/>#', '', $ddoc );
		$ddoc = preg_replace( '#<w:titlePg\s*/>#', '', $ddoc );
		$lpg = array(); if ( preg_match( '#<w:pgMar\b[^>]*>#', $lsect, $pm ) ) { foreach ( array( 'top', 'bottom', 'header', 'footer' ) as $k ) { if ( preg_match( '#w:' . $k . '="(-?\d+)"#', $pm[0], $v ) ) { $lpg[ $k ] = $v[1]; } } }
		$ddoc = preg_replace_callback( '#<w:sectPr\b[^>]*>#', function ( $m ) use ( $refxml ) { return $m[0] . $refxml; }, $ddoc );
		if ( false !== strpos( $lsect, '<w:titlePg' ) ) { $ddoc = preg_replace( '#(</w:sectPr>)#', '<w:titlePg/>$1', $ddoc ); }
		if ( $lpg ) { $ddoc = preg_replace_callback( '#<w:pgMar\b[^>]*/>#', function ( $m ) use ( $lpg ) { $t = $m[0]; foreach ( $lpg as $k => $v ) { $t = preg_match( '#w:' . $k . '="#', $t ) ? preg_replace( '#w:' . $k . '="-?\d+"#', 'w:' . $k . '="' . $v . '"', $t ) : str_replace( '<w:pgMar', '<w:pgMar w:' . $k . '="' . $v . '"', $t ); } return $t; }, $ddoc ); }
		$drel = str_replace( '</Relationships>', $newrels . '</Relationships>', $drel );
		$a->addFromString( 'word/document.xml', $ddoc ); $a->addFromString( 'word/_rels/document.xml.rels', $drel ); $a->addFromString( '[Content_Types].xml', $ct );
		$a->close(); $b->close();
		return true;
	}

	/* ------------------------------------------------- heat test data store */

	private static function grade_key( $g ) { $t = self::grade_token( $g ); return '105' === $t ? 'A105' : ( 'F22' === $t ? 'F22' : '' ); }

	/** Standard specified ranges (ASTM) used when a heat has none of its own. Observed / ladle values are never defaulted. */
	private static function std_spec( $key ) {
		$el = array( 'c', 'si', 'mn', 'p', 's', 'cr', 'mo', 'ni', 'cu', 'v', 'al', 'ce' );
		$tbl = array(
			'A105' => array( 'spec' => 'ASTM A105 / SA-105', 'sp' => array( '0.35 Max', '0.1 - 0.35', '0.6 - 1.05', '0.035 Max', '0.040 Max', '0.30 Max', '0.12 Max', '0.4 Max', '0.4 Max', '0.08 Max', '0.02-0.04', '0.47 Max' ), 'req' => array( '485 Min', '250 Min', '22% Min', '30% Min', '187 MAX', '6 to 8 ASTM' ) ),
			'F22'  => array( 'spec' => 'ASTM A182 F22', 'sp' => array( '0.05-0.15', '0.5 Max', '0.3-0.6', '0.04 Max', '0.04 Max', '2.0-2.5', '0.87-1.13', '---', '---', '---', '---', '---' ), 'req' => array( '415 Min', '205 Min', '20% Min', '35% Min', '170 MAX', '6 to 8 ASTM' ) ),
		);
		if ( ! isset( $tbl[ $key ] ) ) { return array(); }
		$o = array( 'heat_spec' => $tbl[ $key ]['spec'] );
		foreach ( $el as $i => $e ) { $o[ 'spec_' . $e ] = $tbl[ $key ]['sp'][ $i ]; }
		foreach ( array( 'uts', 'ys', 'el', 'ra', 'hard', 'grain' ) as $i => $k ) { $o[ 'req_' . $k ] = $tbl[ $key ]['req'][ $i ]; }
		return $o;
	}

	private static function heat_all() {
		$h = get_option( self::okey( 'pdqap_heat_data' ), array() );
		$h = is_array( $h ) ? $h : array();
		// Fall back to Heat Number Management (NABL import / manual entry) so those heats also fill MTC & IBR documents.
		if ( class_exists( 'PDQ_DB' ) ) {
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, heat_number, material_grade, specification FROM ' . self::tbl( 'heat_numbers' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', PDQ_DB::workspace_id() ), ARRAY_A );
			$els = array( 'c', 'si', 'mn', 'p', 's', 'cr', 'mo', 'ni', 'cu', 'v', 'al', 'ce' );
			foreach ( (array) $rows as $r ) {
				$no = (string) $r['heat_number'];
				if ( '' === $no ) { continue; }
				if ( isset( $h[ $no ]['obs'] ) && is_array( $h[ $no ]['obs'] ) && array_filter( $h[ $no ]['obs'] ) ) { continue; }
				$chem = PDQ_DB::list_rows( 'chemical_tests', array( 'filters' => array( 'heat_id' => $r['id'] ), 'per_page' => 100, 'orderby' => 'id', 'order' => 'ASC' ) );
				$mech = PDQ_DB::list_rows( 'mechanical_tests', array( 'filters' => array( 'heat_id' => $r['id'] ), 'per_page' => 100, 'orderby' => 'id', 'order' => 'ASC' ) );
				if ( empty( $chem['items'] ) && empty( $mech['items'] ) ) { continue; }
				$o = array( 'heat_no' => $no, 'grade' => (string) $r['material_grade'], 'spec' => (string) $r['specification'], 'size' => '', 'ingot_tc' => '', 'maker' => '', 'process' => '', 'ht' => '', 'ladle' => array(), 'obs' => array(), 'mech' => array() );
				if ( isset( $h[ $no ] ) && is_array( $h[ $no ] ) ) { foreach ( array( 'size', 'ingot_tc', 'maker', 'process', 'ht' ) as $k ) { if ( ! empty( $h[ $no ][ $k ] ) ) { $o[ $k ] = $h[ $no ][ $k ]; } } if ( ! empty( $h[ $no ]['ladle'] ) && is_array( $h[ $no ]['ladle'] ) ) { $o['ladle'] = $h[ $no ]['ladle']; } }
				$nt = function ( $x ) { $t = trim( (string) $x ); if ( is_numeric( $t ) && false !== strpos( $t, '.' ) ) { $t = rtrim( rtrim( $t, '0' ), '.' ); } return '' === $t ? '0' : $t; };
				foreach ( $els as $k ) { $o['obs'][ $k ] = ''; if ( ! isset( $o['ladle'][ $k ] ) ) { $o['ladle'][ $k ] = ''; } }
				foreach ( array( 'uts', 'ys', 'el', 'ra', 'hard', 'grain' ) as $k ) { $o['mech'][ $k ] = ''; }
				foreach ( (array) $chem['items'] as $c ) { $k = strtolower( preg_replace( '/[^A-Za-z]/', '', (string) $c['element'] ) ); if ( in_array( $k, $els, true ) ) { $o['obs'][ $k ] = $nt( $c['actual_value'] ); } }
				foreach ( (array) $mech['items'] as $m ) {
					$p = strtolower( (string) $m['property'] ); $k = '';
					if ( false !== strpos( $p, 'tensile' ) || false !== strpos( $p, 'uts' ) ) { $k = 'uts'; }
					elseif ( false !== strpos( $p, 'yield' ) || false !== strpos( $p, 'proof' ) || 'ys' === $p ) { $k = 'ys'; }
					elseif ( false !== strpos( $p, 'elong' ) ) { $k = 'el'; }
					elseif ( false !== strpos( $p, 'reduction' ) || 'ra' === $p ) { $k = 'ra'; }
					elseif ( false !== strpos( $p, 'hard' ) ) { $k = 'hard'; }
					if ( '' !== $k && null !== $m['actual_value'] && '' !== (string) $m['actual_value'] ) { $o['mech'][ $k ] = $nt( $m['actual_value'] ); }
				}
				if ( isset( $h[ $no ]['mech'] ) && is_array( $h[ $no ]['mech'] ) ) { foreach ( $o['mech'] as $k => $v ) { if ( '' === $v && ! empty( $h[ $no ]['mech'][ $k ] ) ) { $o['mech'][ $k ] = $h[ $no ]['mech'][ $k ]; } } }
				$h[ $no ] = $o;
			}
		}
		return $h;
	}

	public static function heat_list() {
		global $wpdb; $out = self::heat_all();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT heat_number, material_grade FROM ' . self::tbl( 'heat_numbers' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 500', PDQ_DB::workspace_id() ), ARRAY_A );
		return array( 'heats' => $out, 'known' => (array) $rows );
	}

	private static function clean_heat( $r ) {
		$r = (array) $r; $o = array();
		foreach ( array( 'heat_no', 'grade', 'spec', 'size', 'ingot_tc', 'maker', 'process', 'ht' ) as $k ) { $o[ $k ] = isset( $r[ $k ] ) && ! is_array( $r[ $k ] ) ? sanitize_text_field( (string) $r[ $k ] ) : ''; }
		foreach ( array( 'ladle' => array( 'c', 'si', 'mn', 'p', 's', 'cr', 'mo', 'ni', 'cu', 'v', 'al', 'ce' ), 'obs' => array( 'c', 'si', 'mn', 'p', 's', 'cr', 'mo', 'ni', 'cu', 'v', 'al', 'ce' ), 'mech' => array( 'uts', 'ys', 'el', 'ra', 'hard', 'grain' ) ) as $g => $ks ) {
			$o[ $g ] = array(); foreach ( $ks as $k ) { $v = isset( $r[ $g ][ $k ] ) && ! is_array( $r[ $g ][ $k ] ) ? trim( (string) $r[ $g ][ $k ] ) : ''; $o[ $g ][ $k ] = sanitize_text_field( $v ); }
		}
		return $o;
	}

	public static function heat_save( WP_REST_Request $req ) {
		$p = (array) $req->get_json_params(); $h = self::clean_heat( $p );
		if ( '' === $h['heat_no'] ) { return new WP_Error( 'pdqap_heat', 'Heat number is required.', array( 'status' => 422 ) ); }
		$all = self::heat_all();
		if ( ! empty( $p['delete'] ) ) { unset( $all[ $h['heat_no'] ] ); } else { $all[ $h['heat_no'] ] = $h; }
		update_option( self::okey( 'pdqap_heat_data' ), $all, false );
		return array( 'ok' => true, 'heats' => $all );
	}

	public static function heat_extract( WP_REST_Request $req ) {
		if ( ! self::ready() ) { return new WP_Error( 'pdqap_core', 'PipeDoc IQ core plugin is not active.', array( 'status' => 500 ) ); }
		$parts = array(); foreach ( $_FILES as $f ) { if ( is_array( $f ) && isset( $f['name'] ) && ! is_array( $f['name'] ) ) { $parts = array_merge( $parts, self::file_parts( $f ) ); } }
		if ( ! $parts ) { return new WP_Error( 'pdqap_nofile', 'Upload the mill test certificate / lab report (PDF, image, Excel or Word).', array( 'status' => 422 ) ); }
		$s = PDQ_Gemini::settings();
		if ( empty( $s['enabled'] ) || empty( $s['consent_given'] ) || empty( $s['model'] ) ) { return new WP_Error( 'pdqap_ai', 'Open "Gemini AI Settings" in PipeDoc IQ: add your API key, tick consent and select a model.', array( 'status' => 400 ) ); }
		$prompt = "You read mill / laboratory test certificates for forged steel flanges. Return ONLY a JSON array, one object per heat found, shaped:\n"
			. '{"heat_no":null,"grade":null,"spec":null,"size":null,"ingot_tc":null,"maker":null,"process":null,"ht":null,"ladle":{"c":null,"si":null,"mn":null,"p":null,"s":null,"cr":null,"mo":null,"ni":null,"cu":null,"v":null,"al":null,"ce":null},"obs":{"c":null,"si":null,"mn":null,"p":null,"s":null,"cr":null,"mo":null,"ni":null,"cu":null,"v":null,"al":null,"ce":null},"mech":{"uts":null,"ys":null,"el":null,"ra":null,"hard":null,"grain":null}}' . "\n"
			. "Rules: ladle = heat/ladle analysis from the mill certificate; obs = product/observed analysis from a lab report (null if absent); mech = tensile (N/mm2), yield, elongation %, reduction of area %, hardness, grain size; ht = heat treatment text; ingot_tc = test certificate no. and date; maker = steel maker. Copy numbers exactly as printed; NEVER guess or calculate missing values - use null.";
		array_unshift( $parts, array( 'text' => $prompt ) );
		$m = new ReflectionMethod( 'PDQ_Gemini', 'request' ); $m->setAccessible( true );
		$j = $m->invoke( null, 'POST', '/models/' . rawurlencode( $s['model'] ) . ':generateContent', array( 'contents' => array( array( 'parts' => $parts ) ), 'generationConfig' => array( 'responseMimeType' => 'application/json', 'temperature' => 0 ) ) );
		if ( is_wp_error( $j ) ) { return $j; }
		$d = json_decode( isset( $j['candidates'][0]['content']['parts'][0]['text'] ) ? $j['candidates'][0]['content']['parts'][0]['text'] : '', true );
		if ( ! is_array( $d ) ) { return new WP_Error( 'pdqap_parse', 'Could not read the certificate.', array( 'status' => 502 ) ); }
		if ( isset( $d['heat_no'] ) ) { $d = array( $d ); }
		$out = array(); foreach ( $d as $r ) { if ( is_array( $r ) ) { $out[] = self::clean_heat( $r ); } }
		return array( 'heats' => $out );
	}

	/** Flat placeholder values for one heat: its own stored data over ASTM defaults for the grade. */
	private static function heat_over( $heat_no, $grade_hint ) {
		$h = self::heat_all(); $h = isset( $h[ $heat_no ] ) ? $h[ $heat_no ] : array();
		$gk = self::grade_key( ( isset( $h['grade'] ) ? $h['grade'] : '' ) . ' ' . $grade_hint );
		$o = self::std_spec( $gk );
		$o['heat_no'] = $heat_no; $o['heat_grade'] = isset( $h['grade'] ) && '' !== $h['grade'] ? $h['grade'] : $grade_hint;
		foreach ( array( 'spec' => 'heat_spec', 'size' => 'heat_size', 'ingot_tc' => 'heat_ingot_tc', 'maker' => 'heat_maker', 'process' => 'heat_process', 'ht' => 'heat_ht' ) as $k => $ph ) { if ( ! empty( $h[ $k ] ) ) { $o[ $ph ] = $h[ $k ]; } }
		foreach ( array( 'ladle', 'obs', 'mech' ) as $g ) { foreach ( (array) ( isset( $h[ $g ] ) ? $h[ $g ] : array() ) as $k => $v ) { if ( '' !== (string) $v ) { $o[ $g . '_' . $k ] = (string) $v; } } }
		return $o;
	}

	/** MAWP (kg/cm2) per metal temperature from ASME B16.5 P-T ratings (psig), linear interpolation. */
	private static function mawp_for( $desc, $grade ) {
		static $pt = null; if ( null === $pt ) { $f = __DIR__ . '/data/pt_b165.json'; $j = is_file( $f ) ? json_decode( file_get_contents( $f ), true ) : array(); $pt = isset( $j['pt'] ) ? $j['pt'] : array(); }
		$d = strtoupper( $desc . ' ' . $grade ); $cl = 0;
		if ( preg_match( '/CL\s?(\d{3,4})/', $d, $m ) ) { $cl = (int) $m[1]; } elseif ( preg_match( '/(\d{3,4})\s?RATING|RATING\s?(\d{3,4})/', $d, $m ) ) { $cl = (int) ( $m[1] ? $m[1] : $m[2] ); }
		$grp = ''; if ( preg_match( '/F\s?22/', $d ) ) { $grp = '1.10'; } elseif ( preg_match( '/F\s?11/', $d ) ) { $grp = '1.9'; } elseif ( preg_match( '/F\s?316/', $d ) ) { $grp = '2.2'; } elseif ( preg_match( '/F\s?304/', $d ) ) { $grp = '2.1'; } elseif ( preg_match( '/LF\s?2|105/', $d ) ) { $grp = '1.1'; }
		$key = $grp . '|' . $cl; if ( ! $grp || ! $cl || empty( $pt[ $key ] ) ) { return array(); }
		$tab = $pt[ $key ]; ksort( $tab, SORT_NUMERIC ); $ts = array_keys( $tab ); $out = array();
		foreach ( array( 38, 50, 100, 150, 200, 250, 300, 350, 400, 425 ) as $c ) {
			$f = $c * 9 / 5 + 32; $v = null;
			if ( $f <= $ts[0] ) { $v = $tab[ $ts[0] ]; }
			elseif ( $f <= end( $ts ) ) { for ( $i = 1; $i < count( $ts ); $i++ ) { if ( $f <= $ts[ $i ] ) { $a = $ts[ $i - 1 ]; $b = $ts[ $i ]; $v = $tab[ $a ] + ( $tab[ $b ] - $tab[ $a ] ) * ( $f - $a ) / ( $b - $a ); break; } } }
			if ( null !== $v ) { $out[ 'mawp_' . $c ] = number_format( $v * 0.070307, 1, '.', '' ); }
		}
		$out['mawp_basis'] = 'ASME B16.5 Class ' . $cl . ', Material Group ' . $grp;
		return $out;
	}


	public static function generate( WP_REST_Request $req ) {
		if ( ! self::ready() ) { return new WP_Error( 'pdqap_core', 'PipeDoc IQ core plugin is not active.', array( 'status' => 500 ) ); }
		$p = (array) $req->get_json_params();
		$po = isset( $p['po'] ) ? (array) $p['po'] : array(); $items = isset( $p['items'] ) ? (array) $p['items'] : array();
		$company_id = (int) ( isset( $p['company_id'] ) ? $p['company_id'] : 0 );
		$po_no = sanitize_text_field( isset( $po['po_number'] ) ? $po['po_number'] : '' );
		if ( '' === $po_no || ! $items ) { return new WP_Error( 'pdqap_input', 'PO number and at least one item are required.', array( 'status' => 422 ) ); }
		if ( ! $company_id ) { return new WP_Error( 'pdqap_company', 'Create and select a company profile first.', array( 'status' => 422 ) ); }

		$cust_id = null;
		if ( ! empty( $po['customer_name'] ) ) { $cust_id = self::find_or_create( 'customers', 'name', sanitize_text_field( $po['customer_name'] ), array() ); if ( is_wp_error( $cust_id ) ) { return $cust_id; } }
		$po_id = self::find_or_create( 'purchase_orders', 'po_number', $po_no, array( 'po_type' => 'purchase', 'po_date' => self::iso_date( isset( $po['po_date'] ) ? $po['po_date'] : '' ), 'customer_id' => $cust_id, 'consignee' => sanitize_text_field( isset( $po['consignee'] ) ? $po['consignee'] : '' ), 'inspection_agency' => sanitize_text_field( isset( $po['inspection_agency'] ) ? $po['inspection_agency'] : '' ) ) );
		if ( is_wp_error( $po_id ) ) { return $po_id; }
		global $wpdb;
		$has = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::tbl( 'purchase_order_items' ) . ' WHERE po_id = %d AND deleted_at IS NULL', $po_id ) );

		$warnings = array(); $list = array(); $n = 0;
		foreach ( $items as $i => $it ) {
			$it = (array) $it; $n++;
			$qty = isset( $it['quantity'] ) && '' !== $it['quantity'] ? (float) $it['quantity'] : null;
			if ( ! $has ) { PDQ_DB::create( 'purchase_order_items', array( 'po_id' => $po_id, 'line_no' => $n, 'material_code' => sanitize_text_field( isset( $it['material_code'] ) ? $it['material_code'] : '' ), 'item_description' => sanitize_text_field( isset( $it['description'] ) ? $it['description'] : '' ), 'material_grade' => sanitize_text_field( isset( $it['grade'] ) ? $it['grade'] : '' ), 'ordered_qty' => $qty ) ); }
			$heat = sanitize_text_field( isset( $it['heat_number'] ) ? $it['heat_number'] : '' );
			if ( '' === $heat ) { $warnings[] = 'Item ' . $n . ': no heat number — add the heat in Heat Number Management (matched by grade) and regenerate.'; }
			$list[] = array( 'line_no' => $n, 'material_code' => sanitize_text_field( isset( $it['material_code'] ) ? $it['material_code'] : '' ), 'description' => sanitize_text_field( isset( $it['description'] ) ? $it['description'] : '' ), 'grade' => sanitize_text_field( isset( $it['grade'] ) ? $it['grade'] : '' ), 'quantity' => $qty, 'heat_number' => $heat ) + self::obs_of( $it );
		}
		// Representative sample IDs per distinct heat (S-01, S-02 ...).
		$heats = array(); foreach ( $list as $r ) { if ( '' !== $r['heat_number'] && ! in_array( $r['heat_number'], $heats, true ) ) { $heats[] = $r['heat_number']; } }
		foreach ( $list as &$r ) {
			$k = array_search( $r['heat_number'], $heats, true );
			$r['item_description'] = $r['description']; $r['batch_no'] = $r['heat_number']; $r['checked_qty'] = $r['quantity'];
			foreach ( self::dims_for( $r['description'] ) as $dk => $dv ) { $r[ $dk ] = $dv; }
			$r['supply'] = 'Forged & Machined'; $r['astm_ref'] = 'ASTM A388'; $r['technique'] = 'Central Conductor'; $r['sample_id'] = false === $k ? '' : sprintf( 'S-%02d', $k + 1 );
		}
		unset( $r );

		// Excel templates use numbered placeholders ({{item_1_description}} ...). Supplied via overrides so no core-file patch is needed.
		$ix = array( 'item_count' => (string) count( $list ) );
		foreach ( $list as $r ) { foreach ( $r as $k => $v ) { if ( is_scalar( $v ) && null !== $v && '' !== (string) $v ) { $ix[ 'item_' . $r['line_no'] . '_' . $k ] = (string) $v; } } }
		$done = get_option( self::okey( 'pdqap_seeded' ), array() ); $want = isset( $p['templates'] ) ? (array) $p['templates'] : array();
		$over = array( 'qap_number' => sanitize_text_field( isset( $po['qap_number'] ) ? $po['qap_number'] : '' ), 'qap_number_2' => sanitize_text_field( isset( $po['qap_number_2'] ) ? $po['qap_number_2'] : '' ), 'po_date' => sanitize_text_field( isset( $po['po_date'] ) ? $po['po_date'] : '' ), 'customer_name' => sanitize_text_field( isset( $po['customer_name'] ) ? $po['customer_name'] : '' ), 'consignee' => sanitize_text_field( isset( $po['consignee'] ) ? $po['consignee'] : '' ), 'certificate_date' => gmdate( 'd-m-Y' ) );
		foreach ( $po as $xk => $xv ) { if ( 0 === strpos( (string) $xk, 'x_' ) && is_scalar( $xv ) && '' !== trim( (string) $xv ) ) { $over[ substr( (string) $xk, 2 ) ] = sanitize_text_field( (string) $xv ); } }

		$token = bin2hex( random_bytes( 12 ) ); $dir = PDQ_Files::base_dir() . '/autopack'; wp_mkdir_p( $dir );
		$zip = new ZipArchive(); $zp = $dir . '/' . $token . '.zip';
		if ( true !== $zip->open( $zp, ZipArchive::CREATE ) ) { return new WP_Error( 'pdqap_zip', 'Could not create ZIP.', array( 'status' => 500 ) ); }
		$docs = array();
		$cx = self::co( $company_id ); $lh = ! empty( $cx['letterhead'] ) ? PDQ_Files::base_dir() . '/' . $cx['letterhead'] : ''; if ( $lh && ! is_file( $lh ) ) { $lh = ''; }
		$over = array_merge( $over, array( 'company_gstin' => isset( $cx['gstin'] ) ? $cx['gstin'] : '', 'company_factory_address' => isset( $cx['factory_address'] ) ? $cx['factory_address'] : '', 'signatory_name' => isset( $cx['signatory_name'] ) ? $cx['signatory_name'] : '', 'ibr_inspecting_authority' => isset( $cx['inspecting_authority'] ) ? $cx['inspecting_authority'] : '' ) );
		$poh = array(); // heat_N_* for lab-request sheets
		foreach ( $heats as $hi => $hn ) { $first = null; foreach ( $list as $r ) { if ( $r['heat_number'] === $hn ) { $first = $r; break; } } $hv = self::heat_over( $hn, $first ? $first['grade'] : '' ); $poh[ 'heat_' . ( $hi + 1 ) . '_no' ] = $hn; $poh[ 'heat_' . ( $hi + 1 ) . '_grade' ] = $hv['heat_grade']; foreach ( array( 'heat_spec' => 'spec', 'heat_size' => 'size' ) as $a => $b ) { if ( ! empty( $hv[ $a ] ) ) { $poh[ 'heat_' . ( $hi + 1 ) . '_' . $b ] = $hv[ $a ]; } } }
		$jobs = array();
		foreach ( self::catalogue() as $t ) {
			if ( $want && ! in_array( $t['file'], $want, true ) ) { continue; }
			if ( empty( $done[ $t['file'] ] ) ) { $warnings[] = $t['name'] . ': template not seeded.'; continue; }
			$mode = isset( $t['mode'] ) ? $t['mode'] : 'po';
			if ( 'heat' === $mode ) {
				if ( ! $heats ) { $warnings[] = $t['name'] . ': no heat numbers assigned to the items.'; }
				foreach ( $heats as $hn ) {
					$sub = array(); foreach ( $list as $r ) { if ( $r['heat_number'] === $hn ) { $sub[] = $r; } }
					
					$hv = self::heat_over( $hn, $sub[0]['grade'] ); if ( empty( $hv['ladle_c'] ) || empty( $hv['mech_uts'] ) ) { $warnings[] = $t['name'] . ' (heat ' . $hn . '): chemical / mechanical test values are not stored — fill them under "Heat test data" (or read the mill TC with AI) and regenerate.'; }
					$chunks = array_chunk( $sub, 8 ); foreach ( $chunks as $ci => $chunk ) { $jobs[] = array( 't' => $t, 'label' => ' - heat ' . $hn . ( count( $chunks ) > 1 ? ' part ' . ( $ci + 1 ) : '' ), 'items' => $chunk, 'extra' => $hv ); }
				}
			} elseif ( 'item' === $mode ) {
				foreach ( $list as $r ) {
					$hv = self::heat_over( $r['heat_number'], $r['grade'] ); $q = null !== $r['quantity'] ? (string) $r['quantity'] : '';
					$io = array_merge( $hv, array( 'ibr_part_name' => $r['description'], 'ibr_qty' => $q, 'ibr_serial' => '' !== $q && (float) $q >= 1 ? 'F1 TO F' . (int) $q : '', 'item_material_code' => $r['material_code'], 'ibr_dim_std' => 'ASME B16.5' ) );
					foreach ( array( 'c', 'mn', 'si', 's', 'p', 'cu', 'ni', 'cr', 'mo', 'v' ) as $k ) { $io[ 'ibr_' . $k ] = isset( $hv[ 'ladle_' . $k ] ) ? $hv[ 'ladle_' . $k ] : ''; }
					$mw = self::mawp_for( $r['description'], $r['grade'] ); if ( ! $mw ) { $warnings[] = $t['name'] . ' item ' . $r['line_no'] . ': MAWP not calculated (flange class / material group not recognised) — fill the pressure table by hand.'; }
					if ( empty( $hv['ladle_c'] ) ) { $warnings[] = $t['name'] . ' item ' . $r['line_no'] . ': chemical / mechanical values are not stored for heat ' . $r['heat_number'] . '.'; }
					$jobs[] = array( 't' => $t, 'label' => ' - item ' . $r['line_no'], 'items' => array( $r ), 'extra' => array_merge( $io, $mw ) );
				}
			} else {
				if ( count( $heats ) > 2 && false !== stripos( $t['file'], 'NABL' ) ) { $warnings[] = $t['name'] . ': ' . count( $heats ) . ' heats — the sheet has room for 2 heat blocks; extra heats were not printed.'; }
				$jobs[] = array( 't' => $t, 'label' => '', 'items' => $list, 'extra' => $poh );
			}
		}
		$lh_warned = false;
		foreach ( $jobs as $job ) {
			$t = $job['t']; $ixj = array( 'item_count' => (string) count( $job['items'] ) );
			foreach ( array_values( $job['items'] ) as $q => $r ) { foreach ( $r as $k => $v ) { if ( is_scalar( $v ) && null !== $v && '' !== (string) $v ) { $ixj[ 'item_' . ( $q + 1 ) . '_' . $k ] = (string) $v; } } }
			$body = array( 'template_version_id' => self::vid( $done[ $t['file'] ] ), 'context' => array( 'company_id' => $company_id, 'customer_id' => $cust_id, 'po_id' => $po_id ), 'items' => array_values( $job['items'] ), 'overrides' => array_filter( array_merge( $ixj, $over, $job['extra'] ), function ( $v ) { return '' !== $v && null !== $v; } ), 'status' => 'draft' );
			$rq = new WP_REST_Request( 'POST', '/pdq/v1/documents/generate' );
			$rq->set_header( 'content-type', 'application/json' ); $rq->set_body( wp_json_encode( $body ) );
			$res = PDQ_REST::doc_generate( $rq );
			if ( is_wp_error( $res ) ) { $warnings[] = $t['name'] . $job['label'] . ': ' . $res->get_error_message(); continue; }
			$data = $res instanceof WP_REST_Response ? $res->get_data() : (array) $res;
			$doc  = isset( $data['document'] ) ? $data['document'] : array();
			foreach ( (array) ( isset( $data['warnings'] ) ? $data['warnings'] : array() ) as $w ) { if ( false === stripos( (string) $w, 'repeating item rows are not expanded' ) && false === stripos( (string) $w, 'has no unit weight' ) ) { $warnings[] = $t['name'] . ': ' . $w; } }
			$full = ! empty( $doc['file_path'] ) ? PDQ_Files::resolve( $doc['file_path'] ) : false;
			if ( ! $full ) { $warnings[] = $t['name'] . ': generated file not found.'; continue; }
			$ext = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) );
			if ( 'docx' === $ext ) {
				if ( $lh ) {
					if ( self::apply_letterhead( $full, $lh ) ) { if ( ! empty( $doc['id'] ) ) { $GLOBALS['wpdb']->suppress_errors( true ); $GLOBALS['wpdb']->update( self::tbl( 'documents' ), array( 'file_hash' => hash_file( 'sha256', $full ) ), array( 'id' => (int) $doc['id'] ) ); $GLOBALS['wpdb']->suppress_errors( false ); } }
					else { $warnings[] = $t['name'] . ': company letterhead could not be applied.'; }
				} elseif ( ! $lh_warned ) { $lh_warned = true; $warnings[] = 'No letterhead uploaded for this company — Word documents carry the default template letterhead. Upload the company letterhead (Word) in section 1.'; }
			}
			$fname = sanitize_file_name( $po_no . '_' . $t['name'] . $job['label'] ) . '.' . $ext;
			$zip->addFile( $full, $fname );
			$docs[] = array( 'name' => $t['name'] . $job['label'], 'doc_number' => isset( $doc['doc_number'] ) ? $doc['doc_number'] : '', 'id' => isset( $doc['id'] ) ? (int) $doc['id'] : 0 );
		}
		$zip->close();
		if ( ! $docs ) { @unlink( $zp ); return new WP_Error( 'pdqap_none', 'No document could be generated: ' . implode( ' ', array_slice( $warnings, 0, 3 ) ), array( 'status' => 422 ) ); }
		$warnings[] = 'Specified dimensions come from published ASME B16.5 tables (unparsable lines stay blank — check them). Observed/measured values are never auto-filled: enter them after inspection.';
		return array( 'token' => $token, 'docs' => $docs, 'warnings' => array_values( array_unique( $warnings ) ) );
	}

	public static function download() {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : '', 'pdqap_zip' ) ) { wp_die( 'Not allowed', 403 ); }
		$token = isset( $_GET['token'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $_GET['token'] ) : '';
		$full  = self::ready() ? PDQ_Files::base_dir() . '/autopack/' . $token . '.zip' : '';
		if ( ! $token || ! is_file( $full ) ) { wp_die( 'File not found', 404 ); }
		nocache_headers(); header( 'Content-Type: application/zip' ); header( 'Content-Disposition: attachment; filename="PipeDoc-IQ-Document-Pack.zip"' ); header( 'Content-Length: ' . filesize( $full ) );
		readfile( $full ); exit;
	}
}
PDQAP::init();
