<?php

/**
 * Unit tests for DeploymentPlanTotalsListener.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\DeploymentPlanTotalsListener;
use OCA\Humaniq\Service\DeploymentPlanService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * The server writes a plan's line hours, its planned total and, for a typed norm, its source.
 *
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-each-teacher-shall-have-a-yearly-deployment-plan-against-the-norm-for-their-contract-req-edu-001
 */
class DeploymentPlanTotalsListenerTest extends TestCase {

	/**
	 * A plan entity.
	 *
	 * @param array<string, mixed> $data The plan.
	 *
	 * @return ObjectEntity
	 */
	private static function plan(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('plan-1');
		$entity->setSchema('DeploymentPlan');
		$entity->setObject($data);
		return $entity;
	}//end plan()

	/**
	 * The listener.
	 *
	 * @return DeploymentPlanTotalsListener
	 */
	private static function listener(): DeploymentPlanTotalsListener {
		return new DeploymentPlanTotalsListener(plans: new DeploymentPlanService(annualNorm: static fn (string $cao): ?float => null));
	}//end listener()

	/**
	 * A new plan with a typed norm gets its totals, and the norm is marked as entered by hand; a typed total is replaced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-an-unverified-cao-figure-is-not-used
	 */
	public function testANewPlanIsStamped(): void {
		$event = new ObjectCreatingEvent(self::plan(['normHours' => 1659, 'plannedHours' => 1, 'lines' => [['category' => 'lesgevend', 'hoursPerWeek' => 25, 'weeks' => 40], ['category' => 'lesgebonden', 'hours' => 700]]]));

		self::listener()->handle($event);

		$this->assertSame(
			[
				'lines'        => [['category' => 'lesgevend', 'hoursPerWeek' => 25, 'weeks' => 40, 'hours' => 1000.0], ['category' => 'lesgebonden', 'hours' => 700.0]],
				'plannedHours' => 1700.0,
				'normSource'   => 'handmatig',
			],
			$event->getModifiedData()
		);
	}//end testANewPlanIsStamped()

	/**
	 * An update keeps what earlier listeners stamped and a norm that names its source.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepsEarlierStampsAndANamedSource(): void {
		$event = new ObjectUpdatingEvent(self::plan(['normHours' => 995.4, 'normSource' => 'cao', 'lines' => [['category' => 'schooltaak', 'hours' => 40]]]), self::plan([]));
		$event->setModifiedData(['earlier' => 'kept']);

		self::listener()->handle($event);

		$this->assertSame(['earlier' => 'kept', 'lines' => [['category' => 'schooltaak', 'hours' => 40.0]], 'plannedHours' => 40.0], $event->getModifiedData());
	}//end testAnUpdateKeepsEarlierStampsAndANamedSource()

	/**
	 * A plan without a norm gets no source, and another event is left alone.
	 *
	 * @return void
	 */
	public function testNoNormNoSourceAndOtherEvents(): void {
		$event = new ObjectCreatingEvent(self::plan(['lines' => []]));
		self::listener()->handle($event);
		$this->assertSame(['lines' => [], 'plannedHours' => 0.0], $event->getModifiedData());

		$created = new ObjectCreatedEvent(self::plan(['lines' => [['category' => 'schooltaak', 'hours' => 4]]]));
		self::listener()->handle($created);
		$this->assertSame(['category' => 'schooltaak', 'hours' => 4], $created->getObject()->getObject()['lines'][0]);
	}//end testNoNormNoSourceAndOtherEvents()
}//end class
