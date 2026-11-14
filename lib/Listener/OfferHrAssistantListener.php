<?php

/**
 * Humaniq OfferHrAssistantListener
 *
 * Offers Hermiq the HR-vraagbaak agent template when Hermiq asks installed apps
 * for templates (self-service-policy-assistant D2). Hermiq dispatches
 * `OCA\Hermiq\Event\CollectAgentTemplatesEvent` on install, on upgrade and on
 * an admin's "Check apps for templates"; the template lands in its Store as
 * quarantined until an admin reviews it. The event is named by string, so
 * humaniq installs without Hermiq.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/changes/self-service-policy-assistant/specs/hr-policy-assistant/spec.md#requirement-the-assistant-shall-answer-from-the-employers-published-policies-and-cite-them-req-hpa-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Answers Hermiq's template collection with the HR question assistant.
 *
 * The template grants no tools: the humaniq tools an instance derives from
 * the MCP blocks of Announcement, LeaveType, Cao and Normfunctie are granted
 * by the admin who reviews the template, so nothing is reachable before that
 * review.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/self-service-policy-assistant/specs/hr-policy-assistant/spec.md#requirement-the-assistant-shall-answer-from-the-employers-published-policies-and-cite-them-req-hpa-001
 */
class OfferHrAssistantListener implements IEventListener {

	/**
	 * The event Hermiq dispatches to collect templates, by name.
	 */
	public const EVENT = 'OCA\Hermiq\Event\CollectAgentTemplatesEvent';

	/**
	 * The template's name; Hermiq knows an offer by app id and name.
	 */
	public const NAME = 'HR-vraagbaak';

	/**
	 * Offer the template, when the event can take an offer.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/self-service-policy-assistant/specs/hr-policy-assistant/spec.md#requirement-the-assistant-shall-answer-from-the-employers-published-policies-and-cite-them-req-hpa-001
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'offer') === false) {
			return;
		}

		$event->offer('humaniq', (string)json_encode(self::package(), JSON_UNESCAPED_UNICODE));
	}//end handle()

	/**
	 * The template package, in the format of Hermiq's Store export.
	 *
	 * @return array{name: string, description: string, category: string, systemPrompt: string, tools: array<int, string>, version: string}
	 *
	 * @spec openspec/changes/self-service-policy-assistant/specs/hr-policy-assistant/spec.md#requirement-the-assistant-shall-answer-from-the-employers-published-policies-and-cite-them-req-hpa-001
	 */
	public static function package(): array {
		return [
			'name' => self::NAME,
			'description' => 'Answers employees\' questions about the employer\'s own HR policies, leave types, collective agreement and jobs, with the source cited. Personal questions go to Mijn HR.',
			'category' => 'hr',
			'systemPrompt' => implode(
				"\n\n",
				[
					'Je beantwoordt vragen van medewerkers over het HR-beleid van hun eigen werkgever. Antwoord alleen vanuit de humaniq-bronnen: mededelingen en beleidsstukken, verlofsoorten, de cao en de functies. Noem bij elk antwoord de bron die je gebruikte: de titel van de mededeling, de verlofsoort of de cao met haar versie.',
					'Gaat een vraag over de eigen gegevens van de vrager, zoals het eigen verlofsaldo, loon, verzuim of contract, beantwoord die dan niet zelf maar verwijs naar Mijn HR: MijnVerlof voor verlof, MijnLoonstroken voor loon en MijnGegevens voor de eigen gegevens.',
					'Vind je geen bron die de vraag beantwoordt, zeg dat dan en bied aan de vraag door te sturen naar HR. Verzin geen beleid.',
					'You answer employees\' questions about their own employer\'s HR policies. Answer only from the humaniq sources: announcements and policy documents, leave types, the collective agreement and the jobs. Cite the source you used in every answer. For questions about the asker\'s own leave balance, pay, absence or contract, do not answer yourself but point to Mijn HR (MijnVerlof, MijnLoonstroken, MijnGegevens). When no source answers the question, say so and offer to forward it to HR. Never invent policy.',
				]
			),
			'tools' => [],
			'version' => '1.0.0',
		];
	}//end package()
}//end class
