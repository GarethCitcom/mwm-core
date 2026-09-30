<?php
/**
 * Site stats for the Studio, read through Google Site Kit.
 *
 * Kym is an Editor with view-only access to the Site Kit dashboard (Site Kit → Dashboard → Share). Site Kit's own
 * data endpoints answer view-only users with the module owner's Google connection, so we call those internally with
 * rest_do_request() rather than holding any Google credentials ourselves. Everything Site Kit-specific lives in this
 * class: if a Site Kit update changes its API, this is the one place to fix.
 *
 * Shared (view-only) requests only accept Site Kit's allowlisted metrics and dimensions
 * (googlesitekit_shareable_analytics_4_metrics / _dimensions); everything requested here is on those lists.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Stats {

	public const RANGES = [ 7, 28, 90 ];

	private const CACHE_TTL = HOUR_IN_SECONDS;

	private const CACHE_KEY = 'mwm_stats_';

	private const PAGE_KINDS = [
		'mwm_lesson'     => 'Lesson',
		'mwm_worksheet'  => 'Worksheet',
		'mwm_quiz'       => 'Quiz',
		'mwm_past_paper' => 'Past paper',
		'page'           => 'Page',
		'post'           => 'Post',
	];

	/* Google's channel names, in words Kym uses. */
	private const SOURCES = [
		'Organic Search' => 'Google and other search engines',
		'Direct'         => 'Typed in or bookmarked',
		'Organic Video'  => 'YouTube and video sites',
		'Organic Social' => 'Social media',
		'Referral'       => 'Links on other websites',
		'Email'          => 'Email',
		'Paid Search'    => 'Search ads',
		'Paid Social'    => 'Social media ads',
		'Unassigned'     => 'Not known',
	];

	private const DEVICES = [ 'mobile' => 'Phone', 'desktop' => 'Computer', 'tablet' => 'Tablet' ];

	public static function available(): bool {
		return defined( 'GOOGLESITEKIT_VERSION' );
	}

	/**
	 * GET mwm/v1/studio/stats?days=7|28|90[&refresh=1]
	 */
	public static function rest( WP_REST_Request $r ): WP_REST_Response {
		$days = (int) $r->get_param( 'days' );
		if ( ! in_array( $days, self::RANGES, true ) ) {
			$days = 28;
		}
		return rest_ensure_response( self::report( $days, (bool) $r->get_param( 'refresh' ) ) );
	}

	public static function report( int $days, bool $refresh = false ): array {
		$base = [ 'days' => $days, 'dashboard' => admin_url( 'admin.php?page=googlesitekit-dashboard' ) ];
		if ( ! self::available() ) {
			return $base + [ 'status' => 'no_sitekit', 'message' => 'Site Kit isn’t switched on for this site, so there are no stats to show yet.' ];
		}
		// Editors get this only once an admin has shared the Site Kit dashboard with their role. Checked before the
		// cache too, so a cached report is never shown to someone Site Kit wouldn't show it to.
		if ( ! current_user_can( 'googlesitekit_view_dashboard' ) ) {
			return $base + [ 'status' => 'not_shared', 'message' => 'Site stats haven’t been shared with your account yet. Ask your web developer to share the Site Kit dashboard with you.' ];
		}

		$key = self::CACHE_KEY . $days;
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$tz    = wp_timezone();
		$end   = new DateTimeImmutable( 'yesterday', $tz ); // Today's numbers are still coming in.
		$start = $end->modify( '-' . ( $days - 1 ) . ' days' );
		$pend  = $start->modify( '-1 day' );
		$pstart = $pend->modify( '-' . ( $days - 1 ) . ' days' );
		$d     = static fn( DateTimeImmutable $x ) => $x->format( 'Y-m-d' );
		$range = [ 'start' => $d( $start ), 'end' => $d( $end ), 'prevStart' => $d( $pstart ), 'prevEnd' => $d( $pend ) ];

		$report = $base + [
			'status'    => 'ok',
			'range'     => $range,
			'label'     => $start->format( 'j M' ) . ' – ' . $end->format( 'j M Y' ),
			'generated' => time(),
			'analytics' => self::analytics( $range ),
			'search'    => self::search( $range ),
		];

		// Don't hold on to a failed answer for an hour; the next visit should try Google again.
		if ( empty( $report['analytics']['error'] ) || empty( $report['search']['error'] ) ) {
			set_transient( $key, $report, self::CACHE_TTL );
		}
		return $report;
	}

	/* ---------------------------------------------------------------
	 * Google Analytics
	 * ------------------------------------------------------------ */

	private static function analytics( array $range ): array {
		$dates = [ 'startDate' => $range['start'], 'endDate' => $range['end'] ];

		$totals = self::sitekit( 'analytics-4', 'report', $dates + [
			'compareStartDate' => $range['prevStart'],
			'compareEndDate'   => $range['prevEnd'],
			'metrics'          => 'totalUsers,screenPageViews,sessions,averageSessionDuration',
		] );
		if ( is_wp_error( $totals ) ) {
			return [ 'error' => self::error_message( $totals, 'Google Analytics' ) ] + self::error_detail( $totals );
		}

		// With two date ranges Google adds a date_range_0 / date_range_1 dimension to each row.
		$names = [ 'users', 'views', 'sessions', 'duration' ];
		$out   = [ 'totals' => array_fill_keys( $names, [ 'now' => 0.0, 'prev' => 0.0 ] ) ];
		foreach ( $totals['rows'] ?? [] as $row ) {
			$dims = array_column( $row['dimensionValues'] ?? [], 'value' );
			$when = in_array( 'date_range_1', $dims, true ) ? 'prev' : 'now';
			foreach ( $names as $i => $name ) {
				$out['totals'][ $name ][ $when ] = (float) ( $row['metricValues'][ $i ]['value'] ?? 0 );
			}
		}

		// The rest are nice-to-haves: if one fails, show what we have.
		$series = self::sitekit( 'analytics-4', 'report', $dates + [
			'dimensions' => 'date',
			'metrics'    => 'totalUsers,screenPageViews',
			'orderby'    => [ [ 'dimension' => [ 'dimensionName' => 'date' ] ] ],
		] );
		$out['series'] = [];
		foreach ( is_wp_error( $series ) ? [] : ( $series['rows'] ?? [] ) as $row ) {
			$ymd             = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
			$out['series'][] = [
				'date'  => substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 ),
				'users' => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
				'views' => (int) ( $row['metricValues'][1]['value'] ?? 0 ),
			];
		}
		usort( $out['series'], static fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

		$pages        = self::sitekit( 'analytics-4', 'report', $dates + [
			'dimensions' => 'pagePath,pageTitle',
			'metrics'    => 'screenPageViews,totalUsers',
			'orderby'    => [ [ 'metric' => [ 'metricName' => 'screenPageViews' ], 'desc' => true ] ],
			'limit'      => 40,
		] );
		$out['pages'] = is_wp_error( $pages ) ? null : self::top_pages( $pages['rows'] ?? [] );

		$sources        = self::sitekit( 'analytics-4', 'report', $dates + [
			'dimensions' => 'sessionDefaultChannelGroup',
			'metrics'    => 'sessions',
			'orderby'    => [ [ 'metric' => [ 'metricName' => 'sessions' ], 'desc' => true ] ],
			'limit'      => 10,
		] );
		$out['sources'] = is_wp_error( $sources ) ? null : self::shares( $sources['rows'] ?? [], self::SOURCES, 6 );

		$devices        = self::sitekit( 'analytics-4', 'report', $dates + [
			'dimensions' => 'deviceCategory',
			'metrics'    => 'totalUsers',
			'orderby'    => [ [ 'metric' => [ 'metricName' => 'totalUsers' ], 'desc' => true ] ],
		] );
		$out['devices'] = is_wp_error( $devices ) ? null : self::shares( $devices['rows'] ?? [], self::DEVICES, 3 );

		return $out;
	}

	/**
	 * Merge rows per path (one page can report several titles), drop the Studio and wp-admin, and name each page
	 * after the post it belongs to where we can find it.
	 */
	private static function top_pages( array $rows ): array {
		$merged = [];
		foreach ( $rows as $row ) {
			$path = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
			$path = strtok( $path, '?#' ) ?: '/';
			if ( preg_match( '#^/(studio|wp-admin|wp-login\.php)(/|$)#', $path ) ) {
				continue;
			}
			if ( ! isset( $merged[ $path ] ) ) {
				$merged[ $path ] = [ 'path' => $path, 'gaTitle' => (string) ( $row['dimensionValues'][1]['value'] ?? '' ), 'views' => 0, 'users' => 0 ];
			}
			$merged[ $path ]['views'] += (int) ( $row['metricValues'][0]['value'] ?? 0 );
			$merged[ $path ]['users'] += (int) ( $row['metricValues'][1]['value'] ?? 0 );
		}
		usort( $merged, static fn( $a, $b ) => $b['views'] <=> $a['views'] );

		$out = [];
		foreach ( array_slice( $merged, 0, 10 ) as $p ) {
			$url     = home_url( $p['path'] );
			$post_id = $p['path'] === '/' ? (int) get_option( 'page_on_front' ) : url_to_postid( $url );
			$type    = $post_id ? get_post_type( $post_id ) : '';
			$title   = $p['path'] === '/' ? 'Home page' : ( $post_id ? get_the_title( $post_id ) : self::strip_site_name( $p['gaTitle'] ) );
			$out[]   = [
				'title' => html_entity_decode( $title ?: $p['path'], ENT_QUOTES, 'UTF-8' ),
				'kind'  => self::PAGE_KINDS[ $type ] ?? '',
				'url'   => $url,
				'path'  => $p['path'],
				'views' => $p['views'],
				'users' => $p['users'],
			];
		}
		return $out;
	}

	private static function strip_site_name( string $title ): string {
		$site = get_bloginfo( 'name' );
		return trim( (string) preg_replace( '/\s*[-–—|·]\s*' . preg_quote( $site, '/' ) . '\s*$/u', '', $title ) );
	}

	/**
	 * One-dimension rows → [{label, value, share}], with anything past $max folded into "Everything else".
	 */
	private static function shares( array $rows, array $labels, int $max ): array {
		$items = [];
		$total = 0;
		foreach ( $rows as $row ) {
			$name  = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
			$value = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			if ( $value <= 0 ) {
				continue;
			}
			$total  += $value;
			$items[] = [ 'label' => $labels[ $name ] ?? ( $name ?: 'Not known' ), 'value' => $value ];
		}
		if ( count( $items ) > $max ) {
			$rest    = array_splice( $items, $max - 1 );
			$items[] = [ 'label' => 'Everything else', 'value' => array_sum( array_column( $rest, 'value' ) ) ];
		}
		foreach ( $items as &$item ) {
			$item['share'] = $total ? round( $item['value'] / $total * 100, 1 ) : 0;
		}
		return $items;
	}

	/* ---------------------------------------------------------------
	 * Search Console
	 * ------------------------------------------------------------ */

	private static function search( array $range ): array {
		// One request covers both periods; split by date here.
		$daily = self::sitekit( 'search-console', 'searchanalytics', [
			'startDate'  => $range['prevStart'],
			'endDate'    => $range['end'],
			'dimensions' => 'date',
			'limit'      => 200,
		] );
		if ( is_wp_error( $daily ) ) {
			return [ 'error' => self::error_message( $daily, 'Google Search Console' ) ] + self::error_detail( $daily );
		}
		$totals = [ 'clicks' => [ 'now' => 0, 'prev' => 0 ], 'impressions' => [ 'now' => 0, 'prev' => 0 ] ];
		foreach ( self::rows( $daily ) as $row ) {
			$when = ( $row['keys'][0] ?? '' ) >= $range['start'] ? 'now' : 'prev';
			$totals['clicks'][ $when ]      += (int) ( $row['clicks'] ?? 0 );
			$totals['impressions'][ $when ] += (int) ( $row['impressions'] ?? 0 );
		}

		$queries = self::sitekit( 'search-console', 'searchanalytics', [
			'startDate'  => $range['start'],
			'endDate'    => $range['end'],
			'dimensions' => 'query',
			'limit'      => 10,
		] );
		$list = [];
		foreach ( is_wp_error( $queries ) ? [] : self::rows( $queries ) as $row ) {
			$list[] = [
				'query'       => (string) ( $row['keys'][0] ?? '' ),
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			];
		}
		return [ 'totals' => $totals, 'queries' => is_wp_error( $queries ) ? null : $list ];
	}

	/* ---------------------------------------------------------------
	 * Site Kit plumbing
	 * ------------------------------------------------------------ */

	/**
	 * Run a Site Kit datapoint as the current user and return it as plain arrays.
	 *
	 * @return array|WP_Error
	 */
	private static function sitekit( string $module, string $datapoint, array $params ) {
		$request = new WP_REST_Request( 'GET', "/google-site-kit/v1/modules/{$module}/data/{$datapoint}" );
		$request->set_query_params( $params );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		// Site Kit hands back Google API model objects; this is the same JSON its own dashboard receives.
		$data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
		return is_array( $data ) ? $data : [];
	}

	/* Search Console returns a bare list of rows, or the whole (row-less) response when there's no data. */
	private static function rows( array $data ): array {
		return array_is_list( $data ) ? $data : [];
	}

	private static function error_message( WP_Error $e, string $service ): string {
		$status = (int) ( $e->get_error_data()['status'] ?? 0 );
		switch ( $e->get_error_code() ) {
			case 'rest_no_route':
				return 'Site Kit isn’t switched on for this site.';
			case 'module_not_active':
			case 'invalid_module_slug':
			case 'missing_required_setting':
				return $service . ' isn’t connected in Site Kit yet.';
			case 'rest_forbidden':
				return $service . ' hasn’t been shared with your account. Ask your web developer to share it in Site Kit.';
		}
		if ( $status === 401 || $status === 403 ) {
			return 'Site Kit needs reconnecting to ' . $service . '. Ask your web developer to sign in to Site Kit again.';
		}
		return $service . ' didn’t answer just now. Try again in a few minutes.';
	}

	/* The raw error is only useful to whoever looks after Site Kit. */
	private static function error_detail( WP_Error $e ): array {
		return current_user_can( 'manage_options' ) ? [ 'detail' => $e->get_error_code() . ': ' . $e->get_error_message() ] : [];
	}
}
