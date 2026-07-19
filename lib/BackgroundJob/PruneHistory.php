<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\BackgroundJob;

use OCA\DBDoctor\Db\AuditEntryMapper;
use OCA\DBDoctor\Db\HistoryEntryMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Nightly cleanup: keep history for 90 days, audit for 365 days.
 */
class PruneHistory extends TimedJob {
	private const HISTORY_RETENTION_DAYS = 90;
	private const AUDIT_RETENTION_DAYS = 365;

	public function __construct(
		ITimeFactory $time,
		private HistoryEntryMapper $history,
		private AuditEntryMapper $audit,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Run at most once per 23h so a slightly slow cron doesn't
		// skip a whole day's run.
		$this->setInterval(23 * 3600);
	}

	protected function run(mixed $argument): void {
		$now = $this->time->getTime();
		$historyCutoff = $now - self::HISTORY_RETENTION_DAYS * 86400;
		$auditCutoff = $now - self::AUDIT_RETENTION_DAYS * 86400;

		try {
			$pruned = $this->history->deleteOlderThan($historyCutoff);
			$prunedAudit = $this->audit->deleteOlderThan($auditCutoff);
			$this->logger->info(
				'DBDoctor: pruned {history} history rows and {audit} audit rows',
				['history' => $pruned, 'audit' => $prunedAudit, 'app' => 'dbdoctor'],
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DBDoctor: prune job failed: {msg}',
				['msg' => $e->getMessage(), 'app' => 'dbdoctor'],
			);
		}
	}
}
