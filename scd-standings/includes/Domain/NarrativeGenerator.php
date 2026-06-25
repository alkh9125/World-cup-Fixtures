<?php

namespace SCD\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a ScenarioEngine diff into deterministic, factual sentences from a
 * fixed Arabic/English phrase bank. This is explicitly NOT a generic AI
 * writer: the same structured input always produces the same sentence, so
 * output is auditable and free of hallucination risk.
 */
final class NarrativeGenerator {

	private const STATUS_LABEL_AR = [
		QualificationStatusResolver::STATUS_QUALIFIED  => 'يتأهل إلى الدور المقبل',
		QualificationStatusResolver::STATUS_ALMOST     => 'يقترب جدًا من التأهل',
		QualificationStatusResolver::STATUS_POSSIBLE    => 'لا يزال التأهل ممكنًا له',
		QualificationStatusResolver::STATUS_ELIMINATED => 'يخرج من البطولة',
	];

	private const STATUS_LABEL_EN = [
		QualificationStatusResolver::STATUS_QUALIFIED  => 'qualifies for the next round',
		QualificationStatusResolver::STATUS_ALMOST     => 'moves to the brink of qualification',
		QualificationStatusResolver::STATUS_POSSIBLE    => 'remains in contention',
		QualificationStatusResolver::STATUS_ELIMINATED => 'is eliminated',
	];

	private const OUTCOME_LABEL_AR = [
		ScenarioEngine::OUTCOME_HOME_WIN => 'بفوز %s',
		ScenarioEngine::OUTCOME_DRAW     => 'بالتعادل',
		ScenarioEngine::OUTCOME_AWAY_WIN => 'بفوز %s',
	];

	private const OUTCOME_LABEL_EN = [
		ScenarioEngine::OUTCOME_HOME_WIN => '%s win',
		ScenarioEngine::OUTCOME_DRAW     => 'a draw',
		ScenarioEngine::OUTCOME_AWAY_WIN => '%s win',
	];

	/**
	 * @param array<int,string> $teamNamesAr teamId => Arabic name
	 * @param array<int,string> $teamNamesEn teamId => English name
	 * @param array{standings: Standing[], qualification: array<int,string>, changes: array} $scenarioResult
	 * @param array<int,string>|null $opponentNamesAr teamId => likely next-round opponent (Arabic), if already known
	 *
	 * @return array{ar: string, en: string}
	 */
	public function generate(
		array $teamNamesAr,
		array $teamNamesEn,
		MatchResult $match,
		string $outcome,
		array $scenarioResult,
		?array $opponentNamesAr = null,
	): array {
		$homeAr = $teamNamesAr[ $match->homeTeamId ] ?? '';
		$awayAr = $teamNamesAr[ $match->awayTeamId ] ?? '';
		$homeEn = $teamNamesEn[ $match->homeTeamId ] ?? '';
		$awayEn = $teamNamesEn[ $match->awayTeamId ] ?? '';

		$outcomeWinnerAr = self::OUTCOME_HOME_WIN === $outcome ? $homeAr : $awayAr;
		$outcomeWinnerEn = self::OUTCOME_HOME_WIN === $outcome ? $homeEn : $awayEn;

		$headingAr = sprintf(
			'إذا انتهت مباراة %1$s و%2$s %3$s:',
			$homeAr,
			$awayAr,
			vsprintf( self::OUTCOME_LABEL_AR[ $outcome ], [ $outcomeWinnerAr ] ),
		);

		$headingEn = sprintf(
			'If the %1$s vs %2$s match ends with %3$s:',
			$homeEn,
			$awayEn,
			vsprintf( self::OUTCOME_LABEL_EN[ $outcome ], [ $outcomeWinnerEn ] ),
		);

		$sentencesAr = [ $headingAr ];
		$sentencesEn = [ $headingEn ];

		foreach ( $scenarioResult['changes'] as $change ) {
			$teamAr = $teamNamesAr[ $change['team_id'] ] ?? '';
			$teamEn = $teamNamesEn[ $change['team_id'] ] ?? '';

			$statusAr = self::STATUS_LABEL_AR[ $change['to_status'] ] ?? '';
			$statusEn = self::STATUS_LABEL_EN[ $change['to_status'] ] ?? '';

			$sentencesAr[] = sprintf( '%1$s %2$s (المركز %3$d).', $teamAr, $statusAr, $change['to_rank'] );
			$sentencesEn[] = sprintf( '%1$s %2$s (position %3$d).', $teamEn, $statusEn, $change['to_rank'] );

			if ( $opponentNamesAr && isset( $opponentNamesAr[ $change['team_id'] ] ) ) {
				$sentencesAr[] = sprintf( 'الخصم المتوقع في الدور المقبل: %s.', $opponentNamesAr[ $change['team_id'] ] );
			}
		}

		if ( 1 === count( $sentencesAr ) ) {
			$sentencesAr[] = 'لن يتغير ترتيب التأهل في هذه الحالة.';
			$sentencesEn[] = 'Qualification standings would not change in this case.';
		}

		return [
			'ar' => implode( ' ', $sentencesAr ),
			'en' => implode( ' ', $sentencesEn ),
		];
	}
}
