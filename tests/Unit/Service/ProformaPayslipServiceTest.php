<?php

/**
 * Unit tests for ProformaPayslipService.
 *
 * Pins the proforma-payslip contract (design.md D1/D2/D3/D7): the anchor
 * input reproduces the payroll-core-engine's hand-computed €3.081,17 net
 * digit-for-digit, a `groen` case drops arbeidskorting, an AOW-age
 * `dateOfBirth` takes the reduced path, a part-time factor scales the gross
 * before the same chain, a one-off bijzondere beloning is folded in as a
 * combined-loon estimate, malformed input throws `\InvalidArgumentException`
 * (the controller's 400 source), and the service is a stateless wrapper that
 * never touches OpenRegister's ObjectService — the constructor takes no
 * ObjectService/container dependency at all, and two identical calls return
 * identical figures (persists nothing, REQ-PRO-001).
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
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-001
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Service\ProformaPayslipService;
use OCA\Hrmq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProformaPayslipService.
 *
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-001
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-006
 */
class ProformaPayslipServiceTest extends TestCase {

	/**
	 * design.md D7 / REQ-PRO-006 anchor scenario: reproduces the
	 * payroll-core-engine design.md D2 hand-computed figures exactly.
	 *
	 * @return void
	 */
	public function testAnchorInputReproducesTheEngineNetExactly(): void {
		$service = $this->buildService();

		$breakdown = $service->simulate(
			[
				'gross' => 3800,
				'table' => 'wit',
				'loonheffingskorting' => true,
				'dateOfBirth' => '1990-04-12',
				'period' => '2026-02',
				'parttime' => 1.0,
				'bijzonder' => 0,
				'aof' => 'laag',
				'whk' => 1.52,
			]
		);

		$this->assertSame(718.83, $breakdown['loonheffing']);
		$this->assertSame(473.75, $breakdown['arbeidskorting']);
		$this->assertSame(231.80, $breakdown['zvw']);
		$this->assertSame(419.14, $breakdown['werknemersverzekeringen']);
		$this->assertSame(304.00, $breakdown['vakantiegeldReserved']);
		$this->assertSame(3081.17, $breakdown['nettoPay']);

	}//end testAnchorInputReproducesTheEngineNetExactly()

	/**
	 * REQ-PRO-006 scenario: a part-time factor scales the gross before the
	 * same chain runs — `grossMonthlySalaryCents` reflects €1.900,00.
	 *
	 * @return void
	 */
	public function testParttimeFactorScalesGrossBeforeTheSameChain(): void {
		$service = $this->buildService();

		$breakdown = $service->simulate(
			[
				'gross' => 3800,
				'table' => 'wit',
				'dateOfBirth' => '1990-04-12',
				'period' => '2026-02',
				'parttime' => 0.5,
				'aof' => 'laag',
				'whk' => 1.52,
			]
		);

		$this->assertSame(1900.0, $breakdown['grossPay']);

	}//end testParttimeFactorScalesGrossBeforeTheSameChain()

	/**
	 * REQ-PRO-006 scenario: a one-off bijzondere beloning is folded into the
	 * period gross as a combined-loon estimate (€3.800 + €1.000 = €4.800),
	 * explicitly labelled as NOT the statutory bijzonder tarief.
	 *
	 * @return void
	 */
	public function testBijzondereBeloningIsACombinedLoonEstimate(): void {
		$service = $this->buildService();

		$breakdown = $service->simulate(
			[
				'gross' => 3800,
				'table' => 'wit',
				'dateOfBirth' => '1990-04-12',
				'period' => '2026-02',
				'parttime' => 1.0,
				'bijzonder' => 1000,
				'aof' => 'laag',
				'whk' => 1.52,
			]
		);

		$this->assertSame(4800.0, $breakdown['grossPay']);
		$this->assertStringContainsString('combinedLoon', $breakdown['bijzondereBeloningNote']);
		$this->assertStringContainsString('bijzonder tarief', $breakdown['bijzondereBeloningNote']);

	}//end testBijzondereBeloningIsACombinedLoonEstimate()

	/**
	 * REQ-PCE-parity scenario carried into proforma: groene tabel applies no
	 * arbeidskorting.
	 *
	 * @return void
	 */
	public function testGroeneTabelDropsArbeidskorting(): void {
		$service = $this->buildService();

		$breakdown = $service->simulate(
			[
				'gross' => 3800,
				'table' => 'groen',
				'dateOfBirth' => '1990-04-12',
				'period' => '2026-02',
				'aof' => 'laag',
				'whk' => 1.52,
			]
		);

		$this->assertSame(0.0, $breakdown['arbeidskorting']);
		$this->assertGreaterThan(0.0, $breakdown['loonheffing']);

	}//end testGroeneTabelDropsArbeidskorting()

	/**
	 * An AOW-age `dateOfBirth` (relative to the run period) takes the
	 * calculator's own reduced AOW path — no code branch in this service,
	 * the raw date is passed straight through (design.md D2).
	 *
	 * @return void
	 */
	public function testAowAgeDateOfBirthTakesTheReducedPath(): void {
		$service = $this->buildService();

		$breakdown = $service->simulate(
			[
				'gross' => 3800,
				'table' => 'wit',
				'dateOfBirth' => '1955-01-01',
				'period' => '2026-02',
				'aof' => 'laag',
				'whk' => 1.52,
			]
		);

		// AOW-age below the below-AOW anchor's loonheffing: no AOW/ANW
		// volksverzekeringen component and the OUK korting applies, so the
		// figures MUST differ from the below-AOW anchor case.
		$this->assertNotSame(718.83, $breakdown['loonheffing']);

	}//end testAowAgeDateOfBirthTakesTheReducedPath()

	/**
	 * REQ-PRO-002 malformed-input scenario: a non-numeric gross throws
	 * (the controller's 400 source).
	 *
	 * @return void
	 */
	public function testNonNumericGrossThrows(): void {
		$service = $this->buildService();

		$this->expectException(\InvalidArgumentException::class);
		$service->simulate(['gross' => 'n/a']);

	}//end testNonNumericGrossThrows()

	/**
	 * An unknown table colour throws.
	 *
	 * @return void
	 */
	public function testUnknownTableColourThrows(): void {
		$service = $this->buildService();

		$this->expectException(\InvalidArgumentException::class);
		$service->simulate(['gross' => 3800, 'table' => 'blauw']);

	}//end testUnknownTableColourThrows()

	/**
	 * REQ-PRO-001: the service holds no state between calls and is
	 * deterministic — every figure comes from `PayrollCalculator::calculate()`,
	 * no tax parameter is computed in the proforma service itself.
	 *
	 * @return void
	 */
	public function testSimulateIsStatelessAndDeterministic(): void {
		$service = $this->buildService();

		$input = [
			'gross' => 3800,
			'table' => 'wit',
			'dateOfBirth' => '1990-04-12',
			'period' => '2026-02',
			'aof' => 'laag',
			'whk' => 1.52,
		];

		$first = $service->simulate($input);
		$second = $service->simulate($input);

		$this->assertSame($first, $second);

	}//end testSimulateIsStatelessAndDeterministic()

	/**
	 * REQ-PRO-001 "Computing a simulation writes no object" scenario: the
	 * service is constructed from exactly `PayrollCalculator` +
	 * `SettingsService` — NO ObjectService or DI container dependency is
	 * declared, so it is structurally incapable of writing an object.
	 *
	 * @return void
	 */
	public function testConstructorDeclaresNoObjectServiceDependency(): void {
		$reflection = new \ReflectionClass(ProformaPayslipService::class);
		$constructor = $reflection->getConstructor();
		$this->assertNotNull($constructor);

		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			$typeName = ($type instanceof \ReflectionNamedType) ? $type->getName() : '';
			$this->assertStringNotContainsStringIgnoringCase('ObjectService', $typeName);
			$this->assertStringNotContainsStringIgnoringCase('ContainerInterface', $typeName);
		}

	}//end testConstructorDeclaresNoObjectServiceDependency()

	/**
	 * Build a `ProformaPayslipService` with a mocked `SettingsService`
	 * (the real `nl-2026` `TaxTables` load is exercised for real — no fake
	 * of the engine under test).
	 *
	 * @return ProformaPayslipService
	 */
	private function buildService(): ProformaPayslipService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getPayrollAofTariff')->willReturn('laag');
		$settings->method('getPayrollWhkPercentage')->willReturnArgument(0);

		return new ProformaPayslipService(new PayrollCalculator(), $settings);
	}//end buildService()

}//end class
