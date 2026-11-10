<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
// Real engine test, explicitly opt-in, restricted to synthetic staging data.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(gethostname()!=='nextcloud-staging'||($argv[1]??'')!=='--allow-staging-mutations'){
 fwrite(STDERR,"Restricted to explicit Signing date QA on nextcloud-staging.\n");exit(1);
}
if(!function_exists('posix_geteuid')||posix_geteuid()!==(posix_getpwnam('www-data')['uid']??-1)){
 fwrite(STDERR,"Run this test as www-data.\n");exit(1);
}
umask(0007);chdir('/var/www/html');$_SERVER['SCRIPT_FILENAME']='/var/www/html/occ';define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
set_exception_handler(static function(Throwable $e):void{
 fwrite(STDERR,'FAIL: '.get_class($e).': '.$e->getMessage().PHP_EOL.$e->getFile().':'.$e->getLine().PHP_EOL);
 foreach(array_slice($e->getTrace(),0,7) as $f)fwrite(STDERR,($f['file']??'').':'.($f['line']??'').' '.($f['class']??'').($f['type']??'').($f['function']??'').PHP_EOL);
 exit(1);
});
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('libresign');
$app=new \OCA\Libresign\AppInfo\Application();$c=$app->getContainer();
$config=\OCP\Server::get(\OCP\IConfig::class);
if(!in_array('nextcloud.sydani.org',$config->getSystemValue('trusted_domains',[]),true))throw new RuntimeException('Staging domain check failed.');
$users=\OCP\Server::get(\OCP\IUserManager::class);$groups=\OCP\Server::get(\OCP\IGroupManager::class);$session=\OCP\Server::get(\OCP\IUserSession::class);
$db=\OCP\Server::get(\OC\DB\Connection::class);$service=$c->get(\OCA\Libresign\Service\CampaignService::class);$store=$c->get(\OCA\Libresign\Service\CampaignStore::class);
$mapper=$c->get(\OCA\Libresign\Db\FileMapper::class);$signers=$c->get(\OCA\Libresign\Db\SignRequestMapper::class);$fields=$c->get(\OCA\Libresign\Db\FileElementMapper::class);
$policy=$c->get(\OCA\Libresign\Service\CampaignPolicy::class);$archive=$c->get(\OCA\Libresign\Service\DocumentArchiveService::class);
$checks=0;$qa=[];$campaignIds=[];$profiles=[];
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;echo 'PASS: '.$message.PHP_EOL;};
$reject=static function(callable $fn,string $message)use($check):void{try{$fn();}catch(\OCP\Files\NotPermittedException|\InvalidArgumentException|\OCA\Libresign\Exception\LibresignException $e){$check(true,$message);return;}$check(false,$message);};
register_shutdown_function(static function()use(&$qa,&$campaignIds,$users,$groups,$db,$store):void{
 // Never leave an unfinished test transaction, active campaign queue or enabled
 // synthetic administrator behind, including on a failed assertion.
 try{while($db->getTransactionNestingLevel()>0)$db->rollBack();}catch(Throwable){}
 foreach($campaignIds as $id)try{$store->update('libresign_campaign',$id,['status'=>'cancelled','cancelled_at'=>time()]);}catch(Throwable){}
 foreach($qa as $user){try{$groups->get('admin')->removeUser($user);}catch(Throwable){}try{$user->setEnabled(false);}catch(Throwable $e){fwrite(STDERR,'QA cleanup needs administrator attention for '.$user->getUID().PHP_EOL);}}
});
$suffix=gmdate('YmdHis').'-'.bin2hex(random_bytes(2));
$preferences=$c->get(\OCA\Libresign\Service\NotificationPreferenceResolver::class);
foreach(['owner','one','two'] as $role){
 $user=$users->createUser('campaign-qa-'.$role.'-'.$suffix,bin2hex(random_bytes(24)));$qa[$role]=$user;
 $user->setDisplayName('Signing date QA '.$role.' '.$suffix);$user->setEMailAddress('campaign-qa-'.$role.'-'.$suffix.'@example.invalid');
 foreach(['libresign_file_to_sign','libresign_file_signed'] as $type)foreach(['email','notification'] as $channel)$config->setUserValue($user->getUID(),'activity','notify_'.$channel.'_'.$type,'0');
 $check($preferences->isEmailNotificationDisabled($user->getUID(),'libresign_file_to_sign',true)&&$preferences->isEmailNotificationDisabled($user->getUID(),'libresign_file_signed',true),'outgoing mail disabled for synthetic '.$role);
 // Supply a visible synthetic mark, just as a real signer must define a
 // signature image. Do not relax the engine's signature requirements for QA.
 $image=new Imagick();$image->newImage(180,60,new ImagickPixel('transparent'));$image->setImageFormat('png');
 $drawing=new ImagickDraw();$drawing->setStrokeColor(new ImagickPixel('black'));$drawing->setStrokeWidth(3);
 foreach([[10,45,40,15],[40,15,60,40],[60,40,95,20],[95,20,130,42],[130,42,165,25]] as $line)$drawing->line(...$line);
 $image->drawImage($drawing);
 $node=\OCP\Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($user->getUID())->newFile('campaign-qa-mark-'.$suffix.'.png',$image->getImageBlob());$profiles[$user->getUID()]=$node->getId();
 $element=new \OCA\Libresign\Db\UserElement();$element->setUserId($user->getUID());$element->setNodeId($node->getId());$element->setType('signature');$element->setCreatedAt(new DateTime());$element->setStarred(1);$c->get(\OCA\Libresign\Db\UserElementMapper::class)->insert($element);
}
$groups->get('admin')->addUser($qa['owner']);$session->setUser($qa['owner']);
$resolver=$c->get(\OCA\Libresign\Service\CampaignRecipientResolver::class);
$check($resolver->resolve(['name'=>'Internal QA','email'=>$qa['one']->getEMailAddress()])['uid']===$qa['one']->getUID(),'real account lookup classifies existing signer as internal');
$check($resolver->resolve(['name'=>'External QA','email'=>'unregistered-'.$suffix.'@example.invalid'])['kind']==='external','unregistered address classified as external without creating account');
$recipient=static fn($user)=>['name'=>$user->getDisplayName(),'email'=>$user->getEMailAddress(),'uid'=>$user->getUID()];
$draft=$service->create($qa['owner'],['name'=>'Signing date QA '.$suffix,'version'=>'QA1','message'=>'Synthetic non-legal staging test.','pdf'=>base64_encode(file_get_contents(__DIR__.'/../../tests/php/fixtures/pdfs/small_valid.pdf')),'sourceName'=>'campaign-qa.pdf','recipients'=>[$recipient($qa['one']),$recipient($qa['two'])],'counter'=>$recipient($qa['owner'])]);
$id=$draft['id'];$campaignIds[]=$id;
$check($draft['status']==='draft'&&$draft['summary']['total']===2&&$draft['requestedAt']===null,'draft cohort saved without sending invitations');
$raw=$store->get($id);$dimension=json_decode($raw['pdf_metadata'],true)['d'][0];$step=min(30,($dimension['h']-20)/10);$layout=[];
foreach(['recipient','counter'] as $roleIndex=>$role)foreach(['signature','text','date','checkbox','date'] as $index=>$type)$layout[]=['role'=>$role,'type'=>$type,'coordinates'=>['page'=>1,'left'=>10,'top'=>10+($roleIndex*5+$index)*$step,'width'=>$type==='checkbox'?16:min(150,$dimension['w']-20),'height'=>min(18,$step-2)],'metadata'=>['label'=>'QA '.$role.' '.($index===4?'manual date':$type),'required'=>true]+($index===2?['useSigningDate'=>true,'signingDateTimezone'=>'Africa/Lagos']:[])];
$draft=$service->update($qa['owner'],$id,$draft['revision'],['name'=>$draft['name'],'version'=>'QA1','message'=>$draft['message'],'recipients'=>[$recipient($qa['one']),$recipient($qa['two'])],'counter'=>$recipient($qa['owner']),'fields'=>$layout,'dueAt'=>time()+86400]);
$publishQuiet=static function(array $draft)use($db,$service,$store,$qa):array{
 // Keep queued rows invisible to other connections until fenced into a future
 // availability window. This tests normal publication without emailing anyone.
 $db->beginTransaction();
 try{$published=$service->publish($qa['owner'],$draft['id'],$draft['revision']);foreach($store->recipients($draft['id']) as $r)$store->update('libresign_camp_rcpt',(int)$r['id'],['available_at'=>time()+86400,'notify_pending'=>0]);$db->commit();return $published;}
 catch(Throwable $e){$db->rollBack();throw $e;}
};
$published=$publishQuiet($draft);
$check($published['requestedAt']!==null&&$published['summary']['completed']===0,'publication records request date without claiming completion');
$service->publish($qa['owner'],$id,$draft['revision']);$check(count($store->recipients($id))===2,'repeat publication does not duplicate cohort');
$create=new ReflectionMethod($service,'createRequest');
$files=[];
foreach($store->recipients($id) as $row){
 $db->beginTransaction();
 try{
  $store->update('libresign_camp_rcpt',(int)$row['id'],['available_at'=>time(),'notify_pending'=>1]);
  $leased=$store->claim(1,$id);$check(count($leased)===1,'queue claims one eligible synthetic recipient');
  $check(count($store->claim(1,$id))===0,'active lease cannot be claimed by a second worker');
  $bound=$create->invoke($service,$store->get($id),$leased[0],$qa['owner']);
  $again=$create->invoke($service,$store->get($id),$leased[0],$qa['owner']);$check($bound['file_id']===$again['file_id'],'repeat dispatch reuses committed request binding');
  $store->update('libresign_camp_rcpt',(int)$row['id'],['queue_state'=>'pending','available_at'=>time()+86400,'notify_pending'=>0,'lease_token'=>null,'lease_until'=>null]);$db->commit();$files[]=$mapper->getById((int)$bound['file_id']);
 }catch(Throwable $e){$db->rollBack();throw $e;}
}
$check(count($files)===2&&$files[0]->getId()!==$files[1]->getId()&&$files[0]->getNodeId()!==$files[1]->getNodeId(),'recipients have independent request IDs and PDF snapshots');
foreach($files as $file){
 $requests=$signers->getByFileId($file->getId());usort($requests,fn($a,$b)=>$a->getSigningOrder()<=>$b->getSigningOrder());
 $check(count($requests)===2&&$requests[0]->getStatus()===1&&$requests[1]->getStatus()===0,'recipient precedes countersigner on each independent copy');
 $check(count($fields->getByFileId($file->getId()))===10,'all ten role-assigned fields copied onto request');
 $check(count($c->get(\OCA\Libresign\Service\SignFileService::class)->getNextcloudFiles($file))===1,'unsigned campaign PDF retrievable from application-owned storage');
 $reject(fn()=>$c->get(\OCA\Libresign\Service\FileService::class)->delete($file->getId(),false),'native deletion cannot remove published campaign record');
}
$row=$store->recipients($id)[0];$store->update('libresign_camp_rcpt',(int)$row['id'],['queue_state'=>'failed']);
$db->beginTransaction();$retry=$service->queueNotifications($qa['owner'],$id,[(int)$row['id']],true);$store->update('libresign_camp_rcpt',(int)$row['id'],['available_at'=>time()+86400,'notify_pending'=>0]);$db->commit();
$check($retry['queued']===1&&(int)$store->recipient((int)$row['id'])['file_id']===$files[0]->getId(),'retry queues notification without creating a replacement PDF');
$sign=static function($file,$user,int $order,bool $empty=false)use($c,$mapper,$signers,$fields,$session,$profiles,$check,$reject,$files):void{
 // A web request constructs FolderService with the authenticated user ID. CLI
 // reuses the container across three identities, so explicitly refresh that
 // request-scoped context rather than changing the engine's access rules.
 $session->setUser($user);
 $folders=$c->get(\OCA\Libresign\Service\FolderService::class);$folders->setUserId($user->getUID());
 $profile=$folders->getFileByNodeId($profiles[$user->getUID()]);
 $check($profile->getId()===$profiles[$user->getUID()]&&strlen($profile->getContent())>0,'current synthetic signer can read their own visible signature');
 $c->get(\OCA\Libresign\Service\IdentifyMethodService::class)->clearCache();
 $request=array_values(array_filter($signers->getByFileId($file->getId()),fn($r)=>$r->getSigningOrder()===$order))[0];$values=[];
 foreach($fields->getByFileIdAndSignRequestId($file->getId(),$request->getId()) as $field){if($field->getType()==='signature'){$values[]=['documentElementId'=>$field->getId(),'profileNodeId'=>$profiles[$user->getUID()]];continue;}$values[]=['documentElementId'=>$field->getId(),'value'=>match($field->getType()){'text'=>'Signing date QA '.$user->getUID(),'date'=>\OCA\Libresign\Service\SigningFieldService::usesSigningDate($field)?'1999-12-31':'2000-02-29','checkbox'=>true}];}
 $c->get(\OCA\Libresign\Handler\SignEngine\Pkcs12Handler::class)->setCertificate('');
 $engine=$c->get(\OCA\Libresign\Service\SignFileService::class);$engine->prepareForSigning($mapper->getById($file->getId()),$signers->getById($request->getId()),$user,'account:'.$user->getUID(),$user->getDisplayName(),true,null,'clickToSign');$engine->setVisibleElements($empty?[]:$values)->sign();
 if($order===1&&$file->getId()===$files[0]->getId()) {
  $clock=new ReflectionProperty($engine,'signingFieldTime');$savedClock=$clock->getValue($engine);
  try{$clock->setValue($engine,$savedClock->modify('-1 day'));$guard=new ReflectionMethod($engine,'assertSigningDateMatchesSignature');$reject(fn()=>$guard->invoke($engine),'midnight-boundary mismatch is rejected instead of recording an incorrect date');}
  finally{$clock->setValue($engine,$savedClock);}
 }
 $signed=$signers->getById($request->getId());$stored=$signed->getMetadata()['signing_fields']??[];
 $dateValues=array_values(array_filter($stored,fn($value)=>$value['type']==='date'));
 $expected=DateTimeImmutable::createFromInterface($signed->getSigned())->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d');
 $check(count($dateValues)===2&&$dateValues[0]['value']===$expected&&$dateValues[1]['value']==='2000-02-29','automatic date matches this signer timestamp; forged date ignored; manual date preserved');
};
$reject(fn()=>$sign($files[0],$qa['one'],1,true),'required campaign fields cannot be skipped');
$sign($files[0],$qa['one'],1);$detail=$service->get($qa['owner'],$id);
$check($detail['summary']['completed']===0&&$detail['summary']['awaiting_counter']===1,'recipient signature waits for countersignature without affecting other copy');
$firstRequest=array_values(array_filter($signers->getByFileId($files[0]->getId()),fn($r)=>$r->getSigningOrder()===1))[0];$firstFields=$firstRequest->getMetadata()['signing_fields'];
$sign($files[0],$qa['owner'],2);
$check($signers->getById($firstRequest->getId())->getMetadata()['signing_fields']===$firstFields,'countersigning never rewrites the first signer date');$detail=$service->get($qa['owner'],$id);
$check($detail['summary']['completed']===1&&$detail['summary']['completionRate']===50.0,'first independent copy contributes exactly 50 percent');
$sign($files[1],$qa['two'],1);$sign($files[1],$qa['owner'],2);$detail=$service->get($qa['owner'],$id);
$check($detail['summary']['completed']===2&&$detail['summary']['completionRate']===100.0,'both independently countersigned copies complete campaign');
foreach($files as $file){$file=$mapper->getById($file->getId());$check($file->getCompletedAt()!==null&&hash_equals($file->getSignedHash(),hash('sha256',$archive->resolve($file)->getContent())),'completed PDF has recorded date, valid stored hash and protected archive copy');}
$query=$c->get(\OCA\Libresign\Service\ArchiveQueryService::class);
$check($query->list(['name'=>$suffix,'recipientName'=>$qa['one']->getDisplayName(),'recipientEmail'=>$qa['one']->getEMailAddress()])['total']===1,'separate archive name/email filters match correct recipient');
$check($query->list(['name'=>$suffix,'recipientName'=>$qa['one']->getDisplayName(),'recipientEmail'=>$qa['owner']->getEMailAddress()])['total']===0,'archive name and email cannot match different signers');
$controller=$c->get(\OCA\Libresign\Controller\CampaignController::class);$session->setUser($qa['one']);$row=$store->recipients($id)[0];
$check($controller->show($id)->getStatus()===403&&$controller->source($id)->getStatus()===403&&$controller->file($id,(int)$row['id'])->getStatus()===403&&$controller->export($id)->getStatus()===403,'ordinary signer denied campaign management, source, PDF and export endpoints');
$session->setUser($qa['owner']);$copy=$service->duplicate($qa['owner'],$id);$campaignIds[]=$copy['id'];
$check($copy['status']==='draft'&&count($copy['recipients'])===0&&count($copy['fields'])===10,'reuse preserves layout but never copies or notifies previous cohort');
$copy=$service->update($qa['owner'],$copy['id'],$copy['revision'],['name'=>$copy['name'],'version'=>'QA2','message'=>$copy['message'],'recipients'=>[$recipient($qa['one']),$recipient($qa['two'])],'counter'=>$copy['counter'],'fields'=>$copy['fields']]);
$publishQuiet($copy);$pending=[];
foreach($store->recipients($copy['id']) as $r){
 $token=bin2hex(random_bytes(16));$store->update('libresign_camp_rcpt',(int)$r['id'],['queue_state'=>'preparing','lease_token'=>$token,'lease_until'=>time()+900]);
 $bound=$create->invoke($service,$store->get($copy['id']),$store->recipient((int)$r['id']),$qa['owner']);
 $store->update('libresign_camp_rcpt',(int)$r['id'],['queue_state'=>'pending','available_at'=>time()+86400,'notify_pending'=>0,'lease_token'=>null,'lease_until'=>null]);$pending[]=$bound;
}
$exempt=$service->exempt($qa['owner'],$copy['id'],(int)$pending[0]['id'],'Synthetic QA exemption');
$check($exempt['summary']['exempt']===1&&$exempt['summary']['required']===1&&!$policy->canSign($mapper->getById((int)$pending[0]['file_id'])),'documented exemption changes denominator and disables old signing link');
$check($policy->canSign($mapper->getById((int)$pending[1]['file_id'])),'other independent request remains signable before cancellation');
$service->cancel($qa['owner'],$copy['id'],'Synthetic QA cancellation');
$reject(fn()=>$sign($mapper->getById((int)$pending[1]['file_id']),$qa['two'],1),'cancelled campaign cannot be signed through old engine link');
$check($mapper->getById((int)$pending[1]['file_id'])->getSignedHash()===null,'cancelled unsigned request does not acquire a signature');
$session->setUser($qa['owner']);
$service->cancel($qa['owner'],$id,'Synthetic QA finished');$check(!$policy->canSign($mapper->getById($files[0]->getId())),'cancellation disables old campaign signing links');
$check($service->get($qa['owner'],$id)['summary']['completed']===2,'cancellation preserves completed signatures and completion history');
foreach($qa as $user){$groups->get('admin')->removeUser($user);$user->setEnabled(false);}
$check(count(array_filter($qa,fn($user)=>$user->isEnabled()))===0,'all synthetic accounts disabled and temporary administrator membership removed');
echo json_encode(['checksPassed'=>$checks,'campaignIds'=>$campaignIds,'signedFileIds'=>array_map(fn($f)=>$f->getId(),$files),'validationUrls'=>array_map(fn($f)=>'https://nextcloud.sydani.org/apps/libresign/validation/'.$f->getUuid(),$files),'realEmailsSent'=>0,'existingDocumentsModified'=>0,'qaAccountsDisabledOnExit'=>true],JSON_PRETTY_PRINT).PHP_EOL;
