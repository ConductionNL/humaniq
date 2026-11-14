<?php

/**
 * SchemaWriteFilter tests.
 *
 * Which pending writes a pre-save listener judges: a create or update of the named schema that is not the app's own internal write.
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

use OCA\Humaniq\Listener\SchemaWriteFilter;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * Only an outside create or update of the named schema is judged.
 *
 * @covers \OCA\Humaniq\Listener\SchemaWriteFilter
 */
class SchemaWriteFilterTest extends TestCase {

	private function filter(bool $internal): SchemaWriteFilter {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('resolveSchemaSlug')->willReturnCallback(static fn (string $id): string => ['5' => 'Expense', '6' => 'timeentry'][$id] ?? '');
		$marker = $this->createMock(InternalWriteMarker::class);
		$marker->method('isInternal')->willReturn($internal);

		return new SchemaWriteFilter($gateway, $marker);
	}

	private static function entity(string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject(['id' => 'o-1']);

		return $entity;
	}

	public function testAnOutsideCreateOrUpdateOfTheSchemaIsJudged(): void {
		$filter = $this->filter(false);

		self::assertTrue($filter->judges(new ObjectCreatingEvent(self::entity('5')), 'expense'));
		self::assertTrue($filter->judges(new ObjectUpdatingEvent(self::entity('5'), self::entity('6')), 'expense'), 'the new object decides');
		self::assertFalse($filter->judges(new ObjectUpdatingEvent(self::entity('6'), self::entity('5')), 'expense'));
		self::assertFalse($filter->judges(new ObjectCreatingEvent(self::entity('9')), 'expense'));
	}

	public function testTheAppsOwnWritesAndFinishedEventsAreNotJudged(): void {
		self::assertFalse($this->filter(true)->judges(new ObjectCreatingEvent(self::entity('5')), 'expense'));
		self::assertFalse($this->filter(false)->judges(new ObjectCreatedEvent(self::entity('5')), 'expense'));
	}

	public function testThePendingObjectOfEachEvent(): void {
		$created = self::entity('5');
		$updated = self::entity('6');

		self::assertSame($created, SchemaWriteFilter::pendingObject(new ObjectCreatingEvent($created)));
		self::assertSame($updated, SchemaWriteFilter::pendingObject(new ObjectUpdatingEvent($updated, $created)));
		self::assertNull(SchemaWriteFilter::pendingObject(new ObjectCreatedEvent($created)));
	}
}
