<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Admin entry + standalone "desktop-style" shell (no WP admin chrome). */
if ( ! class_exists( 'PDQ_Enhance' ) ) {
class PDQ_Enhance {
	public static function print_script() {
		echo '<script>' . self::js() . '</script>';
	}
	public static function js() {
		return <<<'JS'
(function(){
if(window.__pdqEnh)return;window.__pdqEnh=1;
var P=window.PDQ||{};
var css='#pdq-toasts{position:fixed;right:16px;bottom:16px;z-index:99999;display:flex;flex-direction:column;gap:8px;max-width:360px}.pdq-t{padding:10px 14px;border-radius:8px;color:#fff;font:13px/1.4 system-ui,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.25);cursor:pointer}.pdq-t.ok{background:#16a34a}.pdq-t.err{background:#dc2626}.pdq-t.info{background:#2563eb}.pdq-err{color:#dc2626;font:12px system-ui,sans-serif;margin-top:2px}input.pdq-bad{border-color:#dc2626!important}#pdq-nabl{position:fixed;left:12px;bottom:12px;z-index:99998;background:#ea580c;color:#fff;padding:7px 11px;border-radius:8px;font:12px system-ui,sans-serif;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.2)}.pdq-hint{margin:8px 0;padding:8px 12px;border-radius:8px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font:13px/1.4 system-ui,sans-serif}.pdq-hint a{color:#c2410c;font-weight:600}';
var st=document.createElement('style');st.textContent=css;document.head.appendChild(st);
var box=document.createElement('div');box.id='pdq-toasts';document.body.appendChild(box);
function toast(msg,type,ms){var d=document.createElement('div');d.className='pdq-t '+(type||'info');d.textContent=msg;d.onclick=function(){d.remove()};box.appendChild(d);setTimeout(function(){d.remove()},ms||(type==='err'?6000:3500));}
window.pdqToast=toast;
var quiet=/preview|search|bootstrap|retrieve|lookup|autocomplete|suggest/i;
function okMsg(m,u){if(/generate/i.test(u))return 'Document generated successfully';if(/import/i.test(u))return 'Import completed';if(m==='DELETE')return 'Deleted successfully';if(m==='POST')return 'Saved successfully';return 'Updated successfully';}
var of=window.fetch;
window.fetch=function(u,o){var url=typeof u==='string'?u:((u&&u.url)||'');var m=String((o&&o.method)||(u&&u.method)||'GET').toUpperCase();var mine=/pdq(\/|%2F)v1/.test(url);var p=of.apply(this,arguments);
if(mine&&m!=='GET'){p.then(function(r){r.clone().json().catch(function(){return null}).then(function(j){if(r.ok){if(!quiet.test(url))toast(okMsg(m,url),'ok');}else{toast((j&&j.message)||('Request failed ('+r.status+')'),'err');}});},function(){toast('Network error. Check your connection and try again.','err');});}
return p;};
var R=[{t:/gstin|gst\s*(no|number)/i,re:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/,up:1,m:'Enter a valid 15-character GSTIN (e.g. 19ABCDE1234F1Z5)'},{t:/\bpan\b/i,re:/^[A-Z]{5}\d{4}[A-Z]$/,up:1,m:'PAN must look like ABCDE1234F'},{t:/e-?mail/i,ty:'email',re:/^[^\s@]+@[^\s@]+\.[^\s@]+$/,m:'Enter a valid email address'},{t:/phone|mobile|\btel\b/i,ty:'tel',re:/^\+?[\d\s-]{7,15}$/,m:'Enter a valid phone number'},{t:/pin\s*code|postal/i,re:/^\d{6}$/,m:'PIN code must be 6 digits'}];
function lab(i){var t=(i.getAttribute('aria-label')||'')+' '+(i.placeholder||'')+' '+(i.name||'');var l=i.id&&document.querySelector('label[for="'+i.id+'"]');if(l)t+=' '+l.textContent;var p=i.parentElement;if(p&&p.querySelectorAll('input,select,textarea').length===1)t+=' '+p.textContent.slice(0,40);return t;}
function isReq(i){var p=i.parentElement;var l=p&&p.querySelector('label');return !!(l&&/\*\s*$/.test(l.textContent.trim())&&p.querySelectorAll('input,select,textarea').length===1);}
function show(i,ok,msg){var n=i.nextElementSibling;var has=n&&n.classList&&n.classList.contains('pdq-err');if(ok){i.classList.remove('pdq-bad');if(has)n.remove();}else{i.classList.add('pdq-bad');if(has){n.textContent=msg;}else{var s=document.createElement('div');s.className='pdq-err';s.textContent=msg;i.insertAdjacentElement('afterend',s);}}}
function check(i){if(i.type==='checkbox'||i.type==='radio'||i.type==='file'||i.readOnly||i.disabled)return true;var v=(i.value||'').trim();var ok=true,msg='';if(!v){if(i.required||i.getAttribute('aria-required')==='true'||isReq(i)){ok=false;msg='This field is required';}}else{var t=lab(i);for(var k=0;k<R.length;k++){var r=R[k];if(r.t.test(t)||(r.ty&&i.type===r.ty)){var x=r.up?v.toUpperCase().replace(/\s/g,''):v;if(!r.re.test(x)){ok=false;msg=r.m;}break;}}}show(i,ok,msg);return ok;}
function isF(e){return e.target&&e.target.matches&&e.target.matches('input,textarea');}
document.addEventListener('blur',function(e){if(isF(e))check(e.target);},true);
document.addEventListener('input',function(e){if(isF(e)&&e.target.classList.contains('pdq-bad'))check(e.target);},true);
function gate(c,e){var bad=null;c.querySelectorAll('input,textarea').forEach(function(i){if(!check(i)&&!bad)bad=i;});if(bad){e.preventDefault();e.stopImmediatePropagation();toast('Please fix the highlighted fields','err');bad.focus();return true;}return false;}
document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('button');if(!b)return;if(!/^(save|create|add|update|submit|register)\b/i.test((b.textContent||'').trim()))return;var c=b.closest('form,[role=dialog],[class*=modal],[class*=dialog]');if(c)gate(c,e);},true);
document.addEventListener('submit',function(e){gate(e.target,e);},true);

var STEPS=['Company','Customer / PO','Item','Size & grade','Heat & quantity','Retrieve data','Templates','Review','Stamp & signature','Generate'];
function stepOf(b){var t=(b.textContent||'').replace(/^\s*\d+\s*/,'').trim();return STEPS.indexOf(t);}
document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('button');if(!b)return;var k=stepOf(b);if(k<0)return;var pills=[].slice.call(document.querySelectorAll('button')).filter(function(x){return stepOf(x)>=0&&/rounded-full/.test(x.className);});if(pills.length<8)return;var cur=-1;pills.forEach(function(x){if(/bg-brand/.test(x.className))cur=stepOf(x);});if(cur<0||k<=cur)return;var nx=[].slice.call(document.querySelectorAll('button')).filter(function(x){return /^next\b/i.test((x.textContent||'').trim());})[0];if(nx&&nx.disabled){e.preventDefault();e.stopImmediatePropagation();toast('Complete the "'+STEPS[cur]+'" step first','err');}},true);
var base=P.adminUrl||'/wp-admin/';if(base.slice(-1)!=='/')base+='/';
var nabl=base+'admin.php?page=pdq-nabl-import';
var a=document.createElement('a');a.id='pdq-nabl';a.href=nabl;a.target='_top';a.textContent='Import NABL Excel';
function place(){
var h1=document.querySelector('h1'),t=h1?h1.textContent.trim():'';
var W='Designed & Developed by Tamal Roy Chowdhury, 2026 · All rights reserved.';
[].slice.call(document.querySelectorAll('footer,footer *,div,p,span')).filter(function(e){return e.children.length===0&&/^Designed\s*(&|and)\s*Developed by Tamal Roy Chowdhury/i.test((e.textContent||'').trim())}).forEach(function(e){if(e.textContent.trim()!==W)e.textContent=W});
var ex=document.getElementById('pdq-nabl-btn');
var want=/^Heat Number Management$/.test(t)?'heat':(/^Dashboard$/.test(t)?'dash':'');
if(ex&&!want){ex.remove();return}
if(ex||!want)return;
var bs=[].slice.call(document.querySelectorAll('button')),anchor=null,after=false;
if(want==='heat'){anchor=bs.filter(function(b){return /^CSV$/.test((b.textContent||'').trim())})[0]}
else{anchor=bs.filter(function(b){return /Add heat number/i.test((b.textContent||'').trim())})[0];after=true}
if(!anchor)return;
var n=document.createElement('a');n.id='pdq-nabl-btn';n.href=nabl;n.target='_top';n.className=anchor.className;n.style.cssText='text-decoration:none;display:inline-flex;align-items:center;gap:6px;cursor:pointer';n.title='Upload the NABL lab Excel - chemical and mechanical values are filled into heat numbers automatically';n.textContent='Import NABL Excel';
if(after)anchor.parentNode.insertBefore(n,anchor.nextSibling);else anchor.parentNode.insertBefore(n,anchor)}
function scan(){try{place()}catch(e){}
document.querySelectorAll('button').forEach(function(b){if(b.disabled&&/^next\b/i.test((b.textContent||'').trim())&&!b.title)b.title='Complete the required fields on this step to continue';});
var h=document.getElementById('pdq-heat-hint');
var sel=[].slice.call(document.querySelectorAll('select')).filter(function(x){return /heat/i.test(lab(x));})[0];
if(sel&&sel.options.length<=1){if(!h){h=document.createElement('div');h.id='pdq-heat-hint';h.className='pdq-hint';h.innerHTML='No heat numbers saved yet. <a href="'+nabl+'" target="_top">Import from the NABL Excel</a>, or add item lines by hand below. AI is not required.';sel.parentElement.insertAdjacentElement('afterend',h);}}else if(h){h.remove();}
if(!document.getElementById('pdq-co-hint')){var t=[].slice.call(document.querySelectorAll('p,div,span')).filter(function(e){return e.children.length===0&&/create a company profile first/i.test(e.textContent);})[0];var nav=[].slice.call(document.querySelectorAll('a')).filter(function(x){return /compan/i.test(x.textContent+x.getAttribute('href'));})[0];if(t&&nav){var d=document.createElement('div');d.id='pdq-co-hint';d.className='pdq-hint';d.innerHTML='<a href="'+nav.getAttribute('href')+'">Create a company profile now</a>, then come back to this step.';t.insertAdjacentElement('afterend',d);}}
var btnCss='color:#c2410c;font-weight:600;background:none;border:0;cursor:pointer;text-decoration:underline;padding:0';
function hint(id,anchor,html){var h=document.getElementById(id);if(h)return h;h=document.createElement('div');h.id=id;h.className='pdq-hint';h.innerHTML=html;anchor.insertAdjacentElement('afterend',h);[].slice.call(h.querySelectorAll('[data-go]')).forEach(function(b){b.onclick=function(){location.hash=b.getAttribute('data-go');};});return h;}
function leaf(re){return [].slice.call(document.querySelectorAll('p,div,span')).filter(function(e){return e.children.length===0&&re.test(e.textContent);})[0];}
var e1=leaf(/create a company profile first/i),c1=document.getElementById('pdq-co2');
if(e1){if(!c1){hint('pdq-co2',e1,'<button data-go="#/company_profiles" style="'+btnCss+'">Create a company profile now</button>. If you already did, the list may still be loading: wait a second.');}}else if(c1){c1.remove();}
var e2=leaf(/no matching engineering record/i),c2=document.getElementById('pdq-item-hint');
if(e2){if(!c2){hint('pdq-item-hint',e2,'No record found. <button data-go="#/engineering_dimensions" style="'+btnCss+'">Add it in Engineering Database</button>, or press Next and type the item lines by hand in step 5.');}}else if(c2){c2.remove();}
var al=[].slice.call(document.querySelectorAll('button')).filter(function(b){return /^\+?\s*add line$/i.test((b.textContent||'').trim())&&b.disabled;})[0],c3=document.getElementById('pdq-add-hint');
if(al){al.title='Choose an item in step 3 first, or use the manual row below.';if(sel&&sel.value&&!c3&&al.parentElement){hint('pdq-add-hint',al.parentElement,'This Add line button needs an item chosen in step 3 (Engineering Database). Use the manual row below to add the line by hand.');}}else if(c3){c3.remove();}

}
var q=0;new MutationObserver(function(){if(q)return;q=requestAnimationFrame(function(){q=0;scan();});}).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled']});document.addEventListener('change',function(){setTimeout(scan,60);},true);
scan();
})();
JS;
	}
}
}
class PDQ_App {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_fullscreen' ) );
	}
	public static function menu() {
		add_menu_page( 'PipeDoc IQ', 'PipeDoc IQ', 'manage_options', 'pipedoc-iq', array( __CLASS__, 'launcher' ), 'dashicons-media-document', 3 );
	}
	public static function url() { return add_query_arg( array( 'page' => 'pipedoc-iq', 'pdq_app' => 1 ), admin_url( 'admin.php' ) ); }
	public static function launcher() {
		echo '<div class="wrap"><h1>PipeDoc IQ</h1><p>From Engineering Data to Ready-to-Print Documents.</p><p><a class="button button-primary button-hero" href="' . esc_url( self::url() ) . '">Open PipeDoc IQ</a></p><p class="description">Phase 1 runs in a trusted local/private environment and relies on WordPress administrator login. Do not expose it to the public internet.</p></div>';
	}
	public static function maybe_fullscreen() {
		if ( ! isset( $_GET['page'], $_GET['pdq_app'] ) || 'pipedoc-iq' !== $_GET['page'] ) { return; }
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to access PipeDoc IQ.', 'pipedoc-iq' ), 403 ); }
		$js = PDQ_DIR . 'assets/app/app.js'; $css = PDQ_DIR . 'assets/app/app.css';
		$cfg = array( 'root' => esc_url_raw( rest_url( PDQ_REST::NS . '/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'adminUrl' => admin_url(), 'version' => PDQ_VERSION );
		nocache_headers(); header( 'Content-Type: text/html; charset=utf-8' ); header( 'X-Frame-Options: SAMEORIGIN' ); header( 'Referrer-Policy: same-origin' );
		?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PipeDoc IQ</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Inter:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
<?php if ( file_exists( $css ) ) { echo '<link rel="stylesheet" href="' . esc_url( PDQ_URL . 'assets/app/app.css?v=' . filemtime( $css ) ) . '">'; } ?>
</head><body><div id="root"></div>
<script>window.PDQ = <?php echo wp_json_encode( $cfg ); ?>;</script>
<?php if ( file_exists( $js ) ) { echo '<script src="' . esc_url( PDQ_URL . 'assets/app/app.js?v=' . filemtime( $js ) ) . '"></script>'; } else { echo '<p style="font:16px sans-serif;padding:2rem">Frontend bundle missing. Run <code>npm install &amp;&amp; npm run build</code> in the frontend folder.</p>'; } ?>
<?php PDQ_Enhance::print_script(); ?>
</body></html><?php
		exit;
	}
}
