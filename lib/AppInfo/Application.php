<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\AppInfo;

use OCA\DBDoctor\Notification\Notifier;
use OCA\DBDoctor\SetupChecks\DatabaseHealth;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'dbdoctor';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Services are auto-wired by the DI container.
		$context->registerNotifierService(Notifier::class);
		$context->registerSetupCheck(DatabaseHealth::class);
	}

	public function boot(IBootContext $context): void {
	}
}
