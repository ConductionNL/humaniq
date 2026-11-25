<?php

/**
 * Table/legacy parity for the converted loonaangifte rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the tijdvak and
 * tijdvakcode rules of NlWageTaxFilingChecks are declared decision tables,
 * and their pre-conversion closures stay as the parity oracle
 * (`NlWageTaxFilingChecks::legacyChecks()`). This suite drives both over one
 * pinned matrix (filing types, jurisdictions, electronic or not, every
 * tijdvak and an unknown one, periods per grain and malformed, tijdvakcodes
 * expected, wrong, padded or missing, concept and sent statuses) against the
 * shipped LH 210 parameters, and asserts identical verdicts. The other five
 * rules stay closures, which the last test pins.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlWageTaxFilingChecks;
use OCA\Humaniq\Standards\RuleCatalogue;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the loonaangifte tijdvak rules.
 */
class NlWageTaxFilingTableParityTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		RuleCatalogue::reset();

	}//end setUp()

	/**
	 * The pinned LoonaangifteFiling matrix: label => filing.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fixtures(): array {
		$fixtures = [];
		$periods = ['2026-01' => 'maand', '2026-12' => 'maand', '2026-P05' => 'vierweken', '2026-P13' => 'vierweken', '2026-Q2' => 'kwartaal', '2026' => 'jaar', 'januari' => 'maand'];
		foreach (['loonaangifte NL' => ['loonaangifte', 'NL'], 'loonaangifte BE' => ['loonaangifte', 'BE'], 'other NL' => ['pensioen', 'NL'], 'untyped' => [null, null]] as $tLabel => [$type, $jurisdiction]) {
			foreach ($periods as $period => $grain) {
				foreach ([$grain, 'week', ''] as $tijdvak) {
					foreach (['expected' => true, 'wrong' => '9999', 'padded' => 'pad', 'blank' => '', 'absent' => null] as $cLabel => $code) {
						foreach (['concept' => 'concept', 'verzonden' => 'verzonden', 'default' => null] as $sLabel => $status) {
							foreach (['electronic' => true, 'paper' => false, 'truthy' => 1] as $eLabel => $electronic) {
								$filing = ['period' => $period, 'tijdvak' => $tijdvak, 'electronicallyFiled' => $electronic];
								foreach (['filingType' => $type, 'jurisdiction' => $jurisdiction, 'status' => $status] as $field => $value) {
									if ($value !== null) {
										$filing[$field] = $value;
									}
								}

								$fixtures[sprintf('%s %s tijdvak=%s code=%s %s %s', $tLabel, $period, $tijdvak, $cLabel, $sLabel, $eLabel)] = [$filing, $code];
							}
						}
					}
				}
			}
		}

		// Fill in each expected code from the oracle's own answers: try the
		// candidate codes and keep the one the legacy rule accepts.
		$legacy = NlWageTaxFilingChecks::legacyChecks()['LoonaangifteFiling']['nl-loonaangifte-tijdvakcode'];
		$candidates = [];
		foreach (RuleCatalogue::all() as $rule) {
			if (($rule['id'] ?? '') === 'nl-loonaangifte-tijdvakcode') {
				$candidates = array_merge(array_values($rule['parameters']['maandCodes']), $rule['parameters']['vierwekenCodes'], [$rule['parameters']['jaarCode']]);
			}
		}

		$this->assertNotSame([], $candidates, 'the corpus ships the LH 210 codes');
		$resolved = [];
		$expectedFound = 0;
		foreach ($fixtures as $label => [$filing, $code]) {
			if ($code === true || $code === 'pad') {
				$found = null;
				foreach ($candidates as $candidate) {
					if ($legacy($filing + ['tijdvakcode' => $candidate, 'status' => 'verzonden']) === true && $legacy($filing + ['tijdvakcode' => 'x-none', 'status' => 'verzonden']) === false) {
						$found = $candidate;
						break;
					}
				}

				$code = ($found === null ? '6010' : ($code === 'pad' ? ' ' . $found . ' ' : $found));
				$expectedFound += ($found === null ? 0 : 1);
			}

			if ($code !== null) {
				$filing['tijdvakcode'] = $code;
			}

			$resolved[$label] = $filing;
		}

		$this->assertGreaterThan(0, $expectedFound, 'some fixtures carry the code LH 210 expects');

		return $resolved;

	}//end fixtures()

	/**
	 * Both rules answer identically through the table path and the legacy
	 * oracle, and each matrix reaches both verdicts.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlWageTaxFilingChecks::tables()['LoonaangifteFiling'];
		$legacy = NlWageTaxFilingChecks::legacyChecks()['LoonaangifteFiling'];
		$fixtures = $this->fixtures();

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy rule has a table form and vice versa');

		foreach ($tables as $ruleId => $spec) {
			$seen = [];
			foreach ($fixtures as $label => $filing) {
				$inputs = ($spec['derive'])($filing, []);
				$verdict = ($inputs === null || TableCheckEvaluator::satisfied($spec['table'], $inputs) === true);
				$seen[$verdict === true ? 'pass' : 'fail'] = true;
				$this->assertSame((bool)($legacy[$ruleId])($filing), $verdict, sprintf('%s: table and legacy verdicts diverge on fixture "%s"', $ruleId, $label));
			}

			$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)], $ruleId . ': the matrix must reach both verdicts');
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * The five date, derivation and drift rules stay closures, and no rule is
	 * registered both ways.
	 *
	 * @return void
	 */
	public function testTheDateAndDriftRulesStayClosures(): void {
		$this->assertSame(
			['nl-loonaangifte-termijn', 'nl-loonadministratie-bewaarplicht', 'nl-loonaangifte-deadline-derivation', 'nl-loonaangifte-deadline-alert', 'nl-loonaangifte-message-drift'],
			array_keys(NlWageTaxFilingChecks::checks()['LoonaangifteFiling'])
		);
		$this->assertSame([], array_intersect(array_keys(NlWageTaxFilingChecks::checks()['LoonaangifteFiling']), array_keys(NlWageTaxFilingChecks::tables()['LoonaangifteFiling'])));

	}//end testTheDateAndDriftRulesStayClosures()

}//end class
