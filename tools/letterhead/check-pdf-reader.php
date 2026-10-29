<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
if (PHP_SAPI !== 'cli') { exit(1); }
chdir('/var/www/html');
define('OC_CONSOLE', true);
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/occ';
require '/var/www/html/lib/base.php';
set_exception_handler(static function(Throwable $e): void { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); });
if (!in_array('nextcloud.sydani.org', \OCP\Server::get(\OCP\IConfig::class)->getSystemValue('trusted_domains', []), true)) { throw new RuntimeException('Not staging'); }
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('libresign');

$mapper=\OCP\Server::get(\OCA\Libresign\Db\FileMapper::class);
$accounts=\OCP\Server::get(\OCA\Libresign\Service\AccountService::class);
$letterheads=\OCP\Server::get(\OCA\Libresign\Service\LetterheadService::class);
$options=getopt('', ['file-uuid:']);$uuid=(string)($options['file-uuid']??'');
if(!preg_match('/^[a-f0-9-]{36}$/i',$uuid))throw new RuntimeException('Supply --file-uuid for an unsigned staging QA letterhead.');
$file=$mapper->getByUuid($uuid);
if(!str_starts_with($file->getName(),'STAGING UI QA')||$file->getSignedNodeId()||empty(($file->getMetadata()??[])['letterhead']))throw new RuntimeException('Use an unsigned staging QA letterhead.');
$node=$accounts->getPdfByUuid($file->getUuid());$bytes=$node->getContent();
if(!str_starts_with($bytes,'%PDF-')||!hash_equals($file->getMetadata()['letterhead']['sourceHash'],hash('sha256',$bytes)))throw new RuntimeException('Approved PDF hash mismatch');
echo "PASS: unsigned approved letterhead PDF reader returns the exact approved PDF.\n";
$forged=clone $file;$meta=$forged->getMetadata();$meta['letterhead']['id']=2147483647;$forged->setMetadata($meta);
try{$letterheads->assertRelease($forged);throw new RuntimeException('Invalid approval was accepted');}catch(\OCP\Files\NotPermittedException){echo "PASS: invalid approval binding is still denied.\n";}
$rows=\OCP\Server::get(\OCA\Libresign\Service\ArchiveQueryService::class)->list([],1,100)['data'];$count=0;
foreach($rows as $row){$f=$mapper->getById((int)$row['id']);if($f->isEnvelope()||!$f->getSignedHash())continue;$pdf=$accounts->getPdfByUuid($f->getUuid());if(!hash_equals($f->getSignedHash(),hash('sha256',$pdf->getContent())))throw new RuntimeException('Signed PDF mismatch');$count++;}
echo json_encode(['signedPdfReaderHashesVerified'=>$count,'documentWrites'=>0,'emailsSent'=>0])."\n";
