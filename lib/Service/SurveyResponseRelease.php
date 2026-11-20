<?php

/**
 * SurveyResponseRelease
 *
 * Releases held survey answers as responses. An answer is first held in
 * SurveyResponseHold, owned by the system; this release moves the held
 * answers of a survey into SurveyResponse in one shuffled batch, again
 * owned by the system, and stamps them with the day of the release. So a
 * response's owner, creation time and date say nothing about which
 * invitation was answered when.
 *
 * A survey's answers are released once at least its minimum group size
 * is held, and whatever is left once the survey stops taking answers. A
 * response released in a batch smaller than the minimum is not readable
 * by HR one by one; the results still count it.
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
 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Move held survey answers into responses, in batches.
 *
 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-002
 */
class SurveyResponseRelease {

	/**
	 * The fields a held answer carries over into its response.
	 *
	 * @var list<string>
	 */
	private const CARRIED = ['surveyId', 'orgUnitId', 'contractType', 'answers', 'administrationId'];

	/**
	 * The release.
	 *
	 * @param HoursRegisterGateway $gateway Register reads and writes.
	 * @param InternalWriteMarker  $marker  Marks the release writes as humaniq's own.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
	) {
	}//end __construct()

	/**
	 * Release the held answers of every survey that is due: it holds at
	 * least its minimum group size, or it no longer takes answers.
	 *
	 * @param string $today The day, `YYYY-MM-DD`.
	 *
	 * @return int The number of responses released.
	 *
	 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-002
	 */
	public function release(string $today): int {
		$bySurvey = [];
		foreach ($this->gateway->loadAll(SurveyService::HOLD) as $held) {
			$surveyId = trim((string)($held['surveyId'] ?? ''));
			if ($surveyId !== '' && trim((string)($held['id'] ?? '')) !== '') {
				$bySurvey[$surveyId][] = $held;
			}
		}

		$released = 0;
		foreach ($bySurvey as $surveyId => $batch) {
			$survey = $this->gateway->findObjectData((string)$surveyId, SurveyService::SURVEY);
			if ($survey === null) {
				continue;
			}

			$minimum = SurveyResults::minimumOf(survey: $survey);
			if (count($batch) >= $minimum || self::takesAnswers(survey: $survey, today: $today) === false) {
				$released += $this->releaseBatch(batch: $batch, today: $today, readable: count($batch) >= $minimum);
			}
		}

		return $released;
	}//end release()

	/**
	 * Write one survey's held answers as responses in a random order, then
	 * remove them from the hold.
	 *
	 * @param list<array<string, mixed>> $batch    The held answers.
	 * @param string                     $today    The day of the release.
	 * @param bool                       $readable Whether HR may read the responses one by one.
	 *
	 * @return int The number released.
	 */
	private function releaseBatch(array $batch, string $today, bool $readable): int {
		shuffle($batch);
		$this->marker->runInternal(function () use ($batch, $today, $readable): void {
			foreach ($batch as $held) {
				$response = array_intersect_key($held, array_flip(self::CARRIED));
				$response['submittedOn'] = $today;
				$response['readable'] = $readable;
				$this->gateway->createUnowned($response, SurveyService::RESPONSE);
				$this->gateway->delete((string)$held['id'], SurveyService::HOLD);
			}
		});

		return count($batch);
	}//end releaseBatch()

	/**
	 * Whether the survey still takes answers on the day: it is open and
	 * the day is not after its closing day.
	 *
	 * @param array<string, mixed> $survey The survey.
	 * @param string               $today  The day.
	 *
	 * @return bool
	 */
	private static function takesAnswers(array $survey, string $today): bool {
		$closes = trim((string)($survey['closesOn'] ?? ''));

		return ($survey['status'] ?? '') === 'open' && ($closes === '' || $closes >= $today);
	}//end takesAnswers()

}//end class
