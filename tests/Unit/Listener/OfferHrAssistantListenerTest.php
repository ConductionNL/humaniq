<?php

/**
 * Humaniq offers Hermiq the HR-vraagbaak template, without tools, and only to an event that takes offers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/self-service-policy-assistant/specs/hr-policy-assistant/spec.md#requirement-the-assistant-shall-answer-from-the-employers-published-policies-and-cite-them-req-hpa-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\OfferHrAssistantListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Humaniq\Listener\OfferHrAssistantListener
 */
class OfferHrAssistantListenerTest extends TestCase {

	public function testTheTemplateIsOfferedUnderHumaniqsOwnAppId(): void {
		$event = new class extends Event {
			/** @var array<int, array{0: string, 1: string}> */
			public array $offers = [];

			public function offer(string $appId, string $package): void {
				$this->offers[] = [$appId, $package];
			}
		};

		(new OfferHrAssistantListener())->handle($event);

		self::assertCount(1, $event->offers);
		self::assertSame('humaniq', $event->offers[0][0]);
		$package = json_decode($event->offers[0][1], true);
		self::assertSame('HR-vraagbaak', $package['name']);
		self::assertSame('1.0.0', $package['version']);
		self::assertSame([], $package['tools'], 'tools are granted by the reviewing admin');
		foreach (['MijnVerlof', 'MijnLoonstroken', 'MijnGegevens', 'Noem bij elk antwoord de bron', 'Cite the source', 'forward it to HR'] as $needle) {
			self::assertStringContainsString($needle, $package['systemPrompt']);
		}
	}

	public function testAnEventThatTakesNoOfferIsLeftAlone(): void {
		$event = new Event();
		(new OfferHrAssistantListener())->handle($event);
		self::assertFalse($event->isPropagationStopped());
	}

	public function testTheListenerIsRegisteredForHermiqsEventByName(): void {
		self::assertSame('OCA\\Hermiq\\Event\\CollectAgentTemplatesEvent', OfferHrAssistantListener::EVENT);
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
		self::assertStringContainsString('registerEventListener(OfferHrAssistantListener::EVENT, OfferHrAssistantListener::class)', $source);
	}
}
