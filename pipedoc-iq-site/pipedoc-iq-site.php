<?php
/**
 * Plugin Name: PipeDoc IQ Site
 * Description: Public welcome / marketing site for PipeDoc IQ — home, interactive demo, pricing, free plan, register, login and account pages.
 * Version:     2.0.0
 * Author:      Tamal Roy Chowdhury
 * License:     GPL-2.0-or-later
 * Text Domain: pdq-site
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PDQSITE {
	const VER = '2.0.0';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'boot' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_filter( 'show_admin_bar', function ( $s ) { return self::is_member() ? false : $s; } );
		add_action( 'admin_init', function () { if ( self::is_member() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! self::ctx_ok() ) { wp_safe_redirect( self::url( 'account' ) ); exit; } }, 1 );
		add_filter( 'pdq_current_workspace_id', array( __CLASS__, 'workspace' ) );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant' ), 10, 4 );
		add_filter( 'pdq_user_can', array( __CLASS__, 'deny' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'quota' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'count_use' ), 10, 3 );
		add_action( 'in_admin_header', array( __CLASS__, 'member_bar' ) );
		add_action( 'admin_head', array( __CLASS__, 'member_css' ) );
	}

	/* ------------------------------------------------------------ helpers */

	private static function url( $p = 'home' ) { $b = wp_make_link_relative( home_url( '/' ) ); $b = '' === $b ? '/' : $b; return 'home' === $p ? $b : $b . '?pdq=' . rawurlencode( $p ); }
	private static function app_url() { return wp_make_link_relative( admin_url( 'admin.php?page=pipedoc-iq&pdq_app=1' ) ); }
	private static function is_member() { $u = wp_get_current_user(); return $u && $u->exists() && in_array( 'pdq_member', (array) $u->roles, true ); }
	private static function e( $s ) { return esc_html( (string) $s ); }

	public static function plans() {
		$o = get_option( 'pdqsite_plans', array() ); $o = is_array( $o ) ? $o : array();
		return array(
			'free'     => array( 'name' => 'Free', 'price' => isset( $o['free'] ) ? $o['free'] : '0', 'lim' => array( 'packs' => 5, 'companies' => 1, 'tpl' => false ), 'tag' => 'Try the full workflow on your own PO.', 'feat' => array( '1 company profile & Word letterhead', 'Upload PO / BOQ / spec sheet', 'AI reading with your own Gemini key', '5 document packs a month', 'All 15 standard report templates', 'Heat test-data store & mill TC reading', 'ZIP download of every document' ) ),
			'pro'      => array( 'name' => 'Pro', 'price' => isset( $o['pro'] ) ? $o['pro'] : '999', 'lim' => array( 'packs' => 0, 'companies' => 5, 'tpl' => true ), 'tag' => 'For a manufacturer issuing documents every week.', 'feat' => array( 'Everything in Free', 'High-volume document packs (fair use)', 'Up to 5 company profiles & letterheads', 'Upload your own templates (Template Studio)', 'Document archive & revisions' ), 'hot' => 1 ),
			'business' => array( 'name' => 'Business', 'price' => isset( $o['business'] ) ? $o['business'] : '2999', 'lim' => array( 'packs' => 0, 'companies' => 0, 'tpl' => true ), 'tag' => 'For groups, traders and job-work houses.', 'feat' => array( 'Everything in Pro', 'Many company profiles (fair use)', 'Custom template set built for you', 'Priority support & onboarding' ) ),
		);
	}

	/* ----------------------------------------------- tenants, access, plan limits */

	private static function member_active( $uid = 0 ) { $uid = $uid ? $uid : get_current_user_id(); $u = get_userdata( $uid ); return $u && in_array( 'pdq_member', (array) $u->roles, true ) && 'active' === get_user_meta( $uid, 'pdqs_status', true ); }
	private static function plan_of( $uid ) { $p = get_user_meta( $uid, 'pdqs_plan', true ); return isset( self::plans()[ $p ] ) ? $p : 'free'; }
	public static function early() { return '0' !== (string) get_option( 'pdqsite_early', '1' ); }
	private static function limits( $uid ) {
		$pl = self::plans();
		if ( self::early() ) { return array( 'packs' => 100, 'companies' => 10, 'tpl' => true ); }
		return $pl[ self::plan_of( $uid ) ]['lim'];
	}
	private static function used( $uid ) { return (int) get_user_meta( $uid, 'pdqs_use_' . gmdate( 'Ym' ), true ); }

	/** Each member company gets its own workspace id; the administrator keeps the original one. */
	public static function workspace( $w ) { if ( self::is_member() ) { $m = (int) get_user_meta( get_current_user_id(), 'pdqs_ws', true ); if ( $m ) { return $m; } } return $w; }

	/** Is this request one of the application's own screens / endpoints? */
	public static function ctx_ok() {
		$u = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; $path = (string) wp_parse_url( $u, PHP_URL_PATH ); $s = basename( $path );
		if ( false !== strpos( $path, '/pdq/v1/' ) || false !== strpos( $path, '/pdqap/v1/' ) ) { return true; }
		if ( isset( $_GET['rest_route'] ) && preg_match( '#^/pdq(ap)?/v1/#', (string) $_GET['rest_route'] ) ) { return true; }
		if ( 'admin.php' === $s && isset( $_GET['page'] ) && in_array( $_GET['page'], array( 'pipedoc-iq', 'pdqap' ), true ) ) { return true; }
		if ( 'admin-post.php' === $s && isset( $_REQUEST['action'] ) && 'pdqap_zip' === $_REQUEST['action'] ) { return true; }
		return false;
	}

	/** Active members may use the PipeDoc IQ screens and endpoints only (never the rest of wp-admin). */
	public static function grant( $all, $caps, $args, $user ) {
		if ( empty( $user->ID ) || (int) $user->ID !== (int) get_current_user_id() || ! in_array( 'pdq_member', (array) $user->roles, true ) ) { return $all; }
		if ( 'active' !== get_user_meta( $user->ID, 'pdqs_status', true ) || ! self::ctx_ok() ) { return $all; }
		$all['manage_options'] = true; return $all;
	}

	/** Endpoints that act on the whole installation are never available to members. */
	public static function deny( $ok, $request ) {
		if ( ! $ok || ! self::is_member() ) { return $ok; }
		$r = is_object( $request ) && method_exists( $request, 'get_route' ) ? $request->get_route() : '';
		return preg_match( '#^/pdq/v1/(backup|restore|system/info)#', $r ) ? false : $ok;
	}

	public static function quota( $res, $server, $request ) {
		if ( null !== $res || ! self::is_member() ) { return $res; }
		$route = $request->get_route(); $m = $request->get_method(); $uid = get_current_user_id(); $lim = self::limits( $uid ); $pl = self::plans(); $name = self::early() ? 'Early Access' : $pl[ self::plan_of( $uid ) ]['name'];
		if ( 'POST' === $m && '/pdqap/v1/generate' === $route && $lim['packs'] && self::used( $uid ) >= $lim['packs'] ) { return new WP_Error( 'pdqs_limit', 'Your ' . $name . ' plan includes ' . $lim['packs'] . ' document packs a month and they are used up. ' . ( self::early() ? 'Please contact us for more capacity.' : 'Upgrade your plan to continue.' ) . '', array( 'status' => 403 ) ); }
		if ( 'POST' === $m && '/pdq/v1/e/company_profiles' === $route && $lim['companies'] && class_exists( 'PDQ_Registry' ) && class_exists( 'PDQ_DB' ) ) {
			global $wpdb; $n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PDQ_Registry::table( 'company_profiles' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', PDQ_DB::workspace_id() ) );
			if ( $n >= $lim['companies'] ) { return new WP_Error( 'pdqs_limit', 'Your ' . $name . ' plan includes ' . $lim['companies'] . ' company profile' . ( 1 === $lim['companies'] ? '' : 's' ) . '. Upgrade your plan to add more.', array( 'status' => 403 ) ); }
		}
		if ( 'POST' === $m && '/pdq/v1/templates/upload' === $route && empty( $lim['tpl'] ) ) { return new WP_Error( 'pdqs_limit', 'Uploading your own templates is available on the Pro and Business plans.', array( 'status' => 403 ) ); }
		return $res;
	}

	public static function count_use( $result, $server, $request ) {
		if ( self::is_member() && 'POST' === $request->get_method() && '/pdqap/v1/generate' === $request->get_route() && $result instanceof WP_REST_Response && $result->get_status() < 300 ) { $u = get_current_user_id(); update_user_meta( $u, 'pdqs_use_' . gmdate( 'Ym' ), self::used( $u ) + 1 ); }
		return $result;
	}

	public static function member_css() {
		if ( ! self::is_member() ) { return; }
		echo '<style>#adminmenumain,#wpadminbar,#wpfooter,.update-nag,#screen-meta-links{display:none!important}#wpcontent{margin-left:0!important;padding-left:0!important}html.wp-toolbar{padding-top:0!important}#pdqs-bar{background:#14213d;color:#fff;display:flex;gap:6px;align-items:center;padding:10px 18px;font:600 14px/1 system-ui,sans-serif}#pdqs-bar a{color:#cbd5e1;text-decoration:none;padding:8px 12px;border-radius:8px}#pdqs-bar a:hover{background:rgba(255,255,255,.1);color:#fff}#pdqs-bar b{margin-right:auto;font-size:17px}#pdqs-bar b i img{width:24px;height:24px;display:block;border-radius:7px}#pdqs-bar b i{display:inline-block;vertical-align:middle;width:24px;height:24px;border-radius:7px;background:none;line-height:0;font-style:normal;margin-right:8px}</style>';
	}

	public static function member_bar() {
		if ( ! self::is_member() ) { return; }
		echo '<div id="pdqs-bar"><b><i>' . PDQ_Branding::mark() . '</i>PipeDoc IQ</b><a href="' . esc_url( self::url( 'account' ) ) . '">Account</a><a href="' . esc_url( self::app_url() ) . '">Application</a><a href="' . esc_url( wp_make_link_relative( admin_url( 'admin.php?page=pdqap' ) ) ) . '">Auto Pack</a><a href="' . esc_url( self::url( 'logout' ) . '&_wpnonce=' . wp_create_nonce( 'pdqs_logout' ) ) . '">Log out</a></div>';
	}

	private static function provision( $uid, $company ) {
		try {
			if ( class_exists( 'PDQ_DB' ) ) { $c = PDQ_DB::create( 'company_profiles', array( 'company_name' => $company, 'additional_info' => 'Created at registration. Add address, GSTIN and letterhead in Auto Pack.' ) ); if ( is_wp_error( $c ) ) { update_user_meta( $uid, 'pdqs_prov', 'company: ' . $c->get_error_message() ); } }
			if ( class_exists( 'PDQAP' ) ) { PDQAP::provision(); }
			update_user_meta( $uid, 'pdqs_prov', 'ok' );
		} catch ( Throwable $e ) { update_user_meta( $uid, 'pdqs_prov', 'error: ' . $e->getMessage() ); }
	}

	public static function boot() {
		if ( ! get_role( 'pdq_member' ) ) { add_role( 'pdq_member', 'PipeDoc IQ Member', array( 'read' => true ) ); }
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['pdqs_action'] ) ) { return; }
		$a = sanitize_key( $_POST['pdqs_action'] );
		if ( 'login' === $a ) { self::do_login(); } elseif ( 'register' === $a ) { self::do_register(); }
	}

	private static function flash( $msg, $to ) { set_transient( 'pdqs_f_' . self::ip(), $msg, 120 ); wp_safe_redirect( self::url( $to ) ); exit; }
	private static function ip() { return md5( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'x' ); }
	private static function take_flash() { $k = 'pdqs_f_' . self::ip(); $m = get_transient( $k ); if ( $m ) { delete_transient( $k ); } return (string) $m; }

	private static function throttle( $what ) {
		$k = 'pdqs_t_' . $what . '_' . self::ip(); $n = (int) get_transient( $k );
		if ( $n >= 8 ) { self::flash( 'Too many attempts. Please wait a few minutes and try again.', $what ); }
		set_transient( $k, $n + 1, 600 );
	}

	private static function do_login() {
		if ( ! isset( $_POST['_pdqs'] ) || ! wp_verify_nonce( $_POST['_pdqs'], 'pdqs_login' ) ) { self::flash( 'Session expired. Please try again.', 'login' ); }
		self::throttle( 'login' );
		$u = wp_signon( array( 'user_login' => sanitize_text_field( wp_unslash( isset( $_POST['log'] ) ? $_POST['log'] : '' ) ), 'user_password' => (string) wp_unslash( isset( $_POST['pwd'] ) ? $_POST['pwd'] : '' ), 'remember' => ! empty( $_POST['rem'] ) ), is_ssl() );
		if ( is_wp_error( $u ) ) { self::flash( 'Wrong e-mail / username or password.', 'login' ); }
		wp_safe_redirect( user_can( $u, 'manage_options' ) ? self::app_url() : self::url( 'account' ) ); exit;
	}

	private static function do_register() {
		if ( ! isset( $_POST['_pdqs'] ) || ! wp_verify_nonce( $_POST['_pdqs'], 'pdqs_register' ) ) { self::flash( 'Session expired. Please try again.', 'register' ); }
		if ( ! empty( $_POST['website'] ) ) { self::flash( 'Could not register.', 'register' ); } // honeypot
		self::throttle( 'register' );
		$name = sanitize_text_field( wp_unslash( isset( $_POST['name'] ) ? $_POST['name'] : '' ) ); $co = sanitize_text_field( wp_unslash( isset( $_POST['company'] ) ? $_POST['company'] : '' ) );
		$mail = sanitize_email( wp_unslash( isset( $_POST['email'] ) ? $_POST['email'] : '' ) ); $pw = (string) wp_unslash( isset( $_POST['pwd'] ) ? $_POST['pwd'] : '' );
		$plan = sanitize_key( isset( $_POST['plan'] ) ? $_POST['plan'] : 'free' ); if ( ! isset( self::plans()[ $plan ] ) ) { $plan = 'free'; }
		if ( '' === $name || '' === $co || ! is_email( $mail ) ) { self::flash( 'Please enter your name, company and a valid e-mail.', 'register' ); }
		if ( strlen( $pw ) < 8 ) { self::flash( 'Password must be at least 8 characters.', 'register' ); }
		if ( empty( $_POST['terms'] ) ) { self::flash( 'Please accept the terms to continue.', 'register' ); }
		if ( email_exists( $mail ) || username_exists( $mail ) ) { self::flash( 'An account with this e-mail already exists. Please log in.', 'login' ); }
		$id = wp_insert_user( array( 'user_login' => $mail, 'user_email' => $mail, 'user_pass' => $pw, 'display_name' => $name, 'first_name' => $name, 'role' => 'pdq_member' ) );
		if ( is_wp_error( $id ) ) { self::flash( 'Could not create the account. Please try again.', 'register' ); }
		$ws = (int) get_option( 'pdqs_next_ws', 100 ); update_option( 'pdqs_next_ws', $ws + 1, false );
		update_user_meta( $id, 'pdqs_company', $co ); update_user_meta( $id, 'pdqs_plan', 'free' ); update_user_meta( $id, 'pdqs_plan_req', $plan ); update_user_meta( $id, 'pdqs_status', 'active' ); update_user_meta( $id, 'pdqs_ws', $ws ); update_user_meta( $id, 'pdqs_joined', current_time( 'mysql' ) );
		wp_set_current_user( $id ); wp_set_auth_cookie( $id, true, is_ssl() );
		self::provision( $id, $co );
		@wp_mail( get_option( 'admin_email' ), 'New PipeDoc IQ registration: ' . $co, "Name: $name\nCompany: $co\nE-mail: $mail\nRequested plan: $plan\nWorkspace: $ws" );
		wp_safe_redirect( self::url( 'account' ) ); exit;
	}

	/* -------------------------------------------------------------- routing */

	public static function route() {
		if ( is_admin() ) { return; }
		$p = isset( $_GET['pdq'] ) ? sanitize_key( $_GET['pdq'] ) : '';
		if ( '' === $p ) {
			$root = get_option( 'pdqsite_root', '1' );
			if ( '1' === (string) $root && is_front_page() && empty( $_GET ) ) { $p = 'home'; } else { return; }
		}
		if ( 'logout' === $p ) { if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'pdqs_logout' ) ) { wp_logout(); } wp_safe_redirect( self::url( 'home' ) ); exit; }
		if ( ! in_array( $p, array( 'home', 'pricing', 'login', 'register', 'account', 'terms', 'privacy' ), true ) ) { return; }
		if ( 'account' === $p && ! is_user_logged_in() ) { wp_safe_redirect( self::url( 'login' ) ); exit; }
		if ( in_array( $p, array( 'login', 'register' ), true ) && is_user_logged_in() ) { wp_safe_redirect( self::url( 'account' ) ); exit; }
		nocache_headers(); header( 'Content-Type: text/html; charset=utf-8' );
		self::render( $p ); exit;
	}

	/* --------------------------------------------------------------- render */

	private static function css() {
		return <<<'CSS'
:root{--g:#18181b;--g2:#27272a;--ink:#18181b;--mut:#62626d;--line:#e7e7ea;--bg:#fafafa;--card:#fff;--or:#ea580c;--or2:#c2410c;--or3:#fb923c;--orl:#fff1e8;--ok:#16a34a;--navy:#18181b;--navy2:#27272a}
*{box-sizing:border-box}html{scroll-behavior:smooth}
body{margin:0;font:16px/1.65 Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased}
h1,h2,h3,h4,h5{font-family:Manrope,Inter,system-ui,sans-serif}
a{color:inherit}.w{max-width:1180px;margin:0 auto;padding:0 24px}
.nav{position:sticky;top:0;z-index:50;background:rgba(24,24,27,.88);backdrop-filter:saturate(1.4) blur(12px);-webkit-backdrop-filter:saturate(1.4) blur(12px);border-bottom:1px solid rgba(255,255,255,.07)}
.nav .w{display:flex;align-items:center;gap:18px;height:68px}
.logo{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font:800 20px/1 Manrope,Inter,sans-serif;letter-spacing:-.4px}
.logo i{width:34px;height:34px;border-radius:10px;background:none;display:block;font-style:normal;box-shadow:none}.logo i img{width:100%;height:100%;display:block;border-radius:10px}
.nav nav{display:flex;gap:2px;margin-left:auto;align-items:center}.nav nav a{color:#d4d4d8;text-decoration:none;padding:9px 13px;border-radius:10px;font-size:15px;font-weight:500;transition:.15s}.nav nav a:hover{color:#fff;background:rgba(255,255,255,.09)}
.nav nav a.btn{color:#fff;margin-left:8px;padding:11px 18px}.nav nav a.btn:hover{background:var(--or2)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;cursor:pointer;font:600 15px/1 Inter,system-ui,sans-serif;padding:15px 24px;border-radius:12px;text-decoration:none;background:var(--or);color:#fff;transition:.18s;box-shadow:0 8px 20px rgba(234,88,12,.28)}.btn:hover{background:var(--or2);transform:translateY(-1px);box-shadow:0 12px 26px rgba(234,88,12,.34)}
.btn.ghost{background:rgba(255,255,255,.04);color:#fff;border:1px solid rgba(255,255,255,.28);box-shadow:none}.btn.ghost:hover{background:rgba(255,255,255,.12)}
.btn.dark{background:var(--g);box-shadow:none}.btn.dark:hover{background:var(--g2)}.btn.line{background:#fff;color:var(--ink);border:1px solid var(--line);box-shadow:none}.btn.line:hover{background:#f4f4f5}.btn.sm{padding:11px 16px;font-size:14px}.btn.full{width:100%}
.btn[disabled]{opacity:.6;cursor:default;transform:none}
.hero{background:radial-gradient(900px 480px at 88% -8%,rgba(251,146,60,.30),transparent 60%),radial-gradient(640px 420px at -8% 112%,rgba(234,88,12,.20),transparent 60%),linear-gradient(180deg,#18181b,#202024);color:#fff;padding:92px 0 96px;position:relative;overflow:hidden}
.hero:before{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.045) 1px,transparent 1px);background-size:46px 46px;-webkit-mask-image:radial-gradient(ellipse at 50% 30%,#000 25%,transparent 75%);mask-image:radial-gradient(ellipse at 50% 30%,#000 25%,transparent 75%)}
.hero .w{position:relative;z-index:1;display:grid;grid-template-columns:1.06fr .94fr;gap:60px;align-items:center}
.pill{display:inline-flex;align-items:center;gap:8px;background:rgba(251,146,60,.14);color:#fdba74;border:1px solid rgba(251,146,60,.35);padding:6px 14px;border-radius:99px;font-size:13px;font-weight:600;margin-bottom:22px}.pill:before{content:"";width:7px;height:7px;border-radius:50%;background:var(--or3);box-shadow:0 0 0 4px rgba(251,146,60,.2)}
h1{font-size:clamp(34px,4.6vw,56px);line-height:1.05;margin:0 0 20px;letter-spacing:-1.8px;font-weight:800}h1 em{font-style:normal;background:linear-gradient(90deg,var(--or3),#f97316);-webkit-background-clip:text;background-clip:text;color:transparent}
.lead{font-size:19px;color:#d4d4d8;margin:0 0 32px;max-width:560px;line-height:1.6}.cta{display:flex;gap:12px;flex-wrap:wrap}.note{margin-top:18px;font-size:13.5px;color:#a1a1aa}
.stage{position:relative;padding:18px 0}
.mock{background:#fff;color:var(--ink);border-radius:20px;box-shadow:0 40px 80px rgba(0,0,0,.45),0 0 0 1px rgba(255,255,255,.06);padding:20px;position:relative}.mock .bar{display:flex;gap:6px;margin-bottom:14px}.mock .bar b{width:10px;height:10px;border-radius:50%;background:#e4e4e7}.mock .bar b:first-child{background:#fca5a5}.mock .bar b:nth-child(2){background:#fcd34d}.mock .bar b:nth-child(3){background:#86efac}
.mock .row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--line);font-size:14.5px}.mock .row:last-child{border:0}.tick{color:var(--ok);font-weight:800}
.fl{position:absolute;background:#fff;color:var(--ink);border-radius:14px;padding:12px 16px;box-shadow:0 20px 44px rgba(0,0,0,.4);font-size:13.5px;font-weight:600;display:flex;gap:10px;align-items:center;animation:fy 6s ease-in-out infinite}.fl svg{color:var(--or);flex:none}.fl small{display:block;font-weight:500;color:var(--mut);font-size:12px}
.fl.a{left:-38px;bottom:26px}.fl.b{right:-16px;top:-8px;animation-delay:-3s}
@keyframes fy{50%{transform:translateY(-8px)}}
.strip{background:#fff;border-bottom:1px solid var(--line)}.strip .w{display:grid;grid-template-columns:repeat(4,1fr);padding:0 24px}
.strip .w>div{padding:28px 18px;text-align:center;border-right:1px solid var(--line)}.strip .w>div:last-child{border:0}.strip b{display:block;font:800 26px/1.2 Manrope,Inter,sans-serif;color:var(--g);letter-spacing:-.6px}.strip span{font-size:13.5px;color:var(--mut)}
section{padding:92px 0}.sec h2{font-size:clamp(28px,3.8vw,42px);letter-spacing:-1.1px;margin:0 0 12px;line-height:1.12;font-weight:800}.sec .sub{color:var(--mut);max-width:660px;margin:0 0 46px;font-size:17.5px}.ctr{text-align:center}.ctr .sub{margin-left:auto;margin-right:auto}
.eb{display:inline-block;font-size:12.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--or2);margin-bottom:12px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}.grid2{display:grid;grid-template-columns:repeat(2,1fr);gap:22px}
.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:30px;transition:.2s}.card:hover{transform:translateY(-4px);box-shadow:0 22px 48px rgba(24,24,27,.09);border-color:#fdba74}.card h3{margin:16px 0 8px;font-size:19px;letter-spacing:-.3px}.card p{margin:0;color:var(--mut);font-size:15.5px}
.ic{width:48px;height:48px;border-radius:14px;background:var(--orl);color:var(--or2);display:grid;place-items:center}.ic svg{width:23px;height:23px}
.step{counter-increment:s;position:relative}.step .ic{background:linear-gradient(135deg,var(--or3),var(--or));color:#fff;box-shadow:0 10px 22px rgba(234,88,12,.3)}.step .ic:before{content:counter(s);font:800 20px/1 Manrope,sans-serif}.step .ic svg{display:none}
.two .card{display:flex;flex-direction:column}.two .card ul{list-style:none;padding:0;margin:16px 0 22px;flex:1}.two .card li{padding:6px 0 6px 28px;position:relative;color:var(--mut);font-size:15.5px}.two .card li:before{content:"\2713";position:absolute;left:0;color:var(--ok);font-weight:800}
.tag{display:inline-block;font-size:12px;font-weight:700;padding:4px 10px;border-radius:99px;background:#ecfdf3;color:#15803d;margin-left:8px;vertical-align:middle}.tag.ai{background:#fff1e8;color:var(--or2)}
.demo{background:linear-gradient(180deg,#18181b,#202024);color:#fff}.demo .sub{color:#d4d4d8}.dbox{background:#fff;color:var(--ink);border-radius:22px;padding:26px;display:grid;grid-template-columns:1fr 1.2fr;gap:26px;box-shadow:0 30px 70px rgba(0,0,0,.4)}
.dbox h4{margin:0 0 12px;font-size:13px;color:var(--mut);text-transform:uppercase;letter-spacing:.8px}.po{background:#fafafa;border:1px dashed #d4d4d8;border-radius:12px;padding:16px;font:13px/1.6 "IBM Plex Mono",ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap}
.dt{width:100%;border-collapse:collapse;font-size:14px}.dt th,.dt td{padding:9px 6px;border-bottom:1px solid var(--line);text-align:left}.dt th{color:var(--mut);font-weight:600;font-size:12.5px}
.chips{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:12px}.chip{font-size:13px;background:#f4f4f5;border-radius:9px;padding:8px 11px;opacity:.3;transition:.25s}.chip.on{opacity:1;background:#ecfdf3}.chip.on:before{content:"\2713 ";color:var(--ok);font-weight:800}
.prog{height:8px;background:#eceef2;border-radius:9px;overflow:hidden;margin:14px 0}.prog i{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--or3),var(--or));transition:width .3s}
.price{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;align-items:stretch}.pc{background:#fff;border:1px solid var(--line);border-radius:22px;padding:34px 30px;display:flex;flex-direction:column;position:relative;transition:.2s}.pc:hover{box-shadow:0 22px 48px rgba(24,24,27,.08)}.pc.hot{border:2px solid var(--or);box-shadow:0 24px 54px rgba(234,88,12,.16)}
.pc .badge{position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:var(--or);color:#fff;font-size:12px;font-weight:700;padding:5px 14px;border-radius:99px;white-space:nowrap}
.pc h3{margin:0;font-size:21px}.pc .amt{font:800 46px/1.1 Manrope,Inter,sans-serif;letter-spacing:-1.8px;margin:16px 0 4px}.pc .amt small{font:500 15px Inter,sans-serif;letter-spacing:0;color:var(--mut)}.pc .tg{color:var(--mut);font-size:14.5px;margin:0 0 20px}
.pc ul{list-style:none;margin:0 0 28px;padding:0;flex:1}.pc li{padding:7px 0 7px 28px;position:relative;font-size:15px}.pc li:before{content:"\2713";position:absolute;left:0;color:var(--ok);font-weight:800}
.faq details{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px 22px;margin-bottom:12px;transition:.15s}.faq details[open]{border-color:#fdba74;box-shadow:0 10px 26px rgba(24,24,27,.06)}.faq summary{cursor:pointer;font-weight:600;list-style:none;display:flex;justify-content:space-between;gap:12px}.faq summary::-webkit-details-marker{display:none}.faq summary:after{content:"+";font-size:22px;line-height:1;color:var(--or)}.faq details[open] summary:after{content:"\2212"}.faq p{color:var(--mut);margin:12px 0 0}
.band{background:radial-gradient(500px 260px at 100% 0,rgba(255,255,255,.22),transparent 60%),linear-gradient(120deg,var(--or2),var(--or3));color:#fff;border-radius:28px;padding:64px 40px;text-align:center}.band h2{margin:0 0 10px;font-size:clamp(26px,3.4vw,38px);letter-spacing:-.9px}.band p{margin:0 0 26px;opacity:.95;font-size:17px}.band .btn{background:var(--g);box-shadow:0 12px 28px rgba(0,0,0,.28)}
footer{background:var(--g);color:#a1a1aa;padding:60px 0 28px;font-size:14.5px}.fg{display:grid;grid-template-columns:1.5fr 1fr 1fr;gap:36px}.fg h5{color:#fff;margin:0 0 12px;font-size:14px;letter-spacing:.3px}.fg a{display:block;color:#a1a1aa;text-decoration:none;padding:4px 0}.fg a:hover{color:#fff}.fg a.logo{display:flex;padding:0}.fg p{margin:14px 0 0;max-width:340px;line-height:1.6}
.fb{border-top:1px solid rgba(255,255,255,.09);margin-top:40px;padding-top:22px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:13.5px}
.sp{min-height:calc(100vh - 68px);display:grid;grid-template-columns:1fr 1fr}
.sp .as{background:radial-gradient(620px 420px at 100% 0,rgba(251,146,60,.32),transparent 60%),radial-gradient(500px 360px at 0 100%,rgba(234,88,12,.2),transparent 60%),linear-gradient(160deg,#18181b,#27272a);color:#fff;padding:72px 48px;display:flex;flex-direction:column;justify-content:center;position:relative;overflow:hidden}
.sp .as:before{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:42px 42px;-webkit-mask-image:linear-gradient(180deg,#000,transparent 80%);mask-image:linear-gradient(180deg,#000,transparent 80%)}
.sp .ai{position:relative;max-width:480px}.sp .as h2{font-size:clamp(28px,3.2vw,42px);line-height:1.1;letter-spacing:-1.2px;margin:0 0 14px;font-weight:800}.sp .as h2 em{font-style:normal;color:var(--or3)}
.sp .as p{color:#d4d4d8;margin:0;font-size:17px}.sp .as ul{list-style:none;padding:0;margin:30px 0 0;display:grid;gap:18px}.sp .as li{display:flex;gap:14px;align-items:flex-start;color:#a1a1aa;font-size:14.5px;line-height:1.5}.sp .as li strong{color:#fff;font-size:15.5px}
.sp .as li b{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:rgba(251,146,60,.16);color:var(--or3);flex:none}.sp .as li b svg{width:19px;height:19px}
.sp .fm{display:grid;place-items:center;padding:56px 28px;background:#fff}
.ab{width:100%;max-width:420px}.ab h1{font-size:32px;color:var(--ink);letter-spacing:-1px;margin:0 0 8px;line-height:1.15}.ab p.s{color:var(--mut);margin:0 0 26px}
label{display:block;font-size:14px;font-weight:600;margin:16px 0 7px}input[type=text],input[type=email],input[type=password],select{width:100%;padding:13px 15px;border:1px solid #d4d4d8;border-radius:12px;font:inherit;background:#fff;transition:.15s}input:hover,select:hover{border-color:#a1a1aa}input:focus,select:focus{outline:0;border-color:var(--or);box-shadow:0 0 0 4px rgba(234,88,12,.15)}
.r2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.chk{display:flex;gap:10px;align-items:flex-start;font-weight:400;font-size:14px;margin:16px 0;color:var(--mut)}.chk input{margin-top:4px;accent-color:var(--or);width:16px;height:16px;flex:none}.chk a{color:var(--or2)}.hp{position:absolute;left:-9999px}
.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:12px 15px;margin-bottom:14px;font-size:14.5px}.alt{text-align:center;margin-top:22px;color:var(--mut);font-size:14.5px}.alt a{color:var(--or2);font-weight:600}
.pw{position:relative}.pw input{padding-right:64px}.pw button{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:0;color:var(--mut);font:600 13px Inter,sans-serif;cursor:pointer;padding:8px}
.acc{max-width:820px;margin:48px auto;padding:0 24px}.ah{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.av{width:58px;height:58px;border-radius:16px;background:linear-gradient(135deg,var(--or3),var(--or));color:#fff;display:grid;place-items:center;font:800 24px Manrope,sans-serif;box-shadow:0 10px 24px rgba(234,88,12,.3)}.ah h1{font-size:28px;color:var(--ink);letter-spacing:-.6px;margin:0;line-height:1.2}.ah p{margin:0;color:var(--mut)}.ah .st{margin-left:auto}
.tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:26px 0 8px}.tile{border:1px solid var(--line);border-radius:14px;padding:16px 18px;background:#fafafa}.tile small{display:block;color:var(--mut);font-size:12.5px;font-weight:600;text-transform:uppercase;letter-spacing:.6px}.tile b{display:block;font:700 18px Manrope,Inter,sans-serif;margin-top:4px;word-break:break-word}.bar2{height:6px;background:#e4e4e7;border-radius:9px;margin-top:10px;overflow:hidden}.bar2 i{display:block;height:100%;background:linear-gradient(90deg,var(--or3),var(--or))}
.st{display:inline-block;padding:4px 12px;border-radius:99px;font-size:13px;font-weight:700;background:#ecfdf3;color:#15803d}.st.off{background:#fef2f2;color:#b91c1c}
.up{margin-top:20px;padding:20px;border:1px solid #fed7aa;border-radius:16px;background:linear-gradient(180deg,#fff7ed,#fff)}
.lg h3{margin:26px 0 6px;font-size:17px}.lg p{margin:0;color:var(--mut)}
.skip{position:absolute;left:-999px;top:8px;background:#fff;color:#000;padding:8px 14px;border-radius:8px;z-index:99}.skip:focus{left:8px}
a:focus-visible,button:focus-visible,summary:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid #fdba74;outline-offset:2px}
section[id]{scroll-margin-top:76px}
@media(prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important;scroll-behavior:auto!important}}
@media(max-width:820px){.sp{grid-template-columns:1fr}.sp .as{display:none}.sp .fm{padding:40px 20px;align-items:start}}
@media(max-width:860px){.hero .w,.dbox{grid-template-columns:1fr}.grid3,.price,.grid2{grid-template-columns:1fr}.strip .w{grid-template-columns:repeat(2,1fr)}.strip .w>div:nth-child(2){border-right:0}.strip .w>div:nth-child(-n+2){border-bottom:1px solid var(--line)}.nav nav a.hide{display:none}section{padding:60px 0}.hero{padding:56px 0 56px}.fl{display:none}.fg{grid-template-columns:1fr 1fr}.fg>div:first-child{grid-column:1/-1}.tiles{grid-template-columns:1fr}}
@media(max-width:640px){.w{padding:0 18px}.nav .w{gap:8px;height:60px}.logo{white-space:nowrap;font-size:17px}.logo i{width:30px;height:30px}.nav nav{gap:0;flex-wrap:nowrap}.nav nav a{padding:8px 9px;font-size:14px;white-space:nowrap}.nav nav a.hide2{display:none}.nav nav a.btn{padding:9px 13px;margin-left:4px}.pc{padding:26px 22px}.dbox{padding:18px}.r2{grid-template-columns:1fr;gap:0}.band{padding:40px 22px}.card{padding:24px}.chips{grid-template-columns:1fr}.fb{flex-direction:column}.ah .st{margin-left:0}}
.bento{grid-template-columns:repeat(3,1fr)}
.bento .card{position:relative;overflow:hidden;min-height:190px}
.bento .card:nth-child(1),.bento .card:nth-child(4),.bento .card:nth-child(6){grid-column:span 2}
.bento .card:nth-child(1){background:radial-gradient(420px 220px at 100% 0,rgba(251,146,60,.35),transparent 65%),linear-gradient(135deg,#18181b,#2a2a2f);color:#fff;border-color:#27272a}
.bento .card:nth-child(1) p{color:#d4d4d8}.bento .card:nth-child(1) .ic{background:rgba(251,146,60,.16);color:#fdba74}
.bento .card:nth-child(4){background:linear-gradient(135deg,#fff7ed,#fff)}
.bento .card .ic{transition:.2s}.bento .card:hover .ic{transform:scale(1.08) rotate(-4deg)}
.btn{position:relative;overflow:hidden}.btn:after{content:"";position:absolute;top:0;left:-120%;width:60%;height:100%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.28),transparent);transform:skewX(-20deg);transition:left .5s}.btn:hover:after{left:130%}
.sec.alt{background:linear-gradient(180deg,#fff,#fafafa)}
.hero h1 em{display:inline-block}
.card h3{font-weight:800}
.pc.hot{background:linear-gradient(180deg,#fff,#fff7ed)}
@media(max-width:860px){.bento{grid-template-columns:1fr}.bento .card:nth-child(n){grid-column:auto}}
.art{pointer-events:none;position:absolute}
.art.h{right:-170px;top:-90px;width:640px;height:640px;opacity:.55;animation:spin 140s linear infinite;z-index:0;filter:drop-shadow(0 30px 60px rgba(0,0,0,.5))}
.art.a{right:-150px;bottom:-130px;width:460px;height:460px;opacity:.5;animation:spin 160s linear infinite reverse}
.band{position:relative;overflow:hidden}.band>*:not(.art){position:relative;z-index:1}.art.b{right:-110px;top:-110px;width:380px;height:380px;opacity:.28;animation:spin 120s linear infinite;z-index:0}
.art.d{right:18px;bottom:-6px;width:210px;height:auto}
.bento .card:nth-child(1){padding-right:250px}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:860px){.art.h{width:420px;height:420px;right:-160px;top:-60px;opacity:.35}.bento .card:nth-child(1){padding-right:30px;padding-bottom:190px}.art.d{right:50%;transform:translateX(50%)}}
CSS;
	}

	private static function art_flange( $id, $cls ) {
		$h = '';
		for ( $i = 0; $i < 8; $i++ ) { $a = deg2rad( $i * 45 + 22.5 ); $x = round( 200 + 148 * cos( $a ), 1 ); $y = round( 200 + 148 * sin( $a ), 1 ); $h .= '<circle cx="' . $x . '" cy="' . $y . '" r="15" fill="url(#' . $id . 'h)" stroke="rgba(255,255,255,.28)" stroke-width="1.5"/><circle cx="' . ( $x - 3 ) . '" cy="' . ( $y - 3 ) . '" r="4" fill="rgba(255,255,255,.16)"/>'; }
		$r = ''; foreach ( array( 128, 120, 112, 104 ) as $k ) { $r .= '<circle cx="200" cy="200" r="' . $k . '" fill="none" stroke="rgba(255,255,255,' . ( $k % 16 === 0 ? '.14' : '.07' ) . ')" stroke-width="1.5"/>'; }
		return '<svg class="art ' . esc_attr( $cls ) . '" viewBox="0 0 400 400" aria-hidden="true" focusable="false"><defs><radialGradient id="' . $id . 'g" cx=".35" cy=".3" r=".9"><stop offset="0" stop-color="#6b6b75"/><stop offset=".55" stop-color="#3a3a42"/><stop offset="1" stop-color="#202024"/></radialGradient><radialGradient id="' . $id . 'h" cx=".4" cy=".35" r=".8"><stop offset="0" stop-color="#0c0c0e"/><stop offset="1" stop-color="#2b2b31"/></radialGradient><linearGradient id="' . $id . 'r" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fb923c"/><stop offset="1" stop-color="#ea580c"/></linearGradient></defs><circle cx="200" cy="200" r="192" fill="url(#' . $id . 'g)" stroke="rgba(255,255,255,.22)" stroke-width="2"/><circle cx="200" cy="200" r="178" fill="none" stroke="url(#' . $id . 'r)" stroke-width="3" opacity=".85"/>' . $r . '<circle cx="200" cy="200" r="88" fill="#2c2c33" stroke="rgba(255,255,255,.2)" stroke-width="2"/><circle cx="200" cy="200" r="62" fill="url(#' . $id . 'h)" stroke="rgba(255,255,255,.3)" stroke-width="2"/>' . $h . '</svg>';
	}

	private static function art_docs() {
		return '<svg class="art d" viewBox="0 0 220 180" aria-hidden="true" focusable="false"><g fill="none"><rect x="70" y="8" width="120" height="150" rx="12" fill="#3a3a42" stroke="rgba(255,255,255,.18)"/><rect x="48" y="22" width="120" height="150" rx="12" fill="#2f2f36" stroke="rgba(255,255,255,.22)"/><rect x="26" y="36" width="120" height="140" rx="12" fill="#fff"/><rect x="42" y="54" width="60" height="8" rx="4" fill="#18181b"/><rect x="42" y="74" width="88" height="6" rx="3" fill="#d4d4d8"/><rect x="42" y="90" width="76" height="6" rx="3" fill="#d4d4d8"/><rect x="42" y="106" width="88" height="6" rx="3" fill="#d4d4d8"/><circle cx="122" cy="150" r="14" fill="#16a34a"/><path d="M115 150l5 5 9-10" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><rect x="42" y="144" width="40" height="12" rx="6" fill="#ffedd5"/></g></svg>';
	}

	private static function ic( $n ) {
		$p = array(
			'gear' => '<path d="M12 15a3 3 0 100-6 3 3 0 000 6z"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
			'search' => '<path d="M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.3-4.3"/>',
			'ruler' => '<path d="M3 17L17 3l4 4L7 21l-4-4zM8 12l2 2M11 9l2 2M14 6l2 2"/>',
			'wave' => '<path d="M2 12h4l3-8 4 16 3-8h6"/>',
			'truck' => '<path d="M1 4h13v12H1zM14 8h4l4 4v4h-8zM5.5 20a2 2 0 100-4 2 2 0 000 4zM17.5 20a2 2 0 100-4 2 2 0 000 4z"/>',
			'flask' => '<path d="M9 3h6M10 3v6L4 19a2 2 0 001.7 3h12.6A2 2 0 0020 19l-6-10V3"/>',
			'file' => '<path d="M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9zM14 3v6h6M8 13h8M8 17h8"/>',
			'layers' => '<path d="M12 2l10 5-10 5L2 7zM2 17l10 5 10-5M2 12l10 5 10-5"/>',
			'archive' => '<path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>',
			'table' => '<path d="M3 3h18v18H3zM3 9h18M3 15h18M9 3v18"/>',
			'spark' => '<path d="M12 3l2.1 5.9L20 11l-5.9 2.1L12 19l-2.1-5.9L4 11l5.9-2.1zM19 3v4M21 5h-4"/>',
			'lock' => '<path d="M5 11h14v10H5zM8 11V7a4 4 0 118 0v4"/>',
			'check' => '<path d="M20 6L9 17l-5-5"/>',
			'zap' => '<path d="M13 2L3 14h8l-1 8 10-12h-8z"/>',
			'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		);
		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( isset( $p[ $n ] ) ? $p[ $n ] : '' ) . '</svg>';
	}

	private static function head( $title ) {
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e( $title ) . '</title><link rel="icon" type="image/svg+xml" href="' . PDQ_Branding::logo() . '"><link rel="icon" type="image/png" sizes="32x32" href="' . esc_url( get_site_icon_url( 32 ) ) . '"><meta property="og:title" content="PipeDoc IQ"><meta property="og:description" content="Upload the PO or your items list. Get every inspection document."><meta name="theme-color" content="#18181b"><meta name="description" content="PipeDoc IQ turns a purchase order or an Excel items list into the complete inspection, test and dispatch document pack for forging and pipe-fitting manufacturers."><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Manrope:wght@700;800&amp;family=IBM+Plex+Mono:wght@400&amp;display=swap"><style>' . self::css() . '</style></head><body><a class="skip" href="#main">Skip to content</a>';
	}

	private static function nav() {
		$in = is_user_logged_in(); $h = esc_url( self::url( 'home' ) );
		echo '<header class="nav"><div class="w"><a class="logo" href="' . $h . '"><i>' . PDQ_Branding::mark() . '</i>PipeDoc IQ</a><nav>';
		echo '<a class="hide" href="' . $h . '#how">How it works</a><a class="hide" href="' . $h . '#demo">Demo</a><a class="hide" href="' . $h . '#docs">Documents</a><a href="' . esc_url( self::url( 'pricing' ) ) . '">Pricing</a>';
		if ( $in ) { echo '<a class="hide2" href="' . esc_url( self::url( 'account' ) ) . '">Account</a>'; if ( current_user_can( 'manage_options' ) ) { echo '<a class="btn sm" href="' . esc_url( self::app_url() ) . '">Open application</a>'; } }
		else { echo '<a href="' . esc_url( self::url( 'login' ) ) . '">Log in</a><a class="btn sm" href="' . esc_url( self::url( 'register' ) ) . '">Start free</a>'; }
		echo '</nav></div></header>';
	}

	private static function foot() {
		echo '<footer><div class="w"><div class="fg"><div><a class="logo" href="' . esc_url( self::url( 'home' ) ) . '"><i>' . PDQ_Branding::mark() . '</i>PipeDoc IQ</a><p>Inspection, test and dispatch documents for forging and pipe-fitting manufacturers &mdash; generated from your PO or items list.</p></div><div><h5>Product</h5><a href="' . esc_url( self::url( 'home' ) ) . '#how">How it works</a><a href="' . esc_url( self::url( 'home' ) ) . '#docs">Documents</a><a href="' . esc_url( self::url( 'pricing' ) ) . '">Pricing</a></div><div><h5>Account</h5><a href="' . esc_url( self::url( 'login' ) ) . '">Log in</a><a href="' . esc_url( self::url( 'register' ) ) . '">Create account</a><a href="' . esc_url( self::url( 'terms' ) ) . '">Terms</a><a href="' . esc_url( self::url( 'privacy' ) ) . '">Privacy</a></div></div><div class="fb"><span>&copy; ' . esc_html( gmdate( 'Y' ) ) . ' PipeDoc IQ</span><span>Designed &amp; developed by Tamal Roy Chowdhury</span></div></div></footer></body></html>';
	}

	public static function early_box() {
		if ( ! self::early() ) { return; }
		echo '<div style="max-width:760px;margin:0 auto 26px;background:linear-gradient(135deg,#FFF7ED,#FFEDD5);border:1px solid #FDBA74;border-radius:14px;padding:16px 20px;text-align:center;color:#431407"><strong style="font-size:16px">Early Access &mdash; every feature is free for all companies right now.</strong><div style="margin-top:4px;font-size:14px;color:#7C2D12">Use the full application with no payment while we grow with our first companies. It runs on shared capacity, so fair use applies: up to 100 document packs a month per company, files up to 10 MB each, and brief slowdowns are possible at busy times. The plans below show how pricing will work later; you will get clear notice before anything changes.</div></div>';
	}

	private static function pricing_html( $heading = true ) {
		if ( $heading ) { echo '<div class="ctr sec"><h2>Simple pricing. Start free.</h2><p class="sub">' . ( self::early() ? 'Everything is free during early access. These are the plans that will apply later.' : 'Every plan includes the full upload-PO-to-document-pack workflow. Pay only when you outgrow the free plan.' ) . '</p></div>'; }
		self::early_box(); echo '<div class="price">';
		foreach ( self::plans() as $k => $p ) {
			echo '<div class="pc' . ( ! empty( $p['hot'] ) ? ' hot' : '' ) . '">' . ( ! empty( $p['hot'] ) ? '<span class="badge">Most popular</span>' : '' ) . '<h3>' . self::e( $p['name'] ) . '</h3><div class="amt">' . ( '0' === (string) $p['price'] ? 'Free' : '&#8377;' . self::e( number_format_i18n( (float) $p['price'] ) ) . '<small> / month</small>' ) . ( self::early() && 'free' !== $k ? '<div style="font-size:12px;font-weight:700;color:#16A34A;margin:2px 0 6px">FREE during early access</div>' : '' ) . '</div><p class="tg">' . self::e( $p['tag'] ) . '</p><ul>';
			foreach ( $p['feat'] as $f ) { echo '<li>' . self::e( $f ) . '</li>'; }
			echo '</ul><a class="btn full ' . ( ! empty( $p['hot'] ) ? '' : 'dark' ) . '" href="' . esc_url( self::url( 'register' ) . '&plan=' . rawurlencode( $k ) ) . '">' . ( 'free' === $k ? 'Start free' : ( self::early() ? 'Join early access' : 'Choose ' . self::e( $p['name'] ) ) ) . '</a></div>';
		}
		echo '</div><p class="ctr" style="color:var(--mut);font-size:13.5px;margin-top:18px">Prices in INR, excl. GST. AI reading uses each company&rsquo;s own Google Gemini API key &mdash; Gemini usage is billed by Google, not by PipeDoc IQ.</p>';
	}

	private static function render( $p ) {
		$titles = array( 'home' => 'PipeDoc IQ — Inspection & dispatch documents from your PO', 'pricing' => 'Pricing — PipeDoc IQ', 'login' => 'Log in — PipeDoc IQ', 'register' => 'Create your account — PipeDoc IQ', 'account' => 'Your account — PipeDoc IQ', 'terms' => 'Terms of use — PipeDoc IQ', 'privacy' => 'Privacy — PipeDoc IQ' );
		self::head( $titles[ $p ] ); self::nav(); echo '<main id="main">';
		if ( 'home' === $p ) { self::page_home(); } elseif ( 'pricing' === $p ) { self::page_pricing(); } elseif ( 'login' === $p ) { self::page_login(); } elseif ( 'register' === $p ) { self::page_register(); } elseif ( 'terms' === $p || 'privacy' === $p ) { self::page_legal( $p ); } else { self::page_account(); }
		echo '</main><script>document.querySelectorAll("[data-pw]").forEach(function(b){b.onclick=function(){var i=document.getElementById(b.dataset.pw),s=i.type==="password";i.type=s?"text":"password";b.textContent=s?"Hide":"Show";b.setAttribute("aria-label",s?"Hide password":"Show password")}})</script>'; self::foot();
	}

	private static function page_pricing() {
		echo '<section class="sec"><div class="w">'; self::pricing_html(); echo '<div class="faq" style="max-width:760px;margin:50px auto 0">'; self::faq(); echo '</div></div></section>';
	}

	private static function faq() {
		$q = array(
			'I do not want to use AI. Can I upload an Excel list?' => 'Yes. Download the items template, fill Sl No, Material Code, Item Title, Quantity, Heat No and Remarks, and upload it as Excel, Word or CSV. Items are read directly from your file with no AI and no API key.',
			'Do I need to type the PO details again?' => 'No. Upload the PO, BOQ or spec sheet (PDF, Excel, Word, image). PipeDoc IQ reads customer, PO number, dates and every line item; you only review and press Generate.',
			'Where does the AI key come from?' => 'Each company adds its own Google Gemini API key inside the application. It is used only to read your uploaded documents and mill test certificates.',
			'Will the AI make up heat numbers or test values?' => 'Never. Heat numbers come from your Heat Number Management, test values from your certificates. Anything not found stays blank and is flagged before you issue the document.',
			'Can I use my own letterhead?' => 'Yes. Upload your letterhead as a Word file once. Every Word report is then issued on it.',
			'Which documents are generated?' => 'Offer list, visual & dimensional reports, marking certificate, UT / MPI / LPI reports (internal and joint witness), test-sample identification, packing & colour coding, Material Test Certificate, lab sample-request letter and IBR Form III-C.',
		);
		foreach ( $q as $a => $b ) { echo '<details><summary>' . self::e( $a ) . '</summary><p>' . self::e( $b ) . '</p></details>'; }
	}

	private static function page_home() {
		$docs = array( 'Offer List', 'Visual & Dimensional Report', 'Marking & Dimensional Certificate', 'UT Report (internal + joint)', 'MPI Report (internal + joint)', 'LPI / DP Test Report', 'Test Sample Identification', 'Packing & Colour Coding', 'Material Test Certificate', 'NABL Sample Request', 'IBR Form III-C' );
		$reg = esc_url( self::url( 'register' ) );
		echo '<section class="hero">' . self::art_flange( 'hf', 'h' ) . '<div class="w"><div><span class="pill">Built for forging &amp; pipe-fitting manufacturers</span><h1>Upload the PO.<br><em>Get every inspection document.</em></h1><p class="lead">Upload a purchase order, or just your Excel items list. PipeDoc IQ prepares the complete inspection, test and dispatch document pack &mdash; on your own letterhead, in minutes.</p><div class="cta"><a class="btn" href="' . $reg . '">Start free</a><a class="btn ghost" href="#demo">Watch the demo</a></div><p class="note">No credit card &middot; Free plan forever &middot; Your data stays in your own workspace</p></div>';
		echo '<div class="stage"><div class="mock"><div class="bar"><b></b><b></b><b></b></div><div class="row"><span><strong>PO SAMPLE/2026/001</strong><br><small style="color:var(--mut)">3 items &middot; 2 heats</small></span><span class="tick">Read &#10003;</span></div>';
		foreach ( array_slice( $docs, 0, 6 ) as $d ) { echo '<div class="row"><span>' . self::e( $d ) . '</span><span class="tick">&#10003;</span></div>'; }
		echo '<div class="row"><span style="color:var(--mut)">+ 5 more documents</span><span class="tick">&#10003;</span></div></div><div class="fl a">' . self::ic( 'zap' ) . '<span>11 documents<small>generated in one click</small></span></div><div class="fl b">' . self::ic( 'shield' ) . '<span>No invented values<small>blank until you enter them</small></span></div></div></div></section>';
		echo '<div class="strip"><div class="w"><div><b>2 ways in</b><span>PO upload or Excel items list</span></div><div><b>15</b><span>ready report templates</span></div><div><b>0 retyping</b><span>customer, PO, items, heats</span></div><div><b>Your letterhead</b><span>on every Word document</span></div></div></div>';
		echo '<section class="sec" id="how"><div class="w"><div class="ctr"><span class="eb">How it works</span><h2>From PO to document pack in three steps</h2><p class="sub">The manual copy-paste between PO, Excel sheets and Word reports is gone.</p></div><div class="grid3" style="counter-reset:s">';
		foreach ( array( array( 'Upload', 'Drop in the PO, BOQ and spec sheet &mdash; or your items list as Excel, Word or CSV.' ), array( 'Review', 'PipeDoc IQ fills customer, PO, items and heat numbers from your database. You check the table once.' ), array( 'Download', 'One click creates every report, numbered and archived. Download all as a single ZIP.' ) ) as $s ) { echo '<div class="card step"><div class="ic"></div><h3>' . $s[0] . '</h3><p>' . $s[1] . '</p></div>'; }
		echo '</div></div></section>';
		echo '<section class="sec" style="padding-top:0"><div class="w"><div class="ctr"><span class="eb">Two ways in</span><h2>Start from whatever you already have</h2><p class="sub">Pick the route that fits the day &mdash; both end in the same complete document pack.</p></div><div class="grid2 two"><div class="card"><div class="ic">' . self::ic( 'table' ) . '</div><h3>Excel items list<span class="tag">No AI needed</span></h3><ul><li>Download the template, fill Sl No, Material Code, Item Title, Quantity, Heat No, Remarks</li><li>Upload Excel, Word or CSV &mdash; items are read straight from your file</li><li>Needs no AI key and costs nothing to read</li></ul></div><div class="card"><div class="ic">' . self::ic( 'spark' ) . '</div><h3>PO / BOQ upload<span class="tag ai">AI reading</span></h3><ul><li>Upload the PO, BOQ or spec sheet as PDF, Excel, Word or photo</li><li>Customer, PO number, dates and every line item are read for you</li><li>Uses your own Gemini API key &mdash; you stay in control</li></ul></div></div></div></section>';
		echo '<section class="sec demo" id="demo"><div class="w"><div class="ctr"><span class="eb" style="color:#fdba74">Live demo</span><h2 style="color:#fff">See it work</h2><p class="sub">A sample PO &mdash; nothing is uploaded and no real data is used.</p></div><div class="dbox"><div><h4>1 &middot; Sample purchase order</h4><div class="po">SAMPLE CUSTOMER LTD
PO No : SAMPLE/2026/001
PO Date : 05-11-2026

1  FLANGE SOW CS 6" CL300 RF SA105   Qty 3
2  FLANGE BL CS 150NB CL300 RF SA105 Qty 2
3  FLANGE WN CS 4" CL600 RF SA182 F22 Qty 4</div><p style="margin:14px 0 0"><button class="btn" id="d1">Read with AI</button></p></div><div><h4>2 &middot; Extracted &amp; matched</h4><div id="d2" aria-live="polite"><p style="color:var(--mut);margin:0">Press &ldquo;Read with AI&rdquo; to extract the items.</p></div><div id="d3" style="display:none"><div class="prog"><i id="dp"></i></div><div class="chips">';
		foreach ( $docs as $d ) { echo '<div class="chip">' . self::e( $d ) . '</div>'; }
		echo '</div></div></div></div></div></section>';
		echo '<section class="sec" id="docs"><div class="w"><div class="ctr"><span class="eb">Documents</span><h2>Every document your buyer and TPI ask for</h2><p class="sub">Specified dimensions come from published ASME B16.5 tables. Observed and test values are never invented &mdash; they stay blank until you enter them.</p></div><div class="grid3 bento">';
		foreach ( array( array( 'search', 'Inspection', 'Offer list, visual &amp; dimensional reports, marking &amp; dimensional certificate.' ), array( 'wave', 'NDT', 'UT, MPI and LPI reports &mdash; internal 100% and joint-witness 10%.' ), array( 'truck', 'Quality &amp; dispatch', 'MTC per heat, IBR Form III-C, lab sample request, packing &amp; colour coding.' ), array( 'flask', 'Heat test data', 'Store chemical and mechanical values once per heat, or let AI read the mill TC.' ), array( 'file', 'Your letterhead', 'Each company uploads its own Word letterhead. Reports are issued on it.' ), array( 'archive', 'Archive &amp; revisions', 'Every document is numbered, versioned and kept in the archive.' ) ) as $f ) { echo '<div class="card"><div class="ic">' . self::ic( $f[0] ) . '</div><h3>' . $f[1] . '</h3><p>' . $f[2] . '</p>' . ( 'search' === $f[0] ? self::art_docs() : '' ) . '</div>'; }
		echo '</div></div></section>';
		echo '<section class="sec" id="pricing" style="background:#fff"><div class="w">'; self::pricing_html(); echo '</div></section>';
		echo '<section class="sec"><div class="w faq" style="max-width:780px"><div class="ctr"><span class="eb">FAQ</span><h2>Questions</h2></div><div style="margin-top:28px">'; self::faq(); echo '</div></div></section>';
		echo '<section style="padding-top:0"><div class="w"><div class="band">' . self::art_flange( 'bf', 'b' ) . '<h2>Stop retyping your PO into reports.</h2><p>Create a free account and generate your first document pack today.</p><a class="btn" href="' . $reg . '">Start free</a></div></div></section>';
		echo '<script>(function(){var $=function(i){return document.getElementById(i)},items=[["1","FLANGE SOW CS 6\\" CL300 RF","A105","3","Heat B025"],["2","FLANGE BL CS 150NB CL300 RF","A105","2","Heat B025"],["3","FLANGE WN CS 4\\" CL600 RF","A182 F22","4","Heat F22-07"]];var run=false;
$("d1").onclick=function(){var b=this;b.disabled=true;b.textContent="Reading\\u2026";setTimeout(function(){var h="<table class=\\"dt\\"><tr><th>#</th><th>Description</th><th>Grade</th><th>Qty</th><th>Heat (from database)</th></tr>";items.forEach(function(r){h+="<tr><td>"+r[0]+"</td><td>"+r[1]+"</td><td>"+r[2]+"</td><td>"+r[3]+"</td><td>"+r[4]+"</td></tr>"});h+="</table><p style=\\"margin:14px 0 0\\"><button class=\\"btn dark\\" id=\\"d4\\">Generate document pack</button></p>";$("d2").innerHTML=h;b.textContent="Read \\u2713";$("d4").onclick=gen},900)};
function gen(){if(run)return;run=true;this.disabled=true;$("d3").style.display="block";var c=document.querySelectorAll(".chip"),i=0;var t=setInterval(function(){if(i<c.length){c[i].classList.add("on");i++;$("dp").style.width=Math.round(i/c.length*100)+"%"}else{clearInterval(t);$("d4").textContent="Pack ready \\u2713 (sample)"}},260)}})();</script>';
	}


	private static function page_legal( $p ) {
		$t = array(
			'terms' => array( 'Terms of use', array(
				'Using PipeDoc IQ' => 'PipeDoc IQ prepares draft inspection, test and dispatch documents from the purchase orders, test certificates and data you provide. You are responsible for checking every document before it is issued, signed or sent to a customer or inspection agency.',
				'Your data is yours' => 'Documents, templates, heat data and company details you add remain your property. Do not upload material you have no right to use.',
				'No invented values' => 'The application fills only values found in your documents or in published standard tables. Values it cannot find are left blank and flagged. It is not a substitute for actual inspection or testing.',
				'AI reading' => 'Reading of purchase orders and certificates uses your own Google Gemini API key. Google\'s terms and charges for Gemini apply to that usage.',
				'Workspaces' => 'Each registered company gets its own separate workspace. You must not try to access another company\'s data. Accounts that try may be suspended.',
				'Plans and payment' => 'Free accounts have monthly limits shown on the pricing page. Paid plans are switched on by the administrator after payment is received through the payment link provided. Prices and limits may change with notice. Fees already paid are refunded only as the administrator agrees in writing.',
				'Suspension' => 'Accounts may be limited or suspended for misuse, non-payment of a paid plan, or breach of these terms.',
				'Liability' => 'The service is provided as is. To the extent permitted by law, the developer is not liable for losses arising from documents issued without proper review.',
			) ),
			'privacy' => array( 'Privacy', array(
				'What we collect' => 'Your name, company name, e-mail address and the plan you chose when you register, plus the documents and data you add while using the application.',
				'How it is used' => 'To create and run your account and to prepare your documents. We do not sell your data.',
				'AI processing' => 'When you press Read documents, the uploaded files or pasted text are sent to Google Gemini using your own API key so the PO can be read. Nothing is sent unless you start a reading.',
				'Storage' => 'Data is stored in the WordPress database and upload folder of the installation that hosts PipeDoc IQ. Run it only on a trusted, access-controlled server.',
				'Retention' => 'Your data stays while your account is active. When your account is closed the administrator will delete your workspace data on request, except where the law requires records to be kept.',
				'Cookies' => 'Only the login cookies WordPress needs to keep you signed in are used. No advertising cookies.',
				'Your choices' => 'You can ask the administrator to correct or delete your account and data at any time.',
			) ),
		);
		echo '<div class="acc lg" style="max-width:780px"><div class="card" style="padding:38px"><h1 style="font-size:32px;color:var(--ink);letter-spacing:-.8px;margin:0 0 8px">' . self::e( $t[ $p ][0] ) . '</h1><p style="color:var(--mut);margin:0 0 18px">Plain-language summary, effective ' . esc_html( gmdate( 'j F Y' ) ) . '. Questions: ' . esc_html( get_option( 'admin_email' ) ) . '.</p>';
		foreach ( $t[ $p ][1] as $h => $b ) { echo '<h3 style="margin:20px 0 4px;font-size:17px">' . self::e( $h ) . '</h3><p style="margin:0;color:var(--mut)">' . self::e( $b ) . '</p>'; }
		echo '</div></div>';
	}

	private static function notice() { $m = self::take_flash(); return $m ? '<div class="err">' . self::e( $m ) . '</div>' : ''; }

	private static function auth_panel( $h, $p ) {
		$li = array( array( 'zap', 'Two ways to start', 'Upload a PO, or just an Excel items list.' ), array( 'shield', 'Nothing invented', 'Heat and test values come only from your data.' ), array( 'lock', 'Your own workspace', 'Each company is kept completely separate.' ) );
		$o = '<aside class="as">' . self::art_flange( 'af', 'a' ) . '<div class="ai"><h2>' . $h . '</h2><p>' . $p . '</p><ul>';
		foreach ( $li as $x ) { $o .= '<li><b>' . self::ic( $x[0] ) . '</b><span><strong>' . $x[1] . '</strong><br>' . $x[2] . '</span></li>'; }
		return $o . '</ul></div></aside>';
	}

	private static function page_login() {
		echo '<div class="sp">' . self::auth_panel( 'Welcome back to <em>PipeDoc IQ</em>', 'Pick up where you left off &mdash; your documents, heats and templates are waiting.' ) . '<div class="fm"><form class="ab" method="post" action="' . esc_url( self::url( 'login' ) ) . '"><h1>Log in</h1><p class="s">Open your PipeDoc IQ workspace.</p>' . self::notice();
		wp_nonce_field( 'pdqs_login', '_pdqs' );
		echo '<input type="hidden" name="pdqs_action" value="login"><label for="l1">E-mail or username</label><input id="l1" type="text" name="log" required autocomplete="username"><label for="l2">Password</label><div class="pw"><input id="l2" type="password" name="pwd" required autocomplete="current-password"><button type="button" data-pw="l2" aria-label="Show password">Show</button></div><label class="chk"><input type="checkbox" name="rem" value="1"><span>Keep me logged in</span></label><button class="btn full" type="submit">Log in</button><div class="alt"><a href="' . esc_url( wp_make_link_relative( wp_lostpassword_url() ) ) . '">Forgot password?</a> &middot; New here? <a href="' . esc_url( self::url( 'register' ) ) . '">Create a free account</a></div></form></div></div>';
	}

	private static function page_register() {
		$sel = isset( $_GET['plan'] ) ? sanitize_key( $_GET['plan'] ) : 'free';
		echo '<div class="sp">' . self::auth_panel( 'Your first document pack, <em>in minutes</em>', ( self::early() ? 'Early access: every feature is free. No credit card needed.' : 'Free to start. No credit card. Upgrade only when you outgrow the free plan.' ) ) . '<div class="fm"><form class="ab" method="post" action="' . esc_url( self::url( 'register' ) ) . '"><h1>Create your account</h1><p class="s">' . ( self::early() ? 'Every feature is unlocked during early access.' : 'Paid plans begin on Free until your payment is confirmed.' ) . '</p>' . self::notice();
		wp_nonce_field( 'pdqs_register', '_pdqs' );
		echo '<input type="hidden" name="pdqs_action" value="register"><div class="hp"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div><div class="r2"><div><label for="r1">Your name</label><input id="r1" type="text" name="name" required autocomplete="name"></div><div><label for="r2">Company name</label><input id="r2" type="text" name="company" required autocomplete="organization"></div></div><label for="r3">Work e-mail</label><input id="r3" type="email" name="email" required autocomplete="email"><div class="r2"><div><label for="r4">Password (min. 8)</label><div class="pw"><input id="r4" type="password" name="pwd" required minlength="8" autocomplete="new-password"><button type="button" data-pw="r4" aria-label="Show password">Show</button></div></div><div><label for="r5">Plan</label><select id="r5" name="plan">';
		foreach ( self::plans() as $k => $p ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $sel, $k, false ) . '>' . self::e( $p['name'] ) . ( '0' === (string) $p['price'] ? ' (₹0)' : ' — ₹' . number_format_i18n( (float) $p['price'] ) . '/mo' ) . '</option>'; }
		echo '</select></div></div><label class="chk"><input type="checkbox" name="terms" value="1" required><span>I agree to the <a href="' . esc_url( self::url( 'terms' ) ) . '" target="_blank" rel="noopener">Terms</a> and <a href="' . esc_url( self::url( 'privacy' ) ) . '" target="_blank" rel="noopener">Privacy</a> notice, and will review every generated document before issuing it.</span></label><button class="btn full" type="submit">Create account</button><div class="alt">Already registered? <a href="' . esc_url( self::url( 'login' ) ) . '">Log in</a></div></form></div></div>';
	}


	private static function page_account() {
		$u = wp_get_current_user(); $plans = self::plans(); $adm = current_user_can( 'manage_options' ) && ! self::is_member();
		$plan = self::plan_of( $u->ID ); $req = get_user_meta( $u->ID, 'pdqs_plan_req', true ); $st = get_user_meta( $u->ID, 'pdqs_status', true ); $act = $adm || 'active' === $st;
		$lim = self::limits( $u->ID ); $used = self::used( $u->ID ); $pay = get_option( 'pdqsite_pay', array() ); $pay = is_array( $pay ) ? $pay : array();
		$ini = strtoupper( substr( trim( $u->display_name ) ? trim( $u->display_name ) : 'P', 0, 1 ) );
		echo '<div class="acc"><div class="card" style="padding:34px"><div class="ah"><div class="av">' . self::e( $ini ) . '</div><div><h1>Hello, ' . self::e( $u->display_name ) . '</h1><p>' . self::e( $u->user_email ) . '</p></div><span class="st' . ( $act ? '' : ' off' ) . '">' . self::e( $act ? 'Active' : 'Suspended' ) . '</span></div>';
		echo '<div class="tiles"><div class="tile"><small>Company</small><b>' . self::e( $adm ? 'Administrator' : get_user_meta( $u->ID, 'pdqs_company', true ) ) . '</b></div><div class="tile"><small>Plan</small><b>' . self::e( $adm ? 'Administrator' : ( self::early() ? 'Early Access (all features)' : $plans[ $plan ]['name'] ) ) . '</b></div>';
		if ( ! $adm ) {
			$pct = $lim['packs'] ? min( 100, (int) round( 100 * $used / max( 1, (int) $lim['packs'] ) ) ) : 0;
			echo '<div class="tile"><small>Document packs this month</small><b>' . (int) $used . ( $lim['packs'] ? ' of ' . (int) $lim['packs'] : ' &middot; unlimited' ) . '</b>' . ( $lim['packs'] ? '<div class="bar2"><i style="width:' . (int) $pct . '%"></i></div>' : '' ) . '</div>';
		} else { echo '<div class="tile"><small>Limits</small><b>None</b></div>'; }
		echo '</div>';
		if ( $act ) { echo '<p style="margin:22px 0 0;display:flex;gap:10px;flex-wrap:wrap"><a class="btn" href="' . esc_url( self::app_url() ) . '">Open application</a><a class="btn line" href="' . esc_url( wp_make_link_relative( admin_url( 'admin.php?page=pdqap' ) ) ) . '">Auto Pack &mdash; upload a PO or items list</a></p>'; }
		else { echo '<p style="color:var(--mut)">Your account is suspended. Please contact the administrator.</p>'; }
		if ( ! $adm && self::early() ) { echo '<div class="up"><strong>Early Access &mdash; all features are free for you</strong><br><span style="color:var(--mut);font-size:14.5px">No payment while we are in early access. It runs on shared capacity, so fair use applies: up to 100 document packs a month, files up to 10 MB each, and brief slowdowns at busy times. Paid plans (Pro &#8377;' . self::e( number_format_i18n( (float) $plans['pro']['price'] ) ) . ' / month and Business) will start later, with advance notice. Your documents and company data stay yours.</span></div>'; } elseif ( ! $adm && 'business' !== $plan ) {
			$next = 'free' === $plan ? ( 'business' === $req ? 'business' : 'pro' ) : 'business'; $np = $plans[ $next ]; $link = isset( $pay[ $next ] ) ? trim( (string) $pay[ $next ] ) : '';
			echo '<div class="up"><strong>Upgrade to ' . self::e( $np['name'] ) . '</strong> &mdash; &#8377;' . self::e( number_format_i18n( (float) $np['price'] ) ) . ' / month<br><span style="color:var(--mut);font-size:14.5px">' . self::e( implode( ' · ', array_slice( $np['feat'], 1, 3 ) ) ) . '</span><p style="margin:14px 0 0">';
			if ( $link ) { echo '<a class="btn sm" target="_blank" rel="noopener" href="' . esc_url( $link ) . '">Pay &amp; upgrade</a> <span style="color:var(--mut);font-size:13.5px">After payment your plan is switched on by the administrator.</span>'; }
			else { echo '<a class="btn sm" href="mailto:' . esc_attr( get_option( 'admin_email' ) ) . '?subject=' . rawurlencode( 'Upgrade to ' . $np['name'] . ' - ' . get_user_meta( $u->ID, 'pdqs_company', true ) ) . '">Request upgrade</a> <span style="color:var(--mut);font-size:13.5px">Online payment is not switched on yet &mdash; the administrator will contact you.</span>'; }
			echo '</p></div>';
		}
		echo '<p style="margin:24px 0 0"><a class="btn line sm" href="' . esc_url( self::url( 'logout' ) . '&_wpnonce=' . wp_create_nonce( 'pdqs_logout' ) ) . '">Log out</a></p></div></div>';
	}

	/* ---------------------------------------------------------- admin page */

	public static function menu() {
		add_submenu_page( 'pipedoc-iq', 'Site & Members', 'Site & Members', 'manage_options', 'pdqsite', array( __CLASS__, 'admin_page' ) );
	}

	public static function admin_page() {
		if ( ! current_user_can( 'manage_options' ) || self::is_member() ) { return; }
		if ( isset( $_POST['pdqs_save'] ) && check_admin_referer( 'pdqs_admin' ) ) {
			$pl = array(); foreach ( array( 'free', 'pro', 'business' ) as $k ) { $pl[ $k ] = (string) max( 0, (float) ( isset( $_POST[ 'price_' . $k ] ) ? $_POST[ 'price_' . $k ] : 0 ) ); }
			update_option( 'pdqsite_plans', $pl ); update_option( 'pdqsite_root', ! empty( $_POST['root'] ) ? '1' : '0' ); update_option( 'pdqsite_early', ! empty( $_POST['early'] ) ? '1' : '0' );
			update_option( 'pdqsite_pay', array( 'pro' => esc_url_raw( isset( $_POST['pay_pro'] ) ? $_POST['pay_pro'] : '' ), 'business' => esc_url_raw( isset( $_POST['pay_business'] ) ? $_POST['pay_business'] : '' ) ) );
			if ( ! empty( $_POST['member'] ) && is_array( $_POST['member'] ) ) { foreach ( $_POST['member'] as $uid => $d ) { $uid = (int) $uid; $x = get_userdata( $uid ); if ( ! $x || ! in_array( 'pdq_member', (array) $x->roles, true ) ) { continue; } $p = sanitize_key( isset( $d['plan'] ) ? $d['plan'] : 'free' ); if ( isset( self::plans()[ $p ] ) ) { update_user_meta( $uid, 'pdqs_plan', $p ); } update_user_meta( $uid, 'pdqs_status', 'suspended' === ( isset( $d['status'] ) ? $d['status'] : '' ) ? 'suspended' : 'active' ); } }
			echo '<div class="notice notice-success"><p>Saved.</p></div>';
		}
		$pl = self::plans(); $pay = get_option( 'pdqsite_pay', array() ); $pay = is_array( $pay ) ? $pay : array(); $mem = get_users( array( 'role' => 'pdq_member', 'orderby' => 'registered', 'order' => 'DESC', 'number' => 200 ) );
		echo '<div class="wrap"><h1>PipeDoc IQ &mdash; Site &amp; Members</h1><p>Public pages: <a href="' . esc_url( self::url( 'home' ) ) . '" target="_blank">Home</a> &middot; <a href="' . esc_url( self::url( 'pricing' ) ) . '" target="_blank">Pricing</a> &middot; <a href="' . esc_url( self::url( 'login' ) ) . '" target="_blank">Login</a> &middot; <a href="' . esc_url( self::url( 'register' ) ) . '" target="_blank">Register</a> &middot; <a href="' . esc_url( self::url( 'terms' ) ) . '" target="_blank">Terms</a> &middot; <a href="' . esc_url( self::url( 'privacy' ) ) . '" target="_blank">Privacy</a></p><form method="post">'; wp_nonce_field( 'pdqs_admin' );
		echo '<table class="form-table"><tr><th>Early access (all features free)</th><td><label><input type="checkbox" name="early" value="1" ' . checked( get_option( 'pdqsite_early', '1' ), '1', false ) . '> On &mdash; every member can use every feature within fair use (100 packs a month, 10 company profiles). Untick to enforce the plan limits again.</label></td></tr><tr><th>Welcome page on the site address</th><td><label><input type="checkbox" name="root" value="1" ' . checked( get_option( 'pdqsite_root', '1' ), '1', false ) . '> Yes &mdash; visitors to the site root see the PipeDoc IQ home page</label></td></tr>';
		foreach ( $pl as $k => $p ) { echo '<tr><th>' . self::e( $p['name'] ) . ' price (INR / month)</th><td><input type="number" min="0" step="1" name="price_' . esc_attr( $k ) . '" value="' . esc_attr( $p['price'] ) . '">' . ( 'free' === $k ? '' : ' &nbsp; Payment link: <input type="url" class="regular-text" placeholder="https://rzp.io/... or any payment page" name="pay_' . esc_attr( $k ) . '" value="' . esc_attr( isset( $pay[ $k ] ) ? $pay[ $k ] : '' ) . '">' ) . '</td></tr>'; }
		echo '</table><p class="description">Payments: create a payment page / link in Razorpay, Cashfree or UPI collect and paste it above. After a customer pays, set their plan below &mdash; limits switch on immediately. Plan limits are enforced on the server (packs per month, company profiles, own templates).</p><p><button class="button button-primary" name="pdqs_save" value="1">Save</button></p>';
		echo '<h2>Registered members (' . count( $mem ) . ')</h2><table class="widefat striped"><thead><tr><th>Name</th><th>E-mail</th><th>Company</th><th>Wanted</th><th>Plan</th><th>Status</th><th>Packs this month</th><th>Workspace</th><th>Joined</th></tr></thead><tbody>';
		foreach ( $mem as $u ) {
			$id = (int) $u->ID; $p = self::plan_of( $id ); $st = get_user_meta( $id, 'pdqs_status', true );
			echo '<tr><td>' . self::e( $u->display_name ) . '</td><td>' . self::e( $u->user_email ) . '</td><td>' . self::e( get_user_meta( $id, 'pdqs_company', true ) ) . '</td><td>' . self::e( get_user_meta( $id, 'pdqs_plan_req', true ) ) . '</td><td><select name="member[' . $id . '][plan]">';
			foreach ( $pl as $k => $x ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $p, $k, false ) . '>' . self::e( $x['name'] ) . '</option>'; }
			echo '</select></td><td><select name="member[' . $id . '][status]"><option value="active"' . selected( $st, 'active', false ) . '>active</option><option value="suspended"' . selected( $st, 'suspended', false ) . '>suspended</option></select></td><td>' . self::used( $id ) . '</td><td>' . (int) get_user_meta( $id, 'pdqs_ws', true ) . ' <small>' . self::e( get_user_meta( $id, 'pdqs_prov', true ) ) . '</small></td><td>' . self::e( get_user_meta( $id, 'pdqs_joined', true ) ) . '</td></tr>';
		}
		if ( ! $mem ) { echo '<tr><td colspan="9"><em>No registrations yet.</em></td></tr>'; }
		echo '</tbody></table></form></div>';
	}
}
PDQSITE::init();

/* ===== PipeDoc IQ — NABL lab-report Excel importer (parser) ===== */
class PDQ_NABL_Parser {

	/** Read every sheet of an .xlsx into [ sheetName => [ rowNo => [ colIdx => string ] ] ] (no PhpSpreadsheet needed). */
	public static function read_xlsx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) { throw new RuntimeException( 'PHP zip extension is not enabled on this server.' ); }
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) { throw new RuntimeException( 'Cannot open the file as .xlsx (is it a real Excel file?).' ); }
		$ns_m = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$ns_r = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

		$shared = array();
		$ss = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss ) {
			$x = simplexml_load_string( $ss );
			if ( $x ) {
				$x->registerXPathNamespace( 'm', $ns_m );
				foreach ( $x->xpath( '//m:si' ) as $si ) {
					$si->registerXPathNamespace( 'm', $ns_m );
					$txt = '';
					foreach ( $si->xpath( './/m:t' ) as $t ) { $txt .= (string) $t; }
					$shared[] = $txt;
				}
			}
		}

		$wb = simplexml_load_string( (string) $zip->getFromName( 'xl/workbook.xml' ) );
		$rels = simplexml_load_string( (string) $zip->getFromName( 'xl/_rels/workbook.xml.rels' ) );
		if ( ! $wb || ! $rels ) { throw new RuntimeException( 'Workbook structure not readable.' ); }
		$targets = array();
		foreach ( $rels->Relationship as $rel ) { $targets[ (string) $rel['Id'] ] = (string) $rel['Target']; }

		$out = array();
		$wb->registerXPathNamespace( 'm', $ns_m );
		foreach ( $wb->xpath( '//m:sheets/m:sheet' ) as $sh ) {
			$name = (string) $sh['name'];
			$rid  = (string) $sh->attributes( $ns_r )['id'];
			if ( empty( $targets[ $rid ] ) ) { continue; }
			$t    = $targets[ $rid ];
			$file = ( 0 === strpos( $t, '/' ) ) ? ltrim( $t, '/' ) : 'xl/' . $t;
			$xml  = $zip->getFromName( $file );
			if ( false === $xml ) { continue; }
			$s = simplexml_load_string( $xml );
			if ( ! $s ) { continue; }
			$rows = array();
			foreach ( $s->sheetData->row as $row ) {
				$rn = (int) $row['r'];
				foreach ( $row->c as $c ) {
					$ref = (string) $c['r'];
					if ( ! preg_match( '/^([A-Z]+)/', $ref, $mm ) ) { continue; }
					$col = self::col_index( $mm[1] );
					$t   = (string) $c['t'];
					if ( 's' === $t ) { $val = isset( $shared[ (int) $c->v ] ) ? $shared[ (int) $c->v ] : ''; }
					elseif ( 'inlineStr' === $t ) { $val = ''; foreach ( $c->is->xpath( './/*[local-name()="t"]' ) as $tt ) { $val .= (string) $tt; } }
					else { $val = isset( $c->v ) ? (string) $c->v : ''; }
					$val = trim( $val );
					if ( '' !== $val ) { $rows[ $rn ][ $col ] = $val; }
				}
			}
			ksort( $rows );
			$out[ $name ] = $rows;
		}
		$zip->close();
		return $out;
	}

	private static function col_index( $letters ) {
		$n = 0;
		foreach ( str_split( $letters ) as $ch ) { $n = $n * 26 + ( ord( $ch ) - 64 ); }
		return $n;
	}

	/** '----', blank => null; numeric => float; anything else => null. */
	private static function num( $v ) {
		if ( null === $v ) { return null; }
		$v = trim( (string) $v );
		if ( '' === $v || preg_match( '/^[\-\x{2013}\x{2014}\s]+$/u', $v ) ) { return null; }
		$v = str_replace( ',', '', $v );
		return is_numeric( $v ) ? (float) $v : null;
	}

	private static function line( $cells ) { return trim( implode( ' ', $cells ) ); }

	private static function limit_result( $actual, $min, $max ) {
		if ( null === $actual || ( null === $min && null === $max ) ) { return ''; }
		if ( null !== $min && $actual < $min - 1e-9 ) { return 'FAIL'; }
		if ( null !== $max && $actual > $max + 1e-9 ) { return 'FAIL'; }
		return 'PASS';
	}

	/** Parse one worksheet (one heat number per sheet). */
	public static function parse_sheet( $name, $rows ) {
		$res = array( 'sheet' => $name, 'heat' => '', 'grade' => '', 'spec' => '', 'sample' => '', 'po' => '', 'customer' => '', 'chem' => array(), 'mech' => array(), 'charpy' => array(), 'items' => array(), 'chem_method' => '', 'mech_method' => '', 'warnings' => array(), 'errors' => array() );
		$rn = array_keys( $rows );

		// --- header block: heat, spec, sample, PO, customer
		foreach ( $rows as $r => $cells ) {
			$ln = self::line( $cells );
			if ( ! $res['heat'] && preg_match( '/Heat\s*No\.?\s*[:\-]?\s*([A-Za-z0-9][A-Za-z0-9\-\/]*)/i', $ln, $m ) && $r < 20 ) { $res['heat'] = rtrim( $m[1], '.-/' ); }
			if ( ! $res['spec'] && preg_match( '/Required values of\s+(.+?)\.?\s*$/i', $ln, $m ) ) {
				$full = $m[1];
				if ( preg_match( '/^(.*?),?\s*Grade\s*[-:]?\s*(.+)$/i', $full, $g ) ) { $res['spec'] = trim( $g[1], " ,"); $res['grade'] = trim( $g[2], " ." ); } else { $res['spec'] = $full; }
			}
			if ( ! $res['sample'] && preg_match( '/Sample taken from\s+(.+?)\.?\s*$/i', $ln, $m ) ) { $res['sample'] = trim( $m[1] ); }
			if ( ! $res['po'] && preg_match( '/P\.?\s*O\.?\s*No\.?\s*[:\-]?\s*([A-Za-z0-9\/\-]+)/i', $ln, $m ) ) { $res['po'] = $m[1]; }
			if ( ! $res['customer'] && preg_match( '/Customer\s*:\s*(.+?)\.?\s*$/i', $ln, $m ) && stripos( $ln, "Customer's Ref" ) === false ) { $res['customer'] = trim( $m[1] ); }
			if ( preg_match( '/Test Method\s*[-:]\s*(.+)$/i', $ln, $m ) ) {
				if ( preg_match( '/^(.+?)\s+for\s+Chemical.*?&\s*(.+?)\s+for\s+Mechanical/i', $m[1], $mm ) ) { $res['chem_method'] = trim( $mm[1] ); $res['mech_method'] = trim( $mm[2] ); }
			}
		}
		if ( ! $res['grade'] && preg_match( '/A-?\s?(\d{3}[A-Z]?)/i', $res['spec'], $gm ) ) { $res['grade'] = 'A' . strtoupper( $gm[1] ); }
		if ( ! $res['heat'] ) {
			$res['heat'] = rtrim( trim( $name ), '.-' );
			$res['warnings'][] = 'Heat number not found in sheet text; using sheet name "' . $name . '".';
		} elseif ( strcasecmp( rtrim( trim( $name ), '.-' ), $res['heat'] ) !== 0 ) {
			$res['warnings'][] = 'Sheet name "' . $name . '" differs from heat number "' . $res['heat'] . '" — heat number inside the report is used.';
		}

		// --- locate section anchors
		$chem_hdr = $mech_hdr = $charpy_hdr = $item_hdr = null;
		foreach ( $rows as $r => $cells ) {
			foreach ( $cells as $c => $v ) {
				if ( null === $chem_hdr && 0 === strcasecmp( $v, 'Elements' ) ) { $chem_hdr = $r; }
				if ( null === $mech_hdr && 0 === strcasecmp( $v, 'Parameters' ) ) { $mech_hdr = $r; }
				if ( null === $charpy_hdr && 0 === stripos( $v, 'CHARPY' ) ) { $charpy_hdr = $r + 1; }
				if ( null === $item_hdr && 0 === strcasecmp( preg_replace( '/\s+/', ' ', $v ), 'SL NO' ) ) { $item_hdr = $r; }
			}
		}
		if ( null === $chem_hdr && null === $mech_hdr ) {
			$res['heat'] = '';
			$res['warnings'] = array();
			$res['errors'][] = 'No CHEMICAL / MECHANICAL table found — this sheet is not a lab result sheet.';
			return $res;
		}

		// --- generic reader for Sample / Min / Max rows below a header row
		$read_block = function ( $hdr_row, $stop_re ) use ( $rows, $rn ) {
			$blk = array( 'sample' => array(), 'min' => array(), 'max' => array() );
			foreach ( $rn as $r ) {
				if ( $r <= $hdr_row ) { continue; }
				$cells = $rows[ $r ];
				$ln = self::line( $cells );
				if ( preg_match( $stop_re, $ln ) ) { break; }
				$vals = array();
				$kind = null;
				foreach ( $cells as $c => $v ) {
					if ( 0 === strcasecmp( $v, 'Sample' ) ) { $kind = 'sample'; continue; }
					if ( 0 === strcasecmp( $v, 'Min.' ) || 0 === strcasecmp( $v, 'Min' ) ) { $kind = 'min'; continue; }
					if ( 0 === strcasecmp( $v, 'Max.' ) || 0 === strcasecmp( $v, 'Max' ) ) { $kind = 'max'; continue; }
					if ( 0 === strcasecmp( $v, 'Required' ) ) { continue; }
					$vals[ $c ] = $v;
				}
				if ( $kind ) { $blk[ $kind ] = $vals; }
			}
			return $blk;
		};

		// --- CHEMICAL
		if ( null !== $chem_hdr ) {
			$cols = array();
			foreach ( $rows[ $chem_hdr ] as $c => $v ) {
				if ( preg_match( '/^([A-Za-z]{1,2})\s*%?$/', $v, $m ) && 0 !== strcasecmp( $v, 'Elements' ) ) { $cols[ $c ] = ucfirst( strtolower( $m[1] ) ); }
			}
			$b = $read_block( $chem_hdr, '/^MECHANICAL/i' );
			foreach ( $cols as $c => $el ) {
				$actual = self::num( isset( $b['sample'][ $c ] ) ? $b['sample'][ $c ] : null );
				$min = self::num( isset( $b['min'][ $c ] ) ? $b['min'][ $c ] : null );
				$max = self::num( isset( $b['max'][ $c ] ) ? $b['max'][ $c ] : null );
				if ( null === $actual && null === $min && null === $max ) { continue; } // element not reported (e.g. B  ----)
				$res['chem'][] = array( 'element' => $el, 'actual' => $actual, 'min' => $min, 'max' => $max, 'unit' => '%', 'result' => self::limit_result( $actual, $min, $max ) );
			}
			if ( ! $res['chem'] ) { $res['warnings'][] = 'Chemical table found but no values read.'; }
			elseif ( ! array_filter( array_column( $res['chem'], 'actual' ), function ( $v ) { return null !== $v; } ) ) { $res['warnings'][] = 'Chemical actual values are empty.'; }
		} else { $res['warnings'][] = 'No CHEMICAL table in this sheet.'; }

		// --- MECHANICAL
		if ( null !== $mech_hdr ) {
			$cols = array();
			foreach ( $rows[ $mech_hdr ] as $c => $v ) {
				$t = preg_replace( '/\s+/', ' ', $v );
				if ( preg_match( '/^Parameters$/i', $t ) ) { continue; }
				if ( preg_match( '/position/i', $t ) )          { $cols[ $c ] = array( 'pos', 'Position of Test Piece', '' ); }
				elseif ( preg_match( '/^Y\.?\s*S/i', $t ) )      { $cols[ $c ] = array( 'num', 'Yield Strength', 'MPa' ); }
				elseif ( preg_match( '/^(T\.?\s*S|UTS)/i', $t ) ) { $cols[ $c ] = array( 'num', 'Tensile Strength', 'MPa' ); }
				elseif ( preg_match( '/elg|elong/i', $t ) )      { $cols[ $c ] = array( 'num', 'Elongation', '%' ); }
				elseif ( preg_match( '/^R\.?\s*A/i', $t ) )      { $cols[ $c ] = array( 'num', 'Reduction of Area', '%' ); }
				elseif ( preg_match( '/hardness/i', $t ) )       { $cols[ $c ] = array( 'hard', 'Hardness', preg_match( '/\(([A-Z]{2,4})\)/', $t, $hu ) ? $hu[1] : 'HBW' ); }
			}
			$b = $read_block( $mech_hdr, '/^(The above result|CHARPY|Test Method|SL NO)/i' );
			foreach ( $cols as $c => $def ) {
				$raw = isset( $b['sample'][ $c ] ) ? $b['sample'][ $c ] : null;
				if ( 'pos' === $def[0] ) { if ( $raw ) { $res['mech_position'] = $raw; } continue; }
				$min = self::num( isset( $b['min'][ $c ] ) ? $b['min'][ $c ] : null );
				$max = self::num( isset( $b['max'][ $c ] ) ? $b['max'][ $c ] : null );
				if ( 'hard' === $def[0] ) {
					$list = array();
					foreach ( preg_split( '/[,;\/\s]+/', (string) $raw ) as $p ) { $n = self::num( $p ); if ( null !== $n ) { $list[] = $n; } }
					$actual = $list ? round( array_sum( $list ) / count( $list ), 1 ) : null;
					$row = array( 'property' => $def[1], 'actual' => $actual, 'min' => $min, 'max' => $max, 'unit' => $def[2], 'readings' => $list, 'result' => self::limit_result( $list ? max( $list ) : null, null, $max ) );
					if ( $list && null !== $min && min( $list ) < $min ) { $row['result'] = 'FAIL'; }
				} else {
					$actual = self::num( $raw );
					$row = array( 'property' => $def[1], 'actual' => $actual, 'min' => $min, 'max' => $max, 'unit' => $def[2], 'result' => self::limit_result( $actual, $min, $max ) );
				}
				if ( null === $row['actual'] && null === $min && null === $max ) { continue; }
				$res['mech'][] = $row;
			}
			if ( ! $res['mech'] ) { $res['warnings'][] = 'Mechanical table found but no values read.'; }
		} else { $res['warnings'][] = 'No MECHANICAL table in this sheet.'; }

		// --- CHARPY (optional)
		if ( null !== $charpy_hdr && isset( $rows[ $charpy_hdr ] ) ) {
			$hcols = array();
			foreach ( $rows[ $charpy_hdr ] as $c => $v ) {
				$t = preg_replace( '/\s+/', ' ', $v );
				if ( preg_match( '/Condition/i', $t ) ) { $hcols['cond'] = $c; }
				elseif ( preg_match( '/Test Temp/i', $t ) ) { $hcols['temp'] = $c; }
				elseif ( preg_match( '/Single/i', $t ) ) { $hcols['single'] = $c; }
				elseif ( preg_match( '/Avg/i', $t ) ) { $hcols['avg'] = $c; }
				elseif ( preg_match( '/Required/i', $t ) ) { $hcols['req'] = $c; }
			}
			$b = $read_block( $charpy_hdr, '/^(Test Method|SL NO)/i' );
			$s = $b['sample'];
			if ( $s && isset( $hcols['single'] ) ) {
				$list = array();
				foreach ( preg_split( '/[,;\s]+/', (string) ( isset( $s[ $hcols['single'] ] ) ? $s[ $hcols['single'] ] : '' ) ) as $p ) { $n = self::num( $p ); if ( null !== $n ) { $list[] = $n; } }
				$avg = isset( $hcols['avg'], $s[ $hcols['avg'] ] ) ? self::num( $s[ $hcols['avg'] ] ) : null;
				if ( null === $avg && $list ) { $avg = array_sum( $list ) / count( $list ); }
				$req = isset( $hcols['req'], $s[ $hcols['req'] ] ) ? self::num( $s[ $hcols['req'] ] ) : null;
				$res['charpy'] = array(
					'notch'  => isset( $s[ array_key_first( $s ) ] ) ? (string) $s[ array_key_first( $s ) ] : '',
					'temp'   => isset( $hcols['temp'], $s[ $hcols['temp'] ] ) ? $s[ $hcols['temp'] ] : '',
					'singles' => $list,
					'avg'    => null === $avg ? null : round( $avg, 1 ),
					'req'    => $req,
					'result' => self::limit_result( null === $avg ? null : round( $avg, 1 ), $req, null ),
				);
			}
		}

		// --- item table (SL NO | ITEM DESCRIPTION | PO QTY), may repeat side by side
		if ( null !== $item_hdr ) {
			$hdr = $rows[ $item_hdr ];
			$blocks = array();
			foreach ( $hdr as $c => $v ) { if ( 0 === strcasecmp( preg_replace( '/\s+/', ' ', $v ), 'SL NO' ) ) { $blocks[] = array( 'sl' => $c ); } }
			foreach ( $blocks as $i => &$bl ) {
				$next = isset( $blocks[ $i + 1 ]['sl'] ) ? $blocks[ $i + 1 ]['sl'] : 999;
				foreach ( $hdr as $c => $v ) {
					if ( $c <= $bl['sl'] || $c >= $next ) { continue; }
					if ( preg_match( '/ITEM DESC/i', $v ) ) { $bl['desc'] = $c; }
					if ( preg_match( '/PO QTY/i', $v ) )    { $bl['qty'] = $c; }
				}
			}
			unset( $bl );
			foreach ( $rn as $r ) {
				if ( $r <= $item_hdr ) { continue; }
				if ( preg_match( '/End of Report/i', self::line( $rows[ $r ] ) ) ) { break; }
				foreach ( $blocks as $bl ) {
					$sl = isset( $rows[ $r ][ $bl['sl'] ] ) ? $rows[ $r ][ $bl['sl'] ] : '';
					if ( ! ctype_digit( (string) $sl ) ) { continue; }
					$res['items'][] = array( 'sl' => $sl, 'desc' => isset( $bl['desc'], $rows[ $r ][ $bl['desc'] ] ) ? $rows[ $r ][ $bl['desc'] ] : '', 'qty' => isset( $bl['qty'], $rows[ $r ][ $bl['qty'] ] ) ? $rows[ $r ][ $bl['qty'] ] : '' );
				}
			}
		}

		foreach ( array_merge( $res['chem'], $res['mech'] ) as $x ) {
			if ( 'FAIL' === $x['result'] ) { $res['warnings'][] = ( isset( $x['element'] ) ? $x['element'] : $x['property'] ) . ' is outside the required limits.'; }
		}
		return $res;
	}

	/** Parse a whole workbook => list of heat records. */
	public static function parse_file( $path ) {
		$sheets = self::read_xlsx( $path );
		$out = array();
		foreach ( $sheets as $name => $rows ) { $out[] = self::parse_sheet( $name, $rows ); }
		return $out;
	}
}

/* ===== PipeDoc IQ — NABL Import admin page (upload → preview → confirm) ===== */
class PDQ_NABL_Admin {

	const SLUG = 'pdq-nabl-import';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'wp_ajax_pdq_nabl_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_pdq_nabl_commit', array( __CLASS__, 'ajax_commit' ) );
	}

	public static function menu() {
		add_submenu_page( 'pipedoc-iq', 'NABL Import', 'NABL Import', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/* ---------- DB helpers (go through the plugin's own PDQ_DB layer) ---------- */
	private static function rows( $entity, $args ) {
		$r = PDQ_DB::list_rows( $entity, $args );
		if ( is_wp_error( $r ) || ! is_array( $r ) ) { return array(); }
		foreach ( array( 'rows', 'items', 'data' ) as $k ) { if ( isset( $r[ $k ] ) && is_array( $r[ $k ] ) ) { return $r[ $k ]; } }
		return array_values( $r );
	}
	private static function find_heat( $heat ) {
		foreach ( self::rows( 'heat_numbers', array( 'search' => $heat, 'per_page' => 50 ) ) as $r ) {
			if ( isset( $r['heat_number'] ) && 0 === strcasecmp( trim( $r['heat_number'] ), $heat ) ) { return $r; }
		}
		return null;
	}
	private static function rid( $r ) { return is_array( $r ) && isset( $r['id'] ) ? (int) $r['id'] : 0; }

	/* ---------- AJAX: preview ---------- */
	public static function ajax_preview() {
		check_ajax_referer( 'pdq_nabl', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 ); }
		if ( empty( $_FILES['file']['tmp_name'] ) ) { wp_send_json_error( array( 'message' => 'No file received.' ) ); }
		$f = $_FILES['file'];
		if ( ! empty( $f['error'] ) ) { wp_send_json_error( array( 'message' => 'Upload failed (code ' . (int) $f['error'] . ').' ) ); }
		if ( 'xlsx' !== strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) ) ) { wp_send_json_error( array( 'message' => 'Please upload an .xlsx file (Excel 2007+).' ) ); }
		if ( $f['size'] > 15 * 1024 * 1024 ) { wp_send_json_error( array( 'message' => 'File is larger than 15 MB.' ) ); }
		try { $heats = PDQ_NABL_Parser::parse_file( $f['tmp_name'] ); }
		catch ( Exception $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ) ); }
		if ( ! $heats ) { wp_send_json_error( array( 'message' => 'The workbook has no sheets.' ) ); }
		$seen = array();
		foreach ( $heats as &$h ) {
			$h['status'] = $h['errors'] ? 'error' : ( $h['warnings'] ? 'warning' : 'ready' );
			$h['exists'] = false;
			if ( ! $h['errors'] ) {
				if ( isset( $seen[ strtolower( $h['heat'] ) ] ) ) { $h['warnings'][] = 'Duplicate heat number in this file — the later sheet will overwrite the earlier one.'; $h['status'] = 'warning'; }
				$seen[ strtolower( $h['heat'] ) ] = 1;
				$h['exists'] = (bool) self::find_heat( $h['heat'] );
			}
		}
		unset( $h );
		$token = wp_generate_password( 20, false );
		set_transient( 'pdq_nabl_' . get_current_user_id() . '_' . $token, $heats, HOUR_IN_SECONDS );
		wp_send_json_success( array( 'token' => $token, 'file' => sanitize_file_name( $f['name'] ), 'heats' => $heats ) );
	}

	/* ---------- AJAX: commit ---------- */
	public static function ajax_commit() {
		check_ajax_referer( 'pdq_nabl', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 ); }
		$token = isset( $_POST['token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_POST['token'] ) ) : '';
		$heats = get_transient( 'pdq_nabl_' . get_current_user_id() . '_' . $token );
		if ( ! $heats ) { wp_send_json_error( array( 'message' => 'Preview expired — please upload the file again.' ) ); }
		$pick = isset( $_POST['heats'] ) ? array_map( 'strval', (array) json_decode( wp_unslash( $_POST['heats'] ), true ) ) : array();
		if ( ! $pick ) { wp_send_json_error( array( 'message' => 'Select at least one heat to import.' ) ); }
		$report = array(); $tot = array( 'heats' => 0, 'chem' => 0, 'mech' => 0 );
		foreach ( $heats as $h ) {
			if ( $h['errors'] || ! in_array( $h['sheet'], $pick, true ) ) { continue; }
			$r = self::save_heat( $h );
			$report[] = $r;
			if ( empty( $r['error'] ) ) { $tot['heats']++; $tot['chem'] += $r['chem']; $tot['mech'] += $r['mech']; }
		}
		wp_send_json_success( array( 'report' => $report, 'totals' => $tot ) );
	}

	private static function save_heat( $h ) {
		$out = array( 'heat' => $h['heat'], 'chem' => 0, 'mech' => 0, 'created' => false );
		$heat = self::find_heat( $h['heat'] );
		if ( ! $heat ) {
			$new = PDQ_DB::create( 'heat_numbers', array( 'heat_number' => $h['heat'], 'material_grade' => $h['grade'], 'specification' => $h['spec'] ) );
			if ( is_wp_error( $new ) ) { $out['error'] = $new->get_error_message(); return $out; }
			$heat = $new; $out['created'] = true;
		} else {
			$upd = array();
			if ( empty( $heat['material_grade'] ) && $h['grade'] ) { $upd['material_grade'] = $h['grade']; }
			if ( empty( $heat['specification'] ) && $h['spec'] ) { $upd['specification'] = $h['spec']; }
			if ( $upd ) { PDQ_DB::update( 'heat_numbers', self::rid( $heat ), $upd ); }
		}
		$hid = self::rid( $heat );
		if ( ! $hid ) { $out['error'] = 'Could not read the heat record id.'; return $out; }

		$have = array();
		foreach ( self::rows( 'chemical_tests', array( 'filters' => array( 'heat_id' => $hid ), 'per_page' => 200 ) ) as $r ) { $have[ strtolower( $r['element'] ) ] = self::rid( $r ); }
		foreach ( $h['chem'] as $c ) {
			$d = array( 'heat_id' => $hid, 'element' => $c['element'], 'actual_value' => $c['actual'], 'min_limit' => $c['min'], 'max_limit' => $c['max'], 'unit' => '%', 'standard_ref' => $h['chem_method'], 'result' => $c['result'] );
			$k = strtolower( $c['element'] );
			$x = isset( $have[ $k ] ) ? PDQ_DB::update( 'chemical_tests', $have[ $k ], $d ) : PDQ_DB::create( 'chemical_tests', $d );
			if ( is_wp_error( $x ) ) { $out['error'] = 'Chemical ' . $c['element'] . ': ' . $x->get_error_message(); return $out; }
			$out['chem']++;
		}

		$mech = $h['mech'];
		if ( ! empty( $h['charpy'] ) && null !== $h['charpy']['avg'] ) {
			$cp = $h['charpy']; $meth = 'ASTM A370 Charpy V-notch' . ( $cp['temp'] ? ' @ ' . $cp['temp'] : '' );
			$mech[] = array( 'property' => 'Impact Energy (Avg)', 'actual' => $cp['avg'], 'min' => $cp['req'], 'max' => null, 'unit' => 'J', 'method' => $meth, 'result' => $cp['result'] );
			foreach ( $cp['singles'] as $i => $v ) { $mech[] = array( 'property' => 'Impact Energy ' . ( $i + 1 ), 'actual' => $v, 'min' => null, 'max' => null, 'unit' => 'J', 'method' => $meth, 'result' => '' ); }
		}
		$have = array();
		foreach ( self::rows( 'mechanical_tests', array( 'filters' => array( 'heat_id' => $hid ), 'per_page' => 200 ) ) as $r ) { $have[ strtolower( $r['property'] ) ] = self::rid( $r ); }
		foreach ( $mech as $m ) {
			$d = array( 'heat_id' => $hid, 'property' => $m['property'], 'actual_value' => $m['actual'], 'min_limit' => $m['min'], 'max_limit' => $m['max'], 'unit' => $m['unit'], 'test_method' => isset( $m['method'] ) ? $m['method'] : $h['mech_method'], 'result' => $m['result'] );
			$k = strtolower( $m['property'] );
			$x = isset( $have[ $k ] ) ? PDQ_DB::update( 'mechanical_tests', $have[ $k ], $d ) : PDQ_DB::create( 'mechanical_tests', $d );
			if ( is_wp_error( $x ) ) { $out['error'] = 'Mechanical ' . $m['property'] . ': ' . $x->get_error_message(); return $out; }
			$out['mech']++;
		}
		return $out;
	}

	/* ---------- page ---------- */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$cfg = wp_json_encode( array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'pdq_nabl' ), 'app' => admin_url( 'admin.php?page=pipedoc-iq&pdq_app=1' ) ) );
		echo '<style>' . self::css() . '</style><div id="pdqn" class="pdqn"></div><div id="pdqn-toasts" aria-live="polite"></div>';
		echo '<script>window.PDQN=' . $cfg . ';' . self::js() . '</script>';
	}

	private static function css() {
		return <<<'CSS'
.pdqn{--g:#18181B;--o:#EA580C;--o2:#FB923C;font-family:Inter,system-ui,sans-serif;max-width:1100px;margin:18px 20px 40px 0;color:var(--g)}
.pdqn *{box-sizing:border-box}.pdqn h1{font:700 26px Manrope,Inter,sans-serif;margin:0 0 4px}.pdqn .sub{color:#52525b;margin:0 0 18px}
.pdqn .drop{border:2px dashed #d4d4d8;border-radius:14px;background:#fff;padding:34px;text-align:center;cursor:pointer;transition:.15s}
.pdqn .drop:hover,.pdqn .drop.on{border-color:var(--o);background:#fff7ed}.pdqn .drop b{display:block;font-size:16px;margin-bottom:4px}.pdqn .drop span{color:#71717a;font-size:13px}
.pdqn .btn{background:var(--o);color:#fff;border:0;border-radius:10px;padding:10px 18px;font-weight:600;cursor:pointer;font-size:14px}.pdqn .btn:hover{background:#c2410c}.pdqn .btn[disabled]{opacity:.45;cursor:not-allowed}
.pdqn .btn.alt{background:#fff;color:var(--g);border:1px solid #d4d4d8}.pdqn .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:16px 0}
.pdqn .card{background:#fff;border:1px solid #e4e4e7;border-left:4px solid var(--o2);border-radius:12px;padding:12px 14px}.pdqn .card small{display:block;color:#71717a;text-transform:uppercase;font-size:11px;letter-spacing:.04em}.pdqn .card b{font-size:24px}
.pdqn table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e4e4e7;border-radius:12px;overflow:hidden;font-size:13px}.pdqn th{background:#f4f4f5;text-align:left;padding:9px 10px;font-size:12px;color:#52525b}.pdqn td{word-break:break-word;padding:9px 10px;border-top:1px solid #f1f1f3;vertical-align:top}
.pdqn .badge{display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600}.pdqn .b-ready{background:#dcfce7;color:#166534}.pdqn .b-warning{background:#fef3c7;color:#92400e}.pdqn .b-error{background:#fee2e2;color:#991b1b}.pdqn .b-new{background:#e0e7ff;color:#3730a3}
.pdqn .det{background:#fafafa}.pdqn .det table{font-size:12px;margin:6px 0}.pdqn .det th,.pdqn .det td{padding:5px 8px}.pdqn .fail{color:#b91c1c;font-weight:700}.pdqn .warn{color:#92400e;font-size:12px;margin:3px 0}
.pdqn .bar{display:flex;gap:10px;align-items:center;margin:14px 0;flex-wrap:wrap}.pdqn .link{background:none;border:0;color:#c2410c;cursor:pointer;padding:0;font-size:13px;text-decoration:underline}
#pdqn-toasts{position:fixed;right:20px;bottom:24px;z-index:99999;display:flex;flex-direction:column;gap:8px}.pdqt{min-width:260px;max-width:380px;background:#18181B;color:#fff;border-left:5px solid #22c55e;border-radius:10px;padding:12px 14px;box-shadow:0 8px 24px rgba(0,0,0,.25);font:14px Inter,sans-serif;animation:pdqin .2s}
.pdqt.err{border-color:#ef4444}.pdqt.wrn{border-color:#f59e0b}@keyframes pdqin{from{transform:translateY(10px);opacity:0}to{transform:none;opacity:1}}
@media(max-width:800px){.pdqn .cards{grid-template-columns:repeat(2,1fr)}}
CSS;
	}

	private static function js() {
		return <<<'JS'
(function(){
var C=window.PDQN,root=document.getElementById('pdqn'),T=document.getElementById('pdqn-toasts'),S={data:null,sel:{},busy:false};
function el(t,a,k){var e=document.createElement(t);for(var x in(a||{})){if(x==='class')e.className=a[x];else if(x==='text')e.textContent=a[x];else e.setAttribute(x,a[x]);}(k||[]).forEach(function(c){if(c!=null)e.appendChild(typeof c==='string'?document.createTextNode(c):c);});return e;}
function toast(m,kind,ms){var d=el('div',{class:'pdqt '+(kind||''),role:'status',text:m});T.appendChild(d);setTimeout(function(){d.remove();},ms||4500);}
function post(action,fd){fd.append('action',action);fd.append('nonce',C.nonce);return fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){if(!j.success)throw new Error((j.data&&j.data.message)||'Request failed');return j.data;});}
function fmt(v){return v===null||v===undefined?'—':String(v);}
function draw(){
 root.innerHTML='';
 root.appendChild(el('h1',{text:'NABL Lab Report Import'}));
 root.appendChild(el('p',{class:'sub',text:'Upload the NABL lab Excel (one sheet per heat number). Chemical, mechanical and Charpy values are read, checked against the required limits, and saved against each heat — so reports fill automatically.'}));
 var inp=el('input',{type:'file',accept:'.xlsx',style:'display:none'});
 var drop=el('div',{class:'drop',tabindex:'0',role:'button'},[el('b',{text:S.busy?'Reading file…':'Drop the NABL .xlsx here, or click to choose'}),el('span',{text:'.xlsx only · max 15 MB · each sheet = one heat number'})]);
 drop.onclick=function(){if(!S.busy)inp.click();};
 drop.onkeydown=function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();inp.click();}};
 ['dragover','dragenter'].forEach(function(n){drop.addEventListener(n,function(e){e.preventDefault();drop.classList.add('on');});});
 ['dragleave','drop'].forEach(function(n){drop.addEventListener(n,function(e){e.preventDefault();drop.classList.remove('on');});});
 drop.addEventListener('drop',function(e){if(e.dataTransfer.files[0])upload(e.dataTransfer.files[0]);});
 inp.onchange=function(){if(inp.files[0])upload(inp.files[0]);};
 root.appendChild(drop);root.appendChild(inp);
 if(S.data)preview();
}
function upload(f){
 if(!/\.xlsx$/i.test(f.name)){toast('Only .xlsx files are supported.','err');return;}
 if(f.size>15*1024*1024){toast('File is larger than 15 MB.','err');return;}
 S.busy=true;draw();var fd=new FormData();fd.append('file',f);
 post('pdq_nabl_preview',fd).then(function(d){S.busy=false;S.data=d;S.sel={};d.heats.forEach(function(h){if(h.status!=='error')S.sel[h.sheet]=true;});
  var ok=d.heats.filter(function(h){return h.status!=='error';}).length;
  draw();toast(ok+' of '+d.heats.length+' sheets read ✓');
  if(!ok)toast('No lab result sheets found in this file.','err');}).catch(function(e){S.busy=false;draw();toast(e.message,'err',7000);});
}
function count(k){return S.data.heats.filter(function(h){return h.status===k;}).length;}
function preview(){
 var d=S.data,sel=Object.keys(S.sel).filter(function(k){return S.sel[k];});
 var cards=el('div',{class:'cards'},[['Sheets in file',d.heats.length],['Ready ✓',count('ready')],['With warnings',count('warning')],['Errors / skipped',count('error')]].map(function(c){return el('div',{class:'card'},[el('small',{text:c[0]}),el('b',{text:String(c[1])})]);}));
 root.appendChild(cards);
 var bar=el('div',{class:'bar'});
 var all=el('button',{class:'btn alt',type:'button',text:'Select all valid'});all.onclick=function(){d.heats.forEach(function(h){if(h.status!=='error')S.sel[h.sheet]=true;});draw();};
 var none=el('button',{class:'btn alt',type:'button',text:'Clear'});none.onclick=function(){S.sel={};draw();};
 var go=el('button',{class:'btn',type:'button',text:'Import '+sel.length+' selected heat'+(sel.length===1?'':'s')});go.disabled=!sel.length||S.busy;go.onclick=commit;
 bar.appendChild(go);bar.appendChild(all);bar.appendChild(none);bar.appendChild(el('span',{class:'sub',style:'margin:0',text:'File: '+d.file+' · existing values for the same heat/element are updated, nothing is deleted.'}));
 root.appendChild(bar);
 var tb=el('tbody');
 d.heats.forEach(function(h){
  var cb=el('input',{type:'checkbox'});cb.checked=!!S.sel[h.sheet];cb.disabled=h.status==='error';cb.onchange=function(){S.sel[h.sheet]=cb.checked;draw();};
  var st=el('span',{class:'badge b-'+h.status,text:h.status==='ready'?'Ready ✓':h.status==='warning'?'Check':'Skipped'});
  var tr=el('tr',{},[el('td',{},[cb]),el('td',{},[el('b',{text:h.heat||h.sheet}),h.exists?el('span',{class:'badge b-new',style:'margin-left:6px',text:'update'}):(h.status!=='error'?el('span',{class:'badge b-new',style:'margin-left:6px',text:'new'}):null)]),el('td',{text:(h.spec+(h.grade?' · '+h.grade:'')).trim()||'—'}),el('td',{text:String(h.chem.length)}),el('td',{text:String(h.mech.length)}),el('td',{text:h.charpy&&h.charpy.avg!==undefined&&h.charpy.avg!==null?'✓':'—'}),el('td',{},[st])]);
  var tog=el('button',{class:'link',type:'button',text:'View'});var det=null;
  tog.onclick=function(){if(det){det.remove();det=null;tog.textContent='View';return;}det=el('tr',{class:'det'},[el('td',{colspan:'8'},[detail(h)])]);tr.after(det);tog.textContent='Hide';};
  tr.appendChild(el('td',{},[tog]));tb.appendChild(tr);
 });
 root.appendChild(el('table',{},[el('thead',{},[el('tr',{},['','Heat no.','Specification · grade','Chem','Mech','Charpy','Status',''].map(function(x){return el('th',{text:x});}))]),tb]));
}
function detail(h){
 var box=el('div');
 (h.errors||[]).forEach(function(m){box.appendChild(el('div',{class:'warn fail',text:'✗ '+m}));});
 (h.warnings||[]).forEach(function(m){box.appendChild(el('div',{class:'warn',text:'! '+m}));});
 if(h.sample)box.appendChild(el('div',{class:'warn',text:'Sample: '+h.sample+(h.po?' · PO '+h.po:'')}));
 if(h.chem.length){box.appendChild(tbl(['Element','Actual','Min','Max','Result'],h.chem.map(function(c){return [c.element,fmt(c.actual),fmt(c.min),fmt(c.max),c.result];})));}
 if(h.mech.length){box.appendChild(tbl(['Property','Actual','Min','Max','Unit','Result'],h.mech.map(function(m){return [m.property,fmt(m.actual)+(m.readings?' ('+m.readings.join(', ')+')':''),fmt(m.min),fmt(m.max),m.unit,m.result];})));}
 if(h.charpy&&h.charpy.singles){box.appendChild(tbl(['Charpy','Single values (J)','Average (J)','Required','Result'],[[h.charpy.notch+' @ '+h.charpy.temp,h.charpy.singles.join(', '),fmt(h.charpy.avg),fmt(h.charpy.req),h.charpy.result]]));}
 return box;
}
function tbl(head,rows){return el('table',{},[el('thead',{},[el('tr',{},head.map(function(x){return el('th',{text:x});}))]),el('tbody',{},rows.map(function(r){return el('tr',{},r.map(function(x){return el('td',{class:x==='FAIL'?'fail':'',text:String(x)});}));}))]);}
function commit(){
 var sel=Object.keys(S.sel).filter(function(k){return S.sel[k];});if(!sel.length){toast('Select at least one heat.','err');return;}
 S.busy=true;draw();var fd=new FormData();fd.append('token',S.data.token);fd.append('heats',JSON.stringify(sel));
 post('pdq_nabl_commit',fd).then(function(r){S.busy=false;var bad=r.report.filter(function(x){return x.error;});
  if(r.totals.heats)toast('Imported '+r.totals.heats+' heat(s): '+r.totals.chem+' chemical + '+r.totals.mech+' mechanical values ✓');
  bad.forEach(function(b){toast(b.heat+': '+b.error,'err',9000);});
  S.data=null;S.sel={};draw();
  if(r.totals.heats){var a=el('a',{href:C.app,class:'btn',style:'display:inline-block;margin-top:14px;text-decoration:none',text:'Open PipeDoc IQ and generate reports →'});root.appendChild(a);}
 }).catch(function(e){S.busy=false;draw();toast(e.message,'err',8000);});
}
draw();
})();
JS;
	}
}
PDQ_NABL_Admin::init();


/* ===== PipeDoc IQ branding: login, admin bar, dashboard ===== */
if ( ! class_exists( 'PDQ_Branding' ) ) {
class PDQ_Branding {
	public static function svg() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64"><defs><linearGradient id="pdqg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FB923C"/><stop offset="1" stop-color="#EA580C"/></linearGradient></defs><rect width="64" height="64" rx="14" fill="url(#pdqg)"/><path d="M15 47V28a9 9 0 0 1 9-9h12" fill="none" stroke="#18181B" stroke-width="9"/><rect x="8" y="43" width="14" height="7" rx="1.5" fill="#18181B"/><rect x="33" y="12" width="7" height="14" rx="1.5" fill="#18181B"/><path d="M34 30h14l6 6v16a3 3 0 0 1-3 3H34a3 3 0 0 1-3-3V33a3 3 0 0 1 3-3z" fill="#fff"/><path d="M48 30v6h6z" fill="#FDBA74"/><path d="M36 43h14M36 48h9" stroke="#EA580C" stroke-width="2.2" stroke-linecap="round"/></svg>';
	}
	public static function logo() { return 'data:image/svg+xml;base64,' . base64_encode( self::svg() ); }
	public static function mark() { return '<img src="' . self::logo() . '" alt="PipeDoc IQ" width="34" height="34">'; }

	public static function init() {
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_css' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'home' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'name' ) );
		add_filter( 'login_title', array( __CLASS__, 'title' ), 99 );
		add_filter( 'admin_title', array( __CLASS__, 'title' ), 99 );
		/* WordPress already shows the site icon (our logo) beside the site name */
		add_action( 'admin_bar_menu', array( __CLASS__, 'bar_remove' ), 999 );
		add_action( 'admin_head', array( __CLASS__, 'bar_css' ) );
		add_action( 'wp_head', array( __CLASS__, 'bar_css' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'no_welcome' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer' ), 99 );
		add_filter( 'update_footer', '__return_empty_string', 99 );
	}

	public static function home() { return home_url( '/' ); }
	public static function name() { return 'PipeDoc IQ'; }

	public static function title( $t ) {
		return preg_replace( '/\s*(\x{2014}|&#8212;|&mdash;)\s*WordPress\s*$/u', '', $t );
	}

	public static function login_css() {
		$l = self::logo();
		echo '<style id="pdq-login-brand">'
			. 'body.login{background:#F4F4F5;}'
			. '.login h1 a{background-image:url(' . $l . ')!important;background-size:contain!important;background-repeat:no-repeat!important;background-position:center!important;width:88px!important;height:88px!important;margin:0 auto 22px!important;}'
			. '.login form{border-radius:14px;border:1px solid #E4E4E7;box-shadow:0 8px 30px rgba(24,24,27,.08);}'
			. '.login #nav a,.login #backtoblog a,.login .privacy-policy-page-link a{color:#52525B;}'
			. '.login #nav a:hover,.login #backtoblog a:hover{color:#EA580C;}'
			. '.wp-core-ui .button-primary{background:#EA580C!important;border-color:#EA580C!important;box-shadow:none!important;text-shadow:none!important;}'
			. '.wp-core-ui .button-primary:hover,.wp-core-ui .button-primary:focus{background:#C2410C!important;border-color:#C2410C!important;}'
			. '.login input[type=text]:focus,.login input[type=password]:focus{border-color:#EA580C!important;box-shadow:0 0 0 1px #EA580C!important;}'
			. '</style>';
	}

	public static function bar_add( $bar ) {
		$href = is_admin() ? admin_url( 'admin.php?page=pipedoc-iq' ) : home_url( '/' );
		$bar->add_node( array(
			'id'    => 'pdq-logo',
			'title' => '<img class="pdq-bar-img" src="' . self::logo() . '" alt="PipeDoc IQ" />',
			'href'  => $href,
			'meta'  => array( 'title' => 'PipeDoc IQ' ),
		) );
	}

	public static function bar_remove( $bar ) {
		foreach ( array( 'wp-logo-external', 'about', 'contribute', 'wporg', 'documentation', 'learn', 'support-forums', 'feedback', 'wp-logo' ) as $id ) {
			$bar->remove_node( $id );
		}
	}

	public static function bar_css() {
		if ( ! is_admin_bar_showing() ) { return; }
		echo '<style id="pdq-bar-brand">' . ( is_admin() ? '#adminmenu .toplevel_page_pipedoc-iq .wp-menu-image{background:url(' . self::logo() . ') center/20px no-repeat!important}#adminmenu .toplevel_page_pipedoc-iq .wp-menu-image:before{content:none!important}' : '' )
			. '#wpadminbar #wp-admin-bar-pdq-logo>.ab-item{display:flex;align-items:center;padding:0 10px;}'
			. '#wpadminbar #wp-admin-bar-pdq-logo .pdq-bar-img{width:22px;height:22px;display:block;border-radius:5px;}'
			. '</style>';
	}

	public static function dashboard() {
		foreach ( array( 'dashboard_primary', 'dashboard_secondary', 'dashboard_site_health', 'dashboard_quick_press' ) as $w ) {
			remove_meta_box( $w, 'dashboard', 'normal' );
			remove_meta_box( $w, 'dashboard', 'side' );
		}
	}

	public static function no_welcome() {
		remove_action( 'welcome_panel', 'wp_welcome_panel' );
	}

	public static function footer() {
		return 'PipeDoc IQ &mdash; From Engineering Data to Ready-to-Print Documents';
	}
}
PDQ_Branding::init();
}
