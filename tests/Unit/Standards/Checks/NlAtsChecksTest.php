<?php

/**
 * Unit tests for the NL ATS (recruitment) retention checks.
 *
 * Pins the two recruiting-ats-basic predicates on Application: retention-clock
 * derivation (nl-ats-retentie-derivatie — correct 28-day / 365-day opt-in
 * offsets read from the corpus rule's parameters, null/wrong expiry flagged,
 * vacuous pass on every non-afgewezen status) and expiry detection
 * (nl-ats-retentie-verlopen — a past retentionExpiryDate is a violation,
 * a future one or a null one is not).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards\Checks
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

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlAtsChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlAtsChecks.
 *
 * @spec openspec/changes/recruiting-ats-basic/specs/recruiting-applications/spec.md
 */
class NlAtsChecksTest extends TestCase
{


    /**
     * The registered Application predicates, keyed by rule id.
     *
     * @var array<string, callable>
     */
    private array $checks;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->checks = NlAtsChecks::checks()['Application'];

    }//end setUp()


    /**
     * A minimal Application fixture; each test overrides the fields it exercises.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function application(array $overrides=[]): array
    {
        return array_merge(
            [
                'vacancyId'           => 'vacancy-vue-developer',
                'candidateName'       => 'Jan Voorbeeld',
                'email'               => 'voorbeeld@example.org',
                'status'              => 'nieuw',
                'rejectedDate'        => null,
                'talentPoolOptIn'     => false,
                'retentionExpiryDate' => null,
            ],
            $overrides
        );

    }//end application()


    /**
     * @return void
     */
    public function testDerivatieCorrectFourWeekOffsetPasses(): void
    {
        $application = $this->application(
            [
                'status'              => 'afgewezen',
                'rejectedDate'        => '2026-06-01',
                'talentPoolOptIn'     => false,
                'retentionExpiryDate' => '2026-06-29',
            ]
        );

        $this->assertTrue(($this->checks['nl-ats-retentie-derivatie'])($application));

    }//end testDerivatieCorrectFourWeekOffsetPasses()


    /**
     * @return void
     */
    public function testDerivatieCorrectOneYearOptInOffsetPasses(): void
    {
        $application = $this->application(
            [
                'status'              => 'afgewezen',
                'rejectedDate'        => '2026-06-01',
                'talentPoolOptIn'     => true,
                'retentionExpiryDate' => '2027-06-01',
            ]
        );

        $this->assertTrue(($this->checks['nl-ats-retentie-derivatie'])($application));

    }//end testDerivatieCorrectOneYearOptInOffsetPasses()


    /**
     * @return void
     */
    public function testDerivatieWrongOffsetIsAViolation(): void
    {
        $application = $this->application(
            [
                'status'              => 'afgewezen',
                'rejectedDate'        => '2026-06-01',
                'talentPoolOptIn'     => false,
                'retentionExpiryDate' => '2026-07-15',
            ]
        );

        $this->assertFalse(($this->checks['nl-ats-retentie-derivatie'])($application));

    }//end testDerivatieWrongOffsetIsAViolation()


    /**
     * @return void
     */
    public function testDerivatieNullRejectedDateOnAfgewezenIsAViolation(): void
    {
        $application = $this->application(
            [
                'status'              => 'afgewezen',
                'rejectedDate'        => null,
                'retentionExpiryDate' => '2026-06-29',
            ]
        );

        $this->assertFalse(($this->checks['nl-ats-retentie-derivatie'])($application));

    }//end testDerivatieNullRejectedDateOnAfgewezenIsAViolation()


    /**
     * @return void
     */
    public function testDerivatieNullRetentionExpiryDateOnAfgewezenIsAViolation(): void
    {
        $application = $this->application(
            [
                'status'              => 'afgewezen',
                'rejectedDate'        => '2026-06-01',
                'retentionExpiryDate' => null,
            ]
        );

        $this->assertFalse(($this->checks['nl-ats-retentie-derivatie'])($application));

    }//end testDerivatieNullRetentionExpiryDateOnAfgewezenIsAViolation()


    /**
     * @return void
     */
    public function testDerivatieVacuousPassOnEveryActiveStatus(): void
    {
        foreach (['nieuw', 'screening', 'gesprek', 'aanbod', 'aangenomen'] as $status) {
            $application = $this->application(['status' => $status]);
            $this->assertTrue(
                ($this->checks['nl-ats-retentie-derivatie'])($application),
                sprintf('status %s should pass vacuously', $status)
            );
        }

    }//end testDerivatieVacuousPassOnEveryActiveStatus()


    /**
     * @return void
     */
    public function testVerlopenPastRetentionExpiryDateIsAViolation(): void
    {
        // The seeded application-voorbeeld-afgewezen scenario: expiry 2026-06-29,
        // audited well after that date.
        $application = $this->application(
            [
                'status'              => 'afgewezen',
                'rejectedDate'        => '2026-06-01',
                'retentionExpiryDate' => '2026-06-29',
            ]
        );

        $this->assertFalse(($this->checks['nl-ats-retentie-verlopen'])($application));

    }//end testVerlopenPastRetentionExpiryDateIsAViolation()


    /**
     * @return void
     */
    public function testVerlopenFutureRetentionExpiryDateIsNotAViolation(): void
    {
        $application = $this->application(
            [
                'status'              => 'afgewezen',
                'rejectedDate'        => date('Y-m-d'),
                'retentionExpiryDate' => date('Y-m-d', strtotime('+30 days')),
            ]
        );

        $this->assertTrue(($this->checks['nl-ats-retentie-verlopen'])($application));

    }//end testVerlopenFutureRetentionExpiryDateIsNotAViolation()


    /**
     * @return void
     */
    public function testVerlopenNullRetentionExpiryDateIsNotAViolation(): void
    {
        $application = $this->application(['status' => 'nieuw', 'retentionExpiryDate' => null]);

        $this->assertTrue(($this->checks['nl-ats-retentie-verlopen'])($application));

    }//end testVerlopenNullRetentionExpiryDateIsNotAViolation()


    /**
     * @return void
     */
    public function testActiveApplicationPassesBothChecksVacuously(): void
    {
        // The seeded application-voorbeeld-nieuw scenario.
        $application = $this->application(['status' => 'nieuw']);

        foreach ($this->checks as $ruleId => $predicate) {
            $this->assertTrue((bool) $predicate($application), sprintf('nieuw application violates %s', $ruleId));
        }

    }//end testActiveApplicationPassesBothChecksVacuously()


    /**
     * @return void
     */
    public function testAangenomenApplicationPassesBothChecksVacuously(): void
    {
        // The seeded application-voorbeeld-aangenomen scenario — no retention
        // clock on a hire in the MVP (design D4).
        $application = $this->application(['status' => 'aangenomen']);

        foreach ($this->checks as $ruleId => $predicate) {
            $this->assertTrue((bool) $predicate($application), sprintf('aangenomen application violates %s', $ruleId));
        }

    }//end testAangenomenApplicationPassesBothChecksVacuously()


    /**
     * @return void
     */
    public function testBothRuleIdsAreRegistered(): void
    {
        $this->assertArrayHasKey('nl-ats-retentie-derivatie', $this->checks);
        $this->assertArrayHasKey('nl-ats-retentie-verlopen', $this->checks);

    }//end testBothRuleIdsAreRegistered()


}//end class
