<?php

/**
 * Pending claims
 *
 * Judges the pending records a hand-off writes to claim a run before it
 * writes another app: whether a pending record is fresh (its process may
 * still be working) and whether a claim has a rival.
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
	 * Whether any record of the run other than the claim is active.
	 *
	 * @param list<array<string, mixed>> $records The run's records.
	 * @param array<string, mixed>       $claim   The claim just written.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
	 */
	public function hasRival(array $records, array $claim): bool {
		$claimId = (string)($claim['id'] ?? $claim['@self']['id'] ?? '');
		foreach ($records as $record) {
			$recordId = (string)($record['id'] ?? $record['@self']['id'] ?? '');
			if ($recordId !== $claimId && in_array((string)($record['status'] ?? ''), $this->activeStatuses, true) === true) {
				return true;
			}
		}

		return false;
	}//end hasRival()

}//end class
