<?php

declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\PageController;
use OCA\Libresign\Service\AccountService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class PageDownloadControllerTest extends TestCase {
	private function controller(AccountService $account): PageController {
		$controller=(new \ReflectionClass(PageController::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(PageController::class,'accountService'))->setValue($controller,$account);
		return $controller;
	}
	public function testDownloadUsesTheSameProtectedOriginalAndAttachmentHeaders(): void {
		$file=$this->createMock(File::class);$file->method('getMTime')->willReturn(1);$file->method('getMimeType')->willReturn('application/pdf');$file->method('getName')->willReturn("Signed résumé.pdf");
		$account=$this->createMock(AccountService::class);$account->expects($this->once())->method('getPdfByUuid')->with('file-uuid')->willReturn($file);
		$response=$this->controller($account)->getPdf('file-uuid',true);
		$headers=(new \ReflectionProperty(Response::class,'headers'))->getValue($response);
		$this->assertSame(200,$response->getStatus());
		$this->assertSame("attachment; filename*=UTF-8''".rawurlencode('Signed résumé.pdf'),$headers['Content-Disposition']);
		$this->assertSame('private, no-store',$headers['Cache-Control']);
		$this->assertSame('nosniff',$headers['X-Content-Type-Options']);
	}
	public function testViewKeepsExistingInlineBehaviour(): void {
		$file=$this->createMock(File::class);$file->method('getMTime')->willReturn(1);$file->method('getMimeType')->willReturn('application/pdf');$file->method('getName')->willReturn('view.pdf');
		$account=$this->createMock(AccountService::class);$account->method('getPdfByUuid')->willReturn($file);
		$response=$this->controller($account)->getPdf('file-uuid');
		$headers=(new \ReflectionProperty(Response::class,'headers'))->getValue($response);
		$this->assertSame('inline; filename="view.pdf"',$headers['Content-Disposition']);
	}
	public function testUnknownUuidCannotBeDownloaded(): void {
		$account=$this->createMock(AccountService::class);$account->method('getPdfByUuid')->willThrowException(new DoesNotExistException('missing'));
		$this->assertSame(404,$this->controller($account)->getPdf('missing',true)->getStatus());
	}
	public function testFilenameCannotInjectHeaders(): void {
		$file=$this->createMock(File::class);$file->method('getMTime')->willReturn(1);$file->method('getMimeType')->willReturn('application/pdf');$file->method('getName')->willReturn("test\r\nInjected: yes.pdf");
		$account=$this->createMock(AccountService::class);$account->method('getPdfByUuid')->willReturn($file);
		$headers=(new \ReflectionProperty(Response::class,'headers'))->getValue($this->controller($account)->getPdf('id',true));
		$this->assertStringNotContainsString("\r",$headers['Content-Disposition']);$this->assertStringNotContainsString("\n",$headers['Content-Disposition']);
	}
	public function testDownloadRetainsValidationPermissionAttribute(): void {
		$method=new \ReflectionMethod(PageController::class,'getPdf');
		$attributes=$method->getAttributes(\OCA\Libresign\Middleware\Attribute\PrivateValidation::class);
		$this->assertCount(1,$attributes);$this->assertFalse($attributes[0]->newInstance()->allowValidSignRequestUuid());
	}
}
