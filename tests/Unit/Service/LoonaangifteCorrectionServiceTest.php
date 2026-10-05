<?php

/**
 * LoonaangifteCorrectionServiceTest
 *
 * A sent wage tax return is corrected by a linked new filing that carries
 * only what changed: inside the year it travels with the next return
 * (Gegevensspecificaties 2026, 2.4.1), for a closed year it is its own
 * correction message (2.4.3).
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
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DOMDocument;
use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\Loonaangifte\PayslipRecalculator;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use DateTimeImmutable;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\LoonaangifteCorrectionService;
use OCA\Humaniq\Service\LoonaangifteMessageService;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LoonaangifteCorrectionServiceTest extends TestCase {

	private const NS = 'http://xml.belastingdienst.nl/schemas/Loonaangifte/2026/01';

	private const XSD = '/lib/Standards/loonaangifte/Loonaangifte2026v2.0.xsd';

	private FakeObjectStore $store;

	private LoonaangifteMessageService $messages;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('hrAdministration', 'adm-1', ['administrationId' => 'ADM-001', 'name' => 'Conduction Demo B.V.', 'loonheffingennummer' => '111222333L01', 'aangifteContactName' => 'P. Salaris', 'aangifteContactPhone' => '020-1234567']);
		$this->store->seed('Employee', 'emp-jansen', ['firstName' => 'Anna', 'lastName' => 'Jansen', 'bsn' => '123456782', 'dateOfBirth' => '1990-04-12', 'startDate' => '2024-02-01', 'grossMonthlySalary' => 3800, 'administrationId' => 'ADM-001']);
		$this->store->seed('Employee', 'emp-bakker', ['firstName' => 'Kees', 'lastName' => 'Bakker', 'bsn' => '111222333', 'dateOfBirth' => '1985-09-30', 'startDate' => '2026-01-01', 'grossMonthlySalary' => 2400, 'administrationId' => 'ADM-001']);
		$this->store->seed('EmploymentContract', 'ctr-jansen', ['employeeId' => 'emp-jansen', 'type' => 'permanent', 'writtenContract' => true, 'hoursPerWeek' => 36, 'startDate' => '2024-02-01']);
		$this->store->seed('EmploymentContract', 'ctr-bakker', ['employeeId' => 'emp-bakker', 'type' => 'permanent', 'writtenContract' => true, 'hoursPerWeek' => 24, 'startDate' => '2026-01-01']);
		foreach (['03' => '2026-04-10T10:00:00Z', '04' => '2026-05-10T10:00:00Z'] as $month => $at) {
			$this->store->seed('PayrollRun', 'run-' . $month, ['administrationId' => 'ADM-001', 'period' => '2026-' . $month, 'status' => 'approved', 'calculatedAt' => $at, 'totalLoonheffing' => 0.0]);
			$this->seedSlip('slip-jansen-' . $month, 'run-' . $month, 'emp-jansen', '2026-' . $month, 3800.00, true);
			$this->seedSlip('slip-bakker-' . $month, 'run-' . $month, 'emp-bakker', '2026-' . $month, 2400.00, false);
		}

		$this->store->seed('LoonaangifteFiling', 'filing-03', ['period' => '2026-03', 'jurisdiction' => 'NL', 'filingType' => 'loonaangifte', 'tijdvak' => 'maand', 'tijdvakcode' => '6030', 'deadline' => '2026-04-30', 'status' => 'concept', 'administrationId' => 'ADM-001']);
		$this->store->seed('LoonaangifteFiling', 'filing-04', ['period' => '2026-04', 'jurisdiction' => 'NL', 'filingType' => 'loonaangifte', 'tijdvak' => 'maand', 'tijdvakcode' => '6040', 'deadline' => '2026-05-31', 'status' => 'concept', 'administrationId' => 'ADM-001']);

		$this->messages = $this->messageService();
		self::assertSame('rendered', $this->messages->render($this->row('filing-03'), 'payroll-1')['status']);
		$sent = $this->row('filing-03');
		unset($sent['id']);
		$this->store->seed('LoonaangifteFiling', 'filing-03', array_merge($sent, ['status' => 'verzonden', 'submittedDate' => '2026-04-20']));
	}//end setUp()

	/**
	 * Correct on a sent filing makes a new concept filing for the same period
	 * that names the sent one; the sent filing keeps its status and file.
	 *
	 * @return void
	 */
	public function testCorrectingASentReturnOpensALinkedCorrection(): void {
		$before = $this->row('filing-03');
		$outcome = $this->service('2026-05-15')->open($before, 'payroll-1');

		self::assertSame('opened', $outcome['status']);
		$correction = $this->row($outcome['filingId']);
		self::assertSame(['correctie', 'filing-03', '2026-03', 'concept', 'volgende-aangifte', 'ADM-001'], [$correction['filingType'], $correction['corrects'], $correction['period'], $correction['status'], $correction['correctionRoute'], $correction['administrationId']]);
		self::assertSame($before, $this->row('filing-03'));

		$again = $this->service('2026-05-15')->open($before, 'payroll-1');
		self::assertSame(['exists', $outcome['filingId']], [$again['status'], $again['filingId']]);

		unset($correction['id']);
		self::assertSame([], RegisterSchemaValidator::errors('LoonaangifteFiling', $correction));
	}//end testCorrectingASentReturnOpensALinkedCorrection()

	/**
	 * Only a sent Dutch return can be corrected.
	 *
	 * @return void
	 */
	public function testOnlyASentReturnCanBeCorrected(): void {
		self::assertSame('refused-not-sent', $this->service('2026-05-15')->open($this->row('filing-04'), 'payroll-1')['status']);
		self::assertSame('refused-not-nl', $this->service('2026-05-15')->open(array_merge($this->row('filing-03'), ['jurisdiction' => 'DE']), 'payroll-1')['status']);
	}//end testOnlyASentReturnCanBeCorrected()

	/**
	 * One employee's retro pay rise: one correction line, for that employee,
	 * with the changed amounts, the new collective stand and the saldo
	 * against the sent return (GS p23, p56).
	 *
	 * @return void
	 */
	public function testOneEmployeesRetroRiseYieldsOneLine(): void {
		$correctionId = $this->service('2026-05-15')->open($this->row('filing-03'), 'payroll-1')['filingId'];
		$this->seedSlip('slip-bakker-03', 'run-03', 'emp-bakker', '2026-03', 2600.00, false);

		$outcome = $this->service('2026-05-15')->render($this->row($correctionId), 'payroll-1');

		self::assertSame(['prepared', 0], [$outcome['status'], $outcome['blockingFindings']]);
		$correction = $this->row($correctionId);
		self::assertCount(1, $correction['correctionLines']);
		$line = $correction['correctionLines'][0];
		self::assertSame(['emp-bakker', 'changed'], [$line['employeeId'], $line['kind']]);
		self::assertSame(['2400.00', '2600.00'], [$line['changes']['LnLbPh']['old'], $line['changes']['LnLbPh']['new']]);
		$sentTotal = $this->row('filing-03')['collectiveTotals']['TotTeBet'];
		self::assertSame($correction['collectiveTotals']['TotTeBet'] - $sentTotal, $correction['correctionSaldo']);
		self::assertGreaterThan(0, $correction['correctionSaldo']);
		self::assertSame(['2026-03-01', '2026-03-31'], [$correction['correctionTree']['DatAanvTv'], $correction['correctionTree']['DatEindTv']]);
		self::assertCount(1, $correction['correctionTree']['InkomstenverhoudingInitieel']);
		self::assertArrayNotHasKey('TotGen', $correction['correctionTree']['CollectieveAangifte']);

		unset($correction['id']);
		self::assertSame([], RegisterSchemaValidator::errors('LoonaangifteFiling', $correction));
	}//end testOneEmployeesRetroRiseYieldsOneLine()

	/**
	 * Nothing changed since the return was sent: refused with that finding.
	 *
	 * @return void
	 */
	public function testNothingToCorrectIsRefused(): void {
		$correctionId = $this->service('2026-05-15')->open($this->row('filing-03'), 'payroll-1')['filingId'];

		$outcome = $this->service('2026-05-15')->render($this->row($correctionId), 'payroll-1');

		self::assertSame(['blocked', 1], [$outcome['status'], $outcome['blockingFindings']]);
		self::assertSame('nothing-to-correct', $this->row($correctionId)['messageFindings'][0]['kind']);
	}//end testNothingToCorrectIsRefused()

	/**
	 * An employee who should not have been reported is withdrawn
	 * (InkomstenverhoudingIntrekking, GS p22).
	 *
	 * @return void
	 */
	public function testAnEmployeeNoLongerPaidIsWithdrawn(): void {
		$correctionId = $this->service('2026-05-15')->open($this->row('filing-03'), 'payroll-1')['filingId'];
		$this->store->deleteObject('slip-bakker-03', 'humaniq', 'Payslip');

		$this->service('2026-05-15')->render($this->row($correctionId), 'payroll-1');

		$correction = $this->row($correctionId);
		self::assertSame(['emp-bakker', 'withdrawn'], [$correction['correctionLines'][0]['employeeId'] ?? '', $correction['correctionLines'][0]['kind']]);
		self::assertSame([['NumIV' => '1', 'SofiNr' => '111222333']], $correction['correctionTree']['InkomstenverhoudingIntrekking']);
	}//end testAnEmployeeNoLongerPaidIsWithdrawn()

	/**
	 * A correction for a closed year is its own message: only the corrected
	 * period, no return, validated against that year's XSD (GS 2.4.3).
	 *
	 * @return void
	 */
	public function testAClosedYearCorrectionIsItsOwnMessage(): void {
		$correctionId = $this->service('2027-02-01')->open($this->row('filing-03'), 'payroll-1')['filingId'];
		self::assertSame('correctiebericht', $this->row($correctionId)['correctionRoute']);
		$this->seedSlip('slip-bakker-03', 'run-03', 'emp-bakker', '2026-03', 2600.00, false);

		$outcome = $this->service('2027-02-01')->render($this->row($correctionId), 'payroll-1');

		self::assertSame('prepared', $outcome['status']);
		$xml = new DOMDocument();
		$xml->loadXML($this->row($correctionId)['messageXml']);
		self::assertTrue($xml->schemaValidate(dirname(__DIR__, 3) . self::XSD));
		self::assertSame([1, 0, 1], [$xml->getElementsByTagNameNS(self::NS, 'TijdvakCorrectie')->length, $xml->getElementsByTagNameNS(self::NS, 'TijdvakAangifte')->length, $xml->getElementsByTagNameNS(self::NS, 'InkomstenverhoudingInitieel')->length]);
	}//end testAClosedYearCorrectionIsItsOwnMessage()

	/**
	 * A correction made ready inside the year travels with the next return:
	 * the April message holds the March TijdvakCorrectie and its saldo, and
	 * TotGen is TotTeBet plus the saldo (GS p23-24, p55 0011).
	 *
	 * @return void
	 */
	public function testTheNextReturnCarriesTheCorrection(): void {
		$correctionId = $this->service('2026-05-15')->open($this->row('filing-03'), 'payroll-1')['filingId'];
		$this->seedSlip('slip-bakker-03', 'run-03', 'emp-bakker', '2026-03', 2600.00, false);
		$this->service('2026-05-15')->render($this->row($correctionId), 'payroll-1');
		$correction = $this->row($correctionId);
		unset($correction['id']);
		$this->store->seed('LoonaangifteFiling', $correctionId, array_merge($correction, ['status' => 'klaargezet']));

		self::assertSame('rendered', $this->messages->render($this->row('filing-04'), 'payroll-1')['status']);

		$april = $this->row('filing-04');
		$xml = new DOMDocument();
		$xml->loadXML($april['messageXml']);
		self::assertTrue($xml->schemaValidate(dirname(__DIR__, 3) . self::XSD));
		$saldo = $this->row($correctionId)['correctionSaldo'];
		self::assertSame((string)$saldo, $xml->getElementsByTagNameNS(self::NS, 'Saldo')->item(0)->textContent);
		self::assertSame($april['collectiveTotals']['TotTeBet'] + $saldo, $april['collectiveTotals']['TotGen']);
		self::assertSame('2026-03-01', $xml->getElementsByTagNameNS(self::NS, 'TijdvakCorrectie')->item(0)->getElementsByTagNameNS(self::NS, 'DatAanvTv')->item(0)->textContent);
		self::assertSame(['filing-04', [$correctionId]], [$this->row($correctionId)['carriedBy'], $april['carriedCorrectionIds']]);
	}//end testTheNextReturnCarriesTheCorrection()

	/**
	 * A sent return that has no message (filed before humaniq made them) has
	 * nothing to compare against.
	 *
	 * @return void
	 */
	public function testABaselineWithoutAMessageBlocks(): void {
		$sent = $this->row('filing-03');
		unset($sent['id'], $sent['messageXml']);
		$this->store->seed('LoonaangifteFiling', 'filing-03', $sent);
		$correctionId = $this->service('2026-05-15')->open($this->row('filing-03'), 'payroll-1')['filingId'];

		$this->service('2026-05-15')->render($this->row($correctionId), 'payroll-1');

		self::assertSame('baseline-without-message', $this->row($correctionId)['messageFindings'][0]['kind']);
	}//end testABaselineWithoutAMessageBlocks()

	/**
	 * Making a correction is refused when it is no longer a concept or its
	 * year has no specification, and blocks without an approved run; a
	 * yearly filer's correction is always its own message (GS 2.4.2).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public function testTheEdgesOfMakingACorrection(): void {
		$service = $this->service('2026-05-15');
		$correctionId = $service->open($this->row('filing-03'), 'payroll-1')['filingId'];
		$correction = $this->row($correctionId);

		self::assertSame('refused-not-concept', $service->render(array_merge($correction, ['status' => 'klaargezet']), 'payroll-1')['status']);
		self::assertSame('refused-no-specification', $service->render(array_merge($correction, ['period' => '2019-03']), 'payroll-1')['status']);

		$this->store->seed('PayrollRun', 'run-03', ['administrationId' => 'ADM-001', 'period' => '2026-03', 'status' => 'draft', 'calculatedAt' => '2026-04-10T10:00:00Z', 'totalLoonheffing' => 0.0]);
		self::assertSame('blocked', $service->render($correction, 'payroll-1')['status']);
		self::assertSame('run-not-approved', $this->row($correctionId)['messageFindings'][0]['kind']);

		$yearly = array_merge($this->row('filing-03'), ['tijdvak' => 'jaar']);
		$this->store->seed('LoonaangifteFiling', 'filing-03', array_diff_key($yearly, ['id' => true]));
		$this->store->seed('LoonaangifteFiling', $correctionId, array_merge(array_diff_key($correction, ['id' => true]), ['status' => 'verzonden']));
		$other = $service->open($this->row('filing-03'), 'payroll-1');
		self::assertSame('opened', $other['status']);
		self::assertSame('correctiebericht', $this->row($other['filingId'])['correctionRoute']);
	}//end testTheEdgesOfMakingACorrection()

	/**
	 * The correction service on a given day.
	 *
	 * @param string $today The day.
	 *
	 * @return LoonaangifteCorrectionService
	 */
	private function service(string $today): LoonaangifteCorrectionService {
		return new LoonaangifteCorrectionService(gateway: $this->gateway(), messages: $this->messages, logger: new NullLogger(), today: new DateTimeImmutable($today));
	}//end service()

	/**
	 * The message service.
	 *
	 * @return LoonaangifteMessageService
	 */
	private function messageService(): LoonaangifteMessageService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('SWO12345');
		return new LoonaangifteMessageService(gateway: $this->gateway(), recalculator: new PayslipRecalculator(new PayrollCalculator(), new NullLogger()), appConfig: $appConfig);
	}//end messageService()

	/**
	 * The register gateway over the fake store.
	 *
	 * @return HoursRegisterGateway
	 */
	private function gateway(): HoursRegisterGateway {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		return new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
	}//end gateway()

	/**
	 * Seed a payslip exactly as the run writes it from the engine's result.
	 *
	 * @param string $uuid       The payslip id.
	 * @param string $runId      The run.
	 * @param string $employeeId The employee.
	 * @param string $period     The period.
	 * @param float  $gross      The gross wage.
	 * @param bool   $korting    Whether the loonheffingskorting applies.
	 *
	 * @return void
	 */
	private function seedSlip(string $uuid, string $runId, string $employeeId, string $period, float $gross, bool $korting): void {
		$snapshot = (new CalculationInput(grossMonthlySalaryCents: (int)round($gross * 100), taxTableColor: 'wit', loonheffingskortingToegepast: $korting, dateOfBirth: '1988-01-01', period: $period, awfTariff: 'low', aofTariff: 'laag', whkPercentage: 1.52))->toArray();
		$result = (new PayrollCalculator())->calculate(CalculationInput::fromDecoded($snapshot), TaxTables::load('nl-2026'));
		$this->store->seed('Payslip', $uuid, [
			'employeeId' => $employeeId,
			'payrollRunId' => $runId,
			'period' => $period,
			'grossPay' => $result->grossPayCents / 100,
			'loonheffing' => $result->loonheffingCents / 100,
			'arbeidskorting' => $result->arbeidskortingCents / 100,
			'zvw' => $result->zvwCents / 100,
			'zvwMode' => 'werkgeversheffing',
			'vakantiegeldReserved' => $result->vakantiegeldReservedCents / 100,
			'awfTariff' => 'low',
			'engineInputSnapshot' => $snapshot,
		]);
	}//end seedSlip()

	/**
	 * A stored filing with its id.
	 *
	 * @param string $uuid The filing.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $uuid): array {
		return array_merge($this->store->find($uuid, schema: 'LoonaangifteFiling')->getObject(), ['id' => $uuid]);
	}//end row()

}//end class
