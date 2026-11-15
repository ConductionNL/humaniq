<?php

/**
 * Unit tests for ConnectionReporter: what humaniq tells integriq about a connection.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-integrations-catalogue/design.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service\Connection;

use OCA\Humaniq\Service\Connection\ConnectionReporter;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Humaniq\Service\Connection\ConnectionReporter
 */
class ConnectionReporterTest extends TestCase {

	/**
	 * The event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A valid report reaches integriq as its event, keyed by humaniq.
	 *
	 * @return void
	 */
	public function testAReportIsSentAsIntegriqsEvent(): void {
		$sent = null;
		$this->dispatcher->expects($this->once())->method('dispatchTyped')
			->willReturnCallback(function (object $event) use (&$sent): void {
				$sent = $event;
			});

		$reporter = new ConnectionReporter(eventDispatcher: $this->dispatcher, logger: $this->logger);

		$this->assertTrue($reporter->report(key: 'shillinq-ledger', status: 'error', message: 'Shillinq refused the payment run.'));
		$this->assertInstanceOf(ConnectionStatusReportedEvent::class, $sent);
		$this->assertSame(
			['humaniq', 'shillinq-ledger', 'error', 'Shillinq refused the payment run.'],
			[$sent->app, $sent->key, $sent->status, $sent->message]
		);
	}//end testAReportIsSentAsIntegriqsEvent()

	/**
	 * An unknown key or status is refused with a warning and nothing is sent.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyOrStatusIsRefused(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$warnings): void {
			$warnings[] = [$message, $context];
		});

		$reporter = new ConnectionReporter(eventDispatcher: $this->dispatcher, logger: $this->logger);

		$this->assertFalse($reporter->report(key: 'shillinq', status: 'configured'));
		$this->assertFalse($reporter->report(key: 'shillinq-ledger', status: 'fine'));
		$this->assertSame(
			[
				['[ConnectionReporter] Refusing to report an unknown connection key', ['key' => 'shillinq']],
				['[ConnectionReporter] Refusing to report an unknown connection status', ['key' => 'shillinq-ledger', 'status' => 'fine']],
			],
			$warnings
		);
	}//end testAnUnknownKeyOrStatusIsRefused()

	/**
	 * Without integriq nothing is sent and nothing is logged.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSent(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->never())->method('warning');

		$reporter = new class(eventDispatcher: $this->dispatcher, logger: $this->logger) extends ConnectionReporter {
			/**
			 * Integriq is not installed.
			 *
			 * @param string $eventClass The class name.
			 *
			 * @return string|null
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};

		$this->assertFalse($reporter->report(key: 'shillinq-ledger', status: 'configured'));
	}//end testWithoutIntegriqNothingIsSent()

	/**
	 * A listener that throws never reaches the handoff that reported.
	 *
	 * @return void
	 */
	public function testAThrowingListenerIsLoggedNotRaised(): void {
		$this->dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[ConnectionReporter] Could not send a connection report to integriq', ['key' => 'shillinq-ledger', 'exception' => 'registry down']);

		$reporter = new ConnectionReporter(eventDispatcher: $this->dispatcher, logger: $this->logger);

		$this->assertFalse($reporter->report(key: 'shillinq-ledger', status: 'configured'));
	}//end testAThrowingListenerIsLoggedNotRaised()

	/**
	 * Every key the reporter sends is declared, and declared as reported only.
	 *
	 * @return void
	 */
	public function testEveryReportedKeyIsDeclaredAsReportedOnly(): void {
		$declared = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/connections.json'), true);
		$reportedOnly = [];
		foreach ($declared['connections'] as $connection) {
			if (($connection['reportedOnly'] ?? false) === true) {
				$reportedOnly[] = $connection['key'];
			}
		}

		$this->assertSame('humaniq', $declared['app']);
		$this->assertSame(ConnectionReporter::KEYS, $reportedOnly);
	}//end testEveryReportedKeyIsDeclaredAsReportedOnly()
}//end class
