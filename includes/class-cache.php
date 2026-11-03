<?php
/**
 * Page caches: AccelerateWP (WP Rocket underneath), WP Rocket itself, LiteSpeed Cache, W3 Total Cache,
 * WP Super Cache and WP Fastest Cache.
 *
 * Two jobs. Keep the Studio and the REST API out of the page cache, so Kym always sees the live state.
 * And clear the public pages after any Studio change: many of those edits touch only meta, options or
 * term meta (linking a worksheet, pathway order, home page picks), which no cache plugin notices.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Cache {

	private static bool $purge_queued = false;

	public static function init(): void {
		// WP Rocket / AccelerateWP: never cache the Studio or the API. Read when WP Rocket writes its config file.
		add_filter( 'rocket_cache_reject_uri', [ __CLASS__, 'reject_uris' ] );
		// Any Studio write (POST / DELETE under /mwm/v1/studio/) clears the public pages once the request is done.
		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'after_rest' ], 10, 3 );
	}

	/**
	 * URL patterns WP Rocket must never cache (its own regex-fragment format, as in "Never cache URL(s)").
	 */
	public static function reject_uris( $uris ): array {
		$uris   = (array) $uris;
		$uris[] = '/studio(/.*)?';
		$uris[] = '/' . rest_get_url_prefix() . '(/.*)?';
		return array_values( array_unique( $uris ) );
	}

	/**
	 * Ask WP Rocket / AccelerateWP to rewrite its config so the patterns above take effect (no-op without it).
	 */
	public static function refresh_rocket_config(): void {
		if ( function_exists( 'rocket_generate_config_file' ) ) {
			rocket_generate_config_file();
		}
	}

	/**
	 * Mark the current request as not cacheable, in every dialect the common caches understand.
	 */
	public static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // WP Rocket, AccelerateWP, W3TC, WP Super Cache, Batcache, LiteSpeed
		}
		if ( ! defined( 'DONOTROCKETOPTIMIZE' ) ) {
			define( 'DONOTROCKETOPTIMIZE', true ); // No file minification or lazy-load rewriting of the Studio either.
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		do_action( 'litespeed_control_set_nocache', 'mwm studio' );
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' ); // LiteSpeed servers without the plugin
			header( 'X-Accel-Expires: 0' );                  // nginx fastcgi caches that honour it
		}
	}

	public static function after_rest( $response, $handler, $request ) {
		$route = (string) $request->get_route();
		if ( str_starts_with( $route, '/' . MWM_REST::NS . '/studio' ) && $request->get_method() !== 'GET' && ! is_wp_error( $response ) ) {
			self::queue_purge();
		}
		return $response;
	}

	/**
	 * Clear the whole page cache once this request has finished. The site is small and the cache rebuilds on
	 * the next visit (or by WP Rocket's preload), so clearing everything beats guessing which pages a change touched.
	 */
	public static function queue_purge(): void {
		if ( self::$purge_queued ) {
			return;
		}
		self::$purge_queued = true;
		add_action( 'shutdown', [ __CLASS__, 'purge_all' ], 0 );
	}

	public static function purge_all(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket and AccelerateWP
		}
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true ); // WP Fastest Cache
		}
		do_action( 'mwm_purge_page_cache' ); // Anything host-specific can hook in here.
	}
}
