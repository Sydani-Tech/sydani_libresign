<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Libresign\Migration;

use Closure;
use OCA\Libresign\Service\ShortValidationCodeStore;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version18007Date20261110000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(ShortValidationCodeStore::TABLE)) {
			$table = $schema->createTable(ShortValidationCodeStore::TABLE);
			$table->addColumn('code_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->setPrimaryKey(['code_id']);
			$table->addUniqueIndex(['uuid'], 'ls_vlink_uuid');
		}
		return $schema;
	}
}
