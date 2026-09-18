<?php

/**
 * CalendarSubscriptionPoller tests
 *
 * Pins the two properties that decide whether a subscribed calendar helps or
 * harms: a failed poll keeps the periods it read last time, and nothing this
 * class does writes to the feed.
 *
 * The successful poll comes first as the control. Without it, "the cache
 * survived a failure" cannot be told apart from "the cache is never written".
 *
 * The gateway double uses `onlyMethods`, so it cannot answer a method
 * HoursRegisterGateway does not have.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CalendarSubscriptionPoller;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\IcalBusyParser;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for CalendarSubscriptionPoller.
 */
class CalendarSubscriptionPollerTest extends TestCase {

	/**
	 * The writes the poller made, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * The requests the poller issued, as [method, url] pairs.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $requests = [];

	/**
	 * Reset the recorders.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->writes = [];
		$this->requests = [];
	}//end setUp()

	/**
	 * One subscription that has been polled successfully before.
	 *
	 * @return array<string, mixed> The subscription.
	 */
	private function subscription(): array {
		return [
			'id' => 'sub-1',
			'employeeId' => 'emp-1',
			'feedUrl' => 'https://calendar.example.invalid/emp-1.ics',
			'active' => true,
			'lastPollStatus' => 'ok',
			'busyPeriods' => [['start' => '2026-06-01T09:00:00+02:00', 'end' => '2026-06-01T10:00:00+02:00']],
		];
	}//end subscription()

	/**
	 * Build a poller whose HTTP client answers one body, or throws.
	 *
	 * @param string|null $body The iCalendar body, or null to fail the request.
	 *
	 * @return CalendarSubscriptionPoller The subject.
	 */
	private function poller(?string $body): CalendarSubscriptionPoller {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['loadAll', 'save'])
			->getMock();

		$gateway->method('loadAll')->willReturn([$this->subscription()]);
		$gateway->method('save')->willReturnCallback(
			function (array $payload, string $schema, ?string $uuid = null): object {
				$this->writes[] = ['payload' => $payload, 'schema' => $schema, 'uuid' => $uuid];

				return new \stdClass();
			}
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn((string)$body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options = []) use ($body, $response): IResponse {
				$this->requests[] = ['GET', $url];
				if ($body === null) {
					throw new RuntimeException('could not connect');
				}

				return $response;
			}
		);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new CalendarSubscriptionPoller(
			gateway: $gateway,
			clientService: $clientService,
			parser: new IcalBusyParser(),
			logger: new NullLogger()
		);
	}//end poller()

	/**
	 * The control: a feed that answers is cached, and the cached periods carry
	 * nothing but their start and end.
	 *
	 * @return void
	 */
	public function testASuccessfulPollCachesThePeriods(): void {
		$ical = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:Tandarts\r\n"
			. "DTSTART:20260604T070000Z\r\nDTEND:20260604T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

		$report = $this->poller($ical)->pollAll();

		$this->assertSame(['polled' => 1, 'ok' => 1, 'degraded' => 0], $report);
		$this->assertCount(1, $this->writes);

		$payload = $this->writes[0]['payload'];
		$this->assertSame('ok', $payload['lastPollStatus']);
		$this->assertCount(1, $payload['busyPeriods']);
		$this->assertSame(['start', 'end'], array_keys($payload['busyPeriods'][0]));
		$this->assertStringNotContainsString('Tandarts', json_encode($payload) ?: '');
	}//end testASuccessfulPollCachesThePeriods()

	/**
	 * An unreachable feed does not empty an agenda: the degradation is
	 * recorded and the previously cached periods are written back unchanged.
	 *
	 * @return void
	 */
	public function testAnUnreachableFeedKeepsThePreviousCache(): void {
		$report = $this->poller(null)->pollAll();

		$this->assertSame(['polled' => 1, 'ok' => 0, 'degraded' => 1], $report);
		$this->assertCount(1, $this->writes);

		$payload = $this->writes[0]['payload'];
		$this->assertSame('unreachable', $payload['lastPollStatus']);
		$this->assertSame(
			$this->subscription()['busyPeriods'],
			$payload['busyPeriods'],
			'A calendar server that is briefly down must not make somebody look free all week'
		);
	}//end testAnUnreachableFeedKeepsThePreviousCache()

	/**
	 * Something that answers but is not a calendar is a failed read, not an
	 * empty one.
	 *
	 * @return void
	 */
	public function testAnAnswerThatIsNotACalendarIsADegradation(): void {
		$report = $this->poller('<html><body>Sign in</body></html>')->pollAll();

		$this->assertSame(1, $report['degraded']);
		$this->assertSame('unreadable', $this->writes[0]['payload']['lastPollStatus']);
		$this->assertSame($this->subscription()['busyPeriods'], $this->writes[0]['payload']['busyPeriods']);
	}//end testAnAnswerThatIsNotACalendarIsADegradation()

	/**
	 * Nothing is written outward: every request the poller makes against a
	 * subscribed address is a GET.
	 *
	 * @return void
	 */
	public function testOnlyReadsAreIssuedAgainstTheFeed(): void {
		$ical = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:20260604T070000Z\r\nDTEND:20260604T080000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$this->poller($ical)->pollAll();

		$this->assertSame([['GET', 'https://calendar.example.invalid/emp-1.ics']], $this->requests);
		foreach ($this->writes as $write) {
			$this->assertSame(
				'CalendarSubscription',
				$write['schema'],
				'Every write the poller makes lands in humaniq, never in the feed'
			);
		}
	}//end testOnlyReadsAreIssuedAgainstTheFeed()
}//end class
