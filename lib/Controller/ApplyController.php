<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Controller;

use OCA\DBDoctor\AppInfo\Application;
use OCA\DBDoctor\Service\ApplyService;
use OCA\DBDoctor\Service\AuditService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class ApplyController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ApplyService $applier,
		private AuditService $audit,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * POST /api/v1/apply
	 *
	 * Body: { "ruleId": string, "variable": string, "value": string }
	 *
	 * Returns: { success: bool, oldValue: string|null, newValue: string|null, error?: string }
	 */
	#[PasswordConfirmationRequired]
	public function apply(string $ruleId, string $variable, string $value): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			throw new OCSException('Administrator access required.', Http::STATUS_FORBIDDEN);
		}
		$uid = $user->getUID();

		try {
			$result = $this->applier->apply($variable, $value);
			$this->audit->record($uid, $ruleId, $variable, $result['oldValue'], $result['newValue'], true);
			return new DataResponse([
				'success' => true,
				'oldValue' => $result['oldValue'],
				'newValue' => $result['newValue'],
			]);
		} catch (\InvalidArgumentException $e) {
			$this->audit->record($uid, $ruleId, $variable, null, $value, false, $e->getMessage());
			throw new OCSException($e->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			// A failure here is almost always the database refusing the
			// change (e.g. the DB user can't SET GLOBAL) — an expected
			// outcome, not a server fault.  Return it as a normal OCS
			// response carrying success:false so the dialog can show the
			// reason inline; a 500 would make the client throw a generic
			// "Request failed with status code 500" and hide the message.
			$this->audit->record($uid, $ruleId, $variable, null, $value, false, $e->getMessage());
			return new DataResponse([
				'success' => false,
				'oldValue' => null,
				'newValue' => null,
				'error' => $e->getMessage(),
			]);
		}
	}
}
