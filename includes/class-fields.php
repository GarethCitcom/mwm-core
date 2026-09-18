<?php
/**
 * ACF Pro field groups, registered in code.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Fields {

	public static function init(): void {
		add_action( 'acf/init', [ __CLASS__, 'register' ] );
		add_filter( 'acf/settings/show_admin', '__return_true' );
		add_filter( 'acf/fields/post_object/query/name=lesson', [ __CLASS__, 'lesson_query' ] );
		add_filter( 'acf/fields/relationship/query/name=worksheets', [ __CLASS__, 'worksheet_query' ] );
	}

	public static function lesson_query( array $args ): array {
		$args['post_status'] = [ 'publish', 'draft', 'pending' ];
		return $args;
	}

	public static function worksheet_query( array $args ): array {
		$args['post_status'] = [ 'publish' ];
		return $args;
	}

	private static function tax_field( string $key, string $name, string $label, string $taxonomy, string $type = 'radio', string $instructions = '', bool $required = false ): array {
		return [
			'key'           => $key,
			'label'         => $label,
			'name'          => $name,
			'type'          => 'taxonomy',
			'taxonomy'      => $taxonomy,
			'field_type'    => $type,
			'add_term'      => false,
			'save_terms'    => true,
			'load_terms'    => true,
			'return_format' => 'id',
			'multiple'      => false,
			'allow_null'    => ! $required,
			'required'      => $required ? 1 : 0,
			'instructions'  => $instructions,
		];
	}

	public static function register(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		/* ---------------------------------------------------------------
		 * Lesson
		 * ------------------------------------------------------------ */
		acf_add_local_field_group( [
			'key'      => 'group_mwm_lesson',
			'title'    => 'Lesson',
			'fields'   => [
				[
					'key'          => 'field_mwm_lesson_youtube_url',
					'label'        => 'YouTube link',
					'name'         => 'youtube_url',
					'type'         => 'url',
					'instructions' => 'Paste the video link from YouTube (the Share button).',
					'required'     => 0,
				],
				[
					'key'          => 'field_mwm_lesson_youtube_id',
					'label'        => 'YouTube video ID',
					'name'         => 'youtube_id',
					'type'         => 'text',
					'instructions' => 'Filled in automatically from the link.',
					'maxlength'    => 11,
				],
				[
					'key'          => 'field_mwm_lesson_duration',
					'label'        => 'Length (seconds)',
					'name'         => 'duration_seconds',
					'type'         => 'number',
					'min'          => 0,
					'step'         => 1,
					'instructions' => 'Shown as “9 min” on lessons and “0:45” on shorts.',
				],
				[
					'key'          => 'field_mwm_lesson_thumbnail_url',
					'label'        => 'Thumbnail URL',
					'name'         => 'thumbnail_url',
					'type'         => 'url',
					'instructions' => 'Usually the YouTube thumbnail. A featured image, if set, takes priority.',
				],
				self::tax_field( 'field_mwm_lesson_level', 'level', 'Level', 'mwm_level', 'radio', '', true ),
				[
					'key'           => 'field_mwm_lesson_topic',
					'label'         => 'Topic',
					'name'          => 'topic',
					'type'          => 'taxonomy',
					'taxonomy'      => 'mwm_topic',
					'field_type'    => 'select',
					'add_term'      => false,
					'save_terms'    => true,
					'load_terms'    => true,
					'return_format' => 'id',
					'allow_null'    => 1,
					'instructions'  => 'Pick the topic, or a subtopic inside it.',
				],
				self::tax_field( 'field_mwm_lesson_format', 'format', 'Format', 'mwm_format', 'radio', 'Lessons are added by hand. Shorts and gaming videos come from the YouTube playlists.', true ),
				self::tax_field( 'field_mwm_lesson_theme', 'game_theme', 'Game theme', 'mwm_theme', 'radio', 'Only for Gaming & Story videos.' ),
				[
					'key'           => 'field_mwm_lesson_worksheet_post',
					'label'         => 'Worksheet',
					'name'          => 'worksheet_post',
					'type'          => 'post_object',
					'post_type'     => [ 'mwm_worksheet' ],
					'return_format' => 'id',
					'allow_null'    => 1,
					'instructions'  => 'The worksheet page for this lesson (holds the PDF and worked answers). Without one, the lesson page says “No worksheet for this lesson yet.”',
				],
				[
					'key'          => 'field_mwm_lesson_source_id',
					'label'        => 'Old site post ID',
					'name'         => 'source_id',
					'type'         => 'number',
					'instructions' => 'Set by the import. Used for 301 redirects from the old /lesson/{id}/ URLs.',
				],
				[
					'key'          => 'field_mwm_lesson_source_url',
					'label'        => 'Old site URL',
					'name'         => 'source_url',
					'type'         => 'url',
				],
				[
					'key'          => 'field_mwm_lesson_playlist',
					'label'        => 'Synced from playlist',
					'name'         => 'playlist_id',
					'type'         => 'text',
					'instructions' => 'Set by the YouTube sync. Leave blank for lessons added by hand.',
				],
			],
			'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'mwm_lesson' ] ] ],
			'position' => 'acf_after_title',
		] );

		/* ---------------------------------------------------------------
		 * Worksheet
		 * ------------------------------------------------------------ */
		acf_add_local_field_group( [
			'key'      => 'group_mwm_worksheet',
			'title'    => 'Worksheet',
			'fields'   => [
				[
					'key'           => 'field_mwm_ws_pdf',
					'label'         => 'Worksheet PDF',
					'name'          => 'pdf',
					'type'          => 'file',
					'return_format' => 'id',
					'library'       => 'all',
					'mime_types'    => 'pdf',
					'required'      => 1,
					'instructions'  => 'Shown on the worksheet page and offered for download.',
				],
				[
					'key'           => 'field_mwm_ws_answers',
					'label'         => 'Worked answers PDF',
					'name'          => 'answers',
					'type'          => 'file',
					'return_format' => 'id',
					'library'       => 'all',
					'mime_types'    => 'pdf',
					'instructions'  => 'Optional. Appears behind “Reveal answers”.',
				],
				[
					'key'           => 'field_mwm_ws_lesson',
					'label'         => 'Lesson',
					'name'          => 'lesson',
					'type'          => 'post_object',
					'post_type'     => [ 'mwm_lesson' ],
					'return_format' => 'id',
					'allow_null'    => 1,
					'instructions'  => 'The lesson this worksheet practises. Level and topic are copied from it if left blank.',
				],
				self::tax_field( 'field_mwm_ws_level', 'level', 'Level', 'mwm_level', 'radio' ),
				[
					'key'           => 'field_mwm_ws_topic',
					'label'         => 'Topic',
					'name'          => 'topic',
					'type'          => 'taxonomy',
					'taxonomy'      => 'mwm_topic',
					'field_type'    => 'select',
					'add_term'      => false,
					'save_terms'    => true,
					'load_terms'    => true,
					'return_format' => 'id',
					'allow_null'    => 1,
				],
				[
					'key'   => 'field_mwm_ws_source_id',
					'label' => 'Old site post ID',
					'name'  => 'source_id',
					'type'  => 'number',
				],
				[
					'key'   => 'field_mwm_ws_source_url',
					'label' => 'Old site URL',
					'name'  => 'source_url',
					'type'  => 'url',
				],
			],
			'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'mwm_worksheet' ] ] ],
			'position' => 'acf_after_title',
		] );

		/* ---------------------------------------------------------------
		 * Quiz
		 * ------------------------------------------------------------ */
		acf_add_local_field_group( [
			'key'      => 'group_mwm_quiz',
			'title'    => 'Quiz',
			'fields'   => [
				[
					'key'           => 'field_mwm_quiz_lesson',
					'label'         => 'Lesson',
					'name'          => 'lesson',
					'type'          => 'post_object',
					'post_type'     => [ 'mwm_lesson' ],
					'return_format' => 'id',
					'allow_null'    => 1,
					'required'      => 1,
					'instructions'  => 'The lesson this quiz sits on.',
				],
				[
					'key'          => 'field_mwm_quiz_questions',
					'label'        => 'Questions (JSON)',
					'name'         => 'questions',
					'type'         => 'textarea',
					'rows'         => 18,
					'new_lines'    => '',
					'instructions' => 'Schema: {"questions":[{"type":"choice"|"choice-image"|"order","q":"…","options":["…"],"correct":0,"correctOrder":[…],"explain":"…","image":"…","optionImages":[…]}]}. Validated on save.',
				],
				[
					'key'          => 'field_mwm_quiz_minutes',
					'label'        => 'About how many minutes',
					'name'         => 'estimated_minutes',
					'type'         => 'number',
					'min'          => 1,
					'instructions' => 'Leave blank to estimate from the number of questions.',
				],
			],
			'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'mwm_quiz' ] ] ],
		] );

		/* ---------------------------------------------------------------
		 * Past paper
		 * ------------------------------------------------------------ */
		acf_add_local_field_group( [
			'key'      => 'group_mwm_past_paper',
			'title'    => 'Past paper',
			'fields'   => [
				self::tax_field( 'field_mwm_pp_board', 'board', 'Exam board', 'mwm_board', 'radio', '', true ),
				[
					'key'           => 'field_mwm_pp_tier',
					'label'         => 'Tier',
					'name'          => 'tier',
					'type'          => 'radio',
					'choices'       => [ 'foundation' => 'Foundation', 'higher' => 'Higher' ],
					'default_value' => 'higher',
					'layout'        => 'horizontal',
					'required'      => 1,
				],
				[
					'key'           => 'field_mwm_pp_season',
					'label'         => 'Exam series',
					'name'          => 'series_season',
					'type'          => 'radio',
					'choices'       => [ 'June' => 'June', 'November' => 'November' ],
					'default_value' => 'June',
					'layout'        => 'horizontal',
					'required'      => 1,
				],
				[
					'key'      => 'field_mwm_pp_year',
					'label'    => 'Year',
					'name'     => 'series_year',
					'type'     => 'number',
					'min'      => 2015,
					'max'      => 2040,
					'required' => 1,
				],
				[
					'key'           => 'field_mwm_pp_number',
					'label'         => 'Paper',
					'name'          => 'paper_number',
					'type'          => 'radio',
					'choices'       => [ 1 => 'Paper 1', 2 => 'Paper 2', 3 => 'Paper 3' ],
					'default_value' => 1,
					'layout'        => 'horizontal',
					'required'      => 1,
				],
				[
					'key'           => 'field_mwm_pp_calc',
					'label'         => 'Calculator paper',
					'name'          => 'calculator',
					'type'          => 'true_false',
					'ui'            => 1,
					'default_value' => 0,
				],
				[
					'key'           => 'field_mwm_pp_code',
					'label'         => 'Qualification code',
					'name'          => 'qualification_code',
					'type'          => 'text',
					'default_value' => '1MA1',
				],
				[
					'key'           => 'field_mwm_pp_marks',
					'label'         => 'Marks',
					'name'          => 'marks',
					'type'          => 'number',
					'default_value' => 80,
				],
				[
					'key'           => 'field_mwm_pp_minutes',
					'label'         => 'Length (minutes)',
					'name'          => 'duration_minutes',
					'type'          => 'number',
					'default_value' => 90,
				],
				[
					'key'           => 'field_mwm_pp_qp',
					'label'         => 'Question paper PDF',
					'name'          => 'question_paper',
					'type'          => 'file',
					'return_format' => 'id',
					'mime_types'    => 'pdf',
					'required'      => 1,
				],
				[
					'key'           => 'field_mwm_pp_ms',
					'label'         => 'Mark scheme PDF',
					'name'          => 'mark_scheme',
					'type'          => 'file',
					'return_format' => 'id',
					'mime_types'    => 'pdf',
					'instructions'  => 'Can come later — until then the site says “Mark scheme coming soon”.',
				],
				[
					'key'           => 'field_mwm_pp_worksheets',
					'label'         => 'Practise what came up',
					'name'          => 'worksheets',
					'type'          => 'relationship',
					'post_type'     => [ 'mwm_worksheet' ],
					'return_format' => 'id',
					'filters'       => [ 'search', 'taxonomy' ],
					'instructions'  => 'Worksheets that match what came up in this paper.',
				],
			],
			'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'mwm_past_paper' ] ] ],
		] );

		/* ---------------------------------------------------------------
		 * Exam date
		 * ------------------------------------------------------------ */
		acf_add_local_field_group( [
			'key'      => 'group_mwm_exam_date',
			'title'    => 'Exam date',
			'fields'   => [
				self::tax_field( 'field_mwm_ed_board', 'board', 'Exam board', 'mwm_board', 'radio', '', true ),
				self::tax_field( 'field_mwm_ed_level', 'level', 'Level', 'mwm_level', 'radio', '', true ),
				[
					'key'           => 'field_mwm_ed_paper',
					'label'         => 'Paper',
					'name'          => 'paper_label',
					'type'          => 'text',
					'default_value' => 'Paper 1 (non-calculator)',
					'required'      => 1,
				],
				[
					'key'            => 'field_mwm_ed_date',
					'label'          => 'Date',
					'name'           => 'exam_date',
					'type'           => 'date_picker',
					'display_format' => 'D j F Y',
					'return_format'  => 'Y-m-d',
					'first_day'      => 1,
					'required'       => 1,
				],
				[
					'key'           => 'field_mwm_ed_session',
					'label'         => 'Session',
					'name'          => 'session',
					'type'          => 'radio',
					'choices'       => [ 'morning' => 'Morning', 'afternoon' => 'Afternoon' ],
					'default_value' => 'morning',
					'layout'        => 'horizontal',
				],
				[
					'key'           => 'field_mwm_ed_verified',
					'label'         => 'Checked against the official timetable',
					'name'          => 'verified',
					'type'          => 'true_false',
					'ui'            => 1,
					'instructions'  => 'Only verified dates show on the site.',
				],
				[
					'key'            => 'field_mwm_ed_verified_on',
					'label'          => 'Verified on',
					'name'           => 'verified_on',
					'type'           => 'date_picker',
					'display_format' => 'j F Y',
					'return_format'  => 'Y-m-d',
					'first_day'      => 1,
				],
			],
			'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'mwm_exam_date' ] ] ],
		] );

		/* ---------------------------------------------------------------
		 * Pathway
		 * ------------------------------------------------------------ */
		acf_add_local_field_group( [
			'key'      => 'group_mwm_pathway',
			'title'    => 'Revision pathway',
			'fields'   => [
				self::tax_field( 'field_mwm_pw_level', 'level', 'Level', 'mwm_level', 'radio', '', true ),
				[
					'key'           => 'field_mwm_pw_board',
					'label'         => 'Exam boards',
					'name'          => 'boards',
					'type'          => 'taxonomy',
					'taxonomy'      => 'mwm_board',
					'field_type'    => 'checkbox',
					'add_term'      => false,
					'save_terms'    => true,
					'load_terms'    => true,
					'return_format' => 'id',
					'instructions'  => 'Leave empty if the same pathway applies to every board.',
				],
				[
					'key'           => 'field_mwm_pw_complete',
					'label'         => 'Topic list is complete',
					'name'          => 'complete',
					'type'          => 'true_false',
					'ui'            => 1,
					'instructions'  => 'On: “32 topics in order”. Off: “12 topics so far · more being added”.',
				],
				[
					'key'          => 'field_mwm_pw_groups',
					'label'        => 'Topic groups',
					'name'         => 'groups',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => 'Add a group',
					'sub_fields'   => [
						[
							'key'   => 'field_mwm_pw_group_name',
							'label' => 'Group name',
							'name'  => 'name',
							'type'  => 'text',
							'required' => 1,
						],
						[
							'key'          => 'field_mwm_pw_rows',
							'label'        => 'Topics in order',
							'name'         => 'rows',
							'type'         => 'repeater',
							'layout'       => 'table',
							'button_label' => 'Add a topic',
							'sub_fields'   => [
								[
									'key'   => 'field_mwm_pw_row_topic',
									'label' => 'Topic',
									'name'  => 'topic',
									'type'  => 'text',
									'instructions' => 'Leave blank to use the lesson title.',
								],
								[
									'key'           => 'field_mwm_pw_row_lesson',
									'label'         => 'Lesson',
									'name'          => 'lesson',
									'type'          => 'post_object',
									'post_type'     => [ 'mwm_lesson' ],
									'return_format' => 'id',
									'allow_null'    => 1,
								],
								[
									'key'   => 'field_mwm_pw_row_note',
									'label' => 'Prerequisite note',
									'name'  => 'note',
									'type'  => 'text',
									'placeholder' => 'Needs: Linear simultaneous equations',
								],
								[
									'key'   => 'field_mwm_pw_row_soon',
									'label' => 'Coming soon',
									'name'  => 'coming_soon',
									'type'  => 'true_false',
									'ui'    => 1,
								],
							],
						],
					],
				],
				[
					'key'          => 'field_mwm_pw_plan',
					'label'        => 'Suggested revision plan',
					'name'         => 'revision_plan',
					'type'         => 'repeater',
					'layout'       => 'table',
					'button_label' => 'Add a week',
					'instructions' => 'Shown on the exam calendar as shaded weeks and the week-by-week list.',
					'sub_fields'   => [
						[
							'key'            => 'field_mwm_pw_plan_week',
							'label'          => 'Week commencing',
							'name'           => 'week_commencing',
							'type'           => 'date_picker',
							'display_format' => 'D j F Y',
							'return_format'  => 'Y-m-d',
							'first_day'      => 1,
						],
						[
							'key'   => 'field_mwm_pw_plan_focus',
							'label' => 'Focus',
							'name'  => 'focus',
							'type'  => 'text',
						],
						[
							'key'   => 'field_mwm_pw_plan_short',
							'label' => 'Calendar label',
							'name'  => 'short',
							'type'  => 'text',
							'instructions' => 'Short version for the calendar cell, e.g. “Prob & Stats”.',
						],
					],
				],
			],
			'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'mwm_pathway' ] ] ],
		] );

		/* ---------------------------------------------------------------
		 * Topic term meta
		 * ------------------------------------------------------------ */
		acf_add_local_field_group( [
			'key'      => 'group_mwm_topic_term',
			'title'    => 'Topic settings',
			'fields'   => [
				[
					'key'     => 'field_mwm_topic_icon',
					'label'   => 'Chip icon',
					'name'    => 'icon',
					'type'    => 'select',
					'choices' => [ '' => 'None', 'number' => 'Number', 'algebra' => 'Algebra', 'ratio' => 'Ratio', 'geometry' => 'Geometry', 'probability' => 'Probability', 'statistics' => 'Statistics', 'pure' => 'Pure', 'mechanics' => 'Mechanics' ],
				],
				[
					'key'     => 'field_mwm_topic_levels',
					'label'   => 'Applies to levels',
					'name'    => 'levels',
					'type'    => 'checkbox',
					'choices' => [ 'gcse-foundation' => 'GCSE Foundation', 'gcse-higher' => 'GCSE Higher', 'a-level' => 'A-level' ],
				],
				[
					'key'   => 'field_mwm_topic_order',
					'label' => 'Order',
					'name'  => 'order',
					'type'  => 'number',
				],
				[
					'key'          => 'field_mwm_topic_intro',
					'label'        => 'Browse intro',
					'name'         => 'intro',
					'type'         => 'textarea',
					'rows'         => 3,
					'instructions' => 'Shown under the topic heading on Learn Maths.',
				],
				[
					'key'          => 'field_mwm_topic_keywords',
					'label'        => 'Keywords for auto-tagging',
					'name'         => 'keywords',
					'type'         => 'text',
					'instructions' => 'Comma-separated. The YouTube sync uses these to tag shorts and gaming videos with a topic.',
				],
			],
			'location' => [ [ [ 'param' => 'taxonomy', 'operator' => '==', 'value' => 'mwm_topic' ] ] ],
		] );
	}
}
