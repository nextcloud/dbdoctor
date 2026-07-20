<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Controller;

use OCA\DBDoctor\AppInfo\Application;
use OCA\DBDoctor\Advisory\RuleSet;
use OCA\DBDoctor\Advisory\RuleSet\Mysql as MysqlRuleSet;
use OCA\DBDoctor\Advisory\RuleSet\Postgres as PostgresRuleSet;
use OCA\DBDoctor\Service\Advisor;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCA\DBDoctor\Service\HistoryService;
use OCA\DBDoctor\Service\RevertedFixService;
use OCA\DBDoctor\Service\Score;
use OCA\DBDoctor\Service\Snapshot;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Endpoints driving the dashboard:
 *   GET    /check/latest     — most recent stored run (or null if none)
 *   POST   /check/run        — run advisor synchronously, persist, return results
 *   GET    /check/history    — per-rule history series for the sparkline
 *
 * Every endpoint is gated to admin users.  The controller performs the
 * gate even though IDelegatedSettings already prevents non-admins from
 * loading the page — defence in depth.
 */
class CheckController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private DatabaseProbe $probe,
		private Advisor $advisor,
		private Score $score,
		private HistoryService $history,
		private RevertedFixService $revertedFixes,
		private MysqlRuleSet $mysqlRules,
		private PostgresRuleSet $postgresRules,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * GET /api/v1/check/latest
	 */
	public function latest(): DataResponse {
		$this->assertAdmin();

		$entry = $this->history->latest();
		if ($entry === null) {
			return new DataResponse(null, Http::STATUS_NO_CONTENT);
		}

		// We don't decode the stored results JSON here to keep the
		// response cheap; the Vue layer parses it once.  Send the
		// "envelope" (score, grade, counts) plus the encoded results.
		return new DataResponse([
			'ranAt' => $entry->getCreatedAt(),
			'dbFlavour' => $entry->getDbFlavour(),
			'dbVersion' => $entry->getDbVersion(),
			// Probed live rather than stored with the check — the info
			// box should show the size as of now, not as of the last run.
			'dbSize' => $this->probe->databaseSizeBytes(),
			'score' => $entry->getScore(),
			'grade' => $entry->getGrade(),
			'counts' => [
				'alert' => $entry->getFailedAlerts(),
				'warning' => $entry->getFailedWarnings(),
				'notice' => $entry->getFailedNotices(),
				'total' => $entry->getTotalRules(),
			],
			'results' => $this->decodeResults($entry->getResultsJson()),
		]);
	}

	/**
	 * POST /api/v1/check/run
	 *
	 * Synchronous: advisor runs against a fresh snapshot and the
	 * caller blocks until persistence is done.  Most checks finish
	 * in well under 200 ms — running this in a background queue
	 * would only add UX latency without buying anything.
	 *
	 * Rate-limited: each run executes a full SHOW GLOBAL STATUS /
	 * VARIABLES snapshot plus ~70 rule evaluations, so a scripted
	 * client hammering it could load the database server.  The limit
	 * is generous because applying a fix auto-triggers a refresh run,
	 * so a user clicking through many one-click fixes legitimately
	 * runs this several times a minute — 30/min still stops abuse.
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function run(): DataResponse {
		$this->assertAdmin();

		try {
			$snapshot = $this->probe->snapshot();
			if (!$snapshot->isSupported()) {
				throw new OCSException(
					'DB Doctor only supports MySQL, MariaDB, and PostgreSQL — your database is something else.',
					Http::STATUS_PRECONDITION_FAILED,
				);
			}

			$ruleSet = $this->ruleSetFor($snapshot);
			$results = $this->advisor->run($ruleSet, $snapshot);
			$score = $this->score->compute($results);
			$entry = $this->history->record($snapshot, $results, $score);

			return new DataResponse([
				'ranAt' => $entry->getCreatedAt(),
				'dbFlavour' => $entry->getDbFlavour(),
				'dbVersion' => $entry->getDbVersion(),
				'dbSize' => $this->probe->databaseSizeBytes(),
				'score' => $entry->getScore(),
				'grade' => $entry->getGrade(),
				'counts' => [
					'alert' => $entry->getFailedAlerts(),
					'warning' => $entry->getFailedWarnings(),
					'notice' => $entry->getFailedNotices(),
					'total' => $entry->getTotalRules(),
				],
				'results' => array_map(static fn($r) => $r->jsonSerialize(), $results),
			]);
		} catch (OCSException $e) {
			throw $e;
		} catch (\Throwable $e) {
			// Without this catch, an uncaught exception during the
			// advisor / persistence step can land outside the OCS error
			// middleware (e.g. during response serialization), giving
			// the client an empty body with a 500.  Surface the message
			// instead so the UI can show what went wrong.
			$this->logger->error(
				'DBDoctor: check/run failed: {msg}',
				['msg' => $e->getMessage(), 'app' => 'dbdoctor', 'exception' => $e],
			);
			throw new OCSException(
				'Check failed: ' . $e->getMessage(),
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}

	/**
	 * GET /api/v1/check/ping
	 *
	 * Runs a cheap read-only query (SHOW TABLES on MySQL/MariaDB, a
	 * pg_tables catalog read on Postgres) and returns the elapsed
	 * milliseconds.  Polled at ~1 Hz from the dashboard's live latency
	 * chart — the query is intentionally inexpensive but real, so the
	 * timing reflects parser + planner + result transit, not just a
	 * round-trip ping.
	 */
	public function ping(): DataResponse {
		$this->assertAdmin();
		$start = microtime(true);
		try {
			$this->probe->ping();
			$elapsedMs = (microtime(true) - $start) * 1000.0;
			return new DataResponse([
				'elapsedMs' => round($elapsedMs, 2),
				'ok' => true,
			]);
		} catch (\Throwable $e) {
			$elapsedMs = (microtime(true) - $start) * 1000.0;
			return new DataResponse([
				'elapsedMs' => round($elapsedMs, 2),
				'ok' => false,
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * GET /api/v1/check/history?ruleId=...&days=30
	 */
	public function history(string $ruleId, int $days = 30): DataResponse {
		$this->assertAdmin();
		$days = max(1, min(90, $days));
		return new DataResponse([
			'ruleId' => $ruleId,
			'days' => $days,
			'series' => $this->history->seriesForRule($ruleId, $days),
		]);
	}

	/**
	 * GET /api/v1/check/score-history?days=30
	 *
	 * Envelope-only series (timestamp, score, grade) for the headline
	 * trend chart — never decodes the stored per-rule JSON.
	 */
	public function scoreHistory(int $days = 30): DataResponse {
		$this->assertAdmin();
		$days = max(1, min(90, $days));
		return new DataResponse([
			'days' => $days,
			'series' => $this->history->scoreSeries($days),
		]);
	}

	/**
	 * GET /api/v1/check/reverted-fixes
	 *
	 * Runtime fixes DB Doctor applied that no longer hold on the live
	 * server (typically lost to a restart) — the admin should make these
	 * permanent in the config file.
	 */
	public function revertedFixes(): DataResponse {
		$this->assertAdmin();
		return new DataResponse(['reverted' => $this->revertedFixes->detect()]);
	}

	private function ruleSetFor(Snapshot $snapshot): RuleSet {
		return match ($snapshot->flavour) {
			Snapshot::FLAVOUR_PGSQL => $this->postgresRules,
			default => $this->mysqlRules,
		};
	}

	private function assertAdmin(): void {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			throw new OCSException('Administrator access required.', Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function decodeResults(string $blob): array {
		if (str_starts_with($blob, 'gz:')) {
			$payload = base64_decode(substr($blob, 3), true);
			if ($payload === false) {
				return [];
			}
			$json = gzdecode($payload);
			if ($json === false) {
				return [];
			}
			$blob = $json;
		}
		$decoded = json_decode($blob, true);
		return is_array($decoded) ? array_values($decoded) : [];
	}
}
