<?php

/**
 * Characterisation tests for how PayrollAuditVerificationService and
 * PayrollReproduceService read register rows.
 *
 * Pins for both: rows are read past the per-field strip (the second findAll
 * argument), entity rows are normalised through jsonSerialize() or
 * getObject(), anything else becomes an empty row, ids resolve from `id` or
 * `@self.id`, an empty id is never looked up, and a failing read logs a
 * warning naming the service and yields no rows.
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Service\PayrollAuditVerificationService;
use OCA\Humaniq\Service\PayrollReproduceService;
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Both payroll services read rows the same way.
 *
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
 */
class PayrollRowReadingTest extends TestCase {

	/**
	 * The services under test, by name.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function services(): array {
		return [
			'audit verification' => ['PayrollAuditVerificationService'],
			'reproduce' => ['PayrollReproduceService'],
		];

	}//end services()

	/**
	 * Rows normalise to arrays and resolve by `id` or `@self.id`; the read skips the field strip.
	 *
	 * @param string $name The service.
	 *
	 * @return void
	 *
	 * @dataProvider services
	 */
	public function testRowsNormaliseAndResolveById(string $name): void {
		$serialisable = new class implements \JsonSerializable {

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'ps-json', 'gross' => 1];
			}
		};
		$entity = new class {

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['@self' => ['id' => 'ps-entity'], 'gross' => 2];
			}
		};
		$fake = $this->objectService([['id' => 'ps-array', 'gross' => 0], $serialisable, $entity, 'tekst', 42]);
		[$service] = $this->service($name, $fake, null);

		$this->assertSame(
			[['id' => 'ps-array', 'gross' => 0], ['id' => 'ps-json', 'gross' => 1], ['@self' => ['id' => 'ps-entity'], 'gross' => 2], [], []],
			$this->call($service, 'loadAll', ['Payslip'])
		);
		$this->assertSame([['humaniq', 'Payslip', ['limit' => 10000], false]], $fake->calls);
		$this->assertSame(['@self' => ['id' => 'ps-entity'], 'gross' => 2], $this->call($service, 'findById', ['Payslip', 'ps-entity']));
		$this->assertSame(['id' => 'ps-json', 'gross' => 1], $this->call($service, 'findById', ['Payslip', 'ps-json']));
		$this->assertNull($this->call($service, 'findById', ['Payslip', 'ps-none']));

		$calls = count($fake->calls);
		$this->assertNull($this->call($service, 'findById', ['Payslip', '']));
		$this->assertCount($calls, $fake->calls, 'An empty id is never looked up.');

	}//end testRowsNormaliseAndResolveById()

	/**
	 * A failing read, or one that answers no list, yields no rows; only the failure is logged.
	 *
	 * @param string $name The service.
	 *
	 * @return void
	 *
	 * @dataProvider services
	 */
	public function testFailedReadsYieldNoRows(string $name): void {
		[$service, $logger] = $this->service($name, $this->objectService(new \RuntimeException('weg')), 'warning');
		$logger->expects($this->once())->method('warning')->with($name.': kon Payslip niet laden: weg');
		$this->assertSame([], $this->call($service, 'loadAll', ['Payslip']));

		[$quiet] = $this->service($name, $this->objectService('geen lijst'), null);
		$this->assertSame([], $this->call($quiet, 'loadAll', ['Payslip']));

	}//end testFailedReadsYieldNoRows()

	/**
	 * A fake ObjectService answering every findAll() with the given rows or exception.
	 *
	 * @param mixed $answer The rows, or a Throwable to throw.
	 *
	 * @return object
	 */
	private function objectService(mixed $answer): object {
		return new class($answer) {

			/**
			 * Every findAll() call: register, schema, options, field-strip flag.
			 *
			 * @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
			 */
			public array $calls = [];

			/**
			 * The register of the next findAll().
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The schema of the next findAll().
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param mixed $answer The rows, or a Throwable to throw.
			 */
			public function __construct(
				private readonly mixed $answer,
			) {

			}//end __construct()

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options The options.
			 * @param bool                 $strip   Whether fields are stripped.
			 *
			 * @return mixed
			 */
			public function findAll(array $options = [], bool $strip = true): mixed {
				$this->calls[] = [$this->register, $this->schema, $options, $strip];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}//end findAll()
		};

	}//end objectService()

	/**
	 * The named service over a fake ObjectService, with its logger.
	 *
	 * @param string      $name     The service.
	 * @param object      $fake     The fake ObjectService.
	 * @param string|null $expected The logger method a test sets expectations on, or null for none.
	 *
	 * @return array{0: object, 1: \PHPUnit\Framework\MockObject\MockObject}
	 */
	private function service(string $name, object $fake, ?string $expected): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($fake);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$logger = $this->createMock(LoggerInterface::class);
		if ($expected === null) {
			$logger->expects($this->never())->method('warning');
		}

		$service = ($name === 'PayrollReproduceService')
			? new PayrollReproduceService($container, $settings, new PayrollCalculator(), $logger)
			: new PayrollAuditVerificationService($container, $settings, $logger);

		return [$service, $logger];

	}//end service()

	/**
	 * Call a private method.
	 *
	 * @param object            $service   The service.
	 * @param string            $method    The method.
	 * @param array<int, mixed> $arguments The arguments.
	 *
	 * @return mixed
	 */
	private function call(object $service, string $method, array $arguments): mixed {
		return (new ReflectionMethod($service, $method))->invokeArgs($service, $arguments);

	}//end call()
}//end class
