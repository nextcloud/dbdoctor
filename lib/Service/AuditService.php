<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Service;

use OCA\DBDoctor\Db\AuditEntry;
use OCA\DBDoctor\Db\AuditEntryMapper;

/**
 * Records every Apply attempt — successful or failed — so admins have
 * an immutable trail of who changed what.
 */
final class AuditService {
	public function __construct(private AuditEntryMapper $mapper) {
	}

	public function record(
		string $actorUid,
		string $ruleId,
		string $variable,
		?string $oldValue,
		?string $newValue,
		bool $success,
		?string $error = null,
	): AuditEntry {
		$entry = new AuditEntry();
		$entry->setAppliedAt(time());
		$entry->setActorUid($actorUid);
		$entry->setRuleId($ruleId);
		$entry->setVariable($variable);
		$entry->setOldValue($oldValue);
		$entry->setNewValue($newValue);
		$entry->setSuccess($success);
		$entry->setError($error);
		return $this->mapper->insert($entry);
	}

	/**
	 * @return list<AuditEntry>
	 */
	public function recent(int $limit = 50): array {
		return $this->mapper->findRecent($limit);
	}
}
