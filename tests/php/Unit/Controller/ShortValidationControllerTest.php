<?php

declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Controller;
use OCA\Libresign\Controller\ShortValidationController;
use OCA\Libresign\Service\ShortValidationUrlService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
class ShortValidationControllerTest extends TestCase {
 public function testRedirectsOnlyToExistingSameOriginValidationPage():void{
  $uuid='8fe023cd-1f40-43d1-84c4-80cddfc672dc';$urls=$this->createMock(IURLGenerator::class);
  $urls->expects($this->once())->method('linkToRouteAbsolute')->with('libresign.page.validationFileWithShortUrl',['uuid'=>$uuid])->willReturn('https://nextcloud.sydani.org/apps/libresign/validation/'.$uuid);
  $links=$this->createMock(ShortValidationUrlService::class);$links->expects($this->once())->method('resolve')->with('SDs2-Vh4e')->willReturn($uuid);
  $response=(new ShortValidationController($this->createMock(IRequest::class),$urls,$links))->open('SDs2-Vh4e');
  $this->assertSame(303,$response->getStatus());$this->assertSame('https://nextcloud.sydani.org/apps/libresign/validation/'.$uuid,$response->getRedirectURL());$this->assertSame('no-store',(new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response)['Cache-Control']);
 }
 public function testBadCodeDoesNotResolveAnExternalDestination():void{
  $urls=$this->createMock(IURLGenerator::class);$urls->expects($this->never())->method('linkToRouteAbsolute');$links=$this->createMock(ShortValidationUrlService::class);$links->method('resolve')->willReturn(null);$response=(new ShortValidationController($this->createMock(IRequest::class),$urls,$links))->open('https://evil.invalid');$this->assertSame(404,$response->getStatus());
 }
 public function testTargetPageRetainsPrivateValidationPolicy():void{
  $method=new \ReflectionMethod(\OCA\Libresign\Controller\PageController::class,'validationFileWithShortUrl');$attr=$method->getAttributes(\OCA\Libresign\Middleware\Attribute\PrivateValidation::class);$this->assertCount(1,$attr);$this->assertFalse($attr[0]->newInstance()->allowValidSignRequestUuid());
 }

 public function testRegisteredRouteAcceptsGroupedAndLegacyCodes(): void {
  $routes=require dirname(__DIR__,4).'/appinfo/routes.php';
  $route=array_values(array_filter($routes['routes'],static fn($r)=>$r['name']==='shortValidation#open'))[0];
  $pattern='~^'.$route['requirements']['code'].'$~D';
  foreach(['SDs2-Vh4e','SDs2Vh4e','SNunCRPlSZiWtGVa6lItlg'] as $code)$this->assertSame(1,preg_match($pattern,$code));
  foreach(['../bad','SDs2_Vh4e','SDs-2Vh4e'] as $code)$this->assertSame(0,preg_match($pattern,$code));
 }
}
