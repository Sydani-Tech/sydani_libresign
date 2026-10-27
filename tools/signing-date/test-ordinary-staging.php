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
$users=\OCP\Server::get(\OCP\IUserManager::class);$session=\OCP\Server::get(\OCP\IUserSession::class);$root=\OCP\Server::get(\OCP\Files\IRootFolder::class);
$mapper=$c->get(\OCA\Libresign\Db\FileMapper::class);$signers=$c->get(\OCA\Libresign\Db\SignRequestMapper::class);$fields=$c->get(\OCA\Libresign\Db\FileElementMapper::class);$requests=$c->get(\OCA\Libresign\Service\RequestSignatureService::class);$archive=$c->get(\OCA\Libresign\Service\DocumentArchiveService::class);
$checks=0;$qa=[];$profiles=[];$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;echo 'PASS: '.$message.PHP_EOL;};
register_shutdown_function(static function()use(&$qa):void{foreach($qa as $u)try{$u->setEnabled(false);}catch(Throwable){fwrite(STDERR,'QA account cleanup required for '.$u->getUID().PHP_EOL);}});
$suffix=gmdate('YmdHis').'-'.bin2hex(random_bytes(2));$preferences=$c->get(\OCA\Libresign\Service\NotificationPreferenceResolver::class);
foreach(['first','second'] as $role){
 $u=$users->createUser('ordinary-date-qa-'.$role.'-'.$suffix,bin2hex(random_bytes(24)));$qa[]=$u;$u->setDisplayName('Ordinary Date QA '.$role.' '.$suffix);$u->setEMailAddress('ordinary-date-qa-'.$role.'-'.$suffix.'@example.invalid');
 foreach(['libresign_file_to_sign','libresign_file_signed'] as $type)foreach(['email','notification'] as $channel)$config->setUserValue($u->getUID(),'activity','notify_'.$channel.'_'.$type,'0');
 $check($preferences->isEmailNotificationDisabled($u->getUID(),'libresign_file_to_sign',true)&&$preferences->isEmailNotificationDisabled($u->getUID(),'libresign_file_signed',true),'test invitations and signed emails disabled for '.$role);
 $image=new Imagick();$image->newImage(180,60,new ImagickPixel('transparent'));$image->setImageFormat('png');$draw=new ImagickDraw();$draw->setStrokeColor(new ImagickPixel('black'));$draw->setStrokeWidth(3);foreach([[10,45,40,15],[40,15,60,40],[60,40,95,20],[95,20,165,42]] as $line)$draw->line(...$line);$image->drawImage($draw);
 $node=$root->getUserFolder($u->getUID())->newFile('ordinary-date-qa-mark.png',$image->getImageBlob());$profiles[$u->getUID()]=$node->getId();
 $element=new \OCA\Libresign\Db\UserElement();$element->setUserId($u->getUID());$element->setNodeId($node->getId());$element->setType('signature');$element->setCreatedAt(new DateTime());$element->setStarred(1);$c->get(\OCA\Libresign\Db\UserElementMapper::class)->insert($element);
}
$session->setUser($qa[0]);$c->get(\OCA\Libresign\Service\FolderService::class)->setUserId($qa[0]->getUID());$source=$root->getUserFolder($qa[0]->getUID())->newFile('ordinary-date-qa.pdf',file_get_contents(__DIR__.'/../../tests/php/fixtures/pdfs/small_valid.pdf'));
$people=[];foreach($qa as $i=>$u)$people[]=['displayName'=>$u->getDisplayName(),'identifyMethods'=>[['method'=>'account','value'=>$u->getUID()]],'notify'=>0,'signingOrder'=>$i+1];
$data=['file'=>['fileNode'=>$source],'name'=>'Ordinary signing date QA '.$suffix,'userManager'=>$qa[0],'status'=>0,'signatureFlow'=>'ordered_numeric','signers'=>$people];
$file=$requests->save($data);$rows=$signers->getByFileId($file->getId());usort($rows,fn($a,$b)=>$a->getSigningOrder()<=>$b->getSigningOrder());$check(count($rows)===2,'ordinary draft has two sequential signers');
$d=$file->getMetadata()['d'][0];$step=min(30,($d['h']-20)/10);$layout=[];
foreach($rows as $i=>$sr)foreach(['signature','text','date','date','checkbox'] as $j=>$type)$layout[]=['fileId'=>$file->getId(),'signRequestId'=>$sr->getId(),'type'=>$type,'coordinates'=>['page'=>1,'left'=>10,'top'=>10+($i*5+$j)*$step,'width'=>$type==='checkbox'?16:min(150,$d['w']-20),'height'=>min(18,$step-2)],'metadata'=>['label'=>$j===2?'Signing date WAT':($j===3?'Manual effective date':'QA '.$type),'required'=>true]+($j===2?['useSigningDate'=>true,'signingDateTimezone'=>'Africa/Lagos']:[])];
$data['uuid']=$file->getUuid();unset($data['file']);$data['status']=1;$data['visibleElements']=$layout;$c->get(\OCA\Libresign\Service\IdentifyMethodService::class)->clearCache();$file=$requests->save($data);
$check(count($fields->getByFileId($file->getId()))===10,'ordinary request save persists all ten visible fields');
foreach($rows as $sr){$formatted=$c->get(\OCA\Libresign\Service\File\FileListService::class)->formatSingleFileForSignRequest($mapper->getById($file->getId()),$signers->getById($sr->getId()));$auto=array_values(array_filter($formatted['visibleElements']??[],fn($e)=>($e['metadata']['useSigningDate']??false)===true));$check(count($auto)===2&&count(array_filter($auto,fn($e)=>$e['metadata']['signingDateTimezone']==='Africa/Lagos'))===2,'signer API retains automatic-date policy and WAT timezone');}
$firstFields=null;$expectedDate=null;
foreach($qa as $i=>$u){
 $session->setUser($u);$c->get(\OCA\Libresign\Service\FolderService::class)->setUserId($u->getUID());$c->get(\OCA\Libresign\Service\IdentifyMethodService::class)->clearCache();$sr=$signers->getById($rows[$i]->getId());$input=[];
 foreach($fields->getByFileIdAndSignRequestId($file->getId(),$sr->getId()) as $field)$input[]=$field->getType()==='signature'?['documentElementId'=>$field->getId(),'profileNodeId'=>$profiles[$u->getUID()]]:['documentElementId'=>$field->getId(),'value'=>match($field->getType()){'text'=>'Ordinary QA '.$i,'date'=>\OCA\Libresign\Service\SigningFieldService::usesSigningDate($field)?'1999-12-31':'2000-02-29','checkbox'=>true}];
 $c->get(\OCA\Libresign\Handler\SignEngine\Pkcs12Handler::class)->setCertificate('');$engine=$c->get(\OCA\Libresign\Service\SignFileService::class);$engine->prepareForSigning($mapper->getById($file->getId()),$sr,$u,'account:'.$u->getUID(),$u->getDisplayName(),true,null,'clickToSign');$engine->setVisibleElements($input)->sign();
 $signed=$signers->getById($sr->getId());$stored=$signed->getMetadata()['signing_fields']??[];$dates=array_values(array_filter($stored,fn($e)=>$e['type']==='date'));$expectedDate=DateTimeImmutable::createFromInterface($signed->getSigned())->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d');
 $check(count($dates)===2&&$dates[0]['value']===$expectedDate&&$dates[0]['value']!=='1999-12-31','actual signer '.($i+1).' uses WAT signing date despite forged client value');$check($dates[1]['value']==='2000-02-29','manual date remains independent for signer '.($i+1));
 if($i===0){$firstFields=$stored;$check($mapper->getById($file->getId())->getStatus()===2,'first signature leaves ordinary request partially signed');}else{$check($signers->getById($rows[0]->getId())->getMetadata()['signing_fields']===$firstFields,'second signer does not rewrite first signer fields');}
}
$file=$mapper->getById($file->getId());$pdf=$archive->resolve($file)->getContent();$check($file->getStatus()===3&&$file->getCompletedAt()!==null,'both ordinary signatures complete the request');$check(hash_equals($file->getSignedHash(),hash('sha256',$pdf)),'final signed PDF matches protected archive hash');
$path=tempnam(sys_get_temp_dir(),'ordinary-date-qa-');try{file_put_contents($path,$pdf);$lines=[];$status=1;exec('/usr/bin/pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null',$lines,$status);$text=preg_replace('/\s+/', ' ', implode(' ', $lines));$pretty=(new DateTimeImmutable($expectedDate))->format('jS M, Y');$check($status===0&&substr_count($text,$pretty)>=2,'both WAT dates are visibly written into the final PDF');$check(substr_count($text,'29th Feb, 2000')>=2&&!str_contains($text,'31st Dec, 1999'),'PDF preserves manual dates and contains no forged date');}finally{if(is_file($path))unlink($path);}
foreach($qa as $u)$u->setEnabled(false);$check(count(array_filter($qa,fn($u)=>$u->isEnabled()))===0,'both synthetic accounts disabled after testing');
echo json_encode(['checksPassed'=>$checks,'signedFileId'=>$file->getId(),'validationUrl'=>'https://nextcloud.sydani.org/apps/libresign/validation/'.$file->getUuid(),'realEmailsSent'=>0,'existingDocumentsModified'=>0,'qaAccountsDisabledOnExit'=>true],JSON_PRETTY_PRINT).PHP_EOL;
