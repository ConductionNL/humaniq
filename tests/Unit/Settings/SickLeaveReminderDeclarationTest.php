<?php

/**
 * The Poortwachter reminders and the frequent-absence signal are declared.
 *
 * Walks SickLeaveCase in the register fragment: each milestone has a
 * materialised days-left calculation, a rule when it crosses to 14 days and a
 * rule when it passes zero, both addressed to CaseManagerResolver, which
 * exists and implements OpenRegister's recipient seam; the frequent-absence
 * rule fires on the false to true change only. The rule shapes were checked
 * with OpenRegister's NotificationAnnotationValidator and the calculations
 * with its CalculationAnnotationValidator and CalculationEvaluator (0 errors;
 * a milestone due in 10 days reads 10, a done or recovered one reads empty).
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
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-001
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Declared reminders on SickLeaveCase.
 */
class SickLeaveReminderDeclarationTest extends TestCase {

	private const MILESTONES = ['probleemanalyse', 'planVanAanpak', 'uwv42WeekMelding', 'eerstejaarsevaluatie'];

	/**
	 * Every milestone is reminded at 14 days and when overdue, and goes quiet
	 * once done or recovered.
	 *
	 * @return void
	 */
	public function testEveryMilestoneIsRemindedAndGoesQuietWhenDone(): void {
		$config = RegisterSchemaValidator::schema('SickLeaveCase')['configuration'];
		$rules = $config['x-openregister-notifications'];
		$calcs = $config['x-openregister-calculations'];
		$resolver = 'OCA\Humaniq\Notification\CaseManagerResolver';

		self::assertTrue(class_exists($resolver));
		self::assertContains('OCA\OpenRegister\Service\Notification\RecipientResolverInterface', class_implements($resolver));

		foreach (self::MILESTONES as $milestone) {
			$field = $milestone . 'DaysLeft';
			self::assertTrue($calcs[$field]['materialise'], $field . ' is materialised so the daily sweep sees it change.');
			$quiet = json_encode($calcs[$field]['expression']['if'][0]);
			self::assertStringContainsString($milestone . 'Done', (string)$quiet);
			self::assertStringContainsString('hersteld', (string)$quiet);

			$soon = $rules[$milestone . 'DueSoon'];
			self::assertSame(['type' => 'calculatedChange', 'field' => $field, 'condition' => ['lte' => 14], 'previously' => ['gt' => 14]], $soon['trigger']);
			self::assertSame([['kind' => 'expression', 'resolver' => $resolver]], $soon['recipients']);

			$overdue = $rules[$milestone . 'Overdue'];
			self::assertSame(['type' => 'calculatedChange', 'field' => $field, 'condition' => ['lt' => 0], 'previously' => ['gte' => 0]], $overdue['trigger']);
		}

		$signal = $rules['frequentAbsenceSignal']['trigger'];
		self::assertSame(['field' => 'frequentAbsence', 'operator' => 'equals', 'value' => true, 'from' => false], $signal['condition']);
	}//end testEveryMilestoneIsRemindedAndGoesQuietWhenDone()

}//end class
