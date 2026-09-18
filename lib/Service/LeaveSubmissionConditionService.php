<?php

/**
 * Leave Submission Condition Service
 *
 * The conditions a leave type puts on submitting a request of that type: a
 * reason, a document, and how far ahead it may be asked for.
 *
 * WHY THE CHECK IS ON SUBMIT AND NOT ON SAVE
 * ------------------------------------------
 * A draft is a person thinking. Judging it as it is typed refuses a request
 * for missing exactly what the person is about to fill in, which teaches them
 * to fill the field with a space. Submitting is the moment a claim is made,
 * and that is where a condition belongs (REQ-LVM-T02).
 *
 * WHY THE REFUSAL NAMES THE CONDITION
 * -----------------------------------
 * "Deze aanvraag kan niet worden ingediend" leaves the employee guessing which
 * of three conditions they missed, and the two they did satisfy look equally
 * suspect. Each refusal below says which condition failed and, for the notice
 * period, what the limit actually is.
 *
 * Deliberately dependency-free: the caller supplies the request, its resolved
 * type and the date it is judged against, so every branch is reachable from a
 * unit test without a Nextcloud bootstrap.
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
 * @spec openspec/specs/leave-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Says whether a leave request may be submitted under its type's conditions,
 * and which condition stops it when it may not.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T02
 */
class LeaveSubmissionConditionService {

	/**
	 * The refusal for one submit, or null when the request may be submitted.
	 *
	 * @param array<string, mixed> $request The LeaveRequest payload.
	 * @param array<string, mixed>|null $type The resolved LeaveType, or null when none resolves.
	 * @param DateTimeImmutable $today The day the submission is judged on.
	 *
	 * @return string|null The refusal, naming the condition, or null.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T02
	 */
	public function refusal(array $request, ?array $type, DateTimeImmutable $today): ?string {
		if ($type === null) {
			// An unadministered type carries no conditions. It is not this
			// service's business to refuse a request for a type nobody wrote:
			// the picker decides which types are offered.
			return null;
		}

		$label = trim((string)($type['label'] ?? ($type['code'] ?? 'dit verlof')));

		if (($type['requiresReason'] ?? false) === true && trim((string)($request['reason'] ?? '')) === '') {
			return 'Voor ' . $label . ' is een reden verplicht. Vul de reden in en dien de aanvraag opnieuw in.';
		}

		if (($type['requiresDocument'] ?? false) === true && trim((string)($request['documentId'] ?? '')) === '') {
			return 'Voor ' . $label . ' is een document verplicht. Voeg het document toe en dien de aanvraag opnieuw in.';
		}

		return $this->noticeRefusal(request: $request, type: $type, today: $today, label: $label);
	}//end refusal()

	/**
	 * The refusal for a request made further ahead than its type allows.
	 *
	 * @param array<string, mixed> $request The LeaveRequest payload.
	 * @param array<string, mixed> $type The resolved LeaveType.
	 * @param DateTimeImmutable $today The day the submission is judged on.
	 * @param string $label What this kind of leave is called.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T02
	 */
	private function noticeRefusal(
		array $request,
		array $type,
		DateTimeImmutable $today,
		string $label,
	): ?string {
		$maxNoticeDays = ($type['maxNoticeDays'] ?? null);
		if (is_numeric($maxNoticeDays) === false) {
			return null;
		}

		$start = $this->date(value: ($request['startDate'] ?? null));
		if ($start === null) {
			// A request with no start date cannot be measured against a notice
			// period. The schema requires one, so this is not the place to
			// refuse it a second time.
			return null;
		}

		$daysAhead = (int)$today->setTime(hour: 0, minute: 0)
			->diff($start->setTime(hour: 0, minute: 0))
			->format('%r%a');

		if ($daysAhead <= (int)$maxNoticeDays) {
			return null;
		}

		return 'Voor ' . $label . ' kan maximaal ' . (int)$maxNoticeDays
			. ' dagen vooruit verlof worden aangevraagd; deze aanvraag begint over ' . $daysAhead . ' dagen.';
	}//end noticeRefusal()

	/**
	 * Narrow a raw value to a date.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return DateTimeImmutable|null The date, or null when unusable.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T02
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable(substr(trim($value), 0, 10));
		} catch (\Throwable $e) {
			return null;
		}
	}//end date()
}//end class
