<?php

declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[$command, $host] = [$argv[1] ?? '', $argv[2] ?? ''];
$environments = ['nextcloud.sydani.org' => 'nextcloud-staging', 'documents.sydani.org' => 'nextcloud'];
$commands = ['--status', '--prepare-grouped', '--enable-root', '--disable-root', '--disable-grouped'];
if (!isset($environments[$host]) || gethostname() !== $environments[$host] || !in_array($command, $commands, true)) {
 fwrite(STDERR, "Server/domain or command mismatch.\n"); exit(1);
}
try {
$root = dirname(__DIR__, 4); chdir($root); $_SERVER['SCRIPT_FILENAME'] = $root . '/occ';
define('OC_CONSOLE', true); require $root . '/lib/base.php';
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('libresign');
$config = \OCP\Server::get(\OCP\IConfig::class);
if (!in_array($host, $config->getSystemValue('trusted_domains', []), true)) throw new RuntimeException('Expected domain is not trusted.');
$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
$sampleUuid = '00000000-0000-4000-8000-000000000001';
$sampleCode = \OCA\Libresign\Service\ShortValidationUrlService::encode($sampleUuid);
if ($command === '--prepare-grouped') {
 $db = \OCP\Server::get(\OCP\IDBConnection::class);
 $migration = new \OC\DB\MigrationService('libresign', \OCP\Server::get(\OC\DB\Connection::class));
 $version = '18007Date20261110000000';
 if (!in_array($version, $migration->getMigratedVersions(), true)) $migration->executeStep($version);
 $codes = \OCP\Server::get(\OCA\Libresign\Service\ShortValidationCodeStore::class);
 $id = $codes->getOrCreate($sampleUuid);
 if ($codes->findUuid($id) !== $sampleUuid) throw new RuntimeException('Validation link storage round-trip failed.');
 $appConfig->setValueBool('libresign', \OCA\Libresign\Service\ShortValidationUrlService::STORE_READY_KEY, true);
 $sampleCode = \OCA\Libresign\Service\ShortValidationCodeStore::format($id);
} elseif ($command === '--enable-root') {
 if (!$appConfig->getValueBool('libresign', \OCA\Libresign\Service\ShortValidationUrlService::STORE_READY_KEY, false)) throw new RuntimeException('Prepare grouped link storage before enabling root links.');
 $appConfig->setValueBool('libresign', \OCA\Libresign\Service\ShortValidationUrlService::ROOT_ENABLED_KEY, true);
 $appConfig->setValueBool('libresign', \OCA\Libresign\Service\ShortValidationUrlService::GROUPED_ENABLED_KEY, true);
} elseif ($command === '--disable-root') {
 $appConfig->setValueBool('libresign', \OCA\Libresign\Service\ShortValidationUrlService::ROOT_ENABLED_KEY, false);
} elseif ($command === '--disable-grouped') {
 // Keep stored aliases resolvable, even when future generation returns to legacy links.
 $appConfig->setValueBool('libresign', \OCA\Libresign\Service\ShortValidationUrlService::GROUPED_ENABLED_KEY, false);
}
echo json_encode([
 'host' => $host,
 'rootEnabled' => $appConfig->getValueBool('libresign', \OCA\Libresign\Service\ShortValidationUrlService::ROOT_ENABLED_KEY, false),
 'groupedEnabled' => $appConfig->getValueBool('libresign', \OCA\Libresign\Service\ShortValidationUrlService::GROUPED_ENABLED_KEY, false),
 'storeReady' => $appConfig->getValueBool('libresign', \OCA\Libresign\Service\ShortValidationUrlService::STORE_READY_KEY, false),
 'sampleCode' => $sampleCode,
], JSON_THROW_ON_ERROR), PHP_EOL;

} catch (\Throwable $error) {
 fwrite(STDERR, 'Validation-link configuration failed: ' . $error->getMessage() . PHP_EOL);
 exit(1);
}
