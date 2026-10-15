<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Listener;

use OCA\Libresign\Listener\BeforeNodeDeletedListener;
use OCA\Libresign\Service\DocumentArchiveService;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BeforeNodeDeletedListenerTest extends TestCase {

	public static function deletionEvents(): array {
		return ['appdata generic event' => [GenericEvent::class], 'user-files typed event' => [BeforeNodeDeletedEvent::class]];
	}

	#[DataProvider('deletionEvents')]
	public function testSignedMasterProtectedBeforeMimeTypeCheck(string $eventClass): void {
		$archive = $this->createMock(DocumentArchiveService::class);
		$archive->expects($this->once())->method('isProtectedSignedNode')->with(123)->willReturn(true);
		$archive->expects($this->never())->method('detachDeletedSource');
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(123);
		$node->expects($this->never())->method('getMimeType');
		$listener = new BeforeNodeDeletedListener($this->createMock(IDBConnection::class), $archive);
		$this->expectException(NotPermittedException::class);
		$listener->handle(new $eventClass($node));
	}

	public function testEnclosingArchiveFolderCannotBeDeleted(): void {
		$archive = $this->createMock(DocumentArchiveService::class);
		$archive->method('isProtectedSignedNode')->willReturn(false);
		$node = $this->createMock(Folder::class);
		$node->method('getId')->willReturn(124);
		$archive->expects($this->once())->method('containsProtectedSignedNode')->with($node)->willReturn(true);
		$archive->expects($this->never())->method('detachDeletedSource');
		$listener = new BeforeNodeDeletedListener($this->createMock(IDBConnection::class), $archive);
		$this->expectException(NotPermittedException::class);
		$listener->handle(new GenericEvent($node));
	}

	public function testIndependentOriginalStillDetachesWithoutErasingSigningRecords(): void {
		$archive = $this->createMock(DocumentArchiveService::class);
		$archive->method('isProtectedSignedNode')->willReturn(false);
		$archive->expects($this->once())->method('detachDeletedSource')->with(125)->willReturn(true);
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(125);
		$node->method('getMimeType')->willReturn('application/pdf');
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		(new BeforeNodeDeletedListener($db, $archive))->handle(new GenericEvent($node));
	}
}
