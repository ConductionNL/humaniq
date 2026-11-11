<?php

/**
 * Characterisation tests for how the leave and interview calendar services
 * resolve the CalDAV backend and probe the configured calendar.
 *
 * Pins for both: a container failure or a non-object yields no backend and no
 * log; a backend missing any required method yields none and logs exactly one
 * INFO line naming the service and the first missing method; a complete
 * backend is returned as is; the calendar probe answers the backend's array,
 * or null when it throws or answers anything else.
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
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\InterviewCalendarService;
use OCA\Humaniq\Service\LeaveCalendarService;
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Both calendar services resolve the CalDAV backend the same way.
 *
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-006
 */
class CalDavBackendResolutionTest extends TestCase {

	/**
	 * The services under test.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function services(): array {
		return [
			'leave' => [LeaveCalendarService::class],
			'interview' => [InterviewCalendarService::class],
		];

	}//end services()

	/**
	 * @param class-string $class The service.
	 *
	 * @return void
	 *
	 * @dataProvider services
	 */
	public function testBackendResolution(string $class): void {
		$this->assertNull($this->resolve($class, new \RuntimeException('geen dav'), null));
		$this->assertNull($this->resolve($class, 'geen object', null));

		$incomplete = new class {

			/**
			 * @return null
			 */
			public function getCalendarByUri() {
				return null;
			}

			/**
			 * @return null
			 */
			public function getCalendarObjectByUID() {
				return null;
			}
		};
		$short = substr($class, (int)strrpos($class, '\\') + 1);
		$this->assertNull($this->resolve($class, $incomplete, $short.': CalDavBackend mist methode createCalendarObject; kalendersync overgeslagen.'));

		$complete = new class {

			/**
			 * @param string $principal The principal.
			 * @param string $uri       The calendar uri.
			 *
			 * @return mixed
			 */
			public function getCalendarByUri($principal, $uri) {
				return match ($uri) {
					'kapot' => throw new \RuntimeException('down'),
					'leeg' => 'geen rij',
					default => ['id' => 7, 'uri' => $uri, 'principal' => $principal],
				};
			}

			/**
			 * @return null
			 */
			public function getCalendarObjectByUID() {
				return null;
			}

			/**
			 * @return null
			 */
			public function createCalendarObject() {
				return null;
			}

			/**
			 * @return null
			 */
			public function updateCalendarObject() {
				return null;
			}

			/**
			 * @return null
			 */
			public function deleteCalendarObject() {
				return null;
			}

			/**
			 * @return array<int, mixed>
			 */
			public function getCalendarObjects() {
				return [];
			}
		};
		$this->assertSame($complete, $this->resolve($class, $complete, null));

		$service = $this->service($class, $complete, null);
		$probe   = new ReflectionMethod($service, 'probeCalendar');
		$this->assertSame(['id' => 7, 'uri' => 'hr', 'principal' => 'principals/users/hr'], $probe->invoke($service, $complete, 'principals/users/hr', 'hr'));
		$this->assertNull($probe->invoke($service, $complete, 'p', 'kapot'));
		$this->assertNull($probe->invoke($service, $complete, 'p', 'leeg'));

	}//end testBackendResolution()

	/**
	 * Resolve the backend through the service's private resolveBackend().
	 *
	 * @param class-string $class   The service.
	 * @param mixed        $backend What the container answers, or a Throwable it throws.
	 * @param string|null  $info    The one INFO line expected, or null for no log at all.
	 *
	 * @return mixed
	 */
	private function resolve(string $class, mixed $backend, ?string $info): mixed {
		$service = $this->service($class, $backend, $info);

		return (new ReflectionMethod($service, 'resolveBackend'))->invoke($service);

	}//end resolve()

	/**
	 * The service over a container answering the CalDAV backend class with the given value.
	 *
	 * @param class-string $class   The service.
	 * @param mixed        $backend What the container answers, or a Throwable it throws.
	 * @param string|null  $info    The one INFO line expected, or null for no log at all.
	 *
	 * @return object
	 */
	private function service(string $class, mixed $backend, ?string $info): object {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($backend): mixed {
				self::assertSame('OCA\DAV\CalDAV\CalDavBackend', $id);
				if ($backend instanceof \Throwable) {
					throw $backend;
				}

				return $backend;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'notice', 'debug'] as $level) {
			$logger->expects($this->never())->method($level);
		}

		if ($info === null) {
			$logger->expects($this->never())->method('info');
		} else {
			$logger->expects($this->once())->method('info')->with($info);
		}

		return new $class($container, $this->createMock(SettingsService::class), $logger);

	}//end service()
}//end class
