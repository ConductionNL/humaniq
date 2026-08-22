<?php

/**
 * Unit tests for PayrollRetentionGuardService.
 *
 * hrmq#99 (consume-not-rebuild correction): pins that `syncLegalHold()`
 * DERIVES NO retention duration itself -- it only reads an already-known
 * ceiling (a populated `retainedUntil` field, or OpenRegister's own computed
 * `retention.archiefactiedatum`) and syncs a real OpenRegister legal hold
 * onto the object when that ceiling has not yet passed
 * (`RetentionService::placeLegalHold()`), idempotently (never re-places an
 * already-active hold). `isUnderActiveRetention()` additionally recognises an
 * immutable archival status.
 *
 * Second pass (post-review regression fix): also pins
 * `placeStatutoryFloorHold()` -- the ONE place the AWR "period year + N, 31
 * December" formula is computed, for the case (a plain NL Payslip) where NO
 * OpenRegister-native ceiling is reachable for `syncLegalHold()` to read.
 *
 * Drives the service through fake ObjectEntity/RetentionService/MagicMapper
 * doubles (fake collaborators, not fakes of the logic under test) since the
 * real OpenRegister services are a sibling-app dependency not available in
 * this standalone suite.
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
 *
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayrollRetentionGuardService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollRetentionGuardService.
 *
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
 */
class PayrollRetentionGuardServiceTest extends TestCase {

	/**
	 * No ceiling known (no `retainedUntil`, no `retention.archiefactiedatum`)
	 * -- syncLegalHold() is a no-op, no legal hold is placed.
	 *
	 * @return void
	 */
	public function testSyncLegalHoldIsNoOpWhenNoCeilingIsKnown(): void {
		$entity = $this->fakeEntity([], []);
		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: false);
		$objectMapper = $this->fakeObjectMapper();

		$service = $this->buildService($retentionService, $objectMapper);
		$result = $service->syncLegalHold($entity, 'Payslip');

		$this->assertFalse($result['held']);
		$this->assertNull($result['ceiling']);
		$this->assertSame([], $retentionService->placeLegalHoldCalls);
		$this->assertSame([], $objectMapper->saved);

	}//end testSyncLegalHoldIsNoOpWhenNoCeilingIsKnown()

	/**
	 * A populated `retainedUntil` in the FUTURE -- a legal hold is placed and
	 * persisted.
	 *
	 * @return void
	 */
	public function testSyncLegalHoldPlacesHoldFromPopulatedRetainedUntil(): void {
		$future = date('Y-m-d', strtotime('+2 years'));
		$entity = $this->fakeEntity(['retainedUntil' => $future], []);

		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: false);
		$objectMapper = $this->fakeObjectMapper();

		$service = $this->buildService($retentionService, $objectMapper);
		$result = $service->syncLegalHold($entity, 'Payslip');

		$this->assertTrue($result['held']);
		$this->assertSame($future, $result['ceiling']);
		$this->assertSame('retainedUntil', $result['source']);
		$this->assertCount(1, $retentionService->placeLegalHoldCalls);
		$this->assertCount(1, $objectMapper->saved, 'The held object is persisted.');

	}//end testSyncLegalHoldPlacesHoldFromPopulatedRetainedUntil()

	/**
	 * OpenRegister's own computed `retention.archiefactiedatum` in the
	 * FUTURE -- a legal hold is placed. humaniq derives NOTHING here; the date
	 * is read as-is from OpenRegister's own field.
	 *
	 * @return void
	 */
	public function testSyncLegalHoldPlacesHoldFromOpenRegisterArchiefactiedatum(): void {
		$future = date('Y-m-d', strtotime('+3 years'));
		$entity = $this->fakeEntity([], ['archiefactiedatum' => $future, 'archiefstatus' => 'nog_te_archiveren']);

		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: false);
		$objectMapper = $this->fakeObjectMapper();

		$service = $this->buildService($retentionService, $objectMapper);
		$result = $service->syncLegalHold($entity, 'LoonaangifteFiling');

		$this->assertTrue($result['held']);
		$this->assertSame('retention.archiefactiedatum', $result['source']);
		$this->assertCount(1, $retentionService->placeLegalHoldCalls);

	}//end testSyncLegalHoldPlacesHoldFromOpenRegisterArchiefactiedatum()

	/**
	 * A ceiling in the PAST -- no NEW hold is placed (hole #2's
	 * `nl-bewaartermijn-verstreken` flags this moment separately; this
	 * service never acts on it).
	 *
	 * @return void
	 */
	public function testSyncLegalHoldDoesNotPlaceANewHoldWhenCeilingHasPassed(): void {
		$past = date('Y-m-d', strtotime('-1 year'));
		$entity = $this->fakeEntity(['retainedUntil' => $past], []);

		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: false);
		$objectMapper = $this->fakeObjectMapper();

		$service = $this->buildService($retentionService, $objectMapper);
		$result = $service->syncLegalHold($entity, 'Payslip');

		$this->assertFalse($result['held']);
		$this->assertSame([], $retentionService->placeLegalHoldCalls);

	}//end testSyncLegalHoldDoesNotPlaceANewHoldWhenCeilingHasPassed()

	/**
	 * Idempotent: an already-active legal hold is never re-placed.
	 *
	 * @return void
	 */
	public function testSyncLegalHoldIsIdempotentWhenAlreadyHeld(): void {
		$future = date('Y-m-d', strtotime('+1 year'));
		$entity = $this->fakeEntity(['retainedUntil' => $future], []);

		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: true);
		$objectMapper = $this->fakeObjectMapper();

		$service = $this->buildService($retentionService, $objectMapper);
		$result = $service->syncLegalHold($entity, 'Payslip');

		$this->assertTrue($result['held']);
		$this->assertSame([], $retentionService->placeLegalHoldCalls, 'Never re-places an already-active hold.');
		$this->assertSame([], $objectMapper->saved, 'No write when nothing changed.');

	}//end testSyncLegalHoldIsIdempotentWhenAlreadyHeld()

	/**
	 * hrmq#99 regression fix: `placeStatutoryFloorHold()` derives "31
	 * December of (period year + years)" directly from a period-shaped field
	 * when placing the hold -- the ONE place this formula now lives (for the
	 * write side) -- and persists it.
	 *
	 * @return void
	 */
	public function testPlaceStatutoryFloorHoldDerivesCeilingFromPeriodYearPlusYears(): void {
		$entity = $this->fakeEntity(['period' => '2026-08'], []);
		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: false);
		$objectMapper = $this->fakeObjectMapper();

		$service = $this->buildService($retentionService, $objectMapper);
		$result = $service->placeStatutoryFloorHold($entity, 'Payslip', 'period', 7, 'AWR art. 52 lid 4');

		$this->assertTrue($result['held']);
		$this->assertSame('2033-12-31', $result['ceiling']);
		$this->assertCount(1, $retentionService->placeLegalHoldCalls);
		$this->assertStringContainsString('AWR art. 52 lid 4', $retentionService->placeLegalHoldCalls[0]['reason']);
		$this->assertStringContainsString('2033-12-31', $retentionService->placeLegalHoldCalls[0]['reason']);
		$this->assertStringContainsString('2026-08', $retentionService->placeLegalHoldCalls[0]['reason'], 'The reason cites the PERIOD VALUE, not the field name.');
		$this->assertCount(1, $objectMapper->saved);

	}//end testPlaceStatutoryFloorHoldDerivesCeilingFromPeriodYearPlusYears()

	/**
	 * `placeStatutoryFloorHold()` is idempotent -- never re-places an
	 * already-active hold, and performs no write.
	 *
	 * @return void
	 */
	public function testPlaceStatutoryFloorHoldIsIdempotentWhenAlreadyHeld(): void {
		$entity = $this->fakeEntity(['period' => '2026-08'], []);
		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: true);
		$objectMapper = $this->fakeObjectMapper();

		$service = $this->buildService($retentionService, $objectMapper);
		$result = $service->placeStatutoryFloorHold($entity, 'Payslip', 'period', 7, 'AWR art. 52 lid 4');

		$this->assertTrue($result['held']);
		$this->assertSame([], $retentionService->placeLegalHoldCalls);
		$this->assertSame([], $objectMapper->saved);

	}//end testPlaceStatutoryFloorHoldIsIdempotentWhenAlreadyHeld()

	/**
	 * `placeStatutoryFloorHold()` is a no-op (no hold placed) when the
	 * period field is missing/unparseable -- never guesses a year.
	 *
	 * @return void
	 */
	public function testPlaceStatutoryFloorHoldNoOpWhenPeriodFieldUnparseable(): void {
		$entity = $this->fakeEntity([], []);
		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: false);
		$objectMapper = $this->fakeObjectMapper();

		$service = $this->buildService($retentionService, $objectMapper);
		$result = $service->placeStatutoryFloorHold($entity, 'Payslip', 'period', 7, 'AWR art. 52 lid 4');

		$this->assertFalse($result['held']);
		$this->assertSame([], $retentionService->placeLegalHoldCalls);

	}//end testPlaceStatutoryFloorHoldNoOpWhenPeriodFieldUnparseable()

	/**
	 * `isUnderActiveRetention()` is true when an active legal hold exists.
	 *
	 * @return void
	 */
	public function testIsUnderActiveRetentionTrueForActiveLegalHold(): void {
		$entity = $this->fakeEntity([], []);
		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: true);
		$service = $this->buildService($retentionService, $this->fakeObjectMapper());

		$this->assertTrue($service->isUnderActiveRetention($entity));

	}//end testIsUnderActiveRetentionTrueForActiveLegalHold()

	/**
	 * `isUnderActiveRetention()` is true for an immutable archival status
	 * even without an active legal hold.
	 *
	 * @return void
	 */
	public function testIsUnderActiveRetentionTrueForImmutableArchivalStatus(): void {
		$entity = $this->fakeEntity([], []);
		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: false, immutableReason: 'OBJECT_DESTROYED');
		$service = $this->buildService($retentionService, $this->fakeObjectMapper());

		$this->assertTrue($service->isUnderActiveRetention($entity));

	}//end testIsUnderActiveRetentionTrueForImmutableArchivalStatus()

	/**
	 * `isUnderActiveRetention()` is false when there is no hold, no
	 * immutable status, and no ceiling.
	 *
	 * @return void
	 */
	public function testIsUnderActiveRetentionFalseWhenNothingIndicatesRetention(): void {
		$entity = $this->fakeEntity([], []);
		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: false);
		$service = $this->buildService($retentionService, $this->fakeObjectMapper());

		$this->assertFalse($service->isUnderActiveRetention($entity));

	}//end testIsUnderActiveRetentionFalseWhenNothingIndicatesRetention()

	/**
	 * `inheritLegalHold()` places a hold on a DERIVED object (e.g. a
	 * generated PDF's GeneratedDocument) that does not yet carry one.
	 *
	 * @return void
	 */
	public function testInheritLegalHoldPlacesHoldOnDerivedObject(): void {
		$derived = $this->fakeEntity([], []);
		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: false);
		$objectMapper = $this->fakeObjectMapper();

		$service = $this->buildService($retentionService, $objectMapper);
		$held = $service->inheritLegalHold($derived, 'GeneratedDocument', 'Payslip payslip-1');

		$this->assertTrue($held);
		$this->assertCount(1, $retentionService->placeLegalHoldCalls);
		$this->assertStringContainsString('Payslip payslip-1', $retentionService->placeLegalHoldCalls[0]['reason']);
		$this->assertCount(1, $objectMapper->saved);

	}//end testInheritLegalHoldPlacesHoldOnDerivedObject()

	/**
	 * `inheritLegalHold()` is idempotent -- never re-places an already-active
	 * hold on the derived object.
	 *
	 * @return void
	 */
	public function testInheritLegalHoldIsIdempotentWhenDerivedObjectAlreadyHeld(): void {
		$derived = $this->fakeEntity([], []);
		$retentionService = $this->fakeRetentionService(hasActiveLegalHold: true);
		$objectMapper = $this->fakeObjectMapper();

		$service = $this->buildService($retentionService, $objectMapper);
		$held = $service->inheritLegalHold($derived, 'GeneratedDocument', 'Payslip payslip-1');

		$this->assertTrue($held);
		$this->assertSame([], $retentionService->placeLegalHoldCalls);
		$this->assertSame([], $objectMapper->saved);

	}//end testInheritLegalHoldIsIdempotentWhenDerivedObjectAlreadyHeld()

	/**
	 * Build a PayrollRetentionGuardService wired to fake collaborators.
	 *
	 * @param object $retentionService The fake RetentionService.
	 * @param object $objectMapper The fake MagicMapper.
	 *
	 * @return PayrollRetentionGuardService
	 */
	private function buildService(object $retentionService, object $objectMapper): PayrollRetentionGuardService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($retentionService, $objectMapper) {
				if ($id === 'OCA\OpenRegister\Service\RetentionService') {
					return $retentionService;
				}

				if ($id === 'OCA\OpenRegister\Db\MagicMapper') {
					return $objectMapper;
				}

				throw new \RuntimeException('Unexpected container->get(' . $id . ')');
			}
		);

		return new PayrollRetentionGuardService($container, $this->createMock(LoggerInterface::class));
	}//end buildService()

	/**
	 * A fake ObjectEntity double exposing the SAME `getObject()`/
	 * `getRetention()`/`setRetention()` contract PayrollRetentionGuardService
	 * reads/writes.
	 *
	 * @param array<string, mixed> $data The object's own payload (may carry `retainedUntil`).
	 * @param array<string, mixed> $retention The object's `retention` block (may carry `archiefactiedatum`/`archiefstatus`).
	 *
	 * @return object
	 */
	private function fakeEntity(array $data, array $retention): object {
		return new class($data, $retention) {
			/**
			 * @var array<string, mixed>
			 */
			private array $retention;

			/**
			 * @param array<string, mixed> $data Payload.
			 * @param array<string, mixed> $retention Retention block.
			 */
			public function __construct(
				private readonly array $data,
				array $retention,
			) {
				$this->retention = $retention;

			}//end __construct()

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()

			/**
			 * @return array<string, mixed>
			 */
			public function getRetention(): array {
				return $this->retention;
			}//end getRetention()

			/**
			 * @param array<string, mixed> $retention Retention block.
			 *
			 * @return void
			 */
			public function setRetention(array $retention): void {
				$this->retention = $retention;

			}//end setRetention()
		};

	}//end fakeEntity()

	/**
	 * A fake OpenRegister RetentionService double.
	 *
	 * @param bool $hasActiveLegalHold Whether hasActiveLegalHold() reports true.
	 * @param string|null $immutableReason validateNotImmutable()'s return value, or null.
	 *
	 * @return object
	 */
	private function fakeRetentionService(bool $hasActiveLegalHold, ?string $immutableReason = null): object {
		return new class($hasActiveLegalHold, $immutableReason) {
			/**
			 * @var array<int, array{reason: string}>
			 */
			public array $placeLegalHoldCalls = [];

			/**
			 * @param bool $hasActiveLegalHold Configured hasActiveLegalHold() result.
			 * @param string|null $immutableReason Configured validateNotImmutable() result.
			 */
			public function __construct(
				private readonly bool $hasActiveLegalHold,
				private readonly ?string $immutableReason,
			) {

			}//end __construct()

			/**
			 * @param object $object The object.
			 *
			 * @return bool
			 */
			public function hasActiveLegalHold(object $object): bool {
				return $this->hasActiveLegalHold;
			}//end hasActiveLegalHold()

			/**
			 * @param object $object The object.
			 *
			 * @return string|null
			 */
			public function validateNotImmutable(object $object): ?string {
				return $this->immutableReason;
			}//end validateNotImmutable()

			/**
			 * @param object $object The object.
			 * @param string $reason The hold reason.
			 *
			 * @return object The SAME object, with its retention mutated.
			 */
			public function placeLegalHold(object $object, string $reason): object {
				$this->placeLegalHoldCalls[] = ['reason' => $reason];
				$retention = $object->getRetention();
				$retention['legalHold'] = ['active' => true, 'reason' => $reason];
				$object->setRetention($retention);
				return $object;
			}//end placeLegalHold()
		};

	}//end fakeRetentionService()

	/**
	 * A fake OpenRegister MagicMapper double: `update()` records every
	 * write.
	 *
	 * @return object
	 */
	private function fakeObjectMapper(): object {
		return new class {
			/**
			 * @var array<int, object>
			 */
			public array $saved = [];

			/**
			 * hrmq#99: `update()` -- not `ObjectService::saveObject()` -- is
			 * the correct persistence call for entity-level fields like
			 * `retention` (see PayrollRetentionGuardService's class docblock
			 * Persistence gotcha).
			 *
			 * @param object $object The entity to persist.
			 *
			 * @return object
			 */
			public function update(object $object): object {
				$this->saved[] = $object;
				return $object;
			}//end update()
		};

	}//end fakeObjectMapper()

}//end class
