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
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\RetroAdjustmentService;
use OCA\Humaniq\Service\RetroCorrection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that computes/settles a TWK retro-adjustment.
 *
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */
class PayrollAdjustCommand extends Command {

	/**
	 * The required options, in the order they are checked, with the refusal for a blank one.
	 *
	 * @var array<string, string>
	 */
	private const REQUIRED_OPTIONS = [
		'original-period' => '--original-period is verplicht (JJJJ-MM).',
		'employee' => '--employee is verplicht.',
		'correction-ref' => '--correction-ref is verplicht.',
	];

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
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-007
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
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-007
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$missing = self::missingRequiredOption($input);
		if ($missing !== null) {
			$output->writeln('<error>' . $missing . '</error>');
			return 1;
		}

		$result = $this->service->adjustFor(
			self::correction($input),
			CommandOptions::trimmed($input, 'correction-type'),
			CommandOptions::trimmed($input, 'settlement-period'),
			(bool)$input->getOption('apply')
		);

		self::writeResult($output, $result);

		return in_array((string)$result['status'], ['computed', 'applied'], true) === true ? 0 : 1;
	}//end execute()

	/**
	 * The correction the options describe: the trimmed required options and the --gross value.
	 *
	 * @param InputInterface $input Console input.
	 *
	 * @return RetroCorrection
	 */
	private static function correction(InputInterface $input): RetroCorrection {
		return new RetroCorrection(
			trim((string)$input->getOption('original-period')),
			trim((string)$input->getOption('employee')),
			trim((string)$input->getOption('correction-ref')),
			self::grossOption($input)
		);

	}//end correction()

	/**
	 * The refusal for the first blank required option, or null when all are given.
	 *
	 * @param InputInterface $input Console input.
	 *
	 * @return string|null
	 */
	private static function missingRequiredOption(InputInterface $input): ?string {
		foreach (self::REQUIRED_OPTIONS as $name => $refusal) {
			if (trim((string)$input->getOption($name)) === '') {
				return $refusal;
			}
		}

		return null;

	}//end missingRequiredOption()

	/**
	 * The --gross value as a number, or null when it is blank or not numeric.
	 *
	 * @param InputInterface $input Console input.
	 *
	 * @return float|null
	 */
	private static function grossOption(InputInterface $input): ?float {
		$grossOption = $input->getOption('gross');

		return (is_string($grossOption) === true && trim($grossOption) !== '' && is_numeric($grossOption) === true) ? (float)$grossOption : null;

	}//end grossOption()

	/**
	 * Write the correction's outcome, and its delta when one was computed.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $result The adjustFor() outcome.
	 *
	 * @return void
	 */
	private static function writeResult(OutputInterface $output, array $result): void {
		$output->writeln('<info>Humaniq TWK-correctie</info>');
		$output->writeln(sprintf('  originele periode : %s', (string)$result['originalPeriod']));
		$output->writeln(sprintf('  medewerker        : %s', (string)$result['employeeId']));
		$output->writeln(sprintf('  correctionRef     : %s', (string)$result['correctionRef']));
		$output->writeln(sprintf('  correctie         : %s', (string)($result['adjustmentId'] ?? 'geen')));
		$output->writeln(sprintf('  status            : %s — %s', (string)$result['status'], (string)$result['message']));
		$output->writeln(sprintf('  idempotent        : %s', ($result['idempotent'] === true ? 'ja (bestaande correctie bijgewerkt)' : 'nee (nieuwe correctie)')));

		if (is_array($result['delta']) === true) {
			self::writeDelta($output, $result);
		}

	}//end writeResult()

	/**
	 * Write the computed delta, the engine version and the settlement period.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $result The adjustFor() outcome, with a delta.
	 *
	 * @return void
	 */
	private static function writeDelta(OutputInterface $output, array $result): void {
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

	}//end writeDelta()

}//end class
