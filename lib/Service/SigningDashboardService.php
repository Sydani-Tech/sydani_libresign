<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Files\NotPermittedException;

/** Personal inbox only. Administration does not widen this endpoint's scope. */
class SigningDashboardService {
	public const VIEWS = ['ready', 'signed', 'sent', 'completed'];
	public function __construct(private IDBConnection $db, private IUserManager $users) {}
	public function get(IUser $user, string $view = 'ready', int $page = 1): array {
		if (!$user->isEnabled()) throw new NotPermittedException('Sign in with an active account.');
		if (!in_array($view, self::VIEWS, true)) throw new \InvalidArgumentException('Unknown dashboard view.');
		$page = max(1, min(100000, $page)); $counts = [];
		foreach (self::VIEWS as $key) {
			$q = $this->query($user, $key);
			$q->selectAlias($q->createFunction('COUNT(DISTINCT f.id)'), 'total');
			$result = $q->executeQuery(); $counts[$key] = (int)$result->fetchOne(); $result->closeCursor();
		}
		$q = $this->query($user, $view);
		$q->selectDistinct(['f.id', 'f.uuid', 'f.name', 'f.status', 'f.user_id', 'f.requested_at', 'f.created_at', 'f.completed_at', 'c.name AS campaign_name', 'c.due_at AS deadline'])
			->orderBy('f.created_at', 'DESC')->addOrderBy('f.id', 'DESC')->setFirstResult(($page - 1) * 10)->setMaxResults(10);
		$cursor = $q->executeQuery(); $rows = [];
		while ($row = $cursor->fetch()) $rows[] = ['id' => (int)$row['id'], 'uuid' => $row['uuid'], 'name' => $row['name'], 'status' => (int)$row['status'],
			'sender' => $this->users->get($row['user_id'])?->getDisplayName() ?? $row['user_id'], 'requestedAt' => $row['requested_at'] ?? $row['created_at'],
			'completedAt' => $row['completed_at'], 'campaign' => $row['campaign_name'], 'deadline' => $row['deadline'] !== null ? (int)$row['deadline'] : null];
		$cursor->closeCursor();
		return ['counts' => $counts, 'view' => $view, 'page' => $page, 'perPage' => 10, 'total' => $counts[$view], 'documents' => $rows];
	}
	private function query(IUser $user, string $view): IQueryBuilder {
		$q = $this->db->getQueryBuilder(); $e = $q->expr();
		$q->from('libresign_file', 'f')->leftJoin('f', 'libresign_sign_request', 'sr', 'sr.file_id = f.id')
			->leftJoin('sr', 'libresign_identify_method', 'im', 'im.sign_request_id = sr.id')
			->leftJoin('f', 'libresign_id_docs', 'ids', 'ids.file_id = f.id')
			->leftJoin('f', 'libresign_camp_rcpt', 'cr', 'cr.file_id = f.id')
			->leftJoin('cr', 'libresign_campaign', 'c', 'c.id = cr.campaign_id')
			->where($e->isNull('ids.id'))->andWhere($e->neq('f.status', $q->createNamedParameter(4, IQueryBuilder::PARAM_INT)));
		if (in_array($view, ['sent', 'completed'], true)) {
			$q->andWhere($e->eq('f.user_id', $q->createNamedParameter($user->getUID())));
		} else {
			$identity = [$e->andX($e->eq('im.identifier_key', $q->createNamedParameter('account')), $e->eq('im.identifier_value', $q->createNamedParameter($user->getUID())))];
			$email = $user->getEMailAddress();
			if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$matches = $this->users->getByEmail($email);
				if (count($matches) === 1 && reset($matches)->getUID() === $user->getUID()) $identity[] = $e->andX($e->eq('im.identifier_key', $q->createNamedParameter('email')), $e->eq($q->func()->lower('im.identifier_value'), $q->createNamedParameter(strtolower($email))));
			}
			$q->andWhere($e->orX(...$identity));
		}
		if ($view === 'completed') {
			$q->andWhere($e->eq('f.status', $q->createNamedParameter(3, IQueryBuilder::PARAM_INT)))->andWhere($e->isNotNull('f.completed_at'))->andWhere($e->isNotNull('f.signed_hash'));
		} elseif ($view === 'signed') {
			$q->andWhere($e->isNotNull('sr.signed'))->andWhere($e->in('f.status', $q->createNamedParameter([1, 2, 3, 5], IQueryBuilder::PARAM_INT_ARRAY)));
		} else {
			$q->andWhere($e->in('f.status', $q->createNamedParameter($view === 'sent' ? [1, 2, 5] : [1, 2], IQueryBuilder::PARAM_INT_ARRAY)));
			$q->andWhere($e->orX($e->isNull('c.id'), $e->andX($e->eq('c.status', $q->createNamedParameter('active')), $e->neq('cr.queue_state', $q->createNamedParameter('exempt')))));
			if ($view !== 'sent') {
				$q->andWhere($e->isNull('sr.signed'))->andWhere($e->eq('sr.status', $q->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
			}
		}
		return $q;
	}
}
