<?php
/**
 * Custom post types and taxonomies.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Post_Types {

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 5 );
		add_filter( 'wp_insert_post_data', [ __CLASS__, 'auto_titles' ], 10, 2 );
		add_action( 'save_post_mwm_lesson', [ __CLASS__, 'on_save_lesson' ], 20, 2 );
		add_action( 'save_post_mwm_quiz', [ __CLASS__, 'on_save_quiz' ], 20, 2 );
		add_action( 'save_post_mwm_worksheet', [ __CLASS__, 'on_save_worksheet' ], 20, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'redirect_single_past_paper' ] );
	}

	public static function register(): void {
		/* ---- Taxonomies ---- */
		register_taxonomy( 'mwm_level', [ 'mwm_lesson', 'mwm_worksheet', 'mwm_exam_date', 'mwm_pathway' ], [
			'labels'            => self::labels( 'Level', 'Levels' ),
			'public'            => true,
			'hierarchical'      => false,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => [ 'slug' => 'level', 'with_front' => false ],
			'meta_box_cb'       => false,
		] );

		register_taxonomy( 'mwm_topic', [ 'mwm_lesson', 'mwm_worksheet' ], [
			'labels'            => self::labels( 'Topic', 'Topics' ),
			'public'            => true,
			'hierarchical'      => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => [ 'slug' => 'topic', 'with_front' => false, 'hierarchical' => true ],
			'meta_box_cb'       => false,
		] );

		register_taxonomy( 'mwm_format', [ 'mwm_lesson' ], [
			'labels'            => self::labels( 'Format', 'Formats' ),
			'public'            => false,
			'show_ui'           => true,
			'show_in_rest'      => true,
			'hierarchical'      => false,
			'show_admin_column' => true,
			'rewrite'           => false,
			'meta_box_cb'       => false,
		] );

		register_taxonomy( 'mwm_theme', [ 'mwm_lesson' ], [
			'labels'            => self::labels( 'Game theme', 'Game themes' ),
			'public'            => true,
			'hierarchical'      => false,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => [ 'slug' => 'game-theme', 'with_front' => false ],
			'meta_box_cb'       => false,
		] );

		register_taxonomy( 'mwm_board', [ 'mwm_past_paper', 'mwm_exam_date', 'mwm_pathway' ], [
			'labels'            => self::labels( 'Exam board', 'Exam boards' ),
			'public'            => false,
			'show_ui'           => true,
			'show_in_rest'      => true,
			'hierarchical'      => false,
			'show_admin_column' => true,
			'rewrite'           => false,
			'meta_box_cb'       => false,
		] );

		/* ---- Post types ---- */
		register_post_type( 'mwm_lesson', [
			'labels'       => self::labels( 'Lesson', 'Lessons' ),
			'public'       => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-video-alt3',
			'menu_position'=> 20,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ],
			'has_archive'  => false,
			'rewrite'      => [ 'slug' => 'lesson', 'with_front' => false ],
			'taxonomies'   => [ 'mwm_level', 'mwm_topic', 'mwm_format', 'mwm_theme' ],
			'template'     => [],
		] );

		register_post_type( 'mwm_worksheet', [
			'labels'       => self::labels( 'Worksheet', 'Worksheets' ),
			'public'       => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-media-text',
			'menu_position'=> 21,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'revisions' ],
			'has_archive'  => 'worksheets',
			'rewrite'      => [ 'slug' => 'worksheets', 'with_front' => false ],
			'taxonomies'   => [ 'mwm_level', 'mwm_topic' ],
		] );

		register_post_type( 'mwm_quiz', [
			'labels'       => self::labels( 'Quiz', 'Quizzes' ),
			'public'       => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-yes-alt',
			'menu_position'=> 21,
			'supports'     => [ 'title', 'custom-fields', 'revisions' ],
			'has_archive'  => false,
			'rewrite'      => [ 'slug' => 'quiz', 'with_front' => false ],
		] );

		register_post_type( 'mwm_past_paper', [
			'labels'       => self::labels( 'Past paper', 'Past papers' ),
			'public'       => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-media-document',
			'menu_position'=> 22,
			'supports'     => [ 'title', 'custom-fields' ],
			'has_archive'  => false,
			'rewrite'      => [ 'slug' => 'past-paper', 'with_front' => false ],
			'taxonomies'   => [ 'mwm_board' ],
		] );

		register_post_type( 'mwm_exam_date', [
			'labels'       => self::labels( 'Exam date', 'Exam dates' ),
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-calendar-alt',
			'menu_position'=> 23,
			'supports'     => [ 'title', 'custom-fields' ],
			'taxonomies'   => [ 'mwm_level', 'mwm_board' ],
		] );

		register_post_type( 'mwm_pathway', [
			'labels'       => self::labels( 'Pathway', 'Pathways' ),
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-networking',
			'menu_position'=> 24,
			'supports'     => [ 'title', 'custom-fields', 'revisions' ],
			'taxonomies'   => [ 'mwm_level', 'mwm_board' ],
		] );

		register_post_meta( 'mwm_lesson', 'youtube_id', [ 'type' => 'string', 'single' => true, 'show_in_rest' => true ] );
		register_post_meta( 'mwm_lesson', 'duration_seconds', [ 'type' => 'integer', 'single' => true, 'show_in_rest' => true ] );
	}

	private static function labels( string $singular, string $plural ): array {
		return [
			'name'               => $plural,
			'singular_name'      => $singular,
			'add_new'            => 'Add new',
			'add_new_item'       => "Add new $singular",
			'edit_item'          => "Edit $singular",
			'new_item'           => "New $singular",
			'view_item'          => "View $singular",
			'search_items'       => "Search $plural",
			'not_found'          => "No $plural found",
			'not_found_in_trash' => "No $plural found in the bin",
			'all_items'          => "All $plural",
			'menu_name'          => $plural,
		];
	}

	/**
	 * Past papers and exam dates get generated titles so wp-admin lists read well.
	 */
	public static function auto_titles( array $data, array $postarr ): array {
		if ( empty( $postarr['ID'] ) ) {
			return $data;
		}
		return $data;
	}

	/**
	 * Keep derived lesson meta tidy: youtube_id from URL, thumbnail fallback, quiz cache.
	 */
	public static function on_save_lesson( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$url = (string) get_post_meta( $post_id, 'youtube_url', true );
		$id  = (string) get_post_meta( $post_id, 'youtube_id', true );
		if ( $url && ! $id ) {
			$id = mwm_youtube_id( $url );
			if ( $id ) {
				update_post_meta( $post_id, 'youtube_id', $id );
			}
		}
		if ( $id && ! get_post_meta( $post_id, 'thumbnail_url', true ) ) {
			update_post_meta( $post_id, 'thumbnail_url', mwm_youtube_thumb( $id ) );
		}
		if ( ! has_term( '', 'mwm_format', $post_id ) ) {
			wp_set_object_terms( $post_id, 'lesson', 'mwm_format' );
		}
		delete_post_meta( $post_id, '_mwm_quiz_id' );
	}

	public static function on_save_quiz( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$lesson = (int) get_post_meta( $post_id, 'lesson', true );
		if ( $lesson ) {
			delete_post_meta( $lesson, '_mwm_quiz_id' );
		}
	}

	/**
	 * Keep the lesson ↔ worksheet link in sync from the worksheet side (edits in wp-admin).
	 */
	public static function on_save_worksheet( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$lesson = (int) get_post_meta( $post_id, 'lesson', true );
		// Detach from any lesson that used to point here but no longer should.
		$stale = get_posts( [ 'post_type' => 'mwm_lesson', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true, 'meta_key' => 'worksheet_post', 'meta_value' => $post_id ] );
		foreach ( $stale as $sid ) {
			if ( (int) $sid !== $lesson ) {
				delete_post_meta( (int) $sid, 'worksheet_post' );
			}
		}
		if ( $lesson && $post->post_status === 'publish' ) {
			update_post_meta( $lesson, 'worksheet_post', $post_id );
			// Inherit level and topic from the lesson when the worksheet has none.
			foreach ( [ 'mwm_level', 'mwm_topic' ] as $tax ) {
				if ( ! has_term( '', $tax, $post_id ) ) {
					$terms = wp_get_object_terms( $lesson, $tax, [ 'fields' => 'ids' ] );
					if ( $terms && ! is_wp_error( $terms ) ) {
						wp_set_object_terms( $post_id, $terms, $tax );
					}
				}
			}
		}
	}

	/**
	 * Individual past papers have no page of their own — send them to the list.
	 */
	public static function redirect_single_past_paper(): void {
		if ( is_singular( 'mwm_past_paper' ) ) {
			wp_safe_redirect( mwm_page_url( 'past-papers' ), 301 );
			exit;
		}
	}
}
