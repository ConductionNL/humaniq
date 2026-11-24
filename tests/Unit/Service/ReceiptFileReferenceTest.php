<?php

/**
 * Unit tests for ReceiptFileReference.
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
 * @spec openspec/changes/self-service-mobile/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\ReceiptFileReference;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReceiptFileReference.
 *
 * @spec openspec/changes/self-service-mobile/tasks.md#task-2.2
 */
class ReceiptFileReferenceTest extends TestCase {

	/**
	 * Every shape `receiptFile` can hold reads to one reference.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function values(): array {
		return [
			'null'                   => [null, ''],
			'empty string'           => ['  ', ''],
			'int id'                 => [4711, '4711'],
			'zero id'                => [0, ''],
			'digit string'           => [' 4711 ', '4711'],
			'path from before'       => ['/Receipts/taxi.pdf', '/Receipts/taxi.pdf'],
			'rendered file object'   => [['id' => 812, 'path' => '/x/receipt.jpg'], '812'],
			'string id in object'    => [['id' => '812'], '812'],
			'object with path only'  => [['path' => ' /x/receipt.jpg '], '/x/receipt.jpg'],
			'object without either'  => [['name' => 'receipt.jpg'], ''],
			'a list is not a file'   => [[812], ''],
			'a bool is not a file'   => [true, ''],
		];
	}//end values()

	/**
	 * @dataProvider values
	 *
	 * @param mixed  $value    The receiptFile value.
	 * @param string $expected The reference.
	 *
	 * @return void
	 */
	public function testReadsTheReference(mixed $value, string $expected): void {
		$this->assertSame($expected, ReceiptFileReference::of($value));
	}//end testReadsTheReference()
}//end class
