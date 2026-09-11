<?php
/**
 * Per-user learning progress: pathway ticks, saved/completed lessons, quiz scores, level/board prefs.
 * Signed-out visitors keep the same shape in localStorage; the theme merges it on sign-in.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Progress {

	public const META = 'mwm_progress';

	public static function init(): void {}

	public static function empty(): array {
		return [ 'ticks' => [], 'saved' => [], 'completed' => [], 'quizzes' => [], 'prefs' => [] ];
	}

	public static function get( int $user_id ): array {
		$saved = get_user_meta( $user_id, self::META, true );
		$data  = is_array( $saved ) ? $saved : [];
		$data  = array_merge( self::empty(), $data );
		$data['prefs'] = array_merge( [ 'level' => '', 'board' => '' ], (array) $data['prefs'] );
		return $data;
	}

	/**
	 * Merge a patch into the stored progress. Arrays of IDs are unioned unless `replace` is true.
	 *
	 * @param array $patch Any subset of the progress shape, plus optional 'remove' => ['saved'=>[ids], 'completed'=>[ids], 'ticks'=>['pathwayId'=>[keys]]].
	 */
	public static function update( int $user_id, array $patch, bool $replace = false ): array {
		$current = self::get( $user_id );
		if ( $replace ) {
			$current = self::empty();
		}
		foreach ( [ 'saved', 'completed' ] as $k ) {
			if ( isset( $patch[ $k ] ) && is_array( $patch[ $k ] ) ) {
				$ids = array_map( 'intval', $patch[ $k ] );
				$current[ $k ] = array_values( array_unique( array_merge( $current[ $k ], $ids ) ) );
			}
		}
		if ( isset( $patch['ticks'] ) && is_array( $patch['ticks'] ) ) {
			foreach ( $patch['ticks'] as $pathway => $keys ) {
				$pathway = (string) (int) $pathway;
				$keys    = array_map( 'sanitize_key', (array) $keys );
				$current['ticks'][ $pathway ] = array_values( array_unique( array_merge( (array) ( $current['ticks'][ $pathway ] ?? [] ), $keys ) ) );
			}
		}
		if ( isset( $patch['quizzes'] ) && is_array( $patch['quizzes'] ) ) {
			foreach ( $patch['quizzes'] as $quiz_id => $result ) {
				$quiz_id = (string) (int) $quiz_id;
				if ( ! is_array( $result ) ) {
					continue;
				}
				$current['quizzes'][ $quiz_id ] = [
					'score' => (int) ( $result['score'] ?? 0 ),
					'total' => (int) ( $result['total'] ?? 0 ),
					'at'    => sanitize_text_field( (string) ( $result['at'] ?? gmdate( 'Y-m-d' ) ) ),
				];
			}
		}
		if ( isset( $patch['prefs'] ) && is_array( $patch['prefs'] ) ) {
			$level = sanitize_key( (string) ( $patch['prefs']['level'] ?? '' ) );
			$board = sanitize_key( (string) ( $patch['prefs']['board'] ?? '' ) );
			if ( $level && isset( mwm_levels()[ $level ] ) ) {
				$current['prefs']['level'] = $level;
			}
			if ( $board && isset( mwm_boards()[ $board ] ) ) {
				$current['prefs']['board'] = $board;
			}
			update_user_meta( $user_id, 'mwm_prefs', $current['prefs'] );
		}
		if ( isset( $patch['remove'] ) && is_array( $patch['remove'] ) ) {
			foreach ( [ 'saved', 'completed' ] as $k ) {
				if ( ! empty( $patch['remove'][ $k ] ) ) {
					$ids = array_map( 'intval', (array) $patch['remove'][ $k ] );
					$current[ $k ] = array_values( array_diff( $current[ $k ], $ids ) );
				}
			}
			if ( ! empty( $patch['remove']['ticks'] ) ) {
				foreach ( (array) $patch['remove']['ticks'] as $pathway => $keys ) {
					$pathway = (string) (int) $pathway;
					$keys    = array_map( 'sanitize_key', (array) $keys );
					$current['ticks'][ $pathway ] = array_values( array_diff( (array) ( $current['ticks'][ $pathway ] ?? [] ), $keys ) );
				}
			}
		}
		update_user_meta( $user_id, self::META, $current );
		return $current;
	}

	/**
	 * Quiz average across all recorded quizzes, as a whole percentage (or null).
	 */
	public static function quiz_average( array $progress ): ?int {
		$score = 0;
		$total = 0;
		foreach ( (array) $progress['quizzes'] as $r ) {
			$score += (int) ( $r['score'] ?? 0 );
			$total += (int) ( $r['total'] ?? 0 );
		}
		return $total ? (int) round( $score / $total * 100 ) : null;
	}
}
