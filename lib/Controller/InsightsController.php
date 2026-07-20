<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Controller;

use OCA\DBDoctor\AppInfo\Application;
use OCA\DBDoctor\Service\InsightsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Read-only live-metrics endpoint backing the dashboard's metric tiles
 * (cache hit ratio, connections, throughput).  Admin-gated (framework
 * default + explicit check) and never mutates the database.
 */
class InsightsController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private InsightsService $insights,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * GET /api/v1/insights/metrics
	 *
	 * Polled by the dashboard tiles, so it's rate-limited generously
	 * rather than left wide open.
	 */
	#[UserRateLimit(limit: 60, period: 60)]
	public function metrics(): DataResponse {
		$this->assertAdmin();
		return $this->guard(fn () => $this->insights->liveMetrics());
	}

	/**
	 * Run $fn and wrap the result, turning any failure into a clean OCS
	 * error instead of a broken envelope.
	 *
	 * @param callable(): array<string, mixed> $fn
	 */
	private function guard(callable $fn): DataResponse {
		try {
			return new DataResponse($fn());
		} catch (\Throwable $e) {
			$this->logger->error(
				'DBDoctor: insights query failed: {msg}',
				['msg' => $e->getMessage(), 'app' => 'dbdoctor', 'exception' => $e],
			);
			throw new OCSException('Could not gather database insights: ' . $e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function assertAdmin(): void {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			throw new OCSException('Administrator access required.', Http::STATUS_FORBIDDEN);
		}
	}
}
