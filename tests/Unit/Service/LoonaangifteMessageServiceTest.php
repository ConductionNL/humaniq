<?php

/**
 * LoonaangifteMessageServiceTest
 *
 * The wage tax return message is made from the approved run's payslips,
 * validated against the Belastingdienst's 2026 XSD and stored on the
 * filing; what stops it is a named finding per employee and element.
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DOMDocument;
use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\Loonaangifte\PayslipRecalculator;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\HoursRegisterGateway;
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

class LoonaangifteMessageServiceTest extends TestCase {

	private const NS = 'http://xml.belastingdienst.nl/schemas/Loonaangifte/2026/01';

	private FakeObjectStore $store;

	private LoonaangifteMessageService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->service = $this->serviceWithRelNr('SWO12345');

		$this->store->seed('hrAdministration', 'adm-1', ['administrationId' => 'ADM-001', 'name' => 'Conduction Demo B.V.', 'loonheffingennummer' => '111222333L01', 'aangifteContactName' => 'P. Salaris', 'aangifteContactPhone' => '020-1234567']);
		$this->store->seed('Employee', 'emp-jansen', ['firstName' => 'Anna Maria', 'lastName' => 'Jansen', 'bsn' => '123456782', 'dateOfBirth' => '1990-04-12', 'startDate' => '2024-02-01', 'employeeNumber' => 'E-001', 'straat' => 'Kerkstraat', 'huisnummer' => '12a', 'postcode' => '3511AB', 'woonplaats' => 'Utrecht', 'land' => 'NL', 'grossMonthlySalary' => 3800, 'administrationId' => 'ADM-001']);
		$this->store->seed('Employee', 'emp-bakker', ['firstName' => 'Kees', 'lastName' => 'Bakker', 'bsn' => '111222333', 'dateOfBirth' => '1985-09-30', 'startDate' => '2026-06-10', 'grossMonthlySalary' => 2400, 'administrationId' => 'ADM-001']);
		$this->store->seed('EmploymentContract', 'ctr-jansen', ['employeeId' => 'emp-jansen', 'type' => 'permanent', 'writtenContract' => true, 'hoursPerWeek' => 36, 'startDate' => '2024-02-01', 'administrationId' => 'ADM-001']);
		$this->store->seed('EmploymentContract', 'ctr-bakker', ['employeeId' => 'emp-bakker', 'type' => 'temporary', 'writtenContract' => true, 'hoursPerWeek' => 24, 'startDate' => '2026-06-10', 'endDate' => '2026-12-09', 'administrationId' => 'ADM-001']);
		$this->store->seed('PayrollRun', 'run-06', ['administrationId' => 'ADM-001', 'period' => '2026-06', 'status' => 'approved', 'calculatedAt' => '2026-06-24T10:00:00Z', 'totalLoonheffing' => 0.0]);
		$this->seedSlip('slip-jansen', 'emp-jansen', $this->snapshot(3800.00, true, 'low'), 'low');
		$this->seedSlip('slip-bakker', 'emp-bakker', $this->snapshot(2400.00, false, 'high'), 'high');
		$this->store->seed('LoonaangifteFiling', 'filing-06', ['period' => '2026-06', 'jurisdiction' => 'NL', 'filingType' => 'loonaangifte', 'tijdvak' => 'maand', 'tijdvakcode' => '6060', 'deadline' => '2026-07-31', 'status' => 'concept', 'administrationId' => 'ADM-001']);
	}//end setUp()

	/**
	 * An approved run renders a message that validates against the 2026 XSD,
	 * with the collective totals cut to whole euros and stored on the filing.
	 *
	 * @return void
	 */
	public function testAnApprovedRunRendersAValidMessage(): void {
		$outcome = $this->service->render($this->filing(), 'payroll-1');

		self::assertSame(['rendered', 0], [$outcome['status'], $outcome['blockingFindings']]);
		$filing = $this->filingRow();
		self::assertSame(['2.0', 'run-06', '2026-06-24T10:00:00Z', 'payroll-1'], [$filing['messageVersion'], $filing['messageRunId'], $filing['messageRunCalculatedAt'], $filing['messageRenderedBy']]);
		self::assertMatchesRegularExpression('/^LH_111222333L01_2026-06_[0-9]{14}\.xml$/', $filing['messageFileName']);

		$xml = new DOMDocument();
		$xml->loadXML($filing['messageXml']);
		self::assertTrue($xml->schemaValidateSource((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Standards/loonaangifte/Loonaangifte2026v2.0.xsd')));
		self::assertSame(['2026-06-01', '2026-06-30'], [$this->text($xml, 'DatAanvTv'), $this->text($xml, 'DatEindTv')]);
		self::assertSame(2, $xml->getElementsByTagNameNS(self::NS, 'InkomstenverhoudingInitieel')->length);

		$jansen = $this->slipRow('slip-jansen');
		$bakker = $this->slipRow('slip-bakker');
		$totals = $filing['collectiveTotals'];
		self::assertSame((int)floor((float)$jansen['grossPay'] + (float)$bakker['grossPay']), $totals['TotLnLbPh']);
		self::assertSame((int)floor((float)$jansen['loonheffing'] + (float)$bakker['loonheffing']), $totals['IngLbPh']);
		self::assertSame((int)floor((float)$jansen['zvw'] + (float)$bakker['zvw']), $totals['TotWghZvw']);
		// GS p55 condition 2315: the sum of the parts, and TotGen equals it without corrections (0011).
		$parts = ['IngLbPh', 'TotWghZvw', 'IngBijdrZvw', 'TotPrAofLg', 'TotPrAofHg', 'TotPrAofUit', 'TotOpslWko', 'TotPrGediffWhk', 'TotPrAwfLg', 'TotPrAwfHg', 'TotPrAwfHz', 'TotPrAwfUit', 'PrUFO'];
		self::assertSame(array_sum(array_map(static fn (string $k): int => $totals[$k], $parts)), $totals['TotTeBet']);
		self::assertSame($totals['TotTeBet'], $totals['TotGen']);
		self::assertSame((string)$totals['TotTeBet'], $this->text($xml, 'TotTeBet'));

		unset($filing['id']);
		self::assertSame([], RegisterSchemaValidator::errors('LoonaangifteFiling', $filing));
	}//end testAnApprovedRunRendersAValidMessage()

	/**
	 * The anchor employee's line carries the engine's own components
	 * (gross 3800: AWf low 2.74% = 104.12, Aof low 6.27% = 238.26, Wko 0.50%
	 * = 19.00, Whk 1.52% = 57.76, Zvw 6.10% = 231.80) and the codes of a
	 * permanent written contract on the white monthly table.
	 *
	 * @return void
	 */
	public function testTheAnchorEmployeeLine(): void {
		$this->service->render($this->filing(), 'payroll-1');
		$xml = new DOMDocument();
		$xml->loadXML($this->filingRow()['messageXml']);
		$line = $xml->getElementsByTagNameNS(self::NS, 'InkomstenverhoudingInitieel')->item(0);
		$get = static fn (string $name): string => (string)$line->getElementsByTagNameNS(self::NS, $name)->item(0)?->textContent;

		self::assertSame(['1', '2024-02-01', 'E-001', '123456782', 'AM', 'Jansen', '1990-04-12'], [$get('NumIV'), $get('DatAanv'), $get('PersNr'), $get('SofiNr'), $get('Voorl'), $get('SignNm'), $get('Gebdat')]);
		self::assertSame(['Kerkstraat', '12', 'a', '3511AB', 'Utrecht'], [$get('Str'), $get('HuisNr'), $get('HuisNrToev'), $get('Pc'), $get('Woonpl')]);
		self::assertSame(['15', '1', 'J', 'J', 'N', 'J', '012', 'J', 'J', 'J', 'K'], [$get('SrtIV'), $get('CdAard'), $get('IndArbovOnbepTd'), $get('IndSchriftArbov'), $get('IndOprov'), $get('IndLhKort'), $get('LbTab'), $get('IndWAO'), $get('IndWW'), $get('IndZW'), $get('CdZvw')]);
		self::assertSame(['3800.00', '3800.00', '3800.00', '0.00', '3800.00', '3800.00', '0.00'], [$get('LnLbPh'), $get('LnSV'), $get('PrlnAofAnwLg'), $get('PrlnAofAnwHg'), $get('PrlnWhkAnw'), $get('PrlnAwfAnwLg'), $get('PrlnAwfAnwHg')]);
		self::assertSame(['104.12', '0.00', '238.26', '19.00', '57.76', '231.80', '0.00'], [$get('PrAwfLg'), $get('PrAwfHg'), $get('PrAofLg'), $get('OpslWko'), $get('PrGediffWhk'), $get('WghZvw'), $get('BijdrZvw')]);
		self::assertSame(['718.83', '473.75', '304.00', '0.00', '156', '3800.00', '36'], [$get('IngLbPh'), $get('VerrArbKrt'), $get('OpgRchtVakBsl'), $get('VakBsl'), $get('AantVerlU'), $get('Ctrctln'), $get('AantCtrcturenPWk')]);
	}//end testTheAnchorEmployeeLine()

	/**
	 * A fixed-term starter: income period from the start date, the high AWf
	 * column, no loonheffingskorting.
	 *
	 * @return void
	 */
	public function testAFixedTermStarterLine(): void {
		$this->service->render($this->filing(), 'payroll-1');
		$xml = new DOMDocument();
		$xml->loadXML($this->filingRow()['messageXml']);
		$line = $xml->getElementsByTagNameNS(self::NS, 'InkomstenverhoudingInitieel')->item(1);
		$period = $line->getElementsByTagNameNS(self::NS, 'Inkomstenperiode')->item(0);
		$get = static fn (\DOMElement $in, string $name): string => (string)$in->getElementsByTagNameNS(self::NS, $name)->item(0)?->textContent;

		self::assertSame(['2026-06-10', 'N', 'N'], [$get($period, 'DatAanv'), $get($period, 'IndArbovOnbepTd'), $get($period, 'IndLhKort')]);
		self::assertSame(['0.00', '2400.00'], [$get($line, 'PrlnAwfAnwLg'), $get($line, 'PrlnAwfAnwHg')]);
		self::assertSame('185.76', $get($line, 'PrAwfHg'));
		self::assertSame(0, $line->getElementsByTagNameNS(self::NS, 'DatEind')->length, 'An end date after the period is not sent yet (GS p62).');
	}//end testAFixedTermStarterLine()

	/**
	 * The rendered message equals the hand-checked golden file.
	 *
	 * @return void
	 */
	public function testTheMessageMatchesTheGoldenFile(): void {
		$this->service->render($this->filing(), 'payroll-1');
		$message = (string)$this->filingRow()['messageXml'];
		$message = preg_replace('#<IdBer>[^<]+</IdBer>#', '<IdBer>LA2026-06X</IdBer>', $message);
		$message = preg_replace('#<DatTdAanm>[^<]+</DatTdAanm>#', '<DatTdAanm>2026-07-05T10:00:00</DatTdAanm>', (string)$message);
		$message = preg_replace('#<GebrSwPakket>[^<]+</GebrSwPakket>#', '<GebrSwPakket>humaniq</GebrSwPakket>', (string)$message);

		self::assertStringEqualsFile(dirname(__DIR__, 2) . '/fixtures/loonaangifte/loonaangifte-2026-06-adm-001.xml', (string)$message);
	}//end testTheMessageMatchesTheGoldenFile()

	/**
	 * Only a draft run for the period: no message, a blocking finding that
	 * names the run.
	 *
	 * @return void
	 */
	public function testADraftRunIsNotFiled(): void {
		$run = $this->store->find('run-06', schema: 'PayrollRun')->getObject();
		$this->store->seed('PayrollRun', 'run-06', array_merge($run, ['status' => 'draft']));

		$outcome = $this->service->render($this->filing(), 'payroll-1');

		self::assertSame(['blocked', 1], [$outcome['status'], $outcome['blockingFindings']]);
		$filing = $this->filingRow();
		self::assertSame('run-not-approved', $filing['messageFindings'][0]['kind']);
		self::assertStringContainsString('goedgekeurd', $filing['messageFindings'][0]['problem']);
		self::assertSame('', (string)($filing['messageXml'] ?? ''));
	}//end testADraftRunIsNotFiled()

	/**
	 * An employee without a BSN: the filing stays without a message and lists
	 * that employee with the missing citizen service number.
	 *
	 * @return void
	 */
	public function testAnEmployeeWithoutABsnIsANamedFinding(): void {
		$employee = $this->store->find('emp-bakker', schema: 'Employee')->getObject();
		unset($employee['bsn']);
		$this->store->seed('Employee', 'emp-bakker', $employee);

		$outcome = $this->service->render($this->filing(), 'payroll-1');

		self::assertSame(['blocked', 1], [$outcome['status'], $outcome['blockingFindings']]);
		$finding = $this->filingRow()['messageFindings'][0];
		self::assertSame(['emp-bakker', 'SofiNr', 'blocking'], [$finding['employeeId'], $finding['element'], $finding['severity']]);
		self::assertStringContainsString('Kees Bakker', $finding['problem']);
		self::assertSame('', (string)($this->filingRow()['messageXml'] ?? ''));

		$filing = $this->filingRow();
		unset($filing['id']);
		self::assertSame([], RegisterSchemaValidator::errors('LoonaangifteFiling', $filing));
	}//end testAnEmployeeWithoutABsnIsANamedFinding()

	/**
	 * The anonymous rate was applied: the line goes on table 940 and needs no
	 * BSN (GS p66 condition 2287, p94).
	 *
	 * @return void
	 */
	public function testTheAnonymousRateIsReportedOnTable940(): void {
		$employee = $this->store->find('emp-bakker', schema: 'Employee')->getObject();
		unset($employee['bsn']);
		$this->store->seed('Employee', 'emp-bakker', $employee);
		$slip = $this->store->find('slip-bakker', schema: 'Payslip')->getObject();
		$this->store->seed('Payslip', 'slip-bakker', array_merge($slip, ['anoniementariefApplied' => true]));

		$outcome = $this->service->render($this->filing(), 'payroll-1');

		self::assertSame('rendered', $outcome['status']);
		self::assertStringContainsString('<LbTab>940</LbTab>', (string)$this->filingRow()['messageXml']);
	}//end testTheAnonymousRateIsReportedOnTable940()

	/**
	 * The administration's contact, a valid tax number and the software
	 * relation number are required by the message header (GS p32-34).
	 *
	 * @return void
	 */
	public function testTheHeaderFindings(): void {
		$this->store->seed('hrAdministration', 'adm-1', ['administrationId' => 'ADM-001', 'name' => 'Conduction Demo B.V.', 'loonheffingennummer' => '123456789L01']);
		$this->service = $this->serviceWithRelNr('');

		$outcome = $this->service->render($this->filing(), 'payroll-1');

		self::assertSame('blocked', $outcome['status']);
		$elements = array_column($this->filingRow()['messageFindings'], 'element');
		sort($elements);
		self::assertSame(['ContPers', 'LhNr', 'RelNr', 'TelNr'], $elements);
	}//end testTheHeaderFindings()

	/**
	 * A payslip that the engine no longer reproduces, and one with a company
	 * car, are refused rather than reported with a guess.
	 *
	 * @return void
	 */
	public function testAPayslipThatDoesNotReproduceAndACompanyCarBlock(): void {
		$slip = $this->store->find('slip-jansen', schema: 'Payslip')->getObject();
		$this->store->seed('Payslip', 'slip-jansen', array_merge($slip, ['loonheffing' => 700.00]));
		$slip = $this->store->find('slip-bakker', schema: 'Payslip')->getObject();
		$this->store->seed('Payslip', 'slip-bakker', array_merge($slip, ['bijtelling' => 250.00]));

		$this->service->render($this->filing(), 'payroll-1');

		$kinds = array_column($this->filingRow()['messageFindings'], 'kind');
		sort($kinds);
		self::assertSame(['company-car-not-supported', 'payslip-not-reproducible'], $kinds);
	}//end testAPayslipThatDoesNotReproduceAndACompanyCarBlock()

	/**
	 * An ended employment needs its end reason (GS p65 condition 2501).
	 *
	 * @return void
	 */
	public function testAnEndedEmploymentNeedsItsReason(): void {
		$employee = $this->store->find('emp-jansen', schema: 'Employee')->getObject();
		$this->store->seed('Employee', 'emp-jansen', array_merge($employee, ['endDate' => '2026-06-30']));

		$this->service->render($this->filing(), 'payroll-1');
		self::assertSame(['emp-jansen', 'CdRdnEindArbov'], [$this->filingRow()['messageFindings'][0]['employeeId'], $this->filingRow()['messageFindings'][0]['element']]);

		$this->store->seed('Employee', 'emp-jansen', array_merge($employee, ['endDate' => '2026-06-30', 'endReason' => '20']));
		$outcome = $this->service->render($this->filing(), 'payroll-1');
		self::assertSame('rendered', $outcome['status']);
		self::assertStringContainsString('<DatEind>2026-06-30</DatEind><CdRdnEindArbov>20</CdRdnEindArbov>', str_replace(["\n", "\t", ' '], '', (string)$this->filingRow()['messageXml']));
	}//end testAnEndedEmploymentNeedsItsReason()

	/**
	 * A filing that is no longer a concept, or not a Dutch loonaangifte, is
	 * not rendered.
	 *
	 * @return void
	 */
	public function testOnlyAConceptDutchFilingIsRendered(): void {
		self::assertSame('refused-not-concept', $this->service->render(array_merge($this->filing(), ['status' => 'klaargezet']), 'payroll-1')['status']);
		self::assertSame('refused-not-nl', $this->service->render(array_merge($this->filing(), ['jurisdiction' => 'DE', 'filingType' => 'lohnsteuer-anmeldung']), 'payroll-1')['status']);
		self::assertSame('refused-no-specification', $this->service->render(array_merge($this->filing(), ['period' => '2031-06']), 'payroll-1')['status']);
	}//end testOnlyAConceptDutchFilingIsRendered()

	/**
	 * Re-rendering after a fix replaces the findings with the message.
	 *
	 * @return void
	 */
	public function testARenderAfterAFixReplacesTheFindings(): void {
		$employee = $this->store->find('emp-bakker', schema: 'Employee')->getObject();
		unset($employee['bsn']);
		$this->store->seed('Employee', 'emp-bakker', $employee);
		$this->service->render($this->filing(), 'payroll-1');

		$this->store->seed('Employee', 'emp-bakker', array_merge($employee, ['bsn' => '111222333']));
		$this->service->render($this->filingRow(), 'payroll-1');

		$filing = $this->filingRow();
		self::assertSame([[], 0], [$filing['messageFindings'], $filing['blockingFindings']]);
		self::assertNotSame('', (string)$filing['messageXml']);
		self::assertCount(1, $this->rowsOf('LoonaangifteFiling'));
	}//end testARenderAfterAFixReplacesTheFindings()

	/**
	 * The engine input of a payslip.
	 *
	 * @param float  $gross  The gross monthly wage.
	 * @param bool   $korting Whether the loonheffingskorting applies.
	 * @param string $awf    The AWf tariff (low|high).
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(float $gross, bool $korting, string $awf): array {
		return (new CalculationInput(
			grossMonthlySalaryCents: (int)round($gross * 100),
			taxTableColor: 'wit',
			loonheffingskortingToegepast: $korting,
			dateOfBirth: ($gross === 3800.00 ? '1990-04-12' : '1985-09-30'),
			period: '2026-06',
			awfTariff: $awf,
			aofTariff: 'laag',
			whkPercentage: 1.52
		))->toArray();
	}//end snapshot()

	/**
	 * Seed a payslip exactly as the run writes it from the engine's result.
	 *
	 * @param string               $uuid       The payslip id.
	 * @param string               $employeeId The employee.
	 * @param array<string, mixed> $snapshot   The engine input.
	 * @param string               $awf        The AWf tariff.
	 *
	 * @return void
	 */
	private function seedSlip(string $uuid, string $employeeId, array $snapshot, string $awf): void {
		$result = (new PayrollCalculator())->calculate(CalculationInput::fromDecoded($snapshot), TaxTables::load('nl-2026'));
		$this->store->seed('Payslip', $uuid, [
			'employeeId' => $employeeId,
			'payrollRunId' => 'run-06',
			'period' => '2026-06',
			'jurisdiction' => 'NL',
			'grossPay' => $result->grossPayCents / 100,
			'loonheffing' => $result->loonheffingCents / 100,
			'arbeidskorting' => $result->arbeidskortingCents / 100,
			'volksverzekeringen' => $result->volksverzekeringenCents / 100,
			'werknemersverzekeringen' => $result->werknemersverzekeringenCents / 100,
			'zvw' => $result->zvwCents / 100,
			'zvwMode' => 'werkgeversheffing',
			'vakantiegeldReserved' => $result->vakantiegeldReservedCents / 100,
			'anoniementariefApplied' => false,
			'awfTariff' => $awf,
			'engineInputSnapshot' => $snapshot,
			'administrationId' => 'ADM-001',
		]);
	}//end seedSlip()

	/**
	 * The service with a given software relation number.
	 *
	 * @param string $relNr The relation number.
	 *
	 * @return LoonaangifteMessageService
	 */
	private function serviceWithRelNr(string $relNr): LoonaangifteMessageService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($relNr);

		return new LoonaangifteMessageService(gateway: $gateway, recalculator: new PayslipRecalculator(new PayrollCalculator(), new NullLogger()), appConfig: $appConfig);
	}//end serviceWithRelNr()

	/**
	 * The seeded filing with its id.
	 *
	 * @return array<string, mixed>
	 */
	private function filing(): array {
		return array_merge($this->store->find('filing-06', schema: 'LoonaangifteFiling')->getObject(), ['id' => 'filing-06']);
	}//end filing()

	/**
	 * The stored filing.
	 *
	 * @return array<string, mixed>
	 */
	private function filingRow(): array {
		return array_merge($this->store->find('filing-06', schema: 'LoonaangifteFiling')->getObject(), ['id' => 'filing-06']);
	}//end filingRow()

	/**
	 * A stored payslip.
	 *
	 * @param string $uuid The payslip id.
	 *
	 * @return array<string, mixed>
	 */
	private function slipRow(string $uuid): array {
		return $this->store->find($uuid, schema: 'Payslip')->getObject();
	}//end slipRow()

	/**
	 * The text of the first element of a name.
	 *
	 * @param DOMDocument $xml  The message.
	 * @param string      $name The element name.
	 *
	 * @return string
	 */
	private function text(DOMDocument $xml, string $name): string {
		return (string)$xml->getElementsByTagNameNS(self::NS, $name)->item(0)?->textContent;
	}//end text()

	/**
	 * The rows of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rowsOf(string $schema): array {
		$this->store->setSchema($schema);
		return $this->store->findAll();
	}//end rowsOf()

}//end class
