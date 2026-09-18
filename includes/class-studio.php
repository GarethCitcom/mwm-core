<?php
/**
 * /studio/ — Kym's front-end publishing area. Capability-gated, rendered outside the block theme templates.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Studio {

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ], 0 );
		add_filter( 'show_admin_bar', [ __CLASS__, 'hide_admin_bar' ] );
	}

	public static function hide_admin_bar( $show ) {
		return get_query_var( 'mwm_studio' ) ? false : $show;
	}

	public static function url( string $view = '' ): string {
		return home_url( '/studio/' . ( $view ? $view . '/' : '' ) );
	}

	public static function maybe_render(): void {
		if ( ! get_query_var( 'mwm_studio' ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		if ( ! current_user_can( MWM_Activator::CAP ) ) {
			status_header( 403 );
			nocache_headers();
			wp_die( '<p>This area is for the site’s teacher account. If that’s you, sign in with your usual login.</p>', 'Studio', [ 'response' => 403 ] );
		}
		nocache_headers();
		status_header( 200 );
		global $wp_query;
		$wp_query->is_404 = false;
		self::enqueue();
		include MWM_CORE_DIR . 'studio/template.php';
		exit;
	}

	/**
	 * Data the Studio app boots with.
	 */
	public static function boot_data(): array {
		$user   = wp_get_current_user();
		$topics = [];
		foreach ( mwm_topics() as $t ) {
			$topics[] = [ 'slug' => $t['slug'], 'name' => $t['name'], 'subtopics' => mwm_subtopics( $t['id'] ) ];
		}
		$levels = [];
		foreach ( mwm_levels() as $slug => $l ) {
			$levels[] = [ 'slug' => $slug, 'name' => $l['name'] ];
		}
		$year   = (int) wp_date( 'Y' );
		$series = [ "June $year", 'November ' . ( $year - 1 ), 'June ' . ( $year - 1 ) ];
		return [
			'user'      => [ 'name' => $user->display_name ?: $user->user_login, 'initial' => strtoupper( mb_substr( $user->display_name ?: $user->user_login, 0, 1 ) ) ],
			'rest'      => esc_url_raw( rest_url( MWM_REST::NS . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'site'      => home_url( '/' ),
			'view'      => sanitize_key( (string) get_query_var( 'mwm_studio_view' ) ) ?: 'dash',
			'editId'    => (int) ( $_GET['edit'] ?? 0 ),
			'levels'    => $levels,
			'topics'    => $topics,
			'boards'    => mwm_boards(),
			'series'    => $series,
			'prompt'    => MWM_Quiz::prompt(),
			'playlists' => MWM_YouTube::playlist_counts(),
			'sync'      => MWM_YouTube::sync_state(),
			'recent'    => array_slice( MWM_REST::content_rows( 'all', 50 ), 0, 3 ),
			'content'   => MWM_REST::content_rows( 'all', -1 ),
			'dates'     => array_values( array_filter( MWM_REST::content_rows( 'exam-dates' ) ) ),
			'urls'      => [
				'home'       => home_url( '/' ),
				'pastPapers' => mwm_page_url( 'past-papers' ),
				'worksheets' => mwm_page_url( 'worksheets' ),
				'calendar'   => mwm_page_url( 'calendar' ),
				'logout'     => wp_logout_url( home_url( '/' ) ),
			],
		];
	}

	private static function enqueue(): void {
		$theme_css = get_theme_file_uri( 'assets/css/mwm.css' );
		$theme_ver = file_exists( get_theme_file_path( 'assets/css/mwm.css' ) ) ? (string) filemtime( get_theme_file_path( 'assets/css/mwm.css' ) ) : MWM_CORE_VERSION;
		wp_enqueue_style( 'mwm-theme', $theme_css, [], $theme_ver );
		wp_enqueue_style( 'mwm-studio', MWM_CORE_URL . 'studio/studio.css', [ 'mwm-theme' ], (string) filemtime( MWM_CORE_DIR . 'studio/studio.css' ) );
		if ( file_exists( get_theme_file_path( 'assets/js/mwm.js' ) ) ) {
			wp_enqueue_script( 'mwm-theme', get_theme_file_uri( 'assets/js/mwm.js' ), [], (string) filemtime( get_theme_file_path( 'assets/js/mwm.js' ) ), true );
		}
		wp_enqueue_script( 'mwm-studio', MWM_CORE_URL . 'studio/studio.js', [], (string) filemtime( MWM_CORE_DIR . 'studio/studio.js' ), true );
		wp_add_inline_script( 'mwm-studio', 'window.MWM_STUDIO = ' . wp_json_encode( self::boot_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ';', 'before' );
	}
}
