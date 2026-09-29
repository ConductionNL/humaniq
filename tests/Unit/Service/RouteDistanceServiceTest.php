<?php

/**
 * RouteDistanceServiceTest
 *
 * The route lookup of expenses-travel-calculation through integriq. The two
 * integriq services are doubled with their real method names and signatures
 * (`EnvironmentService::resolveSource(string): ?ObjectEntity` and
 * `CallService::call(ObjectEntity, string, string, array, ...)`, returning the
 * CallLog object whose `response` carries `statusCode` and `body`).
 *
 * @category Tests
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\RouteDistanceService;
use OCA\Humaniq\Service\RouteDistanceUnavailableException;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Distance from the configured route planner, and each way it is unavailable.
 */
class RouteDistanceServiceTest extends TestCase {

	/**
	 * The calls the call-service double received.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * The service over an instance with or without integriq and a source.
	 *
	 * @param bool   $installed Whether integriq is installed.
	 * @param string $sourceId  The configured source.
	 * @param array<string, mixed> $response The CallLog response.
	 *
	 * @return RouteDistanceService
	 */
	private function service(bool $installed, string $sourceId, array $response=[]): RouteDistanceService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed === true && $app === 'integriq');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRouteDistanceSource')->willReturn(['source' => $sourceId, 'endpoint' => '/route']);

		$test = $this;
		$environment = new class {

			/**
			 * Mirror of integriq's EnvironmentService::resolveSource().
			 *
			 * @param string $sourceRef The source uuid.
			 *
			 * @return ObjectEntity|null
			 */
			public function resolveSource(string $sourceRef): ?ObjectEntity {
				if ($sourceRef !== 'src-route') {
					return null;
				}

				$source = new ObjectEntity();
				$source->setUuid('src-route');
				$source->setObject(['name' => 'ANWB Routeplanner', 'slug' => 'anwb-route']);
				return $source;
			}//end resolveSource()

		};
		$call = new class ($test, $response) {

			/**
			 * Constructor.
			 *
			 * @param RouteDistanceServiceTest $test     Collects the calls.
			 * @param array<string, mixed>     $response The response to log.
			 */
			public function __construct(private RouteDistanceServiceTest $test, private array $response) {
			}//end __construct()

			/**
			 * Mirror of integriq's CallService::call().
			 *
			 * @param ObjectEntity         $source   The source.
			 * @param string               $endpoint The path.
			 * @param string               $method   The verb.
			 * @param array<string, mixed> $config   The request config.
			 *
			 * @return ObjectEntity The CallLog.
			 */
			public function call(ObjectEntity $source, string $endpoint='', string $method='GET', array $config=[]): ObjectEntity {
				$this->test->calls[] = ['source' => $source->getUuid(), 'endpoint' => $endpoint, 'method' => $method, 'config' => $config];
				$log = new ObjectEntity();
				$log->setObject(['response' => $this->response]);
				return $log;
			}//end call()

		};

		return new RouteDistanceService(
			container: new FakeContainer(['OCA\Integriq\Service\EnvironmentService' => $environment, 'OCA\Integriq\Service\CallService' => $call]),
			appManager: $apps,
			settings: $settings
		);
	}//end service()

	/**
	 * Scenario: distance from the route planner, with the provider's name.
	 *
	 * @return void
	 */
	public function testTheRoutePlannerAnswersTheDistance(): void {
		$service = $this->service(true, 'src-route', ['statusCode' => 200, 'body' => json_encode(['distanceKm' => 7.4])]);

		self::assertSame(['distanceKm' => 7.4, 'provider' => 'ANWB Routeplanner'], $service->lookup('2611 AB', '2628 CD'));
		self::assertSame([['source' => 'src-route', 'endpoint' => '/route', 'method' => 'GET', 'config' => ['query' => ['from' => '2611AB', 'to' => '2628CD']]]], $this->calls);
	}//end testTheRoutePlannerAnswersTheDistance()

	/**
	 * Scenario: no route planner installed.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqThereIsNoRoutePlanner(): void {
		$this->expectException(RouteDistanceUnavailableException::class);
		$this->service(false, 'src-route')->lookup('2611 AB', '2628 CD');
	}//end testWithoutIntegriqThereIsNoRoutePlanner()

	/**
	 * Integriq without a configured or resolvable source is unavailable too.
	 *
	 * @return void
	 */
	public function testWithoutASourceThereIsNoRoutePlanner(): void {
		foreach (['', 'src-gone'] as $source) {
			try {
				$this->service(true, $source)->lookup('2611 AB', '2628 CD');
				self::fail('A missing source must be refused.');
			} catch (RouteDistanceUnavailableException $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}

		self::assertSame([], $this->calls);
	}//end testWithoutASourceThereIsNoRoutePlanner()

	/**
	 * A failed call or an answer without a distance is not a distance.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutADistanceIsRefused(): void {
		foreach ([['statusCode' => 502, 'body' => '{"distanceKm": 7}'], ['statusCode' => 200, 'body' => '{"routes": []}']] as $response) {
			try {
				$this->service(true, 'src-route', $response)->lookup('2611 AB', '2628 CD');
				self::fail('An answer without a distance must be refused.');
			} catch (RouteDistanceUnavailableException $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}
	}//end testAnAnswerWithoutADistanceIsRefused()

}//end class
