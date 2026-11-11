<?php

/**
 * Unit tests for ChangeApprovalRules.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\ChangeApprovalRules;
use OCA\Humaniq\Service\RecordStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Which change approval rules apply to an administration, and which kind
 * guards a field.
 */
class ChangeApprovalRulesTest extends TestCase {

	/**
	 * The whole outcome: the rules per administration and the guarding
	 * kind per field, guarded and not guarded.
	 *
	 * @return void
	 */
	public function testTheWholeRulesAndGuardOutcome(): void {
		$rows = [
			['changeKind' => 'salaris', 'fields' => ['grossMonthlySalary'], 'approverRole' => 'hr'],
			['changeKind' => 'bankrekening', 'fields' => ['iban', 7], 'approverRole' => 'hr'],
			['changeKind' => 'adres', 'fields' => ['straat'], 'approverRole' => 'none'],
			['changeKind' => ' ', 'fields' => ['x'], 'approverRole' => 'hr'],
			['changeKind' => 'salaris', 'fields' => ['grossMonthlySalary', 'bonus'], 'approverRole' => 'finance', 'administrationId' => ' ADM-2 '],
			['changeKind' => 'adres', 'fields' => ['straat'], 'approverRole' => 'manager', 'administrationId' => 'ADM-2'],
			['changeKind' => 'naam', 'fields' => 'firstName', 'administrationId' => 'ADM-2'],
			['changeKind' => 'bankrekening', 'fields' => ['iban'], 'approverRole' => 'none', 'administrationId' => 'ADM-3'],
			['changeKind' => 'salaris', 'fields' => ['grossMonthlySalary'], 'approverRole' => 'cfo'],
			['changeKind' => '42', 'fields' => ['code'], 'approverRole' => 'hr'],
			['changeKind' => '42', 'fields' => ['code'], 'approverRole' => 'hr', 'administrationId' => 'ADM-2'],
		];
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('loadAll')->willReturn($rows);
		$rules = new ChangeApprovalRules($store);
		$got = [];
		foreach (['none' => null, 'blank' => '', 'space' => ' ', 'adm-1' => 'ADM-1', 'adm-2' => 'ADM-2', 'adm-3' => 'ADM-3'] as $name => $administrationId) {
			$forAdministration = $rules->forAdministration($administrationId);
			$guard = [];
			foreach (['grossMonthlySalary', 'bonus', 'iban', '7', 'straat', 'firstName', 'x'] as $field) {
				$guard[$field] = $rules->guardingKind($field, $forAdministration);
			}

			$got[$name] = ['rules' => $forAdministration, 'guard' => $guard];
		}

		self::assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/change-approval-rules.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testTheWholeRulesAndGuardOutcome()

}//end class
