<?php

/**
 * Unit tests for LeaveApprovalListener.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\LeaveApprovalListener;
use OCA\Humaniq\Service\LeaveBalanceProjectionService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The leave request projection after a save.
 */
class LeaveApprovalListenerTest extends TestCase {

	/**
	 * The whole outcome of every event: what is projected and what is
	 * logged.
	 *
	 * @return void
	 */
	public function testTheWholeOutcomeOfEveryEvent(): void {
		$request = ['employeeId' => 'emp-1', 'leaveType' => 'vacation', 'status' => 'approved'];
		$cases = [
			'created'       => fn (): Event => new ObjectCreatedEvent($this->entity('LeaveRequest', $request)),
			'updated'       => fn (): Event => new ObjectUpdatedEvent($this->entity('leaverequest', $request), $this->entity('leaverequest', ['status' => 'submitted'])),
			'other-schema'  => fn (): Event => new ObjectCreatedEvent($this->entity('expense', $request)),
			'no-schema'     => fn (): Event => new ObjectCreatedEvent($this->entity('', $request)),
			'empty-object'  => fn (): Event => new ObjectCreatedEvent($this->entity('leaverequest', [])),
			'pre-save'      => fn (): Event => new ObjectCreatingEvent($this->entity('leaverequest', $request)),
			'plain-event'   => fn (): Event => new Event(),
			'projection-ko' => fn (): Event => new ObjectCreatedEvent($this->entity('leaverequest', ['employeeId' => 'boom'])),
		];
		$got = [];
		foreach ($cases as $name => $make) {
			$projected = [];
			$logged = [];
			$projection = $this->createMock(LeaveBalanceProjectionService::class);
			$projection->method('projectForRequest')->willReturnCallback(
				function (array $request) use (&$projected): void {
					if (($request['employeeId'] ?? '') === 'boom') {
						throw new \RuntimeException('projection failed');
					}

					$projected[] = $request;
				}
			);
			$logger = new class($logged) extends AbstractLogger {
				/**
				 * @param array<int, array<string, mixed>> $lines The log lines.
				 */
				public function __construct(private array &$lines) {
				}//end __construct()

				/**
				 * @param mixed              $level   Level.
				 * @param string|\Stringable $message Message.
				 * @param array<mixed>       $context Context.
				 *
				 * @return void
				 */
				public function log($level, string|\Stringable $message, array $context=[]): void {
					$this->lines[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
				}//end log()
			};
			$listener = new LeaveApprovalListener(
				projection: $projection,
				container: new FakeContainer(['OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper()]),
				logger: $logger
			);
			$listener->handle($make());
			$got[$name] = ['projected' => $projected, 'logged' => $logged];
		}

		self::assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/leave-approval-events.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testTheWholeOutcomeOfEveryEvent()

	/**
	 * An entity.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('leave-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

}//end class
