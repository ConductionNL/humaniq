<?php

/**
 * Tax table set service
 *
 * Validates and stores the tax tables an administrator uploads together with
 * a payroll pack, and hands the active ones to `TaxTables::load()` for an id
 * no bundled file owns (payroll-pack-and-cao-updates design.md D1, D2).
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
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Payroll\TaxTableSourceInterface;
use Throwable;

/**
 * Uploaded tax tables: validation, storage and the active lookup.
 */
class TaxTableSetService implements TaxTableSourceInterface {

	/**
	 * The `TaxTableSet` schema in the humaniq register.
	 *
	 * @var string
	 */
	public const SCHEMA = 'TaxTableSet';

	/**
	 * @param HoursRegisterGateway $gateway The humaniq register gateway.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
	) {

	}//end __construct()

	/**
	 * Validate an uploaded tables document: an id no bundled file owns, the
	 * required parameter groups, and every figure a leaf with a value, a
	 * source and a verified flag, with a `checkAgainst` note when unverified.
	 *
	 * @param array<string, mixed> $document The decoded tables document.
	 *
	 * @return TaxTables The tables, ready for the pack's own golden vectors.
	 *
	 * @throws DslException Naming the id, group or leaf that fails.
	 *
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
	 */
	public function validate(array $document): TaxTables {
		$id = trim((string)($document['id'] ?? ''));
		if (preg_match('/^[a-z]{2}-\d{4}$/', $id) !== 1) {
			throw new DslException('Tabellen: "id" moet de vorm land-jaar hebben, zoals nl-2027.');
		}

		if (TaxTables::isBundled($id) === true) {
			throw new DslException('Tabellen: ' . $id . ' wordt met de app meegeleverd en kan niet door een upload worden vervangen.');
		}

		if ((int)($document['year'] ?? 0) !== (int)substr($id, -4)) {
			throw new DslException('Tabellen: "year" komt niet overeen met ' . $id . '.');
		}

		try {
			$tables = TaxTables::fromDocument($document, 'geüploade tabellen ' . $id);
		} catch (Throwable $e) {
			throw new DslException($e->getMessage(), 0, $e);
		}

		$this->leaves((array)$document['parameters'], []);

		return $tables;
	}//end validate()

	/**
	 * Store validated tables as active. Called only after the pack that uses
	 * them passed every gate against them.
	 *
	 * @param array<string, mixed> $document The validated tables document.
	 *
	 * @return array<string, mixed> The stored object.
	 *
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
	 */
	public function store(array $document): array {
		$object = [
			'tablesId' => (string)$document['id'],
			'jurisdiction' => strtoupper((string)($document['jurisdiction'] ?? substr((string)$document['id'], 0, 2))),
			'year' => (int)$document['year'],
			'issued' => (isset($document['issued']) === true ? (string)$document['issued'] : null),
			'active' => true,
			'provenance' => $this->unverified((array)$document['parameters'], []),
			'uploadedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'document' => (string)json_encode($document),
		];

		foreach ($this->gateway->findFiltered(self::SCHEMA, ['tablesId' => $object['tablesId'], 'active' => true]) as $previous) {
			$previous['active'] = false;
			$this->gateway->save($previous, self::SCHEMA, (string)$previous['id']);
		}

		$this->gateway->save($object, self::SCHEMA);

		return $object;
	}//end store()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id The tables id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
	 */
	public function activeTables(string $id): ?array {
		foreach ($this->gateway->findFiltered(self::SCHEMA, ['tablesId' => $id, 'active' => true]) as $row) {
			$document = json_decode((string)($row['document'] ?? ''), true);
			if (is_array($document) === true) {
				return $document;
			}
		}

		return null;
	}//end activeTables()

	/**
	 * Deactivate the uploaded tables with this id.
	 *
	 * @param string $id The tables id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-003
	 */
	public function deactivate(string $id): void {
		foreach ($this->gateway->findFiltered(self::SCHEMA, ['tablesId' => $id, 'active' => true]) as $row) {
			$row['active'] = false;
			$this->gateway->save($row, self::SCHEMA, (string)$row['id']);
		}

	}//end deactivate()

	/**
	 * Walk the parameters and refuse any figure that is not a proper leaf.
	 * Scalars are allowed only as notes (a key starting with an underscore)
	 * or flags (a boolean).
	 *
	 * @param array<string, mixed> $node The node.
	 * @param array<int, string> $path The path to it.
	 *
	 * @return void
	 *
	 * @throws DslException Naming the leaf.
	 */
	private function leaves(array $node, array $path): void {
		foreach ($node as $key => $child) {
			$here = array_merge($path, [(string)$key]);
			if (is_array($child) === true && array_key_exists('value', $child) === true) {
				$this->leaf($child, implode('.', $here));
				continue;
			}

			if (is_array($child) === true) {
				$this->leaves($child, $here);
				continue;
			}

			if (str_starts_with((string)$key, '_') === false && is_bool($child) === false) {
				throw new DslException('Tabellen: ' . implode('.', $here) . ' is een getal zonder bron; elk getal moet {value, source, verified} zijn.');
			}
		}

	}//end leaves()

	/**
	 * Check one leaf.
	 *
	 * @param array<string, mixed> $leaf The leaf.
	 * @param string $path Its path.
	 *
	 * @return void
	 *
	 * @throws DslException Naming the leaf.
	 */
	private function leaf(array $leaf, string $path): void {
		if (trim((string)($leaf['source'] ?? '')) === '' || is_bool($leaf['verified'] ?? null) === false) {
			throw new DslException('Tabellen: ' . $path . ' mist "source" of "verified".');
		}

		if ($leaf['verified'] === false && trim((string)($leaf['checkAgainst'] ?? '')) === '') {
			throw new DslException('Tabellen: ' . $path . ' is niet bevestigd en zegt niet waartegen het gecontroleerd moet worden ("checkAgainst").');
		}

	}//end leaf()

	/**
	 * The unverified leaves, each with what to check it against.
	 *
	 * @param array<string, mixed> $node The node.
	 * @param array<int, string> $path The path to it.
	 *
	 * @return string
	 */
	private function unverified(array $node, array $path): string {
		$parts = [];
		foreach ($node as $key => $child) {
			if (is_array($child) === false) {
				continue;
			}

			$here = array_merge($path, [(string)$key]);
			if (array_key_exists('value', $child) === false) {
				$nested = $this->unverified($child, $here);
				if ($nested !== '') {
					$parts[] = $nested;
				}

				continue;
			}

			if (($child['verified'] ?? false) !== true) {
				$parts[] = implode('.', $here) . ' (' . (string)($child['checkAgainst'] ?? '') . ')';
			}
		}

		return implode('; ', $parts);
	}//end unverified()

}//end class
