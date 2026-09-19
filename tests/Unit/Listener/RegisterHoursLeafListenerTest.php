<?php

/**
 * RegisterHoursLeafListener tests
 *
 * The hours leaf carries the same fragility the agenda leaf does: it declares
 * how it loads, and that declaration reaches an OpenRegister the admin chose,
 * not one humaniq ships. Against an OpenRegister that predates #3956 the
 * declaration is an `Error`, the listener's own catch turns it into a warning,
 * and the leaf is absent from every consuming page with nothing saying so.
 *
 * `tests/stubs/OpenRegisterLeafStub.php` is that OpenRegister, so the
 * assertion below is the registration itself.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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
 * @spec openspec/specs/hours-leaf/spec.md#requirement-both-halves-of-the-leaf-agree
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\RegisterHoursLeafListener;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

/**
 * The hours leaf survives an OpenRegister older than its own declaration.
 */
class RegisterHoursLeafListenerTest extends TestCase {

	/**
	 * Build the listener with a logger that keeps what it was told.
	 *
	 * @return array{0: RegisterHoursLeafListener, 1: object} The listener and the logger.
	 */
	private function subject(): array {
		$logger = new class extends AbstractLogger {

			/**
			 * Everything the listener logged.
			 *
			 * @var array<int, string>
			 */
			public array $lines = [];

			/**
			 * {@inheritDoc}
			 *
			 * @param mixed                $level   The log level.
			 * @param mixed                $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->lines[] = (string)$message;
			}
		};

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return [new RegisterHoursLeafListener($l10n, $logger), $logger];
	}//end subject()

	/**
	 * The leaf still registers beside an OpenRegister that predates #3956.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-both-halves-of-the-leaf-agree
	 */
	public function testTheLeafStillRegistersOnAnOlderOpenRegister(): void {
		[$listener, $logger] = $this->subject();
		$event = new RegisterLeafProvidersEvent();

		$listener->handle($event);

		$this->assertCount(
			1,
			$event->getLeaves(),
			'The hours leaf was not contributed. The listener swallowed: '
			. (implode(' | ', $logger->lines) ?: 'nothing, so the event was never reached')
		);
		$this->assertSame(
			RegisterHoursLeafListener::LEAF_ID,
			$event->getLeaves()[0]['descriptor']->id
		);
	}//end testTheLeafStillRegistersOnAnOlderOpenRegister()

	/**
	 * The capability probe answers no when the class beside us has neither half.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-both-halves-of-the-leaf-agree
	 */
	public function testTheProbeAnswersNoWithoutTheConstantAndTheParameter(): void {
		[$listener] = $this->subject();
		$probe = new ReflectionMethod($listener, 'descriptorSupportsLoadStrategy');

		$this->assertFalse(
			$probe->invoke($listener),
			'The stubbed LeafDescriptor has neither the constant nor the parameter, '
			. 'so declaring a load strategy against it can only fail'
		);
	}//end testTheProbeAnswersNoWithoutTheConstantAndTheParameter()
}//end class
