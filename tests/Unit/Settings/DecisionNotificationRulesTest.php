<?php

/**
 * Decision notification rules test
 *
 * Every decision on a request reaches the requester, the payslip rule ships
 * switched off, every rule names humaniq as its origin and carries a subject
 * in both locales, and the user settings entry that opens the per-user
 * switches exists in the menu (platform-notifications D1-D4).
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
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
 * @spec openspec/specs/humaniq-notifications/spec.md#REQ-NTF-001
 * @spec openspec/specs/humaniq-notifications/spec.md#REQ-NTF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The declared decision and payslip rules.
 */
class DecisionNotificationRulesTest extends TestCase {

	/**
	 * Schema, rule key, trigger action, and a field the message must carry.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string|null, 3: string|null}>
	 */
	public static function rules(): array {
		return [
			'leave approved' => ['LeaveRequest', 'leave-approved', 'approve', null],
			'leave rejected' => ['LeaveRequest', 'leave-rejected', 'reject', '{{rejectionReason}}'],
			'timesheet approved' => ['Timesheet', 'timesheet-approved', 'approve', null],
			'timesheet rejected' => ['Timesheet', 'timesheet-rejected', 'reject', '{{rejectionReason}}'],
			'expense approved' => ['Expense', 'expense-approved', 'approve', null],
			'expense rejected' => ['Expense', 'expense-rejected', 'reject', '{{rejectionReason}}'],
			'expense reimbursed' => ['Expense', 'expense-reimbursed', 'reimburse', null],
			'leave transaction approved' => ['LeaveTransaction', 'leave-transaction-approved', 'approve', null],
			'leave transaction rejected' => ['LeaveTransaction', 'leave-transaction-rejected', 'reject', '{{rejectionReason}}'],
			'review finalised' => ['PerformanceReview', 'review-finalised', 'vaststellen', null],
			'payslip ready' => ['Payslip', 'payslip-ready', null, null],
		];
	}//end rules()

	/**
	 * Each decision reaches the requester in userId, through the Nextcloud notification.
	 *
	 * @param string      $schema The schema.
	 * @param string      $key    The rule key.
	 * @param string|null $action The transition, null for a created trigger.
	 * @param string|null $field  A placeholder the message must carry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-notifications/spec.md#REQ-NTF-001
	 */
	#[DataProvider('rules')]
	public function testTheDecisionReachesTheRequester(string $schema, string $key, ?string $action, ?string $field): void {
		$declared = RegisterSchemaValidator::schema($schema);
		$rules = ($declared['configuration']['x-openregister-notifications'] ?? []);
		self::assertArrayHasKey($key, $rules, $schema . ' declares ' . $key . '.');
		$rule = $rules[$key];

		if ($action === null) {
			self::assertSame(['type' => 'created'], $rule['trigger']);
		} else {
			self::assertSame(['type' => 'transition', 'action' => $action], $rule['trigger']);
			self::assertArrayHasKey($action, $declared['configuration']['x-openregister-lifecycle']['transitions']);
		}

		self::assertSame([['kind' => 'field', 'field' => 'userId']], $rule['recipients']);
		self::assertArrayHasKey('userId', $declared['properties']);
		self::assertSame(['nc-notification'], $rule['channels']);
		self::assertSame('humaniq', $rule['originApp']);
		foreach (['nl', 'en'] as $locale) {
			self::assertNotSame('', trim((string)($rule['subject'][$locale] ?? '')));
			if ($field !== null) {
				self::assertStringContainsString($field, $rule['message'][$locale]);
			}
		}
	}//end testTheDecisionReachesTheRequester()

	/**
	 * The payslip rule ships off, every decision rule on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-notifications/spec.md#REQ-NTF-002
	 */
	public function testThePayslipRuleShipsSwitchedOff(): void {
		foreach (self::rules() as [$schema, $key]) {
			$rule = RegisterSchemaValidator::schema($schema)['configuration']['x-openregister-notifications'][$key];
			self::assertSame($key !== 'payslip-ready', $rule['enabled'], $key);
		}
	}//end testThePayslipRuleShipsSwitchedOff()

	/**
	 * The menu opens the user settings, where the per-user switches live.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-notifications/spec.md#REQ-NTF-002
	 */
	public function testTheMenuOpensTheUserSettings(): void {
		$menu = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.d/05-menu.json'), true)['menu'];
		$actions = array_column($menu, 'action');
		self::assertContains('user-settings', $actions);
	}//end testTheMenuOpensTheUserSettings()

}//end class
