<?php

/**
 * Asset Dialect Mapper
 *
 * The pure mapping half of {@see AssetDialectMigrationService}, split out so
 * each class holds one job: this one turns a stored row from the retired Dutch
 * dialect into the renamed one; the service reads rows, writes them back, and
 * reports what it did.
 *
 * The split is not cosmetic. Keeping both in one class put its overall
 * complexity over the fleet's phpmd threshold, and the honest fix for "this
 * class does too much" is to make it do less rather than to widen the
 * threshold or baseline the finding.
 *
 * Every method here is side-effect free and takes plain arrays, which is what
 * lets the mapping rules be tested without a Nextcloud bootstrap, an
 * ObjectService double, or a live register.
 *
 * The two refusals that matter live here, not in the service:
 *  - an enum value that is neither a known legacy value nor a current one is
 *    reported, never guessed at or silently passed through;
 *  - a row carrying BOTH an old and a new field name with DIFFERENT non-null
 *    values is reported, never resolved by picking one.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

/**
 * Pure old-dialect -> new-dialect row mapping for Asset and AssetAssignment.
 *
 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-008
 */
class AssetDialectMapper {

	/**
	 * Old Dutch `Asset.category` value -> renamed value (design.md D1).
	 *
	 * @var array<string, string>
	 */
	private const ASSET_CATEGORY_MAP = [
		'telefoon'    => 'phone',
		'voertuig'    => 'vehicle',
		'gereedschap' => 'tool',
		'toegangspas' => 'accessPass',
		'kleding'     => 'clothing',
		'overig'      => 'other',
	];

	/**
	 * Old Dutch `Asset.status` value -> renamed value (design.md D1).
	 *
	 * @var array<string, string>
	 */
	private const ASSET_STATUS_MAP = [
		'beschikbaar' => 'available',
		'uitgegeven'  => 'issued',
		'ingenomen'   => 'checkedIn',
		'afgeschreven' => 'writtenOff',
	];

	/**
	 * Current schema `Asset.category` enum (a row already showing one of
	 * these values needs no category rewrite).
	 *
	 * @var string[]
	 */
	private const ASSET_CATEGORY_CURRENT = ['laptop', 'phone', 'vehicle', 'tool', 'accessPass', 'clothing', 'other'];

	/**
	 * Current schema `Asset.status` enum.
	 *
	 * @var string[]
	 */
	private const ASSET_STATUS_CURRENT = ['available', 'issued', 'checkedIn', 'writtenOff'];

	/**
	 * Old Dutch `Asset` field name -> renamed field name (design.md D1).
	 *
	 * @var array<string, string>
	 */
	private const ASSET_FIELD_MAP = [
		'kenteken'      => 'licencePlate',
		'serienummer'   => 'serialNumber',
		'aanschafdatum' => 'purchaseDate',
		'aanschafwaarde' => 'purchaseValue',
	];

	/**
	 * Old Dutch `AssetAssignment` field name -> renamed field name (design.md D1).
	 *
	 * @var array<string, string>
	 */
	private const ASSIGNMENT_FIELD_MAP = [
		'uitgifteDatum'     => 'issuedOn',
		'innameDatum'       => 'returnedOn',
		'uitgifteBonSigned' => 'issueReceiptSigned',
		'eigenBijdrage'     => 'employeeContribution',
	];



	/**
	 * Compute the migrated shape of one `Asset` row, split into a
	 * status-untouched write (category + field renames, always safe) and
	 * the full target shape including the translated status (may be
	 * rejected by the lifecycle guard -- see class docblock).
	 *
	 * @param array<string, mixed> $row The raw Asset row.
	 *
	 * @return array{hardSkipReason: string|null, nonStatusChanged: bool, statusChanged: bool, withoutStatusChange: array<string, mixed>, final: array<string, mixed>}
	 */
	public function mapAssetRow(array $row): array {
		$withoutStatusChange = $row;
		$nonStatusChanged = false;
		$hardSkipReasons = [];

		// category.
		$categoryResult = $this->mapEnumValue(
			value: ($row['category'] ?? null),
			map: self::ASSET_CATEGORY_MAP,
			current: self::ASSET_CATEGORY_CURRENT,
			label: 'category'
		);
		if ($categoryResult['skipReason'] !== null) {
			$hardSkipReasons[] = $categoryResult['skipReason'];
		}

		if ($categoryResult['changed'] === true) {
			$withoutStatusChange['category'] = $categoryResult['value'];
			$nonStatusChanged = true;
		}

		// status (translated into $final only -- left untouched in
		// $withoutStatusChange, see class docblock).
		$statusResult = $this->mapEnumValue(
			value: ($row['status'] ?? null),
			map: self::ASSET_STATUS_MAP,
			current: self::ASSET_STATUS_CURRENT,
			label: 'status'
		);
		if ($statusResult['skipReason'] !== null) {
			$hardSkipReasons[] = $statusResult['skipReason'];
		}

		$statusChanged = $statusResult['changed'];
		$newStatus = $statusResult['value'];

		// Field renames (kenteken/serienummer/aanschafdatum/aanschafwaarde).
		$fieldResult = $this->applyFieldRenames($withoutStatusChange, self::ASSET_FIELD_MAP);
		if ($fieldResult['hardSkipReason'] !== null) {
			$hardSkipReasons[] = $fieldResult['hardSkipReason'];
		}

		if ($fieldResult['changed'] === true) {
			$withoutStatusChange = $fieldResult['row'];
			$nonStatusChanged = true;
		}

		$withoutStatusChange = $this->normaliseAssetTypes(row: $withoutStatusChange);

		$final = $withoutStatusChange;
		if ($statusChanged === true) {
			$final['status'] = $newStatus;
		}

		return [
			'hardSkipReason' => ($hardSkipReasons === [] ? null : implode('; ', $hardSkipReasons)),
			'nonStatusChanged' => $nonStatusChanged,
			'statusChanged' => $statusChanged,
			'withoutStatusChange' => $withoutStatusChange,
			'final' => $final,
		];

	}//end mapAssetRow()

	/**
	 * Map one enum value from the old dialect to the new one.
	 *
	 * Three outcomes, and the third is the one that matters: a value that is
	 * neither a known legacy value nor a current one is NOT guessed at. It is
	 * reported so the row is skipped with the offending value named, rather
	 * than silently written through or silently dropped.
	 *
	 * @param mixed                 $value   The stored value.
	 * @param array<string, string> $map     Legacy value => current value.
	 * @param string[]              $current The current schema's enum.
	 * @param string                $label   Property name, for the skip reason.
	 *
	 * @return array{value: string|null, changed: bool, skipReason: string|null}
	 */
	private function mapEnumValue(mixed $value, array $map, array $current, string $label): array {
		if (is_string($value) === false || $value === '') {
			return ['value' => null, 'changed' => false, 'skipReason' => null];
		}

		if (isset($map[$value]) === true) {
			return ['value' => $map[$value], 'changed' => true, 'skipReason' => null];
		}

		if (in_array($value, $current, true) === true) {
			return ['value' => $value, 'changed' => false, 'skipReason' => null];
		}

		return [
			'value'      => null,
			'changed'    => false,
			'skipReason' => "unrecognised " . $label . " value '" . $value . "'",
		];
	}//end mapEnumValue()

	/**
	 * Coerce the two renamed Asset fields whose stored TYPE also changed.
	 *
	 * `aanschafdatum` carried a datetime ("YYYY-MM-DD HH:MM:SS") where
	 * `purchaseDate` is `format: date`; `aanschafwaarde` came through as a
	 * numeric string ("1249.00") where `purchaseValue` is `type: number`.
	 * Renaming the key alone would carry the old type into the new field and
	 * fail validation.
	 *
	 * @param array<string, mixed> $row The row, post-rename.
	 *
	 * @return array<string, mixed> The row with both fields in their declared type.
	 */
	private function normaliseAssetTypes(array $row): array {
		if (isset($row['purchaseDate']) === true && is_string($row['purchaseDate']) === true) {
			$row['purchaseDate'] = substr($row['purchaseDate'], 0, 10);
		}

		if (isset($row['purchaseValue']) === true && is_numeric($row['purchaseValue']) === true) {
			$row['purchaseValue'] = (float) $row['purchaseValue'];
		}

		return $row;
	}//end normaliseAssetTypes()

	/**
	 * Apply a set of old-field -> new-field renames to a row. A row
	 * carrying BOTH the old and new field with DIFFERENT values is not
	 * guessed at -- it is reported via `hardSkipReason` and left untouched;
	 * with EQUAL values, the old (redundant) key is simply dropped.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, string> $fieldMap Old field name => new field name.
	 *
	 * @return array{row: array<string, mixed>, changed: bool, hardSkipReason: string|null}
	 */
	private function mapFieldRenames(array $row, array $fieldMap): array {
		$result = $this->applyFieldRenames($row, $fieldMap);
		return [
			'row' => $result['row'],
			'changed' => $result['changed'],
			'hardSkipReason' => $result['hardSkipReason'],
		];

	}//end mapFieldRenames()

	/**
	 * Shared field-rename engine used by both `mapAssetRow()` and
	 * `mapFieldRenames()`.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, string> $fieldMap Old field name => new field name.
	 *
	 * @return array{row: array<string, mixed>, changed: bool, hardSkipReason: string|null}
	 */
	private function applyFieldRenames(array $row, array $fieldMap): array {
		$updated = $row;
		$changed = false;
		$conflicts = [];

		foreach ($fieldMap as $old => $new) {
			if (array_key_exists($old, $row) === false || $row[$old] === null) {
				// A null old-name key is an ABSENT value, not a value to
				// migrate -- OpenRegister objects can carry a null-valued
				// key left over from an earlier schema version that once
				// declared it (measured: `kenteken`/`aanschafdatum`/
				// `aanschafwaarde`/`uitgifteDatum` all appear as explicit
				// nulls on rows that never held Dutch-dialect data in the
				// first place). Treating a null as "present" would report a
				// false conflict against a populated new-name field and
				// block an already-current row from ever reaching
				// alreadyCurrent.
				continue;
			}

			$oldValue = $row[$old];
			$newPresent = array_key_exists($new, $row) === true && $row[$new] !== null;

			if ($newPresent === true) {
				$newValue = $row[$new];
				if ($this->valuesEquivalent($oldValue, $newValue) === true) {
					// Both names hold the same value -- this field's job is
					// done, and there is nothing left to WRITE: OpenRegister
					// never generates a SET clause for a property the
					// CURRENT schema no longer declares, so neither omitting
					// the retired key NOR sending it as an explicit null
					// clears it (both measured against the live instance --
					// orchestrator review, 2026-08-19, Defect C's first
					// "fix" assumed an explicit null would be written
					// through; it is not even looked at). The retired key's
					// stale value is permanent, harmless debris no schema or
					// consumer reads any more -- treating its mere presence
					// as still-unfinished work would make this field retry
					// forever, which is what actually broke idempotency.
					continue;
				}

				$conflicts[] = sprintf(
					"both '%s' (%s) and '%s' (%s) present with different values, refusing to guess",
					$old,
					json_encode($oldValue),
					$new,
					json_encode($newValue)
				);
				continue;
			}//end if

			// Plain rename: the new name is not populated yet, so writing it
			// is real, achievable progress -- the retired key is left
			// exactly as-is in the payload (see the branch above for why
			// touching it at all would be a wasted write).
			$updated[$new] = $oldValue;
			$changed = true;
		}//end foreach

		return [
			'row' => $updated,
			'changed' => $changed,
			'hardSkipReason' => ($conflicts === [] ? null : implode('; ', $conflicts)),
		];

	}//end applyFieldRenames()

	/**
	 * Loose-but-safe equivalence for a duplicate old/new field pair (a
	 * legacy string boolean and a real boolean, or a numeric string and a
	 * float, should count as "already the same value").
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 *
	 * @return bool
	 */
	private function valuesEquivalent(mixed $a, mixed $b): bool {
		if ($a === $b) {
			return true;
		}

		if (is_numeric($a) === true && is_numeric($b) === true) {
			return (float)$a === (float)$b;
		}

		if (is_bool($a) === true || is_bool($b) === true) {
			return (bool)$a === (bool)$b;
		}

		if ($this->sameCalendarDay($a, $b) === true) {
			return true;
		}

		return (string)$a === (string)$b;

	}//end valuesEquivalent()

	/**
	 * Whether two values are the same calendar day at different precisions.
	 *
	 * A datetime string ("YYYY-MM-DD HH:MM:SS", `aanschafdatum`'s old stored
	 * precision) and a date-only string ("YYYY-MM-DD", `purchaseDate`'s
	 * `format: date`) for the same day are the same value at a different
	 * precision, not a conflict -- measured against the live instance, where
	 * every pre-existing Asset date was midnight.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 *
	 * @return bool True only when both are date-shaped strings for one day.
	 */
	private function sameCalendarDay(mixed $a, mixed $b): bool {
		if (is_string($a) === false || is_string($b) === false) {
			return false;
		}

		$shape = '/^\d{4}-\d{2}-\d{2}/';
		if (preg_match($shape, $a) !== 1 || preg_match($shape, $b) !== 1) {
			return false;
		}

		return substr($a, 0, 10) === substr($b, 0, 10);
	}//end sameCalendarDay()

	/**
	 * Map one `AssetAssignment` row from the old dialect to the new one.
	 *
	 * Only field names changed on this schema -- it carries no enum whose
	 * values were translated -- so this is the rename engine and nothing else.
	 *
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return array{row: array<string, mixed>, changed: bool, hardSkipReason: string|null}
	 */
	public function mapAssignmentRow(array $row): array {
		return $this->mapFieldRenames($row, self::ASSIGNMENT_FIELD_MAP);
	}//end mapAssignmentRow()

}//end class
