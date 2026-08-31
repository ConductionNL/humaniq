<?php

/**
 * NL ATS (Recruitment) Check Provider
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
 * @spec openspec/changes/recruiting-ats-basic/specs/recruiting-applications/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;
use OCA\Humaniq\Standards\RuleCatalogue;

/**
 * Dutch ATS/recruitment AVG-retention executable checks.
 */
final class NlAtsChecks implements CheckProvider {

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
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
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

}//end class
