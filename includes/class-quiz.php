<?php
/**
 * Quiz JSON schema: validation (plain-English errors), storage and the ChatGPT prompt.
 *
 * Schema:
 * {"questions":[
 *   {"type":"choice","q":"…","options":["…"],"correct":0,"explain":"…"},
 *   {"type":"choice-image","q":"…","image":"description or URL","options":["…"],"correct":1,"explain":"…","optionImages":["url",…]},
 *   {"type":"order","q":"…","options":["…"],"correctOrder":[1,3,0,2],"explain":"…"}
 * ]}
 */

defined( 'ABSPATH' ) || exit;

class MWM_Quiz {

	public const TYPES = [ 'choice', 'choice-image', 'order' ];

	public static function init(): void {
		add_filter( 'acf/validate_value/name=questions', [ __CLASS__, 'acf_validate' ], 10, 2 );
	}

	public static function acf_validate( $valid, $value ) {
		if ( $valid !== true || $value === '' ) {
			return $valid;
		}
		$result = self::validate( (string) $value );
		return isset( $result['error'] ) ? $result['error'] : $valid;
	}

	/**
	 * The instructions Kym copies into ChatGPT (verbatim from the prototype).
	 */
	public static function prompt(): string {
		return 'Write a 5-question GCSE maths quiz about [YOUR TOPIC] for [GCSE Foundation or GCSE Higher] students.' . "\n\n"
			. 'Reply with ONLY this JSON, no other text:' . "\n\n"
			. "{\n  \"questions\": [\n"
			. "    { \"type\": \"choice\", \"q\": \"Question text\", \"options\": [\"A\", \"B\", \"C\", \"D\"], \"correct\": 0, \"explain\": \"One-sentence reason.\" },\n"
			. "    { \"type\": \"choice-image\", \"q\": \"Question about the picture\", \"image\": \"One sentence describing the picture to show\", \"options\": [\"A\", \"B\", \"C\", \"D\"], \"correct\": 1, \"explain\": \"One-sentence reason.\" },\n"
			. "    { \"type\": \"order\", \"q\": \"Put the steps in order.\", \"options\": [\"Step one\", \"Step two\", \"Step three\", \"Step four\"], \"correctOrder\": [0, 1, 2, 3], \"explain\": \"One-sentence reason.\" }\n"
			. "  ]\n}\n\n"
			. "Rules:\n"
			. "- 4 options per question. \"correct\" counts from 0.\n"
			. "- Use \"order\" for one question at most, and \"choice-image\" for one at most.\n"
			. "- For \"choice-image\", describe the picture in \"image\" — I will attach the real picture myself.\n"
			. '- UK English. Keep every explanation to one sentence.';
	}

	/**
	 * Validate quiz JSON. Returns ['questions' => [...]] or ['error' => 'friendly message'].
	 */
	public static function validate( string $text ): array {
		$data = json_decode( trim( $text ), true );
		if ( ! is_array( $data ) ) {
			return [ 'error' => 'That doesn’t look like quiz code yet — make sure you copy everything ChatGPT gives you, starting with { and ending with }.' ];
		}
		$qs = $data['questions'] ?? null;
		if ( ! is_array( $qs ) || ! array_is_list( $qs ) || count( $qs ) === 0 ) {
			return [ 'error' => 'I can’t find any questions in there. Ask ChatGPT to use the exact format from the instructions box.' ];
		}
		if ( count( $qs ) > 10 ) {
			return [ 'error' => 'That’s ' . count( $qs ) . ' questions — quizzes work best at 5. Ask ChatGPT for fewer.' ];
		}
		$clean = [];
		foreach ( $qs as $i => $q ) {
			$n = 'Question ' . ( $i + 1 );
			if ( ! is_array( $q ) || ! isset( $q['q'] ) || ! is_string( $q['q'] ) || trim( $q['q'] ) === '' ) {
				return [ 'error' => "$n is missing its question text." ];
			}
			$type = $q['type'] ?? '';
			if ( ! in_array( $type, self::TYPES, true ) ) {
				return [ 'error' => "$n has a type I don’t recognise — it should be \"choice\", \"choice-image\" or \"order\"." ];
			}
			$options = $q['options'] ?? null;
			if ( ! is_array( $options ) || ! array_is_list( $options ) || count( $options ) < 3 || count( $options ) > 5 ) {
				return [ 'error' => "$n needs 3 to 5 options." ];
			}
			foreach ( $options as $o ) {
				if ( ! is_scalar( $o ) ) {
					return [ 'error' => "$n has an option that isn’t plain text." ];
				}
			}
			$item = [
				'type'    => $type,
				'q'       => sanitize_text_field( $q['q'] ),
				'options' => array_map( static fn( $o ) => sanitize_text_field( (string) $o ), $options ),
				'explain' => sanitize_text_field( (string) ( $q['explain'] ?? '' ) ),
			];
			if ( $type === 'order' ) {
				$co = $q['correctOrder'] ?? null;
				$ok = is_array( $co ) && count( $co ) === count( $options ) && array_is_list( $co );
				if ( $ok ) {
					$sorted = array_map( 'intval', $co );
					sort( $sorted );
					$ok = $sorted === range( 0, count( $options ) - 1 );
				}
				if ( ! $ok ) {
					return [ 'error' => "$n: \"correctOrder\" must list every option position exactly once." ];
				}
				$item['correctOrder'] = array_map( 'intval', $co );
			} else {
				$correct = $q['correct'] ?? null;
				if ( ! is_int( $correct ) && ! ( is_numeric( $correct ) && (int) $correct == $correct ) ) {
					return [ 'error' => "$n: \"correct\" must point at one of the options, counting from 0." ];
				}
				$correct = (int) $correct;
				if ( $correct < 0 || $correct >= count( $options ) ) {
					return [ 'error' => "$n: \"correct\" must point at one of the options, counting from 0." ];
				}
				$item['correct'] = $correct;
			}
			if ( $type === 'choice-image' ) {
				$item['image'] = isset( $q['image'] ) && is_string( $q['image'] ) ? sanitize_text_field( $q['image'] ) : '';
				$item['imageUrl'] = isset( $q['imageUrl'] ) && is_string( $q['imageUrl'] ) ? esc_url_raw( $q['imageUrl'] ) : '';
				$item['imageAlt'] = isset( $q['imageAlt'] ) && is_string( $q['imageAlt'] ) ? sanitize_text_field( $q['imageAlt'] ) : '';
			}
			if ( isset( $q['optionImages'] ) && is_array( $q['optionImages'] ) ) {
				$item['optionImages'] = array_map( static fn( $u ) => esc_url_raw( (string) $u ), array_values( $q['optionImages'] ) );
			}
			$clean[] = $item;
		}
		return [ 'questions' => $clean ];
	}

	public static function questions( int $quiz_id ): array {
		$raw = (string) get_post_meta( $quiz_id, 'questions', true );
		if ( ! $raw ) {
			return [];
		}
		$res = self::validate( $raw );
		return $res['questions'] ?? [];
	}

	public static function summary( int $quiz_id ): array {
		$qs    = self::questions( $quiz_id );
		$count = count( $qs );
		$mins  = (int) get_post_meta( $quiz_id, 'estimated_minutes', true );
		if ( ! $mins ) {
			$mins = max( 1, (int) round( $count * 0.8 ) );
		}
		return [
			'count'   => $count,
			'minutes' => $mins,
			'label'   => $count . ( $count === 1 ? ' question' : ' questions' ) . ' · about ' . $mins . ( $mins === 1 ? ' minute' : ' minutes' ),
		];
	}

	/**
	 * Kind labels used in the Studio preview.
	 */
	public static function kind_label( string $type ): string {
		return $type === 'order' ? 'Put in order' : ( $type === 'choice-image' ? 'Picture question' : 'Multiple choice' );
	}
}
