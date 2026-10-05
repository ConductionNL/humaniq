<?php

/**
 * SurveyResults
 *
 * The results of a survey, computed on read: per question the number of
 * answers, the distribution, the average of a scale and the eNPS of the
 * recommend question (share of 9 and 10 minus share of 0 to 6), overall
 * and per department. A department with fewer responses than the
 * survey's minimum group size is folded into "other"; "other" is left out
 * when it is itself below the minimum. Free text is returned overall only,
 * in random order.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Fold and summarise survey responses.
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-003
 */
class SurveyResults {

	/**
	 * The minimum group size when the survey sets none.
	 *
	 * @var int
	 */
	public const DEFAULT_MIN = 5;

	/**
	 * The results.
	 *
	 * @param SurveyAnswers $answers The question ranges.
	 */
	public function __construct(
		private readonly SurveyAnswers $answers,
	) {
	}//end __construct()

	/**
	 * The overall and per-department results.
	 *
	 * @param array<string, mixed>       $survey    The survey.
	 * @param list<array<string, mixed>> $responses Its responses.
	 * @param array<string, string>      $unitNames Department names by id.
	 *
	 * @return array{responses: int, minGroupSize: int, overall: list<array<string, mixed>>, units: list<array<string, mixed>>, notes: list<string>, rows: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-003
	 */
	public function compute(array $survey, array $responses, array $unitNames): array {
		$min = max(3, (int)($survey['minGroupSize'] ?? self::DEFAULT_MIN));
		$questions = array_values(array_filter((array)($survey['questions'] ?? []), static fn (mixed $question): bool => is_array($question) === true && trim((string)($question['key'] ?? '')) !== ''));
		[$units, $notes] = $this->breakdown(questions: $questions, responses: $responses, min: $min, unitNames: $unitNames);
		usort($units, static fn (array $one, array $two): int => [$one['unitId'] === 'other', $one['name']] <=> [$two['unitId'] === 'other', $two['name']]);
		$overall = (count($responses) >= $min) ? $this->summaries($questions, $responses, true) : [];
		if ($overall === [] && $responses !== []) {
			$notes[] = 'Fewer responses than the minimum group size: no results are shown yet.';
		}

		return ['responses' => count($responses), 'minGroupSize' => $min, 'overall' => $overall, 'units' => $units, 'notes' => $notes, 'rows' => $this->rows($overall, $units)];
	}//end compute()

	/**
	 * The per-department results, with departments below the minimum folded
	 * into Other, and Other left out when it is itself too small.
	 *
	 * @param list<array<string, mixed>> $questions The questions.
	 * @param list<array<string, mixed>> $responses The responses.
	 * @param int                        $min       The minimum group size.
	 * @param array<string, string>      $unitNames Department names by id.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: list<string>}
	 */
	private function breakdown(array $questions, array $responses, int $min, array $unitNames): array {
		$byUnit = [];
		foreach ($responses as $response) {
			$byUnit[(string)($response['orgUnitId'] ?? '')][] = $response;
		}

		$units = [];
		$other = [];
		foreach ($byUnit as $unitId => $group) {
			if ($unitId !== '' && count($group) >= $min) {
				$units[] = ['unitId' => (string)$unitId, 'name' => ($unitNames[(string)$unitId] ?? (string)$unitId), 'responses' => count($group), 'questions' => $this->summaries($questions, $group, false)];
				continue;
			}

			$other = array_merge($other, $group);
		}

		$notes = [];
		if ($other !== [] && count($other) >= $min) {
			$units[] = ['unitId' => 'other', 'name' => 'Other', 'responses' => count($other), 'questions' => $this->summaries($questions, $other, false)];
		} else if ($other !== []) {
			$notes[] = 'Departments with too few responses are left out of the breakdown; their answers count in the overall figures.';
		}

		return [$units, $notes];
	}//end breakdown()

	/**
	 * One summary per question.
	 *
	 * @param list<array<string, mixed>> $questions The questions.
	 * @param list<array<string, mixed>> $responses The responses.
	 * @param bool                       $withText  Whether free text is returned (overall only).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function summaries(array $questions, array $responses, bool $withText): array {
		$out = [];
		foreach ($questions as $question) {
			$key = (string)$question['key'];
			$values = [];
			foreach ($responses as $response) {
				$answer = (((array)($response['answers'] ?? []))[$key] ?? null);
				if ($answer !== null && $answer !== '') {
					$values[] = $answer;
				}
			}

			$out[] = $this->summary(question: $question, values: $values, withText: $withText);
		}

		return $out;
	}//end summaries()

	/**
	 * The summary of one question.
	 *
	 * @param array<string, mixed> $question The question.
	 * @param list<mixed>          $values   The answers given.
	 * @param bool                 $withText Whether free text is returned.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(array $question, array $values, bool $withText): array {
		$type = (string)($question['type'] ?? 'textarea');
		$summary = ['key' => (string)$question['key'], 'label' => (string)($question['label'] ?? ''), 'type' => $type, 'count' => count($values)];
		if ($type === 'textarea' || $type === 'text') {
			if ($withText === true) {
				$texts = array_map('strval', $values);
				shuffle($texts);
				$summary['texts'] = $texts;
			}

			return $summary;
		}

		$distribution = [];
		if ($type !== 'enum') {
			[$low, $high] = $this->answers->range($question);
			$distribution = array_fill_keys(array_map('strval', range($low, $high)), 0);
		}

		foreach ($values as $value) {
			$distribution[(string)$value] = (($distribution[(string)$value] ?? 0) + 1);
		}

		$summary['distribution'] = $distribution;
		if ($type === 'enum' || $values === []) {
			return $summary;
		}

		$numbers = array_map('intval', $values);
		$summary['average'] = round(array_sum($numbers) / count($numbers), 2);
		if ($type === 'recommend') {
			$promoters = count(array_filter($numbers, static fn (int $score): bool => $score >= 9));
			$detractors = count(array_filter($numbers, static fn (int $score): bool => $score <= 6));
			$summary['enps'] = (int)round(($promoters - $detractors) / count($numbers) * 100);
		}

		return $summary;
	}//end summary()

	/**
	 * Flat rows for a table: one per question, overall and per department.
	 *
	 * @param list<array<string, mixed>> $overall The overall summaries.
	 * @param list<array<string, mixed>> $units   The departments shown.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $overall, array $units): array {
		$groups = array_merge([['name' => 'Overall', 'questions' => $overall]], $units);
		$rows = [];
		foreach ($groups as $group) {
			foreach ((array)$group['questions'] as $summary) {
				if (in_array(($summary['type'] ?? ''), ['textarea', 'text'], true) === true) {
					continue;
				}

				$rows[] = ['group' => (string)$group['name'], 'question' => (string)$summary['label'], 'answers' => (int)$summary['count'], 'average' => ($summary['average'] ?? null), 'enps' => ($summary['enps'] ?? null)];
			}
		}

		return $rows;
	}//end rows()

}//end class
