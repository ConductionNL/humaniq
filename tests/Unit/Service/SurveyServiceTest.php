<?php

/**
 * SurveyServiceTest
 *
 * Anonymous engagement surveys: opening invites the active employees in
 * scope who have an account, an answer is stored without anything that
 * names the employee and only once, and the results fold departments below
 * the minimum group size.
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\SurveyAnswers;
use OCA\Humaniq\Service\SurveyResults;
use OCA\Humaniq\Service\SurveyService;
use OCA\Humaniq\Service\UnitMembership;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */
class SurveyServiceTest extends TestCase {

	private const TODAY = '2026-10-05';

	/**
	 * A uuid, because SurveyInvitation and SurveyResponse relate to the survey by uuid.
	 */
	private const SURVEY_ID = '5a1e0000-0000-4000-8000-000000000001';

	private FakeObjectStore $store;

	/**
	 * Finance (five employees, one without an account), HR (five) and Legal
	 * (two), one employee who left, and a draft survey for Finance and HR.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('OrgUnit', 'unit-fin', ['name' => 'Finance', 'administrationId' => 'ADM-001']);
		$this->store->seed('OrgUnit', 'unit-fin-team', ['name' => 'Payables', 'parentUnitId' => 'unit-fin', 'administrationId' => 'ADM-001']);
		$this->store->seed('OrgUnit', 'unit-hr', ['name' => 'HR', 'administrationId' => 'ADM-001']);
		$this->store->seed('OrgUnit', 'unit-legal', ['name' => 'Legal', 'administrationId' => 'ADM-001']);
		foreach (['f1', 'f2', 'f3', 'f4'] as $i => $name) {
			$this->employee('emp-' . $name, $name, ($i === 3 ? 'unit-fin-team' : 'unit-fin'), 'permanent');
		}

		$this->employee('emp-f5', '', 'unit-fin', 'permanent');
		foreach (['h1', 'h2', 'h3', 'h4', 'h5'] as $name) {
			$this->employee('emp-' . $name, $name, 'unit-hr', ($name === 'h5' ? 'temporary' : 'permanent'));
		}

		$this->employee('emp-l1', 'l1', 'unit-legal', 'permanent');
		$this->employee('emp-l2', 'l2', 'unit-legal', 'temporary');
		$this->store->seed('Employee', 'emp-left', ['firstName' => 'Oud', 'lastName' => 'Collega', 'nextcloudUserId' => 'oud', 'administrationId' => 'ADM-001', 'startDate' => '2020-01-01', 'endDate' => '2026-01-31']);
		$this->store->seed('OrgAssignment', 'asg-left', ['employeeId' => 'emp-left', 'orgUnitId' => 'unit-fin', 'startDate' => '2020-01-01']);
		$this->store->seed('Survey', self::SURVEY_ID, $this->survey(['scope' => 'orgUnits', 'orgUnitIds' => ['unit-fin', 'unit-hr']]));
	}//end setUp()

	/**
	 * Scenario: a survey goes to two teams. Nine employees with an account
	 * are invited, the one without is counted, Legal and the leaver are not.
	 *
	 * @return void
	 */
	public function testASurveyGoesToTwoTeams(): void {
		$outcome = $this->service()->open(self::SURVEY_ID, self::TODAY);

		self::assertSame(['status' => 200, 'message' => null, 'invited' => 9, 'withoutAccount' => 1], $outcome);
		$invitations = $this->rows('SurveyInvitation');
		self::assertCount(9, $invitations);
		self::assertSame(['f1', 'f2', 'f3', 'f4', 'h1', 'h2', 'h3', 'h4', 'h5'], $this->sorted(array_column($invitations, 'userId')));
		self::assertSame(['open'], array_values(array_unique(array_column($invitations, 'status'))));
		self::assertSame('2026-10-20', $invitations[0]['closesOn']);
		// The fixture's employees have readable ids (emp-f1); on the instance they are uuids.
		self::assertSame('emp-', substr((string)$invitations[0]['employeeId'], 0, 4));
		self::assertSame([], RegisterSchemaValidator::errors('SurveyInvitation', ['employeeId' => '5a1e0000-0000-4000-8000-0000000000e1'] + $invitations[0]));

		$survey = $this->row('Survey', self::SURVEY_ID);
		self::assertSame(['open', 9, 1], [$survey['status'], $survey['invitedCount'], $survey['withoutAccountCount']]);
		self::assertSame(['surveyId' => self::SURVEY_ID, 'invited' => 9, 'answered' => 0, 'rate' => 0.0], array_intersect_key($this->service()->results(self::SURVEY_ID), array_flip(['surveyId', 'invited', 'answered', 'rate'])));
	}//end testASurveyGoesToTwoTeams()

	/**
	 * A survey opens once, needs a question, and can go to everyone or to
	 * one contract type.
	 *
	 * @return void
	 */
	public function testOpeningRules(): void {
		self::assertSame(404, $this->service()->open('srv-x', self::TODAY)['status']);
		$this->store->seed('Survey', 'srv-empty', $this->survey(['questions' => []]));
		self::assertSame(409, $this->service()->open('srv-empty', self::TODAY)['status']);

		$this->store->seed('Survey', 'srv-all', $this->survey(['scope' => 'everyone']));
		self::assertSame(11, $this->service()->open('srv-all', self::TODAY)['invited']);
		self::assertSame(409, $this->service()->open('srv-all', self::TODAY)['status'], 'An open survey is not opened again.');

		$this->store->seed('Survey', 'srv-temp', $this->survey(['scope' => 'contractType', 'contractType' => 'temporary']));
		self::assertSame(['status' => 200, 'message' => null, 'invited' => 2, 'withoutAccount' => 0], $this->service()->open('srv-temp', self::TODAY));
	}//end testOpeningRules()

	/**
	 * Scenario: HR cannot find out who wrote what. The response holds the
	 * survey, the answers, the department, the contract type and the day,
	 * and nothing else; the invitation only says the employee answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
	 */
	public function testAnAnswerNamesNobody(): void {
		$this->service()->open(self::SURVEY_ID, self::TODAY);

		$outcome = $this->service()->respond(self::SURVEY_ID, 'f4', ['werkplezier' => 4, 'aanbevelen' => '9', 'werkdruk' => 'te hoog', 'toelichting' => '  Meer overleg.  ', 'onbekend' => 'x'], self::TODAY);

		self::assertSame(['status' => 201, 'message' => null], $outcome);
		$responses = $this->rows('SurveyResponse');
		self::assertCount(1, $responses);
		$response = $responses[0];
		unset($response['id']);
		self::assertSame(['surveyId', 'orgUnitId', 'contractType', 'answers', 'submittedOn', 'administrationId'], array_keys($response));
		self::assertSame(['werkplezier' => 4, 'werkdruk' => 'te hoog', 'aanbevelen' => 9, 'toelichting' => 'Meer overleg.'], $response['answers']);
		self::assertSame(['unit-fin-team', 'permanent', self::TODAY], [$response['orgUnitId'], $response['contractType'], $response['submittedOn']]);
		self::assertSame([], RegisterSchemaValidator::errors('SurveyResponse', $response));
		$encoded = (string)json_encode($response);
		$invitationIds = array_column($this->rows('SurveyInvitation'), 'id');
		foreach (array_merge(['emp-f4', '"f4"', self::TODAY . 'T'], $invitationIds) as $needle) {
			self::assertStringNotContainsString($needle, $encoded);
		}

		$invitation = array_values(array_filter($this->rows('SurveyInvitation'), static fn (array $row): bool => $row['userId'] === 'f4'))[0];
		self::assertSame('beantwoord', $invitation['status']);
		self::assertArrayNotHasKey('answers', $invitation);
	}//end testAnAnswerNamesNobody()

	/**
	 * Scenario: one answer per person. A second answer, a caller without an
	 * invitation, a missing required answer and a closed survey are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
	 */
	public function testOneAnswerPerPerson(): void {
		$this->service()->open(self::SURVEY_ID, self::TODAY);
		$answers = ['werkplezier' => 3, 'aanbevelen' => 7, 'werkdruk' => 'goed'];
		self::assertSame(201, $this->service()->respond(self::SURVEY_ID, 'h1', $answers, self::TODAY)['status']);

		self::assertSame(409, $this->service()->respond(self::SURVEY_ID, 'h1', $answers, self::TODAY)['status']);
		self::assertSame(404, $this->service()->respond(self::SURVEY_ID, 'l1', $answers, self::TODAY)['status'], 'Legal was not invited.');
		self::assertSame(404, $this->service()->respond(self::SURVEY_ID, 'nobody', $answers, self::TODAY)['status']);
		self::assertSame(404, $this->service()->respond('srv-x', 'h2', $answers, self::TODAY)['status']);
		$missing = $this->service()->respond(self::SURVEY_ID, 'h2', ['werkplezier' => 9, 'werkdruk' => 'goed'], self::TODAY);
		self::assertSame(400, $missing['status']);
		self::assertStringContainsString('werkplezier, aanbevelen', (string)$missing['message']);
		self::assertSame(409, $this->service()->respond(self::SURVEY_ID, 'h2', $answers, '2026-10-21')['status'], 'After closesOn no answers are taken.');
		self::assertCount(1, $this->rows('SurveyResponse'));
	}//end testOneAnswerPerPerson()

	/**
	 * Scenario: a small team stays anonymous. With minimum 5, Finance (7)
	 * and HR (5) are shown, Legal (2) is not, and the overall figures count
	 * all 14 responses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-003
	 */
	public function testASmallTeamStaysAnonymous(): void {
		$this->store->seed('Survey', 'srv-closed', $this->survey(['status' => 'gesloten']));
		$scores = ['unit-fin' => [10, 9, 9, 8, 7, 6, 3], 'unit-hr' => [10, 10, 8, 5, 9], 'unit-legal' => [2, 10]];
		$n = 0;
		foreach ($scores as $unit => $list) {
			foreach ($list as $score) {
				$this->store->seed('SurveyResponse', 'resp-' . (++$n), ['surveyId' => 'srv-closed', 'orgUnitId' => $unit, 'contractType' => 'permanent', 'answers' => ['aanbevelen' => $score, 'werkplezier' => 4, 'werkdruk' => 'goed', 'toelichting' => 'tekst ' . $n], 'submittedOn' => '2026-10-01']);
			}
		}

		$results = $this->service()->results('srv-closed');

		self::assertSame(14, $results['responses']);
		self::assertSame(['Finance', 'HR'], array_column($results['units'], 'name'));
		self::assertSame([7, 5], array_column($results['units'], 'responses'));
		self::assertNotSame([], $results['notes']);
		$overall = array_column($results['overall'], null, 'key');
		self::assertSame(14, $overall['aanbevelen']['count']);
		self::assertSame(21, $overall['aanbevelen']['enps'], '7 promoters and 4 detractors of 14: (7 - 4) / 14 = 21%.');
		self::assertSame(1, $overall['aanbevelen']['distribution']['2']);
		self::assertCount(14, $overall['toelichting']['texts']);
		self::assertArrayNotHasKey('texts', array_column($results['units'][0]['questions'], null, 'key')['toelichting'], 'Free text is never shown per department.');
		self::assertSame(['Overall', 'Overall', 'Overall', 'Finance'], array_slice(array_column($results['rows'], 'group'), 0, 4));
		self::assertNull($this->service()->results('srv-x'));
	}//end testASmallTeamStaysAnonymous()

	/**
	 * Two small departments that reach the minimum together are shown as
	 * Other; fewer responses than the minimum show nothing overall.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-003
	 */
	public function testSmallDepartmentsFoldIntoOther(): void {
		$this->store->seed('Survey', 'srv-fold', $this->survey(['status' => 'gesloten', 'minGroupSize' => 3]));
		foreach (['unit-fin' => 3, 'unit-hr' => 2, 'unit-legal' => 1] as $unit => $count) {
			for ($i = 0; $i < $count; $i++) {
				$this->store->seed('SurveyResponse', 'fold-' . $unit . $i, ['surveyId' => 'srv-fold', 'orgUnitId' => $unit, 'answers' => ['werkplezier' => 5, 'werkdruk' => 'te laag'], 'submittedOn' => '2026-10-01']);
			}
		}

		$results = $this->service()->results('srv-fold');
		self::assertSame([['Finance', 3], ['Other', 3]], array_map(static fn (array $unit): array => [$unit['name'], $unit['responses']], $results['units']));
		self::assertSame([], $results['notes']);
		$overall = array_column($results['overall'], null, 'key');
		self::assertSame(['te laag' => 6], $overall['werkdruk']['distribution']);
		self::assertSame(5.0, $overall['werkplezier']['average']);

		$this->store->seed('Survey', 'srv-few', $this->survey(['status' => 'gesloten']));
		$this->store->seed('SurveyResponse', 'few-1', ['surveyId' => 'srv-few', 'orgUnitId' => 'unit-fin', 'answers' => ['werkplezier' => 2], 'submittedOn' => '2026-10-01']);
		$few = $this->service()->results('srv-few');
		self::assertSame([[], []], [$few['overall'], $few['units']]);
		self::assertCount(2, $few['notes']);
	}//end testSmallDepartmentsFoldIntoOther()

	/**
	 * The answers are checked against the questions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
	 */
	public function testAnswersAreCheckedAgainstTheQuestions(): void {
		$questions = $this->survey([])['questions'];
		$questions[] = ['key' => 'kanaal', 'type' => 'enum', 'label' => 'Kanaal', 'options' => ['choices' => ['mail', 'chat']]];
		$questions[] = 'not a question';
		$clean = (new SurveyAnswers())->clean($questions, ['werkplezier' => 6, 'aanbevelen' => 11, 'werkdruk' => 'veel', 'toelichting' => str_repeat('a', 2100), 'kanaal' => 'chat']);

		self::assertSame(['werkplezier', 'werkdruk', 'aanbevelen'], $clean['missing']);
		self::assertSame(['toelichting', 'kanaal'], array_keys($clean['answers']));
		self::assertSame(2000, mb_strlen((string)$clean['answers']['toelichting']));
		self::assertSame([0, 10], (new SurveyAnswers())->range(['type' => 'recommend', 'options' => ['min' => 1, 'max' => 5]]));
		self::assertSame([1, 7], (new SurveyAnswers())->range(['type' => 'scale', 'options' => ['min' => 1, 'max' => 7]]));
	}//end testAnswersAreCheckedAgainstTheQuestions()

	/**
	 * A survey payload.
	 *
	 * @param array<string, mixed> $overrides The fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function survey(array $overrides): array {
		return array_merge([
			'title' => 'Medewerkersonderzoek najaar 2026',
			'questions' => [
				['key' => 'werkplezier', 'type' => 'scale', 'label' => 'Hoeveel plezier heb je in je werk?', 'required' => true, 'options' => ['min' => 1, 'max' => 5]],
				['key' => 'werkdruk', 'type' => 'enum', 'label' => 'Hoe ervaar je de werkdruk?', 'required' => true, 'options' => ['te laag', 'goed', 'te hoog']],
				['key' => 'aanbevelen', 'type' => 'recommend', 'label' => 'Zou je ons aanbevelen als werkgever?', 'required' => true, 'options' => []],
				['key' => 'toelichting', 'type' => 'textarea', 'label' => 'Wat zou je willen verbeteren?', 'required' => false, 'options' => []],
			],
			'scope' => 'everyone',
			'orgUnitIds' => [],
			'opensOn' => '2026-10-01',
			'closesOn' => '2026-10-20',
			'minGroupSize' => 5,
			'status' => 'concept',
			'administrationId' => 'ADM-001',
		], $overrides);
	}//end survey()

	/**
	 * An employee with a placement and a contract.
	 *
	 * @param string $id       The employee id.
	 * @param string $uid      The account, or '' without one.
	 * @param string $unit     The department.
	 * @param string $contract The contract type.
	 *
	 * @return void
	 */
	private function employee(string $id, string $uid, string $unit, string $contract): void {
		$this->store->seed('Employee', $id, ['firstName' => ucfirst($uid), 'lastName' => 'Test', 'nextcloudUserId' => ($uid === '' ? null : $uid), 'administrationId' => 'ADM-001', 'startDate' => '2024-01-01']);
		$this->store->seed('OrgAssignment', 'asg-' . $id, ['employeeId' => $id, 'orgUnitId' => $unit, 'startDate' => '2024-01-01']);
		$this->store->seed('EmploymentContract', 'ctr-' . $id, ['employeeId' => $id, 'type' => $contract, 'startDate' => '2024-01-01']);
	}//end employee()

	/**
	 * The service over the fake store.
	 *
	 * @return SurveyService
	 */
	private function service(): SurveyService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);

		return new SurveyService(
			gateway: $gateway,
			membership: new UnitMembership(),
			orgResolution: new OrgResolutionService(),
			marker: new InternalWriteMarker(),
			results: new SurveyResults(new SurveyAnswers()),
			answers: new SurveyAnswers()
		);
	}//end service()

	/**
	 * Every stored row of a schema, with its id.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema): array {
		return array_values($this->store->setSchema($schema)->findAll());
	}//end rows()

	/**
	 * One stored row.
	 *
	 * @param string $schema The schema.
	 * @param string $uuid   The id.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $schema, string $uuid): array {
		return $this->store->find($uuid, schema: $schema)->getObject();
	}//end row()

	/**
	 * Sorted values.
	 *
	 * @param array<int, mixed> $values The values.
	 *
	 * @return list<mixed>
	 */
	private function sorted(array $values): array {
		sort($values);

		return array_values($values);
	}//end sorted()

}//end class
