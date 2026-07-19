<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema for DBDoctor.
 *
 * Three tables: history (one row per check run, kept 90 days), audit
 * (one row per Apply attempt, kept 365 days), and muted (per-rule mute
 * persistence — using a real table so the cleanup nightly job can do
 * efficient queries).
 */
class Version1000Date20260101000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('dbdoctor_history')) {
			$table = $schema->createTable('dbdoctor_history');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('db_flavour', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('db_version', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('score', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('grade', Types::STRING, ['notnull' => true, 'length' => 1]);
			$table->addColumn('total_rules', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('failed_alerts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('failed_warnings', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('failed_notices', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// JSON blob with one entry per rule.  TEXT can hold up to
			// 64 KiB on MySQL by default; the app gzip-base64-encodes
			// payloads larger than that and prefixes with "gz:" so we
			// never silently truncate a large run.
			$table->addColumn('results_json', Types::TEXT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['created_at'], 'dbd_hist_at');
			$table->addIndex(['db_flavour', 'created_at'], 'dbd_hist_flav_at');
		}

		if (!$schema->hasTable('dbdoctor_audit')) {
			$table = $schema->createTable('dbdoctor_audit');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('applied_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('rule_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('variable', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('old_value', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('new_value', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('success', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['applied_at'], 'dbd_audit_at');
		}

		if (!$schema->hasTable('dbdoctor_muted')) {
			$table = $schema->createTable('dbdoctor_muted');
			$table->addColumn('rule_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('muted_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->setPrimaryKey(['rule_id']);
		}

		return $schema;
	}
}
