<?php

/**
 * The register declares every lifecycle state the app's own code creates an object in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister refuses a create whose lifecycle value is neither the schema's
 * `initial` nor one of its `createStates`. These are the states humaniq's own
 * code creates objects in; each must be declared, or the write is refused.
 *
 * @coversNothing
 *
 * @spec openspec/changes/lifecycle-create-states/specs/lifecycle-create-states/spec.md
 */
class LifecycleCreateStatesTest extends TestCase {

	/**
	 * Register file, schema, and the state the code creates an object in, with where it does.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function createdStates(): array {
		return [
			'CompCollectiveService proposes an adjustment' => ['register.d/hr-comp.json', 'CompAdjustment', 'proposed'],
			'LearniqCredentialListener records a followed training' => ['register.d/hr-training.json', 'TrainingRecord', 'gevolgd'],
		];
	}//end createdStates()

	#[DataProvider('createdStates')]
	public function testTheStateACreateStartsInIsDeclared(string $file, string $schema, string $state): void {
		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/' . $file), true);
		$definition = $register['components']['schemas'][$schema];
		$lifecycle = ($definition['configuration']['x-openregister-lifecycle'] ?? $definition['x-openregister-lifecycle']);

		$allowed = array_merge([$lifecycle['initial']], ($lifecycle['createStates'] ?? []));
		$this->assertContains($state, $allowed, $schema . ' must list "' . $state . '" in x-openregister-lifecycle.createStates');
		$this->assertContains($state, $definition['properties'][$lifecycle['field']]['enum']);
	}//end testTheStateACreateStartsInIsDeclared()
}//end class
