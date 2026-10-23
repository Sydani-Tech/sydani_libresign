<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version18005Date20261022000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('libresign_campaign')) {
			$t = $schema->createTable('libresign_campaign');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 200, 'notnull' => true]);
			$t->addColumn('version', Types::STRING, ['length' => 80, 'notnull' => true]);
			$t->addColumn('message', Types::TEXT, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 20, 'notnull' => true, 'default' => 'draft']);
			$t->addColumn('revision', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('source_file', Types::STRING, ['length' => 80, 'notnull' => false]);
			$t->addColumn('source_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('source_name', Types::STRING, ['length' => 200, 'notnull' => true]);
			foreach (['pdf_metadata', 'fields', 'counter'] as $column) $t->addColumn($column, Types::TEXT, ['notnull' => true]);
			foreach (['created_at', 'published_at', 'due_at', 'cancelled_at'] as $column) $t->addColumn($column, Types::BIGINT, ['notnull' => $column === 'created_at']);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['owner_uid', 'id'], 'ls_campaign_owner');
			$t->addIndex(['status', 'id'], 'ls_campaign_state');
		}
		if (!$schema->hasTable('libresign_camp_rcpt')) {
			$t = $schema->createTable('libresign_camp_rcpt');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('campaign_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('recipient_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('email', Types::STRING, ['length' => 254, 'notnull' => true]);
			$t->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('queue_state', Types::STRING, ['length' => 20, 'notnull' => true, 'default' => 'draft']);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('lease_token', Types::STRING, ['length' => 64, 'notnull' => false]);
			foreach (['lease_until', 'available_at', 'notified_at', 'last_notified_at'] as $column) $t->addColumn($column, Types::BIGINT, ['notnull' => false]);
			$t->addColumn('notify_pending', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$t->addColumn('exemption_reason', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'ls_campaign_recipient_pk');
			$t->addUniqueIndex(['campaign_id', 'recipient_key'], 'ls_campaign_recipient_once');
			$t->addUniqueIndex(['file_id'], 'ls_campaign_file_once');
			$t->addIndex(['campaign_id', 'id'], 'ls_campaign_recipients');
			$t->addIndex(['queue_state', 'available_at'], 'ls_campaign_dispatch');
		}
		if (!$schema->hasTable('libresign_camp_event')) {
			$t = $schema->createTable('libresign_camp_event');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('campaign_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('actor', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('action', Types::STRING, ['length' => 40, 'notnull' => true]);
			$t->addColumn('details', Types::TEXT, ['notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['campaign_id', 'id'], 'ls_campaign_events');
		}
		return $schema;
	}
}
