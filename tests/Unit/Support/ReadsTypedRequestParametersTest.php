<?php

/**
 * Unit tests for ReadsTypedRequestParameters.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Support
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
 * @spec exclude request-parameter plumbing shared by controller actions, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Support;

use OCA\Humaniq\Support\ReadsTypedRequestParameters;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReadsTypedRequestParameters.
 *
 * @covers \OCA\Humaniq\Support\ReadsTypedRequestParameters
 *
 * @spec exclude request-parameter plumbing shared by controller actions, no behavioural spec surface.
 */
class ReadsTypedRequestParametersTest extends TestCase {

	private const SPEC = [
		'gross' => ['mixed', null],
		'table' => ['?string', 'wit'],
	];

	/**
	 * Values come back in the spec's order, a missing one as its default.
	 *
	 * @return void
	 */
	public function testReadsInSpecOrderWithDefaults(): void {
		$this->assertSame(['gross' => ['3800'], 'table' => 'wit'], $this->read(['extra' => 'x', 'gross' => ['3800']]));
		$this->assertSame(['gross' => null, 'table' => 'groen'], $this->read(['table' => 'groen']));

	}//end testReadsInSpecOrderWithDefaults()

	/**
	 * A nullable string refuses every non-string value.
	 *
	 * @return void
	 */
	public function testNullableStringRefusesOtherTypes(): void {
		foreach ([5, 1.5, true, false, ['wit']] as $value) {
			$this->assertNull($this->read(['gross' => 1, 'table' => $value]));
		}

	}//end testNullableStringRefusesOtherTypes()

	/**
	 * Read SPEC from a request holding the given parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(array $params): ?array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (isset($params[$key]) === true ? $params[$key] : $default)
		);

		$reader = new class($request) {
			use ReadsTypedRequestParameters;

			/**
			 * @param IRequest $request The request.
			 */
			public function __construct(
				private readonly IRequest $request,
			) {
			}

			/**
			 * @param array<string, array{0: string, 1: mixed}> $spec The spec.
			 *
			 * @return array<string, mixed>|null
			 */
			public function read(array $spec): ?array {
				return $this->typedRequestParameters($spec);
			}
		};

		return $reader->read(self::SPEC);

	}//end read()

}//end class
