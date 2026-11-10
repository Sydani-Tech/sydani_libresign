<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Exception\TemporaryTimestampException;
use OCA\Libresign\Service\TimestampRetryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TimestampRetryServiceTest extends TestCase {
	private const TSA = 'https://tsa.example.test/tsr';
	private const SERIAL_FAILURE = 'No timestamp token has been retrieved (TSP Status : Error during serial number generation. / PKIFailureInfo: 0x400000)';

	private function retry(array &$delays): TimestampRetryService {
		$service = $this->getMockBuilder(TimestampRetryService::class)
			->setConstructorArgs([$this->createMock(LoggerInterface::class)])
			->onlyMethods(['waitBeforeRetry'])->getMock();
		$service->method('waitBeforeRetry')->willReturnCallback(static function (int $seconds) use (&$delays): void { $delays[] = $seconds; });
		return $service;
	}

	public function testSuccessDoesNotRepeatTheOperation(): void {
		$delays = []; $calls = 0;
		$result = $this->retry($delays)->run(static function () use (&$calls): string { $calls++; return 'signed PDF'; }, self::TSA);
		$this->assertSame('signed PDF', $result);
		$this->assertSame(1, $calls);
		$this->assertSame([], $delays);
	}

	public function testRetriesOnlyFailedEngineCallsAndReturnsTheSuccessfulResultOnce(): void {
		$delays = []; $calls = 0;
		$result = $this->retry($delays)->run(static function () use (&$calls): string {
			if (++$calls < 3) throw new \RuntimeException(self::SERIAL_FAILURE);
			return 'only successful PDF';
		}, self::TSA);
		$this->assertSame('only successful PDF', $result);
		$this->assertSame(3, $calls);
		$this->assertSame([1, 2], $delays);
	}

	public function testExhaustionIsBoundedAndNeverReturnsAFailedResult(): void {
		$delays = []; $calls = 0; $cause = new \RuntimeException(self::SERIAL_FAILURE);
		try {
			$this->retry($delays)->run(static function () use (&$calls, $cause): string { $calls++; throw $cause; }, self::TSA);
			$this->fail('A failed engine result must not be returned');
		} catch (TemporaryTimestampException $error) {
			$this->assertSame(503, $error->getCode());
			$this->assertSame($cause, $error->getPrevious());
			$this->assertStringContainsString('No signed document was saved', $error->getMessage());
		}
		$this->assertSame(3, $calls);
		$this->assertSame([1, 2], $delays);
	}

	public function testPermanentFailureAfterATemporaryFailureStopsImmediately(): void {
		$delays = []; $calls = 0; $permanent = new \RuntimeException('Certificate expired.');
		try {
			$this->retry($delays)->run(static function () use (&$calls, $permanent): string {
				if (++$calls === 1) throw new \RuntimeException(self::SERIAL_FAILURE);
				throw $permanent;
			}, self::TSA);
			$this->fail('Expected permanent failure');
		} catch (\RuntimeException $error) {
			$this->assertSame($permanent, $error);
		}
		$this->assertSame(2, $calls);
		$this->assertSame([1], $delays);
	}

	public static function failureCases(): array {
		$cases = [
			'provider serial error' => [self::SERIAL_FAILURE, self::TSA, 3],
			'JSON engine output' => ['Error to sign PDF. ' . json_encode(['INFO Creating signature', self::SERIAL_FAILURE]), self::TSA, 3],
			'no TSA configured' => [self::SERIAL_FAILURE, '', 1],
			'wrong certificate password' => ['Certificate Password Invalid.', self::TSA, 1],
			'untrusted chain' => ['Signer chain is not trusted by configured anchors.', self::TSA, 1],
			'missing revocation' => ['Revocation data is missing for one or more certificate(s).', self::TSA, 1],
			'field overlap' => ['The new signature field position overlaps with an existing annotation!', self::TSA, 1],
			'invalid timestamp algorithm' => ['No timestamp token has been retrieved (TSP Status : badAlg)', self::TSA, 1],
			'TSA authentication' => ['HTTP response code: 401 for URL: ' . self::TSA, self::TSA, 1],
			'TSA policy rejection' => ['HTTP response code: 403 for URL: ' . self::TSA, self::TSA, 1],
			'CRL endpoint HTTP error' => ['HTTP response code: 503 for URL: https://ca.example.test/list.crl', self::TSA, 1],
			'CRL timeout with TSA configuration in log' => ['INFO engine.dss.tsa.nonce=true WARNING CRL DSS Exception: Read timed out', self::TSA, 1],
			'wrong TSA path' => ['HTTP response code: 503 for URL: https://tsa.example.test/other', self::TSA, 1],
			'unknown failure' => ['Unrecognised signing failure', self::TSA, 1],
		];
		foreach ([429, 502, 503, 504] as $status) {
			$cases['TSA HTTP ' . $status] = ['Error to sign PDF. ' . json_encode(['HTTP response code: ' . $status . ' for URL: ' . self::TSA]), self::TSA, 3];
		}
		return $cases;
	}

	#[DataProvider('failureCases')]
	public function testOnlyExplicitTemporaryTimestampFailuresAreRetried(string $message, string $url, int $expectedCalls): void {
		$delays = []; $calls = 0; $cause = new \RuntimeException($message);
		try {
			$this->retry($delays)->run(static function () use (&$calls, $cause): string { $calls++; throw $cause; }, $url);
			$this->fail('Expected signing failure');
		} catch (\Exception $error) {
			if ($expectedCalls === 3) $this->assertInstanceOf(TemporaryTimestampException::class, $error);
			else $this->assertSame($cause, $error);
		}
		$this->assertSame($expectedCalls, $calls);
		$this->assertCount($expectedCalls - 1, $delays);
	}

	public function testProgrammingErrorsAreNeverRetried(): void {
		$delays = []; $calls = 0;
		try {
			$this->retry($delays)->run(static function () use (&$calls): string { $calls++; throw new \TypeError(self::SERIAL_FAILURE); }, self::TSA);
			$this->fail('Expected TypeError');
		} catch (\TypeError) {
			$this->assertSame(1, $calls);
			$this->assertSame([], $delays);
		}
	}
}
