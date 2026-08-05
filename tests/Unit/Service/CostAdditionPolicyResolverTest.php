<?php

/**
 * CostAdditionPolicyResolver tests
 *
 * Pins scope precedence (contract > cla > organisation, PER KEY), effective
 * dating, and the two cases where matching loosely would be worse than not
 * matching at all: an empty CLA id and an equally-specific duplicate.
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

use OCA\Hrmq\Service\CostAdditionPolicyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CostAdditionPolicyResolver.
 */
class CostAdditionPolicyResolverTest extends TestCase
{

    /**
     * Resolver under test.
     *
     * @var CostAdditionPolicyResolver
     */
    private CostAdditionPolicyResolver $resolver;

    /**
     * A contract under the ICK collective labour agreement.
     *
     * @var array<string, mixed>
     */
    private const CONTRACT = ['cao' => 'cao-ick'];

    /**
     * The contract's id.
     *
     * @var string
     */
    private const CONTRACT_ID = 'contract-1';

    /**
     * Costing date.
     *
     * @var string
     */
    private const ON_DATE = '2026-07-15';


    /**
     * Build the resolver.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CostAdditionPolicyResolver();

    }//end setUp()


    /**
     * Index the result by key for readable assertions.
     *
     * @param array<int, array<string, mixed>> $additions Resolved additions.
     *
     * @return array<string, array<string, mixed>>
     */
    private function byKey(array $additions): array
    {
        $out = [];
        foreach ($additions as $addition) {
            $out[$addition['key']] = $addition;
        }

        return $out;

    }//end byKey()


    /**
     * The ICK example: everyone under that CLA carries EUR 25/h for rent and
     * management fees.
     *
     * @return void
     */
    public function testClaScopedPolicyAppliesToEveryContractUnderThatCla(): void
    {
        $additions = $this->resolver->resolveFor(
            policies: [
                [
                    'key'          => 'overhead',
                    'scope'        => 'cla',
                    'claId'        => 'cao-ick',
                    'centsPerHour' => 2500,
                    'basis'        => 'Huur en managementfee, ICK-populatie',
                    'source'       => 'manual',
                ],
            ],
            contract: self::CONTRACT,
            contractId: self::CONTRACT_ID,
            onDate: self::ON_DATE
        );

        $byKey = $this->byKey($additions);
        $this->assertArrayHasKey('overhead', $byKey);
        $this->assertSame(2500, $byKey['overhead']['centsPerHour']);

    }//end testClaScopedPolicyAppliesToEveryContractUnderThatCla()


    /**
     * A CLA-scoped policy does NOT reach a contract under a different CLA.
     *
     * @return void
     */
    public function testClaScopedPolicyDoesNotReachAnotherCla(): void
    {
        $additions = $this->resolver->resolveFor(
            policies: [
                [
                    'key'          => 'overhead',
                    'scope'        => 'cla',
                    'claId'        => 'cao-metaal-techniek',
                    'centsPerHour' => 2500,
                    'basis'        => 'Andere cao',
                ],
            ],
            contract: self::CONTRACT,
            contractId: self::CONTRACT_ID,
            onDate: self::ON_DATE
        );

        $this->assertSame([], $additions);

    }//end testClaScopedPolicyDoesNotReachAnotherCla()


    /**
     * Precedence is PER KEY: a contract-level equipment figure replaces the
     * CLA one for equipment only, leaving the CLA overhead standing. Cost
     * additions are independent line items, so mixing their sources is the
     * normal case — unlike employment terms, which come from one agreement and
     * are taken whole.
     *
     * @return void
     */
    public function testMoreSpecificScopeWinsPerKeyWithoutDisturbingOthers(): void
    {
        $additions = $this->resolver->resolveFor(
            policies: [
                ['key' => 'overhead', 'scope' => 'organisation', 'centsPerHour' => 1000, 'basis' => 'Org-breed'],
                ['key' => 'overhead', 'scope' => 'cla', 'claId' => 'cao-ick', 'centsPerHour' => 2500, 'basis' => 'ICK'],
                ['key' => 'equipment', 'scope' => 'cla', 'claId' => 'cao-ick', 'centsPerHour' => 300, 'basis' => 'ICK laptop'],
                [
                    'key'          => 'equipment',
                    'scope'        => 'contract',
                    'contractId'   => self::CONTRACT_ID,
                    'centsPerHour' => 800,
                    'basis'        => 'Eigen werkplek',
                ],
            ],
            contract: self::CONTRACT,
            contractId: self::CONTRACT_ID,
            onDate: self::ON_DATE
        );

        $byKey = $this->byKey($additions);
        $this->assertCount(2, $additions);
        $this->assertSame(2500, $byKey['overhead']['centsPerHour'], 'the CLA overhead beats the organisation one');
        $this->assertSame(800, $byKey['equipment']['centsPerHour'], 'the contract equipment beats the CLA one');

    }//end testMoreSpecificScopeWinsPerKeyWithoutDisturbingOthers()


    /**
     * A policy outside its effective window does not apply. Overheads are
     * re-budgeted; costing last year's hours at this year's overhead would
     * silently restate history.
     *
     * @return void
     */
    public function testPolicyOutsideItsEffectiveWindowDoesNotApply(): void
    {
        $policies = [
            [
                'key'           => 'overhead',
                'scope'         => 'organisation',
                'centsPerHour'  => 2500,
                'basis'         => 'FY2026',
                'effectiveFrom' => '2026-01-01',
                'effectiveTo'   => '2026-12-31',
            ],
        ];

        $this->assertCount(
            1,
            $this->resolver->resolveFor($policies, self::CONTRACT, self::CONTRACT_ID, '2026-07-15')
        );
        $this->assertSame(
            [],
            $this->resolver->resolveFor($policies, self::CONTRACT, self::CONTRACT_ID, '2025-12-31'),
            'a date before effectiveFrom must not pick up the policy'
        );
        $this->assertSame(
            [],
            $this->resolver->resolveFor($policies, self::CONTRACT, self::CONTRACT_ID, '2027-01-01'),
            'a date after effectiveTo must not pick up the policy'
        );

    }//end testPolicyOutsideItsEffectiveWindowDoesNotApply()


    /**
     * Both window bounds are inclusive — a policy is in force on its own
     * effectiveFrom and effectiveTo.
     *
     * @return void
     */
    public function testEffectiveWindowBoundsAreInclusive(): void
    {
        $policies = [
            [
                'key'           => 'overhead',
                'scope'         => 'organisation',
                'centsPerHour'  => 2500,
                'basis'         => 'FY2026',
                'effectiveFrom' => '2026-01-01',
                'effectiveTo'   => '2026-12-31',
            ],
        ];

        $this->assertCount(1, $this->resolver->resolveFor($policies, self::CONTRACT, self::CONTRACT_ID, '2026-01-01'));
        $this->assertCount(1, $this->resolver->resolveFor($policies, self::CONTRACT, self::CONTRACT_ID, '2026-12-31'));

    }//end testEffectiveWindowBoundsAreInclusive()


    /**
     * A policy with no window has always applied.
     *
     * @return void
     */
    public function testPolicyWithNoWindowAlwaysApplies(): void
    {
        $additions = $this->resolver->resolveFor(
            policies: [['key' => 'overhead', 'scope' => 'organisation', 'centsPerHour' => 2500, 'basis' => 'Altijd']],
            contract: self::CONTRACT,
            contractId: self::CONTRACT_ID,
            onDate: '1999-01-01'
        );

        $this->assertCount(1, $additions);

    }//end testPolicyWithNoWindowAlwaysApplies()


    /**
     * A CLA-scoped policy with an EMPTY claId must match nothing. Matching
     * loosely here would apply an "unscoped" policy to every contract that
     * names no CLA — the opposite of scoping, and silent.
     *
     * @return void
     */
    public function testClaScopedPolicyWithNoClaIdMatchesNothing(): void
    {
        $additions = $this->resolver->resolveFor(
            policies: [['key' => 'overhead', 'scope' => 'cla', 'claId' => '', 'centsPerHour' => 2500, 'basis' => 'Leeg']],
            contract: ['cao' => ''],
            contractId: self::CONTRACT_ID,
            onDate: self::ON_DATE
        );

        $this->assertSame([], $additions);

    }//end testClaScopedPolicyWithNoClaIdMatchesNothing()


    /**
     * An equally-specific duplicate does NOT silently displace the first.
     * Two organisation-wide overhead policies in force on the same date is a
     * data problem; resolving it by array order would hide it behind a
     * plausible number.
     *
     * @return void
     */
    public function testEquallySpecificDuplicateDoesNotDisplaceTheFirst(): void
    {
        $additions = $this->resolver->resolveFor(
            policies: [
                ['key' => 'overhead', 'scope' => 'organisation', 'centsPerHour' => 1000, 'basis' => 'Eerste'],
                ['key' => 'overhead', 'scope' => 'organisation', 'centsPerHour' => 9999, 'basis' => 'Tweede'],
            ],
            contract: self::CONTRACT,
            contractId: self::CONTRACT_ID,
            onDate: self::ON_DATE
        );

        $byKey = $this->byKey($additions);
        $this->assertCount(1, $additions);
        $this->assertSame(1000, $byKey['overhead']['centsPerHour']);

    }//end testEquallySpecificDuplicateDoesNotDisplaceTheFirst()


    /**
     * An unknown scope never wins, and never applies on its own.
     *
     * @return void
     */
    public function testUnknownScopeIsIgnored(): void
    {
        $additions = $this->resolver->resolveFor(
            policies: [['key' => 'overhead', 'scope' => 'galaxy', 'centsPerHour' => 2500, 'basis' => 'Onzin']],
            contract: self::CONTRACT,
            contractId: self::CONTRACT_ID,
            onDate: self::ON_DATE
        );

        $this->assertSame([], $additions);

    }//end testUnknownScopeIsIgnored()


    /**
     * A percentage policy passes its percentage through, not a cents figure —
     * the cost service refuses an addition stating both.
     *
     * @return void
     */
    public function testPercentagePolicyPassesThroughAsAPercentage(): void
    {
        $additions = $this->resolver->resolveFor(
            policies: [
                [
                    'key'              => 'toeslag',
                    'scope'            => 'organisation',
                    'percentageOfWage' => 12.5,
                    'basis'            => 'Percentage-vorm',
                ],
            ],
            contract: self::CONTRACT,
            contractId: self::CONTRACT_ID,
            onDate: self::ON_DATE
        );

        $this->assertSame(12.5, $additions[0]['percentageOfWage']);
        $this->assertArrayNotHasKey('centsPerHour', $additions[0]);

    }//end testPercentagePolicyPassesThroughAsAPercentage()


    /**
     * A policy stating both forms passes only the fixed one on, so a
     * policy-level data error cannot surface as a rate-level exception far
     * from its cause.
     *
     * @return void
     */
    public function testPolicyStatingBothFormsPassesOnlyTheFixedOne(): void
    {
        $additions = $this->resolver->resolveFor(
            policies: [
                [
                    'key'              => 'overhead',
                    'scope'            => 'organisation',
                    'centsPerHour'     => 2500,
                    'percentageOfWage' => 12.5,
                    'basis'            => 'Beide vormen',
                ],
            ],
            contract: self::CONTRACT,
            contractId: self::CONTRACT_ID,
            onDate: self::ON_DATE
        );

        $this->assertSame(2500, $additions[0]['centsPerHour']);
        $this->assertArrayNotHasKey('percentageOfWage', $additions[0]);

    }//end testPolicyStatingBothFormsPassesOnlyTheFixedOne()
}//end class
