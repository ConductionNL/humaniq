<?php

/**
 * SurveyService
 *
 * Anonymous engagement surveys. Opening a survey invites every active
 * employee in its scope who has a Nextcloud account; answering marks the
 * caller's invitation answered and stores the answers in a separate
 * response that names nobody (no employee, account, invitation or time of
 * day); the results fold every department below the survey's minimum
 * group size into "other", and leave "other" out when it is itself below
 * the minimum.
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Open, answer and read the results of a survey.
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */
class SurveyService {

	/**
	 * The survey schema.
	 *
	 * @var string
	 */
	public const SURVEY = 'Survey';

	/**
	 * The invitation schema.
	 *
	 * @var string
	 */
	public const INVITATION = 'SurveyInvitation';

	/**
	 * The response schema.
	 *
	 * @var string
	 */
	public const RESPONSE = 'SurveyResponse';

	/**
	 * The survey service.
	 *
	 * @param HoursRegisterGateway $gateway       Register reads and writes.
	 * @param UnitMembership       $membership    Row ids and unit subtrees.
	 * @param OrgResolutionService $orgResolution Whether a placement is active on a day.
	 * @param InternalWriteMarker  $marker        Marks the response write as humaniq's own.
	 * @param SurveyResults        $results       The folded results.
	 * @param SurveyAnswers        $answers       Checks answers against the questions.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly UnitMembership $membership,
		private readonly OrgResolutionService $orgResolution,
		private readonly InternalWriteMarker $marker,
		private readonly SurveyResults $results,
		private readonly SurveyAnswers $answers,
	) {
	}//end __construct()

	/**
	 * Open a survey: move it to open and invite every active employee in
	 * scope with a Nextcloud account. Employees without one are counted.
	 *
	 * @param string $surveyId The survey.
	 * @param string $today    The day, `YYYY-MM-DD`.
	 *
	 * @return array{status: int, message: string|null, invited: int, withoutAccount: int}
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
	 */
	public function open(string $surveyId, string $today): array {
		$survey = $this->gateway->findObjectData($surveyId, self::SURVEY);
		if ($survey === null) {
			return ['status' => 404, 'message' => 'Survey not found.', 'invited' => 0, 'withoutAccount' => 0];
		}

		if ((string)($survey['status'] ?? 'concept') !== 'concept') {
			return ['status' => 409, 'message' => 'Only a draft survey can be opened.', 'invited' => 0, 'withoutAccount' => 0];
		}

		if (array_filter((array)($survey['questions'] ?? []), 'is_array') === []) {
			return ['status' => 409, 'message' => 'Add at least one question before you open the survey.', 'invited' => 0, 'withoutAccount' => 0];
		}

		$invited = 0;
		$withoutAccount = 0;
		foreach ($this->inScope(survey: $survey, today: $today) as $employee) {
			$userId = trim((string)($employee['nextcloudUserId'] ?? ''));
			if ($userId === '') {
				$withoutAccount++;
				continue;
			}

			$this->gateway->save(
				[
					'surveyId' => $surveyId,
					'employeeId' => $this->membership->rowId($employee),
					'userId' => $userId,
					'status' => 'open',
					'closesOn' => ($survey['closesOn'] ?? null),
					'administrationId' => ($employee['administrationId'] ?? null),
				],
				self::INVITATION
			);
			$invited++;
		}

		unset($survey['id']);
		// The openen transition's guard (SurveyOpenGuard) passes only inside this write.
		$opened = array_merge($survey, ['status' => 'open', 'invitedCount' => $invited, 'withoutAccountCount' => $withoutAccount]);
		$this->marker->runInternal(fn (): object => $this->gateway->save($opened, self::SURVEY, $surveyId));

		return ['status' => 200, 'message' => null, 'invited' => $invited, 'withoutAccount' => $withoutAccount];
	}//end open()

	/**
	 * Answer a survey as the caller: their open invitation is marked
	 * answered and the answers are stored without anything that names them.
	 *
	 * @param string               $surveyId The survey.
	 * @param string               $uid      The caller.
	 * @param array<string, mixed> $answers  The answers by question key.
	 * @param string               $today    The day, `YYYY-MM-DD`.
	 *
	 * @return array{status: int, message: string|null}
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-002
	 */
	public function respond(string $surveyId, string $uid, array $answers, string $today): array {
		$survey = $this->gateway->findObjectData($surveyId, self::SURVEY);
		$employee = $this->gateway->findEmployeeByUserId($uid);
		$invitation = ($employee === null || $survey === null) ? null : $this->invitationOf(surveyId: $surveyId, employeeId: $this->membership->rowId($employee));
		if ($survey === null || $employee === null || $invitation === null) {
			return ['status' => 404, 'message' => 'You have no invitation for this survey.'];
		}

		if ((string)($invitation['status'] ?? '') === 'beantwoord') {
			return ['status' => 409, 'message' => 'You already answered this survey.'];
		}

		if ($this->acceptsAnswers(survey: $survey, today: $today) === false) {
			return ['status' => 409, 'message' => 'This survey does not take answers now.'];
		}

		$clean = $this->answers->clean(questions: (array)($survey['questions'] ?? []), answers: $answers);
		if ($clean['missing'] !== []) {
			return ['status' => 400, 'message' => 'Answer every required question: ' . implode(', ', $clean['missing']) . '.'];
		}

		$invitationId = (string)$invitation['id'];
		unset($invitation['id']);
		$employeeId = $this->membership->rowId($employee);
		$response = [
			'surveyId' => $surveyId,
			'orgUnitId' => $this->unitOf(employeeId: $employeeId, today: $today),
			'contractType' => $this->contractTypeOf(employeeId: $employeeId, today: $today),
			'answers' => $clean['answers'],
			'submittedOn' => $today,
			'administrationId' => ($survey['administrationId'] ?? null),
		];
		$this->marker->runInternal(function () use ($invitation, $invitationId, $response): void {
			$this->gateway->save(array_merge($invitation, ['status' => 'beantwoord']), self::INVITATION, $invitationId);
			$this->gateway->save($response, self::RESPONSE);
		});

		return ['status' => 201, 'message' => null];
	}//end respond()

	/**
	 * The results of a survey, with the response rate.
	 *
	 * @param string $surveyId The survey.
	 *
	 * @return array<string, mixed>|null Null when the survey does not exist.
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-003
	 */
	public function results(string $surveyId): ?array {
		$survey = $this->gateway->findObjectData($surveyId, self::SURVEY);
		if ($survey === null) {
			return null;
		}

		$invitations = $this->gateway->findFiltered(self::INVITATION, ['surveyId' => $surveyId]);
		$answered = count(array_filter($invitations, static fn (array $invitation): bool => ($invitation['status'] ?? '') === 'beantwoord'));
		$units = [];
		foreach ($this->gateway->loadAll('OrgUnit') as $unit) {
			$units[$this->membership->rowId($unit)] = (string)($unit['name'] ?? '');
		}

		$rate = (count($invitations) === 0 ? 0.0 : round($answered / count($invitations) * 100, 1));
		$computed = $this->results->compute(survey: $survey, responses: $this->gateway->findFiltered(self::RESPONSE, ['surveyId' => $surveyId]), unitNames: $units);
		$figures = [
			['figure' => 'Invited', 'value' => (string)count($invitations)],
			['figure' => 'Answered', 'value' => (string)$answered],
			['figure' => 'Response rate', 'value' => $rate . '%'],
			['figure' => 'Minimum group size', 'value' => (string)$computed['minGroupSize']],
		];
		foreach ($computed['notes'] as $note) {
			$figures[] = ['figure' => 'Note', 'value' => $note];
		}

		return array_merge(['surveyId' => $surveyId, 'invited' => count($invitations), 'answered' => $answered, 'rate' => $rate, 'figures' => $figures], $computed);
	}//end results()

	/**
	 * Whether the survey takes answers on a day.
	 *
	 * @param array<string, mixed> $survey The survey.
	 * @param string               $today  The day.
	 *
	 * @return bool
	 */
	private function acceptsAnswers(array $survey, string $today): bool {
		$opens = trim((string)($survey['opensOn'] ?? ''));
		$closes = trim((string)($survey['closesOn'] ?? ''));

		return ($survey['status'] ?? '') === 'open' && ($opens === '' || $opens <= $today) && ($closes === '' || $closes >= $today);
	}//end acceptsAnswers()

	/**
	 * The caller's invitation to a survey, or null.
	 *
	 * @param string $surveyId   The survey.
	 * @param string $employeeId The employee.
	 *
	 * @return array<string, mixed>|null
	 */
	private function invitationOf(string $surveyId, string $employeeId): ?array {
		return ($this->gateway->findFiltered(self::INVITATION, ['surveyId' => $surveyId, 'employeeId' => $employeeId])[0] ?? null);
	}//end invitationOf()

	/**
	 * The active employees the survey goes to.
	 *
	 * @param array<string, mixed> $survey The survey.
	 * @param string               $today  The day.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function inScope(array $survey, string $today): array {
		$administration = trim((string)($survey['administrationId'] ?? ''));
		$scope = (string)($survey['scope'] ?? 'everyone');
		$audience = ($scope === 'orgUnits') ? $this->audienceUnits(survey: $survey) : [];
		$out = [];
		foreach ($this->gateway->loadAll('Employee') as $employee) {
			if ($this->isActive(employee: $employee, today: $today) === false
				|| ($administration !== '' && $administration !== trim((string)($employee['administrationId'] ?? '')))
			) {
				continue;
			}

			$employeeId = $this->membership->rowId($employee);
			if (($scope === 'orgUnits' && in_array($this->unitOf(employeeId: $employeeId, today: $today), $audience, true) === false)
				|| ($scope === 'contractType' && $this->contractTypeOf(employeeId: $employeeId, today: $today) !== (string)($survey['contractType'] ?? ''))
			) {
				continue;
			}

			$out[] = $employee;
		}

		return $out;
	}//end inScope()

	/**
	 * Whether the employee is in service on the day.
	 *
	 * @param array<string, mixed> $employee The employee.
	 * @param string               $today    The day.
	 *
	 * @return bool
	 */
	private function isActive(array $employee, string $today): bool {
		$start = trim((string)($employee['startDate'] ?? ''));
		$end = trim((string)($employee['endDate'] ?? ''));

		return ($start === '' || $start <= $today) && ($end === '' || $end >= $today);
	}//end isActive()

	/**
	 * The chosen departments with their teams.
	 *
	 * @param array<string, mixed> $survey The survey.
	 *
	 * @return list<string>
	 */
	private function audienceUnits(array $survey): array {
		$units = $this->gateway->loadAll('OrgUnit');
		$out = [];
		foreach ((array)($survey['orgUnitIds'] ?? []) as $unitId) {
			$out = array_merge($out, $this->membership->subtree((string)$unitId, $units));
		}

		return array_values(array_unique($out));
	}//end audienceUnits()

	/**
	 * The employee's department on the day: the first active placement.
	 *
	 * @param string $employeeId The employee.
	 * @param string $today      The day.
	 *
	 * @return string|null
	 */
	private function unitOf(string $employeeId, string $today): ?string {
		foreach ($this->gateway->findFiltered('OrgAssignment', ['employeeId' => $employeeId]) as $assignment) {
			if ($this->orgResolution->isActiveOn($assignment, $today) === true) {
				return (string)($assignment['orgUnitId'] ?? '');
			}
		}

		return null;
	}//end unitOf()

	/**
	 * The type of the employee's contract on the day.
	 *
	 * @param string $employeeId The employee.
	 * @param string $today      The day.
	 *
	 * @return string|null
	 */
	private function contractTypeOf(string $employeeId, string $today): ?string {
		foreach ($this->gateway->findFiltered('EmploymentContract', ['employeeId' => $employeeId]) as $contract) {
			$start = trim((string)($contract['startDate'] ?? ''));
			$end = trim((string)($contract['endDate'] ?? ''));
			if (($start === '' || $start <= $today) && ($end === '' || $end >= $today)) {
				return (string)($contract['type'] ?? '');
			}
		}

		return null;
	}//end contractTypeOf()

}//end class
