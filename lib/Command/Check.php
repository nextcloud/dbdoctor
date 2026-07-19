<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Command;

use OCA\DBDoctor\Advisory\RuleResult;
use OCA\DBDoctor\Advisory\RuleSet;
use OCA\DBDoctor\Advisory\RuleSet\Mysql as MysqlRuleSet;
use OCA\DBDoctor\Advisory\RuleSet\Postgres as PostgresRuleSet;
use OCA\DBDoctor\Service\Advisor;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCA\DBDoctor\Service\HistoryService;
use OCA\DBDoctor\Service\Score;
use OCA\DBDoctor\Service\Snapshot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ dbdoctor:check` — run the advisor from the CLI.
 *
 * Designed for monitoring integration: exit codes follow the Nagios
 * plugin convention (0 = OK, 1 = warnings, 2 = alerts, 3 = unknown /
 * unsupported), and `--output=json` prints a machine-readable document
 * matching the web API's envelope.
 */
class Check extends Command {
	// Nagios plugin exit codes.
	private const EXIT_OK = 0;
	private const EXIT_WARNING = 1;
	private const EXIT_CRITICAL = 2;
	private const EXIT_UNKNOWN = 3;

	public function __construct(
		private DatabaseProbe $probe,
		private Advisor $advisor,
		private Score $score,
		private HistoryService $history,
		private MysqlRuleSet $mysqlRules,
		private PostgresRuleSet $postgresRules,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('dbdoctor:check')
			->setDescription('Run the DB Doctor database check-up and print the results')
			->addOption(
				'output',
				'o',
				InputOption::VALUE_REQUIRED,
				'Output format: plain or json',
				'plain',
			)
			->addOption(
				'no-store',
				null,
				InputOption::VALUE_NONE,
				'Do not record this run in the history table',
			)
			->addOption(
				'failing-only',
				null,
				InputOption::VALUE_NONE,
				'Only list failing rules (plain output)',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$format = (string)$input->getOption('output');
		if (!in_array($format, ['plain', 'json'], true)) {
			$output->writeln('<error>Invalid --output format; use plain or json.</error>');
			return self::EXIT_UNKNOWN;
		}

		$snapshot = $this->probe->snapshot();
		if (!$snapshot->isSupported()) {
			$output->writeln($format === 'json'
				? json_encode(['error' => 'unsupported database'], JSON_PRETTY_PRINT)
				: '<error>DB Doctor only supports MySQL, MariaDB, and PostgreSQL.</error>');
			return self::EXIT_UNKNOWN;
		}

		$ruleSet = $this->ruleSetFor($snapshot);
		$results = $this->advisor->run($ruleSet, $snapshot);
		$score = $this->score->compute($results);

		if (!$input->getOption('no-store')) {
			$this->history->record($snapshot, $results, $score);
		}

		if ($format === 'json') {
			$output->writeln((string)json_encode([
				'ranAt' => time(),
				'dbFlavour' => $snapshot->flavour,
				'dbVersion' => $snapshot->version,
				'score' => $score['score'],
				'grade' => $score['grade'],
				'counts' => $score['counts'],
				'results' => array_map(static fn (RuleResult $r) => $r->jsonSerialize(), $results),
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		} else {
			$this->writePlain($output, $snapshot, $results, $score, (bool)$input->getOption('failing-only'));
		}

		if (($score['counts']['alert'] ?? 0) > 0) {
			return self::EXIT_CRITICAL;
		}
		if (($score['counts']['warning'] ?? 0) > 0) {
			return self::EXIT_WARNING;
		}
		return self::EXIT_OK;
	}

	/**
	 * @param list<RuleResult> $results
	 * @param array{score:int,grade:string,counts:array<string,int>} $score
	 */
	private function writePlain(OutputInterface $output, Snapshot $snapshot, array $results, array $score, bool $failingOnly): void {
		$counts = $score['counts'];
		$output->writeln(sprintf(
			'DB Doctor: grade %s (score %d/100) — %s %s',
			$score['grade'],
			$score['score'],
			$snapshot->flavour,
			$snapshot->version,
		));
		$output->writeln(sprintf(
			'%d alerts, %d warnings, %d notices, %d passing, %d skipped',
			$counts['alert'] ?? 0,
			$counts['warning'] ?? 0,
			$counts['notice'] ?? 0,
			$counts['ok'] ?? 0,
			$counts['skipped'] ?? 0,
		));

		$statusTag = static fn (RuleResult $r): string => match ($r->status) {
			RuleResult::STATUS_FAIL => match ($r->rule->severity) {
				'alert' => '<error>[ALERT]</error>',
				'warning' => '<comment>[WARN ]</comment>',
				default => '[NOTE ]',
			},
			RuleResult::STATUS_OK => '<info>[ OK  ]</info>',
			default => '[SKIP ]',
		};

		foreach ($results as $r) {
			if ($failingOnly && $r->status !== RuleResult::STATUS_FAIL) {
				continue;
			}
			$output->writeln(sprintf('%s %s — %s', $statusTag($r), $r->rule->name, $r->rule->id));
			if ($r->status === RuleResult::STATUS_FAIL) {
				$output->writeln('        ' . $r->rule->recommendation);
				if ($r->justification !== null && $r->justification !== '') {
					$output->writeln('        ' . $r->justification);
				}
			}
		}
	}

	private function ruleSetFor(Snapshot $snapshot): RuleSet {
		return match ($snapshot->flavour) {
			Snapshot::FLAVOUR_PGSQL => $this->postgresRules,
			default => $this->mysqlRules,
		};
	}
}
