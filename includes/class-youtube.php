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
		add_action( self::CRON_HOOK, [ __CLASS__, 'sync_all' ] );
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

	private static function clean_title( string $title ): string {
		$title = preg_replace( '/#\w+/', '', $title );
		$title = preg_replace( '/\s+/', ' ', $title );
		return trim( $title, " \t\n\r\0\x0B-|:" );
	}

	/**
	 * Best-effort level and topic from the video's title, tags and description via topic keyword meta.
	 */
	public static function auto_tag( int $post_id, array $data ): void {
		$hay = strtolower( $data['title'] . ' ' . implode( ' ', $data['tags'] ) . ' ' . substr( $data['description'], 0, 400 ) );
		if ( ! has_term( '', 'mwm_level', $post_id ) ) {
			if ( preg_match( '/\ba[- ]?level\b/', $hay ) ) {
				wp_set_object_terms( $post_id, 'a-level', 'mwm_level' );
			} elseif ( str_contains( $hay, 'higher' ) ) {
				wp_set_object_terms( $post_id, 'gcse-higher', 'mwm_level' );
			} elseif ( str_contains( $hay, 'foundation' ) ) {
				wp_set_object_terms( $post_id, 'gcse-foundation', 'mwm_level' );
			}
		}
		if ( has_term( '', 'mwm_topic', $post_id ) ) {
			return;
		}
		$terms = get_terms( [ 'taxonomy' => 'mwm_topic', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		$best   = null;
		$best_n = 0;
		foreach ( $terms as $t ) {
			$keywords = array_filter( array_map( 'trim', explode( ',', strtolower( (string) get_term_meta( $t->term_id, 'keywords', true ) ) ) ) );
			$keywords[] = strtolower( $t->name );
			$hits = 0;
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
			wp_set_object_terms( $post_id, (int) $best->term_id, 'mwm_topic' );
		}
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
