<?php
/**
 * YouTube: oEmbed/Data API lookups for the Studio, and the daily playlist sync (WP-Cron).
 */

defined( 'ABSPATH' ) || exit;

class MWM_YouTube {

	public const CRON_HOOK  = 'mwm_daily_playlist_sync';
	public const STATE      = 'mwm_sync_state';
	public const API        = 'https://www.googleapis.com/youtube/v3/';

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'daily' ] );
		add_action( 'admin_post_mwm_sync_now', [ __CLASS__, 'admin_sync_now' ] );
	}

	/* ---------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------ */

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// 3:00 am site time, daily.
			$tz    = wp_timezone();
			$next  = new DateTime( 'tomorrow 03:00', $tz );
			wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
			$ts = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	public static function admin_sync_now(): void {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'mwm_sync_now' ) ) {
			wp_die( 'Not allowed.' );
		}
		self::sync_all();
		wp_safe_redirect( admin_url( 'options-general.php?page=mwm-core&mwm_synced=1' ) );
		exit;
	}

	public static function sync_state(): array {
		$state = (array) get_option( self::STATE, [] );
		$last  = (int) ( $state['last_run'] ?? 0 );
		$state['last_label'] = $last ? wp_date( 'j M Y \a\t g:i a', $last ) : '';
		$state['last_relative'] = $last ? self::relative_run_label( $last ) : '';
		return $state;
	}

	private static function relative_run_label( int $ts ): string {
		$diff = time() - $ts;
		if ( $diff < 120 ) {
			return 'Checked just now';
		}
		if ( wp_date( 'Y-m-d', $ts ) === wp_date( 'Y-m-d' ) ) {
			return 'Last checked today at ' . wp_date( 'g:i a', $ts );
		}
		if ( wp_date( 'Y-m-d', $ts ) === wp_date( 'Y-m-d', time() - 86400 ) ) {
			return 'Last checked yesterday at ' . wp_date( 'g:i a', $ts );
		}
		return 'Last checked ' . wp_date( 'j M', $ts );
	}

	/* ---------------------------------------------------------------
	 * Lookups for the Studio
	 * ------------------------------------------------------------ */

	private static function api_key(): string {
		return (string) get_option( MWM_Settings::OPTION_API_KEY, '' );
	}

	private static function api_get( string $endpoint, array $params ): array|WP_Error {
		$params['key'] = self::api_key();
		$url = add_query_arg( array_map( 'rawurlencode', $params ), self::API . $endpoint );
		$res = wp_remote_get( $url, [ 'timeout' => 20 ] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 || ! is_array( $body ) ) {
			$msg = $body['error']['message'] ?? ( 'YouTube replied with HTTP ' . $code );
			return new WP_Error( 'mwm_youtube_api', $msg );
		}
		return $body;
	}

	/**
	 * Fetch details for a video: title, duration, thumbnail, published date.
	 * Uses the Data API when a key is set, otherwise oEmbed plus a best-effort watch-page read.
	 *
	 * @return array|WP_Error {id,title,seconds,thumbnail,published,description,tags,is_short}
	 */
	public static function video_details( string $url_or_id ): array|WP_Error {
		$id = mwm_youtube_id( $url_or_id );
		if ( ! $id ) {
			return new WP_Error( 'mwm_bad_url', 'That doesn’t look like a YouTube link. Copy it from the Share button on YouTube and try again.' );
		}
		if ( self::api_key() ) {
			$body = self::api_get( 'videos', [ 'part' => 'snippet,contentDetails', 'id' => $id ] );
			if ( ! is_wp_error( $body ) ) {
				if ( empty( $body['items'][0] ) ) {
					return new WP_Error( 'mwm_not_found', 'We couldn’t find a video at that link. Check it’s public (or unlisted) on YouTube.' );
				}
				return self::normalise_item( $body['items'][0] );
			}
			$api_error = $body;
		}
		// Fallback: oEmbed for title/thumbnail, watch page for length and date.
		$o = wp_remote_get( 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( 'https://www.youtube.com/watch?v=' . $id ), [ 'timeout' => 15 ] );
		if ( is_wp_error( $o ) || wp_remote_retrieve_response_code( $o ) !== 200 ) {
			return new WP_Error( 'mwm_not_found', 'We couldn’t find a video at that link. Check it’s public (or unlisted) on YouTube.' );
		}
		$ob   = json_decode( wp_remote_retrieve_body( $o ), true ) ?: [];
		$data = [
			'id'          => $id,
			'title'       => (string) ( $ob['title'] ?? '' ),
			'seconds'     => 0,
			'thumbnail'   => mwm_youtube_thumb( $id ),
			'published'   => '',
			'description' => '',
			'tags'        => [],
			'is_short'    => false,
			'source'      => 'oembed',
		];
		$w = wp_remote_get( 'https://www.youtube.com/watch?v=' . $id . '&hl=en', [ 'timeout' => 15, 'headers' => [ 'Accept-Language' => 'en-GB,en;q=0.8' ] ] );
		if ( ! is_wp_error( $w ) && wp_remote_retrieve_response_code( $w ) === 200 ) {
			$html = wp_remote_retrieve_body( $w );
			if ( preg_match( '/"lengthSeconds":"(\d+)"/', $html, $m ) ) {
				$data['seconds'] = (int) $m[1];
			}
			if ( preg_match( '/"publishDate":"([0-9T:\-+.]+)"/', $html, $m ) ) {
				$data['published'] = substr( $m[1], 0, 10 );
			}
			if ( preg_match( '/"shortDescription":"((?:[^"\\\\]|\\\\.)*)"/', $html, $m ) ) {
				$data['description'] = (string) json_decode( '"' . $m[1] . '"' );
			}
		}
		if ( ! empty( $api_error ) && is_wp_error( $api_error ) ) {
			$data['warning'] = $api_error->get_error_message();
		}
		return $data;
	}

	private static function normalise_item( array $item ): array {
		$sn   = $item['snippet'] ?? [];
		$cd   = $item['contentDetails'] ?? [];
		$secs = mwm_iso8601_to_seconds( (string) ( $cd['duration'] ?? '' ) );
		$thumbs = $sn['thumbnails'] ?? [];
		$thumb  = $thumbs['maxres']['url'] ?? $thumbs['standard']['url'] ?? $thumbs['high']['url'] ?? mwm_youtube_thumb( (string) $item['id'] );
		return [
			'id'          => (string) $item['id'],
			'title'       => (string) ( $sn['title'] ?? '' ),
			'seconds'     => $secs,
			'thumbnail'   => (string) $thumb,
			'published'   => substr( (string) ( $sn['publishedAt'] ?? '' ), 0, 10 ),
			'description' => (string) ( $sn['description'] ?? '' ),
			'tags'        => (array) ( $sn['tags'] ?? [] ),
			'is_short'    => $secs > 0 && $secs <= 60,
			'source'      => 'api',
		];
	}

	/* ---------------------------------------------------------------
	 * Playlist sync
	 * ------------------------------------------------------------ */

	/**
	 * Sync every configured playlist. Returns a summary array (also stored in the sync state).
	 */
	/**
	 * Daily cron: pull the playlists, then confirm every lesson's video still plays.
	 */
	public static function daily(): void {
		self::sync_all();
		self::check_videos();
		self::scan_channel();
	}

	/**
	 * Ask YouTube whether each lesson's video is still public and embeddable.
	 * Stores `video_status` (ok | missing | private | unembeddable | invalid) and `video_checked` on each lesson.
	 * Returns counts, or a WP_Error when there is no API key.
	 */
	public static function check_videos(): array|WP_Error {
		if ( ! self::api_key() ) {
			return new WP_Error( 'mwm_no_key', 'No YouTube Data API key is set.' );
		}
		$ids = get_posts( [ 'post_type' => 'mwm_lesson', 'post_status' => [ 'publish', 'draft' ], 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ] );
		$by_video = [];
		$counts   = [ 'checked' => 0, 'ok' => 0, 'problems' => 0 ];
		foreach ( $ids as $id ) {
			$vid = (string) get_post_meta( $id, 'youtube_id', true );
			if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $vid ) ) {
				update_post_meta( $id, 'video_status', 'invalid' );
				update_post_meta( $id, 'video_checked', time() );
				$counts['checked']++;
				$counts['problems']++;
				continue;
			}
			$by_video[ $vid ][] = $id;
		}
		foreach ( array_chunk( array_keys( $by_video ), 50 ) as $chunk ) {
			$body = self::api_get( 'videos', [ 'part' => 'status,snippet', 'id' => implode( ',', $chunk ), 'maxResults' => 50 ] );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$found = []; $thumbs = [];
			foreach ( (array) ( $body['items'] ?? [] ) as $item ) {
				$s = $item['status'] ?? [];
				// While we're here, record the best thumbnail YouTube actually has (imported lessons assumed "maxres", which some videos lack).
				$t = $item['snippet']['thumbnails'] ?? [];
				$thumbs[ $item['id'] ] = (string) ( $t['maxres']['url'] ?? $t['standard']['url'] ?? $t['high']['url'] ?? '' );
				if ( ( $s['privacyStatus'] ?? 'public' ) === 'private' ) {
					$found[ $item['id'] ] = 'private';
				} elseif ( isset( $s['embeddable'] ) && ! $s['embeddable'] ) {
					$found[ $item['id'] ] = 'unembeddable';
				} elseif ( ( $s['uploadStatus'] ?? 'processed' ) !== 'processed' ) {
					$found[ $item['id'] ] = 'missing';
				} else {
					$found[ $item['id'] ] = 'ok';
				}
			}
			foreach ( $chunk as $vid ) {
				$status = $found[ $vid ] ?? 'missing';
				foreach ( $by_video[ $vid ] as $id ) {
					update_post_meta( $id, 'video_status', $status );
					if ( ! empty( $thumbs[ $vid ] ) && ! has_post_thumbnail( $id ) ) {
						update_post_meta( $id, 'thumbnail_url', $thumbs[ $vid ] );
					}
					update_post_meta( $id, 'video_checked', time() );
					$counts['checked']++;
					$counts[ $status === 'ok' ? 'ok' : 'problems' ]++;
				}
			}
		}
		update_option( 'mwm_video_check', [ 'at' => time() ] + $counts, false );
		return $counts;
	}

	/**
	 * Human label for a stored video_status.
	 */
	public static function video_status_label( string $status ): string {
		return [
			'missing'      => 'video not found on YouTube',
			'private'      => 'video is private on YouTube',
			'unembeddable' => 'video can’t be embedded',
			'invalid'      => 'no valid YouTube link',
		][ $status ] ?? '';
	}

	public static function sync_all(): array {
		$state = [ 'last_run' => time(), 'playlists' => [], 'error' => '' ];
		$lines = [];
		if ( ! self::api_key() ) {
			$state['error'] = 'No YouTube Data API key is set (Settings → Maths with Melissa).';
		} else {
			foreach ( MWM_Settings::playlists() as $key => $pl ) {
				if ( ! $pl['ids'] ) {
					$state['playlists'][ $key ] = [ 'skipped' => true ];
					continue;
				}
				$totals = [ 'total' => 0, 'added' => 0, 'updated' => 0, 'unpublished' => 0, 'at' => time() ];
				foreach ( $pl['ids'] as $pid ) {
					$res = self::sync_playlist( array_merge( $pl, [ 'id' => $pid ] ) );
					if ( is_wp_error( $res ) ) {
						$state['playlists'][ $key ] = [ 'error' => $res->get_error_message() ];
						$state['error'] = $pl['label'] . ' (' . $pid . '): ' . $res->get_error_message();
						continue 2;
					}
					foreach ( [ 'total', 'added', 'updated', 'unpublished' ] as $k ) {
						$totals[ $k ] += $res[ $k ];
					}
				}
				$state['playlists'][ $key ] = $totals;
				$lines[] = $totals['total'] . ' in ' . $pl['label'] . ( $totals['added'] ? " (+{$totals['added']} new)" : '' );
			}
		}
		$state['summary'] = $lines ? implode( ', ', $lines ) : ( $state['error'] ?: 'Nothing to sync yet.' );
		update_option( self::STATE, $state, false );
		return $state;
	}

	/**
	 * Fetch all videos in a playlist and upsert them as lessons.
	 */
	public static function sync_playlist( array $pl ): array|WP_Error {
		$video_ids = [];
		$token     = '';
		do {
			$params = [ 'part' => 'contentDetails,status', 'playlistId' => $pl['id'], 'maxResults' => 50 ];
			if ( $token ) {
				$params['pageToken'] = $token;
			}
			$body = self::api_get( 'playlistItems', $params );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			foreach ( (array) ( $body['items'] ?? [] ) as $item ) {
				$privacy = $item['status']['privacyStatus'] ?? 'public';
				if ( $privacy === 'private' ) {
					continue;
				}
				$vid = $item['contentDetails']['videoId'] ?? '';
				if ( $vid ) {
					$video_ids[] = $vid;
				}
			}
			$token = (string) ( $body['nextPageToken'] ?? '' );
		} while ( $token );

		$added   = 0;
		$updated = 0;
		$seen    = [];
		foreach ( array_chunk( array_unique( $video_ids ), 50 ) as $chunk ) {
			$body = self::api_get( 'videos', [ 'part' => 'snippet,contentDetails,status', 'id' => implode( ',', $chunk ), 'maxResults' => 50 ] );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			foreach ( (array) ( $body['items'] ?? [] ) as $item ) {
				if ( ( $item['status']['privacyStatus'] ?? 'public' ) === 'private' || ( $item['status']['uploadStatus'] ?? 'processed' ) !== 'processed' ) {
					continue;
				}
				$data   = self::normalise_item( $item );
				$result = self::upsert( $data, $pl );
				$seen[] = $result['id'];
				if ( $result['created'] ) {
					$added++;
				} else {
					$updated++;
				}
			}
		}

		// Videos removed from the playlist go back to draft (never deleted).
		$stale = get_posts( [
			'post_type'      => 'mwm_lesson',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'playlist_id',
			'meta_value'     => $pl['id'],
			'post__not_in'   => $seen ?: [ 0 ],
		] );
		foreach ( $stale as $sid ) {
			wp_update_post( [ 'ID' => $sid, 'post_status' => 'draft' ] );
		}

		return [ 'total' => count( $seen ), 'added' => $added, 'updated' => $updated, 'unpublished' => count( $stale ), 'at' => time() ];
	}

	/**
	 * Create or update the lesson post for a synced video. Kym's manual edits (level/topic/title) are kept.
	 */
	public static function upsert( array $data, array $pl ): array {
		$existing = get_posts( [
			'post_type'      => 'mwm_lesson',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'youtube_id',
			'meta_value'     => $data['id'],
		] );
		$created = false;
		if ( $existing ) {
			$post_id = (int) $existing[0];
			if ( get_post_status( $post_id ) !== 'publish' && get_post_meta( $post_id, 'playlist_id', true ) === $pl['id'] ) {
				wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
			}
			// Follow YouTube title changes unless the title was edited on the site since the last sync.
			$current   = trim( get_post_field( 'post_title', $post_id ) );
			$last_sync = (string) get_post_meta( $post_id, 'synced_title', true );
			$fresh     = self::clean_title( $data['title'] );
			if ( $current === '' || ( $last_sync !== '' && $current === $last_sync && $fresh !== $current ) ) {
				wp_update_post( [ 'ID' => $post_id, 'post_title' => $fresh ] );
			}
		} else {
			$post_id = (int) wp_insert_post( [
				'post_type'    => 'mwm_lesson',
				'post_status'  => 'publish',
				'post_title'   => self::clean_title( $data['title'] ),
				'post_content' => wp_kses_post( wpautop( esc_html( $data['description'] ) ) ),
				'post_date'    => $data['published'] ? $data['published'] . ' 09:00:00' : current_time( 'mysql' ),
			] );
			$created = true;
			if ( ! $post_id ) {
				return [ 'id' => 0, 'created' => false ];
			}
			update_post_meta( $post_id, 'youtube_id', $data['id'] );
			update_post_meta( $post_id, 'youtube_url', mwm_youtube_watch_url( $data['id'] ) );
			wp_set_object_terms( $post_id, self::format_for( $data, $pl ), 'mwm_format' );
			if ( $pl['theme'] ) {
				wp_set_object_terms( $post_id, $pl['theme'], 'mwm_theme' );
			}
			self::auto_tag( $post_id, $data );
		}
		update_post_meta( $post_id, 'playlist_id', $pl['id'] );
		update_post_meta( $post_id, 'synced_title', self::clean_title( $data['title'] ) );
		update_post_meta( $post_id, 'duration_seconds', $data['seconds'] );
		// Keep synced videos in the right format as durations come in; never touch lessons Kym added or we imported.
		if ( ! $created && ! get_post_meta( $post_id, 'source_id', true ) && mwm_get_term_slug( $post_id, 'mwm_format' ) !== 'lesson' ) {
			wp_set_object_terms( $post_id, self::format_for( $data, $pl ), 'mwm_format' );
		}
		update_post_meta( $post_id, 'thumbnail_url', $data['thumbnail'] );
		update_post_meta( $post_id, 'yt_published', $data['published'] );
		update_post_meta( $post_id, 'last_synced', time() );
		return [ 'id' => $post_id, 'created' => $created ];
	}

	/**
	 * Shorts (up to 3 minutes) go to Quick Maths; longer videos from a themed playlist are Gaming & Story lessons.
	 */
	public static function format_for( array $data, array $pl ): string {
		$is_short = $data['seconds'] > 0 && $data['seconds'] <= 180;
		if ( $is_short ) {
			return 'short';
		}
		return $pl['theme'] ? 'gaming' : 'lesson';
	}

	/**
	 * Drop hashtags from a YouTube title. If the title was nothing but hashtags, keep the words instead.
	 */
	public static function clean_title( string $title ): string {
		$clean = preg_replace( '/#\w+/', '', $title );
		$clean = trim( preg_replace( '/\s+/', ' ', $clean ), " \t\n\r\0\x0B-|:" );
		if ( $clean === '' ) {
			$clean = trim( preg_replace( '/\s+/', ' ', str_replace( '#', '', $title ) ) );
			$clean = ucfirst( $clean );
		}
		return $clean;
	}

	/**
	 * Best-effort level and topic from the video's title, tags and description via topic keyword meta.
	 */
	public static function auto_tag( int $post_id, array $data ): void {
		$guess = self::guess_tags( $data );
		if ( $guess['level'] && ! has_term( '', 'mwm_level', $post_id ) ) {
			wp_set_object_terms( $post_id, $guess['level'], 'mwm_level' );
		}
		if ( $guess['topic_id'] && ! has_term( '', 'mwm_topic', $post_id ) ) {
			wp_set_object_terms( $post_id, $guess['topic_id'], 'mwm_topic' );
		}
	}

	/**
	 * Guess level and topic from a video's title, tags and description. Used by the sync and by lesson suggestions.
	 */
	public static function guess_tags( array $data ): array {
		$hay   = strtolower( ( $data['title'] ?? '' ) . ' ' . implode( ' ', (array) ( $data['tags'] ?? [] ) ) . ' ' . mb_substr( (string) ( $data['description'] ?? '' ), 0, 400 ) );
		$level = '';
		if ( preg_match( '/\ba[- ]?level\b/', $hay ) ) {
			$level = 'a-level';
		} elseif ( str_contains( $hay, 'higher' ) ) {
			$level = 'gcse-higher';
		} elseif ( str_contains( $hay, 'foundation' ) ) {
			$level = 'gcse-foundation';
		}
		$out   = [ 'level' => $level, 'topic_id' => 0, 'topic' => '', 'topic_name' => '' ];
		$terms = get_terms( [ 'taxonomy' => 'mwm_topic', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		$best   = null;
		$best_n = 0;
		foreach ( $terms as $t ) {
			$keywords   = array_filter( array_map( 'trim', explode( ',', strtolower( (string) get_term_meta( $t->term_id, 'keywords', true ) ) ) ) );
			$keywords[] = strtolower( $t->name );
			$hits       = 0;
			foreach ( $keywords as $kw ) {
				if ( $kw !== '' && str_contains( $hay, $kw ) ) {
					$hits += strlen( $kw ) > 4 ? 2 : 1;
				}
			}
			if ( $hits > $best_n ) {
				$best_n = $hits;
				$best   = $t;
			}
		}
		if ( $best ) {
			$out['topic_id']   = (int) $best->term_id;
			$out['topic']      = $best->slug;
			$out['topic_name'] = wp_specialchars_decode( $best->name );
			$out['subtopic']   = '';
			if ( $best->parent ) { // A subtopic matched: the wizard wants its parent as the topic.
				$parent = get_term( $best->parent, 'mwm_topic' );
				if ( $parent && ! is_wp_error( $parent ) ) {
					$out['subtopic']   = $best->slug;
					$out['topic']      = $parent->slug;
					$out['topic_name'] = wp_specialchars_decode( $parent->name ) . ' · ' . wp_specialchars_decode( $best->name );
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Channel scan → lesson suggestions
	 * ------------------------------------------------------------ */

	public const SCAN    = 'mwm_channel_scan';
	public const IGNORED = 'mwm_ignored_videos';

	/**
	 * The channel handle/ID from the URL in settings: "@mathswithmelissa" or "UC…".
	 */
	private static function channel_ref(): array {
		$path = trim( (string) wp_parse_url( mwm_youtube_channel_url(), PHP_URL_PATH ), '/' );
		if ( preg_match( '~^@([\w.\-]+)~', $path, $m ) ) {
			return [ 'forHandle' => $m[1] ];
		}
		if ( preg_match( '~^channel/(UC[\w\-]+)~', $path, $m ) ) {
			return [ 'id' => $m[1] ];
		}
		if ( preg_match( '~^(?:c|user)/([\w.\-]+)~', $path, $m ) ) {
			return [ 'forUsername' => $m[1] ];
		}
		return [ 'forHandle' => $path ?: 'mathswithmelissa' ];
	}

	/**
	 * List every public/unlisted upload on the channel (about 15 API units for 500 videos) and store it.
	 */
	public static function scan_channel(): array|WP_Error {
		if ( ! self::api_key() ) {
			return new WP_Error( 'mwm_no_key', 'No YouTube Data API key is set.' );
		}
		$ch = self::api_get( 'channels', [ 'part' => 'contentDetails,snippet' ] + self::channel_ref() );
		if ( is_wp_error( $ch ) ) {
			return $ch;
		}
		if ( empty( $ch['items'][0] ) ) {
			return new WP_Error( 'mwm_no_channel', 'The channel in Settings → Maths with Melissa couldn’t be found on YouTube.' );
		}
		$uploads = (string) ( $ch['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '' );
		$ids     = [];
		$token   = '';
		do {
			$params = [ 'part' => 'contentDetails', 'playlistId' => $uploads, 'maxResults' => 50 ];
			if ( $token ) {
				$params['pageToken'] = $token;
			}
			$body = self::api_get( 'playlistItems', $params );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			foreach ( (array) ( $body['items'] ?? [] ) as $item ) {
				$ids[] = (string) ( $item['contentDetails']['videoId'] ?? '' );
			}
			$token = (string) ( $body['nextPageToken'] ?? '' );
		} while ( $token );

		$videos = [];
		foreach ( array_chunk( array_filter( array_unique( $ids ) ), 50 ) as $chunk ) {
			$body = self::api_get( 'videos', [ 'part' => 'snippet,contentDetails,status', 'id' => implode( ',', $chunk ), 'maxResults' => 50 ] );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			foreach ( (array) ( $body['items'] ?? [] ) as $item ) {
				$privacy = (string) ( $item['status']['privacyStatus'] ?? 'public' );
				if ( $privacy === 'private' || ( $item['status']['uploadStatus'] ?? 'processed' ) !== 'processed' ) {
					continue;
				}
				$d = self::normalise_item( $item );
				$videos[ $d['id'] ] = [
					'title'     => $d['title'],
					'seconds'   => $d['seconds'],
					'thumbnail' => $d['thumbnail'],
					'published' => $d['published'],
					'privacy'   => $privacy,
					'tags'      => array_slice( $d['tags'], 0, 20 ),
					'blurb'     => mb_substr( $d['description'], 0, 400 ),
				];
			}
		}
		$scan = [ 'at' => time(), 'channel' => (string) ( $ch['items'][0]['snippet']['title'] ?? '' ), 'total' => count( $videos ), 'videos' => $videos ];
		update_option( self::SCAN, $scan, false );
		return $scan;
	}

	/**
	 * Channel videos that aren't on the site yet, split into live suggestions and ones Kym has hidden.
	 * Lessons in any status count as "on the site"; trashed ones count as removed on purpose.
	 */
	public static function suggestions(): array {
		$scan    = (array) get_option( self::SCAN, [] );
		$ignored = (array) get_option( self::IGNORED, [] );
		$videos  = (array) ( $scan['videos'] ?? [] );
		global $wpdb;
		$on_site = $videos ? $wpdb->get_col( "SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'youtube_id' AND p.post_type = 'mwm_lesson' AND p.post_status <> 'auto-draft'" ) : [];
		$on_site = array_flip( array_filter( $on_site ) );
		$items   = [];
		$hidden  = [];
		foreach ( $videos as $id => $v ) {
			if ( isset( $on_site[ $id ] ) ) {
				continue;
			}
			$guess = self::guess_tags( [ 'title' => $v['title'], 'tags' => $v['tags'] ?? [], 'description' => $v['blurb'] ?? '' ] );
			$row   = [
				'id'              => $id,
				'title'           => self::clean_title( $v['title'] ),
				'seconds'         => (int) $v['seconds'],
				'duration_label'  => mwm_duration_label( (int) $v['seconds'], (int) $v['seconds'] <= 180 ? 'short' : 'lesson' ),
				'is_short'        => (int) $v['seconds'] > 0 && (int) $v['seconds'] <= 180,
				'thumbnail'       => $v['thumbnail'],
				'published'       => $v['published'],
				'published_label' => $v['published'] ? mwm_relative_label( $v['published'] . ' 09:00:00' ) : '',
				'unlisted'        => ( $v['privacy'] ?? 'public' ) === 'unlisted',
				'level'           => $guess['level'],
				'level_name'      => $guess['level'] ? mwm_level_name( $guess['level'] ) : '',
				'topic'           => $guess['topic'],
				'subtopic'        => $guess['subtopic'] ?? '',
				'topic_name'      => $guess['topic_name'],
				'url'             => mwm_youtube_watch_url( $id ),
			];
			if ( isset( $ignored[ $id ] ) ) {
				$hidden[] = $row;
			} else {
				$items[] = $row;
			}
		}
		$by_date = static fn( $a, $b ) => strcmp( $b['published'], $a['published'] );
		usort( $items, $by_date );
		usort( $hidden, $by_date );
		return [
			'scanned_at'    => (int) ( $scan['at'] ?? 0 ),
			'scanned_label' => ! empty( $scan['at'] ) ? self::relative_run_label( (int) $scan['at'] ) : 'Not scanned yet',
			'channel'       => (string) ( $scan['channel'] ?? '' ),
			'total'         => (int) ( $scan['total'] ?? 0 ),
			'items'         => $items,
			'hidden'        => $hidden,
		];
	}

	/**
	 * Hide a suggestion for good (or bring it back with $undo).
	 */
	public static function ignore_video( string $id, bool $undo = false ): void {
		$ignored = (array) get_option( self::IGNORED, [] );
		if ( $undo ) {
			unset( $ignored[ $id ] );
		} else {
			$ignored[ $id ] = time();
		}
		update_option( self::IGNORED, $ignored, false );
	}

	/**
	 * Put a channel video on the site directly: as a Quick Maths short, or as a draft lesson to finish later.
	 */
	public static function create_from_video( string $id, string $format = 'lesson', string $status = 'draft' ): int|WP_Error {
		$id = mwm_youtube_id( $id );
		if ( ! $id ) {
			return new WP_Error( 'mwm_bad_id', 'That video ID doesn’t look right.' );
		}
		$existing = get_posts( [ 'post_type' => 'mwm_lesson', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => 'youtube_id', 'meta_value' => $id ] );
		if ( $existing ) {
			return (int) $existing[0];
		}
		$d = self::video_details( $id );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$post_id = (int) wp_insert_post( [
			'post_type'    => 'mwm_lesson',
			'post_status'  => $status === 'publish' ? 'publish' : 'draft',
			'post_title'   => self::clean_title( $d['title'] ),
			'post_content' => wp_kses_post( wpautop( esc_html( $d['description'] ) ) ),
			'post_date'    => $d['published'] ? $d['published'] . ' 09:00:00' : current_time( 'mysql' ),
		] );
		if ( ! $post_id ) {
			return new WP_Error( 'mwm_insert', 'The lesson couldn’t be created.' );
		}
		update_post_meta( $post_id, 'youtube_id', $id );
		update_post_meta( $post_id, 'youtube_url', mwm_youtube_watch_url( $id ) );
		update_post_meta( $post_id, 'synced_title', self::clean_title( $d['title'] ) );
		update_post_meta( $post_id, 'duration_seconds', $d['seconds'] );
		update_post_meta( $post_id, 'thumbnail_url', $d['thumbnail'] );
		update_post_meta( $post_id, 'yt_published', $d['published'] );
		update_post_meta( $post_id, 'video_status', 'ok' );
		wp_set_object_terms( $post_id, $format === 'short' ? 'short' : 'lesson', 'mwm_format' );
		self::auto_tag( $post_id, $d );
		if ( ! has_term( '', 'mwm_level', $post_id ) ) {
			wp_set_object_terms( $post_id, 'gcse-foundation', 'mwm_level' );
			update_post_meta( $post_id, 'needs_level_review', 1 );
		}
		return $post_id;
	}

	/**
	 * Counts per playlist for the Studio dashboard ("42 shorts on the site").
	 */
	public static function playlist_counts(): array {
		$out = [];
		foreach ( MWM_Settings::playlists() as $key => $pl ) {
			$count = 0;
			if ( $pl['ids'] ) {
				$q = new WP_Query( [
					'post_type'      => 'mwm_lesson',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => [ [ 'key' => 'playlist_id', 'value' => $pl['ids'], 'compare' => 'IN' ] ],
				] );
				$count = (int) $q->found_posts;
			}
			$noun  = $pl['format'] === 'short' ? 'shorts' : 'videos';
			$label = $pl['label'] . ( count( $pl['ids'] ) > 1 ? ' (' . count( $pl['ids'] ) . ' playlists)' : '' );
			$out[] = [
				'key'   => $key,
				'name'  => $label,
				'id'    => $pl['id'],
				'count' => $count,
				'meta'  => $pl['ids'] ? "$count $noun on the site" : 'Playlist not set up yet',
			];
		}
		return $out;
	}
}
