<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Read-only: build the migration target in memory and compare it with the live schema.
if (PHP_SAPI !== 'cli') exit(1);
$options = getopt('', ['nextcloud-root:', 'require-migrations']);
$root = realpath($options['nextcloud-root'] ?? dirname(__DIR__, 4));
if (!$root || !is_file($root . '/occ')) throw new RuntimeException('Specify --nextcloud-root with the installed Nextcloud path.');
chdir($root);
$_SERVER['SCRIPT_FILENAME'] = $root . '/occ';
define('OC_CONSOLE', true);
require $root . '/lib/base.php';
set_exception_handler(static function (Throwable $error): void {
	fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
	exit(1);
});
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('libresign');
$connection = \OCP\Server::get(\OC\DB\Connection::class);
$live = new \OC\DB\SchemaWrapper($connection);
$expected = new \OC\DB\SchemaWrapper($connection, new \Doctrine\DBAL\Schema\Schema());
$expected->createTable('libresign_file')->addColumn('id', \OCP\DB\Types::BIGINT);
$versions = ['18004Date20261015000000', '18005Date20261022000000', '18005Date20261028000000', '18006Date20261103000000', '18007Date20261110000000', '19005Date20261110000000'];
$output = new \OC\Migration\SimpleOutput(\OCP\Server::get(\Psr\Log\LoggerInterface::class), 'libresign');
foreach ($versions as $version) {
	$class = 'OCA\\Libresign\\Migration\\Version' . $version;
	// Only changeSchema is called on the in-memory target. Never execute/record a migration here.
	\OCP\Server::get($class)->changeSchema($output, static fn () => $expected, []);
}
$errors = [];
$tables = $expected->getTableNamesWithoutPrefix();
foreach ($tables as $name) {
	if (!$live->hasTable($name)) { $errors[] = 'Missing table ' . $name; continue; }
	$target = $expected->getTable($name);
	$actual = $live->getTable($name);
	foreach ($target->getColumns() as $column) {
		$key = $column->getName();
		if ($name === 'libresign_file' && $key === 'id') continue;
		if (!$actual->hasColumn($key)) { $errors[] = 'Missing column ' . $name . '.' . $key; continue; }
		$found = $actual->getColumn($key);
		if ($found->getType() !== $column->getType() || $found->getNotnull() !== $column->getNotnull()
			|| ($column->getLength() !== null && $found->getLength() !== $column->getLength())
			|| $found->getUnsigned() !== $column->getUnsigned()
			|| $found->getAutoincrement() !== $column->getAutoincrement()) {
			$errors[] = 'Incompatible column ' . $name . '.' . $key;
		}
	}
	foreach ($target->getIndexes() as $index) {
		if ($index->isPrimary()) {
			$found = $actual->getPrimaryKey();
		} else {
			$found = $actual->hasIndex($index->getName()) ? $actual->getIndex($index->getName()) : null;
		}
		if ($found === null || $found->getColumns() !== $index->getColumns() || $found->isUnique() !== $index->isUnique()) {
			$errors[] = 'Missing or incompatible index ' . $name . '.' . $index->getName();
		}
	}
}
$recorded = (new \OC\DB\MigrationService('libresign', $connection))->getMigratedVersions();
$pending = array_values(array_diff($versions, $recorded));
if (isset($options['require-migrations']) && $pending !== []) $errors[] = 'Unrecorded custom migrations: ' . implode(', ', $pending);
echo json_encode(['customTablesChecked' => count($tables) - 1, 'fileColumnsChecked' => 3, 'pendingCustomMigrations' => $pending, 'errors' => $errors, 'databaseWrites' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($errors ? 1 : 0);
