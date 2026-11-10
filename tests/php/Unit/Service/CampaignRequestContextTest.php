<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;
use OCA\Libresign\Service\CampaignAccessService;
use OCA\Libresign\Service\CampaignRequestContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
final class CampaignRequestContextTest extends TestCase {
 public function testBackgroundCreationUsesOwnerAndRestoresAnonymousContext(): void {
  $owner=$this->createMock(IUser::class);$session=$this->createMock(IUserSession::class);$access=$this->createMock(CampaignAccessService::class);$current=null;$calls=[];
  $session->method('getUser')->willReturnCallback(static function()use(&$current){return $current;});
  $session->method('setVolatileActiveUser')->willReturnCallback(static function($user)use(&$current,&$calls):void{$current=$user;$calls[]=$user;});
  $access->expects($this->once())->method('assertCreator')->with($owner);
  $result=(new CampaignRequestContext($session,$access))->run($owner,function()use(&$current,$owner){$this->assertSame($owner,$current);return 'created';});
  $this->assertSame('created',$result);$this->assertNull($current);$this->assertSame([$owner,null],$calls);
 }
 public function testFailureRestoresPreviousUser(): void {
  $owner=$this->createMock(IUser::class);$previous=$this->createMock(IUser::class);$current=$previous;$session=$this->createMock(IUserSession::class);$access=$this->createMock(CampaignAccessService::class);
  $session->method('getUser')->willReturnCallback(static function()use(&$current){return $current;});$session->method('setVolatileActiveUser')->willReturnCallback(static function($user)use(&$current):void{$current=$user;});
  try{(new CampaignRequestContext($session,$access))->run($owner,static function(){throw new \RuntimeException('synthetic failure');});$this->fail('Expected failure');}catch(\RuntimeException $e){$this->assertSame('synthetic failure',$e->getMessage());}
  $this->assertSame($previous,$current);
 }
 public function testUnauthorisedOwnerCannotChangeContextOrRunCallback(): void {
  $owner=$this->createMock(IUser::class);$session=$this->createMock(IUserSession::class);$access=$this->createMock(CampaignAccessService::class);
  $access->method('assertCreator')->willThrowException(new \OCP\Files\NotPermittedException('denied'));$session->expects($this->never())->method('setVolatileActiveUser');
  $this->expectException(\OCP\Files\NotPermittedException::class);(new CampaignRequestContext($session,$access))->run($owner,fn()=>$this->fail('Callback must not run'));
 }
}
