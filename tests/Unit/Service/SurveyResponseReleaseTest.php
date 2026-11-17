<?php

/**
 * SurveyResponseReleaseTest
 *
 * Held survey answers are released as responses in batches: owned by the
 * system, dated on the day of the release, shuffled, and readable by HR
 * one by one only when the batch reaches the survey's minimum group size.
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\SurveyAnswers;
use OCA\Humaniq\Service\SurveyResponseRelease;
use OCA\Humaniq\Service\SurveyResults;
use OCA\Humaniq\Service\SurveyRules;
use OCA\Humaniq\Service\SurveyService;
use OCA\Humaniq\Service\UnitMembership;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Humaniq\Service\SurveyResponseRelease
 * @uses   \OCA\Humaniq\Service\HoursRegisterGateway
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 * @uses   \OCA\Humaniq\Service\OrgResolutionService
 * @uses   \OCA\Humaniq\Service\SurveyAnswers
 * @uses   \OCA\Humaniq\Service\SurveyAudience
 * @uses   \OCA\Humaniq\Service\SurveyOpening
 * @uses   \OCA\Humaniq\Service\SurveyReply
 * @uses   \OCA\Humaniq\Service\SurveyResults
 * @uses   \OCA\Humaniq\Service\SurveyResultsRun
 * @uses   \OCA\Humaniq\Service\SurveyRules
 * @uses   \OCA\Humaniq\Service\SurveyService
 * @uses   \OCA\Humaniq\Service\UnitMembership
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
 */
class SurveyResponseReleaseTest extends TestCase {

	/**
	 * The open survey, minimum group size 3.
	 */
	private const OPEN = '5a1e0000-0000-4000-8000-000000000011';

	/**
	 * A second open survey, minimum group size 5.
	 */
	private const LARGE = '5a1e0000-0000-4000-8000-000000000012';

	private FakeObjectStore $store;

	/**
	 * Two open surveys that close on 20 October.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('Survey', self::OPEN, ['title' => 'Kort', 'status' => 'open', 'closesOn' => '2026-10-20', 'minGroupSize' => 3, 'questions' => []]);
		$this->store->seed('Survey', self::LARGE, ['title' => 'Lang', 'status' => 'open', 'closesOn' => '2026-10-20', 'minGroupSize' => 5, 'questions' => []]);
	}//end setUp()

	/**
	 * A survey holding its minimum group size is released in one batch:
	 * every answer becomes a response owned by the system, dated on the
	 * day of the release, readable one by one, and leaves the hold.
	 *
	 * @return void
	 */
	public function testABatchOfTheMinimumIsReleasedReadable(): void {
		foreach ([1, 2, 3] as $n) {
			$this->hold('hold-' . $n, self::OPEN, $n);
		}

		self::assertSame(3, $this->release()->release('2026-10-06'));

		self::assertSame([], $this->rows('SurveyResponseHold'));
		$responses = $this->rows('SurveyResponse');
		self::assertCount(3, $responses);
		$scores = array_map(static fn (array $row): int => (int)$row['answers']['aanbevelen'], $responses);
		sort($scores);
		self::assertSame([1, 2, 3], $scores);
		foreach ($responses as $response) {
			$id = $response['id'];
			unset($response['id']);
			self::assertSame(['surveyId' => self::OPEN, 'orgUnitId' => 'unit-hr', 'contractType' => 'permanent', 'administrationId' => 'ADM-001', 'submittedOn' => '2026-10-06', 'readable' => true], array_diff_key($response, ['answers' => true]));
			self::assertSame([], RegisterSchemaValidator::errors('SurveyResponse', $response));
			self::assertStringStartsWith('fake-surveyresponse-', $id, 'A response is a new object, not the held one under another schema.');
		}

		$responseSaves = array_values(array_filter($this->store->state->saves, static fn (array $save): bool => $save['schema'] === 'SurveyResponse'));
		self::assertSame([true, true, true], array_column($responseSaves, 'unowned'));
	}//end testABatchOfTheMinimumIsReleasedReadable()

	/**
	 * Below the minimum an open survey keeps its answers held; the other
	 * survey's full batch is released on its own.
	 *
	 * @return void
	 */
	public function testBelowTheMinimumAnOpenSurveyWaits(): void {
		$this->hold('hold-1', self::OPEN, 7);
		$this->hold('hold-2', self::OPEN, 8);
		foreach ([1, 2, 3, 4] as $n) {
			$this->hold('large-' . $n, self::LARGE, $n);
		}

		self::assertSame(0, $this->release()->release('2026-10-06'));

		self::assertCount(6, $this->rows('SurveyResponseHold'));
		self::assertSame([], $this->rows('SurveyResponse'));
	}//end testBelowTheMinimumAnOpenSurveyWaits()

	/**
	 * Once a survey stops taking answers the rest is released, but a batch
	 * below the minimum is not readable one by one.
	 *
	 * @return void
	 */
	public function testAfterClosingTheRestIsReleasedUnreadable(): void {
		$this->hold('hold-1', self::OPEN, 7);
		$this->hold('hold-2', self::OPEN, 8);

		self::assertSame(2, $this->release()->release('2026-10-21'));

		self::assertSame([], $this->rows('SurveyResponseHold'));
		self::assertSame([false, false], array_column($this->rows('SurveyResponse'), 'readable'));
		self::assertSame(['2026-10-21', '2026-10-21'], array_column($this->rows('SurveyResponse'), 'submittedOn'));
	}//end testAfterClosingTheRestIsReleasedUnreadable()

	/**
	 * A closed survey releases on the day it closed, too.
	 *
	 * @return void
	 */
	public function testAClosedSurveyReleasesWhateverIsHeld(): void {
		$this->store->seed('Survey', self::OPEN, ['title' => 'Kort', 'status' => 'gesloten', 'closesOn' => '2026-10-20', 'minGroupSize' => 3, 'questions' => []]);
		$this->hold('hold-1', self::OPEN, 7);

		self::assertSame(1, $this->release()->release('2026-10-06'));
		self::assertSame([false], array_column($this->rows('SurveyResponse'), 'readable'));
	}//end testAClosedSurveyReleasesWhateverIsHeld()

	/**
	 * A held answer whose survey is gone, or that names no survey, stays
	 * where it is.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutItsSurveyStaysHeld(): void {
		$this->hold('hold-gone', '5a1e0000-0000-4000-8000-0000000000ff', 7);
		$this->store->seed('SurveyResponseHold', 'hold-blank', ['surveyId' => '', 'answers' => ['aanbevelen' => 1]]);

		self::assertSame(0, $this->release()->release('2026-10-30'));
		self::assertCount(2, $this->rows('SurveyResponseHold'));
		self::assertSame([], $this->rows('SurveyResponse'));
	}//end testAnAnswerWithoutItsSurveyStaysHeld()

	/**
	 * HR reads a response one by one only when it is readable, and cannot
	 * read a held answer at all.
	 *
	 * @return void
	 */
	public function testHrReadsOnlyReadableResponsesAndNoHeldAnswers(): void {
		self::assertSame([['group' => 'humaniq-hr', 'match' => ['readable' => true]]], RegisterSchemaValidator::schema('SurveyResponse')['authorization']['read']);
		self::assertSame(['admin'], RegisterSchemaValidator::schema('SurveyResponseHold')['authorization']['read']);
	}//end testHrReadsOnlyReadableResponsesAndNoHeldAnswers()

	/**
	 * End to end: answers given on different days come out as responses
	 * that all carry the release day, so no response points at the day,
	 * account or invitation of its answer.
	 *
	 * @return void
	 */
	public function testAReleasedResponseCannotBeLinkedToItsInvitation(): void {
		$survey = '5a1e0000-0000-4000-8000-000000000013';
		$this->store->seed('OrgUnit', 'unit-hr', ['name' => 'HR', 'administrationId' => 'ADM-001']);
		$this->store->seed('Survey', $survey, ['title' => 'Echt', 'status' => 'concept', 'scope' => 'everyone', 'orgUnitIds' => [], 'opensOn' => '2026-10-01', 'closesOn' => '2026-10-20', 'minGroupSize' => 3, 'administrationId' => 'ADM-001', 'questions' => [['key' => 'aanbevelen', 'type' => 'recommend', 'label' => 'Aanbevelen?', 'required' => true, 'options' => []]]]);
		foreach (['h1', 'h2', 'h3'] as $uid) {
			$this->store->seed('Employee', 'emp-' . $uid, ['firstName' => $uid, 'lastName' => 'Test', 'nextcloudUserId' => $uid, 'administrationId' => 'ADM-001', 'startDate' => '2024-01-01']);
			$this->store->seed('OrgAssignment', 'asg-' . $uid, ['employeeId' => 'emp-' . $uid, 'orgUnitId' => 'unit-hr', 'startDate' => '2024-01-01']);
			$this->store->seed('EmploymentContract', 'ctr-' . $uid, ['employeeId' => 'emp-' . $uid, 'type' => 'permanent', 'startDate' => '2024-01-01']);
		}

		$surveys = $this->surveys();
		$surveys->open($survey, '2026-10-02');
		self::assertSame(201, $surveys->respond($survey, 'h1', ['aanbevelen' => 9], '2026-10-03')['status']);
		self::assertSame(0, $this->release()->release('2026-10-03'), 'One answer is not released on its own.');
		self::assertSame(201, $surveys->respond($survey, 'h2', ['aanbevelen' => 4], '2026-10-04')['status']);
		self::assertSame(201, $surveys->respond($survey, 'h3', ['aanbevelen' => 6], '2026-10-05')['status']);

		self::assertSame(3, $this->release()->release('2026-10-06'));

		$responses = $this->rows('SurveyResponse');
		self::assertSame(['2026-10-06', '2026-10-06', '2026-10-06'], array_column($responses, 'submittedOn'));
		$encoded = (string)json_encode($responses);
		foreach (array_merge(['emp-h', '"h1"', '"h2"', '"h3"', '2026-10-03', '2026-10-04', '2026-10-05'], array_column($this->rows('SurveyInvitation'), 'id')) as $needle) {
			self::assertStringNotContainsString($needle, $encoded);
		}

		self::assertSame(3, $surveys->results($survey)['responses']);
	}//end testAReleasedResponseCannotBeLinkedToItsInvitation()

	/**
	 * Seed one held answer.
	 *
	 * @param string $id     The id.
	 * @param string $survey The survey.
	 * @param int    $score  The recommend score.
	 *
	 * @return void
	 */
	private function hold(string $id, string $survey, int $score): void {
		$this->store->seed('SurveyResponseHold', $id, ['surveyId' => $survey, 'orgUnitId' => 'unit-hr', 'contractType' => 'permanent', 'answers' => ['aanbevelen' => $score], 'administrationId' => 'ADM-001']);
	}//end hold()

	/**
	 * The gateway over the fake store.
	 *
	 * @return HoursRegisterGateway
	 */
	private function gateway(): HoursRegisterGateway {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		return new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
	}//end gateway()

	/**
	 * The release over the fake store.
	 *
	 * @return SurveyResponseRelease
	 */
	private function release(): SurveyResponseRelease {
		return new SurveyResponseRelease(gateway: $this->gateway(), marker: new InternalWriteMarker());
	}//end release()

	/**
	 * The survey service over the fake store.
	 *
	 * @return SurveyService
	 */
	private function surveys(): SurveyService {
		return new SurveyService(
			gateway: $this->gateway(),
			membership: new UnitMembership(),
			marker: new InternalWriteMarker(),
			rules: new SurveyRules(orgResolution: new OrgResolutionService(), results: new SurveyResults(new SurveyAnswers()), answers: new SurveyAnswers())
		);
	}//end surveys()

	/**
	 * Every stored row of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema): array {
		return array_values($this->store->setSchema($schema)->findAll());
	}//end rows()

}//end class
