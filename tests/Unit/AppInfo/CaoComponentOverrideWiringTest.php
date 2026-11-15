<?php

/**
 * The CAO component override listener judges every contract save.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\AppInfo
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\AppInfo;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Listener\CaoComponentOverrideListener;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the wiring from the caller, Application.
 */
class CaoComponentOverrideWiringTest extends TestCase {

	/**
	 * The override listener hears the creates and updates of contracts.
	 *
	 * @return void
	 */
	public function testTheOverrideListenerJudgesContractSaves(): void {
		if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
			self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
		}

		ObjectEventSubscription::$recorded = [];
		$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(Application::class, 'registerExpensePayrollListeners');
		$method->invoke($app, $this->createMock(IEventDispatcher::class));

		$events = [];
		foreach (ObjectEventSubscription::$recorded as $entry) {
			if ($entry['listener'] === CaoComponentOverrideListener::class) {
				self::assertSame(['employmentcontract'], $entry['schemas']);
				$events[] = $entry['event'];
			}
		}

		self::assertSame(['OCA\OpenRegister\Event\ObjectCreatingEvent', 'OCA\OpenRegister\Event\ObjectUpdatingEvent'], $events);
	}//end testTheOverrideListenerJudgesContractSaves()

}//end class
