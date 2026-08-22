<?php

/**
 * Payroll Adjust Command
 *
 * `occ humaniq:payroll:adjust --original-period YYYY-MM --employee EID
 * --correction-ref REF [--gross AMOUNT] [--correction-type TYPE]
 * [--settlement-period YYYY-MM] [--apply]` -- the retro-adjustments MVP
 * trigger (design.md D3/D4/D5): computes (and, with `--apply`, settles) a
 * terugwerkende kracht (TWK) correction for a SEALED prior-period payslip and
 * prints the computed delta plus the idempotency outcome. Never mutates the
 * sealed original Payslip/PayrollRun -- only reads them to diff against.
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
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\RetroAdjustmentService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that computes/settles a TWK retro-adjustment.
 */
class PayrollAdjustCommand extends Command {

	/**
	 * @param RetroAdjustmentService $service The delta-computation + settlement service.
	 */
	public function __construct(
		private readonly RetroAdjustmentService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-007
	 */
	protected function configure(): void {
		$this->setName('humaniq:payroll:adjust')
			->setDescription('Compute (and, with --apply, settle) a terugwerkende kracht (TWK) correction for a sealed prior-period payslip.')
			->addOption('original-period', null, InputOption::VALUE_REQUIRED, 'The sealed wage period being corrected (YYYY-MM).')
			->addOption('employee', null, InputOption::VALUE_REQUIRED, 'The Employee id.')
			->addOption('correction-ref', null, InputOption::VALUE_REQUIRED, 'Stable idempotency key for this correction event.')
			->addOption('gross', null, InputOption::VALUE_REQUIRED, 'The corrected gross monthly salary (euro). Required on first computation; reused when omitted on a re-run.')
			->addOption('correction-type', null, InputOption::VALUE_REQUIRED, 'Free-text correction classification (e.g. backdated-raise).')
			->addOption('settlement-period', null, InputOption::VALUE_REQUIRED, 'The current open period to settle the delta into (YYYY-MM). Defaults to the most recent open draft PayrollRun\'s period.')
			->addOption('apply', null, InputOption::VALUE_NONE, 'Flip the adjustment to applied and stamp its settlementPayrollRunId -- surfaces it in the next run generation.');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when computed/applied (including an idempotent re-run), 1 on refusal/failure.
	 *
	 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-007
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$originalPeriod = trim((string)$input->getOption('original-period'));
		if ($originalPeriod === '') {
			$output->writeln('<error>--original-period is verplicht (JJJJ-MM).</error>');
			return 1;
		}

		$employeeId = trim((string)$input->getOption('employee'));
		if ($employeeId === '') {
			$output->writeln('<error>--employee is verplicht.</error>');
			return 1;
		}

		$correctionRef = trim((string)$input->getOption('correction-ref'));
		if ($correctionRef === '') {
			$output->writeln('<error>--correction-ref is verplicht.</error>');
			return 1;
		}

		$grossOption = $input->getOption('gross');
		$gross = (is_string($grossOption) === true && trim($grossOption) !== '' && is_numeric($grossOption) === true) ? (float)$grossOption : null;

		$correctionTypeOption = $input->getOption('correction-type');
		$correctionType = (is_string($correctionTypeOption) === true && trim($correctionTypeOption) !== '') ? trim($correctionTypeOption) : null;

		$settlementPeriodOption = $input->getOption('settlement-period');
		$settlementPeriod = (is_string($settlementPeriodOption) === true && trim($settlementPeriodOption) !== '') ? trim($settlementPeriodOption) : null;

		$result = $this->service->adjustFor($originalPeriod, $employeeId, $correctionRef, $gross, $correctionType, $settlementPeriod, (bool)$input->getOption('apply'));

		$output->writeln('<info>Humaniq TWK-correctie</info>');
		$output->writeln(sprintf('  originele periode : %s', (string)$result['originalPeriod']));
		$output->writeln(sprintf('  medewerker        : %s', (string)$result['employeeId']));
		$output->writeln(sprintf('  correctionRef     : %s', (string)$result['correctionRef']));
		$output->writeln(sprintf('  correctie         : %s', (string)($result['adjustmentId'] ?? 'geen')));
		$output->writeln(sprintf('  status            : %s — %s', (string)$result['status'], (string)$result['message']));
		$output->writeln(sprintf('  idempotent        : %s', ($result['idempotent'] === true ? 'ja (bestaande correctie bijgewerkt)' : 'nee (nieuwe correctie)')));

		if (is_array($result['delta']) === true) {
			$delta = $result['delta'];
			$output->writeln(sprintf(
				'  delta             : bruto %.2f / loonheffing %.2f / netto %.2f / werkgeverslasten-wnv %.2f / zvw %.2f / vv %.2f / vakantiegeld %.2f',
				(float)$delta['gross'],
				(float)$delta['loonheffing'],
				(float)$delta['net'],
				(float)$delta['werknemersverzekeringen'],
				(float)$delta['zvw'],
				(float)$delta['volksverzekeringen'],
				(float)$delta['vakantiegeldReserved']
			));
			$output->writeln(sprintf('  engineVersion     : %s', (string)$result['engineVersion']));
			$output->writeln(sprintf('  settlementPeriod  : %s (%s)', (string)$result['settlementPeriod'], (string)$result['settlementLine']));
		}

		$status = (string)$result['status'];
		return in_array($status, ['computed', 'applied'], true) === true ? 0 : 1;
	}//end execute()

}//end class
