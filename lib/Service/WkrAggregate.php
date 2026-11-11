<?php

/**
 * WKR aggregate buckets
 *
 * Accumulates the per-(administrationId, year) fiscale-loonsom and
 * vrije-ruimte figures `WkrService` assesses: Σ Payslip.grossPay and
 * Σ Payslip.wkrUsed per wage year, plus the declared vrije-ruimte and
 * eindheffing amounts per declaration year. Gericht-vrijgesteld
 * declarations never count.
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
 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Per-(administrationId, year) loonsom and vrije-ruimte buckets.
 *
 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-003
 */
final class WkrAggregate {

	/**
	 * The bucket field each counted declaration category adds its amount to.
	 *
	 * @var array<string, string>
	 */
	private const DECLARED_FIELD_BY_CATEGORY = [
		'vrije-ruimte' => 'vrijeRuimteDeclared',
		'eindheffing' => 'eindheffingDeclared',
	];

	/**
	 * Buckets keyed by administrationId, then year.
	 *
	 * @var array<string, array<int, array<string, float>>>
	 */
	private array $buckets = [];

	/**
	 * Add a payslip's grossPay and wkrUsed to the bucket of its wage year;
	 * a payslip without an administration or a parseable period is skipped.
	 *
	 * @param string $administrationId The payslip's effective administration.
	 * @param array<string, mixed> $payslip The payslip row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-003
	 */
	public function addPayslip(string $administrationId, array $payslip): void {
		if ($administrationId === '') {
			return;
		}

		$year = self::yearFromPeriod((string)($payslip['period'] ?? ''));
		if ($year === null) {
			return;
		}

		$bucket = $this->bucket(administrationId: $administrationId, year: $year);
		$bucket['loonsom'] += ((float)($payslip['grossPay'] ?? 0));
		$bucket['payslipWkrUsed'] += ((float)($payslip['wkrUsed'] ?? 0));
		$this->buckets[$administrationId][$year] = $bucket;

	}//end addPayslip()

	/**
	 * Add a declaration's amount to its category's field; a declaration
	 * without administration or year, or a gericht-vrijgesteld one, is skipped.
	 *
	 * @param array<string, mixed> $declaration The WkrDeclaration row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-003
	 */
	public function addDeclaration(array $declaration): void {
		$administrationId = trim((string)($declaration['administrationId'] ?? ''));
		$year = (int)($declaration['year'] ?? 0);
		$category = (string)($declaration['wkrCategory'] ?? '');
		if ($administrationId === '' || $year === 0 || $category === 'gericht-vrijgesteld') {
			return;
		}

		$bucket = $this->bucket(administrationId: $administrationId, year: $year);
		$field = (self::DECLARED_FIELD_BY_CATEGORY[$category] ?? null);
		if ($field !== null) {
			$bucket[$field] += ((float)($declaration['amount'] ?? 0));
		}

		$this->buckets[$administrationId][$year] = $bucket;

	}//end addDeclaration()

	/**
	 * The buckets collected so far, in insertion order.
	 *
	 * @return array<string, array<int, array<string, float>>>
	 *
	 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-003
	 */
	public function toArray(): array {
		return $this->buckets;
	}//end toArray()

	/**
	 * The existing bucket for (administrationId, year), or a zeroed one.
	 *
	 * @param string $administrationId The administration.
	 * @param int $year The year.
	 *
	 * @return array<string, float>
	 */
	private function bucket(string $administrationId, int $year): array {
		return ($this->buckets[$administrationId][$year] ?? [
			'loonsom' => 0.0,
			'payslipWkrUsed' => 0.0,
			'vrijeRuimteDeclared' => 0.0,
			'eindheffingDeclared' => 0.0,
		]);

	}//end bucket()

	/**
	 * Derive a calendar year from a wage period (`YYYY-MM`/`YYYY-Pnn`) — the
	 * leading four-digit year prefix (the `RuleAuditService::yearFromPeriod`
	 * precedent). Returns null when unparseable.
	 *
	 * @param string $period Wage period string.
	 *
	 * @return int|null
	 */
	private static function yearFromPeriod(string $period): ?int {
		if (preg_match('/^(\d{4})/', $period, $matches) !== 1) {
			return null;
		}

		return (int)$matches[1];
	}//end yearFromPeriod()
}//end class
