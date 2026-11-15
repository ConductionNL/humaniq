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
		$run = new SurveyResultsRun();
		$run->min = max(3, (int)($survey['minGroupSize'] ?? self::DEFAULT_MIN));
		$run->questions = array_values(array_filter((array)($survey['questions'] ?? []), static fn (mixed $question): bool => is_array($question) === true && trim((string)($question['key'] ?? '')) !== ''));
		$run->unitNames = $unitNames;
		[$units, $notes] = $this->breakdown(run: $run, responses: $responses);
		usort($units, static fn (array $one, array $two): int => [$one['unitId'] === 'other', $one['name']] <=> [$two['unitId'] === 'other', $two['name']]);
		$overall = $this->overall(run: $run, responses: $responses);
		if ($overall === [] && $responses !== []) {
			$notes[] = 'Fewer responses than the minimum group size: no results are shown yet.';
		}

		return ['responses' => count($responses), 'minGroupSize' => $run->min, 'overall' => $overall, 'units' => $units, 'notes' => $notes, 'rows' => $this->rows($overall, $units)];
	}//end compute()

	/**
	 * The overall summaries, with free text, or none below the minimum group size.
	 *
	 * @param SurveyResultsRun           $run       The survey's questions and minimum group size.
	 * @param list<array<string, mixed>> $responses Its responses.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function overall(SurveyResultsRun $run, array $responses): array {
		$run->withText = true;

		return (count($responses) >= $run->min) ? $this->summaries(run: $run, responses: $responses) : [];
	}//end overall()

	/**
	 * The per-department results, with departments below the minimum folded
	 * into Other, and Other left out when it is itself too small.
	 *
	 * @param SurveyResultsRun           $run       The survey's questions, minimum group size and department names.
	 * @param list<array<string, mixed>> $responses The responses.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: list<string>}
	 */
	private function breakdown(SurveyResultsRun $run, array $responses): array {
		$other = [];
		foreach ($this->byUnit(responses: $responses) as $unitId => $group) {
			if ($unitId !== '' && count($group) >= $run->min) {
				$run->units[] = ['unitId' => (string)$unitId, 'name' => ($run->unitNames[(string)$unitId] ?? (string)$unitId), 'responses' => count($group), 'questions' => $this->summaries(run: $run, responses: $group)];
				continue;
			}

			$other = array_merge($other, $group);
		}

		return $this->withOther(run: $run, other: $other);
	}//end breakdown()

	/**
	 * The responses per department id, '' for none.
	 *
	 * @param list<array<string, mixed>> $responses The responses.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function byUnit(array $responses): array {
		$byUnit = [];
		foreach ($responses as $response) {
			$byUnit[(string)($response['orgUnitId'] ?? '')][] = $response;
		}

		return $byUnit;
	}//end byUnit()

	/**
	 * The department results with Other added when it reaches the minimum,
	 * or a note that it was left out.
	 *
	 * @param SurveyResultsRun           $run   The survey's questions, minimum group size and department results.
	 * @param list<array<string, mixed>> $other The responses of departments too small or without one.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: list<string>}
	 */
	private function withOther(SurveyResultsRun $run, array $other): array {
		$notes = [];
		if ($other !== [] && count($other) >= $run->min) {
			$run->units[] = ['unitId' => 'other', 'name' => 'Other', 'responses' => count($other), 'questions' => $this->summaries(run: $run, responses: $other)];
		} else if ($other !== []) {
			$notes[] = 'Departments with too few responses are left out of the breakdown; their answers count in the overall figures.';
		}

		return [$run->units, $notes];
	}//end withOther()

	/**
	 * One summary per question.
	 *
	 * @param SurveyResultsRun           $run       The questions, and whether free text is returned (overall only).
	 * @param list<array<string, mixed>> $responses The responses.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function summaries(SurveyResultsRun $run, array $responses): array {
		$out = [];
		foreach ($run->questions as $question) {
			$key = (string)$question['key'];
			$values = [];
			foreach ($responses as $response) {
				$answer = (((array)($response['answers'] ?? []))[$key] ?? null);
				if ($answer !== null && $answer !== '') {
					$values[] = $answer;
				}
			}

			$out[] = $this->summary(question: $question, values: $values, withText: $run->withText);
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
		if (self::isFreeText(type: $type) === true) {
			return $summary + ($withText === true ? ['texts' => self::shuffledTexts(values: $values)] : []);
		}

		$summary['distribution'] = $this->distribution(question: $question, values: $values);
		if ($type === 'enum' || $values === []) {
			return $summary;
		}

		return $summary + self::scores(type: $type, numbers: array_map('intval', $values));
	}//end summary()

	/**
	 * Whether a question takes free text.
	 *
	 * @param string $type The question type.
	 *
	 * @return bool
	 */
	private static function isFreeText(string $type): bool {
		return $type === 'textarea' || $type === 'text';
	}//end isFreeText()

	/**
	 * The free-text answers in a random order, so the order says nothing
	 * about who wrote what.
	 *
	 * @param list<mixed> $values The answers given.
	 *
	 * @return list<string>
	 */
	private static function shuffledTexts(array $values): array {
		$texts = array_map('strval', $values);
		shuffle($texts);

		return $texts;
	}//end shuffledTexts()

	/**
	 * How often each answer was given; a scale lists every point of its
	 * range, a choice only the answers given.
	 *
	 * @param array<string, mixed> $question The question.
	 * @param list<mixed>          $values   The answers given.
	 *
	 * @return array<string, int>
	 */
	private function distribution(array $question, array $values): array {
		$distribution = [];
		if ((string)($question['type'] ?? 'textarea') !== 'enum') {
			[$low, $high] = $this->answers->range($question);
			$distribution = array_fill_keys(array_map('strval', range($low, $high)), 0);
		}

		foreach ($values as $value) {
			$distribution[(string)$value] = (($distribution[(string)$value] ?? 0) + 1);
		}

		return $distribution;
	}//end distribution()

	/**
	 * The average of the scores and, for a recommendation, the eNPS.
	 *
	 * @param string    $type    The question type.
	 * @param list<int> $numbers The scores, at least one.
	 *
	 * @return array{average: float, enps?: int}
	 */
	private static function scores(string $type, array $numbers): array {
		$scores = ['average' => round(array_sum($numbers) / count($numbers), 2)];
		if ($type === 'recommend') {
			$promoters = count(array_filter($numbers, static fn (int $score): bool => $score >= 9));
			$detractors = count(array_filter($numbers, static fn (int $score): bool => $score <= 6));
			$scores['enps'] = (int)round(($promoters - $detractors) / count($numbers) * 100);
		}

		return $scores;
	}//end scores()

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
