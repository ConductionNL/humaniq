<?php

/**
 * Proforma Payslip Command
 *
 * `occ humaniq:payroll:proforma --gross 3800 [--table wit|groen]
 * [--date-of-birth YYYY-MM-DD] [--parttime 1.0] [--bijzonder 0]
 * [--period YYYY-MM] [--aof laag|hoog] [--whk 1.52]` — the support-facing
 * mirror of `POST /api/payroll/proforma` (proforma-payslip design.md D5):
 * calls the same `ProformaPayslipService::simulate()` and prints the full
 * gross-to-net breakdown. Persists nothing; gives support a way to reproduce
 * a net figure with no browser and no DB access.
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
 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use OCA\Humaniq\Service\ProformaPayslipService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command that runs the persist-nothing pro-forma simulation from CLI flags.
 */
class ProformaPayslipCommand extends Command {

	/**
	 * The breakdown figures, in print order, with their line format.
	 *
	 * @var array<string, string>
	 */
	private const FIGURE_LINES = [
		'grossPay' => '  bruto                   : %.2f',
		'loonheffing' => '  loonheffing             : %.2f',
		'arbeidskorting' => '  arbeidskorting          : %.2f',
		'volksverzekeringen' => '  volksverzekeringen      : %.2f',
		'zvw' => '  zvw                     : %.2f',
		'werknemersverzekeringen' => '  werknemersverzekeringen : %.2f',
		'employerCharges' => '  werkgeverslasten        : %.2f',
		'vakantiegeldReserved' => '  vakantiegeldreservering : %.2f',
		'nettoPay' => '  netto                   : %.2f',
		'appliedTaxRate' => '  toegepast tarief        : %.2f%%',
	];

	/**
	 * @param ProformaPayslipService $service The stateless pro-forma simulation service.
	 */
	public function __construct(
		private readonly ProformaPayslipService $service,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-003
	 */
	protected function configure(): void {
		$this->setName('humaniq:payroll:proforma')
			->setDescription('Simuleer een loonstrook (bruto-naar-netto) zonder iets op te slaan.')
			->addOption('gross', null, InputOption::VALUE_REQUIRED, 'Bruto maandsalaris (euro).')
			->addOption('table', null, InputOption::VALUE_REQUIRED, 'Loonheffingstabel: wit of groen (default wit).')
			->addOption('date-of-birth', null, InputOption::VALUE_REQUIRED, 'Geboortedatum (JJJJ-MM-DD); onbekend = onder AOW-leeftijd.')
			->addOption('parttime', null, InputOption::VALUE_REQUIRED, 'Deeltijdfactor, bv. 0.8 (default 1.0).')
			->addOption('bijzonder', null, InputOption::VALUE_REQUIRED, 'Eenmalige bijzondere beloning (euro, default 0).')
			->addOption('period', null, InputOption::VALUE_REQUIRED, 'Loonperiode (JJJJ-MM, default huidige maand).')
			->addOption('aof', null, InputOption::VALUE_REQUIRED, 'Aof-tariefklasse: laag of hoog (default werkgeversinstelling).')
			->addOption('whk', null, InputOption::VALUE_REQUIRED, 'Whk-percentage (default werkgeversinstelling).');

	}//end configure()

	/**
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 on a successful simulation, 1 on malformed input.
	 *
	 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-003
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$breakdown = $this->service->simulate(self::simulationParams($input));
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		self::writeBreakdown($output, $breakdown);

		if ((float)$breakdown['input']['bijzonder'] > 0.0) {
			$output->writeln('  <comment>let op: bijzondere beloning is een combinedLoon-schatting, geen wettelijk bijzonder tarief.</comment>');
		}

		return 0;
	}//end execute()

	/**
	 * The simulation parameters the options give.
	 *
	 * @param InputInterface $input Console input.
	 *
	 * @return array<string, mixed>
	 */
	private static function simulationParams(InputInterface $input): array {
		return [
			'gross' => $input->getOption('gross'),
			'table' => $input->getOption('table'),
			'loonheffingskorting' => true,
			'dateOfBirth' => $input->getOption('date-of-birth'),
			'period' => $input->getOption('period'),
			'parttime' => $input->getOption('parttime'),
			'bijzonder' => $input->getOption('bijzonder'),
			'aof' => $input->getOption('aof'),
			'whk' => $input->getOption('whk'),
		];

	}//end simulationParams()

	/**
	 * Write the pro-forma breakdown.
	 *
	 * @param OutputInterface      $output    Console output.
	 * @param array<string, mixed> $breakdown The simulate() breakdown.
	 *
	 * @return void
	 */
	private static function writeBreakdown(OutputInterface $output, array $breakdown): void {
		$output->writeln('<info>Humaniq pro-forma loonstrook (niets opgeslagen)</info>');
		$output->writeln(sprintf('  periode                 : %s', (string)$breakdown['input']['period']));
		$output->writeln(sprintf('  tabel                   : %s', (string)$breakdown['input']['table']));
		foreach (self::FIGURE_LINES as $field => $format) {
			$output->writeln(sprintf($format, (float)$breakdown[$field]));
		}

	}//end writeBreakdown()

}//end class
