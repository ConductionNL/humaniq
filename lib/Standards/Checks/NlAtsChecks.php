<?php

/**
 * NL ATS (Recruitment) Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable checks for the two machine-checkable AVG/GDPR retention rules of
 * the recruiting-ats-basic change (lib/Standards/rules/privacy.json, domain
 * gdpr-recruitment): a rejected Application must carry a correctly-derived
 * retentionExpiryDate (nl-ats-retentie-derivatie — rejectedDate + 4 weeks
 * without talent-pool consent, or + 1 year with it, per the Autoriteit
 * Persoonsgegevens sollicitatie-richtlijn, AVG art. 5 lid 1 sub e), and an
 * Application whose retentionExpiryDate has passed must no longer exist
 * un-anonymised in the register (nl-ats-retentie-verlopen). Both predicates are
 * scoped to the Application object type and read their day-offsets from the
 * corpus rule's `parameters` (data-over-code, the WVP milestoneWeeks / loonaangifte
 * tijdvakcode convention) rather than hard-coding them here.
 *
 * This provider does NOT implement SeedsObjects: sample data lives in
 * lib/Settings/register.d/hr-seed.json alongside the seeded Vacancy the
 * Applications reference (the NlOnboardingChecks precedent — a self-contained
 * sample cannot carry a resolvable vacancyId cross-reference).
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): each of the two retention rules is declared as an OpenRegister decision table via
 * the ProvidesTables capability. The derivations stay here; the shared
 * Dmn\DecisionTableEvaluator decides. The closure predicate remains
 * available as legacyChecks() -- the parity oracle, NOT registered in the
 * engine -- until staged retirement (REQ-RULE-009).
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/recruiting-applications/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;
use OCA\Humaniq\Standards\RuleCatalogue;

/**
 * Dutch ATS/recruitment AVG-retention executable checks.
 *
 * @spec openspec/specs/recruiting-applications/spec.md
 */
final class NlAtsChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * Fallback retention offset (days) without talent-pool consent, used only
	 * when the corpus rule's `parameters` cannot be read.
	 *
	 * @var int
	 */
	private const DEFAULT_RETENTION_DAYS = 28;

	/**
	 * Fallback retention offset (days) with talent-pool consent, used only when
	 * the corpus rule's `parameters` cannot be read.
	 *
	 * @var int
	 */
	private const DEFAULT_OPT_IN_RETENTION_DAYS = 365;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the check this provider owns is table-declared (see tables()).
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
	 * Both job-application retention rules as derivation + FIRST-hit decision
	 * tables. The date arithmetic (the expected expiry, the expiry passed)
	 * stays in the derivations; an expiry that cannot be read derives null
	 * for the expiry rule and passes, as before.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/recruiting-applications/spec.md
	 */
	public static function tables(): array {
		return [
			'job-application' => [
				// AVG art. 5 lid 1 sub e; AP richtlijn sollicitatiegegevens — the
				// retention clock derivation must be correct on a rejected application.
				'nl-ats-retentie-derivatie' => [
					'derive' => static fn (array $o): array => self::derivatieInputs($o),
					'table' => self::satisfiedTable(
						[['name' => 'rejected', 'type' => 'boolean'], ['name' => 'datesReadable', 'type' => 'boolean'], ['name' => 'clockMatches', 'type' => 'boolean']],
						[
							['id' => 'rejected-without-dates', 'inputEntries' => ['true', 'false', '-'], 'outputEntries' => [false]],
							['id' => 'rejected-with-wrong-clock', 'inputEntries' => ['true', '-', 'false'], 'outputEntries' => [false]],
						]
					),
				],
				// AVG art. 5 lid 1 sub e; AP richtlijn sollicitatiegegevens — an
				// application past its retention clock must no longer exist un-anonymised.
				'nl-ats-retentie-verlopen' => [
					'derive' => static fn (array $o): ?array => self::verlopenInputs($o),
					'table' => self::satisfiedTable(
						[['name' => 'expired', 'type' => 'boolean']],
						[['id' => 'held-past-expiry', 'inputEntries' => ['true'], 'outputEntries' => [false]]]
					),
				],
			],
		];

	}//end tables()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/recruiting-applications/spec.md
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True on every non-afgewezen status (nothing to derive yet — vacuous pass).
	 * On afgewezen: false when rejectedDate or retentionExpiryDate is null, or
	 * when retentionExpiryDate does not equal rejectedDate plus the applicable
	 * rule-parameterised offset (28 days, or 365 with talentPoolOptIn).
	 *
	 * @param array<string, mixed> $o The Application.
	 *
	 * @return bool
	 */
	private static function derivatieSatisfied(array $o): bool {
		if ((string)($o['status'] ?? 'nieuw') !== 'afgewezen') {
			return true;
		}

		$rejectedDate = trim((string)($o['rejectedDate'] ?? ''));
		$retentionExpiryDate = trim((string)($o['retentionExpiryDate'] ?? ''));
		if ($rejectedDate === '' || $retentionExpiryDate === '') {
			return false;
		}

		$rejected = strtotime($rejectedDate);
		$expiry = strtotime($retentionExpiryDate);
		if ($rejected === false || $expiry === false) {
			return false;
		}

		$days = self::retentionDays(($o['talentPoolOptIn'] ?? false) === true);
		$expected = strtotime('+' . $days . ' days', $rejected);

		return $expiry === $expected;
	}//end derivatieSatisfied()

	/**
	 * True when retentionExpiryDate is null (no clock running — nieuw/screening/
	 * gesprek/aanbod applications, or an aangenomen hire out of scope per D4), or
	 * the date lies on/after the audit run date; false once it lies in the past.
	 *
	 * @param array<string, mixed> $o The Application.
	 *
	 * @return bool
	 */
	private static function verlopenSatisfied(array $o): bool {
		$retentionExpiryDate = trim((string)($o['retentionExpiryDate'] ?? ''));
		if ($retentionExpiryDate === '') {
			return true;
		}

		$expiry = strtotime($retentionExpiryDate);
		if ($expiry === false) {
			return true;
		}

		return $expiry >= (new DateTimeImmutable('today'))->getTimestamp();
	}//end verlopenSatisfied()

	/**
	 * The applicable retention offset in days, read from the
	 * nl-ats-retentie-derivatie corpus rule's `parameters`
	 * (retentionDays / optInRetentionDays) — never hard-coded, save for the
	 * fallback used only when the rule/parameters cannot be read.
	 *
	 * @param bool $optedIn Whether talentPoolOptIn is true.
	 *
	 * @return int
	 */
	private static function retentionDays(bool $optedIn): int {
		$parameters = self::derivatieParameters();
		if ($parameters === null) {
			return $optedIn === true ? self::DEFAULT_OPT_IN_RETENTION_DAYS : self::DEFAULT_RETENTION_DAYS;
		}

		$key = $optedIn === true ? 'optInRetentionDays' : 'retentionDays';
		$default = $optedIn === true ? self::DEFAULT_OPT_IN_RETENTION_DAYS : self::DEFAULT_RETENTION_DAYS;

		return isset($parameters[$key]) === true ? (int)$parameters[$key] : $default;
	}//end retentionDays()

	/**
	 * The `parameters` object of the nl-ats-retentie-derivatie corpus rule, or
	 * null when the rule is missing from the catalogue.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function derivatieParameters(): ?array {
		foreach (RuleCatalogue::all() as $rule) {
			if ((string)($rule['id'] ?? '') !== 'nl-ats-retentie-derivatie') {
				continue;
			}

			$parameters = ($rule['parameters'] ?? null);
			return is_array($parameters) === true ? $parameters : null;
		}

		return null;
	}//end derivatieParameters()

	/**
	 * The derivation table's inputs: whether the application is rejected,
	 * whether both dates read, and whether the expiry is the rejection date
	 * plus the (opt-in dependent) retention days.
	 *
	 * @param array<string, mixed> $o The job application.
	 *
	 * @return array{rejected: bool, datesReadable: bool, clockMatches: bool}
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function derivatieInputs(array $o): array {
		$rejectedDate = trim((string)($o['rejectedDate'] ?? ''));
		$expiryDate = trim((string)($o['retentionExpiryDate'] ?? ''));
		$rejected = ($rejectedDate === '') ? false : strtotime($rejectedDate);
		$expiry = ($expiryDate === '') ? false : strtotime($expiryDate);
		$readable = ($rejected !== false && $expiry !== false);
		$days = self::retentionDays(($o['talentPoolOptIn'] ?? false) === true);

		return [
			'rejected' => ((string)($o['status'] ?? 'nieuw') === 'afgewezen'),
			'datesReadable' => $readable,
			'clockMatches' => ($readable === true && $expiry === strtotime('+' . $days . ' days', $rejected)),
		];

	}//end derivatieInputs()

	/**
	 * The expiry table's input, or null when there is no readable expiry.
	 *
	 * @param array<string, mixed> $o The job application.
	 *
	 * @return array{expired: bool}|null
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function verlopenInputs(array $o): ?array {
		$expiryDate = trim((string)($o['retentionExpiryDate'] ?? ''));
		$expiry = ($expiryDate === '') ? false : strtotime($expiryDate);
		if ($expiry === false) {
			return null;
		}

		return ['expired' => ($expiry < (new DateTimeImmutable('today'))->getTimestamp())];

	}//end verlopenInputs()

	/**
	 * The two retention closures from before the tables, kept as the oracle
	 * NlAtsTableParityTest checks tables() against; unregistered, and retired
	 * after the OpenRegister audit run.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/recruiting-applications/spec.md
	 */
	public static function legacyChecks(): array {
		return [
			'job-application' => [
				// AVG art. 5 lid 1 sub e; AP richtlijn sollicitatiegegevens — the
				// retention clock derivation must be correct on a rejected application.
				'nl-ats-retentie-derivatie' => static fn (array $o): bool => self::derivatieSatisfied($o),
				// AVG art. 5 lid 1 sub e; AP richtlijn sollicitatiegegevens — an
				// application past its retention clock must no longer exist un-anonymised.
				'nl-ats-retentie-verlopen' => static fn (array $o): bool => self::verlopenSatisfied($o),
			],
		];

	}//end legacyChecks()

}//end class
