<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Libresign\Service;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Permanent, case-sensitive base62 aliases. Database constraints arbitrate collisions and concurrent creators. */
class ShortValidationCodeStore {
	public const TABLE = 'libresign_validation_link';
	public const MAX_CODE = 218340105584895; // 62^8 - 1; exactly representable on Nextcloud's 64-bit PHP runtime.
	private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

	public function __construct(private IDBConnection $db) {
	}

	public function getOrCreate(string $uuid): int {
		if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $uuid)) {
			throw new \InvalidArgumentException('Invalid validation UUID');
		}
		$uuid = strtolower($uuid);
		$existing = $this->findCode($uuid);
		if ($existing !== null) {
			return $existing;
		}
		for ($attempt = 0; $attempt < 8; $attempt++) {
			$code = $this->candidate();
			try {
				$this->reserve($uuid, $code);
				return $code;
			} catch (Exception $e) {
				if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// A concurrent request for this UUID may already have committed its stable alias.
				$existing = $this->findCode($uuid);
				if ($existing !== null) {
					return $existing;
				}
			}
		}
		throw new \RuntimeException('Could not allocate a unique validation link. Please try again.');
	}

	public function findCode(string $uuid): ?int {
		$q = $this->db->getQueryBuilder();
		$code = $q->select('code_id')->from(self::TABLE)
			->where($q->expr()->eq('uuid', $q->createNamedParameter(strtolower($uuid))))
			->executeQuery()->fetchOne();
		return $code === false || $code === null ? null : (int)$code;
	}

	public function findUuid(int $code): ?string {
		if ($code < 0 || $code > self::MAX_CODE) {
			return null;
		}
		$q = $this->db->getQueryBuilder();
		$uuid = $q->select('uuid')->from(self::TABLE)
			->where($q->expr()->eq('code_id', $q->createNamedParameter($code, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchOne();
		return $uuid === false || $uuid === null ? null : (string)$uuid;
	}

	protected function candidate(): int {
		return random_int(0, self::MAX_CODE);
	}

	protected function reserve(string $uuid, int $code): void {
		$q = $this->db->getQueryBuilder();
		$q->insert(self::TABLE)->values([
			'code_id' => $q->createNamedParameter($code, IQueryBuilder::PARAM_INT),
			'uuid' => $q->createNamedParameter($uuid),
		])->executeStatement();
	}

	public static function format(int $code): string {
		if ($code < 0 || $code > self::MAX_CODE) {
			throw new \InvalidArgumentException('Validation code is outside the eight-character range');
		}
		$raw = '';
		for ($i = 0; $i < 8; $i++) {
			$raw = self::ALPHABET[$code % 62] . $raw;
			$code = intdiv($code, 62);
		}
		return substr($raw, 0, 4) . '-' . substr($raw, 4);
	}

	public static function parse(string $code): ?int {
		if (!preg_match('/^(?:[A-Za-z0-9]{8}|[A-Za-z0-9]{4}-[A-Za-z0-9]{4})$/D', $code)) {
			return null;
		}
		$value = 0;
		foreach (str_split(str_replace('-', '', $code)) as $character) {
			$value = $value * 62 + strpos(self::ALPHABET, $character);
		}
		return $value;
	}
}
