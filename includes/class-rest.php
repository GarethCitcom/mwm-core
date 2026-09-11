<?php
/**
 * REST API: mwm/v1
 *
 * Public:  GET lessons, topics, pathway, exam-dates, quiz/{id}
 * Signed-in: GET|POST me/progress
 * Studio (capability mwm_manage_studio): video lookup, lessons, uploads, quiz validation, past papers, exam dates, content list, trash/restore, sync
 */

defined( 'ABSPATH' ) || exit;

class MWM_REST {

	public const NS = 'mwm/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function can_studio(): bool {
		return current_user_can( MWM_Activator::CAP );
	}

	public static function is_logged_in(): bool {
		return is_user_logged_in();
	}

	public static function routes(): void {
		$ns = self::NS;

		register_rest_route( $ns, '/lessons', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_lessons' ], 'permission_callback' => '__return_true' ] );
		register_rest_route( $ns, '/topics', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_topics' ], 'permission_callback' => '__return_true' ] );
		register_rest_route( $ns, '/pathway', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_pathway' ], 'permission_callback' => '__return_true' ] );
		register_rest_route( $ns, '/exam-dates', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_exam_dates' ], 'permission_callback' => '__return_true' ] );
		register_rest_route( $ns, '/quiz/(?P<id>\d+)', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_quiz' ], 'permission_callback' => '__return_true' ] );

		register_rest_route( $ns, '/me/progress', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_progress' ], 'permission_callback' => [ __CLASS__, 'is_logged_in' ] ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'post_progress' ], 'permission_callback' => [ __CLASS__, 'is_logged_in' ] ],
		] );

		$studio = [ 'permission_callback' => [ __CLASS__, 'can_studio' ] ];
		register_rest_route( $ns, '/studio/video', array_merge( $studio, [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'studio_video' ] ] ) );
		register_rest_route( $ns, '/studio/lessons', array_merge( $studio, [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'studio_save_lesson' ] ] ) );
		register_rest_route( $ns, '/studio/lessons/(?P<id>\d+)', array_merge( $studio, [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'studio_get_lesson' ] ] ) );
		register_rest_route( $ns, '/studio/upload', array_merge( $studio, [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'studio_upload' ] ] ) );
		register_rest_route( $ns, '/studio/quiz/validate', array_merge( $studio, [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'studio_validate_quiz' ] ] ) );
		register_rest_route( $ns, '/studio/past-papers', array_merge( $studio, [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'studio_save_past_paper' ] ] ) );
		register_rest_route( $ns, '/studio/exam-dates', array_merge( $studio, [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'studio_save_exam_date' ] ] ) );
		register_rest_route( $ns, '/studio/content', array_merge( $studio, [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'studio_content' ] ] ) );
		register_rest_route( $ns, '/studio/content/(?P<id>\d+)', array_merge( $studio, [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'studio_trash' ] ] ) );
		register_rest_route( $ns, '/studio/content/(?P<id>\d+)/restore', array_merge( $studio, [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'studio_restore' ] ] ) );
		register_rest_route( $ns, '/studio/sync', array_merge( $studio, [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'studio_sync' ] ] ) );
		register_rest_route( $ns, '/studio/dashboard', array_merge( $studio, [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'studio_dashboard' ] ] ) );
	}

	/* ---------------------------------------------------------------
	 * Public
	 * ------------------------------------------------------------ */

	public static function get_lessons( WP_REST_Request $r ): WP_REST_Response {
		$format = sanitize_key( (string) $r->get_param( 'format' ) );
		$cards  = mwm_query_lessons( [
			'level'     => sanitize_key( (string) $r->get_param( 'level' ) ),
			'topic'     => sanitize_title( (string) $r->get_param( 'topic' ) ),
			'subtopic'  => sanitize_title( (string) $r->get_param( 'subtopic' ) ),
			'format'    => $format && $format !== 'all' ? $format : '',
			'theme'     => sanitize_key( (string) $r->get_param( 'theme' ) ),
			'worksheet' => (bool) $r->get_param( 'worksheet' ),
			'quiz'      => (bool) $r->get_param( 'quiz' ),
			'search'    => sanitize_text_field( (string) $r->get_param( 'search' ) ),
			'per_page'  => (int) ( $r->get_param( 'per_page' ) ?: -1 ),
		] );
		return rest_ensure_response( array_map( [ __CLASS__, 'public_card' ], $cards ) );
	}

	public static function public_card( array $c ): array {
		unset( $c['search'] );
		if ( $c['worksheet'] ) {
			$c['worksheet'] = [ 'url' => $c['worksheet']['url'], 'label' => $c['worksheet']['label'] ];
		}
		if ( $c['answers'] ) {
			$c['answers'] = [ 'url' => $c['answers']['url'], 'label' => $c['answers']['label'] ];
		}
		return $c;
	}

	public static function get_topics( WP_REST_Request $r ): WP_REST_Response {
		$level  = sanitize_key( (string) $r->get_param( 'level' ) );
		$topics = mwm_topics( $level ?: null );
		foreach ( $topics as &$t ) {
			$lessons = mwm_query_lessons( [ 'level' => $level, 'topic' => $t['slug'], 'format' => 'lesson' ] );
			$t['count']     = count( $lessons );
			$t['preview']   = array_map( [ __CLASS__, 'public_card' ], array_slice( $lessons, 0, 3 ) );
			$t['url']       = mwm_browse_url( $level, $t['slug'] );
			$t['subtopics'] = mwm_subtopics( $t['id'] );
		}
		return rest_ensure_response( $topics );
	}

	public static function get_pathway( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$level = sanitize_key( (string) $r->get_param( 'level' ) ) ?: 'gcse-higher';
		$board = sanitize_key( (string) $r->get_param( 'board' ) ) ?: 'edexcel';
		$p     = mwm_find_pathway( $level, $board );
		return rest_ensure_response( [
			'pathway'   => $p ? mwm_pathway_data( $p ) : null,
			'next_exam' => mwm_next_exam( $level, $board ),
			'level'     => $level,
			'board'     => $board,
		] );
	}

	public static function get_exam_dates( WP_REST_Request $r ): WP_REST_Response {
		return rest_ensure_response( mwm_exam_dates( sanitize_key( (string) $r->get_param( 'level' ) ), sanitize_key( (string) $r->get_param( 'board' ) ) ) );
	}

	public static function get_quiz( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$id = (int) $r['id'];
		if ( get_post_type( $id ) !== 'mwm_quiz' || get_post_status( $id ) !== 'publish' ) {
			return new WP_Error( 'not_found', 'Quiz not found', [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'id' => $id, 'title' => mwm_title( $id ), 'questions' => MWM_Quiz::questions( $id ), 'summary' => MWM_Quiz::summary( $id ) ] );
	}

	/* ---------------------------------------------------------------
	 * Progress
	 * ------------------------------------------------------------ */

	public static function get_progress(): WP_REST_Response {
		return rest_ensure_response( MWM_Progress::get( get_current_user_id() ) );
	}

	public static function post_progress( WP_REST_Request $r ): WP_REST_Response {
		$patch = (array) $r->get_json_params();
		return rest_ensure_response( MWM_Progress::update( get_current_user_id(), $patch, (bool) ( $patch['replace'] ?? false ) ) );
	}

	/* ---------------------------------------------------------------
	 * Studio
	 * ------------------------------------------------------------ */

	public static function studio_video( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$url = (string) $r->get_param( 'url' );
		$d   = MWM_YouTube::video_details( $url );
		if ( is_wp_error( $d ) ) {
			return new WP_Error( $d->get_error_code(), $d->get_error_message(), [ 'status' => 400 ] );
		}
		$existing = get_posts( [ 'post_type' => 'mwm_lesson', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => 'youtube_id', 'meta_value' => $d['id'], 'no_found_rows' => true ] );
		$d['existing_id']   = $existing ? (int) $existing[0] : 0;
		$d['duration_label'] = $d['seconds'] ? mwm_duration_clock( $d['seconds'] ) : '';
		$d['minutes_label']  = $d['seconds'] ? mwm_duration_label( $d['seconds'] ) : '';
		$d['published_label'] = $d['published'] ? mwm_format_date( $d['published'], 'short' ) : '';
		return rest_ensure_response( $d );
	}

	public static function studio_get_lesson( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$id = (int) $r['id'];
		if ( get_post_type( $id ) !== 'mwm_lesson' ) {
			return new WP_Error( 'not_found', 'Lesson not found', [ 'status' => 404 ] );
		}
		$card = mwm_lesson_card( $id );
		$quiz = mwm_lesson_quiz( $id );
		$card['quiz_json'] = $quiz ? (string) get_post_meta( $quiz->ID, 'questions', true ) : '';
		$card['status']    = get_post_status( $id );
		$card['content']   = get_post_field( 'post_content', $id );
		return rest_ensure_response( $card );
	}

	/**
	 * Create or update a lesson from the Studio wizard.
	 */
	public static function studio_save_lesson( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$p       = (array) $r->get_json_params();
		$id      = (int) ( $p['id'] ?? 0 );
		$yt      = mwm_youtube_id( (string) ( $p['youtube_url'] ?? $p['youtube_id'] ?? '' ) );
		$title   = sanitize_text_field( (string) ( $p['title'] ?? '' ) );
		$level   = sanitize_key( (string) ( $p['level'] ?? '' ) );
		$topic   = sanitize_title( (string) ( $p['topic'] ?? '' ) );
		$status  = ( $p['status'] ?? 'publish' ) === 'draft' ? 'draft' : 'publish';

		if ( $id && get_post_type( $id ) !== 'mwm_lesson' ) {
			return new WP_Error( 'not_found', 'Lesson not found', [ 'status' => 404 ] );
		}
		if ( ! $id && ! $yt ) {
			return new WP_Error( 'missing_video', 'Add the YouTube link first.', [ 'status' => 400 ] );
		}
		if ( ! $title && $yt ) {
			$d = MWM_YouTube::video_details( $yt );
			if ( ! is_wp_error( $d ) ) {
				$title = $d['title'];
				$p['seconds'] = $p['seconds'] ?? $d['seconds'];
				$p['thumbnail'] = $p['thumbnail'] ?? $d['thumbnail'];
			}
		}
		if ( ! $title ) {
			$title = 'Untitled lesson';
		}
		$postarr = [
			'post_type'   => 'mwm_lesson',
			'post_status' => $status,
			'post_title'  => $title,
		];
		if ( isset( $p['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( (string) $p['content'] );
		}
		if ( $id ) {
			$postarr['ID'] = $id;
			$id = (int) wp_update_post( $postarr, true );
		} else {
			$id = (int) wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			return new WP_Error( 'save_failed', 'Something went wrong saving the lesson. Try again in a moment.', [ 'status' => 500 ] );
		}
		if ( $yt ) {
			update_post_meta( $id, 'youtube_id', $yt );
			update_post_meta( $id, 'youtube_url', mwm_youtube_watch_url( $yt ) );
		}
		if ( isset( $p['seconds'] ) ) {
			update_post_meta( $id, 'duration_seconds', (int) $p['seconds'] );
		}
		if ( ! empty( $p['thumbnail'] ) ) {
			update_post_meta( $id, 'thumbnail_url', esc_url_raw( (string) $p['thumbnail'] ) );
		} elseif ( $yt && ! get_post_meta( $id, 'thumbnail_url', true ) ) {
			update_post_meta( $id, 'thumbnail_url', mwm_youtube_thumb( $yt ) );
		}
		wp_set_object_terms( $id, 'lesson', 'mwm_format' );
		if ( $level && isset( mwm_levels()[ $level ] ) ) {
			wp_set_object_terms( $id, $level, 'mwm_level' );
			delete_post_meta( $id, 'needs_level_review' ); // Kym has chosen a level in the Studio.
		}
		if ( $topic ) {
			$term = get_term_by( 'slug', $topic, 'mwm_topic' );
			if ( $term ) {
				wp_set_object_terms( $id, (int) $term->term_id, 'mwm_topic' );
			}
		}
		foreach ( [ 'worksheet', 'answers' ] as $file_key ) {
			if ( array_key_exists( $file_key, $p ) ) {
				$att = (int) $p[ $file_key ];
				if ( $att && get_post_type( $att ) === 'attachment' ) {
					update_post_meta( $id, $file_key, $att );
					wp_update_post( [ 'ID' => $att, 'post_parent' => $id ] );
				} else {
					delete_post_meta( $id, $file_key );
				}
			}
		}
		// Quiz
		if ( array_key_exists( 'quiz', $p ) ) {
			$existing = mwm_lesson_quiz( $id );
			$quiz_raw = is_string( $p['quiz'] ) ? $p['quiz'] : ( $p['quiz'] ? wp_json_encode( $p['quiz'] ) : '' );
			if ( $quiz_raw ) {
				$v = MWM_Quiz::validate( $quiz_raw );
				if ( isset( $v['error'] ) ) {
					return new WP_Error( 'quiz_invalid', $v['error'], [ 'status' => 400 ] );
				}
				$quiz_id = $existing ? $existing->ID : (int) wp_insert_post( [ 'post_type' => 'mwm_quiz', 'post_status' => 'publish', 'post_title' => $title . ' — quiz' ] );
				wp_update_post( [ 'ID' => $quiz_id, 'post_title' => $title . ' — quiz', 'post_status' => 'publish' ] );
				update_post_meta( $quiz_id, 'lesson', $id );
				update_post_meta( $quiz_id, 'questions', wp_json_encode( [ 'questions' => $v['questions'] ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			} elseif ( $existing ) {
				wp_trash_post( $existing->ID );
			}
			delete_post_meta( $id, '_mwm_quiz_id' );
		}
		return rest_ensure_response( mwm_lesson_card( $id ) );
	}

	/**
	 * File upload (PDF or image) → attachment.
	 */
	public static function studio_upload( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$files = $r->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'no_file', 'Choose a file first.', [ 'status' => 400 ] );
		}
		$kind = sanitize_key( (string) $r->get_param( 'kind' ) ) ?: 'pdf';
		$allowed = $kind === 'image' ? [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml' ] : [ 'pdf' => 'application/pdf' ];
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$att_id = media_handle_upload( 'file', 0, [], [ 'test_form' => false, 'mimes' => $allowed ] );
		if ( is_wp_error( $att_id ) ) {
			$msg = $kind === 'image' ? 'That file isn’t an image we can use — try a JPG or PNG.' : 'That file isn’t a PDF — the site only shows PDFs for worksheets and papers.';
			return new WP_Error( 'upload_failed', $msg . ' (' . $att_id->get_error_message() . ')', [ 'status' => 400 ] );
		}
		$info = mwm_attachment_info( $att_id );
		$info['filename'] = wp_basename( get_attached_file( $att_id ) );
		return rest_ensure_response( $info );
	}

	public static function studio_validate_quiz( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$p    = (array) $r->get_json_params();
		$text = is_string( $p['json'] ?? null ) ? $p['json'] : wp_json_encode( $p['json'] ?? '' );
		$v    = MWM_Quiz::validate( (string) $text );
		if ( isset( $v['error'] ) ) {
			return rest_ensure_response( [ 'ok' => false, 'error' => $v['error'] ] );
		}
		$count = count( $v['questions'] );
		return rest_ensure_response( [
			'ok'        => true,
			'questions' => $v['questions'],
			'summary'   => $count . ' questions, ready to go',
			'kinds'     => array_map( static fn( $q ) => MWM_Quiz::kind_label( $q['type'] ), $v['questions'] ),
		] );
	}

	public static function studio_save_past_paper( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$p      = (array) $r->get_json_params();
		$id     = (int) ( $p['id'] ?? 0 );
		$board  = sanitize_key( (string) ( $p['board'] ?? 'edexcel' ) );
		$tier   = ( $p['tier'] ?? 'higher' ) === 'foundation' ? 'foundation' : 'higher';
		$season = ( $p['season'] ?? 'June' ) === 'November' ? 'November' : 'June';
		$year   = (int) ( $p['year'] ?? gmdate( 'Y' ) );
		$num    = max( 1, min( 3, (int) ( $p['paper'] ?? 1 ) ) );
		$calc   = array_key_exists( 'calculator', $p ) ? (bool) $p['calculator'] : ( $num !== 1 );
		$qp     = (int) ( $p['question_paper'] ?? 0 );
		$ms     = (int) ( $p['mark_scheme'] ?? 0 );
		if ( ! $id && ! $qp ) {
			return new WP_Error( 'missing_paper', 'Add the question paper PDF first.', [ 'status' => 400 ] );
		}
		$title = "$season $year · Paper $num (" . ( $calc ? 'calculator' : 'non-calculator' ) . ') · ' . ucfirst( $tier );
		$postarr = [ 'post_type' => 'mwm_past_paper', 'post_status' => 'publish', 'post_title' => $title ];
		if ( $id ) {
			$postarr['ID'] = $id;
			$id = (int) wp_update_post( $postarr, true );
		} else {
			$id = (int) wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			return new WP_Error( 'save_failed', 'Something went wrong saving the paper. Try again in a moment.', [ 'status' => 500 ] );
		}
		wp_set_object_terms( $id, $board, 'mwm_board' );
		update_post_meta( $id, 'tier', $tier );
		update_post_meta( $id, 'series_season', $season );
		update_post_meta( $id, 'series_year', $year );
		update_post_meta( $id, 'paper_number', $num );
		update_post_meta( $id, 'calculator', $calc ? 1 : 0 );
		if ( ! get_post_meta( $id, 'qualification_code', true ) ) {
			update_post_meta( $id, 'qualification_code', '1MA1' );
			update_post_meta( $id, 'marks', 80 );
			update_post_meta( $id, 'duration_minutes', 90 );
		}
		if ( $qp ) {
			update_post_meta( $id, 'question_paper', $qp );
		}
		if ( array_key_exists( 'mark_scheme', $p ) ) {
			if ( $ms ) {
				update_post_meta( $id, 'mark_scheme', $ms );
			} else {
				delete_post_meta( $id, 'mark_scheme' );
			}
		}
		if ( isset( $p['worksheets'] ) && is_array( $p['worksheets'] ) ) {
			update_post_meta( $id, 'worksheets', array_map( 'intval', $p['worksheets'] ) );
		}
		return rest_ensure_response( mwm_past_paper_data( $id ) );
	}

	public static function studio_save_exam_date( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$p      = (array) $r->get_json_params();
		$id     = (int) ( $p['id'] ?? 0 );
		$paper  = sanitize_text_field( (string) ( $p['paper'] ?? 'Paper 1 (non-calculator)' ) );
		$date   = sanitize_text_field( (string) ( $p['date'] ?? '' ) );
		$sess   = ( $p['session'] ?? 'morning' ) === 'afternoon' ? 'afternoon' : 'morning';
		$level  = sanitize_key( (string) ( $p['level'] ?? 'gcse-higher' ) );
		$board  = sanitize_key( (string) ( $p['board'] ?? 'edexcel' ) );
		if ( ! $date || ! strtotime( $date ) ) {
			return new WP_Error( 'missing_date', 'Pick a date first.', [ 'status' => 400 ] );
		}
		if ( empty( $p['verified'] ) ) {
			return new WP_Error( 'not_verified', 'Tick the box to confirm you’ve checked this against the official timetable.', [ 'status' => 400 ] );
		}
		$title = $paper . ' · ' . mwm_level_name( $level );
		$postarr = [ 'post_type' => 'mwm_exam_date', 'post_status' => 'publish', 'post_title' => $title ];
		if ( $id ) {
			$postarr['ID'] = $id;
			$id = (int) wp_update_post( $postarr, true );
		} else {
			$id = (int) wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			return new WP_Error( 'save_failed', 'Something went wrong saving the date. Try again in a moment.', [ 'status' => 500 ] );
		}
		wp_set_object_terms( $id, $level, 'mwm_level' );
		wp_set_object_terms( $id, $board, 'mwm_board' );
		update_post_meta( $id, 'paper_label', $paper );
		update_post_meta( $id, 'exam_date', gmdate( 'Y-m-d', strtotime( $date ) ) );
		update_post_meta( $id, 'session', $sess );
		update_post_meta( $id, 'verified', 1 );
		update_post_meta( $id, 'verified_on', current_time( 'Y-m-d' ) );
		return rest_ensure_response( mwm_exam_date_data( $id ) );
	}

	/**
	 * Everything Kym has published, newest first.
	 */
	public static function content_rows( string $kind = 'all', int $limit = 200 ): array {
		$rows = [];
		if ( in_array( $kind, [ 'all', 'lessons' ], true ) ) {
			foreach ( mwm_query_lessons( [ 'format' => 'lesson', 'per_page' => $limit ] ) as $c ) {
				$extras = [];
				if ( $c['has_worksheet'] ) {
					$extras[] = 'worksheet';
				}
				if ( $c['has_quiz'] ) {
					$extras[] = 'quiz';
				}
				$review = get_post_meta( $c['id'], 'needs_level_review', true ) ? 'level needs checking' : '';
				$rows[] = [
					'id'    => $c['id'],
					'kind'  => 'Lesson',
					'title' => $c['title'],
					'meta'  => implode( ' · ', array_filter( [ $c['level'], $c['topic'], $extras ? implode( ' + ', $extras ) : '', $review ] ) ),
					'url'   => $c['url'],
					'date'  => get_post_field( 'post_date', $c['id'] ),
					'added' => 'Lesson · added ' . mwm_relative_label( get_post_field( 'post_date', $c['id'] ) ),
				];
			}
		}
		if ( in_array( $kind, [ 'all', 'past-papers' ], true ) ) {
			foreach ( get_posts( [ 'post_type' => 'mwm_past_paper', 'post_status' => 'publish', 'posts_per_page' => $limit, 'no_found_rows' => true ] ) as $p ) {
				$d = mwm_past_paper_data( $p );
				$rows[] = [
					'id'    => $p->ID,
					'kind'  => 'Past paper',
					'title' => $d['series'] . ' · ' . $d['title'] . ' · ' . $d['tier_name'],
					'meta'  => $d['ms'] ? 'Question paper + mark scheme' : 'Question paper · mark scheme still to come',
					'url'   => mwm_page_url( 'past-papers' ),
					'date'  => $p->post_date,
					'added' => 'Past paper · added ' . mwm_relative_label( $p->post_date ),
					'data'  => $d,
				];
			}
		}
		if ( in_array( $kind, [ 'all', 'exam-dates' ], true ) ) {
			foreach ( get_posts( [ 'post_type' => 'mwm_exam_date', 'post_status' => 'publish', 'posts_per_page' => $limit, 'no_found_rows' => true ] ) as $p ) {
				$d = mwm_exam_date_data( $p );
				$rows[] = [
					'id'    => $p->ID,
					'kind'  => 'Exam date',
					'title' => $d['paper'] . ' · ' . $d['level_name'],
					'meta'  => $d['full'] . ' · ' . $d['session_label'] . ( $d['verified_label'] ? ' · verified ' . $d['verified_label'] : '' ),
					'url'   => mwm_page_url( 'calendar' ),
					'date'  => $p->post_date,
					'added' => 'Exam date · added ' . mwm_relative_label( $p->post_date ),
					'data'  => $d,
				];
			}
		}
		if ( in_array( $kind, [ 'all', 'quizzes' ], true ) ) {
			foreach ( get_posts( [ 'post_type' => 'mwm_quiz', 'post_status' => 'publish', 'posts_per_page' => $limit, 'no_found_rows' => true ] ) as $p ) {
				$s      = MWM_Quiz::summary( $p->ID );
				$lesson = (int) get_post_meta( $p->ID, 'lesson', true );
				$rows[] = [
					'id'        => $p->ID,
					'kind'      => 'Quiz',
					'title'     => mwm_title( $p->ID ),
					'meta'      => $s['count'] . ' questions · on the lesson page',
					'url'       => get_permalink( $p ),
					'lesson_id' => $lesson,
					'date'      => $p->post_date,
					'added'     => 'Quiz · added ' . mwm_relative_label( $p->post_date ),
				];
			}
		}
		usort( $rows, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
		return $rows;
	}

	public static function studio_content( WP_REST_Request $r ): WP_REST_Response {
		return rest_ensure_response( self::content_rows( sanitize_key( (string) $r->get_param( 'kind' ) ) ?: 'all' ) );
	}

	private static function studio_types(): array {
		return [ 'mwm_lesson', 'mwm_past_paper', 'mwm_exam_date', 'mwm_quiz' ];
	}

	public static function studio_trash( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$id = (int) $r['id'];
		if ( ! in_array( get_post_type( $id ), self::studio_types(), true ) ) {
			return new WP_Error( 'not_found', 'Not found', [ 'status' => 404 ] );
		}
		wp_trash_post( $id );
		return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
	}

	public static function studio_restore( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$id = (int) $r['id'];
		if ( ! in_array( get_post_type( $id ), self::studio_types(), true ) ) {
			return new WP_Error( 'not_found', 'Not found', [ 'status' => 404 ] );
		}
		wp_untrash_post( $id );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
	}

	public static function studio_sync(): WP_REST_Response {
		$state = MWM_YouTube::sync_all();
		return rest_ensure_response( [ 'state' => MWM_YouTube::sync_state(), 'playlists' => MWM_YouTube::playlist_counts(), 'ok' => empty( $state['error'] ), 'error' => $state['error'] ?? '' ] );
	}

	public static function studio_dashboard(): WP_REST_Response {
		return rest_ensure_response( [
			'playlists' => MWM_YouTube::playlist_counts(),
			'sync'      => MWM_YouTube::sync_state(),
			'recent'    => array_slice( self::content_rows( 'all', 50 ), 0, 3 ),
		] );
	}
}
