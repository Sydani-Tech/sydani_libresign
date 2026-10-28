<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
if (PHP_SAPI !== 'cli' || gethostname() !== 'nextcloud-staging') exit(1);
if (!function_exists('posix_geteuid') || posix_geteuid() !== (posix_getpwnam('www-data')['uid'] ?? -1)) exit(1);
chdir('/var/www/html');
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/occ';
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
$config = \OCP\Server::get(\OCP\IConfig::class);
$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
$mode = $config->getAppValue('core', 'backgroundjobs_mode', 'unknown');
$policy = $appConfig->getValueArray('libresign', 'reminder_schedule', []);
$newEnabled = ($policy['enabled'] ?? false) === true;
$legacyEnabled = $appConfig->getValueInt('libresign', 'reminder_days_before', 0) > 0
 && $appConfig->getValueInt('libresign', 'reminder_days_between', 0) > 0
 && $appConfig->getValueInt('libresign', 'reminder_max', 0) > 0;
if ($mode !== 'cron') throw new RuntimeException('Nextcloud is not configured for cron; no change was made.');
if ($newEnabled || (!$policy && $legacyEnabled)) throw new RuntimeException('Reminders are enabled. Review the email schedule before using this staging-only repair.');
$last = (int)$config->getAppValue('core', 'lastcron', '0');
$minimum = isset($argv[1]) ? (int)$argv[1] : 0;
if ($minimum && $last < $minimum) throw new RuntimeException('Nextcloud has not recorded a successful cron run.');
echo json_encode(['backgroundMode'=>$mode, 'lastCronWAT'=>$last ? (new DateTimeImmutable('@'.$last))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d H:i:s T') : null, 'scheduledRemindersEnabled'=>false, 'padesLtEnabled'=>$appConfig->getValueBool('libresign','pades_lt_enabled',false)], JSON_THROW_ON_ERROR).PHP_EOL;
