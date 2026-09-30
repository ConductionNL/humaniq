<?php

/**
 * Humaniq ExitInterviewService
 *
 * The rules around an exit interview (hiring-offboarding-completion D1): a
 * new interview takes its leaver, administration and department from its
 * offboarding case, and stamps the case's `exitGesprekDone` with the day it
 * was held; HR sees the leavers' own reasons counted over the last twelve
 * months; and after 90 days an interview loses its person link and free
 * text while its reason, scores, department and date stay for reporting.
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCP\IL10N;

/**
 * Exit interview fill-in, case stamp, reason counts and anonymisation.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
 */
class ExitInterviewService {

	public const SCHEMA = 'ExitInterview';

	/**
	 * The fields cleared when an interview is anonymised.
	 */
	public const PERSONAL_FIELDS = ['employeeId', 'offboardingId', 'conductedBy', 'whatWorked', 'whatToImprove'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The register read and write.
	 * @param InternalWriteMarker  $marker  Marks humaniq's own writes.
	 * @param IL10N                $l10n    The reason labels.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * What a new interview takes from its case: the leaver, the administration
	 * and the leaver's department on the day it was held.
	 *
	 * @param array<string, mixed> $interview The interview being created.
	 *
	 * @return array<string, mixed> The fields to set; empty when there is nothing to add.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	public function fillIn(array $interview): array {
		$case = $this->gateway->findObjectData((string)($interview['offboardingId'] ?? ''), 'Offboarding');
		$employeeId = trim((string)($interview['employeeId'] ?? ''));
		if ($employeeId === '' && $case !== null) {
			$employeeId = (string)($case['employeeId'] ?? '');
		}

		if ($employeeId === '') {
			return [];
		}

		$stamps = ['employeeId' => $employeeId];
		$employee = $this->gateway->findObjectData($employeeId, 'Employee');
		if (trim((string)($interview['administrationId'] ?? '')) === '' && $employee !== null) {
			$stamps['administrationId'] = ($employee['administrationId'] ?? null);
		}

		if (trim((string)($interview['orgUnitId'] ?? '')) === '') {
			$stamps['orgUnitId'] = $this->unitOn(employeeId: $employeeId, day: (string)($interview['heldOn'] ?? ''));
		}

		return array_filter($stamps, static fn (mixed $value): bool => $value !== null && $value !== '');
	}//end fillIn()

	/**
	 * Stamp the case's exit interview date with the day the interview was held.
	 *
	 * @param array<string, mixed> $interview The interview that was created.
	 *
	 * @return bool Whether the case was written.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	public function stampCase(array $interview): bool {
		$caseId = trim((string)($interview['offboardingId'] ?? ''));
		$heldOn = trim((string)($interview['heldOn'] ?? ''));
		$case = $this->gateway->findObjectData($caseId, 'Offboarding');
		if ($case === null || $heldOn === '' || ($case['exitGesprekDone'] ?? null) === $heldOn) {
			return false;
		}

		unset($case['id'], $case['@self']);
		$case['exitGesprekDone'] = $heldOn;
		$this->marker->runInternal(fn () => $this->gateway->save($case, 'Offboarding', $caseId));

		return true;
	}//end stampCase()

	/**
	 * The leavers' own reasons counted over the months before today, most
	 * named first. Anonymised interviews count too.
	 *
	 * @param string $today  The day, `YYYY-MM-DD`.
	 * @param int    $months How many months back.
	 *
	 * @return array{from: string, until: string, total: int, reasons: array<int, array{reason: string, label: string, count: int}>}
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	public function reasonCounts(string $today, int $months=12): array {
		$from = (new DateTimeImmutable($today))->modify('-' . max(1, $months) . ' months')->format('Y-m-d');
		$counts = [];
		foreach ($this->gateway->loadAll(self::SCHEMA) as $interview) {
			$heldOn = (string)($interview['heldOn'] ?? '');
			$reason = (string)($interview['mainReason'] ?? '');
			if ($reason === '' || $heldOn < $from || $heldOn > $today) {
				continue;
			}

			$counts[$reason] = (($counts[$reason] ?? 0) + 1);
		}

		arsort($counts);
		$labels = $this->labels();
		$rows = [];
		foreach ($counts as $reason => $count) {
			$rows[] = ['reason' => (string)$reason, 'label' => ($labels[$reason] ?? (string)$reason), 'count' => $count];
		}

		return ['from' => $from, 'until' => $today, 'total' => array_sum($counts), 'reasons' => $rows];
	}//end reasonCounts()

	/**
	 * Clear the person link and free text of every interview held more than
	 * the given days ago and not anonymised yet.
	 *
	 * @param string $today     The day, `YYYY-MM-DD`.
	 * @param string $now       The moment, ISO 8601.
	 * @param int    $afterDays After how many days.
	 *
	 * @return array<int, string> The interviews anonymised.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public function anonymiseDue(string $today, string $now, int $afterDays=90): array {
		$before = (new DateTimeImmutable($today))->modify('-' . max(1, $afterDays) . ' days')->format('Y-m-d');
		$done = [];
		foreach ($this->gateway->loadAll(self::SCHEMA) as $interview) {
			$heldOn = (string)($interview['heldOn'] ?? '');
			if ($heldOn === '' || $heldOn >= $before || trim((string)($interview['anonymisedAt'] ?? '')) !== '') {
				continue;
			}

			$id = (string)($interview['id'] ?? '');
			unset($interview['id'], $interview['@self']);
			foreach (self::PERSONAL_FIELDS as $field) {
				$interview[$field] = null;
			}

			$interview['anonymisedAt'] = $now;
			$this->marker->runInternal(fn () => $this->gateway->save($interview, self::SCHEMA, $id));
			$done[] = $id;
		}

		return $done;
	}//end anonymiseDue()

	/**
	 * The department the employee is placed in on a day, or null.
	 *
	 * @param string $employeeId The employee.
	 * @param string $day        The day, `YYYY-MM-DD`; empty means today.
	 *
	 * @return string|null
	 */
	private function unitOn(string $employeeId, string $day): ?string {
		$day = ($day === '' ? gmdate('Y-m-d') : $day);
		foreach ($this->gateway->findFiltered('OrgAssignment', ['employeeId' => $employeeId]) as $placement) {
			$start = (string)($placement['startDate'] ?? '');
			$end = (string)($placement['endDate'] ?? '');
			if ($start <= $day && ($end === '' || $end >= $day)) {
				return ((string)($placement['orgUnitId'] ?? '') ?: null);
			}
		}

		return null;
	}//end unitOn()

	/**
	 * The reason labels.
	 *
	 * @return array<string, string>
	 */
	private function labels(): array {
		return [
			'salaris' => $this->l10n->t('Pay'),
			'loopbaan' => $this->l10n->t('Career'),
			'leidinggevende' => $this->l10n->t('Manager'),
			'werkdruk' => $this->l10n->t('Workload'),
			'werk-prive' => $this->l10n->t('Work and private life'),
			'verhuizing' => $this->l10n->t('Moving house'),
			'pensioen' => $this->l10n->t('Retirement'),
			'anders' => $this->l10n->t('Other'),
		];
	}//end labels()
}//end class
