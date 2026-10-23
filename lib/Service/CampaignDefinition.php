<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

/** Pure validation shared by HTTP, queued dispatch and the staging tests. */
class CampaignDefinition {
	public const MAX_RECIPIENTS = 2000;
	public const MAX_PDF_BYTES = 20 * 1024 * 1024;

	public static function text(mixed $value, int $limit, string $label, bool $required = true): string {
		if (!is_string($value)) throw new \InvalidArgumentException($label . ' must be text.');
		$value = trim($value);
		if (($required && $value === '') || strlen($value) > $limit || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
			throw new \InvalidArgumentException('Invalid ' . $label . '.');
		}
		return $value;
	}

	public static function pdf(string $base64): string {
		if (strlen($base64) > (int)ceil(self::MAX_PDF_BYTES * 4 / 3) + 100) throw new \InvalidArgumentException('The campaign PDF exceeds 20 MB.');
		$base64 = preg_replace('/^data:application\/pdf;base64,/', '', $base64);
		$content = base64_decode($base64, true);
		if ($content === false || strlen($content) > self::MAX_PDF_BYTES || !str_starts_with($content, '%PDF-')) {
			throw new \InvalidArgumentException('Upload a valid PDF, up to 20 MB.');
		}
		return $content;
	}

	public static function fields(array $fields, array $dimensions, bool $hasCounter, bool $publishing = false): array {
		if (count($fields) > 200) throw new \InvalidArgumentException('A campaign supports up to 200 fields.');
		$result = [];
		$signatures = ['recipient' => false, 'counter' => false];
		foreach ($fields as $field) {
			if (!is_array($field) || !in_array($field['type'] ?? '', ['signature', 'text', 'date', 'checkbox'], true)
				|| !in_array($field['role'] ?? '', $hasCounter ? ['recipient', 'counter'] : ['recipient'], true)) {
				throw new \InvalidArgumentException('Invalid campaign field type or signer role.');
			}
			$c = $field['coordinates'] ?? [];
			$page = $c['page'] ?? null;
			if (!is_int($page) || $page < 1 || !isset($dimensions[$page - 1])) throw new \InvalidArgumentException('Field page does not exist.');
			foreach (['left', 'top', 'width', 'height'] as $key) {
				if (!isset($c[$key]) || !is_numeric($c[$key]) || !is_finite((float)$c[$key])) throw new \InvalidArgumentException('Invalid field coordinates.');
				$c[$key] = (float)$c[$key];
			}
			$size = $dimensions[$page - 1];
			if ($c['left'] < 0 || $c['top'] < 0 || $c['width'] < 8 || $c['height'] < 8
				|| $c['left'] + $c['width'] > (float)$size['w'] + 0.01 || $c['top'] + $c['height'] > (float)$size['h'] + 0.01) {
				throw new \InvalidArgumentException('Every field must fit inside its PDF page.');
			}
			$metadata = SigningFieldService::definition($field['metadata'] ?? []);
			$result[] = ['role' => $field['role'], 'type' => $field['type'], 'coordinates' => array_intersect_key($c, array_flip(['page', 'left', 'top', 'width', 'height'])), 'metadata' => $metadata];
			if ($field['type'] === 'signature') $signatures[$field['role']] = true;
		}
		if ($publishing && (!$signatures['recipient'] || ($hasCounter && !$signatures['counter']))) {
			throw new \InvalidArgumentException('Place at least one signature field for each signer role before sending.');
		}
		return $result;
	}

	public static function recipientStatus(array $row): string {
		if (($row['queue_state'] ?? '') === 'exempt') return 'exempt';
		if ((int)($row['file_status'] ?? -1) === 3 && !empty($row['signed_hash']) && !empty($row['completed_at'])) return 'completed';
		if (($row['campaign_status'] ?? '') === 'cancelled') return 'cancelled';
		if (($row['campaign_status'] ?? '') === 'draft') return 'draft';
		if (!empty($row['recipient_signed'])) return 'awaiting_counter';
		if (($row['queue_state'] ?? '') === 'failed') return 'failed';
		if (!empty($row['file_id']) && (!isset($row['file_status']) || (int)$row['file_status'] === 4)) return 'cancelled';
		if (empty($row['file_id'])) return 'queued';
		if (empty($row['notified_at'])) return 'notification_pending';
		return 'pending';
	}

	public static function summary(array $rows, ?int $deadline, ?int $now = null): array {
		$summary = ['total' => count($rows), 'completed' => 0, 'pending' => 0, 'overdue' => 0, 'awaiting_counter' => 0, 'failed' => 0, 'queued' => 0, 'notification_pending' => 0, 'draft' => 0, 'exempt' => 0, 'cancelled' => 0];
		foreach ($rows as $row) {
			$status = self::recipientStatus($row);
			if (isset($summary[$status])) $summary[$status]++;
			if (!in_array($status, ['completed', 'exempt', 'cancelled', 'draft'], true)) {
				if ($status !== 'pending') $summary['pending']++;
				if ($deadline !== null && $deadline < ($now ?? time())) $summary['overdue']++;
			}
		}
		$summary['required'] = $summary['total'] - $summary['exempt'];
		$summary['completionRate'] = $summary['required'] ? round(100 * $summary['completed'] / $summary['required'], 1) : 0;
		return $summary;
	}
}
