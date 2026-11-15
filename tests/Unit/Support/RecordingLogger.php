<?php

/**
 * Recording PSR logger
 *
 * Keeps every log call as [level, message, context], so a test can pin what
 * a class writes to the log and what it keeps out of it.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A logger that records its calls.
 */
final class RecordingLogger extends AbstractLogger {

	/**
	 * Every call, in order.
	 *
	 * @var list<array{0: mixed, 1: string, 2: array<mixed>}>
	 */
	public array $records = [];

	/**
	 * @param mixed             $level   The level.
	 * @param string|Stringable $message The message.
	 * @param array<mixed>      $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void {
		$this->records[] = [$level, (string)$message, $context];
	}//end log()
}//end class
