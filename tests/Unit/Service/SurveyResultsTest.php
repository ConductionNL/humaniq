<?php

/**
 * SurveyResultsTest
 *
 * The edges of the folded results: too few answers overall, a response
 * without a department, a choice question, free text and a floor under the
 * minimum group size.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\SurveyAnswers;
use OCA\Humaniq\Service\SurveyResults;
use PHPUnit\Framework\TestCase;

/**
 * Edge paths of SurveyResults::compute().
 */
class SurveyResultsTest extends TestCase {

	/**
	 * The questions of the test survey.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const QUESTIONS = [
		['key' => 'druk', 'type' => 'enum', 'label' => 'Workload', 'options' => ['low', 'fine', 'high']],
		['key' => 'beveel', 'type' => 'recommend', 'label' => 'Recommend'],
		['key' => 'tekst', 'type' => 'textarea', 'label' => 'Anything else'],
	];

	/**
	 * A response.
	 *
	 * @param string|null $unit  The department, or null.
	 * @param int         $score The recommend score.
	 *
	 * @return array<string, mixed>
	 */
	private function response(?string $unit, int $score): array {
		return ['orgUnitId' => $unit, 'answers' => ['druk' => 'fine', 'beveel' => $score, 'tekst' => 'note ' . $score]];
	}//end response()

	/**
	 * No answers yet: nothing shown and nothing to note.
	 *
	 * @return void
	 */
	public function testNoResponsesShowNothing(): void {
		$results = (new SurveyResults(new SurveyAnswers()))->compute(['questions' => self::QUESTIONS, 'minGroupSize' => 5], [], []);

		self::assertSame(0, $results['responses']);
		self::assertSame([], $results['overall']);
		self::assertSame([], $results['units']);
		self::assertSame([], $results['notes']);
		self::assertSame([], $results['rows']);
	}//end testNoResponsesShowNothing()

	/**
	 * Fewer answers than the minimum: no overall figures either, with a note.
	 *
	 * @return void
	 */
	public function testTooFewAnswersOverallAreWithheld(): void {
		$responses = [$this->response('u1', 9), $this->response('u1', 3)];
		$results = (new SurveyResults(new SurveyAnswers()))->compute(['questions' => self::QUESTIONS, 'minGroupSize' => 5], $responses, ['u1' => 'Finance']);

		self::assertSame([], $results['overall']);
		self::assertSame([], $results['units']);
		self::assertCount(2, $results['notes']);
	}//end testTooFewAnswersOverallAreWithheld()

	/**
	 * A minimum below three is raised to three, a response without a
	 * department counts under Other, a choice has no average, and free text
	 * appears only overall.
	 *
	 * @return void
	 */
	public function testTheEdgesOfTheBreakdown(): void {
		$responses = [
			$this->response('u1', 10),
			$this->response('u1', 9),
			$this->response('u1', 2),
			$this->response(null, 8),
			$this->response(null, 7),
			$this->response('u2', 10),
		];
		$results = (new SurveyResults(new SurveyAnswers()))->compute(['questions' => self::QUESTIONS, 'minGroupSize' => 1], $responses, ['u1' => 'Finance', 'u2' => 'Legal']);

		self::assertSame(3, $results['minGroupSize']);
		self::assertSame(['Finance', 'Other'], array_column($results['units'], 'name'));
		self::assertSame(3, $results['units'][1]['responses']);

		$overall = array_column($results['overall'], null, 'key');
		self::assertSame(['fine' => 6], $overall['druk']['distribution']);
		self::assertArrayNotHasKey('average', $overall['druk']);
		// Three promoters (10, 9, 10), one detractor (2), of six: 33.
		self::assertSame(33, $overall['beveel']['enps']);
		self::assertCount(6, $overall['tekst']['texts']);
		foreach ($results['units'] as $unit) {
			$byKey = array_column($unit['questions'], null, 'key');
			self::assertArrayNotHasKey('texts', $byKey['tekst']);
		}

		self::assertNotContains('Anything else', array_column($results['rows'], 'question'));
	}//end testTheEdgesOfTheBreakdown()

}//end class
