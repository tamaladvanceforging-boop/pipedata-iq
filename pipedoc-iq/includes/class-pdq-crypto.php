<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Server-side secret encryption (libsodium preferred, OpenSSL AES-256-GCM fallback). */
class PDQ_Crypto {
	private static function key() {
		$material = ( defined( 'PDQ_ENCRYPTION_KEY' ) ? PDQ_ENCRYPTION_KEY : '' ) . wp_salt( 'secure_auth' ) . wp_salt( 'auth' );
		return hash_hkdf( 'sha256', $material, 32, 'pipedoc-iq-secrets' );
	}
	public static function encrypt( $plain ) {
		$k = self::key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$n = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $n . sodium_crypto_secretbox( $plain, $n, $k ) );
		}
		$iv = random_bytes( 12 ); $tag = '';
		$c = openssl_encrypt( $plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag );
		return 'o1:' . base64_encode( $iv . $tag . $c );
	}
	public static function decrypt( $blob ) {
		if ( ! $blob ) { return ''; }
		$k = self::key(); $kind = substr( $blob, 0, 3 ); $raw = base64_decode( substr( $blob, 3 ) );
		if ( 's1:' === $kind && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$n = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ); $r = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $n, $k );
			return false === $r ? '' : $r;
		}
		if ( 'o1:' === $kind ) {
			$r = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $r ? '' : $r;
		}
		return '';
	}
}
