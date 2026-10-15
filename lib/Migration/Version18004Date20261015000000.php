<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version18004Date20261015000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('libresign_file');
		foreach (['requested_at', 'completed_at'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::DATETIME, ['notnull' => false]);
			}
		}
		if (!$table->hasIndex('ls_file_completed')) {
			$table->addIndex(['completed_at', 'id'], 'ls_file_completed');
			$table->addIndex(['requested_at', 'id'], 'ls_file_requested');
		}
		return $schema;
	}
}
