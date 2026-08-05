<?php

/**
 * Unit tests for the CAO corpus loader (CaoRegistry) and the cao/*.json corpus.
 *
 * Covers cao-library REQ-CAO-001 / REQ-CAO-005: the corpus loads and merges the
 * per-CAO files, `availableCaos()` lists the three MVP CAOs with their
 * version/effectiveDate, `get()` resolves the full record (null when unknown),
 * and the two resolvers return integer values for the verified `cao-generiek`
 * anchor but `null` for the placeholder/unverified `cao-metaal-techniek` /
 * `cao-horeca` figures (the design.md D5 advisory lever). Also pins the static
 * leaf-shape discipline of every corpus file (cao/SCHEMA.md).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards
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
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-001
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards;

use OCA\Hrmq\Standards\CaoRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CaoRegistry and the cao/*.json corpus.
 *
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-001
 */
class CaoRegistryTest extends TestCase
{


    /**
     * The corpus is memoised statically; reset before each test so a resolver
     * call in one test cannot mask a load bug in another.
     *
     * @return void
     */
    protected function setUp(): void
    {
        CaoRegistry::reset();

    }//end setUp()


    /**
     * @return void
     */
    protected function tearDown(): void
    {
        CaoRegistry::reset();

    }//end tearDown()


    /**
     * availableCaos() lists the three MVP CAOs with their version/effectiveDate.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-001
     */
    public function testAvailableCaosListsTheThreeSeedCaos(): void
    {
        $available = CaoRegistry::availableCaos();

        $this->assertArrayHasKey('cao-generiek', $available);
        $this->assertArrayHasKey('cao-metaal-techniek', $available);
        $this->assertArrayHasKey('cao-horeca', $available);

        foreach (['cao-generiek', 'cao-metaal-techniek', 'cao-horeca'] as $id) {
            $this->assertNotSame('', trim((string) $available[$id]['name']), $id.' must carry a name.');
            $this->assertNotSame('', trim((string) $available[$id]['sector']), $id.' must carry a sector.');
            $this->assertNotSame('', trim((string) $available[$id]['version']), $id.' must carry a version.');
            $this->assertNotSame('', trim((string) $available[$id]['effectiveDate']), $id.' must carry an effectiveDate.');
        }

    }//end testAvailableCaosListsTheThreeSeedCaos()


    /**
     * get() returns the full record for a known CAO, null for an unknown one.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-001
     */
    public function testGetResolvesKnownCaoAndNullForUnknown(): void
    {
        $cao = CaoRegistry::get('cao-generiek');

        $this->assertIsArray($cao);
        $this->assertSame('cao-generiek', $cao['id']);
        $this->assertArrayHasKey('payScales', $cao);
        $this->assertArrayHasKey('leaveEntitlement', $cao);

        $this->assertNull(CaoRegistry::get('cao-does-not-exist'));

    }//end testGetResolvesKnownCaoAndNullForUnknown()


    /**
     * The verified cao-generiek anchor resolves a real integer-cents minimum.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-001
     */
    public function testMinMaandloonCentsResolvesVerifiedAnchor(): void
    {
        $this->assertSame(229440, CaoRegistry::minMaandloonCents('cao-generiek', 'generiek'));

    }//end testMinMaandloonCentsResolvesVerifiedAnchor()


    /**
     * A placeholder/unverified payScales leaf resolves to null (advisory), even
     * for a scale key that IS present in the leaf's value map — the whole leaf
     * is unverified, so no per-scale figure is enforceable (design.md D5).
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-001
     */
    public function testMinMaandloonCentsReturnsNullForPlaceholderScale(): void
    {
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-metaal-techniek', 'B'));
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-horeca', 'II'));

    }//end testMinMaandloonCentsReturnsNullForPlaceholderScale()


    /**
     * Unknown CAO, or an unknown scale on a verified CAO, both resolve to null.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-001
     */
    public function testMinMaandloonCentsReturnsNullForUnknownCaoOrScale(): void
    {
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-does-not-exist', 'A'));
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-generiek', 'no-such-scale'));

    }//end testMinMaandloonCentsReturnsNullForUnknownCaoOrScale()


    /**
     * The verified cao-generiek leave entitlement prorates to hours: 20 days x
     * (40/5 = 8 h/day) = 160 h at a 40-hour week; 20 x (32/5 = 6.4) = 128 h at
     * a 32-hour week.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
     */
    public function testMinLeaveHoursProratesVerifiedEntitlement(): void
    {
        $this->assertSame(160, CaoRegistry::minLeaveHours('cao-generiek', 40.0));
        $this->assertSame(128, CaoRegistry::minLeaveHours('cao-generiek', 32.0));

    }//end testMinLeaveHoursProratesVerifiedEntitlement()


    /**
     * A placeholder/unverified leaveEntitlement leaf, an unknown CAO, and a
     * non-positive contract week all resolve to null.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
     */
    public function testMinLeaveHoursReturnsNullForPlaceholderUnknownOrZeroWeek(): void
    {
        $this->assertNull(CaoRegistry::minLeaveHours('cao-metaal-techniek', 38.0));
        $this->assertNull(CaoRegistry::minLeaveHours('cao-does-not-exist', 40.0));
        $this->assertNull(CaoRegistry::minLeaveHours('cao-generiek', 0.0));

    }//end testMinLeaveHoursReturnsNullForPlaceholderUnknownOrZeroWeek()


    /**
     * VERSION is bumped to the cao-library corpus stamp.
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-001
     */
    public function testVersionConstantIsBumped(): void
    {
        // Bumped 2026-08-05 for the `overtime` leaf added to every corpus file.
        // SCHEMA.md's re-issue discipline requires the bump on any cao/*.json
        // change; this literal is the tripwire that makes it deliberate.
        $this->assertSame('2026-08.19', CaoRegistry::VERSION);

    }//end testVersionConstantIsBumped()


    /**
     * availableCaos() lists all nine CAOs (three existing + six sector CAOs
     * added by cao-sector-datasets) and get() resolves the full record for
     * each of the six new ids — the loader is generic over however many
     * cao/*.json files exist, no per-CAO wiring (cao-sector-datasets
     * REQ-CAOS-001).
     *
     * @return void
     *
     * @spec openspec/changes/cao-sector-datasets/specs/cao-sector-datasets/spec.md#REQ-CAOS-001
     */
    public function testAvailableCaosListsAllNineCaosIncludingTheSixSectorCaos(): void
    {
        $available = CaoRegistry::availableCaos();

        $sixNew = [
            'cao-rijk',
            'cao-gemeenten',
            'cao-onderwijs-po',
            'cao-onderwijs-vo',
            'cao-ziekenhuizen',
            'cao-zorg-vvt',
        ];

        // 11 since 2026-08-05: the ten real CLAs plus `cao-voorbeeld`, the
        // fictional example that ships VERIFIED leaves so the CLA machinery
        // can be demonstrated and tested without a transcribed CAO text.
        $this->assertCount(
            11,
            $available,
            'Expected three existing CAOs plus six new sector CAOs plus cao-abu plus the cao-voorbeeld example.'
        );
        $this->assertArrayHasKey('cao-voorbeeld', $available, 'the example CLA must be listed by availableCaos().');
        $this->assertArrayHasKey('cao-abu', $available, 'cao-abu (uitzend-flexpool) must be listed by availableCaos().');

        foreach ($sixNew as $id) {
            $this->assertArrayHasKey($id, $available, $id.' must be listed by availableCaos().');
            $this->assertNotSame('', trim((string) $available[$id]['name']), $id.' must carry a name.');
            $this->assertNotSame('', trim((string) $available[$id]['sector']), $id.' must carry a sector.');

            $cao = CaoRegistry::get($id);
            $this->assertIsArray($cao, $id.' must resolve through get().');
        }

    }//end testAvailableCaosListsAllNineCaosIncludingTheSixSectorCaos()


    /**
     * Every placeholder leaf on the six new sector CAOs resolves null through
     * both resolvers — the advisory lever holds for every sector-naming
     * convention (numeric BBRA/VNG schalen, onderwijs letter-schalen,
     * FWG-functiegroep ids), never a wrong number (cao-sector-datasets
     * REQ-CAOS-002).
     *
     * @return void
     *
     * @spec openspec/changes/cao-sector-datasets/specs/cao-sector-datasets/spec.md#REQ-CAOS-002
     */
    public function testSixNewCaosPlaceholderLeavesResolveNull(): void
    {
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-rijk', '11'));
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-rijk', '15a'));
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-gemeenten', '10'));
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-onderwijs-po', 'L11'));
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-onderwijs-po', 'OOP-6'));
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-onderwijs-po', 'DIR-A'));
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-onderwijs-vo', 'LB'));
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-ziekenhuizen', 'FWG-40'));
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-zorg-vvt', 'FWG-40'));

        $this->assertNull(CaoRegistry::minLeaveHours('cao-rijk', 36.0));
        $this->assertNull(CaoRegistry::minLeaveHours('cao-gemeenten', 36.0));
        $this->assertNull(CaoRegistry::minLeaveHours('cao-onderwijs-po', 40.0));
        $this->assertNull(CaoRegistry::minLeaveHours('cao-onderwijs-vo', 40.0));
        $this->assertNull(CaoRegistry::minLeaveHours('cao-ziekenhuizen', 36.0));
        $this->assertNull(CaoRegistry::minLeaveHours('cao-zorg-vvt', 36.0));

    }//end testSixNewCaosPlaceholderLeavesResolveNull()


    /**
     * cao-rijk's two verified leaves (allowances/IKB, workingTime) are
     * display-only facts, never read by minMaandloonCents/minLeaveHours
     * (design.md D4/Context) — marking them verified changes nothing about
     * which checks fire. payScales/leaveEntitlement stay placeholder, so both
     * enforcement resolvers still return null for cao-rijk even though the
     * CAO itself carries two verified: true leaves (cao-sector-datasets
     * REQ-CAOS-001 tasks.md #12).
     *
     * @return void
     *
     * @spec openspec/changes/cao-sector-datasets/specs/cao-sector-datasets/spec.md#REQ-CAOS-001
     */
    public function testCaoRijkVerifiedLeavesAreDisplayOnlyNotEnforcementCritical(): void
    {
        $cao = CaoRegistry::get('cao-rijk');
        $this->assertIsArray($cao);

        $this->assertTrue($cao['allowances']['verified']);
        $this->assertTrue($cao['workingTime']['verified']);
        $this->assertFalse($cao['payScales']['verified']);
        $this->assertFalse($cao['leaveEntitlement']['verified']);

        $this->assertNull(CaoRegistry::minMaandloonCents('cao-rijk', '11'));
        $this->assertNull(CaoRegistry::minLeaveHours('cao-rijk', 36.0));

    }//end testCaoRijkVerifiedLeavesAreDisplayOnlyNotEnforcementCritical()


    /**
     * A sector-specific scale identifier (FWG-functiegroep id) resolves like
     * any other schaal key — CaoRegistry performs no schaal-format validation
     * (cao-sector-datasets REQ-CAOS-001, D2).
     *
     * @return void
     *
     * @spec openspec/changes/cao-sector-datasets/specs/cao-sector-datasets/spec.md#REQ-CAOS-001
     */
    public function testFwgFunctiegroepScaleIdentifierResolvesLikeAnyOtherSchaal(): void
    {
        $cao = CaoRegistry::get('cao-ziekenhuizen');
        $this->assertIsArray($cao);
        $this->assertArrayHasKey('FWG-40', $cao['payScales']['value']);

        // Placeholder -- resolves null, but the lookup path itself succeeds
        // (no format validation), proving the resolver is schaal-format-agnostic.
        $this->assertNull(CaoRegistry::minMaandloonCents('cao-ziekenhuizen', 'FWG-40'));

    }//end testFwgFunctiegroepScaleIdentifierResolvesLikeAnyOtherSchaal()


    /**
     * Every corpus file carries the required top-level keys and four
     * `{value, source, verified}` leaf groups; every unverified/placeholder
     * leaf carries a `checkAgainst` note (cao/SCHEMA.md — an unconfirmed value
     * is never silent).
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-001
     */
    public function testEveryCorpusFileIsWellFormedAndSourced(): void
    {
        $files = (glob(__DIR__.'/../../../lib/Standards/cao/*.json') ?: []);
        $this->assertNotEmpty($files, 'Expected at least one cao/*.json corpus file.');

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($decoded, basename($file).' must decode to an array.');
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), basename($file).' must be valid JSON.');

            foreach (['id', 'name', 'sector', 'version', 'effectiveDate', 'payScales', 'allowances', 'leaveEntitlement', 'workingTime'] as $key) {
                $this->assertArrayHasKey($key, $decoded, basename($file)." is missing top-level '{$key}'.");
            }

            foreach (['payScales', 'allowances', 'leaveEntitlement', 'workingTime'] as $group) {
                $leaf = $decoded[$group];
                $this->assertIsArray($leaf, basename($file)." leaf '{$group}' must be an object.");
                $this->assertArrayHasKey('value', $leaf, basename($file)." leaf '{$group}' is missing 'value'.");
                $this->assertArrayHasKey('source', $leaf, basename($file)." leaf '{$group}' is missing 'source'.");
                $this->assertNotSame('', trim((string) $leaf['source']), basename($file)." leaf '{$group}' has an empty 'source'.");
                $this->assertArrayHasKey('verified', $leaf, basename($file)." leaf '{$group}' is missing 'verified'.");
                $this->assertIsBool($leaf['verified'], basename($file)." leaf '{$group}' 'verified' must be a boolean.");

                if ($leaf['verified'] === false || ($leaf['placeholder'] ?? false) === true) {
                    $this->assertArrayHasKey(
                        'checkAgainst',
                        $leaf,
                        basename($file)." leaf '{$group}' is unverified/placeholder and must carry a 'checkAgainst' note."
                    );
                    $this->assertNotSame('', trim((string) $leaf['checkAgainst']), basename($file)." leaf '{$group}' has an empty 'checkAgainst'.");
                }
            }//end foreach
        }//end foreach

    }//end testEveryCorpusFileIsWellFormedAndSourced()


    /**
     * cao-generiek is the fully-verified anchor: no leaf is placeholder and
     * both enforceable leaves (payScales, leaveEntitlement) are verified — the
     * end-to-end proof that the below-CAO checks CAN fire (design.md Seed Data).
     *
     * @return void
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-001
     */
    public function testCaoGeneriekIsTheVerifiedAnchor(): void
    {
        $cao = CaoRegistry::get('cao-generiek');
        $this->assertIsArray($cao);

        $this->assertTrue($cao['payScales']['verified']);
        $this->assertNotSame(true, ($cao['payScales']['placeholder'] ?? false));
        $this->assertTrue($cao['leaveEntitlement']['verified']);
        $this->assertNotSame(true, ($cao['leaveEntitlement']['placeholder'] ?? false));

    }//end testCaoGeneriekIsTheVerifiedAnchor()


}//end class
