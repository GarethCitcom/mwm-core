<?php
/**
 * Per-user learning progress: pathway ticks (keyed "level:topic" => subtopic keys), saved/completed lessons, quiz scores, level/board prefs.
 * A quiz entry keeps every attempt with its date (newest last, up to 30), plus the latest score/total/at for older readers.
 * Signed-out visitors keep the same shape in localStorage; the theme merges it on sign-in.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Progress {

	public const META = 'mwm_progress';

	public static function init(): void {}

	/** Pathway ids are "level:topic" (e.g. gcse-higher:algebra); anything else is stripped. */
	public static function pathway_key( $id ): string {
		return (string) preg_replace( '/[^a-z0-9:_-]/', '', strtolower( (string) $id ) );
	}

	/**
	 * Forget every user's ticks (used once when pathways changed shape; row keys no longer line up).
	 */
	public static function reset_all_ticks(): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::META ) );
		foreach ( array_map( 'intval', (array) $ids ) as $uid ) {
			$data = self::get( $uid );
			$data['ticks'] = [];
			update_user_meta( $uid, self::META, $data );
		}
	}

	public static function empty(): array {
		return [ 'ticks' => [], 'saved' => [], 'completed' => [], 'quizzes' => [], 'prefs' => [] ];
	}

	public static function get( int $user_id ): array {
		$saved = get_user_meta( $user_id, self::META, true );
		$data  = is_array( $saved ) ? $saved : [];
		$data  = array_merge( self::empty(), $data );
		$data['prefs'] = array_merge( [ 'level' => '', 'board' => '' ], (array) $data['prefs'] );
		foreach ( (array) $data['quizzes'] as $qid => $r ) { // Results saved before attempts were kept become a one-attempt history.
			if ( is_array( $r ) && empty( $r['attempts'] ) && isset( $r['total'] ) ) {
				$data['quizzes'][ $qid ]['attempts'] = [ [ 'score' => (int) $r['score'], 'total' => (int) $r['total'], 'at' => (string) ( $r['at'] ?? '' ) ] ];
			}
		}
		return $data;
	}

	public const MAX_ATTEMPTS = 30;

	/**
	 * Merge quiz attempts: union by timestamp, oldest first, capped. Returns the record with latest score/total/at set.
	 */
	private static function merge_quiz( array $current, array $incoming ): array {
		$attempts = [];
		$add = static function ( $a ) use ( &$attempts ) {
			if ( ! is_array( $a ) || ! isset( $a['total'] ) ) {
				return;
			}
			$at = sanitize_text_field( (string) ( $a['at'] ?? '' ) );
			$attempts[ $at ?: (string) count( $attempts ) ] = [ 'score' => (int) ( $a['score'] ?? 0 ), 'total' => (int) $a['total'], 'at' => $at ];
		};
		if ( ! empty( $current['attempts'] ) && is_array( $current['attempts'] ) ) {
			foreach ( $current['attempts'] as $a ) {
				$add( $a );
			}
		} elseif ( isset( $current['total'] ) ) { // A result saved before attempts were kept.
			$add( $current );
		}
		if ( ! empty( $incoming['attempts'] ) && is_array( $incoming['attempts'] ) ) {
			foreach ( $incoming['attempts'] as $a ) {
				$add( $a );
			}
		} elseif ( isset( $incoming['total'] ) ) { // An older client sending just the result.
			$add( $incoming );
		}
		ksort( $attempts );
		$attempts = array_slice( array_values( $attempts ), -self::MAX_ATTEMPTS );
		$latest   = $attempts ? end( $attempts ) : [ 'score' => 0, 'total' => 0, 'at' => '' ];
		return [
			'score'    => $latest['score'],
			'total'    => $latest['total'],
			'at'       => $latest['at'] ? substr( $latest['at'], 0, 10 ) : gmdate( 'Y-m-d' ),
			'attempts' => $attempts,
		];
	}

	/**
	 * Latest, best and every attempt for one quiz, or null if never taken.
	 */
	public static function quiz_history( array $progress, int $quiz_id ): ?array {
		$r = $progress['quizzes'][ (string) $quiz_id ] ?? null;
		if ( ! is_array( $r ) || empty( $r['attempts'] ) ) {
			return null;
		}
		$pct  = static fn( $a ) => $a['total'] ? (int) round( $a['score'] / $a['total'] * 100 ) : 0;
		$best = null;
		foreach ( $r['attempts'] as $a ) {
			if ( ! $best || $pct( $a ) > $pct( $best ) ) {
				$best = $a;
			}
		}
		$latest = end( $r['attempts'] );
		return [ 'latest' => $latest + [ 'pct' => $pct( $latest ) ], 'best' => $best + [ 'pct' => $pct( $best ) ], 'attempts' => $r['attempts'], 'count' => count( $r['attempts'] ) ];
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
				$pathway = self::pathway_key( $pathway );
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
				$current['quizzes'][ $quiz_id ] = self::merge_quiz( (array) ( $current['quizzes'][ $quiz_id ] ?? [] ), $result );
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
					$pathway = self::pathway_key( $pathway );
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
