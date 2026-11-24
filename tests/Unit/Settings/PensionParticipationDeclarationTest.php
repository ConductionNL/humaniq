<?php

/**
 * PensionParticipationDeclarationTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * payroll-engine-port-from-shillinq, tasks 1.1 and 1.2: the participation sits
 * next to the existing PensionScheme (O1 decided: the schema
 * filings-pension-upa-message already ships), keys on the same fund, and has
 * its pages, an employee tab and two seeded examples.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The pension participation declaration.
 */
class PensionParticipationDeclarationTest extends TestCase {

	/**
	 * A decoded JSON file below the app root.
	 *
	 * @param string $path The path below the app root.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		$decoded = json_decode((string)file_get_contents(__DIR__ . '/../../../' . $path), true);
		$this->assertIsArray($decoded, $path);
		return $decoded;
	}

	/**
	 * The pension fragment's schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		return $this->json('lib/Settings/register.d/hr-pension.json')['components']['schemas'];
	}

	public function testTheSchemeTheRunReadsCarriesFranchiseCapAndPercentages(): void {
		$properties = $this->schemas()['PensionScheme']['properties'];

		foreach (['fund', 'franchise', 'maxPensionableSalary', 'employerPercentage', 'employeePercentage', 'validFrom', 'validUntil'] as $field) {
			$this->assertArrayHasKey($field, $properties, $field);
		}
	}

	public function testTheParticipationLinksAnEmployeeToAFundForADateRange(): void {
		$schemas       = $this->schemas();
		$participation = $schemas['PensionParticipation'];
		$properties    = $participation['properties'];

		$this->assertSame('schema:Role', $participation['x-schema-org']);
		$this->assertSame(['employeeId', 'fund', 'startDate'], $participation['required']);
		$this->assertSame('Employee', $properties['employeeId']['$ref']);
		$this->assertSame($schemas['PensionScheme']['properties']['fund']['enum'], $properties['fund']['enum'], 'a participation names a fund the scheme knows');
		$this->assertSame('date', $properties['startDate']['format']);
		$this->assertSame('date', $properties['endDate']['format']);
		$this->assertSame(1, $properties['participationFactor']['default']);
		$this->assertContains(['group' => 'authenticated', 'match' => ['userId' => '$userId']], $participation['authorization']['read']);
		$this->assertNotContains('authenticated', $participation['authorization']['create']);
	}

	public function testTheRegisterCarriesTheParticipation(): void {
		$schemas = $this->json('lib/Settings/humaniq_register.json')['components']['registers']['humaniq']['schemas'];

		$this->assertContains('PensionParticipation', $schemas);
	}

	public function testTheSeedHoldsTwoParticipationsInASeededScheme(): void {
		$funds = [];
		$participations = [];
		foreach ($this->json('lib/Settings/register.d/hr-seed.json')['components']['objects'] as $object) {
			if ($object['@self']['schema'] === 'PensionScheme') {
				$funds[] = $object['fund'];
			} else if ($object['@self']['schema'] === 'PensionParticipation') {
				$participations[] = $object;
			}
		}

		$this->assertCount(2, $participations);
		foreach ($participations as $participation) {
			$this->assertContains($participation['fund'], $funds);
		}
	}

	public function testPayrollHasPagesAndTheEmployeeATab(): void {
		$ids = [];
		foreach ($this->json('src/manifest.d/hr-pension.json')['pageInstances'] as $page) {
			$ids[] = $page['params']['id'];
		}

		$this->assertContains('PensionParticipations', $ids);
		$this->assertContains('PensionParticipationDetail', $ids);

		$shown = [];
		foreach ($this->json('src/manifest.d/hr-objects.json')['pages'] as $page) {
			if ($page['id'] === 'EmployeeDetail') {
				foreach ($page['config']['widgets'] as $widget) {
					$shown[] = ($widget['content']['schema'] ?? null);
				}
			}
		}

		$this->assertContains('PensionParticipation', $shown);
	}
}
