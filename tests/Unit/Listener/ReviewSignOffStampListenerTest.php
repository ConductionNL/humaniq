<?php

/**
 * Unit tests for ReviewSignOffStampListener.
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
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/performance-reviews/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ReviewSignOffStampListener;
use OCA\Humaniq\Service\OrgLookupInterface;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Both sides of a finished review are stamped by the server.
 *
 * @covers \OCA\Humaniq\Listener\ReviewSignOffStampListener
 */
class ReviewSignOffStampListenerTest extends TestCase {

	/**
	 * A set review for Sanne, finalised by her manager.
	 *
	 * @var array<string, mixed>
	 */
	private const SET = [
		'status' => 'vastgesteld',
		'userId' => 'sanne',
		'rating' => 'goed',
		'sterktes' => 'Plant goed',
		'ontwikkelpunten' => 'Delegeren',
		'afspraken' => 'Cursus leidinggeven',
		'goals' => [['title' => 'Cursus', 'status' => 'open']],
		'vastgesteldDoor' => 'manager',
		'vastgesteldOp' => '2026-11-10',
		'medewerkerOndertekendDoor' => null,
		'medewerkerOndertekendOp' => null,
		'medewerkerOpmerking' => null,
	];

	/**
	 * The records double.
	 *
	 * @var RecordStoreInterface
	 */
	private RecordStoreInterface $records;

	/**
	 * Sanne signs with a comment: her account and today's date are stamped,
	 * the comment kept, and a rating she tried to change stays as it was.
	 *
	 * @return void
	 */
	public function testTheEmployeeSignsASetReview(): void {
		$event = $this->update(self::SET, ['status' => 'ondertekend', 'medewerkerOpmerking' => '  Agreed ', 'rating' => 'uitstekend', 'afspraken' => 'Geen', 'medewerkerOndertekendOp' => '2020-01-01'] + self::SET);

		$this->listener('sanne')->handle($event);

		$stamped = $event->getModifiedData();
		$this->assertSame('sanne', $stamped['medewerkerOndertekendDoor']);
		$this->assertSame(gmdate('Y-m-d'), $stamped['medewerkerOndertekendOp']);
		$this->assertSame('Agreed', $stamped['medewerkerOpmerking']);
		$this->assertSame('goed', $stamped['rating']);
		$this->assertSame('Cursus leidinggeven', $stamped['afspraken']);
		$this->assertSame('manager', $stamped['vastgesteldDoor']);
		$this->assertSame('2026-11-10', $stamped['vastgesteldOp']);
	}//end testTheEmployeeSignsASetReview()

	/**
	 * A later write to a signed review keeps the sign-off and the content.
	 *
	 * @return void
	 */
	public function testASignedReviewStaysAsSigned(): void {
		$signed = ['status' => 'ondertekend', 'medewerkerOndertekendDoor' => 'sanne', 'medewerkerOndertekendOp' => '2026-11-12', 'medewerkerOpmerking' => 'Agreed'] + self::SET;
		$event = $this->update($signed, ['medewerkerOndertekendDoor' => 'manager', 'medewerkerOpmerking' => 'Changed', 'sterktes' => 'Anders'] + $signed);

		$this->listener('manager')->handle($event);

		$stamped = $event->getModifiedData();
		$this->assertSame('sanne', $stamped['medewerkerOndertekendDoor']);
		$this->assertSame('2026-11-12', $stamped['medewerkerOndertekendOp']);
		$this->assertSame('Agreed', $stamped['medewerkerOpmerking']);
		$this->assertSame('Plant goed', $stamped['sterktes']);
	}//end testASignedReviewStaysAsSigned()

	/**
	 * Setting a review stamps the reviewer's date; a forged sign-off is dropped.
	 *
	 * @return void
	 */
	public function testSettingAReviewStampsTheReviewersDate(): void {
		$discussed = ['status' => 'besproken', 'vastgesteldOp' => null] + self::SET;
		$event = $this->update($discussed, ['status' => 'vastgesteld', 'vastgesteldOp' => '1999-01-01', 'medewerkerOndertekendDoor' => 'sanne'] + $discussed);

		$this->listener('manager')->handle($event);

		$stamped = $event->getModifiedData();
		$this->assertSame(gmdate('Y-m-d'), $stamped['vastgesteldOp']);
		$this->assertNull($stamped['medewerkerOndertekendDoor']);
		$this->assertArrayNotHasKey('rating', $stamped);
	}//end testSettingAReviewStampsTheReviewersDate()

	/**
	 * Reopening a signed review clears the employee's sign-off and keeps the reviewer's date.
	 *
	 * @return void
	 */
	public function testReopeningClearsTheSignOff(): void {
		$signed = ['status' => 'ondertekend', 'medewerkerOndertekendDoor' => 'sanne', 'medewerkerOndertekendOp' => '2026-11-12', 'medewerkerOpmerking' => 'Agreed'] + self::SET;
		$event = $this->update($signed, ['status' => 'besproken'] + $signed);

		$this->listener('manager')->handle($event);

		$this->assertSame(
			['medewerkerOndertekendDoor' => null, 'medewerkerOndertekendOp' => null, 'medewerkerOpmerking' => null, 'vastgesteldOp' => '2026-11-10'],
			$event->getModifiedData()
		);
	}//end testReopeningClearsTheSignOff()

	/**
	 * A new review carries no sign-off, whatever was sent.
	 *
	 * @return void
	 */
	public function testANewReviewHasNoSignOff(): void {
		$event = new ObjectCreatingEvent($this->entity('PerformanceReview', ['status' => 'concept', 'medewerkerOndertekendDoor' => 'sanne']));

		$this->listener('hr.user')->handle($event);

		$this->assertSame(['medewerkerOndertekendDoor' => null, 'medewerkerOndertekendOp' => null, 'medewerkerOpmerking' => null], $event->getModifiedData());
	}//end testANewReviewHasNoSignOff()

	/**
	 * Without the stored review the write is refused; another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnUnknownStoredReviewIsRefusedAndOtherSchemasAreIgnored(): void {
		$listener = $this->listener('sanne');
		$this->records->method('findObjectData')->willReturn(null);
		$event = new ObjectUpdatingEvent($this->entity('PerformanceReview', ['status' => 'ondertekend']), null);

		$listener->handle($event);

		$this->assertStringContainsString('niet worden geladen', (string)($event->getErrors()['message'] ?? ''));
		$this->assertTrue($event->isPropagationStopped());

		$other = new ObjectUpdatingEvent($this->entity('Timesheet', ['status' => 'approved']), $this->entity('Timesheet', ['status' => 'submitted']));
		$listener->handle($other);
		$this->assertSame([], $other->getModifiedData());
	}//end testAnUnknownStoredReviewIsRefusedAndOtherSchemasAreIgnored()

	/**
	 * An update event from stored to new.
	 *
	 * @param array<string, mixed> $stored The review as stored.
	 * @param array<string, mixed> $new    The review as written.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(array $stored, array $new): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent($this->entity('PerformanceReview', $new), $this->entity('PerformanceReview', $stored));
	}//end update()

	/**
	 * An object entity of a schema.
	 *
	 * @param string               $schema The schema (the double resolves it to itself).
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('review-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The listener for a signed-in user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return ReviewSignOffStampListener
	 */
	private function listener(string $uid): ReviewSignOffStampListener {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$gateway = $this->createMock(OrgLookupInterface::class);
		$gateway->method('resolveSchemaSlug')->willReturnCallback(static fn (string $schema): string => $schema);
		$this->records = $this->createMock(RecordStoreInterface::class);

		return new ReviewSignOffStampListener($gateway, $this->records, $session, new NullLogger());
	}//end listener()
}//end class
