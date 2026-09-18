<?php
/**
 * One-off past paper importer from the old site's /revision/{board}/ pages.
 *
 * The old site keeps one post per board + exam series, with the papers only in the rendered HTML
 * (ACF fields are not in its REST API), so this scrapes the three board pages. Every paper line is
 * "<Board> GCSE Mathematics Paper N: Calculator|Non-Calculator - Foundation|Higher" with links to
 * the question paper, the mark scheme and (sometimes) a revision worksheet PDF.
 *
 * Re-running is safe: papers are matched on board + series + tier + paper number, and PDFs are
 * matched on their original URL, so nothing is downloaded or created twice.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Past_Paper_Importer {

	private string $base;
	private bool $dry;
	private array $boards;
	private array $totals = [ 'papers' => 0, 'created' => 0, 'updated' => 0, 'pdfs' => 0, 'worksheets' => 0, 'skipped' => 0 ];

	public function __construct( array $opts ) {
		$this->base   = rtrim( (string) ( $opts['base'] ?? 'https://mathswithmelissa.co.uk' ), '/' );
		$this->dry    = isset( $opts['dry-run'] );
		$wanted       = array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $opts['board'] ?? '' ) ) ) );
		$this->boards = $wanted ? array_intersect_key( mwm_boards(), array_flip( $wanted ) ) : mwm_boards();
	}

	public function run(): void {
		foreach ( $this->boards as $slug => $name ) {
			WP_CLI::log( "» $name" );
			$rows = $this->scrape( $slug );
			if ( ! $rows ) {
				WP_CLI::warning( "  No papers found on {$this->base}/revision/$slug/" );
				continue;
			}
			if ( $this->dry ) {
				WP_CLI\Utils\format_items( 'table', array_map( static fn( $r ) => [
					'series'    => $r['season'] . ' ' . $r['year'],
					'tier'      => ucfirst( $r['tier'] ),
					'paper'     => 'Paper ' . $r['paper'] . ( $r['calculator'] ? ' (calc)' : ' (non-calc)' ),
					'qp'        => basename( $r['qp'] ),
					'ms'        => $r['ms'] ? basename( $r['ms'] ) : '—',
					'worksheet' => $r['ws'] ? basename( $r['ws'] ) : '—',
				], $rows ), [ 'series', 'tier', 'paper', 'qp', 'ms', 'worksheet' ] );
				$this->totals['papers'] += count( $rows );
				continue;
			}
			foreach ( $rows as $r ) {
				$this->upsert( $slug, $r );
			}
		}
		$t = $this->totals;
		if ( $this->dry ) {
			WP_CLI::success( sprintf( 'Dry run: %d papers would be imported.', $t['papers'] ) );
			return;
		}
		WP_CLI::success( sprintf( '%d papers imported (%d new, %d updated), %d PDFs downloaded, %d revision worksheets linked, %d lines skipped.', $t['papers'], $t['created'], $t['updated'], $t['pdfs'], $t['worksheets'], $t['skipped'] ) );
	}

	/**
	 * Fetch a board page and turn it into normalised paper rows.
	 */
	private function scrape( string $board ): array {
		$url = "{$this->base}/revision/$board/";
		$res = wp_remote_get( $url, [ 'timeout' => 60, 'headers' => [ 'User-Agent' => 'mwm-import' ] ] );
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
			WP_CLI::warning( "  Could not fetch $url" );
			return [];
		}
		$html = wp_remote_retrieve_body( $res );
		$rows = [];
		$seen = [];
		// Each series is introduced by a heading like "Edexcel GCSE Maths Past Papers June 2024"; papers follow it.
		$chunks = preg_split( '~(?=<h[23][^>]*>[^<]*Past Papers[^<]*</h[23]>)~i', $html );
		foreach ( $chunks as $chunk ) {
			if ( ! preg_match( '~<h[23][^>]*>[^<]*Past Papers\s+(June|November)\s+(20\d\d)[^<]*</h[23]>~i', $chunk, $h ) ) {
				continue;
			}
			$season = ucfirst( strtolower( $h[1] ) );
			$year   = (int) $h[2];
			preg_match_all( '~<li class="revision-past-paper-item">\s*<p[^>]*>(.*?)</p>\s*<div class="revision-past-paper-links">(.*?)</div>~is', $chunk, $items, PREG_SET_ORDER );
			foreach ( $items as $item ) {
				$title = html_entity_decode( trim( wp_strip_all_tags( $item[1] ) ), ENT_QUOTES, 'UTF-8' );
				if ( ! preg_match( '~Paper\s*(\d+)\s*:?\s*(Non-?\s*Calculator|Calculator)\s*-?\s*(Foundation|Higher)~i', $title, $m ) ) {
					WP_CLI::warning( "  Skipped (couldn’t read): $title" );
					$this->totals['skipped']++;
					continue;
				}
				$links = [];
				preg_match_all( '~<a\s+href="([^"]+)"[^>]*>\s*(Past Paper|Mark Scheme|Worksheet)\s*</a>~i', $item[2], $ls, PREG_SET_ORDER );
				foreach ( $ls as $l ) {
					$links[ strtolower( $l[2] ) ] = html_entity_decode( $l[1] );
				}
				if ( empty( $links['past paper'] ) ) {
					WP_CLI::warning( "  Skipped (no question paper): $season $year · $title" );
					$this->totals['skipped']++;
					continue;
				}
				// Which paper is non-calculator is fixed per board (Edexcel/AQA: paper 1, OCR: paper 2); the old site mislabels a few.
				$labelled_calc = stripos( $m[2], 'non' ) === false;
				$rule_calc     = (int) $m[1] !== ( $board === 'ocr' ? 2 : 1 );
				if ( $labelled_calc !== $rule_calc ) {
					WP_CLI::log( "  Note: $season $year · $title is labelled " . ( $labelled_calc ? 'calculator' : 'non-calculator' ) . ' on the old site; importing as ' . ( $rule_calc ? 'calculator' : 'non-calculator' ) . " (the {$board} rule)." );
				}
				$row = [
					'season'     => $season,
					'year'       => $year,
					'paper'      => (int) $m[1],
					'calculator' => $rule_calc,
					'tier'       => strtolower( $m[3] ),
					'qp'         => $links['past paper'],
					'ms'         => $links['mark scheme'] ?? '',
					'ws'         => $links['worksheet'] ?? '',
					'title'      => $title,
				];
				$key = "$season|$year|{$row['tier']}|{$row['paper']}";
				if ( isset( $seen[ $key ] ) ) {
					WP_CLI::log( "  Duplicate line for $season $year {$row['tier']} paper {$row['paper']} — keeping the first." );
					continue;
				}
				$seen[ $key ] = true;
				$rows[]       = $row;
			}
		}
		return $rows;
	}

	private function upsert( string $board, array $r ): void {
		$key      = "$board|{$r['season']}|{$r['year']}|{$r['tier']}|{$r['paper']}";
		$existing = get_posts( [ 'post_type' => 'mwm_past_paper', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => 'source_key', 'meta_value' => $key, 'no_found_rows' => true ] );
		$title    = "{$r['season']} {$r['year']} · Paper {$r['paper']} (" . ( $r['calculator'] ? 'calculator' : 'non-calculator' ) . ') · ' . ucfirst( $r['tier'] );
		$postarr  = [ 'post_type' => 'mwm_past_paper', 'post_status' => 'publish', 'post_title' => $title ];
		if ( $existing ) {
			$id = (int) $existing[0];
			$postarr['ID'] = $id;
			wp_update_post( $postarr );
			$this->totals['updated']++;
		} else {
			$id = (int) wp_insert_post( $postarr );
			if ( ! $id ) {
				WP_CLI::warning( "  Could not create $title" );
				return;
			}
			$this->totals['created']++;
		}
		$this->totals['papers']++;
		WP_CLI::log( "  " . mwm_board_name( $board ) . " · $title" );

		$codes = [ 'edexcel' => [ '1MA1', 80, 90 ], 'aqa' => [ '8300', 80, 90 ], 'ocr' => [ 'J560', 100, 90 ] ];
		[ $code, $marks, $mins ] = $codes[ $board ] ?? [ '1MA1', 80, 90 ];
		wp_set_object_terms( $id, $board, 'mwm_board' );
		update_post_meta( $id, 'source_key', $key );
		update_post_meta( $id, 'source_url', "{$this->base}/revision/$board/" );
		update_post_meta( $id, 'tier', $r['tier'] );
		update_post_meta( $id, 'series_season', $r['season'] );
		update_post_meta( $id, 'series_year', $r['year'] );
		update_post_meta( $id, 'paper_number', $r['paper'] );
		update_post_meta( $id, 'calculator', $r['calculator'] ? 1 : 0 );
		update_post_meta( $id, 'qualification_code', $code );
		update_post_meta( $id, 'marks', $marks );
		update_post_meta( $id, 'duration_minutes', $mins );

		$label = mwm_board_name( $board ) . " {$r['season']} {$r['year']} Paper {$r['paper']} " . ucfirst( $r['tier'] );
		$qp    = $this->sideload_pdf( $r['qp'], $id, "$label question paper" );
		if ( $qp ) {
			update_post_meta( $id, 'question_paper', $qp );
		}
		if ( $r['ms'] ) {
			$ms = $this->sideload_pdf( $r['ms'], $id, "$label mark scheme" );
			if ( $ms ) {
				update_post_meta( $id, 'mark_scheme', $ms );
			}
		}
		if ( $r['ws'] ) {
			$ws_id = $this->upsert_worksheet( $r['ws'], "$label revision worksheet", $r['tier'] );
			if ( $ws_id ) {
				update_post_meta( $id, 'worksheets', [ $ws_id ] );
				$this->totals['worksheets']++;
			}
		}
	}

	/**
	 * A revision worksheet PDF becomes a standalone worksheet page (matched on its source URL on re-runs).
	 */
	private function upsert_worksheet( string $url, string $title, string $tier ): int {
		$existing = get_posts( [ 'post_type' => 'mwm_worksheet', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => 'source_url', 'meta_value' => $url, 'no_found_rows' => true ] );
		$pdf      = $this->sideload_pdf( $url, (int) ( $existing[0] ?? 0 ), $title );
		if ( ! $pdf ) {
			return 0;
		}
		if ( $existing ) {
			$ws_id = (int) $existing[0];
			update_post_meta( $ws_id, 'pdf', $pdf );
		} else {
			$ws_id = mwm_upsert_worksheet( 0, $pdf, 0, $title );
			if ( ! $ws_id ) {
				return 0;
			}
			update_post_meta( $ws_id, 'source_url', $url );
		}
		wp_set_object_terms( $ws_id, $tier === 'foundation' ? 'gcse-foundation' : 'gcse-higher', 'mwm_level' );
		return $ws_id;
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
		$this->totals['pdfs']++;
		return (int) $id;
	}
}
