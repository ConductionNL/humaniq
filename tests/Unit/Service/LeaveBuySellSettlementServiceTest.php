<?php

/**
 * Unit tests for LeaveBuySellSettlementService.
 *
 * Pins the leave-buy-sell settlement write contract (design.md D4): an
 * approved transaction with a valid settlementPeriod and a resolvable,
 * sufficient (for sell) LeaveBalance writes ONLY bovenwettelijkHours
 * (`entitledHours`/`usedHours` untouched) and stamps
 * settledAmount/settledAt/status on the transaction; every refusal branch
 * writes nothing; settling twice is an idempotent no-op. Drives the service
 * through a fake ObjectService double (a fake collaborator, not a fake of
 * the service logic under test) since the real OpenRegister ObjectService is
 * a sibling-app dependency not available in this standalone suite.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-002
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\LeaveBuySellSettlementService;
use OCA\Hrmq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for LeaveBuySellSettlementService.
 *
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
 */
class LeaveBuySellSettlementServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double supporting the subset
	 * LeaveBuySellSettlementService uses: `find()` by id/schema, `setSchema()`
	 * + `findAll()`, and `saveObject()` (recording every write and reflecting
	 * it back into the seeded rows).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object The fake ObjectService.
	 */
	private function fakeObjectService(array $rowsBySchema = []): object {
		return new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Every saveObject() call, as `['schema' => ..., 'object' => ...]`.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				public array $rowsBySchema,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

			/**
			 * @param string $id Object id.
			 * @param string $register Register slug (unused by the fake).
			 * @param string $schema Schema name.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, string $register, string $schema): ?array {
				foreach (($this->rowsBySchema[$schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * @param array<string, mixed> $object The object to save.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 * @param string|null $uuid Existing id when updating.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return array<string, mixed> The saved object (with its id).
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$targetSchema = ($schema ?? $this->schema);
				$id = ($uuid ?? (string)($object['id'] ?? ''));
				$saved = array_merge($object, ['id' => $id]);

				$this->saved[] = ['schema' => $targetSchema, 'object' => $saved];

				$rows = ($this->rowsBySchema[$targetSchema] ?? []);
				$replaced = false;
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $id) {
						$rows[$i] = $saved;
						$replaced = true;
						break;
					}
				}

				if ($replaced === false) {
					$rows[] = $saved;
				}

				$this->rowsBySchema[$targetSchema] = $rows;

				return $saved;
			}//end saveObject()

		};

	}//end fakeObjectService()

	/**
	 * Build a fully-wired LeaveBuySellSettlementService plus its fake
	 * ObjectService double (for assertions on what was saved).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return array{0: LeaveBuySellSettlementService, 1: object}
	 */
	private function service(array $rowsBySchema = []): array {
		$fake = $this->fakeObjectService($rowsBySchema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);

		return [new LeaveBuySellSettlementService($container, $settings, $logger), $fake];
	}//end service()

	/**
	 * The standard fixture: one LeaveBalance (bovenwettelijkHours 20) and one
	 * approved sell transaction for 8 hours at 25.00/hour.
	 *
	 * @param array<string, mixed> $transactionOverrides Fields to override on the LeaveTransaction.
	 * @param array<string, mixed> $balanceOverrides Fields to override on the LeaveBalance.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function fixture(array $transactionOverrides = [], array $balanceOverrides = []): array {
		$transaction = array_merge(
			[
				'id' => 'txn-1',
				'employeeId' => 'emp-1',
				'transactionType' => 'sell',
				'year' => 2026,
				'leaveType' => 'holiday',
				'hours' => 8,
				'hourlyRate' => 25.00,
				'status' => 'approved',
				'settlementPeriod' => '2026-06',
				'settledAmount' => null,
				'settledAt' => null,
			],
			$transactionOverrides
		);

		$balance = array_merge(
			[
				'id' => 'bal-1',
				'employeeId' => 'emp-1',
				'year' => 2026,
				'leaveType' => 'holiday',
				'entitledHours' => 160,
				'bovenwettelijkHours' => 20,
				'usedHours' => 40,
			],
			$balanceOverrides
		);

		return [
			'LeaveTransaction' => [$transaction],
			'LeaveBalance' => [$balance],
			'PayrollRun' => [],
		];

	}//end fixture()

	/**
	 * REQ-BUYSELL-004: an approved sell with a valid settlementPeriod and a
	 * sufficient balance writes bovenwettelijkHours down by `hours`, leaves
	 * entitledHours/usedHours untouched, computes settledAmount exactly, and
	 * stamps the transaction settled.
	 *
	 * @return void
	 */
	public function testSellSettlementWritesExactBalanceAndSettledAmount(): void {
		[$service, $fake] = $this->service($this->fixture());

		$result = $service->settle('txn-1');

		$this->assertSame('settled', $result['status']);
		$this->assertSame(200.00, $result['settledAmount']);

		$balanceSaves = array_values(array_filter($fake->saved, static fn (array $s): bool => $s['schema'] === 'LeaveBalance'));
		$this->assertCount(1, $balanceSaves);
		$this->assertSame(12.0, $balanceSaves[0]['object']['bovenwettelijkHours'], 'sell: bovenwettelijkHours -= hours (20 - 8 = 12).');
		$this->assertSame(160, $balanceSaves[0]['object']['entitledHours'], 'entitledHours is NEVER touched.');
		$this->assertSame(40, $balanceSaves[0]['object']['usedHours'], 'usedHours is NEVER touched.');

		$transactionSaves = array_values(array_filter($fake->saved, static fn (array $s): bool => $s['schema'] === 'LeaveTransaction'));
		$this->assertCount(1, $transactionSaves);
		$this->assertSame('settled', $transactionSaves[0]['object']['status']);
		$this->assertSame(200.00, $transactionSaves[0]['object']['settledAmount']);
		$this->assertNotNull($transactionSaves[0]['object']['settledAt']);

	}//end testSellSettlementWritesExactBalanceAndSettledAmount()

	/**
	 * REQ-BUYSELL-004/-002: a buy adds `hours` onto bovenwettelijkHours.
	 *
	 * @return void
	 */
	public function testBuySettlementAddsToBalance(): void {
		[$service, $fake] = $this->service($this->fixture(['id' => 'txn-1', 'transactionType' => 'buy', 'hours' => 10, 'hourlyRate' => 15.00]));

		$result = $service->settle('txn-1');

		$this->assertSame('settled', $result['status']);
		$this->assertSame(150.00, $result['settledAmount']);

		$balanceSaves = array_values(array_filter($fake->saved, static fn (array $s): bool => $s['schema'] === 'LeaveBalance'));
		$this->assertSame(30.0, $balanceSaves[0]['object']['bovenwettelijkHours'], 'buy: bovenwettelijkHours += hours (20 + 10 = 30).');

	}//end testBuySettlementAddsToBalance()

	/**
	 * REQ-BUYSELL-004: settling an already-settled transaction is an
	 * idempotent no-op -- nothing is written a second time.
	 *
	 * @return void
	 */
	public function testAlreadySettledIsIdempotentNoOp(): void {
		[$service, $fake] = $this->service($this->fixture(['status' => 'settled', 'settledAmount' => 200.00]));

		$result = $service->settle('txn-1');

		$this->assertSame('already-settled', $result['status']);
		$this->assertSame([], $fake->saved);

	}//end testAlreadySettledIsIdempotentNoOp()

	/**
	 * REQ-BUYSELL-004: a non-approved transaction (e.g. still `submitted`)
	 * is refused and writes nothing.
	 *
	 * @return void
	 */
	public function testNonApprovedTransactionRefusedWritesNothing(): void {
		[$service, $fake] = $this->service($this->fixture(['status' => 'submitted']));

		$result = $service->settle('txn-1');

		$this->assertSame('refused-not-approved', $result['status']);
		$this->assertSame([], $fake->saved);

	}//end testNonApprovedTransactionRefusedWritesNothing()

	/**
	 * REQ-BUYSELL-004: a missing settlementPeriod is refused and writes
	 * nothing (belt-and-braces alongside LeaveSettlementPeriodGuard).
	 *
	 * @return void
	 */
	public function testMissingSettlementPeriodRefusedWritesNothing(): void {
		[$service, $fake] = $this->service($this->fixture(['settlementPeriod' => '']));

		$result = $service->settle('txn-1');

		$this->assertSame('refused-no-settlement-period', $result['status']);
		$this->assertSame([], $fake->saved);

	}//end testMissingSettlementPeriodRefusedWritesNothing()

	/**
	 * REQ-BUYSELL-004: an unresolvable LeaveBalance is refused and writes
	 * nothing (no auto-provisioning, per the named non-goal).
	 *
	 * @return void
	 */
	public function testUnresolvableBalanceRefusedWritesNothing(): void {
		$rows = $this->fixture();
		$rows['LeaveBalance'] = [];

		[$service, $fake] = $this->service($rows);

		$result = $service->settle('txn-1');

		$this->assertSame('refused-balance-unresolvable', $result['status']);
		$this->assertSame([], $fake->saved);

	}//end testUnresolvableBalanceRefusedWritesNothing()

	/**
	 * REQ-BUYSELL-002/-004: a sell whose hours exceed the balance's
	 * bovenwettelijkHours (re-checked at settle time, belt-and-braces
	 * alongside LeaveBuySellApprovalGuard) is refused and writes nothing.
	 *
	 * @return void
	 */
	public function testInsufficientBovenwettelijkForSellRefusedWritesNothing(): void {
		[$service, $fake] = $this->service($this->fixture(['hours' => 30], ['bovenwettelijkHours' => 20]));

		$result = $service->settle('txn-1');

		$this->assertSame('refused-insufficient-bovenwettelijk', $result['status']);
		$this->assertSame([], $fake->saved);

	}//end testInsufficientBovenwettelijkForSellRefusedWritesNothing()

	/**
	 * A transaction that cannot be found is refused ('failed'), writing
	 * nothing.
	 *
	 * @return void
	 */
	public function testUnknownTransactionRefusedWritesNothing(): void {
		[$service, $fake] = $this->service($this->fixture());

		$result = $service->settle('no-such-transaction');

		$this->assertSame('failed', $result['status']);
		$this->assertSame([], $fake->saved);

	}//end testUnknownTransactionRefusedWritesNothing()

	/**
	 * When a PayrollRun exists for the settlementPeriod, settle() stamps
	 * settlementPayrollRunId onto the transaction (the retro-adjustments
	 * RetroAdjustmentService::resolveRunForPeriod precedent, best-effort).
	 *
	 * @return void
	 */
	public function testSettlementStampsMatchingPayrollRunId(): void {
		$rows = $this->fixture();
		$rows['PayrollRun'] = [['id' => 'run-2026-06', 'period' => '2026-06', 'administrationId' => 'ADM-001', 'status' => 'draft']];

		[$service, $fake] = $this->service($rows);

		$service->settle('txn-1');

		$transactionSaves = array_values(array_filter($fake->saved, static fn (array $s): bool => $s['schema'] === 'LeaveTransaction'));
		$this->assertSame('run-2026-06', $transactionSaves[0]['object']['settlementPayrollRunId']);

	}//end testSettlementStampsMatchingPayrollRunId()

}//end class
