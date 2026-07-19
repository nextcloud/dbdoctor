<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AuditEntry>
 */
class AuditEntryMapper extends QBMapper {
	public const TABLE = 'dbdoctor_audit';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, AuditEntry::class);
	}

	/**
	 * @return list<AuditEntry>
	 */
	public function findRecent(int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->orderBy('applied_at', 'DESC')
			->setMaxResults($limit);
		return array_values($this->findEntities($qb));
	}

	/**
	 * Most recent *successful* apply for each variable, newest first.
	 * Backs the reverted-fix detector, which needs the last value DB
	 * Doctor actually wrote per variable.
	 *
	 * @return list<AuditEntry>
	 */
	public function findLatestSuccessfulPerVariable(int $scan = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('success', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('applied_at', 'DESC')
			->setMaxResults($scan);

		$latest = [];
		foreach ($this->findEntities($qb) as $entry) {
			$latest[$entry->getVariable()] ??= $entry;
		}
		return array_values($latest);
	}

	public function deleteOlderThan(int $cutoffTs): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->lt('applied_at', $qb->createNamedParameter($cutoffTs, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
