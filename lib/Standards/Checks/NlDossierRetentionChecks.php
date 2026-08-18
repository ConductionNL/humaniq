<?php

/**
 * NL Dossier Retention Checks
 *
 * hrmq#99 hole #2 (consume-not-rebuild correction): nothing in this
 * corpus previously flagged the OPPOSITE direction from every existing
 * bewaarplicht-floor check (`nl-id-bewaarplicht-5jaar` and siblings check
 * that a retention field is populated FAR ENOUGH in the future -- has the
 * record not been destroyed too early). Nothing checked whether a record is
 * still present PAST its own retention ceiling -- the AVG art. 5 lid 1 sub e
 * storage-limitation direction ("this should probably be gone by now").
 *
 * `nl-bewaartermijn-verstreken` reads OpenRegister's own computed
 * `retention.archiefactiedatum` (`RetentionService::applyArchivalMetadata()`/
 * `calculateArchiefactiedatum()`, populated automatically on save for any
 * schema with an `archive` config) -- NEVER a bespoke hrmq field. This is
 * deliberately the SAME ceiling `Archival\DestructionService
 * ::findEligibleObjects()` already reads to list destruction candidates; this
 * check surfaces the fact at audit time (`occ hrmq:rules:audit`), it does
 * not act on it -- no automated destruction job exists here (a materially
 * different, materially riskier capability, out of scope).
 *
 * Scoped to the payroll/loonadministratie schema family (the same set the
 * now-deleted `AvgDsrRetentionClassifier` covered) plus `GeneratedDocument`
 * (hrmq#99 hole #1 -- a generated PDF that inherited a legal hold from its
 * source carries no expiry date on the hold itself, so it is naturally
 * vacuous here until/unless a future change gives `GeneratedDocument` its
 * own `archive` config).
 *
 * @category Standards
 * @package  OCA\Hrmq\Standards\Checks
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use DateTimeImmutable;

/**
 * Storage-limitation-ceiling check: an object still present past its own
 * OpenRegister-computed `retention.archiefactiedatum`.
 */
final class NlDossierRetentionChecks implements CheckProvider {

	/**
	 * The payroll/loonadministratie schema family this check applies to,
	 * plus `GeneratedDocument` (hrmq#99 hole #1). Mirrors the deleted
	 * `AvgDsrRetentionClassifier::PAYROLL_FAMILY_SCHEMAS` scope.
	 *
	 * @var array<int, string>
	 */
	private const SCOPED_SCHEMAS = [
		'Payslip',
		'PayrollRun',
		'LoonaangifteFiling',
		'PayrollMutationReport',
		'WkrDeclaration',
		'WkrAssessment',
		'PensionFiling',
		'GeneratedDocument',
	];

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
	 */
	public static function checks(): array {
		$checks = [];
		foreach (self::SCOPED_SCHEMAS as $schema) {
			$checks[$schema] = [
				'nl-bewaartermijn-verstreken' => static fn (array $object): bool => self::notPastCeiling($object),
			];
		}

		return $checks;
	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * Vacuous when no `retention.archiefactiedatum` is populated, or when
	 * the object's `archiefstatus` is already `vernietigd` (properly
	 * destroyed -- not "still present"). Violates when a populated
	 * `archiefactiedatum` date is before today and the object is not
	 * `vernietigd`.
	 *
	 * @param array<string, mixed> $object The object (as `RuleAuditService` passes it -- `@self.retention` carries OpenRegister's archival metadata).
	 *
	 * @return bool
	 */
	private static function notPastCeiling(array $object): bool {
		$self = (array)($object['@self'] ?? []);
		$retention = (array)($self['retention'] ?? []);

		$archiefstatus = (string)($retention['archiefstatus'] ?? '');
		if ($archiefstatus === 'vernietigd') {
			return true;
		}

		$archiefactiedatum = trim((string)($retention['archiefactiedatum'] ?? ''));
		if ($archiefactiedatum === '') {
			return true;
		}

		$ceiling = strtotime($archiefactiedatum);
		if ($ceiling === false) {
			return true;
		}

		return $ceiling >= (new DateTimeImmutable('today'))->getTimestamp();
	}//end notPastCeiling()

}//end class
