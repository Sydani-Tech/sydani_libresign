<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
chdir('/var/www/html');
define('OC_CONSOLE', true);
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/occ';
require '/var/www/html/lib/base.php';
set_exception_handler(static function(Throwable $e): void { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); });
if (!in_array('nextcloud.sydani.org', \OCP\Server::get(\OCP\IConfig::class)->getSystemValue('trusted_domains', []), true)) { throw new RuntimeException('Not staging'); }
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('libresign');

$db=\OCP\Server::get(\OCP\IDBConnection::class);$users=\OCP\Server::get(\OCP\IUserManager::class);$svc=\OCP\Server::get(\OCA\Libresign\Service\SigningDashboardService::class);
$q=$db->getQueryBuilder();$q->selectDistinct('identifier_value')->from('libresign_identify_method')->where($q->expr()->eq('identifier_key',$q->createNamedParameter('account')))->setMaxResults(200);$cursor=$q->executeQuery();$uids=$cursor->fetchAll();$cursor->closeCursor();$checks=0;$ready=0;
foreach($uids as $row){$user=$users->get($row['identifier_value']);if(!$user||!$user->isEnabled())continue;
 foreach(['ready','signed','sent','completed'] as $view){$result=$svc->get($user,$view);foreach($result['documents'] as $doc){if($view!=='ready'){if($doc['signingUuid']!==null)throw new RuntimeException('Signing identifier leaked to history/outbound');$checks++;continue;}
 if(empty($doc['signingUuid'])||$doc['signingUuid']===$doc['uuid'])throw new RuntimeException('Missing personal signing request identifier');
 $q=$db->getQueryBuilder();$q->select('sr.file_id','sr.status','sr.signed','im.identifier_key','im.identifier_value')->from('libresign_sign_request','sr')->innerJoin('sr','libresign_identify_method','im','im.sign_request_id = sr.id')->where($q->expr()->eq('sr.uuid',$q->createNamedParameter($doc['signingUuid'])));$cursor=$q->executeQuery();$rows=$cursor->fetchAll();$cursor->closeCursor();$matched=false;
 foreach($rows as $r){if((int)$r['file_id']!==$doc['id']||(int)$r['status']!==1||$r['signed']!==null)throw new RuntimeException('Signing link is not the active participant on the listed file');if($r['identifier_key']==='account'&&$r['identifier_value']===$user->getUID())$matched=true;if($r['identifier_key']==='email'&&strcasecmp($r['identifier_value'],$user->getEMailAddress()??'')===0)$matched=true;}
 if(!$matched)throw new RuntimeException('Signing link does not belong to this user');$checks++;$ready++;}}
}
if(!$ready)throw new RuntimeException('No ready requests were exercised');echo json_encode(['readOnlyDashboardLinkChecks'=>$checks,'readySigningLinksVerified'=>$ready,'signaturesCreated'=>0,'emailsSent'=>0]).PHP_EOL;
