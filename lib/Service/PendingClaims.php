<?php

/**
 * Pending claims
 *
 * Judges the pending records a hand-off writes to claim a run before it
 * writes another app: whether a pending record is fresh (its process may
 * still be working), which record next to a claim holds the run, and
 * which stale ones are left to supersede.
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
 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The claim rules shared by the net pay and GL post hand-offs.
 *
 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 */
final class PendingClaims {

	/**
	 * How long a pending record counts as another process still working on
	 * the run. An older one is a crash left behind.
	 *
	 * @var int
	 */
	private const FRESH_SECONDS = 900;

	/**
	 * @param list<string> $activeStatuses The statuses that hold a run (pending and done).
	 *
	 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
	 */
	public function __construct(
		private readonly array $activeStatuses,
	) {
	}//end __construct()

	/**
	 * Whether a pending record was written recently enough that its process
	 * may still be working (OpenRegister's `@self.updated`, else `created`).
	 * A record without either is stale.
	 *
	 * @param array<string, mixed> $record The pending record.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
	 */
	public function isFresh(array $record): bool {
		$stamp = strtotime((string)($record['@self']['updated'] ?? $record['@self']['created'] ?? ''));

		return $stamp !== false && (time() - $stamp) < self::FRESH_SECONDS;
	}//end isFresh()

	/**
	 * The first record next to the claim that holds the run: an active
	 * record other than the claim, leaving out stale pending records,
	 * which are crashes left behind.
	 *
	 * @param list<array<string, mixed>> $records The records with the claim's number.
	 * @param array<string, mixed>       $claim   The claim just written.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
	 */
	public function rival(array $records, array $claim): ?array {
		foreach ($this->others($records, $claim) as $record) {
			$status = (string)($record['status'] ?? '');
			if (in_array($status, $this->activeStatuses, true) === true && ($status !== 'pending' || $this->isFresh($record) === true)) {
				return $record;
			}
		}

		return null;
	}//end rival()

	/**
	 * The stale pending records next to the claim, of this run or of
	 * another run with the same number, for the caller to supersede.
	 *
	 * @param list<array<string, mixed>> $records The records with the claim's number.
	 * @param array<string, mixed>       $claim   The claim just written.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
	 */
	public function staleOthers(array $records, array $claim): array {
		return array_values(array_filter($this->others($records, $claim), fn (array $record): bool => ($record['status'] ?? '') === 'pending' && $this->isFresh($record) === false));
	}//end staleOthers()

	/**
	 * Whether the rival belongs to another payroll run: two runs of one
	 * period and administration share a number, so the second is a
	 * duplicate run.
	 *
	 * @param array<string, mixed> $rival The rival record.
	 * @param array<string, mixed> $claim The claim just written.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
	 */
	public function isAnotherRun(array $rival, array $claim): bool {
		return trim((string)($rival['payrollRunId'] ?? '')) !== trim((string)($claim['payrollRunId'] ?? ''));
	}//end isAnotherRun()

	/**
	 * The records other than the claim.
	 *
	 * @param list<array<string, mixed>> $records The records.
	 * @param array<string, mixed>       $claim   The claim.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function others(array $records, array $claim): array {
		$claimId = (string)($claim['id'] ?? $claim['@self']['id'] ?? '');
		return array_values(array_filter($records, static fn (array $record): bool => (string)($record['id'] ?? $record['@self']['id'] ?? '') !== $claimId));
	}//end others()

}//end class
