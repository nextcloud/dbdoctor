<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getAppliedAt()
 * @method void setAppliedAt(int $appliedAt)
 * @method string getActorUid()
 * @method void setActorUid(string $actorUid)
 * @method string getRuleId()
 * @method void setRuleId(string $ruleId)
 * @method string getVariable()
 * @method void setVariable(string $variable)
 * @method string|null getOldValue()
 * @method void setOldValue(?string $oldValue)
 * @method string|null getNewValue()
 * @method void setNewValue(?string $newValue)
 * @method bool getSuccess()
 * @method void setSuccess(bool $success)
 * @method string|null getError()
 * @method void setError(?string $error)
 */
class AuditEntry extends Entity {
	// Sentinel defaults so Entity's setter never short-circuits on
	// columns without SQL defaults (see HistoryEntry for full rationale).
	protected int $appliedAt = -1;
	protected string $actorUid = '';
	protected string $ruleId = '';
	protected string $variable = '';
	protected ?string $oldValue = null;
	protected ?string $newValue = null;
	protected bool $success = false;
	protected ?string $error = null;

	public function __construct() {
		$this->addType('appliedAt', 'integer');
		$this->addType('success', 'boolean');
	}
}
