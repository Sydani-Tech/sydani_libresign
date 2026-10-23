<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// Isolated unit doubles. This process does not bootstrap Nextcloud or send mail.
namespace OCP {
 interface IUser {public function getUID():string;public function isEnabled():bool;}
 interface IUserManager {public function getByEmail(string $email):array;public function get(string $uid):mixed;}
 interface IAppConfig {public function getValueArray(string $app,string $key,array $default=[]):array;public function setValueArray(string $app,string $key,array $value):void;}
 interface IGroupManager {public function isInGroup(string $uid,string $gid):bool;public function get(string $gid):mixed;}
}
namespace OCP\Files {class NotPermittedException extends \Exception{}}
namespace OCA\Libresign\AppInfo {class Application{public const APP_ID='libresign';}}
namespace {
 if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
 require __DIR__.'/../../lib/Service/CampaignDefinition.php';
 require __DIR__.'/../../lib/Service/CampaignRecipientResolver.php';
 require __DIR__.'/../../lib/Service/CampaignAccessService.php';
 class UserDouble implements \OCP\IUser {public function __construct(public string $uid,public string $email,public bool $enabled=true){}public function getUID():string{return $this->uid;}public function getEMailAddress():string{return $this->email;}public function isEnabled():bool{return $this->enabled;}}
 $manager=new class implements \OCP\IUserManager {public array $users=[];public function get(string $uid):mixed{return $this->users[$uid]??null;}public function getByEmail(string $email):array{return array_values(array_filter($this->users,fn($u)=>strcasecmp($u->email,$email)===0));}};
 $config=new class implements \OCP\IAppConfig {public array $ids=['hr'];public function getValueArray(string $a,string $k,array $d=[]):array{return $this->ids;}public function setValueArray(string $a,string $k,array $v):void{$this->ids=$v;}};
 $groups=new class implements \OCP\IGroupManager {public array $members=['admin'=>['admin'],'hr'=>['hr','other']];public function isInGroup(string $u,string $g):bool{return in_array($u,$this->members[$g]??[],true);}public function get(string $g):mixed{return isset($this->members[$g])?new class($g){public function __construct(private string $id){}public function getDisplayName():string{return $this->id;}}:null;}};
 $checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new \RuntimeException($message);$checks++;echo 'PASS: '.$message.PHP_EOL;};
 $denied=static function(callable $fn,string $message)use($check):void{try{$fn();}catch(\InvalidArgumentException|\OCP\Files\NotPermittedException $e){$check(true,$message);return;}$check(false,$message);};
 $resolver=new \OCA\Libresign\Service\CampaignRecipientResolver($manager);
 $manager->users['alice']=new UserDouble('alice','Alice@Sydani.org');
 $internal=$resolver->resolve(['name'=>'Alice','email'=>'alice@sydani.org']);
 $check($internal['kind']==='internal'&&$internal['uid']==='alice','account match is internal regardless of supplied case');
 $external=$resolver->resolve(['name'=>'Guest','email'=>'guest@sydani.org']);
 $check($external['kind']==='external'&&$external['uid']===null,'Sydani address without account is external');
 $manager->users['partner']=new UserDouble('partner','partner@outside.test');
 $check($resolver->resolve(['name'=>'Partner','email'=>'partner@outside.test'])['kind']==='internal','non-Sydani address with account is internal');
 $denied(fn()=>$resolver->resolve(['name'=>'Wrong','email'=>'guest@outside.test','uid'=>'alice']),'mismatched selected UID rejected');
 $manager->users['alice']->enabled=false;
 $denied(fn()=>$resolver->resolve(['name'=>'Alice','email'=>'alice@sydani.org']),'disabled account cannot be downgraded to external');
 $manager->users['alice']->enabled=true;
 $manager->users['duplicate']=new UserDouble('duplicate','alice@sydani.org');
 $denied(fn()=>$resolver->resolve(['name'=>'Alice','email'=>'alice@sydani.org']),'ambiguous email rejected');unset($manager->users['duplicate']);
 $denied(fn()=>$resolver->resolveMany([['name'=>'Guest','email'=>'g@outside.test'],['name'=>'Again','email'=>'G@outside.test']]),'duplicate external emails rejected');
 $denied(fn()=>$resolver->resolveMany([['name'=>'A','email'=>'alice@sydani.org'],['name'=>'Again','email'=>'Alice@Sydani.org']]),'duplicate internal accounts rejected');
 $denied(fn()=>$resolver->resolve(['name'=>'Guest','email'=>'not-an-email']),'invalid email rejected');
 $denied(fn()=>$resolver->resolveMany([]),'empty published cohort rejected');
 $access=new \OCA\Libresign\Service\CampaignAccessService($config,$groups);
 $admin=new UserDouble('admin','admin@example.test');$hr=new UserDouble('hr','hr@example.test');$other=new UserDouble('other','other@example.test');$staff=new UserDouble('staff','staff@example.test');
 $check($access->canCreate($admin),'administrator allowed');
 $check($access->canCreate($hr),'configured group member allowed');
 $check(!$access->canCreate($staff),'ordinary signed-in user denied');
 $check(!$access->canCreate(null),'anonymous user denied');
 $denied(fn()=>$access->assertOwner($other,['owner_uid'=>'hr']),'another creator cannot inspect HR campaign');
 $access->assertOwner($hr,['owner_uid'=>'hr']);$check(true,'creator can inspect own campaign');
 $access->assertOwner($admin,['owner_uid'=>'hr']);$check(true,'administrator can inspect any campaign');
 $config->ids=[];$check(!$access->canCreate($hr)&&$access->canCreate($admin),'empty permission selection means admins only');
 $hr->enabled=false;$config->ids=['hr'];$check(!$access->canCreate($hr),'disabled creator denied even in authorised group');
 $denied(fn()=>$access->saveGroups(['missing']),'unknown permission group rejected');
 echo json_encode(['checksPassed'=>$checks,'documentChanges'=>0],JSON_PRETTY_PRINT).PHP_EOL;
}
