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
        $this->assertSame('2026-07.14', CaoRegistry::VERSION);

    }//end testVersionConstantIsBumped()


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
