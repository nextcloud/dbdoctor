<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Settings;

use OCA\DBDoctor\AppInfo\Application;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Mounts the DBDoctor Vue dashboard inside the admin settings page.
 *
 * The whole UI — dashboard + settings — lives in a single SPA bundle
 * loaded here.  Vue Router dispatches between the two views based on
 * the URL hash so deep-linking works.
 */
class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private DatabaseProbe $probe,
	) {
	}

	public function getForm(): TemplateResponse {
		// Bootstrap data the SPA needs before its first network call.
		// Knowing the database flavour up-front lets us decide whether
		// to render the dashboard or the "unsupported database" card
		// without a flash of the wrong content.
		$flavour = $this->probe->detectFlavour();
		$this->initialState->provideInitialState('flavour', $flavour);
		$this->initialState->provideInitialState('version', $this->probe->detectVersion($flavour));

		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');

		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
