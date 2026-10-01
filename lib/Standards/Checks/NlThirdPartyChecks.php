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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;

/**
 * Third-party payments report checks.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-002
 */
final class NlThirdPartyChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'ThirdPartyPayment' => [
				// Belastingdienst, opgaaf uitbetaalde bedragen aan derden: report by 31 January of the next year.
				'nl-ubd-deadline' => static fn (array $o, array $c): bool => self::reportedInTime($o, $c),
			],
			'ThirdPartyPayee' => [
				// UBD message: ONTVANGER/geboortedatum and bSN are mandatory for a natural person.
				'nl-ubd-payee-identification' => static fn (array $o, array $c): bool => self::identified($o, $c),
			],
		];

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
	 * True while the deadline of the payment's year has not passed, or the
	 * administration's report for that year is sent. The audit date is
	 * `context['today']` when given, else today.
	 *
	 * @param array<string, mixed> $o The ThirdPartyPayment.
	 * @param array<string, mixed> $c Evaluation context (carries `ubd.sent`).
	 *
	 * @return bool
	 */
	private static function reportedInTime(array $o, array $c): bool {
		$paidOn = (string)($o['paidOn'] ?? '');
		if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $paidOn, $matches) !== 1) {
			return true;
		}

		$year = (int)$matches[1];
		$today = new DateTimeImmutable((string)($c['today'] ?? 'today'));
		if ($today <= new DateTimeImmutable(($year + 1) . '-01-31')) {
			return true;
		}

		$key = trim((string)($o['administrationId'] ?? '')) . '|' . $year;
		return isset($c['ubd']['sent'][$key]) === true;
	}//end reportedInTime()

	/**
	 * True when the payee has a BSN and a date of birth, or nobody paid
	 * them.
	 *
	 * @param array<string, mixed> $o The ThirdPartyPayee.
	 * @param array<string, mixed> $c Evaluation context (carries `ubd.paidPayees`).
	 *
	 * @return bool
	 */
	private static function identified(array $o, array $c): bool {
		$id = (string)($o['id'] ?? $o['@self']['id'] ?? '');
		if (isset($c['ubd']['paidPayees'][$id]) === false) {
			return true;
		}

		return trim((string)($o['bsn'] ?? '')) !== '' && trim((string)($o['dateOfBirth'] ?? '')) !== '';
	}//end identified()

}//end class
