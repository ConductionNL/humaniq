<?php

/**
 * WKR aggregate buckets
 *
 * Accumulates the per-(administrationId, year) fiscale-loonsom and
 * vrije-ruimte figures `WkrService` assesses: Σ Payslip.grossPay and
 * Σ Payslip.wkrUsed per wage year, plus the declared vrije-ruimte and
 * eindheffing amounts per declaration year. Buckets keep the order in which
 * they were first opened.
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
	 * A bucket before anything is added to it.
	 *
	 * @var array<string, float>
	 */
	private const EMPTY_BUCKET = [
		'loonsom' => 0.0,
		'payslipWkrUsed' => 0.0,
		'vrijeRuimteDeclared' => 0.0,
		'eindheffingDeclared' => 0.0,
	];

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
	 * The administration of the selected bucket.
	 *
	 * @var string
	 */
	private string $administrationId = '';

	/**
	 * The year of the selected bucket.
	 *
	 * @var int
	 */
	private int $year = 0;

	/**
	 * Select the bucket of (administrationId, year), opening it with zeroes
	 * when it does not exist yet.
	 *
	 * @param string $administrationId The administration.
	 * @param int $year The year.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-003
	 */
	public function at(string $administrationId, int $year): self {
		$this->administrationId = $administrationId;
		$this->year = $year;
		$this->buckets[$administrationId][$year] ??= self::EMPTY_BUCKET;

		return $this;
	}//end at()

	/**
	 * Add an amount to one field of the selected bucket.
	 *
	 * @param string $field The bucket field.
	 * @param float $amount The amount to add.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-003
	 */
	public function add(string $field, float $amount): self {
		$this->buckets[$this->administrationId][$this->year][$field] += $amount;

		return $this;
	}//end add()

	/**
	 * Add a declared amount to the selected bucket's field for its category;
	 * a category other than vrije-ruimte or eindheffing adds nothing.
	 *
	 * @param string $category The declaration's wkrCategory.
	 * @param float $amount The declared amount.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-003
	 */
	public function addDeclared(string $category, float $amount): self {
		$field = (self::DECLARED_FIELD_BY_CATEGORY[$category] ?? null);
		if ($field !== null) {
			$this->add($field, $amount);
		}

		return $this;
	}//end addDeclared()

	/**
	 * The buckets collected so far, in the order they were opened.
	 *
	 * @return array<string, array<int, array<string, float>>>
	 *
	 * @spec openspec/specs/wkr-administration/spec.md#REQ-WKR-003
	 */
	public function toArray(): array {
		return $this->buckets;
	}//end toArray()
}//end class
