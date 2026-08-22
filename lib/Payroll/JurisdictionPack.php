<?php

/**
 * Jurisdiction Pack
 *
 * One jurisdiction's gross-to-net chain as an exchangeable artefact
 * (jurisdiction-packs design.md D1/D7): a single self-contained JSON document
 * declaring its identity, its input contract, its bindings, its ordered steps
 * and its own golden vectors.
 *
 * `jurisdiction` and `taxYear` are DECLARED FIELDS, never substrings parsed
 * out of the pack id (REQ-JP-001). Today's resolver hardcodes the country
 * (`'nl-'.substr($period, 0, 4)`); a declared field is what lets the resolver
 * become a lookup on `(jurisdiction, year-of(period))`.
 *
 * A pack is a value object over validated data. It carries no behaviour of its
 * own: `PackValidator` decides whether the data is trustworthy and
 * `PackInterpreter` executes it.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * A jurisdiction's declared calculation chain.
 */
final class JurisdictionPack {

	/**
	 * A pack that ships inside humaniq, under `lib/Standards/packs/`.
	 *
	 * @var string
	 */
	public const ORIGIN_BUNDLED = 'bundled';

	/**
	 * A pack uploaded by an admin, stored as an OpenRegister object.
	 *
	 * @var string
	 */
	public const ORIGIN_UPLOADED = 'uploaded';

	/**
	 * @param array<string, mixed> $data The decoded pack document.
	 * @param string $origin One of `bundled` / `uploaded`.
	 */
	public function __construct(
		private readonly array $data,
		private readonly string $origin = self::ORIGIN_UPLOADED,
	) {

	}//end __construct()

	/**
	 * The pack id (e.g. `nl-2026`).
	 *
	 * @return string
	 */
	public function id(): string {
		return (string)($this->data['id'] ?? '');
	}//end id()

	/**
	 * The declared ISO 3166-1 alpha-2 jurisdiction (never parsed from the id).
	 *
	 * @return string
	 */
	public function jurisdiction(): string {
		return strtoupper((string)($this->data['jurisdiction'] ?? ''));
	}//end jurisdiction()

	/**
	 * The declared tax year (never parsed from the id).
	 *
	 * @return int
	 */
	public function taxYear(): int {
		return (int)($this->data['taxYear'] ?? 0);
	}//end taxYear()

	/**
	 * The pack's semver version — the `RuleCatalogue::VERSION` analogue.
	 *
	 * @return string
	 */
	public function packVersion(): string {
		return (string)($this->data['packVersion'] ?? '');
	}//end packVersion()

	/**
	 * The interpreter contract this pack was written against.
	 *
	 * @return string
	 */
	public function dslVersion(): string {
		return (string)($this->data['dslVersion'] ?? '');
	}//end dslVersion()

	/**
	 * The `TaxTables` id this pack's `@table.*` refs resolve against.
	 *
	 * @return string
	 */
	public function tablesId(): string {
		return (string)($this->data['tables'] ?? '');
	}//end tablesId()

	/**
	 * The reference naming the gross base the incidence fold subtracts from
	 * (REQ-JP-003). The pack declares WHICH value is gross; the fold itself is
	 * the interpreter's and is not authorable.
	 *
	 * @return string
	 */
	public function grossRef(): string {
		return (string)($this->data['grossRef'] ?? '');
	}//end grossRef()

	/**
	 * The declared input contract.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function inputs(): array {
		return (array)($this->data['inputs'] ?? []);
	}//end inputs()

	/**
	 * The declared bindings, in order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function bindings(): array {
		return (array)($this->data['bindings'] ?? []);
	}//end bindings()

	/**
	 * The declared steps, in order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function steps(): array {
		return (array)($this->data['steps'] ?? []);
	}//end steps()

	/**
	 * The declared golden vectors (REQUIRED, >= 1 — REQ-JP-006).
	 *
	 * @return array<string, mixed>
	 */
	public function selfTest(): array {
		return (array)($this->data['selfTest'] ?? []);
	}//end selfTest()

	/**
	 * The metadata exposed to `@pack.*` references.
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return [
			'id' => $this->id(),
			'jurisdiction' => $this->jurisdiction(),
			'taxYear' => $this->taxYear(),
			'packVersion' => $this->packVersion(),
			'currency' => (string)($this->data['currency'] ?? ''),
		];

	}//end meta()

	/**
	 * Where this pack came from.
	 *
	 * @return string
	 */
	public function origin(): string {
		return $this->origin;
	}//end origin()

	/**
	 * Whether this pack ships inside humaniq (and therefore may not be shadowed
	 * by an upload without an explicit recorded override — design.md D7).
	 *
	 * @return bool
	 */
	public function isBundled(): bool {
		return ($this->origin === self::ORIGIN_BUNDLED);
	}//end isBundled()

	/**
	 * The run's `engineVersion` stamp: `{packId}@{packVersion}` — strictly
	 * more information than today's bare table id (design.md D7).
	 *
	 * @return string
	 */
	public function engineVersion(): string {
		return $this->id() . '@' . $this->packVersion();
	}//end engineVersion()

	/**
	 * The raw decoded document.
	 *
	 * @return array<string, mixed>
	 */
	public function raw(): array {
		return $this->data;
	}//end raw()

}//end class
