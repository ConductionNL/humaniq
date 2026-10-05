<?php

/**
 * Wage Tax Remittance Service Test
 *
 * The confirmed wage tax return of a payable payroll run becomes one draft
 * payable to the Belastingdienst in shillinq (payroll-wage-tax-remittance-shillinq).
 * The payload is validated against shillinq's real APTransaction schema and
 * the log record against humaniq's real WageTaxRemittance schema.
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

use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\WageTaxRemittanceService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for WageTaxRemittanceService.
 */
class WageTaxRemittanceServiceTest extends TestCase {

	/**
	 * The approved May run.
	 *
	 * @var string
	 */
	public const RUN_ID = '5b1d3f4e-0000-4000-8000-0000000000a1';

	/**
	 * The May return.
	 *
	 * @var string
	 */
	public const FILING_ID = '5b1d3f4e-0000-4000-8000-0000000000b1';

	/**
	 * The fake OpenRegister object store.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		self::seedMay($this->store);
	}//end setUp()

	/**
	 * Seed an approved May run, its confirmed return and shillinq's
	 * Belastingdienst payee.
	 *
	 * @param FakeObjectStore $store The store.
	 *
	 * @return void
	 */
	public static function seedMay(FakeObjectStore $store): void {
		$store->seed('PayrollRun', self::RUN_ID, ['period' => '2026-05', 'administrationId' => 'ADM-001', 'status' => 'approved', 'totalLoonheffing' => 1200.00]);
		$store->seed(
			'LoonaangifteFiling',
			self::FILING_ID,
			[
				'period' => '2026-05',
				'jurisdiction' => 'NL',
				'filingType' => 'loonaangifte',
				'status' => 'bevestigd',
				'deadline' => '2026-06-30',
				'aangiftenummer' => '000000000L016050',
				'betalingskenmerk' => '1234000000006050',
				'administrationId' => 'ADM-001',
				'messageRunId' => self::RUN_ID,
				'collectiveTotals' => ['IngLbPh' => '1200', 'TotPrAwfLg' => '300', 'TotPrAofHg' => '240', 'IngBijdrZvw' => '100', 'TotTeBet' => '1840', 'TotGen' => '1840'],
			]
		);
		$store->seed('Payee', 'payee-belastingdienst', ['vendorNumber' => 'BD', 'name' => 'Belastingdienst', 'payeeType' => 'government', 'paymentTermDays' => 0, 'administrationId' => 'ADM-001', 'lifecycleState' => 'active', 'bankAccount' => ['iban' => 'NL86INGB0002445588']]);
	}//end seedMay()

	/**
	 * The service on the fake store.
	 *
	 * @param FakeObjectStore $store    The store.
	 * @param bool            $shillinq Whether shillinq is installed.
	 * @param string          $payeeId  The configured payee.
	 *
	 * @return WageTaxRemittanceService
	 */
	private function serviceOn(FakeObjectStore $store, bool $shillinq=true, string $payeeId='payee-belastingdienst'): WageTaxRemittanceService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(static fn (string $appId): bool => ($appId === 'shillinq' && $shillinq));
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getWageTaxPayeeId')->willReturn($payeeId);
		$settings->method('getGlPostAccountWageTaxLiability')->willReturn('1701');

		return new WageTaxRemittanceService(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $store]),
			appManager: $appManager,
			settingsService: $settings,
			logger: new NullLogger(),
		);
	}//end serviceOn()

	/**
	 * A confirmed return becomes one draft APTransaction that fits shillinq's
	 * schema, and the log record fits humaniq's.
	 *
	 * @return void
	 */
	public function testAConfirmedReturnBecomesOneDraftPayableThatFitsShillinqsSchema(): void {
		$outcome = $this->serviceOn($this->store)->processPayableRuns('2026-05');

		self::assertSame('created', $outcome[0]['status']);
		$payables = $this->rowsOf('APTransaction');
		self::assertCount(1, $payables);
		$payable = $payables[0];
		self::assertSame(
			['draft', 'payee-belastingdienst', '1234000000006050', '000000000L016050', '2026-05-31', '2026-06-30', 'EUR', 1840.0, 'ADM-001'],
			[$payable['state'], $payable['vendorId'], $payable['invoiceNumber'], $payable['invoiceReference'], $payable['invoiceDate'], $payable['dueDate'], $payable['currency'], $payable['totalAmount'], $payable['administrationId']]
		);
		self::assertSame([['description' => 'Loonheffingen 2026-05, aangifte 000000000L016050', 'accountNumber' => '1701', 'amount' => 1840.0]], $payable['lines']);
		// What shillinq's BalanceGuard checks on issue: lines plus tax equal the total.
		self::assertSame((int)round($payable['totalAmount'] * 100), (int)round((array_sum(array_column($payable['lines'], 'amount')) + $payable['taxAmount']) * 100));

		$fixture = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/shillinq/ap-transaction-schema.json'), true);
		unset($payable['id']);
		self::assertSame([], RegisterSchemaValidator::errorsAgainst($fixture['APTransaction'], $payable));

		$record = $this->rowsOf('WageTaxRemittance')[0];
		self::assertSame(['created', self::RUN_ID, self::FILING_ID, 1840.0, '1234000000006050', '2026-06-30'], [$record['status'], $record['payrollRunId'], $record['filingId'], $record['amount'], $record['paymentReference'], $record['dueDate']]);
		self::assertNotSame('', (string)$record['shillinqPayableRef']);
		unset($record['id']);
		self::assertSame([], RegisterSchemaValidator::errors('WageTaxRemittance', $record));
	}//end testAConfirmedReturnBecomesOneDraftPayableThatFitsShillinqsSchema()

	/**
	 * The employee insurance premiums and the Zvw contribution are in the
	 * return's total and travel to the same creditor; nothing goes to UWV.
	 *
	 * @return void
	 */
	public function testThePremiumsArePaidWithTheWageTaxToOneCreditor(): void {
		$this->serviceOn($this->store)->processPayableRuns('2026-05');

		$payables = $this->rowsOf('APTransaction');
		self::assertSame(['payee-belastingdienst'], array_values(array_unique(array_column($payables, 'vendorId'))));
		// 1200 wage tax + 300 AWf + 240 Aof + 100 Zvw.
		self::assertSame(1840.0, $payables[0]['totalAmount']);
	}//end testThePremiumsArePaidWithTheWageTaxToOneCreditor()

	/**
	 * A return with nothing to pay writes no payable.
	 *
	 * @return void
	 */
	public function testNothingToPayWritesNoPayable(): void {
		$this->patchFiling(['collectiveTotals' => ['TotTeBet' => '0', 'TotGen' => '0']]);

		$outcome = $this->serviceOn($this->store)->processPayableRuns('2026-05');

		self::assertSame('nothing-to-pay', $outcome[0]['status']);
		self::assertSame([], $this->rowsOf('APTransaction'));
		self::assertSame('nothing-to-pay', $this->rowsOf('WageTaxRemittance')[0]['status']);
	}//end testNothingToPayWritesNoPayable()

	/**
	 * Without a payment reference nothing reaches shillinq.
	 *
	 * @return void
	 */
	public function testAMissingPaymentReferenceWritesNothing(): void {
		$this->patchFiling(['betalingskenmerk' => null]);

		$outcome = $this->serviceOn($this->store)->processPayableRuns('2026-05');

		self::assertSame('failed', $outcome[0]['status']);
		self::assertStringContainsString('payment reference', $outcome[0]['message']);
		self::assertSame([], $this->rowsOf('APTransaction'));
		$record = $this->rowsOf('WageTaxRemittance')[0];
		unset($record['id']);
		self::assertSame([], RegisterSchemaValidator::errors('WageTaxRemittance', $record));
	}//end testAMissingPaymentReferenceWritesNothing()

	/**
	 * An unset or unknown payee writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownPayeeWritesNothing(): void {
		$unset = $this->serviceOn($this->store, payeeId: '')->processPayableRuns('2026-05');
		$unknown = $this->serviceOn($this->store, payeeId: 'payee-nobody')->processPayableRuns('2026-05');

		self::assertSame(['failed', 'failed'], [$unset[0]['status'], $unknown[0]['status']]);
		self::assertStringContainsString('wagetax_payee_id', $unset[0]['message']);
		self::assertStringContainsString('payee-nobody', $unknown[0]['message']);
		self::assertSame([], $this->rowsOf('APTransaction'));
	}//end testAnUnknownPayeeWritesNothing()

	/**
	 * A return that is not confirmed yet is not paid and gets no record.
	 *
	 * @return void
	 */
	public function testADraftReturnIsNotPaidYet(): void {
		$this->patchFiling(['status' => 'klaargezet']);

		$outcome = $this->serviceOn($this->store)->processPayableRuns('2026-05');

		self::assertSame('no-return', $outcome[0]['status']);
		self::assertSame([], $this->rowsOf('APTransaction'));
		self::assertSame([], $this->rowsOf('WageTaxRemittance'));
	}//end testADraftReturnIsNotPaidYet()

	/**
	 * A second hand-off of the same return writes nothing new.
	 *
	 * @return void
	 */
	public function testASecondHandOffIsANoOp(): void {
		$service = $this->serviceOn($this->store);
		$service->processPayableRuns('2026-05');
		$again = $service->processPayableRuns('2026-05');

		self::assertSame('created', $again[0]['status']);
		self::assertCount(1, $this->rowsOf('APTransaction'));
		self::assertCount(1, $this->rowsOf('WageTaxRemittance'));
	}//end testASecondHandOffIsANoOp()

	/**
	 * A payable written before a crash, without its record, is adopted.
	 *
	 * @return void
	 */
	public function testAPayableWrittenBeforeACrashIsAdopted(): void {
		$this->store->seed('APTransaction', 'ap-earlier', ['vendorId' => 'payee-belastingdienst', 'invoiceNumber' => '1234000000006050', 'state' => 'draft', 'totalAmount' => 1840.0]);

		$outcome = $this->serviceOn($this->store)->processPayableRuns('2026-05');

		self::assertSame('created', $outcome[0]['status']);
		self::assertCount(1, $this->rowsOf('APTransaction'));
		self::assertSame('ap-earlier', $this->rowsOf('WageTaxRemittance')[0]['shillinqPayableRef']);
	}//end testAPayableWrittenBeforeACrashIsAdopted()

	/**
	 * Without shillinq the hand-off is logged as skipped.
	 *
	 * @return void
	 */
	public function testWithoutShillinqTheHandOffIsSkipped(): void {
		$outcome = $this->serviceOn($this->store, shillinq: false)->processPayableRuns('2026-05');

		self::assertSame('skipped-no-shillinq', $outcome[0]['status']);
		self::assertSame([], $this->rowsOf('APTransaction'));
		self::assertSame('skipped-no-shillinq', $this->rowsOf('WageTaxRemittance')[0]['status']);
	}//end testWithoutShillinqTheHandOffIsSkipped()

	/**
	 * Merge fields onto the May filing.
	 *
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return void
	 */
	private function patchFiling(array $fields): void {
		$filing = $this->rowsOf('LoonaangifteFiling')[0];
		$this->store->seed('LoonaangifteFiling', self::FILING_ID, array_merge($filing, $fields));
	}//end patchFiling()

	/**
	 * The stored rows of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rowsOf(string $schema): array {
		return array_values(array_map(static fn (mixed $row): array => (array)(is_array($row) === true ? $row : $row->jsonSerialize()), $this->store->setSchema($schema)->findAll()));
	}//end rowsOf()
}//end class
