<?php
/**
 * Shared helper functions used by the plugin, the theme blocks and the Studio.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Taxonomy vocab
 * ---------------------------------------------------------------------- */

/**
 * Levels in display order.
 *
 * @return array<string, array{name:string, short:string, blurb:string, number:string}>
 */
function mwm_levels(): array {
	return [
		'gcse-foundation' => [ 'name' => 'GCSE Foundation', 'short' => 'Foundation', 'number' => '01', 'blurb' => 'Build your skills, step by step.' ],
		'gcse-higher'     => [ 'name' => 'GCSE Higher',     'short' => 'Higher',     'number' => '02', 'blurb' => 'Stretch your understanding further.' ],
		'a-level'         => [ 'name' => 'A-level',         'short' => 'A-level',    'number' => '03', 'blurb' => 'Take your maths even further.' ],
	];
}

/**
 * Home page picks chosen in the Studio: hero lesson (+ optional short title) and the featured grid.
 * Empty values mean "let the block decide" (its own field, else the newest lessons).
 */
function mwm_home_settings(): array {
	$o = (array) get_option( 'mwm_home', [] );
	return [
		'hero_lesson' => (int) ( $o['hero_lesson'] ?? 0 ),
		'hero_title'  => sanitize_text_field( (string) ( $o['hero_title'] ?? '' ) ),
		'featured'    => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $o['featured'] ?? [] ) ) ) ) ),
	];
}

function mwm_level_name( string $slug ): string {
	return mwm_levels()[ $slug ]['name'] ?? '';
}

/**
 * Clean a level or list of levels from a request: known slugs only, no repeats, in the site's level order
 * (Foundation, Higher, A-level). Accepts a slug, an array of slugs or a comma-separated string.
 */
function mwm_clean_levels( $levels ): array {
	if ( is_string( $levels ) ) {
		$levels = explode( ',', $levels );
	}
	$wanted = array_map( static fn( $l ) => sanitize_key( (string) $l ), (array) $levels );
	return array_values( array_intersect( array_keys( mwm_levels() ), $wanted ) );
}

/**
 * The levels a post is tagged with, in the site's level order. A lesson can suit more than one.
 */
function mwm_post_levels( int $post_id ): array {
	$terms = get_the_terms( $post_id, 'mwm_level' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return [];
	}
	return mwm_clean_levels( wp_list_pluck( $terms, 'slug' ) );
}

/**
 * One readable label for a set of levels: "GCSE Higher", "GCSE Foundation & Higher",
 * "GCSE Higher & A-level", "GCSE Foundation, Higher & A-level".
 */
function mwm_levels_label( array $slugs ): string {
	$names = [];
	$prev  = '';
	foreach ( mwm_clean_levels( $slugs ) as $slug ) {
		$name    = mwm_level_name( $slug );
		$is_gcse = str_starts_with( $slug, 'gcse-' );
		// "GCSE Foundation & Higher" rather than "GCSE Foundation & GCSE Higher".
		$names[] = $is_gcse && str_starts_with( $prev, 'gcse-' ) ? preg_replace( '/^GCSE /', '', $name ) : $name;
		$prev    = $slug;
	}
	if ( count( $names ) < 2 ) {
		return $names[0] ?? '';
	}
	$last = array_pop( $names );
	return implode( ', ', $names ) . ' & ' . $last;
}

/**
 * Set a lesson's levels from the Studio: replaces what was there, keeps its worksheet page in step,
 * clears the "level needs checking" flag and the cached home page topic counts. Returns the levels saved.
 */
function mwm_set_lesson_levels( int $lesson_id, $levels ): array {
	$levels = mwm_clean_levels( $levels );
	if ( ! $levels ) {
		return [];
	}
	$set = wp_set_object_terms( $lesson_id, $levels, 'mwm_level' );
	if ( is_wp_error( $set ) ) {
		return [];
	}
	$ws = (int) get_post_meta( $lesson_id, 'worksheet_post', true );
	if ( $ws && get_post_type( $ws ) === 'mwm_worksheet' ) {
		wp_set_object_terms( $ws, $levels, 'mwm_level' );
	}
	delete_post_meta( $lesson_id, 'needs_level_review' ); // Kym has chosen the levels in the Studio.
	delete_transient( 'mwm_topic_browser' );
	mwm_flush_pathway_cache();
	return $levels;
}

function mwm_boards(): array {
	return [ 'edexcel' => 'Edexcel', 'aqa' => 'AQA', 'ocr' => 'OCR' ];
}

function mwm_board_name( string $slug ): string {
	return mwm_boards()[ $slug ] ?? '';
}

function mwm_qualification_codes(): array {
	return [
		'gcse-foundation' => [ 'edexcel' => 'GCSE Mathematics 1MA1', 'aqa' => 'GCSE Mathematics 8300', 'ocr' => 'GCSE Mathematics J560' ],
		'gcse-higher'     => [ 'edexcel' => 'GCSE Mathematics 1MA1', 'aqa' => 'GCSE Mathematics 8300', 'ocr' => 'GCSE Mathematics J560' ],
		'a-level'         => [ 'edexcel' => 'A-level Mathematics 9MA0', 'aqa' => 'A-level Mathematics 7357', 'ocr' => 'A-level Mathematics H240' ],
	];
}

/**
 * Top-level topics as seeded. Icon keys map to SVGs in the theme.
 */
function mwm_default_topics(): array {
	return [
		'number'              => [ 'name' => 'Number',              'icon' => 'number',      'levels' => [ 'gcse-foundation', 'gcse-higher' ], 'keywords' => 'fraction, decimal, percent, rounding, round, place value, negative number, prime, factor, multiple, hcf, lcm, standard form, surd, indices, index, power, bounds, error interval, estimat, bidmas, bodmas, order of operations, significant figure, recurring, ratio to percent, money, budget, wages, tax, interest, number' ],
		'algebra'             => [ 'name' => 'Algebra',             'icon' => 'algebra',     'levels' => [ 'gcse-foundation', 'gcse-higher' ], 'keywords' => 'equation, expand, factoris, simultaneous, sequence, nth term, inequalit, quadratic, graph, formula, substitut, expression, bracket, solve for, coordinate, gradient, straight line, function, iteration, proof, rearrang, completing the square, algebra' ],
		'ratio-proportion'    => [ 'name' => 'Ratio & Proportion',  'icon' => 'ratio',       'levels' => [ 'gcse-foundation', 'gcse-higher' ], 'keywords' => 'ratio, proportion, scale, convert, conversion, exchange rate, speed, density, pressure, best buy, share, sharing, recipe, map scale, direct, inverse, unit' ],
		'geometry-measures'   => [ 'name' => 'Geometry & Measures', 'icon' => 'geometry',    'levels' => [ 'gcse-foundation', 'gcse-higher' ], 'keywords' => 'area, perimeter, circumference, volume, angle, pythagoras, trigonometry, trig, sine, cosine, tan, vector, circle, triangle, shape, bearing, transformation, reflection, rotation, enlargement, translation, symmetry, polygon, cuboid, cylinder, prism, sphere, cone, surface area, loci, construct, 3d, geometry, measure' ],
		'probability'         => [ 'name' => 'Probability',         'icon' => 'probability', 'levels' => [ 'gcse-foundation', 'gcse-higher' ], 'keywords' => 'probability, tree diagram, venn, dice, coin, chance, likely, outcome, sample space, relative frequency, expected' ],
		'statistics'          => [ 'name' => 'Statistics',          'icon' => 'statistics',  'levels' => [ 'gcse-foundation', 'gcse-higher', 'a-level' ], 'keywords' => 'mean, median, mode, range, average, histogram, frequency, bar chart, pie chart, scatter, data, box plot, cumulative, stem and leaf, sampling, correlation, statistic' ],
		'pure'                => [ 'name' => 'Pure',                'icon' => 'pure',        'levels' => [ 'a-level' ], 'keywords' => 'differentiat, integrat, calculus, logarithm, binomial, polynomial, radian, exponential, trigonometric identit, partial fraction, parametric, series, vectors 3d' ],
		'mechanics'           => [ 'name' => 'Mechanics',           'icon' => 'mechanics',   'levels' => [ 'a-level' ], 'keywords' => 'mechanics, force, newton, suvat, projectile, moment, friction, kinematic, velocity, acceleration, momentum' ],
	];
}

/**
 * Topic terms (top level) with their metadata, ordered by term_order meta then name.
 *
 * @param string|null $level Restrict to topics that apply to this level.
 * @return array<int, array{id:int, slug:string, name:string, icon:string, levels:array, level_label:string}>
 */
function mwm_topics( ?string $level = null ): array {
	$terms = get_terms( [ 'taxonomy' => 'mwm_topic', 'hide_empty' => false, 'parent' => 0 ] );
	if ( is_wp_error( $terms ) ) {
		return [];
	}
	$out = [];
	foreach ( $terms as $t ) {
		$levels = (array) get_term_meta( $t->term_id, 'levels', true );
		$levels = array_values( array_filter( $levels ) );
		if ( $level && $levels && ! in_array( $level, $levels, true ) ) {
			continue;
		}
		$out[] = [
			'id'          => $t->term_id,
			'slug'        => $t->slug,
			'name'        => wp_specialchars_decode( $t->name ),
			'icon'        => (string) get_term_meta( $t->term_id, 'icon', true ),
			'order'       => (int) get_term_meta( $t->term_id, 'order', true ),
			'levels'      => $levels,
			'level_label' => mwm_topic_level_label( $levels ),
		];
	}
	usort( $out, static fn( $a, $b ) => [ $a['order'], $a['name'] ] <=> [ $b['order'], $b['name'] ] );
	return $out;
}

function mwm_topic_level_label( array $levels ): string {
	$gcse  = array_intersect( $levels, [ 'gcse-foundation', 'gcse-higher' ] );
	$parts = [];
	if ( count( $gcse ) === 2 ) {
		$parts[] = 'GCSE Foundation & Higher';
	} elseif ( $gcse ) {
		$parts[] = mwm_level_name( reset( $gcse ) );
	}
	if ( in_array( 'a-level', $levels, true ) ) {
		$parts[] = 'A-level';
	}
	return implode( ' & ', $parts );
}

/**
 * Subtopics (child terms) of a topic, ordered.
 */
function mwm_subtopics( int $topic_id ): array {
	$terms = get_terms( [ 'taxonomy' => 'mwm_topic', 'hide_empty' => false, 'parent' => $topic_id ] );
	if ( is_wp_error( $terms ) ) {
		return [];
	}
	$out = array_map( static fn( $t ) => [ 'id' => $t->term_id, 'slug' => $t->slug, 'name'        => wp_specialchars_decode( $t->name ), 'order' => (int) get_term_meta( $t->term_id, 'order', true ) ], $terms );
	usort( $out, static fn( $a, $b ) => [ $a['order'], $a['name'] ] <=> [ $b['order'], $b['name'] ] );
	return $out;
}

/* -------------------------------------------------------------------------
 * Formatting
 * ---------------------------------------------------------------------- */

/**
 * "9 min" for lessons, "0:45" for shorts.
 */
function mwm_duration_label( int $seconds, string $format = 'lesson' ): string {
	if ( $seconds <= 0 ) {
		return '';
	}
	if ( $format === 'short' || $seconds < 60 ) {
		return sprintf( '%d:%02d', intdiv( $seconds, 60 ), $seconds % 60 );
	}
	return max( 1, (int) round( $seconds / 60 ) ) . ' min';
}

/**
 * "11:24" style label used in the Studio preview.
 */
function mwm_duration_clock( int $seconds ): string {
	if ( $seconds >= 3600 ) {
		return sprintf( '%d:%02d:%02d', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ), $seconds % 60 );
	}
	return sprintf( '%d:%02d', intdiv( $seconds, 60 ), $seconds % 60 );
}

/**
 * Parse an ISO-8601 duration (PT9M12S) into seconds.
 */
function mwm_iso8601_to_seconds( string $iso ): int {
	if ( ! preg_match( '/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m ) ) {
		return 0;
	}
	return (int) ( $m[1] ?? 0 ) * 86400 + (int) ( $m[2] ?? 0 ) * 3600 + (int) ( $m[3] ?? 0 ) * 60 + (int) ( $m[4] ?? 0 );
}

/**
 * Extract a YouTube video ID from any common URL shape (or return the ID if given one).
 */
function mwm_youtube_id( string $url ): string {
	$url = trim( $url );
	if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $url ) ) {
		return $url;
	}
	$patterns = [
		'~youtu\.be/([A-Za-z0-9_-]{11})~',
		'~youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)([A-Za-z0-9_-]{11})~',
	];
	foreach ( $patterns as $p ) {
		if ( preg_match( $p, $url, $m ) ) {
			return $m[1];
		}
	}
	return '';
}

function mwm_youtube_thumb( string $video_id, string $size = 'maxresdefault' ): string {
	return $video_id ? "https://i.ytimg.com/vi/{$video_id}/{$size}.jpg" : '';
}

function mwm_youtube_watch_url( string $video_id ): string {
	return $video_id ? 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id ) : mwm_youtube_channel_url();
}

/**
 * Human file size in the prototype's style: "240 KB", "1.1 MB".
 */
function mwm_file_size_label( int $bytes ): string {
	if ( $bytes <= 0 ) {
		return '';
	}
	if ( $bytes >= 1048576 ) {
		return number_format( $bytes / 1048576, 1 ) . ' MB';
	}
	return max( 1, (int) round( $bytes / 1024 ) ) . ' KB';
}

/**
 * UK date formats: "Thu 13 May", "Thu 13 May 2027", "1 Sept 2026", "12 Aug 2026".
 */
function mwm_format_date( string $ymd, string $style = 'day-month' ): string {
	$ts = strtotime( $ymd );
	if ( ! $ts ) {
		return '';
	}
	$months_short = [ 1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'June', 'July', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec' ];
	$m   = (int) gmdate( 'n', $ts );
	$d   = (int) gmdate( 'j', $ts );
	$y   = gmdate( 'Y', $ts );
	$dow = gmdate( 'D', $ts );
	switch ( $style ) {
		case 'dow-day-month':
			return "$dow $d " . gmdate( 'F', $ts );
		case 'dow-day-month-year':
			return "$dow $d " . gmdate( 'F', $ts ) . " $y";
		case 'short':
			return "$d {$months_short[$m]} $y";
		case 'day-month-year':
			return "$d " . gmdate( 'F', $ts ) . " $y";
		case 'week':
			return "w/c $d " . gmdate( 'F', $ts );
		case 'month-year':
			return gmdate( 'F Y', $ts );
		default:
			return "$d " . gmdate( 'F', $ts );
	}
}

/**
 * "added 2 days ago" style relative label.
 */
function mwm_relative_label( string $mysql_date ): string {
	$diff = time() - strtotime( $mysql_date );
	if ( $diff < 3600 ) {
		return 'just now';
	}
	if ( $diff < 86400 ) {
		return 'today';
	}
	$days = (int) floor( $diff / 86400 );
	if ( $days === 1 ) {
		return '1 day ago';
	}
	if ( $days < 7 ) {
		return "$days days ago";
	}
	$weeks = (int) floor( $days / 7 );
	if ( $weeks < 5 ) {
		return $weeks === 1 ? '1 week ago' : "$weeks weeks ago";
	}
	$months = (int) floor( $days / 30 );
	return $months <= 1 ? '1 month ago' : "$months months ago";
}

/* -------------------------------------------------------------------------
 * Lessons
 * ---------------------------------------------------------------------- */

/**
 * Post title as plain text (no texturized entities), for JSON and esc_html().
 */
function mwm_title( int $post_id ): string {
	return html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

function mwm_get_term_slug( int $post_id, string $taxonomy ): string {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	return $terms[0]->slug;
}

function mwm_get_term_name( int $post_id, string $taxonomy ): string {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	return wp_specialchars_decode( $terms[0]->name );
}

/**
 * The lesson's top-level topic term and its subtopic (if the lesson is tagged with a child term).
 *
 * @return array{topic:?WP_Term, subtopic:?WP_Term}
 */
function mwm_lesson_topic_terms( int $post_id ): array {
	$terms = get_the_terms( $post_id, 'mwm_topic' );
	$topic = null;
	$sub   = null;
	if ( $terms && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $t ) {
			if ( $t->parent ) {
				$sub    = $t;
				$parent = get_term( $t->parent, 'mwm_topic' );
				if ( $parent && ! is_wp_error( $parent ) ) {
					$topic = $parent;
				}
			} elseif ( ! $topic ) {
				$topic = $t;
			}
		}
	}
	return [ 'topic' => $topic, 'subtopic' => $sub ];
}

/**
 * Quiz post attached to a lesson, or null.
 */
function mwm_lesson_quiz( int $lesson_id ): ?WP_Post {
	$raw     = get_post_meta( $lesson_id, 'quiz_post', true );
	$indexed = (int) $raw;
	if ( $raw !== '' && ! $indexed ) {
		return null; // Indexed as "no quiz".
	}
	if ( $indexed ) {
		$q = get_post( $indexed );
		if ( $q && $q->post_type === 'mwm_quiz' && $q->post_status === 'publish' && (int) get_post_meta( $q->ID, 'lesson', true ) === $lesson_id ) {
			return $q;
		}
	}
	$id = mwm_reindex_lesson_quiz( $lesson_id );
	return $id ? get_post( $id ) : null;
}

/**
 * Recompute the lesson's `quiz_post` index (used by the "Has quiz" filter so it can page on the server).
 */
function mwm_reindex_lesson_quiz( int $lesson_id ): int {
	$q = get_posts( [
		'post_type'      => 'mwm_quiz',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'meta_key'       => 'lesson',
		'meta_value'     => $lesson_id,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	] );
	if ( $q ) {
		update_post_meta( $lesson_id, 'quiz_post', (int) $q[0] );
		return (int) $q[0];
	}
	update_post_meta( $lesson_id, 'quiz_post', 0 );
	return 0;
}

/* -------------------------------------------------------------------------
 * Worksheets
 * ---------------------------------------------------------------------- */

/**
 * The worksheet post attached to a lesson, or null. Cached in the lesson's `worksheet_post` meta.
 */
function mwm_lesson_worksheet( int $lesson_id ): ?WP_Post {
	$raw = get_post_meta( $lesson_id, 'worksheet_post', true );
	$id  = (int) $raw;
	if ( $id ) {
		$w = get_post( $id );
		if ( $w && $w->post_type === 'mwm_worksheet' && $w->post_status === 'publish' ) {
			return $w;
		}
	} elseif ( $raw !== '' ) {
		return null; // Indexed as "no worksheet" — no lookup needed (keeps listing pages to a handful of queries).
	}
	$found = get_posts( [
		'post_type'      => 'mwm_worksheet',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_key'       => 'lesson',
		'meta_value'     => $lesson_id,
	] );
	if ( $found ) {
		update_post_meta( $lesson_id, 'worksheet_post', $found[0] );
		return get_post( $found[0] );
	}
	update_post_meta( $lesson_id, 'worksheet_post', 0 );
	return null;
}

/**
 * Normalised worksheet data. With $with_lesson the linked lesson card is included.
 */
function mwm_worksheet_data( $post, bool $with_lesson = true ): ?array {
	$post = get_post( $post );
	if ( ! $post || $post->post_type !== 'mwm_worksheet' ) {
		return null;
	}
	$id        = $post->ID;
	$pdf       = mwm_attachment_info( get_post_meta( $id, 'pdf', true ) );
	$answers   = mwm_attachment_info( get_post_meta( $id, 'answers', true ) );
	$lesson_id = (int) get_post_meta( $id, 'lesson', true );
	$levels    = mwm_post_levels( $id );
	$level     = $levels[0] ?? '';
	$tt        = mwm_lesson_topic_terms( $id );
	$thumb     = has_post_thumbnail( $id ) ? ( get_the_post_thumbnail_url( $id, 'large' ) ?: '' ) : '';
	$lesson    = null;
	if ( $with_lesson && $lesson_id ) {
		$lesson = mwm_lesson_card( $lesson_id );
		if ( $lesson && ! $thumb ) {
			$thumb = $lesson['thumb'];
		}
	} elseif ( $lesson_id && ! $thumb ) {
		$thumb = (string) get_post_meta( $lesson_id, 'thumbnail_url', true );
		if ( has_post_thumbnail( $lesson_id ) ) {
			$thumb = get_the_post_thumbnail_url( $lesson_id, 'large' ) ?: $thumb;
		}
	}
	return [
		'id'            => $id,
		'title'         => mwm_title( $id ),
		'url'           => get_permalink( $id ),
		'slug'          => $post->post_name,
		'pdf'           => $pdf,
		'answers'       => $answers,
		'has_pdf'       => (bool) $pdf,
		'has_answers'   => (bool) $answers,
		'lesson_id'     => $lesson_id,
		'lesson'        => $lesson,
		'level'         => mwm_levels_label( $levels ),
		'level_slug'    => $level, // The first of `levels`; use `levels` wherever a worksheet can suit more than one.
		'levels'        => $levels,
		'level_style'   => in_array( 'gcse-higher', $levels, true ) ? 'higher' : 'tint',
		'topic'         => $tt['topic'] ? wp_specialchars_decode( $tt['topic']->name ) : '',
		'topic_slug'    => $tt['topic'] ? $tt['topic']->slug : '',
		'subtopic'      => $tt['subtopic'] ? wp_specialchars_decode( $tt['subtopic']->name ) : '',
		'subtopic_slug' => $tt['subtopic'] ? $tt['subtopic']->slug : '',
		'thumb'         => $thumb,
		'excerpt'       => wp_strip_all_tags( get_post_field( 'post_content', $id ) ),
		'published'     => get_the_date( 'Y-m-d', $id ),
		'published_label' => mwm_format_date( get_the_date( 'Y-m-d', $id ), 'short' ),
	];
}

/**
 * Query worksheets (level/topic/subtopic/search) as data arrays.
 */
function mwm_query_worksheets( array $args = [] ): array {
	return mwm_query_worksheets_paged( $args )['items'];
}

/**
 * Paged worksheet query, same shape as mwm_query_lessons_paged().
 */
function mwm_query_worksheets_paged( array $args = [] ): array {
	$a   = array_merge( [ 'level' => '', 'topic' => '', 'subtopic' => '', 'search' => '', 'per_page' => -1, 'page' => 1, 'exclude' => [], 'include' => [] ], $args );
	$tax = [];
	if ( $a['level'] ) {
		$tax[] = [ 'taxonomy' => 'mwm_level', 'field' => 'slug', 'terms' => (array) $a['level'] ];
	}
	if ( $a['subtopic'] ) {
		$tax[] = [ 'taxonomy' => 'mwm_topic', 'field' => 'slug', 'terms' => (array) $a['subtopic'], 'include_children' => false ];
	} elseif ( $a['topic'] ) {
		$tax[] = [ 'taxonomy' => 'mwm_topic', 'field' => 'slug', 'terms' => (array) $a['topic'], 'include_children' => true ];
	}
	$paged = (int) $a['per_page'] > 0;
	$q     = [ 'post_type' => 'mwm_worksheet', 'post_status' => 'publish', 'posts_per_page' => $a['per_page'], 'paged' => max( 1, (int) $a['page'] ), 'no_found_rows' => ! $paged, 'post__not_in' => (array) $a['exclude'] ];
	if ( $a['include'] ) {
		$q['post__in'] = (array) $a['include'];
		$q['orderby']  = 'post__in';
	}
	if ( $tax ) {
		$q['tax_query'] = array_merge( [ 'relation' => 'AND' ], $tax );
	}
	if ( $a['search'] ) {
		$q['s'] = $a['search'];
	}
	$query = new WP_Query( $q );
	$out   = [];
	// Load the lessons and PDFs behind this page of worksheets up front (two queries instead of several per card).
	$related = [];
	foreach ( $query->posts as $p ) {
		foreach ( [ 'lesson', 'pdf', 'answers' ] as $key ) {
			$rid = (int) get_post_meta( $p->ID, $key, true );
			if ( $rid ) {
				$related[] = $rid;
			}
		}
	}
	if ( $related ) {
		_prime_post_caches( array_unique( $related ), true, true );
	}
	foreach ( $query->posts as $p ) {
		$d = mwm_worksheet_data( $p, false );
		if ( $d ) {
			$out[] = $d;
		}
	}
	return [
		'items'    => $out,
		'total'    => $paged ? (int) $query->found_posts : count( $out ),
		'pages'    => $paged ? (int) $query->max_num_pages : 1,
		'page'     => $paged ? max( 1, (int) $a['page'] ) : 1,
		'per_page' => (int) $a['per_page'],
	];
}

/**
 * Create or update the worksheet post for a lesson from its PDFs. Pass 0 for $pdf_id to detach/unpublish.
 *
 * @return int Worksheet post ID (0 when removed).
 */
function mwm_upsert_worksheet( int $lesson_id, int $pdf_id, int $answers_id = 0, string $title = '' ): int {
	$existing = mwm_lesson_worksheet( $lesson_id );
	if ( ! $pdf_id ) {
		if ( $existing ) {
			wp_trash_post( $existing->ID );
			delete_post_meta( $lesson_id, 'worksheet_post' );
		}
		return 0;
	}
	$title = $title ?: ( $lesson_id ? mwm_title( $lesson_id ) : get_the_title( $pdf_id ) );
	if ( $existing ) {
		$ws_id = $existing->ID;
		if ( $title && get_post_field( 'post_title', $ws_id ) !== $title ) {
			wp_update_post( [ 'ID' => $ws_id, 'post_title' => $title ] );
		}
	} else {
		$ws_id = (int) wp_insert_post( [
			'post_type'   => 'mwm_worksheet',
			'post_status' => 'publish',
			'post_title'  => $title ?: 'Worksheet',
			'post_name'   => sanitize_title( $title ) . ( preg_match( '/worksheets?$/i', trim( $title ) ) ? '' : '-worksheet' ),
			'post_date'   => $lesson_id ? get_post_field( 'post_date', $lesson_id ) : current_time( 'mysql' ),
		] );
		if ( ! $ws_id ) {
			return 0;
		}
	}
	update_post_meta( $ws_id, 'pdf', $pdf_id );
	wp_update_post( [ 'ID' => $pdf_id, 'post_parent' => $ws_id ] );
	if ( $answers_id ) {
		update_post_meta( $ws_id, 'answers', $answers_id );
		wp_update_post( [ 'ID' => $answers_id, 'post_parent' => $ws_id ] );
	} else {
		delete_post_meta( $ws_id, 'answers' );
	}
	if ( $lesson_id ) {
		update_post_meta( $ws_id, 'lesson', $lesson_id );
		update_post_meta( $lesson_id, 'worksheet_post', $ws_id );
		foreach ( [ 'mwm_level', 'mwm_topic' ] as $tax ) {
			$terms = wp_get_object_terms( $lesson_id, $tax, [ 'fields' => 'ids' ] );
			if ( $terms && ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $ws_id, $terms, $tax );
			}
		}
	}
	return $ws_id;
}

/**
 * Load the worksheet, quiz and PDF posts behind a page of lessons in two queries instead of several per card.
 *
 * @param WP_Post[] $posts
 */
function mwm_prime_lesson_relations( array $posts ): void {
	$related = [];
	foreach ( $posts as $p ) {
		foreach ( [ 'worksheet_post', 'quiz_post' ] as $key ) {
			$rid = (int) get_post_meta( $p->ID, $key, true );
			if ( $rid ) {
				$related[] = $rid;
			}
		}
	}
	if ( ! $related ) {
		return;
	}
	_prime_post_caches( array_unique( $related ), true, true ); // Terms too: worksheet cards read level/topic.
	$files = [];
	foreach ( $related as $rid ) {
		foreach ( [ 'pdf', 'answers' ] as $key ) {
			$fid = (int) get_post_meta( $rid, $key, true );
			if ( $fid ) {
				$files[] = $fid;
			}
		}
	}
	if ( $files ) {
		_prime_post_caches( array_unique( $files ), false, true );
	}
}

function mwm_attachment_info( $attachment ): ?array {
	$id = is_array( $attachment ) ? ( $attachment['ID'] ?? 0 ) : (int) $attachment;
	if ( ! $id || get_post_type( $id ) !== 'attachment' ) {
		return null;
	}
	$size = get_post_meta( $id, '_mwm_filesize', true );
	if ( $size === '' ) { // Remember the size so listing pages don't stat the file for every card.
		$path = get_attached_file( $id );
		$size = $path && file_exists( $path ) ? (int) filesize( $path ) : 0;
		update_post_meta( $id, '_mwm_filesize', $size );
	}
	$size = (int) $size;
	return [
		'id'    => $id,
		'url'   => wp_get_attachment_url( $id ),
		'name'  => mwm_title( $id ),
		'size'  => $size,
		'label' => $size ? 'PDF · ' . mwm_file_size_label( $size ) : 'PDF',
	];
}

/**
 * Normalised card data for a lesson, used by every card renderer and the REST API.
 */
function mwm_lesson_card( $post ): ?array {
	$post = get_post( $post );
	if ( ! $post || $post->post_type !== 'mwm_lesson' ) {
		return null;
	}
	$id      = $post->ID;
	$format  = mwm_get_term_slug( $id, 'mwm_format' ) ?: 'lesson';
	$yt      = (string) get_post_meta( $id, 'youtube_id', true );
	$seconds = (int) get_post_meta( $id, 'duration_seconds', true );
	$thumb   = (string) get_post_meta( $id, 'thumbnail_url', true );
	if ( has_post_thumbnail( $id ) ) {
		$thumb = get_the_post_thumbnail_url( $id, 'large' ) ?: $thumb;
	}
	if ( ! $thumb && $yt ) {
		$thumb = mwm_youtube_thumb( $yt );
	}
	$levels     = mwm_post_levels( $id );
	$level_slug = $levels[0] ?? '';
	$tt         = mwm_lesson_topic_terms( $id );
	$ws_post    = mwm_lesson_worksheet( $id );
	$ws_data    = $ws_post ? mwm_worksheet_data( $ws_post, false ) : null;
	$worksheet  = $ws_data ? $ws_data['pdf'] : null;
	$answers    = $ws_data ? $ws_data['answers'] : null;
	$quiz       = mwm_lesson_quiz( $id );
	$theme_slug = mwm_get_term_slug( $id, 'mwm_theme' );

	return [
		'id'              => $id,
		'title'           => mwm_title( $id ),
		'url'             => get_permalink( $id ),
		'slug'            => $post->post_name,
		'format'          => $format,
		'is_short'        => $format === 'short',
		'is_gaming'       => $format === 'gaming',
		'youtube_id'      => $yt,
		'youtube_url'     => mwm_youtube_watch_url( $yt ),
		'thumb'           => $thumb,
		'alt'             => mwm_title( $id ) . ( $format === 'short' ? ' short thumbnail' : ' video thumbnail' ),
		'level'           => mwm_levels_label( $levels ),
		'level_slug'      => $level_slug, // The first of `levels`; a lesson can suit more than one, so prefer `levels`.
		'levels'          => $levels,
		'level_style'     => in_array( 'gcse-higher', $levels, true ) ? 'higher' : 'tint',
		'topic'           => $tt['topic'] ? wp_specialchars_decode( $tt['topic']->name ) : '',
		'topic_slug'      => $tt['topic'] ? $tt['topic']->slug : '',
		'subtopic'        => $tt['subtopic'] ? wp_specialchars_decode( $tt['subtopic']->name ) : '',
		'subtopic_slug'   => $tt['subtopic'] ? $tt['subtopic']->slug : '',
		'theme'           => $theme_slug ? mwm_get_term_name( $id, 'mwm_theme' ) : '',
		'theme_slug'      => $theme_slug,
		'seconds'         => $seconds,
		'duration'        => mwm_duration_label( $seconds, $format ),
		'worksheet'       => $worksheet,
		'worksheet_id'    => $ws_data ? $ws_data['id'] : 0,
		'worksheet_url'   => $ws_data ? $ws_data['url'] : '',
		'worksheet_title' => $ws_data ? $ws_data['title'] : '',
		'answers'         => $answers,
		'has_worksheet'   => (bool) $worksheet,
		'has_answers'     => (bool) $answers,
		'quiz_id'         => $quiz ? $quiz->ID : 0,
		'quiz_url'        => $quiz ? get_permalink( $quiz ) : '',
		'has_quiz'        => (bool) $quiz,
		'published'       => get_the_date( 'Y-m-d', $id ),
		'published_label' => mwm_format_date( get_the_date( 'Y-m-d', $id ), 'short' ),
		'search'          => strtolower( mwm_title( $id ) . ' ' . ( $tt['topic'] ? wp_specialchars_decode( $tt['topic']->name ) : '' ) . ' ' . ( $tt['subtopic'] ? wp_specialchars_decode( $tt['subtopic']->name ) : '' ) ),
	];
}

/**
 * Query lessons and return card arrays.
 *
 * @param array $args {
 *   level, topic, subtopic, format (lesson|short|gaming|array), theme, worksheet(bool), quiz(bool), search, per_page, exclude, orderby
 * }
 */
/**
 * How many lessons match, without building any cards.
 */
function mwm_count_lessons( array $args = [] ): int {
	return mwm_query_lessons_paged( [ 'count_only' => true ] + $args )['total'];
}

function mwm_query_lessons( array $args = [] ): array {
	return mwm_query_lessons_paged( $args )['items'];
}

/**
 * Paged lesson query. Returns ['items' => cards, 'total' => int, 'pages' => int, 'page' => int, 'per_page' => int].
 */
function mwm_query_lessons_paged( array $args = [] ): array {
	$defaults = [ 'level' => '', 'topic' => '', 'subtopic' => '', 'format' => '', 'theme' => '', 'worksheet' => false, 'quiz' => false, 'search' => '', 'per_page' => -1, 'page' => 1, 'exclude' => [], 'orderby' => 'date', 'order' => 'DESC', 'include' => [], 'status' => 'publish', 'hide_broken' => true ];
	$a        = array_merge( $defaults, $args );
	$tax      = [];
	if ( $a['level'] ) {
		$tax[] = [ 'taxonomy' => 'mwm_level', 'field' => 'slug', 'terms' => (array) $a['level'] ];
	}
	if ( $a['subtopic'] ) {
		$tax[] = [ 'taxonomy' => 'mwm_topic', 'field' => 'slug', 'terms' => (array) $a['subtopic'], 'include_children' => false ];
	} elseif ( $a['topic'] ) {
		$tax[] = [ 'taxonomy' => 'mwm_topic', 'field' => 'slug', 'terms' => (array) $a['topic'], 'include_children' => true ];
	}
	if ( $a['format'] ) {
		$tax[] = [ 'taxonomy' => 'mwm_format', 'field' => 'slug', 'terms' => (array) $a['format'] ];
	}
	if ( $a['theme'] === 'none' ) {
		$tax[] = [ 'taxonomy' => 'mwm_theme', 'operator' => 'NOT EXISTS' ];
	} elseif ( $a['theme'] ) {
		$tax[] = [ 'taxonomy' => 'mwm_theme', 'field' => 'slug', 'terms' => (array) $a['theme'] ];
	}
	$paged = (int) $a['per_page'] > 0;
	$q = [
		'post_type'      => 'mwm_lesson',
		'post_status'    => $a['status'],
		'posts_per_page' => $a['per_page'],
		'paged'          => max( 1, (int) $a['page'] ),
		'orderby'        => $a['orderby'],
		'order'          => $a['order'],
		'no_found_rows'  => ! $paged,
		'post__not_in'   => (array) $a['exclude'],
	];
	if ( $a['include'] ) {
		$q['post__in'] = (array) $a['include'];
		$q['orderby']  = 'post__in';
	}
	if ( $tax ) {
		$q['tax_query'] = array_merge( [ 'relation' => 'AND' ], $tax );
	}
	if ( $a['search'] ) {
		$q['s'] = $a['search'];
	}
	if ( $a['worksheet'] ) {
		$q['meta_query'][] = [ 'key' => 'worksheet_post', 'value' => '0', 'compare' => '>', 'type' => 'NUMERIC' ];
	}
	if ( $a['quiz'] ) {
		$q['meta_query'][] = [ 'key' => 'quiz_post', 'value' => '0', 'compare' => '>', 'type' => 'NUMERIC' ];
	}
	if ( $a['hide_broken'] ) { // Lessons whose video is gone from YouTube stay off public listings until Kym fixes them.
		$q['meta_query'][] = [
			'relation' => 'OR',
			[ 'key' => 'video_status', 'compare' => 'NOT EXISTS' ],
			[ 'key' => 'video_status', 'value' => [ 'missing', 'private', 'unembeddable', 'invalid' ], 'compare' => 'NOT IN' ],
		];
	}
	if ( ! empty( $a['count_only'] ) ) { // Just the number: no cards, no meta, one cheap query.
		$q['fields']         = 'ids';
		$q['posts_per_page'] = 1;
		$q['paged']          = 1;
		$q['no_found_rows']  = false;
		return [ 'items' => [], 'total' => (int) ( new WP_Query( $q ) )->found_posts, 'pages' => 0, 'page' => 1, 'per_page' => 1 ];
	}
	$query = new WP_Query( $q );
	mwm_prime_lesson_relations( $query->posts );
	$cards = [];
	foreach ( $query->posts as $p ) {
		$card = mwm_lesson_card( $p );
		if ( $card ) {
			$cards[] = $card;
		}
	}
	$total = $paged ? (int) $query->found_posts : count( $cards );
	return [
		'items'    => $cards,
		'total'    => $total,
		'pages'    => $paged ? (int) $query->max_num_pages : 1,
		'page'     => $paged ? max( 1, (int) $a['page'] ) : 1,
		'per_page' => (int) $a['per_page'],
	];
}

/* -------------------------------------------------------------------------
 * Exam dates, past papers
 * ---------------------------------------------------------------------- */

function mwm_exam_dates( string $level = '', string $board = '', bool $future_only = false ): array {
	$tax = [];
	if ( $level ) {
		$tax[] = [ 'taxonomy' => 'mwm_level', 'field' => 'slug', 'terms' => $level ];
	}
	if ( $board ) {
		$tax[] = [ 'taxonomy' => 'mwm_board', 'field' => 'slug', 'terms' => $board ];
	}
	$q = [
		'post_type'      => 'mwm_exam_date',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_key'       => 'exam_date',
		'orderby'        => 'meta_value',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	];
	if ( $tax ) {
		$q['tax_query'] = array_merge( [ 'relation' => 'AND' ], $tax );
	}
	if ( $future_only ) {
		$q['meta_query'] = [ [ 'key' => 'exam_date', 'value' => gmdate( 'Ymd' ), 'compare' => '>=' ] ];
	}
	$out = [];
	foreach ( get_posts( $q ) as $p ) {
		$out[] = mwm_exam_date_data( $p );
	}
	return $out;
}

function mwm_exam_date_data( $post ): array {
	$post        = get_post( $post );
	$id          = $post->ID;
	$date        = (string) get_post_meta( $id, 'exam_date', true );
	$ymd         = $date ? gmdate( 'Y-m-d', strtotime( $date ) ) : '';
	$session     = (string) get_post_meta( $id, 'session', true ) ?: 'morning';
	$verified_on = (string) get_post_meta( $id, 'verified_on', true );
	$level       = mwm_get_term_slug( $id, 'mwm_level' );
	$board       = mwm_get_term_slug( $id, 'mwm_board' );
	return [
		'id'             => $id,
		'paper'          => (string) get_post_meta( $id, 'paper_label', true ),
		'date'           => $ymd,
		'day'            => $ymd ? (int) gmdate( 'j', strtotime( $ymd ) ) : 0,
		'month'          => $ymd ? (int) gmdate( 'n', strtotime( $ymd ) ) : 0,
		'year'           => $ymd ? (int) gmdate( 'Y', strtotime( $ymd ) ) : 0,
		'dow_day_month'  => $ymd ? mwm_format_date( $ymd, 'dow-day-month' ) : '',
		'full'           => $ymd ? mwm_format_date( $ymd, 'dow-day-month-year' ) : '',
		'session'        => $session,
		'session_label'  => ucfirst( $session ),
		'verified'       => (bool) get_post_meta( $id, 'verified', true ),
		'verified_on'    => $verified_on ? gmdate( 'Y-m-d', strtotime( $verified_on ) ) : '',
		'verified_label' => $verified_on ? mwm_format_date( gmdate( 'Y-m-d', strtotime( $verified_on ) ), 'short' ) : '',
		'level'          => $level,
		'level_name'     => mwm_level_name( $level ),
		'board'          => $board,
		'board_name'     => mwm_board_name( $board ),
		'title'          => mwm_title( $id ),
	];
}

/**
 * Next verified exam for a level + board.
 */
function mwm_next_exam( string $level, string $board ): ?array {
	foreach ( mwm_exam_dates( $level, $board, true ) as $d ) {
		if ( $d['verified'] ) {
			return $d;
		}
	}
	return null;
}

/* -------------------------------------------------------------------------
 * Revision pathways
 *
 * A pathway is one level + one topic ("GCSE Higher · Algebra"): the topic's subtopics in the order to revise
 * them, with the lessons tagged with each subtopic at that level. Nothing is copied: lessons appear as soon
 * as they are published. What Kym curates is the ORDER (and an optional note) per level and topic, stored as
 * term meta on the topic term: pathway_{level} => [ [ 'subtopic' => term_id, 'note' => '' ], ... ].
 *
 *  - A placed subtopic with no lesson at that level shows as "Coming soon" (she can plan ahead).
 *  - A subtopic with lessons that hasn't been placed yet goes at the end, flagged so the Studio can nag.
 *  - A lesson with no subtopic at all also goes at the end, flagged "needs a subtopic".
 * ---------------------------------------------------------------------- */

function mwm_pathway_id( string $level, string $topic ): string {
	return "$level:$topic";
}

function mwm_pathway_url( string $level, string $topic = '' ): string {
	return trailingslashit( mwm_page_url( 'revision' ) ) . $level . '/' . ( $topic ? $topic . '/' : '' );
}

/**
 * Forget cached overviews: call after anything that changes lessons, subtopics or an order.
 */
function mwm_flush_pathway_cache(): void {
	delete_transient( 'mwm_pathway_overviews' );
}

/**
 * The saved order for a level + topic: rows of [ 'subtopic' => term_id, 'note' => '' ], subtopics that no
 * longer exist dropped.
 */
function mwm_pathway_order( string $level, int $topic_id ): array {
	$rows = get_term_meta( $topic_id, 'pathway_' . $level, true );
	if ( ! is_array( $rows ) ) {
		return [];
	}
	$valid = array_column( mwm_subtopics( $topic_id ), 'id' );
	$out   = [];
	$seen  = [];
	foreach ( $rows as $r ) {
		$sid = (int) ( $r['subtopic'] ?? 0 );
		if ( ! $sid || isset( $seen[ $sid ] ) || ! in_array( $sid, $valid, true ) ) {
			continue;
		}
		$seen[ $sid ] = true;
		$out[] = [ 'subtopic' => $sid, 'note' => sanitize_text_field( (string) ( $r['note'] ?? '' ) ) ];
	}
	return $out;
}

/**
 * Subtopics Kym has taken out of a level's pathway even though they have lessons at that level (the lessons
 * stay on Learn Maths). Stored as pathway_{level}_hidden => [ term_id, ... ].
 */
function mwm_pathway_hidden( string $level, int $topic_id ): array {
	$ids   = get_term_meta( $topic_id, 'pathway_' . $level . '_hidden', true );
	$valid = array_column( mwm_subtopics( $topic_id ), 'id' );
	return array_values( array_intersect( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ), $valid ) );
}

function mwm_set_pathway_order( string $level, int $topic_id, array $rows, ?array $hidden = null ): void {
	$clean = [];
	$seen  = [];
	foreach ( $rows as $r ) {
		$sid = (int) ( $r['subtopic'] ?? 0 );
		if ( ! $sid || isset( $seen[ $sid ] ) ) {
			continue;
		}
		$seen[ $sid ] = true;
		$clean[] = [ 'subtopic' => $sid, 'note' => sanitize_text_field( (string) ( $r['note'] ?? '' ) ) ];
	}
	update_term_meta( $topic_id, 'pathway_' . $level, $clean );
	if ( $hidden !== null ) { // Anything placed can't also be hidden.
		update_term_meta( $topic_id, 'pathway_' . $level . '_hidden', array_values( array_diff( array_unique( array_map( 'intval', $hidden ) ), array_keys( $seen ) ) ) );
	}
	// Keep the subtopic order field in step so Learn Maths filters list them in pathway order too.
	foreach ( $clean as $i => $r ) {
		update_term_meta( $r['subtopic'], 'order', $i + 1 );
	}
	mwm_flush_pathway_cache();
}

/**
 * The full pathway for a level + topic slug, or null when the topic doesn't exist or isn't for that level.
 */
function mwm_pathway( string $level, string $topic_slug ): ?array {
	if ( ! isset( mwm_levels()[ $level ] ) ) {
		return null;
	}
	$topic = null;
	foreach ( mwm_topics( $level ) as $t ) {
		if ( $t['slug'] === $topic_slug ) {
			$topic = $t;
		}
	}
	if ( ! $topic ) {
		return null;
	}
	$subtopics = [];
	foreach ( mwm_subtopics( $topic['id'] ) as $st ) {
		$subtopics[ $st['id'] ] = $st;
	}
	// Every lesson for this level + topic, oldest first, grouped by subtopic.
	$by_sub  = [];
	$orphans = [];
	foreach ( mwm_query_lessons( [ 'level' => $level, 'topic' => $topic_slug, 'format' => 'lesson', 'orderby' => 'date', 'order' => 'ASC' ] ) as $c ) {
		$sid = 0;
		foreach ( $subtopics as $id => $st ) {
			if ( $st['slug'] === $c['subtopic_slug'] ) {
				$sid = $id;
			}
		}
		$lesson = [
			'id'            => $c['id'],
			'title'         => $c['title'],
			'url'           => $c['url'],
			'has_worksheet' => $c['has_worksheet'],
			'worksheet_url' => $c['worksheet_url'],
			'has_quiz'      => $c['has_quiz'],
			'quiz_id'       => $c['quiz_id'],
			'quiz_url'      => $c['quiz_url'],
		];
		if ( $sid ) {
			$by_sub[ $sid ][] = $lesson;
		} else {
			$orphans[] = $lesson;
		}
	}
	$rows   = [];
	$placed = [];
	$step   = 0;
	$row    = static function ( array $st, string $note, array $lessons, bool $is_placed ) use ( &$step ): array {
		$step++;
		return [
			'key'            => 's' . $st['id'],
			'step'           => str_pad( (string) $step, 2, '0', STR_PAD_LEFT ),
			'subtopic_id'    => $st['id'],
			'subtopic_slug'  => $st['slug'],
			'name'           => $st['name'],
			'note'           => $note,
			'lessons'        => $lessons,
			'coming_soon'    => ! $lessons,
			'placed'         => $is_placed,
			'needs_subtopic' => false,
			'worksheet'      => (bool) array_filter( array_column( $lessons, 'has_worksheet' ) ),
			'quiz'           => (bool) array_filter( array_column( $lessons, 'has_quiz' ) ),
		];
	};
	$hidden_ids = mwm_pathway_hidden( $level, $topic['id'] );
	foreach ( mwm_pathway_order( $level, $topic['id'] ) as $r ) {
		if ( in_array( $r['subtopic'], $hidden_ids, true ) ) {
			continue;
		}
		$placed[ $r['subtopic'] ] = true;
		$rows[] = $row( $subtopics[ $r['subtopic'] ], $r['note'], $by_sub[ $r['subtopic'] ] ?? [], true );
	}
	$hidden = [];
	foreach ( $by_sub as $sid => $lessons ) {
		if ( in_array( $sid, $hidden_ids, true ) ) {
			$hidden[] = [ 'subtopic_id' => $sid, 'name' => $subtopics[ $sid ]['name'], 'lessons' => $lessons ];
		} elseif ( ! isset( $placed[ $sid ] ) ) {
			$rows[] = $row( $subtopics[ $sid ], '', $lessons, false );
		}
	}
	foreach ( $orphans as $lesson ) {
		$step++;
		$rows[] = [
			'key'            => 'l' . $lesson['id'],
			'step'           => str_pad( (string) $step, 2, '0', STR_PAD_LEFT ),
			'subtopic_id'    => 0,
			'subtopic_slug'  => '',
			'name'           => $lesson['title'],
			'note'           => '',
			'lessons'        => [ $lesson ],
			'coming_soon'    => false,
			'placed'         => false,
			'needs_subtopic' => true,
			'worksheet'      => $lesson['has_worksheet'],
			'quiz'           => $lesson['has_quiz'],
		];
	}
	$with_lessons = count( array_filter( $rows, static fn( $r ) => ! $r['coming_soon'] ) );
	return [
		'id'              => mwm_pathway_id( $level, $topic_slug ),
		'level'           => $level,
		'level_name'      => mwm_level_name( $level ),
		'topic'           => $topic_slug,
		'topic_id'        => $topic['id'],
		'topic_name'      => $topic['name'],
		'icon'            => $topic['icon'],
		'url'             => mwm_pathway_url( $level, $topic_slug ),
		'rows'            => $rows,
		'total'           => count( $rows ),
		'with_lessons'    => $with_lessons,
		'coming_soon'     => count( $rows ) - $with_lessons,
		'with_worksheets' => count( array_filter( $rows, static fn( $r ) => $r['worksheet'] ) ),
		'with_quizzes'    => count( array_filter( $rows, static fn( $r ) => $r['quiz'] ) ),
		'unplaced'        => count( array_filter( $rows, static fn( $r ) => ! $r['placed'] && ! $r['needs_subtopic'] ) ),
		'orphans'         => count( $orphans ),
		'hidden'          => $hidden,
	];
}

/**
 * One line per topic for a level: counts only, no rows. Cached (all levels in one transient) because the
 * Revision overview, the home page, My Learning and the Studio dashboard all ask for it.
 */
function mwm_pathway_overview( string $level ): array {
	$all = get_transient( 'mwm_pathway_overviews' );
	if ( ! is_array( $all ) ) {
		$all = [];
	}
	if ( ! isset( $all[ $level ] ) ) {
		$all[ $level ] = [];
		foreach ( mwm_topics( $level ) as $t ) {
			$pw = mwm_pathway( $level, $t['slug'] );
			if ( ! $pw ) {
				continue;
			}
			$all[ $level ][] = array_intersect_key( $pw, array_flip( [ 'id', 'level', 'topic', 'topic_id', 'topic_name', 'icon', 'url', 'total', 'with_lessons', 'coming_soon', 'with_worksheets', 'with_quizzes', 'unplaced', 'orphans' ] ) )
				+ [ 'keys' => array_column( array_filter( $pw['rows'], static fn( $r ) => ! $r['coming_soon'] ), 'key' ) ];
		}
		set_transient( 'mwm_pathway_overviews', $all, DAY_IN_SECONDS );
	}
	return $all[ $level ];
}

/**
 * Where a lesson sits in its pathway at a level, and what comes after it (the next row with a lesson).
 * Returns null when the lesson isn't in any pathway row for that level.
 */
function mwm_next_in_pathway( int $lesson_id, string $level ): ?array {
	$card = mwm_lesson_card( $lesson_id );
	if ( ! $card || ! $card['topic_slug'] ) {
		return null;
	}
	$pw = mwm_pathway( $level, $card['topic_slug'] );
	if ( ! $pw ) {
		return null;
	}
	$found = false;
	foreach ( $pw['rows'] as $r ) {
		$here = in_array( $lesson_id, array_column( $r['lessons'], 'id' ), true );
		if ( $found && ! $r['coming_soon'] ) {
			return [ 'pathway' => $pw, 'found' => true, 'next' => $r ];
		}
		if ( $here ) {
			$found = true;
		}
	}
	return [ 'pathway' => $pw, 'found' => $found, 'next' => null ];
}

/**
 * The pathway rows a lesson belongs to: one per level the lesson has, with the pathway id, the row key and
 * the ids of every lesson in that row, so a lesson page can tick the row once all of them are complete.
 * Cheap (term lookups and one ids-only query per level); no pathway is assembled.
 */
function mwm_lesson_pathway_rows( int $lesson_id ): array {
	$tt = mwm_lesson_topic_terms( $lesson_id );
	if ( ! $tt['topic'] ) {
		return [];
	}
	$sub  = $tt['subtopic'];
	$rows = [];
	foreach ( mwm_post_levels( $lesson_id ) as $level ) {
		$siblings = [ $lesson_id ];
		if ( $sub ) {
			$ids = get_posts( [
				'post_type'      => 'mwm_lesson',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => [
					'relation' => 'AND',
					[ 'taxonomy' => 'mwm_level', 'field' => 'slug', 'terms' => $level ],
					[ 'taxonomy' => 'mwm_topic', 'field' => 'term_id', 'terms' => (int) $sub->term_id, 'include_children' => false ],
					[ 'taxonomy' => 'mwm_format', 'field' => 'slug', 'terms' => 'lesson' ],
				],
			] );
			$siblings = array_values( array_unique( array_merge( [ $lesson_id ], array_map( 'intval', (array) $ids ) ) ) );
		}
		$rows[] = [
			'pathway'    => mwm_pathway_id( $level, $tt['topic']->slug ),
			'key'        => $sub ? 's' . (int) $sub->term_id : 'l' . $lesson_id,
			'level'      => $level,
			'level_name' => mwm_level_name( $level ),
			'topic_name' => wp_specialchars_decode( $tt['topic']->name ),
			'url'        => mwm_pathway_url( $level, $tt['topic']->slug ),
			'lessons'    => $siblings,
		];
	}
	return $rows;
}

/**
 * The suggested week-by-week revision plan for a level (shown on the exam calendar).
 * Rows: [ 'week' => Y-m-d (Monday), 'focus' => '', 'short' => '' ], sorted by week.
 */
function mwm_revision_plan( string $level ): array {
	$all  = (array) get_option( 'mwm_revision_plans', [] );
	$rows = [];
	foreach ( (array) ( $all[ $level ] ?? [] ) as $w ) {
		$week = (string) ( $w['week'] ?? '' );
		if ( $week && strtotime( $week ) ) {
			$rows[] = [ 'week' => gmdate( 'Y-m-d', strtotime( $week ) ), 'focus' => (string) ( $w['focus'] ?? '' ), 'short' => (string) ( $w['short'] ?? '' ) ];
		}
	}
	usort( $rows, static fn( $a, $b ) => strcmp( $a['week'], $b['week'] ) );
	return $rows;
}

function mwm_set_revision_plan( string $level, array $rows ): array {
	$clean = [];
	foreach ( $rows as $w ) {
		$week  = sanitize_text_field( (string) ( $w['week'] ?? '' ) );
		$focus = sanitize_text_field( (string) ( $w['focus'] ?? '' ) );
		if ( ! $week || ! strtotime( $week ) || $focus === '' ) {
			continue;
		}
		$clean[] = [ 'week' => gmdate( 'Y-m-d', strtotime( $week ) ), 'focus' => $focus, 'short' => sanitize_text_field( (string) ( $w['short'] ?? '' ) ) ];
	}
	usort( $clean, static fn( $a, $b ) => strcmp( $a['week'], $b['week'] ) );
	$all = (array) get_option( 'mwm_revision_plans', [] );
	$all[ $level ] = $clean;
	update_option( 'mwm_revision_plans', $all, false );
	return $clean;
}

function mwm_past_paper_data( $post ): ?array {
	$post = get_post( $post );
	if ( ! $post || $post->post_type !== 'mwm_past_paper' ) {
		return null;
	}
	$id     = $post->ID;
	$tier   = (string) get_post_meta( $id, 'tier', true ) ?: 'higher';
	$season = (string) get_post_meta( $id, 'series_season', true ) ?: 'June';
	$year   = (int) get_post_meta( $id, 'series_year', true );
	$num    = (int) get_post_meta( $id, 'paper_number', true ) ?: 1;
	$calc   = (bool) get_post_meta( $id, 'calculator', true );
	$qp     = mwm_attachment_info( get_post_meta( $id, 'question_paper', true ) );
	$ms     = mwm_attachment_info( get_post_meta( $id, 'mark_scheme', true ) );
	$code   = (string) get_post_meta( $id, 'qualification_code', true ) ?: '1MA1';
	$marks  = (int) get_post_meta( $id, 'marks', true ) ?: 80;
	$mins   = (int) get_post_meta( $id, 'duration_minutes', true ) ?: 90;
	$ws_ids = (array) get_post_meta( $id, 'worksheets', true );
	$worksheets = [];
	foreach ( $ws_ids as $wid ) {
		$w = mwm_worksheet_data( (int) $wid, false );
		if ( $w ) {
			$worksheets[] = [ 'id' => $w['id'], 'name' => $w['title'], 'url' => $w['url'] ];
		}
	}
	$h     = intdiv( $mins, 60 );
	$m     = $mins % 60;
	$dur   = $h ? ( $h . ' hour' . ( $h > 1 ? 's' : '' ) . ( $m ? " $m minutes" : '' ) ) : "$m minutes";
	$board = mwm_get_term_slug( $id, 'mwm_board' );
	return [
		'id'         => $id,
		'board'      => $board,
		'board_name' => mwm_board_name( $board ),
		'tier'       => $tier,
		'tier_name'  => ucfirst( $tier ),
		'series'     => "$season $year",
		'season'     => $season,
		'year'       => $year,
		'sort'       => mwm_series_sort_key( $season, $year ),
		'paper'      => $num,
		'calculator' => $calc,
		'title'      => "Paper $num (" . ( $calc ? 'calculator' : 'non-calculator' ) . ')',
		'meta'       => "$code/{$num}" . strtoupper( substr( $tier, 0, 1 ) ) . " · $marks marks · $dur",
		'qp'         => $qp,
		'ms'         => $ms,
		'worksheets' => $worksheets,
		'post_title' => mwm_title( $id ),
	];
}

/**
 * A predicted paper: Melissa's own paper for a coming exam, with worked solutions rather than a mark scheme.
 */
function mwm_predicted_paper_data( $post ): ?array {
	$post = get_post( $post );
	if ( ! $post || $post->post_type !== 'mwm_predicted_paper' ) {
		return null;
	}
	$id    = $post->ID;
	$level = mwm_get_term_slug( $id, 'mwm_level' ) ?: 'gcse-higher';
	$board = mwm_get_term_slug( $id, 'mwm_board' );
	$year  = (int) get_post_meta( $id, 'exam_year', true );
	$name  = trim( (string) get_post_meta( $id, 'paper_name', true ) ) ?: 'Predicted paper';
	$calc  = (bool) get_post_meta( $id, 'calculator', true );
	$qp    = mwm_attachment_info( get_post_meta( $id, 'question_paper', true ) );
	$sol   = mwm_attachment_info( get_post_meta( $id, 'worked_solutions', true ) );
	$worksheets = [];
	foreach ( (array) get_post_meta( $id, 'worksheets', true ) as $wid ) {
		$w = mwm_worksheet_data( (int) $wid, false );
		if ( $w ) {
			$worksheets[] = [ 'id' => $w['id'], 'name' => $w['title'], 'url' => $w['url'] ];
		}
	}
	$level_name = mwm_level_name( $level );
	return [
		'id'         => $id,
		'board'      => $board,
		'board_name' => mwm_board_name( $board ),
		'level'      => $level,
		'level_name' => $level_name,
		'year'       => $year,
		'group'      => ( $year ? "Predicted $year" : 'Predicted' ) . ' · ' . $level_name,
		'calculator' => $calc,
		'title'      => $name,
		'meta'       => implode( ' · ', array_filter( [ $year ? "Predicted $year" : '', mwm_board_name( $board ), $level_name, $calc ? 'calculator' : 'non-calculator' ] ) ),
		'qp'         => $qp,
		'solutions'  => $sol,
		'worksheets' => $worksheets,
		'post_title' => mwm_title( $id ),
	];
}

/**
 * Sort key for a series label ("June 2025" > "November 2024").
 */
function mwm_series_sort_key( string $season, int $year ): int {
	return $year * 10 + ( strtolower( $season ) === 'november' ? 2 : 1 );
}

/* -------------------------------------------------------------------------
 * User preferences (level/board) — falls back to sensible defaults.
 * ---------------------------------------------------------------------- */

function mwm_user_prefs(): array {
	$defaults = [ 'level' => 'gcse-higher', 'board' => 'edexcel' ];
	if ( is_user_logged_in() ) {
		$saved = get_user_meta( get_current_user_id(), 'mwm_prefs', true );
		if ( is_array( $saved ) ) {
			$defaults = array_merge( $defaults, array_intersect_key( $saved, $defaults ) );
		}
	}
	return $defaults;
}

/**
 * Pages created by the activator, by key.
 */
function mwm_page_url( string $key ): string {
	if ( $key === 'worksheets' ) {
		return (string) ( get_post_type_archive_link( 'mwm_worksheet' ) ?: home_url( '/worksheets/' ) );
	}
	$ids = (array) get_option( 'mwm_pages', [] );
	if ( ! empty( $ids[ $key ] ) && get_post_status( $ids[ $key ] ) === 'publish' ) {
		return get_permalink( (int) $ids[ $key ] );
	}
	$fallbacks = [
		'home'        => '/',
		'browse'      => '/learn-maths/',
		'revision'    => '/revision/',
		'calendar'    => '/revision/exam-calendar/',
		'past-papers' => '/revision/past-papers/',
		'predicted-papers' => '/revision/predicted-papers/',
		'quick-maths' => '/quick-maths/',
		'gaming'      => '/gaming-story-maths/',
		'my-learning' => '/my-learning/',
		'privacy'     => '/privacy/',
	];
	return home_url( $fallbacks[ $key ] ?? '/' );
}

function mwm_browse_url( string $level = '', string $topic = '', string $subtopic = '' ): string {
	$base = trailingslashit( mwm_page_url( 'browse' ) );
	if ( $level ) {
		$base .= $level . '/';
		if ( $topic ) {
			$base .= $topic . '/';
		}
	}
	if ( $subtopic ) {
		$base = add_query_arg( 'subtopic', $subtopic, $base );
	}
	return $base;
}

function mwm_youtube_channel_url(): string {
	return (string) get_option( 'mwm_youtube_channel_url', 'https://www.youtube.com/@mathswithmelissa' );
}
