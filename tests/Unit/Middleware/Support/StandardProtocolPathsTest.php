<?php

/**
 * Unit tests for the paths that speak a protocol defined outside the app.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Middleware\Support;

use OCA\Humaniq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The health check and the metrics are recognised as standard protocol paths,
 * whether the request names the app root or not; nothing else is.
 *
 * @covers \OCA\Humaniq\Middleware\Support\StandardProtocolPaths
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and whether they speak a standard protocol.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function paths(): array {
		return [
			'health'                => ['/api/health', true],
			'metrics without slash' => ['api/metrics', true],
			'health on the request' => ['/index.php/apps/humaniq/api/health', true],
			'metrics on the request' => ['/apps/humaniq/api/metrics', true],
			'below health'          => ['/api/health/deep', false],
			'versioned health'      => ['/api/v1/health', false],
			'an api path'           => ['/api/things', false],
			'another app'           => ['/index.php/apps/other/api/health/x', false],
		];
	}//end paths()

	/**
	 * Each path is recognised as expected.
	 *
	 * @param string $path     The path.
	 * @param bool   $standard Whether it speaks a standard protocol.
	 *
	 * @return void
	 */
	#[DataProvider('paths')]
	public function testMatches(string $path, bool $standard): void {
		$this->assertSame(expected: $standard, actual: StandardProtocolPaths::matches(path: $path));
	}//end testMatches()

	/**
	 * The app-relative part of a request path starts after the app root; any other path gets one leading slash.
	 *
	 * @return void
	 */
	public function testAppRelative(): void {
		$this->assertSame(expected: '/api/things/7', actual: StandardProtocolPaths::appRelative(path: '/index.php/apps/humaniq/api/things/7'));
		$this->assertSame(expected: '/api/things', actual: StandardProtocolPaths::appRelative(path: '/apps/humaniq/api/things'));
		$this->assertSame(expected: '/api/things', actual: StandardProtocolPaths::appRelative(path: 'api/things'));
		$this->assertSame(expected: '/apps/humaniqx/api', actual: StandardProtocolPaths::appRelative(path: '/apps/humaniqx/api'));
		$this->assertSame(expected: '/apps/humaniq', actual: StandardProtocolPaths::appRelative(path: '/apps/humaniq'));
	}//end testAppRelative()
}//end class
