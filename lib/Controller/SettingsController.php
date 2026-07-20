<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Controller;

use Doctrine\DBAL\DriverManager;
use OCA\DBDoctor\AppInfo\Application;
use OCA\DBDoctor\Service\AuditService;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;

/**
 * Settings + audit endpoints.
 *
 * The `index` response NEVER returns the override password — only a
 * boolean `passwordSet`.  `update` accepts a password only when the
 * field is non-empty, so a re-save with empty password leaves the
 * stored value untouched.
 */
class SettingsController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IConfig $config,
		private ICredentialsManager $credentials,
		private AuditService $audit,
		private DatabaseProbe $probe,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * GET /api/v1/settings
	 */
	public function index(): DataResponse {
		$this->assertAdmin();

		$host = $this->config->getAppValue(Application::APP_ID, 'override.host', '');
		$passwordSet = $host !== ''
			&& $this->credentials->retrieve('', Application::APP_ID . ':override.password') !== null;

		return new DataResponse([
			'override' => [
				'host' => $host,
				'port' => (int)$this->config->getAppValue(Application::APP_ID, 'override.port', '0'),
				'user' => $this->config->getAppValue(Application::APP_ID, 'override.user', ''),
				'database' => $this->config->getAppValue(Application::APP_ID, 'override.database', ''),
				'driver' => $this->config->getAppValue(Application::APP_ID, 'override.driver', 'pdo_mysql'),
				'passwordSet' => $passwordSet,
			],
		]);
	}

	/**
	 * PUT /api/v1/settings
	 */
	#[PasswordConfirmationRequired]
	public function update(
		?string $host = null,
		?int $port = null,
		?string $user = null,
		?string $database = null,
		?string $driver = null,
		?string $password = null, // optional — only stored when present
		?bool $clearPassword = false,
	): DataResponse {
		$this->assertAdmin();

		if ($host !== null) {
			$host = trim($host);
			if ($host !== '' && !$this->probe->isHostAllowed($host)) {
				throw new OCSException(
					'This host is not on the allowed override hosts list (dbdoctor.allowed_override_hosts in config.php).',
					Http::STATUS_FORBIDDEN,
				);
			}
			$this->config->setAppValue(Application::APP_ID, 'override.host', $host);
		}
		if ($port !== null) {
			$this->config->setAppValue(Application::APP_ID, 'override.port', (string)max(0, $port));
		}
		if ($user !== null) {
			$this->config->setAppValue(Application::APP_ID, 'override.user', trim($user));
		}
		if ($database !== null) {
			$this->config->setAppValue(Application::APP_ID, 'override.database', trim($database));
		}
		if ($driver !== null && in_array($driver, ['pdo_mysql', 'pdo_pgsql'], true)) {
			$this->config->setAppValue(Application::APP_ID, 'override.driver', $driver);
		}
		if ($password !== null && $password !== '') {
			$this->credentials->store('', Application::APP_ID . ':override.password', $password);
		} elseif ($clearPassword === true) {
			$this->credentials->delete('', Application::APP_ID . ':override.password');
		}
		return $this->index();
	}

	/**
	 * POST /api/v1/settings/test-connection
	 *
	 * Doesn't persist anything — tests the supplied credentials and
	 * returns ok / error.  Lets admins verify before saving.
	 *
	 * Password-confirmed like update(): it dials out with admin-supplied
	 * parameters, so it must not be reachable from a riding session.
	 */
	#[PasswordConfirmationRequired]
	public function testConnection(
		string $host,
		int $port,
		string $user,
		string $password,
		string $database,
		string $driver = 'pdo_mysql',
	): DataResponse {
		$this->assertAdmin();

		// Same whitelist as update(): anything beyond the two supported
		// PDO drivers (e.g. pdo_sqlite with a local path as "database")
		// must never reach DriverManager.
		if (!in_array($driver, ['pdo_mysql', 'pdo_pgsql'], true)) {
			throw new OCSException('Unsupported database driver.', Http::STATUS_BAD_REQUEST);
		}
		if (!$this->probe->isHostAllowed(trim($host))) {
			throw new OCSException(
				'This host is not on the allowed override hosts list (dbdoctor.allowed_override_hosts in config.php).',
				Http::STATUS_FORBIDDEN,
			);
		}

		try {
			$conn = DriverManager::getConnection([
				'driver' => $driver,
				'host' => $host,
				'port' => $port > 0 ? $port : null,
				'user' => $user,
				'password' => $password,
				'dbname' => $database,
			]);
			$conn->connect();
			$result = $conn->fetchOne($driver === 'pdo_pgsql' ? 'SHOW server_version' : 'SELECT VERSION()');
			$conn->close();
			return new DataResponse([
				'ok' => true,
				'message' => 'Connected. Server version: ' . (string)$result,
			]);
		} catch (\Throwable $e) {
			return new DataResponse([
				'ok' => false,
				'message' => $e->getMessage(),
			]);
		}
	}

	/**
	 * GET /api/v1/audit?limit=50
	 */
	public function audit(int $limit = 50): DataResponse {
		$this->assertAdmin();
		$limit = max(1, min(200, $limit));
		return new DataResponse([
			'entries' => array_map(
				static fn($e) => [
					'id' => $e->getId(),
					'appliedAt' => $e->getAppliedAt(),
					'actorUid' => $e->getActorUid(),
					'ruleId' => $e->getRuleId(),
					'variable' => $e->getVariable(),
					'oldValue' => $e->getOldValue(),
					'newValue' => $e->getNewValue(),
					'success' => $e->getSuccess(),
					'error' => $e->getError(),
				],
				$this->audit->recent($limit),
			),
		]);
	}

	private function assertAdmin(): void {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			throw new OCSException('Administrator access required.', Http::STATUS_FORBIDDEN);
		}
	}
}
