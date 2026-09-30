<?php

/**
 * Jurisdiction Pack Service
 *
 * The OpenRegister-backed home for UPLOADED jurisdiction packs, and the
 * implementation of the pure `PackSourceInterface` seam the resolver depends
 * on (jurisdiction-packs design.md D7).
 *
 * This class is where the cross-app dependency lives, deliberately: everything
 * under `lib/Payroll/` stays free of Nextcloud and OpenRegister imports so the
 * engine remains portable and directly unit-testable. Bundled packs live in
 * code (`lib/Standards/packs/`, the `lib/Standards/tables/` precedent);
 * uploaded packs live as `JurisdictionPack` objects in the humaniq register
 * (ADR-022 — per-tenant config lives in OpenRegister).
 *
 * **Activation is recorded here, never read from the pack.** A pack document
 * is author-supplied, so an author must not be able to promote their own
 * upload over the bundled NL regression contract by setting a field in their
 * own JSON. The `active` and `overridesBundled` flags live on the stored
 * OBJECT, set by this service only after `PackValidator` has passed every gate
 * — including the explicit-override gate.
 *
 * payroll-pack-and-cao-updates (D2, D3): a pack may bring its tables along.
 * Both are validated as one unit and neither is stored unless both pass;
 * deactivation is a guarded service call, never an object edit.
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\JurisdictionPack;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PackSourceInterface;
use OCA\Humaniq\Payroll\PackValidator;
use OCA\Humaniq\Payroll\TaxTables;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validates, stores, resolves and deactivates uploaded jurisdiction packs.
 */
class JurisdictionPackService implements PackSourceInterface {

	/**
	 * The `JurisdictionPack` schema in the humaniq register.
	 *
	 * @var string
	 */
	public const SCHEMA = 'JurisdictionPack';

	/**
	 * The BUNDLED-only pack resolver, for the shadowing gate.
	 *
	 * @var PackRepository
	 */
	private readonly PackRepository $bundled;

	/**
	 * The shadowing gate asks one question — "does a BUNDLED pack already own
	 * this key?" — so it needs a bundled-only resolver, constructed here rather
	 * than injected. That is not incidental: the container's `PackRepository`
	 * is wired to THIS service as its uploaded-pack source, so injecting it
	 * would be a dependency cycle, and a resolver that already consults
	 * uploads would answer the wrong question anyway.
	 *
	 * @param HoursRegisterGateway $gateway The humaniq register gateway (guards OpenRegister's absence).
	 * @param PackValidator $validator The blocking upload validator.
	 * @param TaxTableSetService $tableSets The uploaded tables home.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly PackValidator $validator,
		private readonly TaxTableSetService $tableSets,
		private readonly LoggerInterface $logger,
	) {
		$this->bundled = new PackRepository();

	}//end __construct()

	/**
	 * Validate and store an uploaded pack, with its tables when given. EVERY
	 * gate blocks: nothing is stored until the tables passed their own checks
	 * and the pack passed structure, vocabulary, references, handler
	 * resolution, bounds, shadowing AND its own golden vectors against those
	 * tables (design.md D11; payroll-pack-and-cao-updates D2).
	 *
	 * @param array<string, mixed> $document The uploaded pack document.
	 * @param bool $override Whether the admin explicitly activated this as a recorded override of a bundled pack.
	 * @param array<string, mixed>|null $tablesDocument The tables the pack declares, when uploaded with it.
	 *
	 * @return array<string, mixed> The stored object.
	 *
	 * @throws DslException When any gate rejects the pack or its tables, naming the offending op, ref, handler, bound, group or leaf.
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
	 */
	public function upload(array $document, bool $override = false, ?array $tablesDocument = null): array {
		$pack = new JurisdictionPack($document, JurisdictionPack::ORIGIN_UPLOADED);

		$tables = $this->tablesFor($pack, $tablesDocument);
		$provenance = $this->validator->validate($pack, $tables, $this->bundled, $override);

		// Only now, after every gate has passed for both, does either become
		// an object, and only then is it marked active. Activation is recorded
		// on the OBJECT, never taken from the author-supplied document.
		if ($tablesDocument !== null) {
			$this->tableSets->store($tablesDocument);
		}

		$object = [
			'packId' => $pack->id(),
			'jurisdiction' => $pack->jurisdiction(),
			'taxYear' => $pack->taxYear(),
			'packVersion' => $pack->packVersion(),
			'dslVersion' => $pack->dslVersion(),
			'tables' => $pack->tablesId(),
			'active' => true,
			'overridesBundled' => $override,
			'provenance' => $this->describeProvenance($provenance),
			'document' => json_encode($document),
		];

		$this->gateway->save($object, self::SCHEMA);

		return $object;
	}//end upload()

	/**
	 * {@inheritDoc}
	 *
	 * An uploaded pack surfaces here ONLY when it is active. A pack claiming a
	 * bundled key is stored active only when an admin explicitly recorded the
	 * override, so "bundled wins by default" holds without the resolver having
	 * to re-litigate it.
	 *
	 * @param string $jurisdiction The ISO 3166-1 alpha-2 jurisdiction.
	 * @param int $taxYear The tax year.
	 *
	 * @return JurisdictionPack|null
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
	 */
	public function activePack(string $jurisdiction, int $taxYear): ?JurisdictionPack {
		foreach ($this->activeRows($jurisdiction, $taxYear) as $row) {
			$document = json_decode((string)($row['document'] ?? ''), true);
			if (is_array($document) === true) {
				return new JurisdictionPack($document, JurisdictionPack::ORIGIN_UPLOADED);
			}
		}

		return null;
	}//end activePack()

	/**
	 * Deactivate an uploaded pack, and its uploaded tables when no other active
	 * pack uses them (payroll-pack-and-cao-updates D3). A run already
	 * calculated keeps its engineVersion stamp; a draft recalculated
	 * afterwards resolves its pack again.
	 *
	 * @param string $objectId The JurisdictionPack object id.
	 *
	 * @return array<string, mixed> The pack as stored now.
	 *
	 * @throws OutOfBoundsException When no pack has this id.
	 *
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-003
	 */
	public function deactivate(string $objectId): array {
		$pack = $this->gateway->findObjectData($objectId, self::SCHEMA);
		if ($pack === null) {
			throw new OutOfBoundsException('Geen geüpload pack met id ' . $objectId . '.');
		}

		// The gateway save is a full replace, so the whole record goes back.
		$pack['active'] = false;
		$this->gateway->save($pack, self::SCHEMA, $objectId);

		$tablesId = (string)($pack['tables'] ?? '');
		$stillUsed = $this->gateway->findFiltered(self::SCHEMA, ['tables' => $tablesId, 'active' => true]);
		if ($tablesId !== '' && $stillUsed === []) {
			$this->tableSets->deactivate($tablesId);
		}

		return $pack;
	}//end deactivate()

	/**
	 * Every uploaded pack, newest tax year first, without the documents.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
	 */
	public function list(): array {
		$rows = [];
		foreach ($this->gateway->loadAll(self::SCHEMA) as $row) {
			unset($row['document']);
			$row['origin'] = JurisdictionPack::ORIGIN_UPLOADED;
			$row['engineVersion'] = (string)($row['packId'] ?? '') . '@' . (string)($row['packVersion'] ?? '');
			$rows[] = $row;
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => [(int)($right['taxYear'] ?? 0), (string)($right['packVersion'] ?? '')] <=> [(int)($left['taxYear'] ?? 0), (string)($left['packVersion'] ?? '')]
		);

		return $rows;
	}//end list()

	/**
	 * The active uploaded pack rows for a key. A pack store that cannot be
	 * read must never silently fall through to "no uploaded pack" when an
	 * override IS active: that would resolve the bundled pack and pay everyone
	 * from the wrong chain.
	 *
	 * @param string $jurisdiction The jurisdiction.
	 * @param int $taxYear The tax year.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws DslException When the store cannot be read.
	 */
	private function activeRows(string $jurisdiction, int $taxYear): array {
		try {
			return $this->gateway->findFiltered(
				self::SCHEMA,
				[
					'jurisdiction' => strtoupper($jurisdiction),
					'taxYear' => $taxYear,
					'active' => true,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error('humaniq: kon geüploade jurisdictiepacks niet lezen: ' . $e->getMessage(), ['exception' => $e]);
			throw new DslException('Pack: kon de geüploade jurisdictiepacks niet lezen — een run mag niet stilzwijgend terugvallen op een ander pack.', 0, $e);
		}

	}//end activeRows()

	/**
	 * The tables corpus a pack's `@table.*` refs resolve against, as DECLARED
	 * by the pack itself: the tables uploaded with it, or else bundled or an
	 * earlier active upload.
	 *
	 * @param JurisdictionPack $pack The pack.
	 * @param array<string, mixed>|null $tablesDocument The tables uploaded with the pack, if any.
	 *
	 * @return TaxTables
	 *
	 * @throws DslException When the uploaded tables fail or are not the pack's, or the declared corpus does not exist.
	 */
	private function tablesFor(JurisdictionPack $pack, ?array $tablesDocument): TaxTables {
		if ($tablesDocument !== null) {
			$tables = $this->tableSets->validate($tablesDocument);
			if ($tables->id() !== $pack->tablesId()) {
				throw new DslException('Pack: de geüploade tabellen ' . $tables->id() . ' zijn niet de tabellen die het pack gebruikt (' . $pack->tablesId() . ').');
			}

			return $tables;
		}

		try {
			return TaxTables::load($pack->tablesId());
		} catch (Throwable $e) {
			throw new DslException('Pack: het gedeclareerde tabellenbestand "' . $pack->tablesId() . '" bestaat niet; upload de tabellen samen met het pack.', 0, $e);
		}

	}//end tablesFor()

	/**
	 * A human-readable provenance stamp for any unverified/placeholder leaf
	 * the pack resolves (design.md D11 gate 6 — stamped, never blocking).
	 *
	 * @param array<int, array<string, mixed>> $provenance The flagged leaves.
	 *
	 * @return string
	 */
	private function describeProvenance(array $provenance): string {
		if ($provenance === []) {
			return '';
		}

		$parts = [];
		foreach ($provenance as $leaf) {
			$parts[] = (string)$leaf['path'] . ($leaf['placeholder'] === true ? ' (placeholder)' : ' (onbevestigd)');
		}

		return implode('; ', $parts);
	}//end describeProvenance()

}//end class
