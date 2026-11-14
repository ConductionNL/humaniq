<?php

/**
 * SettleShippedExampleClaim unit tests
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Repair
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
 * @spec openspec/changes/settle-the-shipped-example-claim/specs/humaniq-expenses/spec.md#Requirement:-The-shipped-example-claim-SHALL-be-settled-once-on-tenants-that-imported-it-as-payable
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\SettleShippedExampleClaim;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OpenRegisterAvailability;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\PayrollExpenseFoldService;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Settling the shipped example claim.
 *
 * @covers \OCA\Humaniq\Repair\SettleShippedExampleClaim
 * @uses   \OCA\Humaniq\Service\HoursRegisterGateway
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 * @uses   \OCA\Humaniq\Service\PayrollExpenseFoldService
 * @uses   \OCA\Humaniq\Service\AllowanceSplitter
 * @uses   \OCA\Humaniq\Service\OpenRegisterAvailability
 */
class SettleShippedExampleClaimTest extends TestCase {

	private const EMPLOYEE = 'b3f1c2d4-0000-4000-8000-00000000d001';

	/**
	 * The claim as the old seed shipped it, after import.
	 *
	 * @var array<string, mixed>
	 */
	private const SHIPPED = [
		'@self' => ['slug' => 'expense-devries-train'],
		'employeeId' => self::EMPLOYEE,
		'title' => 'Treinreis Utrecht: Den Haag',
		'description' => 'Retour tweede klas voor projectoverleg.',
		'amount' => 27.4,
		'currency' => 'EUR',
		'category' => 'travel',
		'expenseDate' => '2026-05-20',
		'status' => 'approved',
		'submittedAt' => '2026-05-20T18:00:00Z',
		'approvedBy' => 'manager-pietersen',
		'approvedAt' => '2026-05-21T09:15:00Z',
		'administrationId' => 'ADM-001',
		'reimbursementRoute' => 'payroll',
		'userId' => 'devries',
		'managerUserId' => 'pietersen',
	];

	/**
	 * The fake register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The marker the step writes under.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * Every log line, with its level.
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * Whether each save ran as an internal write.
	 *
	 * @var list<bool>
	 */
	private array $internal = [];

	/**
	 * Seed the employee and the shipped claim.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->marker = new InternalWriteMarker();
		$marker = $this->marker;
		$internal = &$this->internal;
		$this->store = new class($marker, $internal) extends FakeObjectStore {
			/**
			 * Whether a save throws.
			 *
			 * @var bool
			 */
			public bool $failSave = false;

			/**
			 * @param InternalWriteMarker $marker The marker.
			 * @param list<bool> $internal One flag per save.
			 */
			public function __construct(private InternalWriteMarker $marker, private array &$internal) {
				parent::__construct();
			}//end __construct()

			/**
			 * Record the marker, then save.
			 *
			 * @param array<string, mixed>|object $object The payload.
			 * @param array<int, string>|null $extend Ignored.
			 * @param mixed $register Ignored.
			 * @param mixed $schema The schema.
			 * @param string|null $uuid The uuid.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return \OCA\OpenRegister\Db\ObjectEntity
			 */
			public function saveObject(
				array|object $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): \OCA\OpenRegister\Db\ObjectEntity {
				if ($this->failSave === true) {
					throw new \RuntimeException('store down');
				}

				$this->internal[] = $this->marker->isInternal();

				return parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy);
			}//end saveObject()
		};
		$this->store->seed('Employee', self::EMPLOYEE, ['@self' => ['slug' => 'employee-devries'], 'nextcloudUserId' => 'devries']);
		$this->store->seed('Employee', 'emp-other', ['@self' => ['slug' => 'employee-jansen'], 'nextcloudUserId' => 'jansen']);
		$this->store->seed('Expense', 'ex-train', self::SHIPPED);
		$this->store->seed('Expense', 'ex-other', array_merge(self::SHIPPED, ['@self' => ['slug' => 'expense-jansen-hotel']]));
	}//end setUp()

	/**
	 * Run the step and return what it told the operator.
	 *
	 * @param bool $available Whether OpenRegister is available.
	 *
	 * @return array{infos: list<string>, warnings: list<string>}
	 */
	private function runStep(bool $available = true): array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app === 'openregister' && $available);
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('log')->willReturnCallback(function ($level, $message, array $context = []): void {
			$this->logged[] = [(string)$level, (string)$message, $context];
		});
		foreach (['info', 'notice', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
				$this->logged[] = [$level, (string)$message, $context];
			});
		}

		$output = new class implements IOutput {
			/**
			 * @var list<string>
			 */
			public array $infos = [];

			/**
			 * @var list<string>
			 */
			public array $warnings = [];

			/**
			 * @param string $message The message.
			 *
			 * @return void
			 */
			public function debug(string $message): void {
			}//end debug()

			/**
			 * @param string $message The message.
			 *
			 * @return void
			 */
			public function info($message): void {
				$this->infos[] = $message;
			}//end info()

			/**
			 * @param string $message The message.
			 *
			 * @return void
			 */
			public function warning($message): void {
				$this->warnings[] = $message;
			}//end warning()

			/**
			 * @param int $max The steps.
			 *
			 * @return void
			 */
			public function startProgress($max = 0): void {
			}//end startProgress()

			/**
			 * @param int $step The step.
			 * @param string $description The description.
			 *
			 * @return void
			 */
			public function advance($step = 1, $description = ''): void {
			}//end advance()

			/**
			 * @return void
			 */
			public function finishProgress(): void {
			}//end finishProgress()
		};

		(new SettleShippedExampleClaim(gateway: $gateway, marker: $this->marker, openRegister: new OpenRegisterAvailability($apps), logger: $logger))->run($output);

		return ['infos' => $output->infos, 'warnings' => $output->warnings];
	}//end runStep()

	/**
	 * What payroll pays for the claim in June 2026, in cents.
	 *
	 * @param array<string, mixed> $claim The claim.
	 *
	 * @return int
	 */
	private function paidCents(array $claim): int {
		$fold = new PayrollExpenseFoldService($this->createMock(RecordStoreInterface::class), new InternalWriteMarker());

		return $fold->foldFor(['expenses' => [$claim], 'allowances' => [], 'arrangementsById' => []], self::EMPLOYEE, '2026-06', 'run-1')['claimCents'];
	}//end paidCents()

	/**
	 * The unchanged shipped claim is reimbursed directly, as an internal
	 * write, logged; nothing else changes, and a second run writes nothing.
	 *
	 * @return void
	 */
	public function testTheUnchangedExampleClaimIsSettled(): void {
		self::assertSame(2740, $this->paidCents($this->store->state->objects['Expense']['ex-train']));

		$told = $this->runStep();

		$claim = $this->store->state->objects['Expense']['ex-train'];
		self::assertSame('reimbursed', $claim['status']);
		self::assertSame('direct', $claim['reimbursementRoute']);
		self::assertSame('2026-05-26T10:00:00Z', $claim['reimbursedAt']);
		self::assertSame(0, $this->paidCents($claim));
		self::assertCount(1, $this->store->state->saves);
		self::assertSame('ex-train', $this->store->state->saves[0]['uuid']);
		self::assertSame(['status' => 'reimbursed', 'reimbursementRoute' => 'direct', 'reimbursedAt' => '2026-05-26T10:00:00Z'], array_diff_assoc($this->store->state->saves[0]['payload'], array_diff_key(self::SHIPPED, ['@self' => true]) + ['id' => 'ex-train']));
		self::assertArrayNotHasKey('@self', $this->store->state->saves[0]['payload']);
		self::assertSame([true], $this->internal);
		self::assertSame('approved', $this->store->state->objects['Expense']['ex-other']['status']);
		self::assertSame([['notice', 'humaniq: shipped example claim settled', ['id' => 'ex-train']]], $this->logged);
		self::assertSame(['Settled the shipped example claim ex-train.'], $told['infos']);

		$again = $this->runStep();
		self::assertCount(1, $this->store->state->saves);
		self::assertSame(['The shipped example claim needs no change.'], $again['infos']);
	}//end testTheUnchangedExampleClaimIsSettled()

	/**
	 * A changed field, another employee, a payroll run or a missing employee
	 * leaves the claim alone.
	 *
	 * @return void
	 */
	public function testAChangedOrPaidClaimIsLeftAlone(): void {
		$cases = [
			'amount' => ['amount' => 27.41],
			'title' => ['title' => 'Treinreis'],
			'route' => ['reimbursementRoute' => 'direct'],
			'status' => ['status' => 'submitted'],
			'approvedAt' => ['approvedAt' => '2026-05-22T09:15:00Z'],
			'employee' => ['employeeId' => 'emp-other'],
			'run' => ['payrollRunId' => 'run-7'],
			'description' => ['description' => ''],
			'currency' => ['currency' => 'USD'],
			'category' => ['category' => 'meals'],
			'expenseDate' => ['expenseDate' => '2026-05-21'],
			'submittedAt' => ['submittedAt' => '2026-05-20T18:00:01Z'],
			'approvedBy' => ['approvedBy' => 'someone'],
		];
		foreach ($cases as $name => $change) {
			$this->store->state->objects['Expense']['ex-train'] = array_merge(self::SHIPPED, $change, ['id' => 'ex-train']);
			$this->store->state->saves = [];
			$told = $this->runStep();

			self::assertSame([], $this->store->state->saves, $name);
			self::assertSame(['The shipped example claim needs no change.'], $told['infos'], $name);
		}

		unset($this->store->state->objects['Expense']['ex-train']);
		self::assertSame(['The shipped example claim needs no change.'], $this->runStep()['infos']);
	}//end testAChangedOrPaidClaimIsLeftAlone()

	/**
	 * The amount compares in cents, so the shipped 27.40 stored as a string
	 * still matches.
	 *
	 * @return void
	 */
	public function testTheAmountComparesInCents(): void {
		$this->store->state->objects['Expense']['ex-train']['amount'] = '27.40';
		$this->runStep();

		self::assertSame('reimbursed', $this->store->state->objects['Expense']['ex-train']['status']);
	}//end testTheAmountComparesInCents()

	/**
	 * Without OpenRegister the step warns and writes nothing; a failure is
	 * warned and logged, never rethrown.
	 *
	 * @return void
	 */
	public function testItNeverAbortsTheUpgrade(): void {
		$told = $this->runStep(available: false);
		self::assertSame([], $this->store->state->saves);
		self::assertCount(1, $told['warnings']);

		$this->store->failSave = true;
		$failed = $this->runStep();
		self::assertSame([], $this->store->state->saves);
		self::assertSame('approved', $this->store->state->objects['Expense']['ex-train']['status']);
		self::assertSame(['Could not settle the shipped example claim: store down'], $failed['warnings']);
		self::assertSame([['error', 'humaniq: settling the shipped example claim failed', ['exception' => 'store down']]], $this->logged);
	}//end testItNeverAbortsTheUpgrade()

}//end class
