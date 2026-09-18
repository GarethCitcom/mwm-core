<?php
/**
 * 301 redirects from the old site's URLs (/lesson/{id}/ and anything in the saved map).
 */

defined( 'ABSPATH' ) || exit;

class MWM_Redirects {

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect' ], 1 );
		// Unknown URLs must 404 (so the map below applies) rather than be "guessed" to a similar-looking lesson.
		add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
	}

	/**
	 * Taxonomy archives (/topic/algebra/, /level/gcse-higher/…) duplicate the real Learn Maths pages: send them there.
	 */
	private static function taxonomy_target(): string {
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return '';
		}
		switch ( $term->taxonomy ) {
			case 'mwm_level':
				return mwm_browse_url( $term->slug );
			case 'mwm_topic':
				$top    = $term->parent ? get_term( $term->parent, 'mwm_topic' ) : $term;
				$levels = array_values( array_filter( (array) get_term_meta( $top->term_id, 'levels', true ) ) );
				$level  = $levels[0] ?? 'gcse-foundation';
				$url    = mwm_browse_url( $level, $top->slug );
				return $term->parent ? add_query_arg( 'subtopic', $term->slug, $url ) : $url;
			case 'mwm_theme':
				return add_query_arg( 'theme', $term->slug, mwm_page_url( 'gaming' ) );
			case 'mwm_format':
				return $term->slug === 'short' ? mwm_page_url( 'quick-maths' ) : ( $term->slug === 'gaming' ? mwm_page_url( 'gaming' ) : mwm_page_url( 'browse' ) );
			case 'mwm_board':
				return add_query_arg( 'board', $term->slug, mwm_page_url( 'past-papers' ) );
		}
		return '';
	}

	public static function maybe_redirect(): void {
		if ( is_tax() || is_tag() || is_category() ) {
			$target = self::taxonomy_target();
			if ( $target ) {
				wp_redirect( $target, 301 );
				exit;
			}
		}
		if ( ! is_404() ) {
			return;
		}
		$path = '/' . trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' ) . '/';
		$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $home_path && str_starts_with( $path, '/' . $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) + 1 );
		}
		$target = self::resolve( $path );
		if ( $target ) {
			wp_redirect( $target, 301 );
			exit;
		}
	}

	/**
	 * Resolve an old path to a new URL, or empty string.
	 */
	public static function resolve( string $path ): string {
		$map = (array) get_option( 'mwm_redirect_map', [] );
		if ( isset( $map[ $path ] ) ) {
			$to = $map[ $path ];
			return str_starts_with( $to, 'http' ) ? $to : home_url( $to );
		}
		if ( preg_match( '~^/lesson/(\d+)/$~', $path, $m ) ) {
			$posts = get_posts( [
				'post_type'      => 'mwm_lesson',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'source_id',
				'meta_value'     => (int) $m[1],
			] );
			if ( $posts ) {
				return get_permalink( $posts[0] );
			}
		}
		if ( preg_match( '~^/\?p=(\d+)$~', $path ) ) {
			return '';
		}
		return '';
	}

	/**
	 * Build the map from imported lessons (source_url/source_id → permalink).
	 */
	public static function build_map(): array {
		$map = [];
		$posts = get_posts( [
			'post_type'      => 'mwm_lesson',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'source_id',
		] );
		$worksheets = get_posts( [
			'post_type'      => 'mwm_worksheet',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'source_url',
		] );
		foreach ( array_merge( $posts, $worksheets ) as $id ) {
			$source_id = (int) get_post_meta( $id, 'source_id', true );
			$new       = wp_make_link_relative( get_permalink( $id ) );
			if ( $source_id && get_post_type( $id ) === 'mwm_lesson' ) {
				$map[ "/lesson/{$source_id}/" ] = $new;
			}
			$source_url = (string) get_post_meta( $id, 'source_url', true );
			if ( $source_url ) {
				$old_path = '/' . trim( (string) wp_parse_url( $source_url, PHP_URL_PATH ), '/' ) . '/';
				if ( $old_path !== '//' ) {
					$map[ $old_path ] = $new;
				}
			}
		}
		// Old site sections that have no post behind them.
		$old_topics = [ 'algebra' => 'algebra', 'number' => 'number', 'percentages' => 'number', 'proportion' => 'ratio-proportion', 'geometry' => 'geometry-measures', 'probability' => 'probability', 'data' => 'statistics', 'math-in-real-life' => '' ];
		foreach ( $old_topics as $old => $new ) {
			$map[ "/topic/$old/" ] = wp_make_link_relative( $new ? mwm_browse_url( 'gcse-foundation', $new ) : mwm_browse_url( 'gcse-foundation' ) );
		}
		$map['/youtube-short-video/quick-maths/'] = wp_make_link_relative( mwm_page_url( 'quick-maths' ) );
		foreach ( [ 'roblox', 'minecraft', 'story' ] as $theme ) {
			$map[ "/youtube-short-video/$theme/" ] = wp_make_link_relative( add_query_arg( 'theme', $theme, mwm_page_url( 'gaming' ) ) );
		}
		$map['/youtube-short-video/'] = wp_make_link_relative( mwm_page_url( 'quick-maths' ) );
		$map['/quick-revision/']      = wp_make_link_relative( mwm_page_url( 'revision' ) );
		// Old /revision/{board}/ past paper pages → the new past papers page, filtered to that board.
		$pp = mwm_page_url( 'past-papers' );
		if ( $pp && get_posts( [ 'post_type' => 'mwm_past_paper', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ] ) ) {
			foreach ( array_keys( mwm_boards() ) as $slug ) {
				$map[ "/revision/$slug/" ] = wp_make_link_relative( add_query_arg( 'board', $slug, $pp ) );
			}
		}
		ksort( $map );
		return $map;
	}
}
