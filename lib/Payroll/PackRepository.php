<?php

/**
 * Pack Repository
 *
 * Resolves the pack for a run on `(jurisdiction, year-of(period))`
 * (jurisdiction-packs design.md D7).
 *
 * At HEAD the resolver hardcoded the country:
 *
 *     $tableId = 'nl-'.substr($period, 0, 4);   // PayrollRunService::generate()
 *
 * Here the country is a parameter and the pack's own `jurisdiction`/`taxYear`
 * are DECLARED FIELDS, matched as fields — no code path parses a country out
 * of a pack id or out of a run period (REQ-JP-001).
 *
 * **Two homes, one key** (design.md D7):
 * - bundled packs ship in `lib/Standards/packs/*.pack.json` — universal facts
 *   live in code, mirroring the `lib/Standards/tables/` precedent;
 * - uploaded packs live as OpenRegister objects, reached through the pure
 *   `PackSourceInterface` seam so this class stays free of Nextcloud deps.
 *
 * **Bundled wins by default.** The obvious design lets an upload shadow a
 * bundled pack by key — which would let a stray upload silently replace the NL
 * regression contract with someone's half-finished experiment, and everyone
 * gets paid from it. So an uploaded pack only ever reaches this resolver when
 * the source reports it ACTIVE, which for a bundled key requires an explicit,
 * recorded admin override (enforced at upload by `PackValidator` gate 9).
 * Overriding NL is a deliberate, auditable act, never an accident.
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll;

use OCA\Hrmq\Payroll\Dsl\DslException;

/**
 * Resolves a jurisdiction pack across the bundled and uploaded homes.
 */
final class PackRepository {

	/**
	 * Memoised bundled packs, keyed `{JURISDICTION}-{taxYear}`.
	 *
	 * @var array<string, JurisdictionPack>|null
	 */
	private ?array $bundledCache = null;

	/**
	 * @param PackSourceInterface|null $uploaded The uploaded-pack source, or null when only bundled packs are available.
	 */
	public function __construct(
		private readonly ?PackSourceInterface $uploaded = null,
	) {

	}//end __construct()

	/**
	 * Resolve the pack for a run.
	 *
	 * @param string $jurisdiction The run's declared jurisdiction (ISO 3166-1 alpha-2).
	 * @param string $period The wage period, `YYYY-MM`.
	 *
	 * @return JurisdictionPack
	 *
	 * @throws DslException When no pack owns this key.
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
	 */
	public function resolve(string $jurisdiction, string $period): JurisdictionPack {
		$jurisdiction = strtoupper(trim($jurisdiction));
		$taxYear = (int)substr($period, 0, 4);

		$active = null;
		if ($this->uploaded !== null) {
			$active = $this->uploaded->activePack($jurisdiction, $taxYear);
		}

		if ($active !== null) {
			return $active;
		}

		$bundled = $this->bundledFor($jurisdiction, $taxYear);
		if ($bundled !== null) {
			return $bundled;
		}

		throw new DslException('Pack: geen jurisdictiepack gevonden voor ' . $jurisdiction . ' ' . $taxYear . '.');
	}//end resolve()

	/**
	 * The bundled pack owning a key, or null.
	 *
	 * @param string $jurisdiction The jurisdiction.
	 * @param int $taxYear The tax year.
	 *
	 * @return JurisdictionPack|null
	 */
	public function bundledFor(string $jurisdiction, int $taxYear): ?JurisdictionPack {
		$key = strtoupper($jurisdiction) . '-' . $taxYear;

		return ($this->bundled()[$key] ?? null);
	}//end bundledFor()

	/**
	 * Every bundled pack, keyed `{JURISDICTION}-{taxYear}` from the packs'
	 * own DECLARED fields — not from their filenames.
	 *
	 * @return array<string, JurisdictionPack>
	 *
	 * @throws DslException When a bundled pack file is unreadable or malformed.
	 */
	public function bundled(): array {
		if ($this->bundledCache !== null) {
			return $this->bundledCache;
		}

		$packs = [];
		foreach ((glob($this->packsDir() . '/*.pack.json') ?: []) as $file) {
			$pack = $this->read($file);
			$packs[$pack->jurisdiction() . '-' . $pack->taxYear()] = $pack;
		}

		$this->bundledCache = $packs;

		return $packs;
	}//end bundled()

	/**
	 * Reset the memoised bundled-pack list (test hook).
	 *
	 * @return void
	 */
	public function resetCache(): void {
		$this->bundledCache = null;

	}//end resetCache()

	/**
	 * Decode one bundled pack file.
	 *
	 * @param string $file The absolute path.
	 *
	 * @return JurisdictionPack
	 *
	 * @throws DslException When the file is unreadable or malformed.
	 */
	private function read(string $file): JurisdictionPack {
		$content = file_get_contents($file);
		if ($content === false) {
			throw new DslException('Pack: kon het meegeleverde pack niet lezen: ' . $file . '.');
		}

		$decoded = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			throw new DslException('Pack: kon het meegeleverde pack niet parsen: ' . $file . ' (' . json_last_error_msg() . ').');
		}

		return new JurisdictionPack($decoded, JurisdictionPack::ORIGIN_BUNDLED);
	}//end read()

	/**
	 * The absolute path to `lib/Standards/packs/` — the `lib/Standards/tables/`
	 * precedent.
	 *
	 * @return string
	 */
	private function packsDir(): string {
		return __DIR__ . '/../Standards/packs';
	}//end packsDir()

}//end class
