<?php

/**
 * Register Prefill Service
 *
 * Fills a company car from the RDW vehicle register and an employee from
 * the BRP (people-register-prefill). Only empty fields are filled; every
 * field where the register differs from the stored value is listed with both
 * values and left as it is (D2). The RDW open data answers per plate from
 * two datasets; the BRP is asked with the BSN in a POST body, and integriq
 * is told not to keep a CallLog of that call, so the BSN is kept by neither
 * app (D3). Who may ask, and the legal basis for the BRP, are the
 * controller's to check.
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
 * Fill empty fields from a base register, report the differences.
 *
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */
class RegisterPrefillService {

	/**
	 * The RDW registered vehicles dataset.
	 *
	 * @var string
	 */
	public const RDW_VEHICLE_ENDPOINT = '/resource/m9d7-ebf2.json';

	/**
	 * The RDW fuel dataset.
	 *
	 * @var string
	 */
	public const RDW_FUEL_ENDPOINT = '/resource/8ys7-d773.json';

	/**
	 * The BRP Personen bevragen endpoint.
	 *
	 * @var string
	 */
	public const BRP_ENDPOINT = '/personen';

	/**
	 * The BRP fields asked for, and nothing more.
	 *
	 * @var list<string>
	 */
	private const BRP_FIELDS = ['naam', 'geboorte.datum', 'verblijfplaats'];

	/**
	 * Constructor.
	 *
	 * @param RegisterLookupGateway $gateway Calls the register through integriq.
	 * @param RdwVehicleMapper      $rdw     Maps the RDW answer.
	 * @param BrpPersonMapper       $brp     Maps the BRP answer.
	 */
	public function __construct(
		private readonly RegisterLookupGateway $gateway,
		private readonly RdwVehicleMapper $rdw,
		private readonly BrpPersonMapper $brp,
	) {

	}//end __construct()

	/**
	 * Fill a vehicle asset from the RDW by its licence plate.
	 *
	 * @param array<string, mixed> $asset The stored asset.
	 *
	 * @return array{filled: array<string, mixed>, differs: list<array{field: string, stored: mixed, register: mixed}>}
	 *
	 * @throws RegisterPrefillUnavailableException When nothing can be looked up.
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
	 */
	public function vehicle(array $asset): array {
		$plate = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($asset['licencePlate'] ?? '')));
		if (($asset['category'] ?? '') !== 'vehicle' || $plate === '') {
			throw new RegisterPrefillUnavailableException('Alleen een voertuig met een kenteken kan uit het RDW-register worden gevuld.', 'not-applicable');
		}

		$query = ['query' => ['kenteken' => $plate]];
		$vehicles = $this->gateway->call(register: 'rdw', endpoint: self::RDW_VEHICLE_ENDPOINT, method: 'GET', config: $query);
		if (is_array($vehicles[0] ?? null) === false) {
			throw new RegisterPrefillUnavailableException('Het RDW-register kent kenteken ' . $plate . ' niet.', 'not-found');
		}

		$fuels = $this->gateway->call(register: 'rdw', endpoint: self::RDW_FUEL_ENDPOINT, method: 'GET', config: $query);

		return $this->merge($asset, $this->rdw->map($vehicles[0], array_values(array_filter($fuels, 'is_array'))));
	}//end vehicle()

	/**
	 * Fill an employee from the BRP by BSN.
	 *
	 * @param array<string, mixed> $employee The stored employee.
	 *
	 * @return array{filled: array<string, mixed>, differs: list<array{field: string, stored: mixed, register: mixed}>}
	 *
	 * @throws RegisterPrefillUnavailableException When nothing can be looked up.
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
	 */
	public function employee(array $employee): array {
		$bsn = preg_replace('/\D/', '', (string)($employee['bsn'] ?? ''));
		if ($bsn === null || $bsn === '') {
			throw new RegisterPrefillUnavailableException('Zonder BSN kan de medewerker niet in de BRP worden opgezocht.', 'not-applicable');
		}

		$request = [
			'type' => 'RaadpleegMetBurgerservicenummer',
			'burgerservicenummer' => [$bsn],
			'fields' => self::BRP_FIELDS,
		];
		$answer = $this->gateway->call(
			register: 'brp',
			endpoint: self::BRP_ENDPOINT,
			method: 'POST',
			config: ['body' => json_encode($request), 'headers' => ['Content-Type' => 'application/json']],
			persistLog: false
		);
		$person = ($answer['personen'][0] ?? null);
		if (is_array($person) === false) {
			throw new RegisterPrefillUnavailableException('De BRP kent deze medewerker niet.', 'not-found');
		}

		return $this->merge($employee, $this->brp->map($person));
	}//end employee()

	/**
	 * Fill the empty fields; list the ones where the register differs.
	 *
	 * @param array<string, mixed> $stored   The stored record.
	 * @param array<string, mixed> $register The register's values.
	 *
	 * @return array{filled: array<string, mixed>, differs: list<array{field: string, stored: mixed, register: mixed}>}
	 */
	private function merge(array $stored, array $register): array {
		$filled = [];
		$differs = [];
		foreach ($register as $field => $value) {
			$current = ($stored[$field] ?? null);
			if ($current === null || $current === '') {
				$filled[$field] = $value;
				continue;
			}

			if ($this->same($current, $value) === false) {
				$differs[] = ['field' => (string)$field, 'stored' => $current, 'register' => $value];
			}
		}

		return ['filled' => $filled, 'differs' => $differs];
	}//end merge()

	/**
	 * Whether a stored value and a register value say the same.
	 *
	 * @param mixed $stored   The stored value.
	 * @param mixed $register The register value.
	 *
	 * @return bool
	 */
	private function same(mixed $stored, mixed $register): bool {
		if (is_numeric($stored) === true && is_numeric($register) === true) {
			return abs((float)$stored - (float)$register) < 0.005;
		}

		return mb_strtolower(trim((string)$stored)) === mb_strtolower(trim((string)$register));
	}//end same()

}//end class
