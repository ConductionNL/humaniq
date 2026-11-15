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
	 * Who a survey goes to, and an employee's department and contract on a day.
	 *
	 * @var SurveyAudience
	 */
	private readonly SurveyAudience $audience;

	/**
	 * The survey service.
	 *
	 * @param HoursRegisterGateway $gateway       Register reads and writes.
	 * @param UnitMembership       $membership    Row ids and unit subtrees.
	 * @param InternalWriteMarker  $marker        Marks the response write as humaniq's own.
	 * @param SurveyRules          $rules         Placements on a day, answer checks and result folding.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly UnitMembership $membership,
		private readonly InternalWriteMarker $marker,
		private readonly SurveyRules $rules,
	) {
		$this->audience = new SurveyAudience(gateway: $gateway, membership: $membership, orgResolution: $rules->orgResolution);
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
		$refusal = self::openingRefusal(survey: $survey);
		if ($refusal !== null) {
			return $refusal + ['invited' => 0, 'withoutAccount' => 0];
		}

		$opening = new SurveyOpening();
		$opening->surveyId = $surveyId;
		$opening->survey = $survey;
		$opening->today = $today;
		$this->inviteAudience(opening: $opening);
		$this->markOpened(opening: $opening);

		return ['status' => 200, 'message' => null, 'invited' => $opening->invited, 'withoutAccount' => $opening->withoutAccount];
	}//end open()

	/**
	 * Why a survey cannot be opened: it does not exist, is not a draft, or
	 * has no question; null when it can.
	 *
	 * @param array<string, mixed>|null $survey The stored survey, or null.
	 *
	 * @return array{status: int, message: string}|null
	 */
	private static function openingRefusal(?array $survey): ?array {
		if ($survey === null) {
			return ['status' => 404, 'message' => 'Survey not found.'];
		}

		if ((string)($survey['status'] ?? 'concept') !== 'concept') {
			return ['status' => 409, 'message' => 'Only a draft survey can be opened.'];
		}

		if (array_filter((array)($survey['questions'] ?? []), 'is_array') === []) {
			return ['status' => 409, 'message' => 'Add at least one question before you open the survey.'];
		}

		return null;
	}//end openingRefusal()

	/**
	 * Invite every employee in scope with a Nextcloud account, and count
	 * those without one.
	 *
	 * @param SurveyOpening $opening The survey being opened.
	 *
	 * @return void
	 */
	private function inviteAudience(SurveyOpening $opening): void {
		foreach ($this->audience->employeesFor(survey: $opening->survey, today: $opening->today) as $employee) {
			$userId = trim((string)($employee['nextcloudUserId'] ?? ''));
			if ($userId === '') {
				$opening->withoutAccount++;
				continue;
			}

			$this->gateway->save(
				[
					'surveyId' => $opening->surveyId,
					'employeeId' => $this->membership->rowId($employee),
					'userId' => $userId,
					'status' => 'open',
					'closesOn' => ($opening->survey['closesOn'] ?? null),
					'administrationId' => ($employee['administrationId'] ?? null),
				],
				self::INVITATION
			);
			$opening->invited++;
		}
	}//end inviteAudience()

	/**
	 * Save the survey as open with its counts.
	 *
	 * @param SurveyOpening $opening The survey being opened.
	 *
	 * @return void
	 */
	private function markOpened(SurveyOpening $opening): void {
		$survey = $opening->survey;
		unset($survey['id']);
		// The openen transition's guard (SurveyOpenGuard) passes only inside this write.
		$opened = array_merge($survey, ['status' => 'open', 'invitedCount' => $opening->invited, 'withoutAccountCount' => $opening->withoutAccount]);
		$this->marker->runInternal(fn (): object => $this->gateway->save($opened, self::SURVEY, $opening->surveyId));
	}//end markOpened()

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
		$reply = new SurveyReply();
		$reply->surveyId = $surveyId;
		$reply->today = $today;
		$reply->survey = $this->gateway->findObjectData($surveyId, self::SURVEY);
		$reply->employee = $this->gateway->findEmployeeByUserId($uid);
		$reply->invitation = $this->invitationFor(reply: $reply);
		$refusal = $this->replyRefusal(reply: $reply);
		if ($refusal !== null) {
			return $refusal;
		}

		$clean = $this->rules->answers->clean(questions: (array)($reply->survey['questions'] ?? []), answers: $answers);
		if ($clean['missing'] !== []) {
			return ['status' => 400, 'message' => 'Answer every required question: ' . implode(', ', $clean['missing']) . '.'];
		}

		$this->storeReply(reply: $reply, answers: $clean['answers']);

		return ['status' => 201, 'message' => null];
	}//end respond()

	/**
	 * The caller's invitation to the survey; null without a survey or an
	 * employee record.
	 *
	 * @param SurveyReply $reply The survey and the caller's employee record.
	 *
	 * @return array<string, mixed>|null
	 */
	private function invitationFor(SurveyReply $reply): ?array {
		if ($reply->employee === null || $reply->survey === null) {
			return null;
		}

		return $this->invitationOf(surveyId: $reply->surveyId, employeeId: $this->membership->rowId($reply->employee));
	}//end invitationFor()

	/**
	 * Why the caller cannot answer: no invitation, already answered, or the
	 * survey takes no answers today; null when they can.
	 *
	 * @param SurveyReply $reply The survey, the caller and their invitation.
	 *
	 * @return array{status: int, message: string}|null
	 */
	private function replyRefusal(SurveyReply $reply): ?array {
		if ($reply->survey === null || $reply->employee === null || $reply->invitation === null) {
			return ['status' => 404, 'message' => 'You have no invitation for this survey.'];
		}

		if ((string)($reply->invitation['status'] ?? '') === 'beantwoord') {
			return ['status' => 409, 'message' => 'You already answered this survey.'];
		}

		if ($this->acceptsAnswers(survey: $reply->survey, today: $reply->today) === false) {
			return ['status' => 409, 'message' => 'This survey does not take answers now.'];
		}

		return null;
	}//end replyRefusal()

	/**
	 * Mark the invitation answered and store the answers with the caller's
	 * department and contract type, and nothing that names them.
	 *
	 * @param SurveyReply          $reply   The survey, the caller and their invitation.
	 * @param array<string, mixed> $answers The checked answers.
	 *
	 * @return void
	 */
	private function storeReply(SurveyReply $reply, array $answers): void {
		$invitation = (array)$reply->invitation;
		$invitationId = (string)$invitation['id'];
		unset($invitation['id']);
		$employeeId = $this->membership->rowId((array)$reply->employee);
		$response = [
			'surveyId' => $reply->surveyId,
			'orgUnitId' => $this->audience->unitOf(employeeId: $employeeId, today: $reply->today),
			'contractType' => $this->audience->contractTypeOf(employeeId: $employeeId, today: $reply->today),
			'answers' => $answers,
			'submittedOn' => $reply->today,
			'administrationId' => ($reply->survey['administrationId'] ?? null),
		];
		$this->marker->runInternal(function () use ($invitation, $invitationId, $response): void {
			$this->gateway->save(array_merge($invitation, ['status' => 'beantwoord']), self::INVITATION, $invitationId);
			$this->gateway->save($response, self::RESPONSE);
		});
	}//end storeReply()

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
		$computed = $this->rules->results->compute(survey: $survey, responses: $this->gateway->findFiltered(self::RESPONSE, ['surveyId' => $surveyId]), unitNames: $units);
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

}//end class
