<?php

/**
 * Every lifecycle `requires` tag resolves to a registered guard.
 *
 * OpenRegister resolves a transition's `requires` FQCN through the app
 * container. A tag naming a class that does not exist, that is not a guard,
 * or that Application.php never registers fails at the moment a user presses
 * the button, not in any test. So this walks every register fragment, collects
 * every `requires` it declares, and checks the three facts from the caller's
 * side: the class exists, it implements LifecycleGuardInterface, and
 * Application::register() hands it to `registerService()`.
 *
 * The walk also asserts a lower bound on how many tags it found, so an empty
 * walk (a moved directory, a renamed key) fails instead of passing on nothing.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit
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
 * @spec exclude architectural contract test: walks every lifecycle guard tag across all register fragments; no single requirement owns it
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Walks the register fragments' lifecycle guard tags.
 */
class LifecycleGuardRegistrationTest extends TestCase {

	/**
	 * Every `requires` FQCN declared on a transition, keyed `fragment:schema.transition`.
	 *
	 * @return array<string, string>
	 */
	private function requiresTags(): array {
		$tags = [];
		foreach (glob(dirname(__DIR__, 2) . '/lib/Settings/register.d/*.json') as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			foreach (($fragment['components']['schemas'] ?? []) as $schemaName => $schema) {
				$lifecycle = ($schema['configuration']['x-openregister-lifecycle'] ?? ($schema['x-openregister-lifecycle'] ?? []));
				foreach (($lifecycle['transitions'] ?? []) as $transition => $spec) {
					$requires = ($spec['requires'] ?? null);
					if (is_string($requires) === true && $requires !== '') {
						$tags[basename($file) . ':' . $schemaName . '.' . $transition] = $requires;
					}
				}
			}
		}

		return $tags;
	}//end requiresTags()

	/**
	 * The walk found the guards it is meant to check.
	 *
	 * @return void
	 */
	public function testTheWalkFindsTheDeclaredGuards(): void {
		$tags = $this->requiresTags();

		$this->assertGreaterThanOrEqual(17, count($tags), 'The walk found fewer requires tags than the fragments declare; it is reading the wrong place.');
		$this->assertContains('OCA\Humaniq\Lifecycle\RosterCompetenceGuard', $tags, 'Roster publiceren is guarded (humaniq#512).');
	}//end testTheWalkFindsTheDeclaredGuards()

	/**
	 * Every tag names an existing guard class that Application.php registers.
	 *
	 * @return void
	 */
	public function testEveryRequiresTagResolvesToARegisteredGuard(): void {
		$application = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/AppInfo/Application.php');

		foreach ($this->requiresTags() as $where => $fqcn) {
			$this->assertTrue(class_exists($fqcn), $where . ' requires ' . $fqcn . ', which does not exist.');
			$this->assertContains(
				'OCA\OpenRegister\Lifecycle\LifecycleGuardInterface',
				class_implements($fqcn),
				$where . ' requires ' . $fqcn . ', which is not a lifecycle guard.'
			);

			$short = substr($fqcn, (strrpos($fqcn, '\\') + 1));
			$this->assertMatchesRegularExpression(
				'/registerService\(\s*' . preg_quote($short, '/') . '::class/',
				$application,
				$where . ' requires ' . $fqcn . ', which Application.php never registers.'
			);
		}
	}//end testEveryRequiresTagResolvesToARegisteredGuard()

}//end class
