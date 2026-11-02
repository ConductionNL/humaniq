<?php

/**
 * @category Test
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
 * @spec openspec/changes/hr-reopens-an-approved-claim/specs/humaniq-expenses/spec.md#Requirement:-HR-SHALL-be-able-to-reopen-an-approved-expense-claim-that-no-payroll-run-holds
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ExpenseReopenAuditListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every reopen is logged.
 *
 * @covers \OCA\Humaniq\Listener\ExpenseReopenAuditListener
 * @uses   \OCA\Humaniq\Service\HoursRegisterGateway
 */
class ExpenseReopenAuditListenerTest extends TestCase {

	/**
	 * The log lines.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * Run one saved write through the listener.
	 *
	 * @param string $schema The schema.
	 * @param array<string, mixed> $old The claim before.
	 * @param array<string, mixed> $new The claim after.
	 *
	 * @return void
	 */
	private function saved(string $schema, array $old, array $new): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => new FakeObjectStore(),
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ilse');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('notice')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = [$message, $context];
		});

		$entity = static function (array $data) use ($schema): ObjectEntity {
			$object = new ObjectEntity();
			$object->setUuid('ex-1');
			$object->setSchema($schema);
			$object->setObject($data);
			return $object;
		};

		(new ExpenseReopenAuditListener(gateway: $gateway, userSession: $session, logger: $logger))->handle(new ObjectUpdatedEvent($entity($new), $entity($old)));
	}//end saved()

	/**
	 * A reopen is logged with the claim, who reopened it and who had
	 * approved it; other moves are not.
	 *
	 * @return void
	 */
	public function testAReopenIsLogged(): void {
		$this->saved('Expense', ['status' => 'approved', 'approvedBy' => 'sanne'], ['status' => ' submitted ', 'approvedBy' => null]);
		self::assertSame([['humaniq: expense claim reopened', ['id' => 'ex-1', 'reopenedBy' => 'ilse', 'approvedBy' => 'sanne']]], $this->logged);

		$this->logged = [];
		$this->saved('Expense', ['status' => 'draft'], ['status' => 'submitted']);
		$this->saved('Expense', ['status' => 'submitted'], ['status' => 'approved']);
		$this->saved('Expense', ['status' => 'approved'], ['status' => 'approved']);
		$this->saved('CommuteArrangement', ['status' => 'approved'], ['status' => 'submitted']);
		self::assertSame([], $this->logged);
	}//end testAReopenIsLogged()

}//end class
