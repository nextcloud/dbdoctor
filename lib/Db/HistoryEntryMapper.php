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
 * @template-extends QBMapper<HistoryEntry>
 */
class HistoryEntryMapper extends QBMapper {
	public const TABLE = 'dbdoctor_history';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, HistoryEntry::class);
	}

	public function findLatest(?string $flavour = null): ?HistoryEntry {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->orderBy('created_at', 'DESC')
			->setMaxResults(1);
		if ($flavour !== null) {
			$qb->where($qb->expr()->eq('db_flavour', $qb->createNamedParameter($flavour)));
		}
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<HistoryEntry>
	 */
	public function findRange(int $sinceTs, int $untilTs, ?string $flavour = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($untilTs, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC');
		if ($flavour !== null) {
			$qb->andWhere($qb->expr()->eq('db_flavour', $qb->createNamedParameter($flavour)));
		}
		return array_values($this->findEntities($qb));
	}

	/**
	 * Lightweight score series for the trend chart: only the envelope
	 * columns, never the (potentially large) results JSON blob.
	 *
	 * @return list<array{ts:int, score:int, grade:string}>
	 */
	public function findScoreSeries(int $sinceTs, ?string $flavour = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('created_at', 'score', 'grade')
			->from(self::TABLE)
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC');
		if ($flavour !== null) {
			$qb->andWhere($qb->expr()->eq('db_flavour', $qb->createNamedParameter($flavour)));
		}
		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = [
				'ts' => (int)$row['created_at'],
				'score' => (int)$row['score'],
				'grade' => (string)$row['grade'],
			];
		}
		$result->closeCursor();
		return $rows;
	}

	public function deleteOlderThan(int $cutoffTs): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoffTs, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
