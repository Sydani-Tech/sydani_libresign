<?php

declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Service\ShortValidationCodeStore;
use OCP\DB\Exception;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ShortValidationCodeStoreTest extends TestCase {
	private const UUID = '48dba709-13e5-4998-96b4-655aea522d96';

	public function testGroupedCodeRetainsCaseAndCoversWholeEightCharacterRange(): void {
		$this->assertSame('0000-0000', ShortValidationCodeStore::format(0));
		$this->assertSame('zzzz-zzzz', ShortValidationCodeStore::format(ShortValidationCodeStore::MAX_CODE));
		foreach (['SDs2-Vh4e', 'AbCd-0123', 'abcd-0123', '0000-0000', 'zzzz-zzzz'] as $code) {
			$id = ShortValidationCodeStore::parse($code);
			$this->assertSame($code, ShortValidationCodeStore::format($id));
			$this->assertSame($id, ShortValidationCodeStore::parse(str_replace('-', '', $code)));
		}
		$this->assertNotSame(ShortValidationCodeStore::parse('AbCd-0123'), ShortValidationCodeStore::parse('abcd-0123'));
	}

	public function testMalformedCodesCannotReachALookup(): void {
		foreach (['', 'AbCd012', 'AbCd01234', 'AbC-d0123', 'AbCd_0123', '../x.php', 'AbCd-!123', 'AbCd–0123', 'AbCd-0123?x'] as $code) {
			$this->assertNull(ShortValidationCodeStore::parse($code));
		}
		$this->expectException(\InvalidArgumentException::class);
		ShortValidationCodeStore::format(ShortValidationCodeStore::MAX_CODE + 1);
	}

	private function store(): ShortValidationCodeStore {
		return $this->getMockBuilder(ShortValidationCodeStore::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class)])
			->onlyMethods(['findCode', 'candidate', 'reserve'])->getMock();
	}

	private function conflict(int $reason = Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION): Exception {
		return new class($reason) extends Exception {
			public function __construct(private int $reason) { parent::__construct('Synthetic database failure'); }
			public function getReason(): ?int { return $this->reason; }
		};
	}

	public function testAStoredUuidAlwaysKeepsItsExistingCode(): void {
		$store = $this->store();
		$store->expects($this->once())->method('findCode')->with(self::UUID)->willReturn(42);
		$store->expects($this->never())->method('candidate');
		$store->expects($this->never())->method('reserve');
		$this->assertSame(42, $store->getOrCreate(strtoupper(self::UUID)));
	}

	public function testCodeCollisionRetriesWithoutReplacingTheOtherDocument(): void {
		$store = $this->store();
		$store->method('findCode')->willReturn(null);
		$store->expects($this->exactly(2))->method('candidate')->willReturnOnConsecutiveCalls(10, 11);
		$seen = [];
		$store->expects($this->exactly(2))->method('reserve')->willReturnCallback(function (string $uuid, int $code) use (&$seen): void {
			$seen[] = [$uuid, $code];
			if ($code === 10) throw $this->conflict();
		});
		$this->assertSame(11, $store->getOrCreate(self::UUID));
		$this->assertSame([[self::UUID, 10], [self::UUID, 11]], $seen);
	}

	public function testConcurrentCreationReusesTheWinningUuidMapping(): void {
		$store = $this->store();
		$store->expects($this->exactly(2))->method('findCode')->willReturnOnConsecutiveCalls(null, 99);
		$store->expects($this->once())->method('candidate')->willReturn(10);
		$store->expects($this->once())->method('reserve')->willThrowException($this->conflict());
		$this->assertSame(99, $store->getOrCreate(self::UUID));
	}

	public function testUnrelatedDatabaseFailureIsNotRetried(): void {
		$store = $this->store();
		$store->method('findCode')->willReturn(null);
		$store->expects($this->once())->method('candidate')->willReturn(10);
		$store->expects($this->once())->method('reserve')->willThrowException($this->conflict(Exception::REASON_CONNECTION_LOST));
		$this->expectException(Exception::class);
		$store->getOrCreate(self::UUID);
	}

	public function testCollisionRetryIsBounded(): void {
		$store = $this->store();
		$store->method('findCode')->willReturn(null);
		$store->expects($this->exactly(8))->method('candidate')->willReturn(10);
		$store->expects($this->exactly(8))->method('reserve')->willThrowException($this->conflict());
		$this->expectException(\RuntimeException::class);
		$store->getOrCreate(self::UUID);
	}
}
