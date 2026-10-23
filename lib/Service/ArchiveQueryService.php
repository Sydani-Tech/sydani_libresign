<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\FileStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/** Read-only organisational reporting. Access is admin-only in ArchiveController. */
class ArchiveQueryService {
	public function __construct(
		private IDBConnection $db,
		private FileMapper $fileMapper,
		private SignRequestMapper $signRequestMapper,
		private IUserManager $userManager,
		private DocumentArchiveService $documentArchive,
	) {
	}

	public function list(array $filter, int $page = 1, int $length = 25): array {
		$page = max(1, $page);
		$length = max(1, min(100, $length));
		$qb = $this->db->getQueryBuilder();
		$qb->from('libresign_file', 'f')
			->leftJoin('f', 'libresign_id_docs', 'idoc', 'idoc.file_id = f.id')
			->where($qb->expr()->eq('f.node_type', $qb->createNamedParameter('file')))
			->andWhere($qb->expr()->isNull('idoc.id'))
			->andWhere($qb->expr()->isNotNull('f.signed_node_id'))
			->andWhere($qb->expr()->isNotNull('f.signed_hash'));
		$statuses = match ($filter['status'] ?? 'completed') {
			'partial' => [FileStatus::PARTIAL_SIGNED->value],
			'all' => [FileStatus::PARTIAL_SIGNED->value, FileStatus::SIGNED->value],
			default => [FileStatus::SIGNED->value],
		};
		$qb->andWhere($qb->expr()->in('f.status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_INT_ARRAY)));
		if (!empty($filter['name'])) {
			$qb->andWhere($qb->expr()->iLike('f.name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filter['name']) . '%')));
		}
		if (!empty($filter['sender'])) {
			$qb->andWhere($qb->expr()->eq('f.user_id', $qb->createNamedParameter($filter['sender'])));
		}
		// Keep both filters on the same participant; matching different people on
		// one document must not satisfy a name/email combination.
		if (!empty($filter['recipientName']) || !empty($filter['recipientEmail'])) {
			$sub = $this->db->getQueryBuilder();
			$sub->select('sr2.file_id')->from('libresign_sign_request', 'sr2')
				->leftJoin('sr2', 'libresign_identify_method', 'im2', 'im2.sign_request_id = sr2.id');
			if (!empty($filter['recipientName'])) {
				$sub->andWhere($sub->expr()->iLike('sr2.display_name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filter['recipientName']) . '%')));
			}
			if (!empty($filter['recipientEmail'])) {
				$matches = $sub->expr()->andX(
					$sub->expr()->eq('im2.identifier_key', $qb->createNamedParameter('email')),
					$sub->expr()->iLike('im2.identifier_value', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filter['recipientEmail']) . '%')),
				);
				// Internal signers store a UID, not their email, in identify_method.
				$uids = [];
				$accounts = $this->db->getQueryBuilder();
				$accounts->selectDistinct('identifier_value')->from('libresign_identify_method')
					->where($accounts->expr()->eq('identifier_key', $accounts->createNamedParameter('account')));
				foreach ($accounts->executeQuery()->fetchAll() as $account) {
					$user = $this->userManager->get($account['identifier_value']);
					if ($user && stripos($user->getEMailAddress() ?? '', $filter['recipientEmail']) !== false) $uids[] = $user->getUID();
				}
				if ($uids) {
					$matches = $sub->expr()->orX($matches, $sub->expr()->andX(
						$sub->expr()->eq('im2.identifier_key', $qb->createNamedParameter('account')),
						$sub->expr()->in('im2.identifier_value', $qb->createNamedParameter(array_values(array_unique($uids)), IQueryBuilder::PARAM_STR_ARRAY)),
					));
				}
				$sub->andWhere($matches);
			}
			$qb->andWhere($qb->expr()->in('f.id', $qb->createFunction($sub->getSQL())));
		}
		if (!empty($filter['recipient'])) {
			$needle = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filter['recipient']) . '%');
			$sub = $this->db->getQueryBuilder();
			$sub->select('sr.file_id')->from('libresign_sign_request', 'sr')
				->leftJoin('sr', 'libresign_identify_method', 'im', 'im.sign_request_id = sr.id')
				->where($sub->expr()->orX(
					$sub->expr()->iLike('sr.display_name', $needle),
					$sub->expr()->iLike('im.identifier_value', $needle),
				));
			$accounts = filter_var($filter['recipient'], FILTER_VALIDATE_EMAIL)
				? $this->userManager->getByEmail($filter['recipient']) : [];
			if ($accounts) {
				$sub->orWhere($sub->expr()->in('im.identifier_value', $qb->createNamedParameter(
					array_map(static fn ($user) => $user->getUID(), $accounts), IQueryBuilder::PARAM_STR_ARRAY,
				)));
			}
			$qb->andWhere($qb->expr()->in('f.id', $qb->createFunction($sub->getSQL())));
		}
		$dateColumn = ($filter['dateBy'] ?? '') === 'requested' ? 'f.requested_at' : 'f.completed_at';
		foreach (['from' => 'gte', 'to' => 'lte'] as $key => $operation) {
			if (!empty($filter[$key])) {
				$date = (new \DateTimeImmutable('@' . (int)$filter[$key]))->format('Y-m-d H:i:s');
				$qb->andWhere($qb->expr()->$operation($dateColumn, $qb->createNamedParameter($date)));
			}
		}
		$count = clone $qb;
		$count->select($count->func()->count('f.id'));
		$total = (int)$count->executeQuery()->fetchOne();
		$sort = match ($filter['sortBy'] ?? '') {
			'requested' => 'f.requested_at',
			'name' => 'f.name',
			'sender' => 'f.user_id',
			default => 'f.completed_at',
		};
		$direction = ($filter['direction'] ?? '') === 'asc' ? 'ASC' : 'DESC';
		$qb->select('f.*')->orderBy($qb->createFunction('CASE WHEN ' . $sort . ' IS NULL THEN 1 ELSE 0 END'), 'ASC')
			->addOrderBy($sort, $direction)->addOrderBy('f.id', $direction)
			->setFirstResult(($page - 1) * $length)->setMaxResults($length);
		$rows = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$file = (new File())->fromRow($row);
			$rows[] = $this->describe($file);
		}
		return ['data' => $rows, 'total' => $total, 'page' => $page, 'length' => $length, 'senders' => $this->senders()];
	}

	public function getProtectedFile(int $fileId): File {
		$file = $this->fileMapper->getById($fileId);
		if (!$file->getSignedNodeId() || !$this->documentArchive->hasSignatures($file)) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('Signed document not found');
		}
		return $file;
	}

	public function describe(File $file): array {
		$requests = $this->signRequestMapper->getByFileId($file->getId());
		$methods = $requests ? $this->signRequestMapper->getIdentifyMethodsFromSigners($requests) : [];
		$recipients = [];
		foreach ($requests as $request) {
			$identifiers = [];
			foreach ($methods[$request->getId()] ?? [] as $method) {
				$value = $method->getIdentifierValue();
				$identifiers[] = ['type' => $method->getIdentifierKey(), 'value' => $value];
				if ($method->getIdentifierKey() === 'account') {
					$email = $this->userManager->get($value)?->getEMailAddress();
					if ($email) {
						$identifiers[] = ['type' => 'email', 'value' => $email];
					}
				}
			}
			$metadata = $request->getMetadata() ?? [];
			$recipients[] = [
				'name' => $request->getDisplayName(),
				'identifiers' => $identifiers,
				'order' => $request->getSigningOrder(),
				'status' => $request->getStatus(),
				'statusText' => $this->signRequestMapper->getTextOfSignerStatus($request->getStatus()),
				'signedAt' => $request->getSigned()?->format(\DateTimeInterface::ATOM),
				'signedHash' => $request->getSignedHash(),
				'fields' => $metadata['signing_fields'] ?? [],
				'ipAddress' => $metadata['remote-address'] ?? null,
				'userAgent' => $metadata['user-agent'] ?? null,
			];
		}
		usort($recipients, static fn ($a, $b) => $a['order'] <=> $b['order']);
		return [
			'id' => $file->getId(), 'uuid' => $file->getUuid(), 'name' => $file->getName(),
			'status' => $file->getStatus(), 'statusText' => $this->fileMapper->getTextOfStatus($file->getStatus()),
			'sender' => ['id' => $file->getUserId(), 'name' => $this->userManager->get($file->getUserId())?->getDisplayName() ?? $file->getUserId()],
			'recipients' => $recipients,
			'requestedAt' => $file->getRequestedAt()?->format(\DateTimeInterface::ATOM),
			'completedAt' => $file->getCompletedAt()?->format(\DateTimeInterface::ATOM),
			'hash' => $file->getSignedHash(),
			'archived' => !empty(($file->getMetadata() ?? [])['organisational_archive']),
			'sourceDeleted' => !empty(($file->getMetadata() ?? [])['original_file_deleted']),
		];
	}

	private function senders(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')->from('libresign_file')
			->where($qb->expr()->isNotNull('signed_hash'));
		$senders = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$uid = (string)$row['user_id'];
			$senders[] = ['id' => $uid, 'name' => $this->userManager->get($uid)?->getDisplayName() ?? $uid];
		}
		usort($senders, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return $senders;
	}
}
