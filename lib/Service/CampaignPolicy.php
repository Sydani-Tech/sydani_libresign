<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\Service;

use OCA\Libresign\Db\File;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;

class CampaignPolicy {
	public function __construct(private IDBConnection $db) {
	}

	public static function assertEditable(File $file): void {
		if (!empty(($file->getMetadata() ?? [])['campaign'])) throw new NotPermittedException('Campaign recipients and field layouts are frozen after publication. Manage the request from Campaign instead.');
	}

	public function canSign(File $file): bool {
		$meta = ($file->getMetadata() ?? [])['campaign'] ?? null;
		if ($meta === null) return true;
		if (!is_array($meta) || !isset($meta['id'], $meta['recipientId'], $meta['sourceHash'])) return false;
		$q = $this->db->getQueryBuilder();
		$row = $q->select('c.status', 'c.source_hash', 'c.owner_uid', 'r.queue_state')->from('libresign_campaign', 'c')
			->innerJoin('c', 'libresign_camp_rcpt', 'r', 'r.campaign_id = c.id')
			->where($q->expr()->eq('c.id', $q->createNamedParameter((int)$meta['id'], IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('r.id', $q->createNamedParameter((int)$meta['recipientId'], IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('r.file_id', $q->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetch();
		return $row && $row['status'] === 'active' && $row['queue_state'] !== 'exempt'
			&& $row['owner_uid'] === $file->getUserId() && hash_equals($row['source_hash'], (string)$meta['sourceHash']);
	}

	public function assertCanSign(File $file): void {
		if (!$this->canSign($file)) throw new NotPermittedException('This campaign request is cancelled, exempted or no longer available. Contact the campaign sender.');
	}
}
