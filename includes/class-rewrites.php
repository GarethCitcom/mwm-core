<?php
/**
 * Pretty URLs: /learn-maths/{level}/{topic}/, /revision/{level}/{board}/, /studio/{view}/.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Rewrites {

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'add_rules' ], 20 );
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_filter( 'redirect_canonical', [ __CLASS__, 'no_canonical_on_custom' ], 10, 2 );
	}

	public static function query_vars( array $vars ): array {
		return array_merge( $vars, [ 'mwm_level', 'mwm_topic', 'mwm_board', 'mwm_studio', 'mwm_studio_view', 'subtopic', 'type', 'worksheet', 'quiz', 'theme', 'level', 'board' ] );
	}

	public static function add_rules(): void {
		$levels = implode( '|', array_keys( mwm_levels() ) );
		$boards = implode( '|', array_keys( mwm_boards() ) );
		$pages  = (array) get_option( 'mwm_pages', [] );
		$browse = ! empty( $pages['browse'] ) ? get_post_field( 'post_name', $pages['browse'] ) : 'learn-maths';
		$rev    = ! empty( $pages['revision'] ) ? get_post_field( 'post_name', $pages['revision'] ) : 'revision';

		add_rewrite_rule( "^{$browse}/({$levels})/?$", "index.php?pagename={$browse}&mwm_level=\$matches[1]", 'top' );
		add_rewrite_rule( "^{$browse}/({$levels})/([^/]+)/?$", "index.php?pagename={$browse}&mwm_level=\$matches[1]&mwm_topic=\$matches[2]", 'top' );
		add_rewrite_rule( "^{$rev}/({$levels})/({$boards})/?$", "index.php?pagename={$rev}&mwm_level=\$matches[1]&mwm_board=\$matches[2]", 'top' );
		add_rewrite_rule( "^{$rev}/({$levels})/?$", "index.php?pagename={$rev}&mwm_level=\$matches[1]", 'top' );
		add_rewrite_rule( '^studio/?$', 'index.php?mwm_studio=1', 'top' );
		add_rewrite_rule( '^studio/([a-z-]+)/?$', 'index.php?mwm_studio=1&mwm_studio_view=$matches[1]', 'top' );
	}

	public static function no_canonical_on_custom( $redirect, $requested ) {
		if ( get_query_var( 'mwm_studio' ) || get_query_var( 'mwm_level' ) ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Current browse selection from the URL (pretty or query-string), with defaults.
	 */
	public static function browse_state(): array {
		$levels = mwm_levels();
		$level  = sanitize_key( (string) ( get_query_var( 'mwm_level' ) ?: ( $_GET['level'] ?? '' ) ) );
		if ( ! isset( $levels[ $level ] ) ) {
			$level = mwm_user_prefs()['level'];
		}
		$topic    = sanitize_title( (string) ( get_query_var( 'mwm_topic' ) ?: ( $_GET['topic'] ?? '' ) ) );
		$subtopic = sanitize_title( (string) ( $_GET['subtopic'] ?? '' ) );
		$type     = sanitize_key( (string) ( $_GET['type'] ?? 'all' ) );
		if ( ! in_array( $type, [ 'all', 'lesson', 'short', 'gaming' ], true ) ) {
			$type = 'all';
		}
		return [
			'level'     => $level,
			'topic'     => $topic,
			'subtopic'  => $subtopic,
			'type'      => $type,
			'worksheet' => ! empty( $_GET['worksheet'] ),
			'quiz'      => ! empty( $_GET['quiz'] ),
			'search'    => sanitize_text_field( (string) ( $_GET['q'] ?? '' ) ),
		];
	}

	public static function pathway_state(): array {
		$prefs  = mwm_user_prefs();
		$level  = sanitize_key( (string) ( get_query_var( 'mwm_level' ) ?: ( $_GET['level'] ?? '' ) ) );
		$board  = sanitize_key( (string) ( get_query_var( 'mwm_board' ) ?: ( $_GET['board'] ?? '' ) ) );
		if ( ! isset( mwm_levels()[ $level ] ) ) {
			$level = $prefs['level'];
		}
		if ( ! isset( mwm_boards()[ $board ] ) ) {
			$board = $prefs['board'];
		}
		return [ 'level' => $level, 'board' => $board ];
	}
}
