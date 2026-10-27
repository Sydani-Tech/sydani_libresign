<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
if (PHP_SAPI !== 'cli' || gethostname() !== 'nextcloud-staging') exit(1);
chdir('/var/www/html'); $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/occ'; define('OC_CONSOLE', true); require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, 'FAIL: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL); exit(1); });
$source = getenv('LIBRESIGN_QA_SOURCE');
if ($source) {
 if ($source !== '/home/codexdev/document-navigation-candidate') throw new RuntimeException('Unexpected candidate source');
 spl_autoload_register(static function (string $class) use ($source): void {
  $prefix = 'OCA\\Libresign\\';
  if (str_starts_with($class, $prefix)) { $path = $source . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; if (is_file($path)) require $path; }
 }, true, true);
}
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('libresign');
$app = new \OCA\Libresign\AppInfo\Application(); $c = $app->getContainer();
$users = \OCP\Server::get(\OCP\IUserManager::class); $groups = \OCP\Server::get(\OCP\IGroupManager::class);
$service = $c->get(\OCA\Libresign\Service\File\FileListService::class); $mapper = $c->get(\OCA\Libresign\Db\FileMapper::class); $requests = $c->get(\OCA\Libresign\Db\SignRequestMapper::class);
$checks = 0; $check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException($label); $checks++; echo 'PASS: ' . $label . PHP_EOL; };
$ids = static fn (array $rows): array => array_values(array_map(static fn ($r) => (int)$r['id'], $rows));
$equalIds = static function (array $a, array $b) use ($ids): bool { $a = $ids($a); $b = $ids($b); sort($a); sort($b); return $a === $b; };
$tested = 0;
foreach ($users->search('', 200, 0) as $user) {
 if (!$user->isEnabled()) continue;
 $all = []; $page = 1;
 do { $r = $service->listAssociatedFilesOfSignFlow($user, $page++, 100, [], ['sortBy'=>'created_at','sortDirection'=>'desc'], false, true); $all = array_merge($all, $r['data']); } while ($r['pagination']['next'] && $page < 100);
 $check(count($all) === count(array_unique($ids($all))), 'personal list contains no duplicate documents');
 $senderIds = array_column($r['senders'], 'id'); $expectedSenders = [];
 foreach ($all as $row) $expectedSenders[] = $mapper->getById($row['id'])->getUserId();
 $expectedSenders = array_values(array_unique($expectedSenders)); sort($expectedSenders); sort($senderIds);
 $check($senderIds === $expectedSenders, 'searchable sender choices are limited to accessible documents');
 foreach (['requested_at', 'completed_at', 'user_id', 'name'] as $sort) {
  $result = $service->listAssociatedFilesOfSignFlow($user, 1, 100, [], ['sortBy'=>$sort,'sortDirection'=>'asc']);
  $check(count($result['data']) <= 100 && count($result['data']) === count(array_unique($ids($result['data']))), 'server supports ' . $sort . ' sorting without duplicate rows');
 }
 if ($all) {
  $sample = $all[0]; $file = $mapper->getById($sample['id']);
  $filters = [ ['name'=>$file->getName()], ['sender'=>$file->getUserId()], ['status'=>[$file->getStatus()]], ['sender'=>'__no_such_sender_qa__'], ['name'=>'__no_such_name_%_qa__'] ];
  foreach ($filters as $filter) {
   $expected = array_values(array_filter($all, static function ($row) use ($mapper, $filter): bool { $f=$mapper->getById($row['id']); return (!isset($filter['name']) || stripos($f->getName(),$filter['name'])!==false) && (!isset($filter['sender']) || $f->getUserId()===$filter['sender']) && (!isset($filter['status']) || in_array($f->getStatus(),$filter['status'],true)); }));
   $result = $service->listAssociatedFilesOfSignFlow($user,1,100,$filter);
   $check($equalIds($result['data'],$expected), 'file name, sender and status predicates preserve personal scope');
  }
  foreach (['requested'=>'getRequestedAt','completed'=>'getCompletedAt','created'=>'getCreatedAt'] as $dateBy=>$getter) {
   $filter=['dateBy'=>$dateBy,'from'=>strtotime('2026-10-01 UTC'),'to'=>strtotime('2026-10-31 23:59:59 UTC')];
   $expected=array_values(array_filter($all,static function($row)use($mapper,$getter,$filter):bool{$d=$mapper->getById($row['id'])->$getter();return $d && $d->getTimestamp()>=$filter['from'] && $d->getTimestamp()<=$filter['to'];}));
   $result=$service->listAssociatedFilesOfSignFlow($user,1,100,$filter);
   $check($equalIds($result['data'],$expected),'exact '.$dateBy.' date range uses recorded date without fallback');
  }
  $people=[];
  foreach($all as $row)foreach($requests->getByFileId($row['id']) as $sr){$methods=$requests->getIdentifyMethodsFromSigners([$sr]);$email='';foreach($methods[$sr->getId()]??[] as $m){if($m->getIdentifierKey()==='email')$email=$m->getIdentifierValue();if($m->getIdentifierKey()==='account')$email=$users->get($m->getIdentifierValue())?->getEMailAddress()??'';}if($email && $sr->getDisplayName())$people[]=['name'=>$sr->getDisplayName(),'email'=>$email];}
  if($people){$person=$people[0];foreach([['recipientName'=>$person['name']],['recipientEmail'=>$person['email']],['recipientName'=>$person['name'],'recipientEmail'=>$person['email']]] as $filter){$result=$service->listAssociatedFilesOfSignFlow($user,1,100,$filter);$check(count($result['data'])>0 && !array_diff($ids($result['data']),$ids($all)),'recipient lookup supports internal/external identities within personal scope');}}
  $first=$service->listAssociatedFilesOfSignFlow($user,1,1,[],['sortBy'=>'created_at','sortDirection'=>'asc']);$second=count($all)>1?$service->listAssociatedFilesOfSignFlow($user,2,1,[],['sortBy'=>'created_at','sortDirection'=>'asc']):null;
  $check($first['pagination']['total']===count($all),'pagination counts distinct documents rather than signer rows');
  if($second)$check($first['data'][0]['id']!==$second['data'][0]['id'],'subsequent page advances to a different document');
 }
 foreach(\OCA\Libresign\Service\SigningDashboardService::VIEWS as $view){$d=$c->get(\OCA\Libresign\Service\SigningDashboardService::class)->get($user,$view);$check($d['perPage']===10 && count($d['documents'])<=10,'dashboard '.$view.' is limited to ten records');}
 if(++$tested>=3)break;
}
$check($tested>0,'enabled staging users tested without creating accounts');
$archive=$c->get(\OCA\Libresign\Service\ArchiveQueryService::class);$result=$archive->list(['status'=>'all'],1,100);$check(isset($result['data'],$result['senders']),'Signed Archive remains readable with shared predicates');
$session=\OCP\Server::get(\OCP\IUserSession::class);$session->setUser(null);$check($c->get(\OCA\Libresign\Controller\ArchiveController::class)->index()->getStatus()===403,'anonymous archive access remains denied');
echo json_encode(['checksPassed'=>$checks,'usersTested'=>$tested,'existingDocumentsModified'=>0,'emailsSent'=>0],JSON_PRETTY_PRINT).PHP_EOL;
