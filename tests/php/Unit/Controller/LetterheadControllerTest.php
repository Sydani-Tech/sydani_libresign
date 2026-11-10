<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Controller;
use OCA\Libresign\Controller\LetterheadController;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\LetterheadService;
use OCP\{IRequest,IUserSession,IUser};
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
class LetterheadControllerTest extends TestCase {
 public function testPolicyValidationReturnsAnActionable422(): void {
  $letters=$this->createMock(LetterheadService::class);$session=$this->createMock(IUserSession::class);$user=$this->createMock(IUser::class);$user->method('isEnabled')->willReturn(true);$session->method('getUser')->willReturn($user);
  $letters->method('prepareRequest')->with($user,15)->willThrowException(new LibresignException('The approved signer account is unavailable.',422));$logger=$this->createMock(LoggerInterface::class);$logger->expects($this->never())->method('error');
  $response=(new LetterheadController($this->createMock(IRequest::class),$letters,$session,$logger))->request(15);
  $this->assertSame(422,$response->getStatus());$this->assertSame(['message'=>'The approved signer account is unavailable.'],$response->getData());
 }
 public function testUnexpectedFailuresStillHideServerDetails(): void {
  $letters=$this->createMock(LetterheadService::class);$session=$this->createMock(IUserSession::class);$user=$this->createMock(IUser::class);$user->method('isEnabled')->willReturn(true);$session->method('getUser')->willReturn($user);
  $letters->method('prepareRequest')->willThrowException(new \RuntimeException('private filesystem diagnostics'));$logger=$this->createMock(LoggerInterface::class);$logger->expects($this->once())->method('error');
  $response=(new LetterheadController($this->createMock(IRequest::class),$letters,$session,$logger))->request(15);
  $this->assertSame(500,$response->getStatus());$this->assertStringNotContainsString('private filesystem',$response->getData()['message']);
 }
}
