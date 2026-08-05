<?php

/**
 * Register-declaration tests for lib/Settings/hrmq_register.json.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * hrmq_register.json used to carry NO `components.registers` section at all —
 * only `info`, `x-openregister` and an empty `components.schemas`, with all 54
 * schemas arriving from the `register.d/*.json` fragments (ADR-037).
 *
 * OpenRegister's ImportHandler creates registers from EXACTLY ONE place:
 * `$data['components']['registers']`. With that section absent, an import of
 * hrmq's configuration created 54 schemas and ZERO registers — and then
 * silently skipped every seed object, because object import resolves
 * `@self.register` through the register map the registers section populates
 * ("Skipping object import - register or schema not found in maps").
 *
 * The visible symptom was not an error. `occ app:enable hrmq` exited 0, the
 * SPA booted, and every one of the 176 manifest page configs that names
 * `register: "hrmq"` resolved to nothing, so the router fell back to its
 * default route. In CI that read as 68 failed / 2 passed — and BOTH "passes"
 * were the default route (`/timesheets`), i.e. the failure mode was also the
 * only thing that looked like success.
 *
 * So the two assertions below are the ones that would have caught it:
 *   1. the register is declared at all, with the slug the manifest uses; and
 *   2. its schema list is EXACTLY the union of the fragments' schema slugs —
 *      because a register that lists only some of them is the same silent
 *      partial outage, one schema at a time, and fragments are added by
 *      different changes that never touch this file.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Settings
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
 * @spec exclude Register provisioning is infrastructure shared by all 55 specs; no single spec owns it.
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pins the `components.registers.hrmq` declaration against the fragments.
 *
 * @spec exclude Register provisioning is infrastructure shared by all 55 specs; no single spec owns it.
 */
class RegisterDeclarationTest extends TestCase
{

    /**
     * The decoded base register configuration.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Absolute path to lib/Settings.
     *
     * @var string
     */
    private string $settingsDir;


    /**
     * Load and decode hrmq_register.json.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsDir = dirname(__DIR__, 3).'/lib/Settings';
        $path = $this->settingsDir.'/hrmq_register.json';
        $this->assertFileExists($path, 'lib/Settings/hrmq_register.json must exist.');

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertIsArray($decoded, 'hrmq_register.json must be valid JSON.');

        $this->config = $decoded;

    }//end setUp()


    /**
     * The `hrmq` register is declared, under the slug the manifest and the
     * OpenRegister REST routes both use.
     *
     * @return void
     */
    public function testRegisterIsDeclared(): void
    {
        $registers = ($this->config['components']['registers'] ?? null);

        $this->assertIsArray(
            $registers,
            'hrmq_register.json must declare components.registers — OpenRegister\'s ImportHandler '
            .'creates registers from that key and nowhere else, so without it an import creates '
            .'schemas but no register and every manifest page resolves to nothing.'
        );

        $this->assertArrayHasKey('hrmq', $registers, 'The register must be keyed "hrmq".');
        $this->assertSame(
            'hrmq',
            ($registers['hrmq']['slug'] ?? null),
            'The register slug must be "hrmq" — src/manifest.json names it 176 times and '
            .'tests/e2e resolves /api/objects/hrmq/<schema> against it.'
        );

    }//end testRegisterIsDeclared()


    /**
     * The register version tracks `info.version`, so the routine version bump
     * that accompanies every fragment change also re-imports the register (and
     * therefore its schema list). OpenRegister skips a register import whose
     * declared version is not newer than the stored one.
     *
     * @return void
     */
    public function testRegisterVersionTracksInfoVersion(): void
    {
        $this->assertSame(
            ($this->config['info']['version'] ?? null),
            ($this->config['components']['registers']['hrmq']['version'] ?? null),
            'The register version must equal info.version. OpenRegister\'s importRegister() skips '
            .'"as existing version is newer or equal", so a register version frozen at 1.0.0 would '
            .'never pick up schemas added by later fragments.'
        );

    }//end testRegisterVersionTracksInfoVersion()


    /**
     * The declared schema list is exactly the union of the schema slugs the
     * `register.d/*.json` fragments define.
     *
     * Compared as sorted sets and reported as two explicit diffs, because
     * "missing from the register" and "listed but nonexistent" are different
     * defects: the first is a schema that silently will not resolve at
     * runtime, the second is a slug OpenRegister logs a warning for and drops.
     *
     * @return void
     */
    public function testDeclaredSchemasMatchTheFragments(): void
    {
        $declared = ($this->config['components']['registers']['hrmq']['schemas'] ?? []);
        $this->assertIsArray($declared, 'The register must declare a schemas list.');

        $fragmentSlugs = [];
        $fragmentFiles = (glob($this->settingsDir.'/register.d/*.json') ?: []);
        $this->assertNotEmpty($fragmentFiles, 'There must be register.d fragments to compare against.');

        foreach ($fragmentFiles as $fragmentFile) {
            $fragment = json_decode(file_get_contents($fragmentFile), true);
            $this->assertIsArray($fragment, basename($fragmentFile).' must be valid JSON.');

            foreach (($fragment['components']['schemas'] ?? []) as $key => $schema) {
                // The slug is what OpenRegister stores and what the register
                // list is resolved against; the object key is only a label.
                $fragmentSlugs[] = ($schema['slug'] ?? $key);
            }
        }

        // Also fold in anything defined directly in the base file, so moving a
        // schema out of a fragment cannot quietly drop it from this check.
        foreach (($this->config['components']['schemas'] ?? []) as $key => $schema) {
            $fragmentSlugs[] = ($schema['slug'] ?? $key);
        }

        sort($fragmentSlugs);
        $declaredSorted = $declared;
        sort($declaredSorted);

        $this->assertSame(
            [],
            array_values(array_diff($fragmentSlugs, $declaredSorted)),
            'Schemas exist that the hrmq register does not list. They will be created but not '
            .'attached to the register, so /api/objects/hrmq/<slug> 404s and their manifest pages '
            .'fall back to the default route.'
        );

        $this->assertSame(
            [],
            array_values(array_diff($declaredSorted, $fragmentSlugs)),
            'The hrmq register lists schema slugs that no fragment defines. OpenRegister drops '
            .'them with a warning during import, which is easy to miss.'
        );

    }//end testDeclaredSchemasMatchTheFragments()


}//end class
