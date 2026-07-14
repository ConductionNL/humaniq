<?php

/**
 * Unit tests for the versioned tax-year table corpus (lib/Standards/tables/).
 *
 * Pure static-data sanity checks per lib/Standards/tables/SCHEMA.md and
 * design.md D3 of payroll-core-schema: the file parses, carries the required
 * top-level keys, and every parameter leaf carries `value` + `source` +
 * `verified`. No engine/calculation logic is exercised here — that lands with
 * payroll-core-engine.
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
 * @spec openspec/changes/payroll-core-schema/specs/payroll-core-schema/spec.md#REQ-PCS-001
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the nl-2026 tax-year table file.
 *
 * @spec openspec/changes/payroll-core-schema/specs/payroll-core-schema/spec.md#REQ-PCS-001
 */
class TablesFileTest extends TestCase
{


    /**
     * The decoded nl-2026.json content.
     *
     * @var array<string, mixed>
     */
    private array $table;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        $path = __DIR__.'/../../../lib/Standards/tables/nl-2026.json';
        $this->assertFileExists($path, 'lib/Standards/tables/nl-2026.json must exist.');

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'nl-2026.json must decode to an array.');
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'nl-2026.json must be valid JSON: '.json_last_error_msg());

        $this->table = $decoded;

    }//end setUp()


    /**
     * @return void
     */
    public function testFileParsesAsValidJson(): void
    {
        $this->assertIsArray($this->table);

    }//end testFileParsesAsValidJson()


    /**
     * @return void
     */
    public function testTopLevelKeysArePresent(): void
    {
        $this->assertSame('nl-2026', ($this->table['id'] ?? null));
        $this->assertSame('NL', ($this->table['jurisdiction'] ?? null));
        $this->assertSame(2026, ($this->table['year'] ?? null));
        $this->assertArrayHasKey('issued', $this->table);
        $this->assertArrayHasKey('basedOn', $this->table);
        $this->assertIsArray($this->table['basedOn']);
        $this->assertNotEmpty($this->table['basedOn'], 'basedOn must list at least one primary source.');
        $this->assertArrayHasKey('parameters', $this->table);
        $this->assertIsArray($this->table['parameters']);

    }//end testTopLevelKeysArePresent()


    /**
     * Every basedOn entry names a document and a URL.
     *
     * @return void
     */
    public function testBasedOnEntriesAreWellFormed(): void
    {
        foreach ($this->table['basedOn'] as $entry) {
            $this->assertIsArray($entry);
            $this->assertArrayHasKey('doc', $entry);
            $this->assertArrayHasKey('url', $entry);
            $this->assertNotSame('', trim((string) $entry['doc']));
            $this->assertNotSame('', trim((string) $entry['url']));
        }

    }//end testBasedOnEntriesAreWellFormed()


    /**
     * Every parameter leaf (recursively discovered) carries `value`, `source`
     * and `verified` — the normative leaf shape per design.md D2 / SCHEMA.md.
     * A leaf whose `value` is itself an object with per-variant sub-keys
     * (e.g. `{belowAow, aowAge}`) is still one leaf: the leaf boundary is the
     * presence of the `source`/`verified` sibling keys, not the shape of
     * `value`.
     *
     * @return void
     */
    public function testEveryParameterLeafCarriesValueSourceAndVerified(): void
    {
        $leaves = $this->collectLeaves($this->table['parameters']);

        $this->assertNotEmpty($leaves, 'Expected at least one parameter leaf in nl-2026.json.');

        foreach ($leaves as $path => $leaf) {
            $this->assertArrayHasKey('value', $leaf, "Leaf {$path} is missing 'value'.");
            $this->assertArrayHasKey('source', $leaf, "Leaf {$path} is missing 'source'.");
            $this->assertNotSame('', trim((string) $leaf['source']), "Leaf {$path} has an empty 'source'.");
            $this->assertArrayHasKey('verified', $leaf, "Leaf {$path} is missing 'verified'.");
            $this->assertIsBool($leaf['verified'], "Leaf {$path} 'verified' must be a boolean.");

            if ($leaf['verified'] === false) {
                $this->assertArrayHasKey(
                    'checkAgainst',
                    $leaf,
                    "Leaf {$path} has verified=false and must carry a 'checkAgainst' note."
                );
            }

            if (($leaf['placeholder'] ?? false) === true) {
                $this->assertArrayHasKey(
                    'checkAgainst',
                    $leaf,
                    "Leaf {$path} is a placeholder and must carry a 'checkAgainst' note."
                );
            }
        }//end foreach

    }//end testEveryParameterLeafCarriesValueSourceAndVerified()


    /**
     * Exactly one leaf in the whole file carries `placeholder: true` — the
     * employer-specific Whk value (design.md D3/Risks: "the single
     * placeholder-semantics value in the file").
     *
     * @return void
     */
    public function testExactlyOnePlaceholderLeaf(): void
    {
        $leaves = $this->collectLeaves($this->table['parameters']);

        $placeholderPaths = [];
        foreach ($leaves as $path => $leaf) {
            if (($leaf['placeholder'] ?? false) === true) {
                $placeholderPaths[] = $path;
            }
        }

        $this->assertCount(
            1,
            $placeholderPaths,
            'Expected exactly one placeholder:true leaf, found: '.implode(', ', $placeholderPaths)
        );
        $this->assertSame('parameters.werknemersverzekeringen.whk', $placeholderPaths[0]);

    }//end testExactlyOnePlaceholderLeaf()


    /**
     * The Whk leaf specifically carries `placeholder: true` and a
     * `checkAgainst` note naming the employer-specific beschikking (REQ-PCS-001
     * scenario: "Placeholder values are explicit, never silent").
     *
     * @return void
     */
    public function testWhkLeafIsExplicitlyFlaggedAsPlaceholder(): void
    {
        $whk = ($this->table['parameters']['werknemersverzekeringen']['whk'] ?? null);

        $this->assertIsArray($whk, 'parameters.werknemersverzekeringen.whk must exist.');
        $this->assertSame(1.52, $whk['value']);
        $this->assertTrue($whk['verified']);
        $this->assertTrue(($whk['placeholder'] ?? false));
        $this->assertArrayHasKey('checkAgainst', $whk);
        $this->assertStringContainsStringIgnoringCase('beschikking', (string) $whk['checkAgainst']);

    }//end testWhkLeafIsExplicitlyFlaggedAsPlaceholder()


    /**
     * Spot-check a handful of values byte-match design.md D3 (the design is
     * the verified record; a mismatch here is a defect in the table file, not
     * the design).
     *
     * @return void
     */
    public function testKeyValuesMatchTheVerifiedDesignRecord(): void
    {
        $params = $this->table['parameters'];

        $this->assertSame(54, $params['loonheffing']['Lv']['value']);
        $this->assertSame(133110, $params['loonheffing']['Lmax']['value']);

        $belowAow = $params['loonheffing']['schijven']['belowAow']['value'];
        $this->assertSame(35.75, $belowAow[0]['percentage']);
        $this->assertSame(37.56, $belowAow[1]['percentage']);
        $this->assertSame(49.50, $belowAow[2]['percentage']);
        $this->assertSame(38883, $belowAow[1]['a']);
        $this->assertSame(13900, $belowAow[1]['c']);
        $this->assertSame(78426, $belowAow[2]['a']);
        $this->assertSame(28752, $belowAow[2]['c']);

        $this->assertSame(17.90, $params['volksverzekeringen']['aow']['value']);
        $this->assertSame(0.10, $params['volksverzekeringen']['anw']['value']);
        $this->assertSame(9.65, $params['volksverzekeringen']['wlz']['value']);
        $this->assertTrue($params['volksverzekeringen']['informativeSplitOnly']);

        $this->assertSame(67, $params['aow']['leeftijdJaren']['value']);

        $this->assertSame(6.10, $params['zvw']['werkgeversheffing']['value']);
        $this->assertSame(4.85, $params['zvw']['inhouding']['value']);
        $this->assertSame(79409, $params['zvw']['maximumbijdrageloon']['value']);

        $this->assertSame(2.74, $params['werknemersverzekeringen']['awf']['value']['laag']);
        $this->assertSame(7.74, $params['werknemersverzekeringen']['awf']['value']['hoog']);
        $this->assertSame(6.27, $params['werknemersverzekeringen']['aof']['value']['laag']);
        $this->assertSame(7.63, $params['werknemersverzekeringen']['aof']['value']['hoog']);
        $this->assertSame(0.50, $params['werknemersverzekeringen']['wkoOpslag']['value']);
        $this->assertSame(79409, $params['werknemersverzekeringen']['maximumpremieloon']['value']['jaar']);

        $this->assertSame(14.71, $params['wml']['hourly21Plus']['value']['2026-01-01']);
        $this->assertSame(14.99, $params['wml']['hourly21Plus']['value']['2026-07-01']);
        $this->assertSame(2294.40, $params['wml']['referentiemaandloon']['value']);

        $this->assertSame(8.0, $params['vakantiebijslag']['minRatePercent']['value']);

    }//end testKeyValuesMatchTheVerifiedDesignRecord()


    /**
     * Recursively walk `parameters` and collect every "leaf" object — an
     * associative array that carries a `source` key (the discriminator
     * between a leaf and an intermediate group node).
     *
     * @param array<string, mixed> $node    Current subtree.
     * @param string               $prefix  Dotted path accumulated so far.
     *
     * @return array<string, array<string, mixed>> Path => leaf.
     */
    private function collectLeaves(array $node, string $prefix='parameters'): array
    {
        $leaves = [];

        if (array_key_exists('source', $node) === true && array_key_exists('verified', $node) === true) {
            $leaves[$prefix] = $node;
            return $leaves;
        }

        foreach ($node as $key => $value) {
            if (is_array($value) === false) {
                continue;
            }

            $leaves = array_merge($leaves, $this->collectLeaves($value, $prefix.'.'.$key));
        }

        return $leaves;

    }//end collectLeaves()


}//end class
