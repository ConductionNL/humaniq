<?php

/**
 * Calendar Sync Command
 *
 * `occ humaniq:calendar:sync [--from DATE]` — the MVP trigger for
 * leave-calendar-nc (design.md D6): upserts one all-day VEVENT per approved
 * LeaveRequest / SickLeaveCase into the configured shared Nextcloud
 * calendar, removes events that left scope, and reconciles orphaned events
 * whose source was hard-deleted from the register. Prints one outcome line
 * per touched source plus a summary. Duck-typed no-op
 * (`skipped-no-calendar`, exit 0) when the calendar is not configured or
 * cannot be resolved. No event listener or background job ships in this
 * change — LeaveRequest transitions carry no humaniq-owned lifecycle hook yet,
 * so this command is run on operator demand until
 * `humaniq-rule-compliance-enforcement` wires guards/events.
 *
 * @category Command
 * @package  OCA\Humaniq\Command
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
 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\LeaveCalendarService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that syncs approved absence onto the configured shared
 * Nextcloud calendar.
 */
class CalendarSyncCommand extends Command {

	/**
	 * @param LeaveCalendarService $service The calendar-sync service.
	 */
	public function __construct(
		private readonly LeaveCalendarService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Declare the command name, description and CLI options.
	 *
	 * @spec exclude Symfony Console plumbing — declares only this command's name, description and options; the sync behaviour those options drive is specified at openspec/specs/leave-calendar-nc/spec.md#REQ-LC-007, cited on execute() below.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('humaniq:calendar:sync')
			->setDescription('Sync approved leave and sickness absence onto the configured shared Nextcloud calendar (MVP trigger; run on operator demand). Calendar edits made by hand are overwritten by the next sync (one-way projection).')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'Bound the upsert set to sources whose absence period ends on/after this date (Y-m-d). Reconciliation and open sickness cases are always unbounded.');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when no source ended `failed` (a fully skipped run is a healthy 0), 1 otherwise.
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-007
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$results = $this->service->sync(CommandOptions::trimmed($input, 'from'));

		$output->writeln('<info>Humaniq leave calendar sync</info>');

		return (new OutcomeReport($output, '  %d uitkomst(en), %d mislukt.'))->exitCode($results, self::describeOutcome(...));
	}//end execute()

	/**
	 * The report line for one sync outcome: per source, or for the run as a whole.
	 *
	 * @param array<string, mixed> $result The outcome.
	 * @param string               $status The outcome's status.
	 *
	 * @return string
	 */
	private static function describeOutcome(array $result, string $status): string {
		$label = ($result['type'] !== null && $result['sourceId'] !== null)
			? sprintf('%s %s', (string)$result['type'], (string)$result['sourceId'])
			: 'run';

		return sprintf('  %s: %s — %s', $label, $status, (string)$result['message']);

	}//end describeOutcome()

}//end class
