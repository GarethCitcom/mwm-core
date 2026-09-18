<?php
/**
 * One-off lesson importer from the old (Oxygen-based) site.
 *
 * Sources:
 *   --source=https://old-site   REST API (/wp-json/wp/v2/{post-type}?_embed)
 *   --file=export.xml           WordPress WXR export
 *   --file=lessons.json         JSON array of {id,title,url,youtube,level,topic,worksheet,answers,content,date}
 *
 * Each old lesson becomes a mwm_lesson with ACF fields populated and PDFs sideloaded into the media library.
 * The old post ID is kept in `source_id` and the old URL in `source_url`, which drive the 301 map.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Importer {

	private array $opts;
	private bool $dry;
	private array $stats = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'pdfs' => 0 ];

	public function __construct( array $opts ) {
		$this->opts = $opts;
		$this->dry  = isset( $opts['dry-run'] );
	}

	public function run(): void {
		$rows = [];
		if ( ! empty( $this->opts['file'] ) ) {
			$file = $this->opts['file'];
			if ( ! file_exists( $file ) ) {
				WP_CLI::error( "File not found: $file" );
			}
			$rows = str_ends_with( strtolower( $file ), '.json' ) ? $this->from_json( $file ) : $this->from_wxr( $file );
		} elseif ( ! empty( $this->opts['source'] ) ) {
			$rows = $this->from_rest( rtrim( $this->opts['source'], '/' ) );
		} else {
			WP_CLI::error( 'Give me the old site with --source=https://… or an export with --file=…' );
		}
		if ( ! empty( $this->opts['limit'] ) ) {
			$rows = array_slice( $rows, 0, (int) $this->opts['limit'] );
		}
		WP_CLI::log( sprintf( 'Found %d lessons to look at.', count( $rows ) ) );
		foreach ( $rows as &$row ) {
			if ( ! $row['youtube'] || ( ! $row['worksheet'] && ! $row['answers'] ) ) {
				$row = $this->scrape( $row );
			}
		}
		unset( $row );
		if ( $this->dry ) {
			$table = array_map( static fn( $r ) => [
				'old_id'    => $r['id'],
				'title'     => mb_substr( $r['title'], 0, 48 ),
				'youtube'   => $r['youtube'] ?: '—',
				'level'     => $r['level'] ?: '?',
				'topic'     => $r['topic'] ?: '?',
				'worksheet' => $r['worksheet'] ? basename( $r['worksheet'] ) : '—',
				'answers'   => $r['answers'] ? basename( $r['answers'] ) : '—',
			], $rows );
			WP_CLI\Utils\format_items( 'table', $table, [ 'old_id', 'title', 'youtube', 'level', 'topic', 'worksheet', 'answers' ] );
			return;
		}
		foreach ( $rows as $r ) {
			$this->import_row( $r );
		}
		update_option( 'mwm_redirect_map', MWM_Redirects::build_map() );
		WP_CLI::success( sprintf( 'Created %d, updated %d, skipped %d, errors %d, PDFs %d, level guessed from --default-level %d. Redirect map saved.', $this->stats['created'], $this->stats['updated'], $this->stats['skipped'], $this->stats['errors'], $this->stats['pdfs'], $this->stats['level_defaulted'] ?? 0 ) );
	}

	/* ---------------------------------------------------------------
	 * Worksheets (old CPT → our worksheet posts, matched by PDF URL)
	 * ------------------------------------------------------------ */

	public function run_worksheets(): void {
		$base = rtrim( (string) $this->opts['source'], '/' );
		$rows = [];
		$page = 1;
		do {
			$res = wp_remote_get( "$base/wp-json/wp/v2/worksheet?per_page=100&page=$page&_embed=1&status=publish", [ 'timeout' => 60 ] );
			if ( is_wp_error( $res ) ) {
				WP_CLI::error( 'Could not reach the old site: ' . $res->get_error_message() );
			}
			$code = wp_remote_retrieve_response_code( $res );
			if ( $code === 400 && $page > 1 ) {
				break;
			}
			if ( $code !== 200 ) {
				WP_CLI::error( "The old site replied with HTTP $code." );
			}
			$items = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $items ) || ! $items ) {
				break;
			}
			foreach ( $items as $it ) {
				$topics = [];
				foreach ( (array) ( $it['_embedded']['wp:term'] ?? [] ) as $g ) {
					foreach ( (array) $g as $t ) {
						$topics[] = html_entity_decode( (string) ( $t['name'] ?? '' ) );
					}
				}
				$rows[] = [ 'id' => (int) $it['id'], 'title' => html_entity_decode( (string) $it['title']['rendered'] ), 'slug' => (string) $it['slug'], 'url' => (string) $it['link'], 'topic' => implode( '|', array_unique( $topics ) ), 'date' => (string) ( $it['date'] ?? '' ) ];
			}
			$total_pages = (int) wp_remote_retrieve_header( $res, 'x-wp-totalpages' );
			$page++;
		} while ( $page <= max( 1, $total_pages ) );
		WP_CLI::log( sprintf( 'Found %d worksheet pages on the old site.', count( $rows ) ) );

		$matched = 0;
		$created = 0;
		$missing = 0;
		foreach ( $rows as $r ) {
			// The PDF link is only in the page HTML.
			$html = wp_remote_retrieve_body( wp_remote_get( $r['url'], [ 'timeout' => 45 ] ) );
			if ( preg_match( '~<main[^>]*>(.*)</main>~is', $html, $m ) ) {
				$html = $m[1];
			}
			$pdfs = $this->find_pdfs( $html, [] );
			$pdf  = $pdfs['worksheet'] ?: $pdfs['answers'];
			$att  = $pdf ? get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_mwm_source_url', 'meta_value' => $pdf, 'no_found_rows' => true ] ) : [];
			$ws   = 0;
			if ( $att ) {
				$found = get_posts( [ 'post_type' => 'mwm_worksheet', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => 'pdf', 'meta_value' => (int) $att[0], 'no_found_rows' => true ] );
				$ws = $found ? (int) $found[0] : 0;
			}
			if ( $this->dry ) {
				WP_CLI::log( sprintf( '  %s #%d %s → %s', $ws ? 'match ' : ( $pdf ? 'new   ' : 'no-pdf' ), $r['id'], $r['title'], $ws ? "worksheet #$ws" : ( $pdf ? basename( $pdf ) : '—' ) ) );
				continue;
			}
			if ( ! $ws ) {
				if ( ! $pdf ) {
					$missing++;
					WP_CLI::warning( "  no PDF found for {$r['url']}" );
					continue;
				}
				$att_id = $att ? (int) $att[0] : $this->sideload_pdf( $pdf, 0, $r['title'] );
				if ( ! $att_id ) {
					$missing++;
					continue;
				}
				$ws = mwm_upsert_worksheet( 0, $att_id, 0, $r['title'] );
				$created++;
			} else {
				$matched++;
			}
			// Old title, slug and topic win: they are what students and search engines know.
			wp_update_post( [ 'ID' => $ws, 'post_title' => $r['title'], 'post_name' => $r['slug'], 'post_date' => $r['date'] ?: get_post_field( 'post_date', $ws ) ] );
			update_post_meta( $ws, 'source_id', $r['id'] );
			update_post_meta( $ws, 'source_url', $r['url'] );
			if ( $r['topic'] && ! has_term( '', 'mwm_topic', $ws ) ) {
				$topic = $this->map_topic( $r['topic'], $r['title'] );
				if ( $topic ) {
					wp_set_object_terms( $ws, $this->ensure_subtopic( $topic, $r['topic'] ), 'mwm_topic' );
				}
			}
			WP_CLI::log( sprintf( '  #%d → worksheet #%d /worksheets/%s/', $r['id'], $ws, $r['slug'] ) );
		}
		update_option( 'mwm_redirect_map', MWM_Redirects::build_map() );
		WP_CLI::success( sprintf( 'Matched %d, created %d, no PDF %d. Redirect map saved.', $matched, $created, $missing ) );
	}

	/* ---------------------------------------------------------------
	 * Readers
	 * ------------------------------------------------------------ */

	private function from_json( string $file ): array {
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'That JSON file doesn’t contain a list of lessons.' );
		}
		return array_map( function ( $r ) {
			$r       = (array) $r;
			$content = (string) ( $r['content'] ?? '' );
			$meta    = (array) ( $r['meta'] ?? [] );
			if ( empty( $r['youtube'] ) ) {
				$r['youtube'] = $this->find_youtube( $content, $meta );
			}
			if ( empty( $r['worksheet'] ) && empty( $r['answers'] ) ) {
				$pdfs = $this->find_pdfs( $content, $meta );
				$r['worksheet'] = $pdfs['worksheet'];
				$r['answers']   = $pdfs['answers'];
			}
			return $this->normalise( $r );
		}, $data );
	}

	private function from_rest( string $base ): array {
		$type = $this->opts['post-type'] ?? 'lesson';
		$rows = [];
		$page = 1;
		do {
			$url = "$base/wp-json/wp/v2/" . rawurlencode( $type ) . "?per_page=100&page=$page&_embed=1&status=publish";
			$res = wp_remote_get( $url, [ 'timeout' => 60 ] );
			if ( is_wp_error( $res ) ) {
				WP_CLI::error( 'Could not reach the old site: ' . $res->get_error_message() );
			}
			$code = wp_remote_retrieve_response_code( $res );
			if ( $code === 400 && $page > 1 ) {
				break; // past the last page
			}
			if ( $code !== 200 ) {
				WP_CLI::error( "The old site replied with HTTP $code for $url. Check --post-type (try: wp mwm import-lessons --source=… --post-type=lessons)." );
			}
			$items = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $items ) || ! $items ) {
				break;
			}
			foreach ( $items as $item ) {
				$rows[] = $this->normalise( $this->from_rest_item( $item ) );
			}
			$total_pages = (int) wp_remote_retrieve_header( $res, 'x-wp-totalpages' );
			$page++;
		} while ( $page <= max( 1, $total_pages ) );
		return $rows;
	}

	private function from_rest_item( array $item ): array {
		$content = (string) ( $item['content']['rendered'] ?? '' );
		$meta    = (array) ( $item['meta'] ?? [] );
		$acf     = (array) ( $item['acf'] ?? [] );
		$flat    = array_merge( $meta, $acf );
		$level   = '';
		$topics  = [];
		$level_tax = $this->opts['level-tax'] ?? '';
		$topic_tax = $this->opts['topic-tax'] ?? 'topic';
		foreach ( (array) ( $item['_embedded']['wp:term'] ?? [] ) as $group ) {
			foreach ( (array) $group as $term ) {
				$tax  = (string) ( $term['taxonomy'] ?? '' );
				$name = html_entity_decode( (string) ( $term['name'] ?? '' ) );
				if ( ( $level_tax && $tax === $level_tax ) || ( ! $level_tax && $this->guess_level( $name ) ) ) {
					$level = $level ?: $name;
				} elseif ( $tax === $topic_tax || $this->guess_topic( $name ) ) {
					$topics[] = $name;
				}
			}
		}
		$topic = implode( '|', array_unique( $topics ) );
		$pdfs = $this->find_pdfs( $content, $flat );
		$featured = $item['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '';
		return [
			'id'        => (int) ( $item['id'] ?? 0 ),
			'title'     => html_entity_decode( (string) ( $item['title']['rendered'] ?? '' ) ),
			'url'       => (string) ( $item['link'] ?? '' ),
			'date'      => (string) ( $item['date'] ?? '' ),
			'content'   => $content,
			'youtube'   => $this->find_youtube( $content, $flat ),
			'level'     => $level,
			'topic'     => $topic,
			'worksheet' => $pdfs['worksheet'],
			'answers'   => $pdfs['answers'],
			'thumbnail' => $featured,
		];
	}

	private function from_wxr( string $file ): array {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_file( $file );
		if ( ! $xml ) {
			WP_CLI::error( 'Could not read that XML file as a WordPress export.' );
		}
		$ns    = $xml->getNamespaces( true );
		$type  = $this->opts['post-type'] ?? 'lesson';
		$items = [];
		$attachments = [];
		foreach ( $xml->channel->item as $item ) {
			$wp   = $item->children( $ns['wp'] );
			$ptype = (string) $wp->post_type;
			if ( $ptype === 'attachment' ) {
				$attachments[ (int) $wp->post_parent ][] = [ 'url' => (string) $wp->attachment_url, 'title' => (string) $item->title ];
				continue;
			}
			if ( $ptype !== $type || (string) $wp->status !== 'publish' ) {
				continue;
			}
			$meta = [];
			foreach ( $wp->postmeta as $pm ) {
				$meta[ (string) $pm->meta_key ] = (string) $pm->meta_value;
			}
			$content = (string) $item->children( $ns['content'] )->encoded;
			$level   = '';
			$topic   = '';
			foreach ( $item->category as $cat ) {
				$name = (string) $cat;
				$dom  = (string) $cat['domain'];
				if ( ( ! empty( $this->opts['level-tax'] ) && $dom === $this->opts['level-tax'] ) || ( empty( $this->opts['level-tax'] ) && $this->guess_level( $name ) ) ) {
					$level = $level ?: $name;
				} elseif ( ( ! empty( $this->opts['topic-tax'] ) && $dom === $this->opts['topic-tax'] ) || ( empty( $this->opts['topic-tax'] ) && $this->guess_topic( $name ) ) ) {
					$topic = $topic ?: $name;
				}
			}
			$items[] = [
				'id'      => (int) $wp->post_id,
				'title'   => (string) $item->title,
				'url'     => (string) $item->link,
				'date'    => (string) $wp->post_date,
				'content' => $content,
				'meta'    => $meta,
				'level'   => $level,
				'topic'   => $topic,
			];
		}
		$rows = [];
		foreach ( $items as $it ) {
			$flat = $it['meta'];
			foreach ( (array) ( $attachments[ $it['id'] ] ?? [] ) as $i => $att ) {
				$flat[ "attached_$i" ] = $att['url'];
			}
			$pdfs = $this->find_pdfs( $it['content'], $flat );
			$rows[] = $this->normalise( [
				'id'        => $it['id'],
				'title'     => $it['title'],
				'url'       => $it['url'],
				'date'      => $it['date'],
				'content'   => $it['content'],
				'youtube'   => $this->find_youtube( $it['content'], $flat ),
				'level'     => $it['level'],
				'topic'     => $it['topic'],
				'worksheet' => $pdfs['worksheet'],
				'answers'   => $pdfs['answers'],
			] );
		}
		return $rows;
	}

	/* ---------------------------------------------------------------
	 * Detection helpers
	 * ------------------------------------------------------------ */

	private function find_youtube( string $content, array $meta ): string {
		foreach ( $meta as $v ) {
			if ( is_string( $v ) && ( $id = mwm_youtube_id( $v ) ) ) {
				return $id;
			}
		}
		if ( preg_match_all( '~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|v/)|youtube\.html#|ytimg\.com/vi/)([A-Za-z0-9_-]{11})~', $content, $m ) ) {
			return $m[1][0];
		}
		return '';
	}

	/**
	 * The old site renders ACF fields into the page (lazy-loaded YouTube iframe, PDF links) but not into the API,
	 * so fetch the public page and read them from the HTML.
	 */
	private function scrape( array $r ): array {
		if ( ! $r['url'] || isset( $this->opts['no-scrape'] ) ) {
			return $r;
		}
		$res = wp_remote_get( $r['url'], [ 'timeout' => 45, 'headers' => [ 'User-Agent' => 'mwm-import' ] ] );
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
			WP_CLI::warning( "    could not read {$r['url']}" );
			return $r;
		}
		$html = wp_remote_retrieve_body( $res );
		// Only the article area: skip the site chrome (menus link to other lessons and PDFs).
		if ( preg_match( '~<main[^>]*>(.*)</main>~is', $html, $m ) || preg_match( '~<article[^>]*>(.*)</article>~is', $html, $m ) ) {
			$html = $m[1];
		}
		if ( ! $r['youtube'] ) {
			$r['youtube'] = $this->find_youtube( $html, [] );
		}
		if ( ! $r['worksheet'] && ! $r['answers'] ) {
			$pdfs = $this->find_pdfs( $html, [] );
			$r['worksheet'] = $pdfs['worksheet'];
			$r['answers']   = $pdfs['answers'];
		}
		return $r;
	}

	/**
	 * @return array{worksheet:string, answers:string}
	 */
	private function find_pdfs( string $content, array $meta ): array {
		$urls = [];
		foreach ( $meta as $v ) {
			if ( is_string( $v ) && preg_match( '~^https?://\S+\.pdf$~i', trim( $v ) ) ) {
				$urls[] = trim( $v );
			} elseif ( is_array( $v ) && ! empty( $v['url'] ) && str_ends_with( strtolower( $v['url'] ), '.pdf' ) ) {
				$urls[] = $v['url'];
			}
		}
		if ( preg_match_all( '~https?://[^\s"\'<>]+\.pdf~i', $content, $m ) ) {
			$urls = array_merge( $urls, $m[0] );
		}
		$urls = array_values( array_unique( array_map( 'html_entity_decode', $urls ) ) );
		$out  = [ 'worksheet' => '', 'answers' => '' ];
		foreach ( $urls as $u ) {
			$name = strtolower( basename( $u ) );
			if ( preg_match( '/answer|solution|mark|ms[-_.]/', $name ) ) {
				$out['answers'] = $out['answers'] ?: $u;
			} else {
				$out['worksheet'] = $out['worksheet'] ?: $u;
			}
		}
		if ( ! $out['worksheet'] && $out['answers'] && count( $urls ) === 1 ) {
			// A single PDF is more likely the worksheet.
			$out['worksheet'] = $out['answers'];
			$out['answers']   = '';
		}
		return $out;
	}

	private function guess_level( string $name ): bool {
		return (bool) preg_match( '/foundation|higher|a[- ]?level|gcse/i', $name );
	}

	private function guess_topic( string $name ): bool {
		return (bool) preg_match( '/number|algebra|ratio|proportion|geometry|measure|probability|statistic|pure|mechanic/i', $name );
	}

	/**
	 * Level from the old taxonomy name or the title. Titles like "Grade 8/9" map to GCSE Higher (grades 6–9)
	 * or Foundation (grades 1–5). Anything else falls back to --default-level (or stays unset).
	 */
	private function map_level( string $name, string $title = '' ): string {
		$hay = strtolower( $name . ' ' . $title );
		if ( preg_match( '/a[- ]?level/', $hay ) ) {
			return 'a-level';
		}
		if ( str_contains( $hay, 'higher' ) ) {
			return 'gcse-higher';
		}
		if ( str_contains( $hay, 'foundation' ) ) {
			return 'gcse-foundation';
		}
		if ( preg_match( '/grade\s*(\d)(?:\s*[\/\-]\s*(\d))?/', $hay, $m ) ) {
			$top = (int) ( $m[2] ?? $m[1] );
			return $top >= 6 ? 'gcse-higher' : 'gcse-foundation';
		}
		return '';
	}

	private function map_topic( string $name, string $title = '' ): int {
		$terms = get_terms( [ 'taxonomy' => 'mwm_topic', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return 0;
		}
		// Exact name matches first, preferring a subtopic over its parent topic.
		$names = array_filter( array_map( 'trim', explode( '|', strtolower( $name ) ) ) );
		$exact = [];
		foreach ( $terms as $t ) {
			if ( in_array( strtolower( wp_specialchars_decode( $t->name ) ), $names, true ) ) {
				$exact[] = $t;
			}
		}
		if ( $exact ) {
			usort( $exact, static fn( $a, $b ) => ( $b->parent ? 1 : 0 ) <=> ( $a->parent ? 1 : 0 ) );
			return (int) $exact[0]->term_id;
		}
		$hay = implode( ' ', $names ) . ' ' . strtolower( $title );
		$best = 0;
		$best_n = 0;
		foreach ( $terms as $t ) {
			$keywords = array_filter( array_map( 'trim', explode( ',', strtolower( (string) get_term_meta( $t->term_id, 'keywords', true ) ) ) ) );
			$keywords[] = strtolower( $t->name );
			$hits = 0;
			foreach ( $keywords as $kw ) {
				if ( $kw && str_contains( $hay, $kw ) ) {
					$hits += strlen( $kw ) > 4 ? 2 : 1;
				}
			}
			if ( $hits > $best_n ) {
				$best_n = $hits;
				$best   = (int) $t->term_id;
			}
		}
		return $best;
	}

	/**
	 * The old site's subtopic terms (e.g. "Percentages") become subtopics under the mapped topic, so the
	 * Browse page can filter by them. Returns the term to attach (the subtopic if one was made).
	 */
	private function ensure_subtopic( int $topic_id, string $old_names ): int {
		$term = get_term( $topic_id, 'mwm_topic' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $topic_id;
		}
		if ( $term->parent ) {
			return $topic_id; // already a subtopic
		}
		$generic = [ 'number', 'algebra', 'ratio', 'ratio & proportion', 'geometry', 'geometry & measures', 'probability', 'statistics', 'pure', 'mechanics', 'shape', 'data' ];
		foreach ( array_filter( array_map( 'trim', explode( '|', $old_names ) ) ) as $name ) {
			$lower = strtolower( $name );
			if ( in_array( $lower, $generic, true ) || $lower === strtolower( wp_specialchars_decode( $term->name ) ) ) {
				continue;
			}
			$existing = get_terms( [ 'taxonomy' => 'mwm_topic', 'hide_empty' => false, 'name' => $name, 'parent' => $topic_id ] );
			if ( ! is_wp_error( $existing ) && $existing ) {
				return (int) $existing[0]->term_id;
			}
			$new = wp_insert_term( $name, 'mwm_topic', [ 'parent' => $topic_id ] );
			if ( ! is_wp_error( $new ) ) {
				return (int) $new['term_id'];
			}
		}
		return $topic_id;
	}

	private function normalise( array $r ): array {
		return [
			'id'        => (int) ( $r['id'] ?? 0 ),
			'title'     => trim( (string) ( $r['title'] ?? '' ) ),
			'url'       => (string) ( $r['url'] ?? '' ),
			'date'      => (string) ( $r['date'] ?? '' ),
			'content'   => (string) ( $r['content'] ?? '' ),
			'youtube'   => mwm_youtube_id( (string) ( $r['youtube'] ?? '' ) ),
			'level'     => (string) ( $r['level'] ?? '' ),
			'topic'     => (string) ( $r['topic'] ?? '' ),
			'worksheet' => (string) ( $r['worksheet'] ?? '' ),
			'answers'   => (string) ( $r['answers'] ?? '' ),
			'thumbnail' => (string) ( $r['thumbnail'] ?? '' ),
			'seconds'   => (int) ( $r['seconds'] ?? 0 ),
		];
	}

	/* ---------------------------------------------------------------
	 * Writer
	 * ------------------------------------------------------------ */

	private function import_row( array $r ): void {
		$existing = get_posts( [ 'post_type' => 'mwm_lesson', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => 'source_id', 'meta_value' => $r['id'], 'no_found_rows' => true ] );
		if ( $existing && ! isset( $this->opts['update'] ) ) {
			$this->stats['skipped']++;
			WP_CLI::log( "  skip   #{$r['id']} {$r['title']} (already imported as #{$existing[0]})" );
			return;
		}
		$content = $this->clean_content( $r['content'] );
		$postarr = [
			'post_type'    => 'mwm_lesson',
			'post_status'  => 'publish',
			'post_title'   => $r['title'] ?: 'Untitled lesson',
			'post_content' => $content,
			'post_date'    => $r['date'] ?: current_time( 'mysql' ),
		];
		if ( $existing ) {
			$postarr['ID'] = (int) $existing[0];
			$id = (int) wp_update_post( $postarr, true );
			$this->stats['updated']++;
		} else {
			$id = (int) wp_insert_post( $postarr, true );
			$this->stats['created']++;
		}
		if ( is_wp_error( $id ) || ! $id ) {
			$this->stats['errors']++;
			WP_CLI::warning( "  failed #{$r['id']} {$r['title']}" );
			return;
		}
		update_post_meta( $id, 'source_id', $r['id'] );
		update_post_meta( $id, 'source_url', $r['url'] );
		wp_set_object_terms( $id, 'lesson', 'mwm_format' );
		if ( $r['youtube'] ) {
			update_post_meta( $id, 'youtube_id', $r['youtube'] );
			update_post_meta( $id, 'youtube_url', mwm_youtube_watch_url( $r['youtube'] ) );
			update_post_meta( $id, 'thumbnail_url', mwm_youtube_thumb( $r['youtube'] ) );
			if ( ! $r['seconds'] ) {
				$d = MWM_YouTube::video_details( $r['youtube'] );
				if ( ! is_wp_error( $d ) && $d['seconds'] ) {
					$r['seconds'] = $d['seconds'];
				}
			}
		}
		if ( $r['seconds'] ) {
			update_post_meta( $id, 'duration_seconds', $r['seconds'] );
		}
		$level     = $this->map_level( $r['level'], $r['title'] );
		$defaulted = false;
		if ( ! $level && ! empty( $this->opts['default-level'] ) ) {
			$level     = sanitize_key( (string) $this->opts['default-level'] );
			$defaulted = true;
		}
		if ( $level ) {
			wp_set_object_terms( $id, $level, 'mwm_level' );
		}
		if ( $defaulted ) {
			update_post_meta( $id, 'needs_level_review', 1 );
			$this->stats['level_defaulted'] = ( $this->stats['level_defaulted'] ?? 0 ) + 1;
		} else {
			delete_post_meta( $id, 'needs_level_review' );
		}
		$topic = $this->map_topic( $r['topic'], $r['title'] );
		if ( $topic ) {
			$topic = $this->ensure_subtopic( $topic, $r['topic'] );
			wp_set_object_terms( $id, $topic, 'mwm_topic' );
		}
		if ( ! isset( $this->opts['no-media'] ) ) {
			$atts = [];
			foreach ( [ 'worksheet', 'answers' ] as $k ) {
				if ( $r[ $k ] ) {
					$att = $this->sideload_pdf( $r[ $k ], $id, $r['title'] . ( $k === 'answers' ? ' — worked answers' : ' — worksheet' ) );
					if ( $att ) {
						$atts[ $k ] = $att;
						$this->stats['pdfs']++;
					}
				}
			}
			if ( ! empty( $atts['worksheet'] ) ) {
				mwm_upsert_worksheet( $id, $atts['worksheet'], $atts['answers'] ?? 0, $r['title'] );
			}
		}
		WP_CLI::log( sprintf( '  %s #%d → #%d %s%s%s%s', $existing ? 'update' : 'create', $r['id'], $id, $r['title'], $r['youtube'] ? '' : ' [no YouTube ID]', $level ? '' : ' [no level]', $topic ? '' : ' [no topic]' ) );
	}

	private function clean_content( string $html ): string {
		// Drop embeds and download links (they live in fields now) and Oxygen wrappers; keep paragraphs.
		$html = preg_replace( '~<iframe[^>]*>.*?</iframe>~is', '', $html );
		$html = preg_replace( '~<a[^>]+\.pdf[^>]*>.*?</a>~is', '', $html );
		$html = preg_replace( '~\[/?(?:oxygen|ct_[a-z_]+)[^\]]*\]~i', '', $html );
		$html = wp_kses_post( $html );
		$html = preg_replace( '~<p>\s*(?:&nbsp;)?\s*</p>~', '', $html );
		return trim( (string) $html );
	}

	private function sideload_pdf( string $url, int $parent, string $title ): int {
		$existing = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_mwm_source_url', 'meta_value' => $url, 'no_found_rows' => true ] );
		if ( $existing ) {
			return (int) $existing[0];
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			WP_CLI::warning( "    PDF not downloaded: $url (" . $tmp->get_error_message() . ')' );
			return 0;
		}
		$name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) ?: sanitize_title( $title ) . '.pdf';
		$id   = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp, 'type' => 'application/pdf' ], $parent, $title );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			WP_CLI::warning( "    PDF rejected: $url (" . $id->get_error_message() . ')' );
			return 0;
		}
		update_post_meta( $id, '_mwm_source_url', $url );
		return (int) $id;
	}
}
