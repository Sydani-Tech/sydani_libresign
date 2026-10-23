<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CampaignStore {
	public function __construct(public readonly IDBConnection $db) {
	}

	public function get(int $id): array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('*')->from('libresign_campaign')->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
		if (!$row) throw new DoesNotExistException('Campaign not found.');
		return $row;
	}

	public function recipient(int $id): array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('*')->from('libresign_camp_rcpt')->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
		if (!$row) throw new DoesNotExistException('Recipient not found.');
		return $row;
	}

	public function campaigns(?string $owner, int $page = 1, int $length = 25): array {
		$q = $this->db->getQueryBuilder();
		$q->from('libresign_campaign');
		if ($owner !== null) $q->where($q->expr()->eq('owner_uid', $q->createNamedParameter($owner)));
		$count = clone $q;
		$total = (int)$count->select($count->func()->count('id'))->executeQuery()->fetchOne();
		$rows = $q->select('*')->orderBy('id', 'DESC')->setFirstResult((max(1, $page) - 1) * $length)->setMaxResults($length)->executeQuery()->fetchAll();
		return ['rows' => $rows, 'total' => $total];
	}

	public function recipients(int $id): array {
		$q = $this->db->getQueryBuilder();
		return $q->select('r.*', 'f.uuid', 'f.status', 'f.signed_hash', 'f.completed_at', 'f.requested_at')
			->selectAlias('f.status', 'file_status')
			->selectAlias('c.status', 'campaign_status')
			->selectAlias('sr.signed', 'recipient_signed')
			->from('libresign_camp_rcpt', 'r')
			->innerJoin('r', 'libresign_campaign', 'c', 'c.id = r.campaign_id')
			->leftJoin('r', 'libresign_file', 'f', 'f.id = r.file_id')
			->leftJoin('r', 'libresign_sign_request', 'sr', 'sr.file_id = r.file_id AND sr.signing_order = 1')
			->where($q->expr()->eq('r.campaign_id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->orderBy('r.id', 'ASC')->executeQuery()->fetchAll();
	}

	public function events(int $id): array {
		$q = $this->db->getQueryBuilder();
		return $q->select('*')->from('libresign_camp_event')->where($q->expr()->eq('campaign_id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')->setMaxResults(100)->executeQuery()->fetchAll();
	}

	public function insert(string $table, array $values): int {
		$this->assertTable($table);
		$q = $this->db->getQueryBuilder();
		$q->insert($table);
		foreach ($values as $column => $value) $q->setValue($column, $this->parameter($q, $value));
		$q->executeStatement();
		return (int)$q->getLastInsertId();
	}

	public function update(string $table, int $id, array $values, array $expected = []): bool {
		$this->assertTable($table);
		$q = $this->db->getQueryBuilder();
		$q->update($table)->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		foreach ($values as $column => $value) $q->set($column, $this->parameter($q, $value));
		foreach ($expected as $column => $value) $q->andWhere($value === null ? $q->expr()->isNull($column) : $q->expr()->eq($column, $this->parameter($q, $value)));
		return $q->executeStatement() === 1;
	}

	private function parameter($q, mixed $value): mixed {
		return $q->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
	}

	private function assertTable(string $table): void {
		if (!in_array($table, ['libresign_campaign', 'libresign_camp_rcpt', 'libresign_camp_event'], true)) throw new \LogicException('Invalid campaign table.');
	}

	public function event(int $id, string $actor, string $action, array $details = []): void {
		$this->insert('libresign_camp_event', ['campaign_id' => $id, 'actor' => $actor, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => time()]);
	}

	public function replaceDraftRecipients(int $id, array $recipients): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('libresign_camp_rcpt')->where($q->expr()->eq('campaign_id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
		foreach ($recipients as $row) {
			$this->insert('libresign_camp_rcpt', ['campaign_id' => $id, 'recipient_key' => $row['recipient_key'], 'name' => $row['name'], 'email' => $row['email'], 'user_uid' => $row['uid'], 'queue_state' => 'draft']);
		}
	}

	public function activateRecipients(int $id): void {
		$q = $this->db->getQueryBuilder();
		$q->update('libresign_camp_rcpt')->set('queue_state', $q->createNamedParameter('queued'))->set('available_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($q->expr()->eq('campaign_id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('queue_state', $q->createNamedParameter('draft')))->executeStatement();
	}

	/** Atomic leases fence off concurrent cron/web workers. */
	public function claim(int $limit = 10, ?int $campaignId = null): array {
		$q = $this->db->getQueryBuilder();
		$q->select('r.*')->from('libresign_camp_rcpt', 'r')->innerJoin('r', 'libresign_campaign', 'c', 'c.id = r.campaign_id')
			->where($q->expr()->eq('c.status', $q->createNamedParameter('active')))
			->andWhere($q->expr()->in('r.queue_state', $q->createNamedParameter(['queued', 'pending', 'preparing'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($q->expr()->orX($q->expr()->isNull('r.available_at'), $q->expr()->lte('r.available_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT))))
			->andWhere($q->expr()->orX($q->expr()->isNull('r.lease_until'), $q->expr()->lte('r.lease_until', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT))))
			->andWhere($q->expr()->orX($q->expr()->isNull('r.file_id'), $q->expr()->eq('r.notify_pending', $q->createNamedParameter(1, IQueryBuilder::PARAM_INT))))
			->orderBy('r.id', 'ASC')->setMaxResults(max(1, min(20, $limit)));
		if ($campaignId !== null) $q->andWhere($q->expr()->eq('c.id', $q->createNamedParameter($campaignId, IQueryBuilder::PARAM_INT)));
		$result = [];
		foreach ($q->executeQuery()->fetchAll() as $row) {
			$token = bin2hex(random_bytes(16));
			if ($this->update('libresign_camp_rcpt', (int)$row['id'], ['queue_state' => 'preparing', 'lease_token' => $token, 'lease_until' => time() + 900], ['queue_state' => $row['queue_state'], 'lease_token' => $row['lease_token']])) {
				$result[] = [...$row, 'lease_token' => $token, 'queue_state' => 'preparing'];
			}
		}
		return $result;
	}
}
