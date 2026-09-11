<?php
/**
 * Activation: seed taxonomy terms, create the site's pages, add capabilities, schedule cron.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Activator {

	public const CAP = 'mwm_manage_studio';

	public static function activate(): void {
		MWM_Post_Types::register();
		MWM_Rewrites::add_rules();
		self::seed_terms();
		self::add_caps();
		self::create_pages();
		MWM_YouTube::schedule();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		MWM_YouTube::unschedule();
		flush_rewrite_rules();
	}

	public static function add_caps(): void {
		foreach ( [ 'administrator', 'editor' ] as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::CAP );
			}
		}
	}

	private static function ensure_term( string $taxonomy, string $slug, string $name, array $meta = [], int $parent = 0 ): int {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term ) {
			$id = (int) $term->term_id;
		} else {
			$res = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug, 'parent' => $parent ] );
			if ( is_wp_error( $res ) ) {
				return 0;
			}
			$id = (int) $res['term_id'];
		}
		foreach ( $meta as $k => $v ) {
			if ( get_term_meta( $id, $k, true ) === '' ) {
				update_term_meta( $id, $k, $v );
			}
		}
		return $id;
	}

	public static function seed_terms(): void {
		$i = 0;
		foreach ( mwm_levels() as $slug => $l ) {
			self::ensure_term( 'mwm_level', $slug, $l['name'], [ 'order' => ++$i, 'short' => $l['short'], 'blurb' => $l['blurb'] ] );
		}
		$i = 0;
		foreach ( mwm_default_topics() as $slug => $t ) {
			self::ensure_term( 'mwm_topic', $slug, $t['name'], [ 'order' => ++$i, 'icon' => $t['icon'], 'levels' => $t['levels'], 'keywords' => $t['keywords'] ?? '' ] );
		}
		// Algebra subtopics as listed in the prototype.
		$algebra = get_term_by( 'slug', 'algebra', 'mwm_topic' );
		if ( $algebra ) {
			$subs = [ 'Expanding brackets', 'Factorising', 'Solving linear equations', 'Solving quadratics', 'Simultaneous equations', 'Inequalities', 'Sequences', 'Graphs', 'Rearranging formulae', 'Algebraic fractions', 'Functions', 'Iteration', 'Algebraic proof', 'Indices', 'Straight-line graphs', 'Quadratic graphs', 'Cubic and reciprocal graphs', 'Gradients and rates of change', 'Equation of a circle', 'Expressions and substitution' ];
			foreach ( $subs as $j => $name ) {
				self::ensure_term( 'mwm_topic', sanitize_title( $name ), $name, [ 'order' => $j + 1 ], (int) $algebra->term_id );
			}
			if ( get_term_meta( $algebra->term_id, 'intro', true ) === '' ) {
				update_term_meta( $algebra->term_id, 'intro', 'From expanding brackets to quadratic simultaneous equations — every Algebra lesson for this level, in a sensible order. Filter by subtopic, or by what comes with a worksheet or quiz.' );
			}
		}
		foreach ( [ 'lesson' => 'Lesson', 'short' => 'Quick Maths', 'gaming' => 'Gaming & Story' ] as $slug => $name ) {
			self::ensure_term( 'mwm_format', $slug, $name );
		}
		foreach ( [ 'roblox' => 'Roblox', 'minecraft' => 'Minecraft', 'story' => 'Story' ] as $slug => $name ) {
			self::ensure_term( 'mwm_theme', $slug, $name );
		}
		foreach ( mwm_boards() as $slug => $name ) {
			self::ensure_term( 'mwm_board', $slug, $name );
		}
	}

	/**
	 * Pages the theme's navigation relies on. Each one holds the ACF block that renders it.
	 */
	public static function page_defs(): array {
		return [
			'home'        => [ 'title' => 'Home', 'slug' => 'home', 'parent' => '', 'content' => "<!-- wp:acf/home-hero /-->\n<!-- wp:acf/level-cards /-->\n<!-- wp:acf/featured-lessons /-->\n<!-- wp:acf/how-it-works /-->\n<!-- wp:acf/topic-browser /-->\n<!-- wp:acf/pathway-cards /-->\n<!-- wp:acf/gaming-row /-->\n<!-- wp:acf/quick-maths-band /-->\n<!-- wp:acf/exam-panel /-->\n<!-- wp:acf/subscribe-strip /-->" ],
			'browse'      => [ 'title' => 'Learn Maths', 'slug' => 'learn-maths', 'parent' => '', 'content' => '<!-- wp:acf/browse /-->' ],
			'revision'    => [ 'title' => 'Revision', 'slug' => 'revision', 'parent' => '', 'content' => '<!-- wp:acf/pathway /-->' ],
			'calendar'    => [ 'title' => 'Exam calendar', 'slug' => 'exam-calendar', 'parent' => 'revision', 'content' => '<!-- wp:acf/exam-calendar /-->' ],
			'past-papers' => [ 'title' => 'Past papers', 'slug' => 'past-papers', 'parent' => 'revision', 'content' => '<!-- wp:acf/past-papers /-->' ],
			'quick-maths' => [ 'title' => 'Quick Maths', 'slug' => 'quick-maths', 'parent' => '', 'content' => '<!-- wp:acf/quick-maths /-->' ],
			'gaming'      => [ 'title' => 'Gaming & Story Maths', 'slug' => 'gaming-story-maths', 'parent' => '', 'content' => '<!-- wp:acf/gaming-story /-->' ],
			'my-learning' => [ 'title' => 'My Learning', 'slug' => 'my-learning', 'parent' => '', 'content' => '<!-- wp:acf/my-learning /-->' ],
			'privacy'     => [ 'title' => 'Privacy', 'slug' => 'privacy', 'parent' => '', 'content' => "<!-- wp:acf/page-header {\"data\":{\"heading\":\"Privacy\",\"intro\":\"How Maths with Melissa looks after your information.\"}} /-->\n<!-- wp:paragraph -->\n<p>This page will hold the privacy notice.</p>\n<!-- /wp:paragraph -->" ],
		];
	}

	public static function create_pages(): void {
		$ids = (array) get_option( 'mwm_pages', [] );
		foreach ( self::page_defs() as $key => $def ) {
			if ( ! empty( $ids[ $key ] ) && get_post( $ids[ $key ] ) && get_post_status( $ids[ $key ] ) !== 'trash' ) {
				continue;
			}
			$parent_id = $def['parent'] && ! empty( $ids[ $def['parent'] ] ) ? (int) $ids[ $def['parent'] ] : 0;
			$existing  = get_page_by_path( ( $def['parent'] ? $def['parent'] . '/' : '' ) . $def['slug'] );
			if ( $existing ) {
				$ids[ $key ] = $existing->ID;
				continue;
			}
			$id = wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $def['title'],
				'post_name'    => $def['slug'],
				'post_parent'  => $parent_id,
				'post_content' => $def['content'],
			] );
			if ( $id && ! is_wp_error( $id ) ) {
				$ids[ $key ] = (int) $id;
			}
		}
		update_option( 'mwm_pages', $ids );
		if ( ! empty( $ids['home'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $ids['home'] );
		}
		// Remove the stock sample page/post so the site starts clean.
		foreach ( [ 'sample-page' ] as $slug ) {
			$p = get_page_by_path( $slug );
			if ( $p ) {
				wp_trash_post( $p->ID );
			}
		}
		$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
		if ( $hello ) {
			wp_trash_post( $hello->ID );
		}
	}
}
