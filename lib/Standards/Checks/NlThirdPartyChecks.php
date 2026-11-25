<?php

/**
 * NL Third-Party Payments (UBD, formerly IB47) Check Provider
 *
 * Executable checks for the yearly report of payments to third parties
 * (filings-ib47 D3), framework nl-ubd of the payroll corpus:
 * a payment whose year's report was not sent by 31 January of the next
 * year (nl-ubd-deadline, on ThirdPartyPayment), and a paid payee without
 * BSN or date of birth (nl-ubd-payee-identification, on ThirdPartyPayee).
 *
 * Both are cross-object: they read the `context['ubd']` index
 * RuleAuditService::audit() builds (the administration|year keys of sent
 * reports, the ids of payees with payments) rather than loading siblings.
 * No SeedsObjects: the seed lives in lib/Settings/register.d/hr-seed.json.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): both rules are declared as OpenRegister decision
 * tables via the ProvidesTables capability. The derivations (deadline passed,
 * report sent, payee paid, BSN and date of birth present) stay here; the
 * shared Dmn\DecisionTableEvaluator decides. The closure predicates remain
 * available as legacyChecks() -- the parity oracle, NOT registered in the
 * engine -- until staged retirement (REQ-RULE-009).
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-002
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;

/**
 * Third-party payments report checks.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-002
 */
final class NlThirdPartyChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: every check this provider owns is table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * Both rules as derivation + FIRST-hit decision table. A payment whose
	 * date cannot be read derives null and passes, as before.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-002
	 */
	public static function tables(): array {
		return [
			'ThirdPartyPayment' => [
				// Belastingdienst, opgaaf uitbetaalde bedragen aan derden: report by 31 January of the next year.
				'nl-ubd-deadline' => [
					'derive' => static fn (array $object, array $context): ?array => self::deadlineInputs($object, $context),
					'table' => self::satisfiedTable(
						[['name' => 'deadlinePassed', 'type' => 'boolean'], ['name' => 'reportSent', 'type' => 'boolean']],
						[['id' => 'deadline-passed-without-report', 'inputEntries' => ['true', 'false'], 'outputEntries' => [false]]]
					),
				],
			],
			'ThirdPartyPayee' => [
				// UBD message: ONTVANGER/geboortedatum and bSN are mandatory for a natural person.
				'nl-ubd-payee-identification' => [
					'derive' => static fn (array $object, array $context): array => [
						'paid' => isset($context['ubd']['paidPayees'][self::payeeId($object)]),
						'bsnRecorded' => (trim((string)($object['bsn'] ?? '')) !== ''),
						'dateOfBirthRecorded' => (trim((string)($object['dateOfBirth'] ?? '')) !== ''),
					],
					'table' => self::satisfiedTable(
						[
							['name' => 'paid', 'type' => 'boolean'],
							['name' => 'bsnRecorded', 'type' => 'boolean'],
							['name' => 'dateOfBirthRecorded', 'type' => 'boolean'],
						],
						[
							['id' => 'paid-without-bsn', 'inputEntries' => ['true', 'false', '-'], 'outputEntries' => [false]],
							['id' => 'paid-without-date-of-birth', 'inputEntries' => ['true', '-', 'false'], 'outputEntries' => [false]],
						]
					),
				],
			],
		];

	}//end tables()

	/**
	 * The pre-conversion closure predicates, kept as the PARITY ORACLE only:
	 * not registered in the engine (checks() is empty), consumed by the
	 * table/legacy parity test, retired with the other converted rules after
	 * the table form is proven on an OpenRegister-backed audit run
	 * (REQ-RULE-009, tasks section 6).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-002
	 */
	public static function legacyChecks(): array {
		return [
			'ThirdPartyPayment' => [
				// Belastingdienst, opgaaf uitbetaalde bedragen aan derden: report by 31 January of the next year.
				'nl-ubd-deadline' => static fn (array $object, array $context): bool => self::reportedInTime($object, $context),
			],
			'ThirdPartyPayee' => [
				// UBD message: ONTVANGER/geboortedatum and bSN are mandatory for a natural person.
				'nl-ubd-payee-identification' => static fn (array $object, array $context): bool => self::identified($object, $context),
			],
		];

	}//end legacyChecks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-002
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * The deadline table's inputs: whether 31 January after the payment's
	 * year has passed on the audit date (`context['today']` when given, else
	 * today), and whether the administration's report for that year is sent.
	 * Null when the payment date cannot be read: not this rule's finding.
	 *
	 * @param array<string, mixed> $object  The ThirdPartyPayment.
	 * @param array<string, mixed> $context Evaluation context (carries `ubd.sent`).
	 *
	 * @return array{deadlinePassed: bool, reportSent: bool}|null
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function deadlineInputs(array $object, array $context): ?array {
		$paidOn = (string)($object['paidOn'] ?? '');
		if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $paidOn, $matches) !== 1) {
			return null;
		}

		$year = (int)$matches[1];
		$today = new DateTimeImmutable((string)($context['today'] ?? 'today'));
		$key = trim((string)($object['administrationId'] ?? '')) . '|' . $year;

		return [
			'deadlinePassed' => ($today > new DateTimeImmutable(($year + 1) . '-01-31')),
			'reportSent' => isset($context['ubd']['sent'][$key]),
		];

	}//end deadlineInputs()

	/**
	 * The payee's id, from the object or its `@self` envelope.
	 *
	 * @param array<string, mixed> $object The ThirdPartyPayee.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-002
	 */
	private static function payeeId(array $object): string {
		return (string)($object['id'] ?? $object['@self']['id'] ?? '');

	}//end payeeId()

	/**
	 * True while the deadline of the payment's year has not passed, or the
	 * administration's report for that year is sent. The audit date is
	 * `context['today']` when given, else today.
	 *
	 * @param array<string, mixed> $object  The ThirdPartyPayment.
	 * @param array<string, mixed> $context Evaluation context (carries `ubd.sent`).
	 *
	 * @return bool
	 */
	private static function reportedInTime(array $object, array $context): bool {
		$paidOn = (string)($object['paidOn'] ?? '');
		if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $paidOn, $matches) !== 1) {
			return true;
		}

		$year = (int)$matches[1];
		$today = new DateTimeImmutable((string)($context['today'] ?? 'today'));
		if ($today <= new DateTimeImmutable(($year + 1) . '-01-31')) {
			return true;
		}

		$key = trim((string)($object['administrationId'] ?? '')) . '|' . $year;
		return isset($context['ubd']['sent'][$key]) === true;
	}//end reportedInTime()

	/**
	 * True when the payee has a BSN and a date of birth, or nobody paid
	 * them.
	 *
	 * @param array<string, mixed> $object  The ThirdPartyPayee.
	 * @param array<string, mixed> $context Evaluation context (carries `ubd.paidPayees`).
	 *
	 * @return bool
	 */
	private static function identified(array $object, array $context): bool {
		$id = (string)($object['id'] ?? $object['@self']['id'] ?? '');
		if (isset($context['ubd']['paidPayees'][$id]) === false) {
			return true;
		}

		return trim((string)($object['bsn'] ?? '')) !== '' && trim((string)($object['dateOfBirth'] ?? '')) !== '';
	}//end identified()

}//end class
