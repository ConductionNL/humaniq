<?php

/**
 * Interview Calendar Sync Command
 *
 * `occ humaniq:interview:sync [--from DATE]` — the operator trigger for
 * interview-scheduling (design.md D6): upserts one timed VEVENT per
 * `scheduled` Interview into the configured shared Nextcloud calendar,
 * removes the event of any `cancelled` Interview, leaves `completed`
 * Interviews' events untouched, and reconciles orphaned events whose source
 * was hard-deleted from the register. Prints one outcome line per touched
 * Interview plus a summary. Duck-typed no-op (`skipped-no-calendar`, exit 0)
 * when the calendar is not configured or cannot be resolved. No event
 * listener or background job ships in this change — the `Application`
 * `uitnodigen` transition carries no humaniq-owned lifecycle hook to hang an
 * automatic sync on, so this command (and the guarded manifest action) are
 * run on operator demand until `humaniq-rule-compliance-enforcement` wires
 * guards/events.
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\InterviewCalendarService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that syncs scheduled Interviews onto the configured shared
 * Nextcloud calendar.
 */
class InterviewCalendarSyncCommand extends Command {

	/**
	 * @param InterviewCalendarService $service The interview-calendar-sync service.
	 */
	public function __construct(
		private readonly InterviewCalendarService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('humaniq:interview:sync')
			->setDescription('Sync scheduled recruiting interviews onto the configured shared Nextcloud calendar (operator trigger; run on demand). Calendar edits made by hand are overwritten by the next sync (one-way projection).')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'Bound the upsert set to Interviews whose scheduledStart is on/after this date (Y-m-d). Reconciliation is always unbounded.');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when no Interview ended `failed` (a fully skipped run is a healthy 0), 1 otherwise.
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-007
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$fromOption = $input->getOption('from');
		$from = (is_string($fromOption) === true && trim($fromOption) !== '') ? trim($fromOption) : null;

		$results = $this->service->sync($from);

		$output->writeln('<info>Humaniq interview calendar sync</info>');

		$failed = 0;
		foreach ($results as $result) {
			$status = (string)$result['status'];
			$label = ($result['type'] !== null && $result['sourceId'] !== null)
				? sprintf('%s %s', (string)$result['type'], (string)$result['sourceId'])
				: 'run';
			$line = sprintf('  %s: %s — %s', $label, $status, (string)$result['message']);

			if ($status === 'failed') {
				$failed++;
				$output->writeln('<error>' . $line . '</error>');
				continue;
			}

			$output->writeln($line);
		}

		$output->writeln(sprintf('  %d uitkomst(en), %d mislukt.', count($results), $failed));

		return $failed > 0 ? 1 : 0;
	}//end execute()

}//end class
