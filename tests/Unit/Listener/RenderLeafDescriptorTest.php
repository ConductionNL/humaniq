<?php

/**
 * Unit tests for the descriptors the two render-surface leaf listeners contribute.
 *
 * Pins, for the agenda and the hours leaf alike, every field of the contributed
 * descriptor, the null provider, that any other event is ignored, and that a
 * failure while building the descriptor costs only that leaf and is logged as a
 * warning naming it.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\RegisterAgendaLeafListener;
use OCA\Humaniq\Listener\RegisterHoursLeafListener;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The agenda and hours leaf listeners contribute their exact descriptors.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-both-halves-of-the-leaf-agree
 */
class RenderLeafDescriptorTest extends TestCase {

	/**
	 * Each listener with the descriptor fields it must contribute.
	 *
	 * @return array<string, array{0: class-string, 1: array<string, mixed>}>
	 */
	public static function leaves(): array {
		return [
			'agenda' => [
				RegisterAgendaLeafListener::class,
				[
					'id' => 'humaniq-agenda',
					'label' => 'vertaald:Agenda',
					'icon' => 'CalendarMonthOutline',
					'kinds' => ['render-surface'],
					'requiredApp' => 'humaniq',
					'group' => 'workflow',
					'surfaces' => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'],
					'referenceType' => 'agenda',
					'requiresPermission' => null,
					'renderMode' => 'mount',
				],
			],
			'hours' => [
				RegisterHoursLeafListener::class,
				[
					'id' => 'humaniq-hours',
					'label' => 'vertaald:Hours',
					'icon' => 'ClockOutline',
					'kinds' => ['render-surface'],
					'requiredApp' => 'humaniq',
					'group' => 'workflow',
					'surfaces' => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'],
					'referenceType' => 'hours',
					'requiresPermission' => null,
					'renderMode' => 'mount',
				],
			],
		];

	}//end leaves()

	/**
	 * The contributed descriptor carries every expected field and no provider.
	 *
	 * @param class-string         $listenerClass The listener.
	 * @param array<string, mixed> $expected      The descriptor fields.
	 *
	 * @return void
	 *
	 * @dataProvider leaves
	 */
	public function testContributesTheExactDescriptor(string $listenerClass, array $expected): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'vertaald:'.$text);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$event = new RegisterLeafProvidersEvent();
		(new $listenerClass($l10n, $logger))->handle($event);

		$leaves = $event->getLeaves();
		$this->assertCount(1, $leaves);
		$this->assertNull($leaves[0]['provider']);
		$this->assertSame($expected, get_object_vars($leaves[0]['descriptor']));

	}//end testContributesTheExactDescriptor()

	/**
	 * Any other event is ignored without touching the label.
	 *
	 * @param class-string $listenerClass The listener.
	 *
	 * @return void
	 *
	 * @dataProvider leaves
	 */
	public function testIgnoresOtherEvents(string $listenerClass): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->never())->method('t');

		(new $listenerClass($l10n, $this->createMock(LoggerInterface::class)))->handle(new Event());

		$this->addToAssertionCount(1);

	}//end testIgnoresOtherEvents()

	/**
	 * A failure while describing the leaf is logged as a warning naming it, and no leaf is contributed.
	 *
	 * @param class-string         $listenerClass The listener.
	 * @param array<string, mixed> $expected      The descriptor fields.
	 *
	 * @return void
	 *
	 * @dataProvider leaves
	 */
	public function testFailureCostsOnlyThisLeafAndIsLogged(string $listenerClass, array $expected): void {
		$failure = new RuntimeException('boom');
		$l10n    = $this->createMock(IL10N::class);
		$l10n->method('t')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('Humaniq could not register the '.$expected['id'].' leaf: boom', ['exception' => $failure]);

		$event = new RegisterLeafProvidersEvent();
		(new $listenerClass($l10n, $logger))->handle($event);

		$this->assertSame([], $event->getLeaves());

	}//end testFailureCostsOnlyThisLeafAndIsLogged()
}//end class
