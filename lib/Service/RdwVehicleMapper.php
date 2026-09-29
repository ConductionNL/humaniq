<?php

/**
 * RDW Vehicle Mapper
 *
 * Maps the RDW open data answer for one licence plate onto the Asset fields
 * (people-register-prefill D2): `merk`, `handelsbenaming`,
 * `datum_eerste_toelating` and `catalogusprijs` from the registered vehicles
 * dataset (m9d7-ebf2), and the fuel type from the fuel dataset (8ys7-d773),
 * where a hybrid is a vehicle with electricity and a second fuel.
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
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * RDW rows to Asset fields.
 *
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */
class RdwVehicleMapper {

	/**
	 * The RDW fuel description per single-fuel Asset value.
	 *
	 * @var array<string, string>
	 */
	private const FUELS = [
		'benzine' => 'gasoline',
		'diesel' => 'diesel',
		'elektriciteit' => 'fullyElectric',
		'waterstof' => 'hydrogen',
	];

	/**
	 * The Asset fields the register answers for.
	 *
	 * @param array<string, mixed>       $vehicle  The registered vehicle row.
	 * @param list<array<string, mixed>> $fuelRows The vehicle's fuel rows.
	 *
	 * @return array<string, string|float>
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
	 */
	public function map(array $vehicle, array $fuelRows): array {
		$fields = [
			'make' => trim((string)($vehicle['merk'] ?? '')),
			'model' => trim((string)($vehicle['handelsbenaming'] ?? '')),
			'firstAdmissionDate' => $this->date((string)($vehicle['datum_eerste_toelating'] ?? '')),
			'listPrice' => (is_numeric($vehicle['catalogusprijs'] ?? null) === true ? (float)$vehicle['catalogusprijs'] : null),
			'fuelType' => $this->fuel($fuelRows),
		];

		return array_filter($fields, static fn ($value): bool => $value !== null && $value !== '');
	}//end map()

	/**
	 * An RDW date (`YYYYMMDD`) as an ISO date, or null.
	 *
	 * @param string $value The RDW date.
	 *
	 * @return string|null
	 */
	private function date(string $value): ?string {
		if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $parts) !== 1) {
			return null;
		}

		return $parts[1] . '-' . $parts[2] . '-' . $parts[3];
	}//end date()

	/**
	 * The Asset fuel type for the fuel rows, or null without rows.
	 *
	 * @param list<array<string, mixed>> $rows The fuel rows.
	 *
	 * @return string|null
	 */
	private function fuel(array $rows): ?string {
		$fuels = array_values(array_unique(array_map(static fn (array $row): string => strtolower(trim((string)($row['brandstof_omschrijving'] ?? ''))), $rows)));
		$fuels = array_values(array_filter($fuels, static fn (string $fuel): bool => $fuel !== ''));
		if ($fuels === []) {
			return null;
		}

		if (count($fuels) > 1) {
			return in_array('elektriciteit', $fuels, true) === true ? 'hybrid' : 'other';
		}

		return (self::FUELS[$fuels[0]] ?? 'other');
	}//end fuel()

}//end class
