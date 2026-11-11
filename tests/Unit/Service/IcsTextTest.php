<?php

/**
 * Unit tests for IcsText.
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
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\IcsText;
use PHPUnit\Framework\TestCase;

/**
 * RFC 5545 TEXT escaping and its reverse.
 *
 * @covers \OCA\Humaniq\Service\IcsText
 */
class IcsTextTest extends TestCase {

	/**
	 * @return void
	 */
	public function testEscapeAndUnescape(): void {
		$raw = "De Vries; Jansen, a\\b\nnieuwe regel";

		$this->assertSame('De Vries\; Jansen\\, a\\\\b\\nnieuwe regel', IcsText::escape($raw));
		$this->assertSame($raw, IcsText::unescape(IcsText::escape($raw)));
		$this->assertSame('Afwezig', IcsText::escape('Afwezig'));
		$this->assertSame("a\nb", IcsText::unescape('a\\nb'));

	}//end testEscapeAndUnescape()
}//end class
