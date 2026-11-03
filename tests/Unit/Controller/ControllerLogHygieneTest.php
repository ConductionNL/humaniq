<?php

/**
 * Controllers keep exception text out of the log
 *
 * Every logger call in lib/Controller is read from the source: none may
 * carry a variable other than `$e::class`. Exception text, exception
 * objects and caller-supplied ids can carry row content and caller input;
 * a controller logs a fixed sentence and the exception class.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Source check over the controllers' log calls.
 */
class ControllerLogHygieneTest extends TestCase {

	/**
	 * No controller log call carries a variable other than `$e::class`.
	 *
	 * @return void
	 */
	public function testNoControllerLogsExceptionText(): void {
		$calls = 0;
		$offending = [];
		foreach (glob(dirname(__DIR__, 3) . '/lib/Controller/*.php') as $file) {
			foreach ($this->logCalls((string)file_get_contents($file)) as $call) {
				$calls++;
				if ($this->variablesIn($call) !== []) {
					$offending[] = basename($file) . ': ' . $call;
				}
			}
		}

		$this->assertGreaterThan(30, $calls, 'The scan reads the controllers\' log calls.');
		$this->assertSame([], $offending);
	}//end testNoControllerLogsExceptionText()

	/**
	 * The scan sees a raw message, an exception object, an appended id, a
	 * cast exception and a property in a log call, and lets the exception
	 * class through.
	 *
	 * @return void
	 */
	public function testTheScanFindsEveryVariableButTheExceptionClass(): void {
		$source = "<?php\n\$this->logger->info('x ' . \$e->getMessage());\n\$this->caller->logger->error(\n\t'y',\n\t['exception' => \$e]\n);\n"
			. "\$this->logger->info('run ' . \$runId . ' failed');\n\$this->logger->error('w', ['detail' => (string)\$e]);\n"
			. "\$this->logger->error('v', ['app' => \$this->appName]);\n\$this->logger->warning('z', ['exceptionClass' => \$e::class]);\n\$this->logger->warning('u', ['class' => \$row::class]);\n";

		$calls = $this->logCalls($source);

		$this->assertCount(7, $calls);
		$this->assertSame([['$e'], ['$e'], ['$runId'], ['$e'], ['$this'], [], ['$row']], array_map(fn (string $call): array => $this->variablesIn($call), $calls));
	}//end testTheScanFindsEveryVariableButTheExceptionClass()

	/**
	 * The variables a log call's arguments use, leaving out `$e::class`.
	 *
	 * @param string $call The log call, from `logger->` to its closing parenthesis.
	 *
	 * @return list<string>
	 */
	private function variablesIn(string $call): array {
		preg_match_all('/\$(?!e::class\b)\w+/', $call, $matches);

		return array_values(array_unique($matches[0]));
	}//end variablesIn()

	/**
	 * The full text of every `logger->level(...)` call in a source.
	 *
	 * @param string $source PHP source.
	 *
	 * @return list<string>
	 */
	private function logCalls(string $source): array {
		$calls = [];
		preg_match_all('/logger->\w+\(/', $source, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as [$text, $offset]) {
			$end = ($offset + strlen($text));
			for ($depth = 1; $depth > 0 && $end < strlen($source); $end++) {
				$depth += ($source[$end] === '(' ? 1 : ($source[$end] === ')' ? -1 : 0));
			}

			$calls[] = substr($source, $offset, ($end - $offset));
		}

		return $calls;
	}//end logCalls()
}//end class
