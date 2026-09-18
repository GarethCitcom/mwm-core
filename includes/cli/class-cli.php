<?php
/**
 * WP-CLI: wp mwm <command>
 *
 *   wp mwm seed-demo [--remove]           Recreate the prototype's sample content (or remove it).
 *   wp mwm import-lessons ...             One-off import from the old site (REST API, WXR export or JSON).
 *   wp mwm redirect-map [--format=...]    Build the 301 map from imported lessons.
 *   wp mwm sync                           Run the YouTube playlist sync now.
 */

defined( 'ABSPATH' ) || exit;

class MWM_CLI {

	private const DEMO_META = '_mwm_demo';

	/**
	 * Recreate the prototype's sample content so every screen can be checked against the design.
	 *
	 * ## OPTIONS
	 *
	 * [--remove]
	 * : Remove everything the seeder created.
	 *
	 * @subcommand seed-demo
	 * @when after_wp_load
	 */
	public function seed_demo( array $args, array $assoc ): void {
		if ( isset( $assoc['remove'] ) ) {
			$this->remove_demo();
			return;
		}
		MWM_Activator::seed_terms();
		MWM_Activator::create_pages();
		$this->seed_lessons();
		$this->seed_quiz();
		$this->seed_exam_dates();
		$this->seed_pathways();
		$this->seed_past_papers();
		$this->seed_home_featured();
		flush_rewrite_rules();
		WP_CLI::success( 'Demo content is in place. Remove it later with: wp mwm seed-demo --remove' );
	}

	private function remove_demo(): void {
		// Explicit post types: 'any' would skip the non-public ones (pathways, exam dates).
		$types = [ 'mwm_lesson', 'mwm_quiz', 'mwm_pathway', 'mwm_exam_date', 'mwm_past_paper' ];
		$posts = get_posts( [ 'post_type' => $types, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => self::DEMO_META, 'no_found_rows' => true ] );
		$atts  = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => self::DEMO_META, 'no_found_rows' => true ] );
		foreach ( array_unique( array_merge( $posts, $atts ) ) as $id ) {
			if ( get_post_type( $id ) === 'attachment' ) {
				wp_delete_attachment( $id, true );
			} else {
				wp_delete_post( $id, true );
			}
		}
		// The home hero may point at a demo lesson; let it fall back to the newest real one.
		$pages = (array) get_option( 'mwm_pages', [] );
		if ( ! empty( $pages['home'] ) ) {
			$content = (string) get_post_field( 'post_content', $pages['home'] );
			$content = preg_replace( '/<!-- wp:acf\/home-hero(?: \{.*?\})? \/-->/', '<!-- wp:acf/home-hero /-->', $content, 1 );
			wp_update_post( [ 'ID' => $pages['home'], 'post_content' => $content ] );
		}
		WP_CLI::success( sprintf( 'Removed %d demo items.', count( $posts ) + count( $atts ) ) );
	}

	/* ---------------------------------------------------------------
	 * Demo helpers
	 * ------------------------------------------------------------ */

	private function proto_assets_dir(): string {
		return get_theme_file_path( 'docs/design/design_handoff_mwm/prototype/assets' );
	}

	private function sideload_image( string $file, int $parent = 0 ): int {
		$path = $this->proto_assets_dir() . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return 0;
		}
		$existing = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_mwm_demo_file', 'meta_value' => $file, 'no_found_rows' => true ] );
		if ( $existing ) {
			return (int) $existing[0];
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( $file );
		copy( $path, $tmp );
		$id = media_handle_sideload( [ 'name' => $file, 'tmp_name' => $tmp ], $parent );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return 0;
		}
		update_post_meta( $id, self::DEMO_META, 1 );
		update_post_meta( $id, '_mwm_demo_file', $file );
		return (int) $id;
	}

	/**
	 * A small, valid PDF with a title line, padded to roughly the requested size.
	 */
	private function make_pdf( string $title, int $target_bytes, int $parent = 0 ): int {
		$key = sanitize_title( $title ) . '-' . $target_bytes;
		$existing = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_mwm_demo_file', 'meta_value' => $key, 'no_found_rows' => true ] );
		if ( $existing ) {
			return (int) $existing[0];
		}
		$text    = str_replace( [ '\\', '(', ')' ], [ '\\\\', '\\(', '\\)' ], $title );
		$content = "BT /F1 24 Tf 72 760 Td ($text) Tj ET\nBT /F1 12 Tf 72 730 Td (Maths with Melissa — demo file) Tj ET";
		$objs    = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
			'<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		];
		$pdf     = "%PDF-1.4\n";
		$offsets = [];
		foreach ( $objs as $i => $o ) {
			$offsets[] = strlen( $pdf );
			$pdf .= ( $i + 1 ) . " 0 obj\n" . $o . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$pdf .= sprintf( '%010d 00000 n ', $off ) . "\n";
		}
		$pdf .= "trailer\n<< /Size " . ( count( $objs ) + 1 ) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
		$pad = $target_bytes - strlen( $pdf );
		if ( $pad > 0 ) {
			$pdf .= '%' . str_repeat( '0', $pad - 2 ) . "\n";
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$name = sanitize_title( $title ) . '.pdf';
		$tmp  = wp_tempnam( $name );
		file_put_contents( $tmp, $pdf );
		$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp, 'type' => 'application/pdf' ], $parent );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			WP_CLI::warning( 'PDF failed: ' . $id->get_error_message() );
			return 0;
		}
		wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
		update_post_meta( $id, self::DEMO_META, 1 );
		update_post_meta( $id, '_mwm_demo_file', $key );
		return (int) $id;
	}

	private function find_demo( string $type, string $title ): int {
		$posts = get_posts( [ 'post_type' => $type, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'title' => $title, 'meta_key' => self::DEMO_META, 'no_found_rows' => true ] );
		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * Lesson definitions from the prototypes.
	 */
	private function demo_lessons(): array {
		// Lessons/gaming videos give minutes; shorts give seconds.
		$L = static fn( $title, $level, $topic, $mins, $opts = [] ) => array_merge( [ 'title' => $title, 'level' => $level, 'topic' => $topic, 'seconds' => ( $opts['format'] ?? 'lesson' ) === 'short' ? $mins : $mins * 60, 'format' => 'lesson' ], $opts );
		$rows = [
			// "Start with these" order = newest first, so dates count down from here.
			$L( 'Circumference of a circle', 'gcse-foundation', 'geometry-measures', 6, [ 'thumb' => 'thumb2-circumference.jpg', 'worksheet' => 210 ] ),
			$L( 'Quick percentages: mental maths', 'gcse-foundation', 'number', 5, [ 'thumb' => 'thumb2-quickperc.jpg' ] ),
			$L( 'Finding the mode in complex data', 'gcse-higher', 'statistics', 8, [ 'thumb' => 'thumb2-mode.jpg', 'worksheet' => 188 ] ),
			$L( 'Mixed number percentages', 'gcse-higher', 'number', 9, [ 'thumb' => 'thumb2-mixedperc.jpg' ] ),
			$L( 'Volume of a cuboid', 'gcse-foundation', 'geometry-measures', 7, [ 'thumb' => 'thumb2-cuboid.jpg', 'quiz' => true ] ),
			$L( 'Converting miles and kilometres', 'gcse-foundation', 'ratio-proportion', 6, [ 'thumb' => 'thumb2-miles.jpg', 'worksheet' => 150 ] ),
			$L( 'Area of equal shapes', 'gcse-foundation', 'geometry-measures', 6, [ 'thumb' => 'thumb2-areashapes.jpg', 'worksheet' => 170 ] ),
			$L( 'Exam-style questions on averages', 'gcse-foundation', 'statistics', 8, [ 'thumb' => 'thumb-averages.png', 'worksheet' => 260, 'quiz' => true ] ),
			$L( 'Linear simultaneous equations', 'gcse-higher', 'simultaneous-equations', 9, [ 'thumb' => 'thumb-simeq.png', 'worksheet' => 230, 'answers' => 310 ] ),
			$L( 'Quadratic simultaneous equations', 'gcse-higher', 'simultaneous-equations', 9, [ 'thumb' => 'thumb2-quadsimeq.jpg', 'worksheet' => 240, 'answers' => 330, 'date' => '2026-08-12 10:00:00', 'content' => '<p>How to solve a pair of simultaneous equations where one is quadratic — for example <em>x</em>² + <em>y</em>² = 25 with <em>y</em> = 2<em>x</em> − 5. You’ll substitute the linear equation into the quadratic, solve for <em>x</em>, then find both pairs of solutions. Every step is written out, including the factorising.</p>' ] ),
			$L( 'Solving quadratics by completing the square', 'gcse-higher', 'solving-quadratics', 10, [] ),
			// Gaming & Story
			$L( 'Circumference word problems', 'gcse-foundation', 'geometry-measures', 7, [ 'format' => 'gaming', 'theme' => 'story', 'thumb' => 'thumb2-circword.jpg', 'worksheet' => 200 ] ),
			$L( 'Coordinates in Roblox obbies', 'gcse-foundation', 'algebra', 6, [ 'format' => 'gaming', 'theme' => 'roblox' ] ),
			$L( 'Scale and ratio in Minecraft builds', 'gcse-foundation', 'ratio-proportion', 8, [ 'format' => 'gaming', 'theme' => 'minecraft' ] ),
			$L( 'Potion ratio maths', 'gcse-foundation', 'ratio-proportion', 6, [ 'format' => 'gaming', 'theme' => 'story', 'thumb' => 'thumb2-potion.jpg' ] ),
			// Quick Maths shorts (order as on the Quick Maths page)
			$L( 'Rounding decimals', 'gcse-foundation', 'number', 45, [ 'format' => 'short', 'thumb' => 'short-rounding.jpg' ] ),
			$L( 'Fractions in ascending order (Adopt Me)', 'gcse-foundation', 'number', 55, [ 'format' => 'short', 'thumb' => 'short-fractions.jpg' ] ),
			$L( 'The Halloween map', 'gcse-foundation', 'number', 50, [ 'format' => 'short', 'thumb' => 'short-halloween.jpg' ] ),
			$L( 'Nth term in Animal Hospital', 'gcse-foundation', 'algebra', 58, [ 'format' => 'short', 'thumb' => 'short-nthterm.jpg' ] ),
			$L( 'Inequalities in 99 Nights', 'gcse-higher', 'inequalities', 52, [ 'format' => 'short', 'thumb' => 'short-inequalities.jpg' ] ),
			$L( 'Completing the square', 'gcse-higher', 'solving-quadratics', 59, [ 'format' => 'short', 'thumb' => 'short-completingsq.jpg' ] ),
			$L( 'Adding vectors (Dress to Impress)', 'gcse-higher', 'geometry-measures', 48, [ 'format' => 'short', 'thumb' => 'short-addvectors.jpg' ] ),
			$L( 'Column vectors (Dress to Impress)', 'gcse-higher', 'geometry-measures', 47, [ 'format' => 'short', 'thumb' => 'short-colvectors.jpg' ] ),
		];
		return $rows;
	}

	/**
	 * Create (or fetch) a lesson post.
	 */
	private function upsert_lesson( array $r, string $date ): int {
		$id = $this->find_demo( 'mwm_lesson', $r['title'] );
		if ( ! $id ) {
			$id = (int) wp_insert_post( [
				'post_type'    => 'mwm_lesson',
				'post_status'  => 'publish',
				'post_title'   => $r['title'],
				'post_content' => $r['content'] ?? '',
				'post_date'    => $r['date'] ?? $date,
			] );
			update_post_meta( $id, self::DEMO_META, 1 );
		}
		if ( ! $id ) {
			return 0;
		}
		// Demo lessons carry no video ID: the thumbnails are Kym's real ones, but we don't know the video IDs, so the play button opens the channel.
		update_post_meta( $id, 'youtube_id', '' );
		update_post_meta( $id, 'youtube_url', '' );
		update_post_meta( $id, 'duration_seconds', (int) $r['seconds'] );
		wp_set_object_terms( $id, $r['level'], 'mwm_level' );
		wp_set_object_terms( $id, $r['format'], 'mwm_format' );
		if ( ! empty( $r['theme'] ) ) {
			wp_set_object_terms( $id, $r['theme'], 'mwm_theme' );
		}
		$topic = get_term_by( 'slug', $r['topic'], 'mwm_topic' );
		if ( $topic ) {
			wp_set_object_terms( $id, (int) $topic->term_id, 'mwm_topic' );
		}
		if ( ! empty( $r['thumb'] ) ) {
			$att = $this->sideload_image( $r['thumb'], $id );
			if ( $att ) {
				set_post_thumbnail( $id, $att );
			}
			delete_post_meta( $id, 'thumbnail_url' );
		} else {
			// No real thumbnail yet — the theme shows its "thumbnail to follow" placeholder.
			delete_post_thumbnail( $id );
			update_post_meta( $id, 'thumbnail_url', '' );
		}
		if ( ! empty( $r['worksheet'] ) ) {
			update_post_meta( $id, 'worksheet', $this->make_pdf( $r['title'] . ' — worksheet', (int) $r['worksheet'] * 1024, $id ) );
		}
		if ( ! empty( $r['answers'] ) ) {
			update_post_meta( $id, 'answers', $this->make_pdf( $r['title'] . ' — worked answers', (int) $r['answers'] * 1024, $id ) );
		}
		if ( ! empty( $r['quiz'] ) && ! mwm_lesson_quiz( $id ) ) {
			$this->generic_quiz( $id, $r['title'] );
		}
		delete_post_meta( $id, '_mwm_quiz_id' );
		return $id;
	}

	private function seed_lessons(): void {
		$base = strtotime( '2026-09-08 09:00:00' );
		$n    = 0;
		foreach ( $this->demo_lessons() as $r ) {
			$date = gmdate( 'Y-m-d H:i:s', $base - $n * 86400 * 2 );
			$n++;
			$id = $this->upsert_lesson( $r, $date );
			WP_CLI::log( sprintf( '  lesson #%d %s', $id, $r['title'] ) );
		}
	}

	private function generic_quiz( int $lesson_id, string $title ): int {
		$questions = [ 'questions' => [
			[ 'type' => 'choice', 'q' => 'Which step comes first when tackling a question on ' . lcfirst( $title ) . '?', 'options' => [ 'Write down what you know', 'Guess an answer', 'Skip to the end', 'Round everything to 1 s.f.' ], 'correct' => 0, 'explain' => 'Start by writing down the values and what the question is asking for.' ],
			[ 'type' => 'choice', 'q' => 'How many marks does showing your working usually earn on a 3-mark question?', 'options' => [ 'None', 'One or two', 'All three', 'Half a mark' ], 'correct' => 1, 'explain' => 'Method marks are awarded for correct working even when the final answer slips.' ],
			[ 'type' => 'order', 'q' => 'Put the steps in order.', 'options' => [ 'Check the answer makes sense', 'Read the question carefully', 'Do the calculation', 'Write down the method' ], 'correctOrder' => [ 1, 3, 2, 0 ], 'explain' => 'Read, plan, calculate, check.' ],
			[ 'type' => 'choice', 'q' => 'What should you include with a measurement answer?', 'options' => [ 'A colour', 'The units', 'A smiley face', 'Nothing extra' ], 'correct' => 1, 'explain' => 'Units are part of the answer — cm, cm² or cm³ all mean different things.' ],
			[ 'type' => 'choice', 'q' => 'If your answer looks unreasonable, what is the best next move?', 'options' => [ 'Leave it', 'Re-read the question and check the working', 'Change the units', 'Cross everything out' ], 'correct' => 1, 'explain' => 'A quick sense-check catches most slips.' ],
		] ];
		return $this->upsert_quiz( $lesson_id, $title, $questions );
	}

	private function upsert_quiz( int $lesson_id, string $title, array $questions ): int {
		$qid = $this->find_demo( 'mwm_quiz', $title . ' — quiz' );
		if ( ! $qid ) {
			$qid = (int) wp_insert_post( [ 'post_type' => 'mwm_quiz', 'post_status' => 'publish', 'post_title' => $title . ' — quiz' ] );
			update_post_meta( $qid, self::DEMO_META, 1 );
		}
		update_post_meta( $qid, 'lesson', $lesson_id );
		update_post_meta( $qid, 'questions', wp_json_encode( $questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		update_post_meta( $qid, 'estimated_minutes', 4 );
		delete_post_meta( $lesson_id, '_mwm_quiz_id' );
		return $qid;
	}

	private function seed_quiz(): void {
		$lesson = $this->find_demo( 'mwm_lesson', 'Quadratic simultaneous equations' );
		if ( ! $lesson ) {
			return;
		}
		$image = get_theme_file_uri( 'assets/img/quiz-circle-line.svg' );
		$questions = [ 'questions' => [
			[ 'type' => 'choice', 'q' => 'You’re solving x² + y² = 25 with y = 2x − 5. What does substituting give you?', 'options' => [ 'x² + (2x − 5)² = 25', 'x² + 2x − 5 = 25', '(x + 2x − 5)² = 25', 'x² − (2x − 5)² = 25' ], 'correct' => 0, 'explain' => 'Replace y with the whole bracket (2x − 5), then square it.' ],
			[ 'type' => 'order', 'q' => 'Put the four steps for solving the pair in order.', 'options' => [ 'Factorise and solve for x', 'Substitute the linear equation into the quadratic', 'Substitute each x back to find y', 'Expand and simplify into one equation in x' ], 'correctOrder' => [ 1, 3, 0, 2 ], 'explain' => 'Substitute first, tidy it into one equation, solve for x, then find each matching y.' ],
			[ 'type' => 'choice-image', 'q' => 'The graph shows both equations. How many solution pairs does this system have?', 'image' => 'The circle x² + y² = 25 and the line y = 2x − 5', 'imageUrl' => $image, 'imageAlt' => 'A circle centred on the origin with a straight line crossing it at two points', 'options' => [ '1', '2', '0', '4' ], 'correct' => 1, 'explain' => 'The line crosses the circle twice, and each crossing point is one (x, y) solution pair.' ],
			[ 'type' => 'choice', 'q' => 'Expand (2x − 5)².', 'options' => [ '4x² − 25', '2x² − 20x + 25', '4x² − 20x + 25', '4x² + 25' ], 'correct' => 2, 'explain' => 'Square term by term: (2x)² − 2 × (2x) × 5 + 5².' ],
			[ 'type' => 'choice', 'q' => 'Solve 5x² − 20x = 0.', 'options' => [ 'x = 4 only', 'x = 0 or x = −4', 'x = 0 or x = 5', 'x = 0 or x = 4' ], 'correct' => 3, 'explain' => 'Factorise: 5x(x − 4) = 0, so either factor can be zero.' ],
		] ];
		$qid = $this->upsert_quiz( $lesson, 'Quadratic simultaneous equations', $questions );
		WP_CLI::log( "  quiz #$qid attached to lesson #$lesson" );
	}

	private function seed_exam_dates(): void {
		$dates = [
			[ 'Paper 1 (non-calculator)', '2027-05-13' ],
			[ 'Paper 2 (calculator)', '2027-06-09' ],
			[ 'Paper 3 (calculator)', '2027-06-14' ],
		];
		foreach ( [ 'gcse-higher', 'gcse-foundation' ] as $level ) {
			foreach ( $dates as $i => [ $paper, $date ] ) {
				$title = $paper . ' · ' . mwm_level_name( $level );
				$id    = $this->find_demo( 'mwm_exam_date', $title );
				if ( ! $id ) {
					$id = (int) wp_insert_post( [ 'post_type' => 'mwm_exam_date', 'post_status' => 'publish', 'post_title' => $title, 'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '2026-09-01 09:00:00' ) + $i * 60 ) ] );
					update_post_meta( $id, self::DEMO_META, 1 );
				}
				wp_set_object_terms( $id, $level, 'mwm_level' );
				wp_set_object_terms( $id, 'edexcel', 'mwm_board' );
				update_post_meta( $id, 'paper_label', $paper );
				update_post_meta( $id, 'exam_date', $date );
				update_post_meta( $id, 'session', 'morning' );
				update_post_meta( $id, 'verified', 1 );
				update_post_meta( $id, 'verified_on', '2026-09-01' );
			}
		}
		WP_CLI::log( '  exam dates seeded' );
	}

	/**
	 * Pathway rows: [topic, worksheet, quiz, note, comingSoon]. Rows without a real lesson get a stub lesson.
	 */
	private function seed_pathways(): void {
		$higher = [
			'Number' => [
				[ 'Fractions, decimals and percentages', true, true ],
				[ 'Percentage change and reverse percentages', true, false ],
				[ 'Standard form', false, false ],
				[ 'Surds', true, true, 'Needs: Simplifying expressions' ],
				[ 'Bounds and error intervals', true, false ],
			],
			'Algebra' => [
				[ 'Expanding and factorising', true, true ],
				[ 'Solving linear equations', true, false ],
				[ 'Linear simultaneous equations', true, false ],
				[ 'Quadratic simultaneous equations', true, true, 'Needs: Linear simultaneous equations' ],
				[ 'Completing the square', false, false, '', false, 'Solving quadratics by completing the square' ],
				[ 'Inequalities', true, false ],
				[ 'Sequences and the nth term', true, true ],
				[ 'Algebraic proof', false, false, '', true ],
			],
			'Ratio & Proportion' => [
				[ 'Ratio and sharing in a ratio', true, true ],
				[ 'Direct and inverse proportion', true, false ],
				[ 'Compound measures', false, false ],
				[ 'Converting units', true, false ],
				[ 'Best buy problems', false, false ],
			],
			'Geometry & Measures' => [
				[ 'Angles and parallel lines', true, false ],
				[ 'Circumference and area of circles', true, false ],
				[ 'Volume of prisms and cuboids', false, true ],
				[ 'Pythagoras’ theorem', true, true ],
				[ 'Trigonometry in right-angled triangles', true, true, 'Needs: Pythagoras’ theorem' ],
				[ 'Sine and cosine rules', false, false ],
				[ 'Vectors', true, false ],
				[ 'Circle theorems', true, false ],
			],
			'Probability' => [
				[ 'Probability basics and sample spaces', true, false ],
				[ 'Tree diagrams', true, true ],
				[ 'Venn diagrams', false, false ],
			],
			'Statistics' => [
				[ 'Averages and spread', true, true ],
				[ 'Cumulative frequency and box plots', true, false ],
				[ 'Histograms', false, false ],
			],
		];
		$foundation = [
			'Number' => [
				[ 'Place value and rounding', true, false ],
				[ 'Fractions of amounts', true, true ],
				[ 'Percentages of amounts', true, false ],
				[ 'Rounding decimals', false, false ],
			],
			'Ratio & Proportion' => [
				[ 'Sharing in a ratio', true, false ],
				[ 'Converting miles and kilometres', true, false ],
			],
			'Geometry & Measures' => [
				[ 'Perimeter and area', true, true ],
				[ 'Circumference of a circle', true, false ],
				[ 'Volume of a cuboid', false, true ],
			],
			'Statistics' => [
				[ 'Averages from a list', true, false ],
			],
		];
		$plan = [
			[ '2027-05-03', 'Algebra', 'Algebra' ], [ '2027-05-10', 'Mixed practice', 'Mixed + P1' ], [ '2027-05-17', 'Ratio & Proportion', 'Ratio' ],
			[ '2027-05-24', 'Geometry & Measures', 'Geometry' ], [ '2027-05-31', 'Probability & Statistics', 'Prob & Stats' ], [ '2027-06-07', 'Past papers', 'Past papers' ], [ '2027-06-14', 'Rest and light recap', 'Rest' ],
		];
		$this->upsert_pathway( 'GCSE Higher revision pathway', 'gcse-higher', $higher, true, $plan );
		$this->upsert_pathway( 'GCSE Foundation revision pathway', 'gcse-foundation', $foundation, false, $plan );
		WP_CLI::log( '  pathways seeded' );
	}

	private function topic_slug_for_group( string $group ): string {
		$map = [ 'Number' => 'number', 'Algebra' => 'algebra', 'Ratio & Proportion' => 'ratio-proportion', 'Geometry & Measures' => 'geometry-measures', 'Probability' => 'probability', 'Statistics' => 'statistics' ];
		return $map[ $group ] ?? 'number';
	}

	private function upsert_pathway( string $title, string $level, array $groups, bool $complete, array $plan ): void {
		$id = $this->find_demo( 'mwm_pathway', $title );
		if ( ! $id ) {
			$id = (int) wp_insert_post( [ 'post_type' => 'mwm_pathway', 'post_status' => 'publish', 'post_title' => $title ] );
			update_post_meta( $id, self::DEMO_META, 1 );
		}
		wp_set_object_terms( $id, $level, 'mwm_level' );
		wp_set_object_terms( $id, [], 'mwm_board' );
		update_post_meta( $id, 'complete', $complete ? 1 : 0 );
		$field_groups = [];
		$stub_date = strtotime( '2026-06-01 09:00:00' );
		foreach ( $groups as $name => $rows ) {
			$frows = [];
			foreach ( $rows as $row ) {
				[ $topic, $ws, $quiz ] = $row;
				$note = $row[3] ?? '';
				$soon = $row[4] ?? false;
				$lesson_title = $row[5] ?? $topic;
				$lesson_id = 0;
				if ( ! $soon ) {
					$lesson_id = $this->find_demo( 'mwm_lesson', $lesson_title );
					if ( ! $lesson_id ) {
						$stub_date -= 86400;
						$lesson_id = $this->upsert_lesson( [
							'title' => $lesson_title, 'level' => $level, 'topic' => $this->topic_slug_for_group( $name ), 'seconds' => 480, 'format' => 'lesson',
							'worksheet' => $ws ? 200 : 0, 'quiz' => $quiz,
						], gmdate( 'Y-m-d H:i:s', $stub_date ) );
					} else {
						// Real lesson: make sure its resources match the pathway row.
						if ( $ws && ! get_post_meta( $lesson_id, 'worksheet', true ) ) {
							update_post_meta( $lesson_id, 'worksheet', $this->make_pdf( $lesson_title . ' — worksheet', 200 * 1024, $lesson_id ) );
						}
						if ( $quiz && ! mwm_lesson_quiz( $lesson_id ) ) {
							$this->generic_quiz( $lesson_id, $lesson_title );
						}
					}
				}
				$frows[] = [ 'topic' => $topic, 'lesson' => $lesson_id ?: '', 'note' => $note, 'coming_soon' => $soon ? 1 : 0 ];
			}
			$field_groups[] = [ 'name' => $name, 'rows' => $frows ];
		}
		update_field( 'field_mwm_pw_groups', $field_groups, $id );
		update_field( 'field_mwm_pw_plan', array_map( static fn( $w ) => [ 'week_commencing' => $w[0], 'focus' => $w[1], 'short' => $w[2] ?? '' ], $plan ), $id );
	}

	private function seed_past_papers(): void {
		$papers = [
			[ 'June', 2025, 1, false, 1.1, 640, [ 'higher' => [ 'Linear simultaneous equations', 'Surds' ], 'foundation' => [ 'Fractions of amounts', 'Perimeter and area' ] ] ],
			[ 'June', 2025, 2, true, 1.3, 710, [ 'higher' => [ 'Percentage change and reverse percentages', 'Tree diagrams' ], 'foundation' => [ 'Percentages of amounts', 'Averages from a list' ] ] ],
			[ 'June', 2025, 3, true, 1.2, 580, [ 'higher' => [ 'Vectors', 'Histograms' ], 'foundation' => [ 'Volume of a cuboid', 'Sharing in a ratio' ] ] ],
			[ 'November', 2024, 1, false, 1.0, 620, [ 'higher' => [ 'Expanding and factorising', 'Standard form' ], 'foundation' => [ 'Place value and rounding' ] ] ],
			[ 'November', 2024, 2, true, 1.2, 660, [ 'higher' => [ 'Trigonometry in right-angled triangles', 'Direct and inverse proportion' ], 'foundation' => [ 'Converting units' ] ] ],
			[ 'November', 2024, 3, true, 1.1, 0, [ 'higher' => [ 'Circle theorems', 'Cumulative frequency and box plots' ], 'foundation' => [ 'Probability basics and sample spaces' ] ] ],
			[ 'June', 2024, 1, false, 1.1, 600, [ 'higher' => [ 'Inequalities', 'Bounds and error intervals' ], 'foundation' => [ 'Rounding decimals' ] ] ],
			[ 'June', 2024, 2, true, 1.2, 690, [ 'higher' => [ 'Quadratic simultaneous equations', 'Sine and cosine rules' ], 'foundation' => [ 'Circumference of a circle' ] ] ],
			[ 'June', 2024, 3, true, 1.0, 570, [ 'higher' => [ 'Pythagoras’ theorem', 'Venn diagrams' ], 'foundation' => [ 'Area of equal shapes' ] ] ],
		];
		$n = 0;
		foreach ( [ 'higher', 'foundation' ] as $tier ) {
			foreach ( $papers as [ $season, $year, $num, $calc, $qp_mb, $ms_kb, $ws ] ) {
				$title = "$season $year · Paper $num (" . ( $calc ? 'calculator' : 'non-calculator' ) . ') · ' . ucfirst( $tier );
				$id    = $this->find_demo( 'mwm_past_paper', $title );
				if ( ! $id ) {
					$id = (int) wp_insert_post( [ 'post_type' => 'mwm_past_paper', 'post_status' => 'publish', 'post_title' => $title, 'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '2026-09-03 09:00:00' ) - $n * 3600 ) ] );
					update_post_meta( $id, self::DEMO_META, 1 );
				}
				$n++;
				wp_set_object_terms( $id, 'edexcel', 'mwm_board' );
				update_post_meta( $id, 'tier', $tier );
				update_post_meta( $id, 'series_season', $season );
				update_post_meta( $id, 'series_year', $year );
				update_post_meta( $id, 'paper_number', $num );
				update_post_meta( $id, 'calculator', $calc ? 1 : 0 );
				update_post_meta( $id, 'qualification_code', '1MA1' );
				update_post_meta( $id, 'marks', 80 );
				update_post_meta( $id, 'duration_minutes', 90 );
				update_post_meta( $id, 'question_paper', $this->make_pdf( "$title — question paper", (int) ( $qp_mb * 1048576 ), $id ) );
				if ( $ms_kb ) {
					update_post_meta( $id, 'mark_scheme', $this->make_pdf( "$title — mark scheme", $ms_kb * 1024, $id ) );
				} else {
					delete_post_meta( $id, 'mark_scheme' );
				}
				$ids = [];
				foreach ( $ws[ $tier ] as $lesson_title ) {
					$lid = $this->find_demo( 'mwm_lesson', $lesson_title );
					if ( $lid ) {
						$ids[] = $lid;
					}
				}
				update_post_meta( $id, 'worksheets', $ids );
			}
		}
		WP_CLI::log( '  past papers seeded' );
	}

	/**
	 * Point the home hero at the Quadratic simultaneous equations lesson, as in the prototype.
	 */
	private function seed_home_featured(): void {
		$pages  = (array) get_option( 'mwm_pages', [] );
		$lesson = $this->find_demo( 'mwm_lesson', 'Quadratic simultaneous equations' );
		if ( empty( $pages['home'] ) || ! $lesson ) {
			return;
		}
		$content = get_post_field( 'post_content', $pages['home'] );
		$new     = '<!-- wp:acf/home-hero {"name":"acf/home-hero","data":{"featured_lesson":' . $lesson . ',"_featured_lesson":"field_mwm_blk_hero_featured"},"mode":"preview"} /-->';
		$content = preg_replace( '/<!-- wp:acf\/home-hero(?: \{.*?\})? \/-->/', $new, $content, 1 );
		wp_update_post( [ 'ID' => $pages['home'], 'post_content' => $content ] );
	}

	/* ---------------------------------------------------------------
	 * Import from the old site
	 * ------------------------------------------------------------ */

	/**
	 * Import lessons (title, YouTube ID, level, topic, worksheet + answers PDFs) from the old site.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<url>]
	 * : Old site base URL, read through its REST API (e.g. https://mathswithmelissa.co.uk).
	 *
	 * [--file=<path>]
	 * : A WordPress export (WXR .xml) or a JSON file with an array of lessons instead of the REST API.
	 *
	 * [--post-type=<slug>]
	 * : REST route/post type slug for lessons on the old site. Default: lesson.
	 *
	 * [--level-tax=<slug>]
	 * : Old taxonomy holding the level (auto-detected if omitted).
	 *
	 * [--topic-tax=<slug>]
	 * : Old taxonomy holding the topic (auto-detected if omitted).
	 *
	 * [--limit=<n>]
	 * : Only import the first n lessons.
	 *
	 * [--dry-run]
	 * : Show what would be imported without writing anything.
	 *
	 * [--update]
	 * : Update lessons already imported (matched on the old post ID).
	 *
	 * [--no-media]
	 * : Skip downloading PDFs (links are kept in the source_url meta for a later run).
	 *
	 * [--no-scrape]
	 * : Don't read each lesson's public page for the video and PDFs (used when the API/export doesn't carry them).
	 *
	 * [--default-level=<slug>]
	 * : Level to use when none can be worked out (gcse-foundation, gcse-higher or a-level). Default: leave unset.
	 *
	 * @subcommand import-lessons
	 * @when after_wp_load
	 */
	public function import_lessons( array $args, array $assoc ): void {
		require_once MWM_CORE_DIR . 'includes/cli/class-importer.php';
		$importer = new MWM_Importer( $assoc );
		$importer->run();
	}

	/**
	 * Move worksheet PDFs that sit directly on lessons (the original data model) onto worksheet posts.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would change.
	 *
	 * @subcommand migrate-worksheets
	 * @when after_wp_load
	 */
	public function migrate_worksheets( array $args, array $assoc ): void {
		$ids = get_posts( [ 'post_type' => 'mwm_lesson', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => 'worksheet' ] );
		$made = 0;
		foreach ( $ids as $id ) {
			$pdf = (int) get_post_meta( $id, 'worksheet', true );
			$ans = (int) get_post_meta( $id, 'answers', true );
			if ( ! $pdf || get_post_type( $pdf ) !== 'attachment' ) {
				continue;
			}
			if ( isset( $assoc['dry-run'] ) ) {
				WP_CLI::log( sprintf( '  #%d %s → worksheet (pdf #%d%s)', $id, get_the_title( $id ), $pdf, $ans ? ", answers #$ans" : '' ) );
				continue;
			}
			$ws = mwm_upsert_worksheet( $id, $pdf, $ans );
			if ( $ws ) {
				delete_post_meta( $id, 'worksheet' );
				delete_post_meta( $id, 'answers' );
				$made++;
				WP_CLI::log( sprintf( '  #%d %s → worksheet #%d %s', $id, get_the_title( $id ), $ws, get_permalink( $ws ) ) );
			}
		}
		WP_CLI::success( sprintf( '%d lessons looked at, %d worksheet pages created.', count( $ids ), $made ) );
	}

	/**
	 * Pull the old site's worksheet pages (titles, slugs, topics) and match them to the worksheets we already hold
	 * by PDF, so the old /worksheets/{slug}/ URLs redirect and titles match what students knew.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<url>]
	 * : Old site base URL. Default: https://mathswithmelissa.co.uk
	 *
	 * [--dry-run]
	 * : Show matches without changing anything.
	 *
	 * @subcommand import-worksheets
	 * @when after_wp_load
	 */
	public function import_worksheets( array $args, array $assoc ): void {
		require_once MWM_CORE_DIR . 'includes/cli/class-importer.php';
		$importer = new MWM_Importer( array_merge( [ 'source' => 'https://mathswithmelissa.co.uk' ], $assoc ) );
		$importer->run_worksheets();
	}

	/**
	 * Build the 301 redirect map from imported lessons.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : option (save to the plugin), nginx, apache, csv or json. Default: option.
	 *
	 * @subcommand redirect-map
	 * @when after_wp_load
	 */
	public function redirect_map( array $args, array $assoc ): void {
		$map    = MWM_Redirects::build_map();
		$format = $assoc['format'] ?? 'option';
		if ( ! $map ) {
			WP_CLI::warning( 'No imported lessons found (nothing has a source_id yet).' );
		}
		switch ( $format ) {
			case 'nginx':
				foreach ( $map as $from => $to ) {
					WP_CLI::line( "location = {$from} { return 301 {$to}; }" );
				}
				break;
			case 'apache':
				foreach ( $map as $from => $to ) {
					WP_CLI::line( "Redirect 301 {$from} {$to}" );
				}
				break;
			case 'csv':
				WP_CLI::line( 'old_path,new_path' );
				foreach ( $map as $from => $to ) {
					WP_CLI::line( "{$from},{$to}" );
				}
				break;
			case 'json':
				WP_CLI::line( wp_json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				break;
			default:
				update_option( 'mwm_redirect_map', $map );
				WP_CLI::success( sprintf( 'Saved %d redirects to the plugin settings (Settings → Maths with Melissa).', count( $map ) ) );
		}
	}

	/**
	 * Re-run level/topic auto-tagging on synced videos that still have no topic or level.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Also re-check lessons that already have a topic (never overwrites an existing term).
	 *
	 * @when after_wp_load
	 */
	public function retag( array $args, array $assoc ): void {
		$ids = get_posts( [ 'post_type' => 'mwm_lesson', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => 'playlist_id' ] );
		$tagged = 0;
		foreach ( $ids as $id ) {
			if ( ! isset( $assoc['all'] ) && has_term( '', 'mwm_topic', $id ) && has_term( '', 'mwm_level', $id ) ) {
				continue;
			}
			$before = has_term( '', 'mwm_topic', $id );
			MWM_YouTube::auto_tag( $id, [ 'title' => get_the_title( $id ), 'tags' => [], 'description' => get_post_field( 'post_content', $id ) ] );
			if ( ! $before && has_term( '', 'mwm_topic', $id ) ) {
				$tagged++;
			}
		}
		$untagged = count( array_filter( $ids, static fn( $id ) => ! has_term( '', 'mwm_topic', $id ) ) );
		WP_CLI::success( sprintf( 'Checked %d synced videos: %d newly tagged, %d still without a topic.', count( $ids ), $tagged, $untagged ) );
	}

	/**
	 * Run the YouTube playlist sync now.
	 *
	 * @when after_wp_load
	 */
	public function sync( array $args, array $assoc ): void {
		$state = MWM_YouTube::sync_all();
		if ( ! empty( $state['error'] ) ) {
			WP_CLI::warning( $state['error'] );
		}
		WP_CLI::success( $state['summary'] ?? 'Done.' );
	}
}

WP_CLI::add_command( 'mwm', 'MWM_CLI' );
