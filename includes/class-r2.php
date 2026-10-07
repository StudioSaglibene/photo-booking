<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal S3-compatible client for Cloudflare R2 (AWS Signature V4), no SDK required.
 */
class R2 {

	private $access_key;
	private $secret_key;
	private $bucket;
	private $endpoint; // https://<account>.r2.cloudflarestorage.com
	private $region = 'auto';

	public function __construct( $access_key, $secret_key, $bucket, $endpoint, $region = 'auto' ) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->bucket     = $bucket;
		$this->endpoint   = rtrim( $endpoint, '/' );
		$this->region     = $region;
	}

	/** @return R2|\WP_Error */
	public static function from_settings() {
		$s        = Settings::all();
		$endpoint = $s['r2_endpoint'] ? $s['r2_endpoint'] : ( $s['r2_account_id'] ? 'https://' . $s['r2_account_id'] . '.r2.cloudflarestorage.com' : '' );
		if ( ! $endpoint || ! $s['r2_access_key'] || ! $s['r2_secret_key'] || ! $s['r2_bucket'] ) {
			return new \WP_Error( 'pb_r2_config', __( 'Cloudflare R2 non è configurato (Prenotazioni → Impostazioni → Consegne).', 'photo-booking' ) );
		}
		return new self( $s['r2_access_key'], $s['r2_secret_key'], $s['r2_bucket'], $endpoint );
	}

	public static function is_configured() {
		return ! is_wp_error( self::from_settings() );
	}

	/* ------------------------------------------------------------------ */
	/* Signing                                                            */
	/* ------------------------------------------------------------------ */

	public static function uri_encode( $str, $keep_slash = false ) {
		$enc = rawurlencode( (string) $str );
		$enc = str_replace( '%7E', '~', $enc );
		return $keep_slash ? str_replace( '%2F', '/', $enc ) : $enc;
	}

	public static function canonical_query( array $query ) {
		$pairs = array();
		foreach ( $query as $k => $v ) {
			$pairs[ self::uri_encode( $k ) ] = self::uri_encode( null === $v ? '' : $v );
		}
		ksort( $pairs, SORT_STRING );
		$out = array();
		foreach ( $pairs as $k => $v ) {
			$out[] = $k . '=' . $v;
		}
		return implode( '&', $out );
	}

	private function signing_key( $date ) {
		$k = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
		$k = hash_hmac( 'sha256', $this->region, $k, true );
		$k = hash_hmac( 'sha256', 's3', $k, true );
		return hash_hmac( 'sha256', 'aws4_request', $k, true );
	}

	/**
	 * Generic SigV4 signer (exposed for tests with AWS reference vectors).
	 *
	 * @param string $method
	 * @param string $host
	 * @param string $uri     Canonical (encoded) path.
	 * @param array  $query
	 * @param array  $headers lowercase name => value (must include host).
	 * @param string $payload_hash
	 * @param int    $time
	 * @return array signature, signed_headers, scope, amz_date
	 */
	public function sign( $method, $uri, array $query, array $headers, $payload_hash, $time ) {
		$amz_date = gmdate( 'Ymd\THis\Z', $time );
		$date     = gmdate( 'Ymd', $time );
		$scope    = "{$date}/{$this->region}/s3/aws4_request";
		ksort( $headers, SORT_STRING );
		$canon_headers = '';
		foreach ( $headers as $k => $v ) {
			$canon_headers .= strtolower( $k ) . ':' . trim( preg_replace( '/\s+/', ' ', (string) $v ) ) . "\n";
		}
		$signed_headers = implode( ';', array_map( 'strtolower', array_keys( $headers ) ) );
		$canonical      = implode( "\n", array( $method, $uri, self::canonical_query( $query ), $canon_headers, $signed_headers, $payload_hash ) );
		$to_sign        = implode( "\n", array( 'AWS4-HMAC-SHA256', $amz_date, $scope, hash( 'sha256', $canonical ) ) );
		return array(
			'signature'      => hash_hmac( 'sha256', $to_sign, $this->signing_key( $date ) ),
			'signed_headers' => $signed_headers,
			'scope'          => $scope,
			'amz_date'       => $amz_date,
		);
	}

	private function host() {
		return (string) wp_parse_url( $this->endpoint, PHP_URL_HOST ) . ( wp_parse_url( $this->endpoint, PHP_URL_PORT ) ? ':' . wp_parse_url( $this->endpoint, PHP_URL_PORT ) : '' );
	}

	private function path( $key = '' ) {
		$base = (string) wp_parse_url( $this->endpoint, PHP_URL_PATH );
		return rtrim( $base, '/' ) . '/' . self::uri_encode( $this->bucket ) . ( '' !== $key ? '/' . self::uri_encode( ltrim( $key, '/' ), true ) : '' );
	}

	/**
	 * Presigned URL (query-string auth).
	 */
	public function presign( $method, $key, $expires = 600, array $query = array(), $time = null ) {
		$time                         = $time ? $time : time();
		$query['X-Amz-Algorithm']     = 'AWS4-HMAC-SHA256';
		$query['X-Amz-Credential']    = $this->access_key . '/' . gmdate( 'Ymd', $time ) . '/' . $this->region . '/s3/aws4_request';
		$query['X-Amz-Date']          = gmdate( 'Ymd\THis\Z', $time );
		$query['X-Amz-Expires']       = (string) (int) $expires;
		$query['X-Amz-SignedHeaders'] = 'host';
		$sig                          = $this->sign( $method, $this->path( $key ), $query, array( 'host' => $this->host() ), 'UNSIGNED-PAYLOAD', $time );
		$query['X-Amz-Signature']     = $sig['signature'];
		$scheme                       = (string) wp_parse_url( $this->endpoint, PHP_URL_SCHEME );
		return $scheme . '://' . $this->host() . $this->path( $key ) . '?' . self::canonical_query( $query );
	}

	/**
	 * Signed request (header auth).
	 *
	 * @return array|\WP_Error code, body, headers
	 */
	public function request( $method, $key = '', array $query = array(), $body = '', array $extra_headers = array() ) {
		$time         = time();
		$payload_hash = hash( 'sha256', (string) $body );
		$headers      = array_change_key_case( $extra_headers, CASE_LOWER );
		$headers['host']                 = $this->host();
		$headers['x-amz-content-sha256'] = $payload_hash;
		$headers['x-amz-date']           = gmdate( 'Ymd\THis\Z', $time );
		$sig                             = $this->sign( $method, $this->path( $key ), $query, $headers, $payload_hash, $time );
		$headers['authorization']        = 'AWS4-HMAC-SHA256 Credential=' . $this->access_key . '/' . $sig['scope'] . ', SignedHeaders=' . $sig['signed_headers'] . ', Signature=' . $sig['signature'];
		unset( $headers['host'] );

		$qs  = $query ? '?' . self::canonical_query( $query ) : '';
		$url = (string) wp_parse_url( $this->endpoint, PHP_URL_SCHEME ) . '://' . $this->host() . $this->path( $key ) . $qs;
		$res = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 60,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$out  = array(
			'code'    => $code,
			'body'    => (string) wp_remote_retrieve_body( $res ),
			'headers' => wp_remote_retrieve_headers( $res ),
		);
		if ( $code >= 300 || false !== strpos( $out['body'], '<Error>' ) ) {
			$msg = $out['body'] && preg_match( '#<Message>(.*?)</Message>#s', $out['body'], $m ) ? $m[1] : 'HTTP ' . $code;
			return new \WP_Error( 'pb_r2', 'R2: ' . $msg, $out );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Operations                                                         */
	/* ------------------------------------------------------------------ */

	/** @return string|\WP_Error UploadId */
	public function create_multipart( $key, $content_type = 'application/zip' ) {
		$res = $this->request( 'POST', $key, array( 'uploads' => '' ), '', array( 'content-type' => $content_type ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! preg_match( '#<UploadId>(.*?)</UploadId>#s', $res['body'], $m ) ) {
			return new \WP_Error( 'pb_r2', 'R2: UploadId mancante' );
		}
		return html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1 );
	}

	public function presign_part( $key, $upload_id, $part, $expires = 3600 ) {
		return $this->presign(
			'PUT',
			$key,
			$expires,
			array(
				'partNumber' => (string) (int) $part,
				'uploadId'   => $upload_id,
			)
		);
	}

	/**
	 * @param array $parts [ [ 'PartNumber' => 1, 'ETag' => '"abc"' ], ... ]
	 */
	public function complete_multipart( $key, $upload_id, array $parts ) {
		usort(
			$parts,
			static function ( $a, $b ) {
				return (int) $a['PartNumber'] - (int) $b['PartNumber'];
			}
		);
		$xml = '<CompleteMultipartUpload>';
		foreach ( $parts as $p ) {
			$etag = '"' . trim( (string) $p['ETag'], '"' ) . '"';
			$xml .= '<Part><PartNumber>' . (int) $p['PartNumber'] . '</PartNumber><ETag>' . esc_html( $etag ) . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';
		return $this->request( 'POST', $key, array( 'uploadId' => $upload_id ), $xml, array( 'content-type' => 'application/xml' ) );
	}

	public function abort_multipart( $key, $upload_id ) {
		return $this->request( 'DELETE', $key, array( 'uploadId' => $upload_id ) );
	}

	/** @return int|\WP_Error size in bytes */
	public function head( $key ) {
		$res = $this->request( 'HEAD', $key );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return (int) ( $res['headers']['content-length'] ?? 0 );
	}

	public function delete( $key ) {
		return $this->request( 'DELETE', $key );
	}

	public function download_url( $key, $filename, $expires = 600 ) {
		$ascii = preg_replace( '/[^A-Za-z0-9._-]/', '_', $filename );
		return $this->presign(
			'GET',
			$key,
			$expires,
			array(
				'response-content-disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ),
			)
		);
	}

	/** Connection test: list 1 object. */
	public function test() {
		return $this->request(
			'GET',
			'',
			array(
				'list-type' => '2',
				'max-keys'  => '1',
			)
		);
	}
}
