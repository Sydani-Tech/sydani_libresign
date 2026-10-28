<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
// Deliberately not a web endpoint or a sudo-policy change.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (gethostname() !== 'nextcloud-staging' || ($argv[1] ?? '') !== '--install-workflow-schema') {
  fwrite(STDERR,"Restricted to explicit CLI installation on nextcloud-staging.\n"); exit(1);
}
umask(0077);
if (!function_exists('posix_geteuid') || posix_geteuid() !== (posix_getpwnam('www-data')['uid'] ?? -1)) {
  fwrite(STDERR,"Run this installer as www-data using the administrator command provided.\n"); exit(1);
}
chdir('/var/www/html');
$_SERVER['SCRIPT_FILENAME']='/var/www/html/occ';
define('OC_CONSOLE',true);
require '/var/www/html/lib/base.php';
set_exception_handler(static function(Throwable $e):void { fwrite(STDERR,'FAIL: '.get_class($e).': '.$e->getMessage().PHP_EOL); exit(1); });
$config=\OCP\Server::get(\OCP\IConfig::class);
if (!in_array('nextcloud.sydani.org',$config->getSystemValue('trusted_domains',[]),true)) throw new RuntimeException('Staging domain check failed.');
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('libresign');
$db=\OCP\Server::get(\OCP\IDBConnection::class);
$migration=new \OC\DB\MigrationService('libresign',\OCP\Server::get(\OC\DB\Connection::class));
$version='18005Date20261028000000';
if (in_array($version,$migration->getMigratedVersions(),true)) { echo "Workflow schema already installed; no changes required.\n"; exit(0); }
// Back up the database before the additive migration. Secrets only enter a
// private option file, never the command line, terminal output or this repository.
if ($config->getSystemValue('dbtype') !== 'mysql') throw new RuntimeException('This staging installer expects MariaDB/MySQL. No schema changes made.');
$dump=is_executable('/usr/bin/mariadb-dump')?'/usr/bin/mariadb-dump':'/usr/bin/mysqldump';
if(!is_executable($dump)) throw new RuntimeException('Database backup utility is missing. No schema changes made.');
$data=rtrim($config->getSystemValue('datadirectory'),'/');
$backup=$data.'/.libresign-workflow-backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if(!mkdir($backup,0700,true))throw new RuntimeException('Cannot create private backup directory.');
$quote=static function(string $value):string { if(preg_match('/[\r\n\x00]/',$value))throw new RuntimeException('Unsupported database option value.');return '"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"'; };
$host=(string)$config->getSystemValue('dbhost','localhost');
$options="[client]\nuser=".$quote((string)$config->getSystemValue('dbuser'))."\npassword=".$quote((string)$config->getSystemValue('dbpassword'))."\n";
if(preg_match('/^([^:]+):([0-9]+)$/',$host,$match))$options.='host='.$quote($match[1])."\nport=".$match[2]."\n";
elseif(str_starts_with($host,'/'))$options.='socket='.$quote($host)."\n";
else $options.='host='.$quote($host)."\n";
$optionFile=$backup.'/client.cnf';
file_put_contents($optionFile,$options);chmod($optionFile,0600);
try {
  $process=proc_open([$dump,'--defaults-extra-file='.$optionFile,'--single-transaction','--skip-lock-tables','--',(string)$config->getSystemValue('dbname')],[0=>['pipe','r'],1=>['file',$backup.'/database.sql','w'],2=>['file',$backup.'/backup-error.log','w']],$pipes);
  if(!is_resource($process))throw new RuntimeException('Unable to start database backup.');
  fclose($pipes[0]);
  if(proc_close($process)!==0 || filesize($backup.'/database.sql')<100)throw new RuntimeException('Database backup did not succeed. No schema changes made. Inspect the private backup-error.log as administrator.');
} finally { if(is_file($optionFile))unlink($optionFile); }
echo 'PASS: database backup saved privately at '.$backup.PHP_EOL;
$migration->executeStep($version);
foreach(['libresign_letterhead','libresign_letter_doc','libresign_letter_event','libresign_reminder'] as $table)if(!$db->tableExists($table))throw new RuntimeException('Workflow table missing after migration: '.$table);
\OCP\Server::get(\OCP\BackgroundJob\IJobList::class)->add(\OCA\Libresign\BackgroundJob\CampaignDispatch::class);
echo "PASS: four workflow tables installed; existing documents and signing records were not changed.\n";

$groups=\OCP\Server::get(\OCP\IGroupManager::class);if (!$groups->get('Letterhead Document Approver')) $groups->createGroup('Letterhead Document Approver');
echo "PASS: Letterhead Document Approver group is available; no users were added.\n";
