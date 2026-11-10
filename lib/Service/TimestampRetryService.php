<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Libresign\Service;

use OCA\Libresign\Exception\TemporaryTimestampException;
use Psr\Log\LoggerInterface;

/** Retry only the uncommitted engine operation, never the signing workflow. */
class TimestampRetryService {
	private const RETRY_DELAYS = [1, 2];

	public function __construct(private LoggerInterface $logger) {
	}

	/** @param callable(): string $sign */
	public function run(callable $sign, string $tsaUrl): string {
		for ($attempt = 0; ; $attempt++) {
			try {
				$result = $sign();
				if ($attempt > 0) {
					$this->logger->info('Timestamp service recovered during signing.', ['attempt' => $attempt + 1]);
				}
				return $result;
			} catch (\Exception $error) {
				if ($tsaUrl === '' || !self::isTemporaryFailure($error->getMessage(), $tsaUrl)) {
					throw $error;
				}
				if (!isset(self::RETRY_DELAYS[$attempt])) {
					throw new TemporaryTimestampException($error);
				}
				$delay = self::RETRY_DELAYS[$attempt];
				$this->logger->warning('Temporary timestamp failure; retrying the uncommitted signing operation.', [
					'attempt' => $attempt + 1, 'retryDelaySeconds' => $delay,
				]);
				$this->waitBeforeRetry($delay);
			}
		}
	}

	protected function waitBeforeRetry(int $seconds): void {
		sleep($seconds);
	}

	private static function isTemporaryFailure(string $message, string $tsaUrl): bool {
		// JSignPdf embeds its output as JSON; decode it before matching lines/URLs.
		$start = strpos($message, '[');
		if ($start !== false) {
			$lines = json_decode(substr($message, $start), true);
			if (is_array($lines) && count(array_filter($lines, 'is_string')) === count($lines)) {
				$message = implode("\n", $lines);
			}
		}
		if (preg_match('/No timestamp token has been retrieved\s*\(TSP Status\s*:\s*Error during serial number generation\b/i', $message)) {
			return true;
		}
		// A CRL/OCSP failure or unrelated HTTP response must never trigger a TSA retry.
		if (!preg_match_all('/HTTP response code:\s*(429|502|503|504)\s+for URL:\s*([^\s"\x27,]+)/i', $message, $matches, PREG_SET_ORDER)) {
			return false;
		}
		foreach ($matches as $match) {
			if (hash_equals(rtrim($tsaUrl, '/'), rtrim($match[2], '/'))) {
				return true;
			}
		}
		return false;
	}
}
