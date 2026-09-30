<?php

/**
 * Year transition service
 *
 * Answers which pack and which tables a tax year will be paid with, where each
 * came from, and whether the pack's own golden vectors pass against those
 * tables. One service for the Payroll packs page and for the occ preflight
 * (payroll-pack-and-cao-updates design.md D4).
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
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Payroll\JurisdictionPack;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PackValidator;
use OCA\Humaniq\Payroll\TaxTables;
use Throwable;

/**
 * The resolution of a tax year to a pack and tables.
 *
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
 */
class YearTransitionService {

	/**
	 * @param PackRepository $packs The two-home pack resolver the runs use.
	 * @param PackValidator $validator The validator whose self-test gate is re-run.
	 */
	public function __construct(
		private readonly PackRepository $packs,
		private readonly PackValidator $validator,
	) {

	}//end __construct()

	/**
	 * Which pack and tables January of a year resolves to.
	 *
	 * @param string $jurisdiction The ISO 3166-1 alpha-2 jurisdiction.
	 * @param int $year The tax year.
	 *
	 * @return array<string, mixed> `resolves`, and when it does: packId, packVersion, packOrigin, tablesId, tablesOrigin, selfTest {passed, message}, provenance; otherwise a message.
	 *
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
	 * @SuppressWarnings(PHPMD.StaticAccess) TaxTables is a pure value-object factory with static load/fromDocument/isBundled, the precedent PayrollRunService and NlPayrollChecks already use.
	 */
	public function resolution(string $jurisdiction, int $year): array {
		$jurisdiction = strtoupper(trim($jurisdiction));
		$answer = ['jurisdiction' => $jurisdiction, 'year' => $year, 'resolves' => false];

		try {
			$pack = $this->packs->resolve($jurisdiction, sprintf('%04d-01', $year));
		} catch (Throwable $e) {
			return $answer + ['message' => 'No pack resolves for ' . $jurisdiction . ' ' . $year . '.'];
		}

		$answer = array_merge(
			$answer,
			[
				'resolves' => true,
				'packId' => $pack->id(),
				'packVersion' => $pack->packVersion(),
				'engineVersion' => $pack->engineVersion(),
				'packOrigin' => $pack->origin(),
				'tablesId' => $pack->tablesId(),
				'tablesOrigin' => (TaxTables::isBundled($pack->tablesId()) === true ? JurisdictionPack::ORIGIN_BUNDLED : JurisdictionPack::ORIGIN_UPLOADED),
			]
		);

		try {
			$tables = TaxTables::load($pack->tablesId());
		} catch (Throwable $e) {
			return $answer + [
				'selfTest' => ['passed' => false, 'message' => 'The tables ' . $pack->tablesId() . ' this pack uses are not available.'],
				'provenance' => [],
			];
		}

		return $answer + $this->selfTest($pack, $tables);
	}//end resolution()

	/**
	 * Re-run the pack's own golden vectors against its tables.
	 *
	 * @param JurisdictionPack $pack The pack.
	 * @param TaxTables $tables Its tables.
	 *
	 * @return array{selfTest: array{passed: bool, message: string}, provenance: array<int, string>}
	 */
	private function selfTest(JurisdictionPack $pack, TaxTables $tables): array {
		try {
			$flagged = $this->validator->validate($pack, $tables);
		} catch (Throwable $e) {
			return [
				'selfTest' => ['passed' => false, 'message' => $e->getMessage()],
				'provenance' => [],
			];
		}

		$provenance = [];
		foreach ($flagged as $leaf) {
			$provenance[] = (string)($leaf['path'] ?? '') . (($leaf['placeholder'] ?? false) === true ? ' (placeholder)' : ' (unconfirmed)');
		}

		return [
			'selfTest' => ['passed' => true, 'message' => ''],
			'provenance' => array_values(array_unique($provenance)),
		];
	}//end selfTest()

}//end class
