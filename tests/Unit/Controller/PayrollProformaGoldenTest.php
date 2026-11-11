<?php

/**
 * Golden test for PayrollController::proforma().
 *
 * Dispatches the action the way Nextcloud's Dispatcher does
 * (NextcloudDispatch), with the real ProformaPayslipService, the real
 * PayrollCalculator and the nl-2026 tables. The cases cover valid input in
 * JSON and query form, defaults, every malformed value the simulation
 * refuses, a non-string value for each string parameter (the dispatcher's
 * bare 400), an array for each mixed parameter, a denied, an unavailable and
 * a missing payroll register, and a forged userId. Every response, every
 * capability probe and every log line is compared with
 * tests/fixtures/golden/payroll-proforma.json. Set RECORD_GOLDEN=1 to
 * rewrite that file.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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
 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-002
 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\PayrollController;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Service\CallerContext;
use OCA\Humaniq\Service\EmployerPremiumSettings;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\PayrollMutationService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\Humaniq\Service\PayrollRunServices;
use OCA\Humaniq\Service\ProformaPayslipService;
use OCA\Humaniq\Service\RetroAdjustmentService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\WkrService;
use OCA\Humaniq\Tests\Unit\Support\NextcloudDispatch;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Golden cases for the pro-forma endpoint. No coverage annotations, like
 * PayrollControllerProformaTest: a case runs the whole payroll engine.
 *
 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-002
 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-004
 */
class PayrollProformaGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/payroll-proforma.json';

	private const VALID = [
		'gross' => 3800,
		'table' => 'wit',
		'dateOfBirth' => '1990-04-12',
		'period' => '2026-02',
		'aof' => 'laag',
		'whk' => 1.52,
	];

	/**
	 * Every probe and log line of the current case, in order.
	 *
	 * @var list<mixed>
	 */
	public array $events = [];

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = array_keys(self::scenarios());
		return array_combine($names, array_map(static fn (string $n): array => [$n], $names));

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $case The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(string $case): void {
		[$params, $register] = self::scenarios()[$case];
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$actual = str_replace(trim((string)json_encode(dirname(__DIR__, 3), JSON_UNESCAPED_SLASHES), '"'), '<app>', (string)json_encode($this->dispatch($params, $register), $flags));
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode((string)$actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], $flags), $actual);

	}//end testGolden()

	/**
	 * The request parameters and the register's answer per case. The register
	 * is 'allow', 'deny' (RBAC refuses), 'missing' (the object does not
	 * exist) or 'unavailable' (OpenRegister is not installed).
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	private static function scenarios(): array {
		$v = self::VALID;
		return [
			'valid json'                       => [$v, 'allow'],
			'valid query strings'              => [['gross' => '3800', 'table' => 'groen', 'loonheffingskorting' => 'false', 'dateOfBirth' => '1985-11-30', 'period' => '2026-06', 'parttime' => '0.8', 'bijzonder' => '250', 'aof' => 'hoog', 'whk' => '1.2'], 'allow'],
			'aow age'                          => [array_merge($v, ['dateOfBirth' => '1950-01-01']), 'allow'],
			'defaults'                         => [['gross' => 3000, 'period' => '2026-01'], 'allow'],
			'explicit nulls'                   => [['gross' => 3000, 'table' => null, 'loonheffingskorting' => null, 'dateOfBirth' => null, 'period' => '2026-01', 'parttime' => null, 'bijzonder' => null, 'aof' => null, 'whk' => null], 'allow'],
			'korting off bool'                 => [array_merge($v, ['loonheffingskorting' => false]), 'allow'],
			'korting zero'                     => [array_merge($v, ['loonheffingskorting' => 0]), 'allow'],
			'parttime and bijzonder numbers'   => [array_merge($v, ['parttime' => 0.5, 'bijzonder' => 1000]), 'allow'],
			'empty strings'                    => [array_merge($v, ['table' => '', 'dateOfBirth' => '', 'aof' => '']), 'allow'],
			'gross not numeric'                => [array_merge($v, ['gross' => 'n/a']), 'allow'],
			'gross missing'                    => [['period' => '2026-02'], 'allow'],
			'gross negative'                   => [array_merge($v, ['gross' => -5]), 'allow'],
			'table unknown'                    => [array_merge($v, ['table' => 'blauw']), 'allow'],
			'period malformed'                 => [array_merge($v, ['period' => '2026-13']), 'allow'],
			'period without tables'            => [array_merge($v, ['period' => '2019-01']), 'allow'],
			'parttime zero'                    => [array_merge($v, ['parttime' => 0]), 'allow'],
			'bijzonder negative'               => [array_merge($v, ['bijzonder' => -1]), 'allow'],
			'aof unknown'                      => [array_merge($v, ['aof' => 'midden']), 'allow'],
			'whk not numeric'                  => [array_merge($v, ['whk' => 'veel']), 'allow'],
			'table array'                      => [array_merge($v, ['table' => ['wit']]), 'allow'],
			'table int'                        => [array_merge($v, ['table' => 5]), 'allow'],
			'table bool'                       => [array_merge($v, ['table' => true]), 'allow'],
			'date of birth array'              => [array_merge($v, ['dateOfBirth' => ['1990-04-12']]), 'allow'],
			'date of birth int'                => [array_merge($v, ['dateOfBirth' => 19900412]), 'allow'],
			'period int'                       => [array_merge($v, ['period' => 202602]), 'allow'],
			'period float'                     => [array_merge($v, ['period' => 2026.02]), 'allow'],
			'aof array'                        => [array_merge($v, ['aof' => ['laag']]), 'allow'],
			'aof false'                        => [array_merge($v, ['aof' => false]), 'allow'],
			'gross array'                      => [array_merge($v, ['gross' => ['3800']]), 'allow'],
			'parttime array'                   => [array_merge($v, ['parttime' => ['1']]), 'allow'],
			'bijzonder array'                  => [array_merge($v, ['bijzonder' => ['1']]), 'allow'],
			'whk array'                        => [array_merge($v, ['whk' => ['1']]), 'allow'],
			'register denied'                  => [$v, 'deny'],
			'register missing'                 => [$v, 'missing'],
			'openregister unavailable'         => [$v, 'unavailable'],
			'register denied and table array'  => [array_merge($v, ['table' => ['wit']]), 'deny'],
			'register denied and gross bad'    => [array_merge($v, ['gross' => 'n/a']), 'deny'],
			'forged user id allowed'           => [array_merge($v, ['userId' => 'admin', 'owner' => 'admin', '_rbac' => false]), 'allow'],
			'forged user id denied'            => [array_merge($v, ['userId' => 'admin', 'owner' => 'admin', '_rbac' => false]), 'deny'],
		];

	}//end scenarios()

	/**
	 * Dispatch proforma() with one request and record what it did.
	 *
	 * @param array<string, mixed> $params   The request parameters.
	 * @param string               $register The register's answer.
	 *
	 * @return array<string, mixed>
	 */
	private function dispatch(array $params, string $register): array {
		$this->events = [];
		$response = NextcloudDispatch::call($this->controller($params, $register), 'proforma', $params);

		return [
			'class'   => (new \ReflectionClass($response))->getShortName(),
			'status'  => $response->getStatus(),
			'data'    => ($response instanceof JSONResponse ? $response->getData() : null),
			'events'  => $this->events,
		];

	}//end dispatch()

	/**
	 * Build the controller around a recording ObjectService and logger.
	 *
	 * @param array<string, mixed> $params   The request parameters.
	 * @param string               $register The register's answer.
	 *
	 * @return PayrollController
	 */
	private function controller(array $params, string $register): PayrollController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (isset($params[$key]) === true ? $params[$key] : $default)
		);
		$request->method('getParams')->willReturn($params);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn($register !== 'unavailable');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => $this->objectService($id, $register)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mila');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PayrollController(
			$request,
			new PayrollRunServices($this->createMock(PayrollRunService::class), $this->createMock(PayrollMutationService::class), new ProformaPayslipService(new PayrollCalculator(), new EmployerPremiumSettings($this->createMock(IAppConfig::class))), $this->createMock(RetroAdjustmentService::class)),
			$this->createMock(WkrService::class),
			$settings,
			new CallerContext($session, new HumaniqRoles($this->createMock(IGroupManager::class)), $container, $this->logger())
		);

	}//end controller()

	/**
	 * A recording ObjectService whose findAll() answers like OpenRegister.
	 *
	 * @param string $id       The container id asked for.
	 * @param string $register The register's answer.
	 *
	 * @return object
	 */
	private function objectService(string $id, string $register): object {
		$this->events[] = ['container' => $id];
		return new class($this, $register) {

			/**
			 * The register slug of the probe.
			 *
			 * @var string
			 */
			private string $registerSlug = '';

			/**
			 * The schema slug of the probe.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param PayrollProformaGoldenTest $test     The recording test.
			 * @param string                    $register The register's answer.
			 */
			public function __construct(
				private readonly PayrollProformaGoldenTest $test,
				private readonly string $register,
			) {
			}

			/**
			 * @param string $register The register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->registerSlug = $register;
				return $this;
			}

			/**
			 * @param string $schema The schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->test->events[] = ['findAll' => [$this->registerSlug, $this->schema, $config]];
				if ($this->register === 'deny') {
					throw new RuntimeException('RBAC denied.');
				}

				if ($this->register === 'missing') {
					throw new DoesNotExistException('Register not found.');
				}

				return [['id' => 'run-1', 'grossPay' => 999999]];
			}
		};

	}//end objectService()

	/**
	 * A logger that records every line into the test's events.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class($this) extends AbstractLogger {

			/**
			 * @param PayrollProformaGoldenTest $test The recording test.
			 */
			public function __construct(
				private readonly PayrollProformaGoldenTest $test,
			) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|Stringable    $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->events[] = ['log' => [$level, (string)$message]];
			}
		};

	}//end logger()

}//end class
