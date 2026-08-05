<?php

/**
 * EmploymentTermsResolver tests
 *
 * Pins the inheritance direction (CAO is the norm, the contract is the
 * exception), the provenance every resolution carries, and the three refusals:
 * an unexplained override, a negative percentage, and a partial merge that
 * would invent terms nobody agreed to.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Hrmq\Service\EmploymentTermsResolver;
use OCA\Hrmq\Standards\CaoRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmploymentTermsResolver.
 */
class EmploymentTermsResolverTest extends TestCase
{

    /**
     * Resolver under test.
     *
     * @var EmploymentTermsResolver
     */
    private EmploymentTermsResolver $resolver;


    /**
     * Build the resolver.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        CaoRegistry::reset();
        $this->resolver = new EmploymentTermsResolver();

    }//end setUp()


    /**
     * A contract override wins over the CAO, and says so.
     *
     * @return void
     */
    public function testContractOverrideWinsOverTheCao(): void
    {
        $terms = $this->resolver->resolveOvertimeToeslag(
            [
                'cao'                         => 'cao-gemeenten',
                'overtimeToeslagPercentages'  => ['doordeweeks' => 50, 'zondag' => 100],
                'overtimeTermsOverrideReason' => 'Individueel onderhandeld bij indiensttreding 2026-03',
            ]
        );

        $this->assertNotNull($terms);
        $this->assertSame(EmploymentTermsResolver::SOURCE_CONTRACT, $terms['source']);
        $this->assertSame(50.0, $terms['percentages']['doordeweeks']);
        $this->assertSame(100.0, $terms['percentages']['zondag']);
        $this->assertStringContainsString('Individueel onderhandeld', $terms['basis']);

    }//end testContractOverrideWinsOverTheCao()


    /**
     * An override wins IN FULL — categories it omits are NOT back-filled from
     * the CAO. A per-category merge would produce a set of terms that exists in
     * neither document and that nobody agreed to.
     *
     * @return void
     */
    public function testOverrideIsNotMergedPerCategoryWithTheCao(): void
    {
        $terms = $this->resolver->resolveOvertimeToeslag(
            [
                'cao'                         => 'cao-gemeenten',
                'overtimeToeslagPercentages'  => ['zondag' => 100],
                'overtimeTermsOverrideReason' => 'Alleen zondagstoeslag afwijkend',
            ]
        );

        $this->assertSame(['zondag' => 100.0], $terms['percentages']);
        $this->assertArrayNotHasKey('zaterdag', $terms['percentages']);
        $this->assertArrayNotHasKey('doordeweeks', $terms['percentages']);

    }//end testOverrideIsNotMergedPerCategoryWithTheCao()


    /**
     * Departing from a collective agreement is a decision someone must be able
     * to justify — an unexplained override is indistinguishable from a
     * data-entry error.
     *
     * @return void
     */
    public function testUnexplainedOvertimeOverrideIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must say why/');

        $this->resolver->resolveOvertimeToeslag(
            ['cao' => 'cao-gemeenten', 'overtimeToeslagPercentages' => ['zondag' => 100]]
        );

    }//end testUnexplainedOvertimeOverrideIsRefused()


    /**
     * A negative surcharge is a data error, not a discount.
     *
     * @return void
     */
    public function testNegativePercentageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not be negative/');

        $this->resolver->resolveOvertimeToeslag(
            [
                'cao'                         => 'cao-gemeenten',
                'overtimeToeslagPercentages'  => ['zondag' => -100],
                'overtimeTermsOverrideReason' => 'typo',
            ]
        );

    }//end testNegativePercentageIsRefused()


    /**
     * With no override, the CAO applies. Every shipped CAO's overtime leaf is
     * currently a placeholder pending transcription against the official
     * CAO-tekst, so the honest resolution today is null — no uplift rather than
     * an invented one. This test states that expectation explicitly so that
     * confirming a CAO's overtime article turns it red, which is the moment
     * someone should re-read this test.
     *
     * @return void
     */
    public function testUnverifiedCaoOvertimeResolvesToNullNotAGuess(): void
    {
        $this->assertNull(CaoRegistry::overtimeToeslagPercentages('cao-gemeenten'));
        $this->assertNull($this->resolver->resolveOvertimeToeslag(['cao' => 'cao-gemeenten']));

    }//end testUnverifiedCaoOvertimeResolvesToNullNotAGuess()


    /**
     * A contract naming no CAO and carrying no override has no terms.
     *
     * @return void
     */
    public function testNoCaoAndNoOverrideYieldsNull(): void
    {
        $this->assertNull($this->resolver->resolveOvertimeToeslag([]));

    }//end testNoCaoAndNoOverrideYieldsNull()


    /**
     * An unknown CAO id resolves to null rather than throwing — a contract
     * naming a CAO the corpus does not carry is a gap, not a crash.
     *
     * @return void
     */
    public function testUnknownCaoResolvesToNull(): void
    {
        $this->assertNull($this->resolver->resolveOvertimeToeslag(['cao' => 'cao-does-not-exist']));

    }//end testUnknownCaoResolvesToNull()


    /**
     * The leave override wins and carries its reason.
     *
     * @return void
     */
    public function testLeaveOverrideWinsAndCarriesItsReason(): void
    {
        $terms = $this->resolver->resolveLeaveEntitlementDays(
            [
                'cao'                          => 'cao-gemeenten',
                'leaveEntitlementOverrideDays' => [
                    'vakantiedagenWettelijk'      => 20,
                    'vakantiedagenBovenwettelijk' => 13,
                ],
                'leaveTermsOverrideReason'     => 'Arbeidsvoorwaardelijke afspraak: 33 dagen',
            ]
        );

        $this->assertSame(EmploymentTermsResolver::SOURCE_CONTRACT, $terms['source']);
        $this->assertSame(13.0, $terms['days']['vakantiedagenBovenwettelijk']);

    }//end testLeaveOverrideWinsAndCarriesItsReason()


    /**
     * An unexplained leave override is refused, same as overtime.
     *
     * @return void
     */
    public function testUnexplainedLeaveOverrideIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver->resolveLeaveEntitlementDays(
            [
                'cao'                          => 'cao-gemeenten',
                'leaveEntitlementOverrideDays' => ['vakantiedagenWettelijk' => 20],
            ]
        );

    }//end testUnexplainedLeaveOverrideIsRefused()


    /**
     * A BELOW-statutory override is returned as given, not silently corrected
     * up to the wettelijk minimum. Correcting it here would hide a violation
     * that `nl-verlof-wettelijk-minimum` exists to raise.
     *
     * @return void
     */
    public function testBelowStatutoryLeaveOverrideIsNotSilentlyCorrected(): void
    {
        $terms = $this->resolver->resolveLeaveEntitlementDays(
            [
                'cao'                          => 'cao-gemeenten',
                'leaveEntitlementOverrideDays' => ['vakantiedagenWettelijk' => 5],
                'leaveTermsOverrideReason'     => 'Foutieve invoer die zichtbaar moet blijven',
            ]
        );

        $this->assertSame(5.0, $terms['days']['vakantiedagenWettelijk']);

    }//end testBelowStatutoryLeaveOverrideIsNotSilentlyCorrected()


    /**
     * Every shipped CAO file parses and either carries a well-formed overtime
     * leaf or omits it — a malformed one would resolve to null and look exactly
     * like an honest placeholder.
     *
     * @return void
     */
    public function testEveryShippedCaoHasAWellFormedOrAbsentOvertimeLeaf(): void
    {
        // availableCaos() is keyed BY id, with a metadata row as the value.
        $caos = array_keys(CaoRegistry::availableCaos());
        $this->assertNotEmpty($caos);

        foreach ($caos as $caoId) {
            $cao = CaoRegistry::get($caoId);
            $this->assertNotNull($cao, $caoId.' must load');
            if (array_key_exists('overtime', $cao) === false) {
                continue;
            }

            $leaf = $cao['overtime'];
            $this->assertArrayHasKey('value', $leaf, $caoId.' overtime leaf needs a value');
            $this->assertArrayHasKey('source', $leaf, $caoId.' overtime leaf needs a source');
            // The corpus rule: an unconfirmed value must never be silent.
            if (($leaf['verified'] ?? false) !== true || ($leaf['placeholder'] ?? false) === true) {
                $this->assertNotEmpty(
                    ($leaf['checkAgainst'] ?? ''),
                    $caoId.' has an unverified overtime leaf and must name what to confirm it against'
                );
            }
        }//end foreach

    }//end testEveryShippedCaoHasAWellFormedOrAbsentOvertimeLeaf()
}//end class
