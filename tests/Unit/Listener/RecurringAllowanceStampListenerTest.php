<?php

/**
 * Unit tests for RecurringAllowanceStampListener.
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

use OCA\Humaniq\Listener\RecurringAllowanceStampListener;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The drafter and employee stamps on a recurring allowance.
 */
class RecurringAllowanceStampListenerTest extends TestCase {

	/**
	 * The whole outcome of every allowance write: the stamped fields and
	 * what is logged.
	 *
	 * @return void
	 */
	public function testTheWholeOutcomeOfEveryAllowanceWrite(): void {
		$base = ['employeeId' => 'emp-piet', 'kind' => 'thuiswerk'];
		$stamped = array_merge($base, ['userId' => 'pjansen', 'administrationId' => 'ADM-001']);
		$cases = [
			'create'                 => ['hr', fn (): Event => new ObjectCreatingEvent($this->entity($base))],
			'create-anonymous'       => [null, fn (): Event => new ObjectCreatingEvent($this->entity($base))],
			'create-no-employee'     => ['hr', fn (): Event => new ObjectCreatingEvent($this->entity(['kind' => 'thuiswerk']))],
			'create-unreadable'      => ['hr', fn (): Event => new ObjectCreatingEvent($this->entity(['employeeId' => 'emp-boom']))],
			'create-bare-employee'   => ['hr', fn (): Event => new ObjectCreatingEvent($this->entity(['employeeId' => 'emp-bare']))],
			'create-unknown'         => ['hr', fn (): Event => new ObjectCreatingEvent($this->entity(['employeeId' => 'emp-gone']))],
			'update-keeps-drafter'   => ['hr', fn (): Event => new ObjectUpdatingEvent($this->entity(array_merge($stamped, ['proposedBy' => 'someone'])), $this->entity(array_merge($stamped, ['proposedBy' => 'anna'])))],
			'update-same-drafter'    => ['hr', fn (): Event => new ObjectUpdatingEvent($this->entity(array_merge($stamped, ['proposedBy' => 'anna'])), $this->entity(array_merge($stamped, ['proposedBy' => 'anna'])))],
			'update-no-old-drafter'  => ['hr', fn (): Event => new ObjectUpdatingEvent($this->entity(array_merge($stamped, ['proposedBy' => 'someone'])), $this->entity($stamped))],
			'update-restamps'        => ['hr', fn (): Event => new ObjectUpdatingEvent($this->entity(array_merge($base, ['userId' => 'old', 'proposedBy' => 'anna'])), $this->entity(array_merge($base, ['proposedBy' => 'anna'])))],
			'update-drops-drafter'   => ['hr', fn (): Event => new ObjectUpdatingEvent($this->entity($stamped), $this->entity(array_merge($stamped, ['proposedBy' => 'anna'])))],
			'update-without-old'     => ['hr', fn (): Event => new ObjectUpdatingEvent($this->entity($base))],
			'update-unreadable'      => ['hr', fn (): Event => new ObjectUpdatingEvent($this->entity(['employeeId' => 'emp-boom']), $this->entity(['proposedBy' => 'anna']))],
			'post-save'              => ['hr', fn (): Event => new ObjectCreatedEvent($this->entity($base))],
		];
		$got = [];
		foreach ($cases as $name => [$uid, $make]) {
			$logged = [];
			$event = $make();
			$this->listener(uid: $uid, logged: $logged)->handle($event);
			$modified = null;
			if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent) {
				$modified = $event->getModifiedData();
			}

			$got[$name] = ['modified' => $modified, 'logged' => $logged];
		}

		self::assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/allowance-stamps.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testTheWholeOutcomeOfEveryAllowanceWrite()

	/**
	 * The listener over a fixed set of employees.
	 *
	 * @param string|null                      $uid    The writer, or none.
	 * @param array<int, array<string, mixed>> $logged The log lines.
	 *
	 * @return RecurringAllowanceStampListener
	 */
	private function listener(?string $uid, array &$logged): RecurringAllowanceStampListener {
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findObjectData')->willReturnCallback(
			static function (string $uuid, string $schema): ?array {
				if ($uuid === 'emp-boom') {
					throw new \RuntimeException('store down');
				}

				$employees = [
					'emp-piet' => ['nextcloudUserId' => 'pjansen', 'administrationId' => 'ADM-001', 'schema' => $schema],
					'emp-bare' => ['nextcloudUserId' => '', 'firstName' => 'Bare'],
				];

				return ($employees[$uuid] ?? null);
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
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

		return new RecurringAllowanceStampListener(gateway: $store, userSession: $session, logger: $logger);
	}//end listener()

	/**
	 * An allowance entity.
	 *
	 * @param array<string, mixed> $data The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('allowance-1');
		$entity->setSchema('recurringallowance');
		$entity->setObject($data);
		return $entity;
	}//end entity()

}//end class
