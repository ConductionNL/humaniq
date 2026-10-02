<?php

/**
 * SurveyAnswers
 *
 * Checks the answers to a survey against its questions: a scale or a
 * recommend answer is a whole number in its range, a choice is one of the
 * choices, free text is trimmed and capped. Answers to unknown questions
 * and invalid answers are dropped; a required question without a valid
 * answer is named.
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Clean the answers to a survey.
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
 */
class SurveyAnswers {

	/**
	 * The longest free-text answer kept.
	 *
	 * @var int
	 */
	private const TEXT_MAX = 2000;

	/**
	 * The valid answers by key, and the required questions left without one.
	 *
	 * @param array<int|string, mixed> $questions The survey's questions.
	 * @param array<string, mixed>     $answers   The answers given.
	 *
	 * @return array{answers: array<string, int|string>, missing: list<string>}
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
	 */
	public function clean(array $questions, array $answers): array {
		$out = ['answers' => [], 'missing' => []];
		foreach ($questions as $question) {
			if (is_array($question) === false || trim((string)($question['key'] ?? '')) === '') {
				continue;
			}

			$key = (string)$question['key'];
			$value = $this->valid(question: $question, value: ($answers[$key] ?? null));
			if ($value !== null) {
				$out['answers'][$key] = $value;
			} else if (($question['required'] ?? false) === true) {
				$out['missing'][] = $key;
			}
		}

		return $out;
	}//end clean()

	/**
	 * The answer when it is valid for the question, else null.
	 *
	 * @param array<string, mixed> $question The question.
	 * @param mixed                $value    The answer.
	 *
	 * @return int|string|null
	 */
	private function valid(array $question, mixed $value): int|string|null {
		$type = (string)($question['type'] ?? 'textarea');
		if ($type === 'scale' || $type === 'recommend') {
			[$min, $max] = $this->range($question);
			$number = filter_var($value, FILTER_VALIDATE_INT);

			return ($number !== false && $number >= $min && $number <= $max) ? $number : null;
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		if ($type === 'enum') {
			return in_array($value, $this->choices($question), true) === true ? $value : null;
		}

		return mb_substr(trim($value), 0, self::TEXT_MAX);
	}//end valid()

	/**
	 * The lowest and highest value of a scale (default 1 to 5) or of the
	 * recommend question (always 0 to 10, the eNPS scale).
	 *
	 * @param array<string, mixed> $question The question.
	 *
	 * @return array{0: int, 1: int}
	 */
	public function range(array $question): array {
		if (($question['type'] ?? '') === 'recommend') {
			return [0, 10];
		}

		$options = (array)($question['options'] ?? []);

		return [(int)($options['min'] ?? 1), (int)($options['max'] ?? 5)];
	}//end range()

	/**
	 * The choices of a choice question: options.choices, or the builder's
	 * options list.
	 *
	 * @param array<string, mixed> $question The question.
	 *
	 * @return list<string>
	 */
	private function choices(array $question): array {
		$options = (array)($question['options'] ?? []);
		$choices = (array)($options['choices'] ?? $options);

		return array_values(array_map('strval', array_filter($choices, 'is_scalar')));
	}//end choices()

}//end class
