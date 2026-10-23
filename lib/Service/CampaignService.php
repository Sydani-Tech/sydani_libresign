<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\Service;

use OCA\Libresign\BackgroundJob\CampaignDispatch;
use OCA\Libresign\Db\File;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Service\File\Pdf\PdfMetadataExtractor;
use OCP\BackgroundJob\IJobList;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class CampaignService {
	public function __construct(
		private CampaignStore $store,
		private CampaignAccessService $access,
		private CampaignRecipientResolver $resolver,
		private CampaignStorage $storage,
		private FileService $files,
		private PdfMetadataExtractor $pdfMetadata,
		private RequestSignatureService $requests,
		private FileMapper $fileMapper,
		private SignRequestMapper $signRequests,
		private FileElementService $elements,
		private IdentifyMethodService $identify,
		private DocumentArchiveService $archive,
		private CampaignPolicy $policy,
		private IUserManager $users,
		private IJobList $jobs,
		private LoggerInterface $logger,
	) {
	}

	public function list(IUser $user, int $page = 1): array {
		$this->access->assertCreator($user);
		$page = max(1, $page);
		$result = $this->store->campaigns($this->access->isAdmin($user) ? null : $user->getUID(), $page);
		return ['data' => array_map(fn ($row) => $this->present($row, false), $result['rows']), 'total' => $result['total'], 'page' => $page, 'length' => 25];
	}

	public function get(IUser $user, int $id): array {
		$campaign = $this->authorised($user, $id);
		return $this->present($campaign, true);
	}

	public function authorised(IUser $user, int $id): array {
		$this->access->assertCreator($user);
		$campaign = $this->store->get($id);
		$this->access->assertOwner($user, $campaign);
		return $campaign;
	}

	private function metadata(array $input): array {
		$due = $input['dueAt'] ?? null;
		if ($due !== null && (!is_numeric($due) || (int)$due < 0 || (int)$due > 253402300799)) throw new \InvalidArgumentException('Invalid campaign deadline.');
		return [
			'name' => CampaignDefinition::text($input['name'] ?? '', 200, 'campaign name'),
			'version' => CampaignDefinition::text($input['version'] ?? '1', 80, 'document version'),
			'message' => CampaignDefinition::text($input['message'] ?? '', 4000, 'campaign message', false),
			'due_at' => $due === null ? null : (int)$due,
		];
	}

	private function counter(mixed $input): ?array {
		if ($input === null || $input === []) return null;
		if (!is_array($input)) throw new \InvalidArgumentException('Invalid countersigner.');
		return $this->resolver->resolve($input);
	}

	private function recipientInput(array $input, ?array $counter): array {
		$recipients = $input ? $this->resolver->resolveMany($input) : [];
		foreach ($recipients as $recipient) {
			if ($counter && $counter['recipient_key'] === $recipient['recipient_key']) throw new \InvalidArgumentException('A countersigner cannot also be a campaign recipient.');
		}
		return $recipients;
	}

	public function create(IUser $user, array $input): array {
		$this->access->assertCreator($user);
		$values = $this->metadata($input);
		$content = CampaignDefinition::pdf($input['pdf'] ?? '');
		$sourceName = CampaignDefinition::text($input['sourceName'] ?? 'document.pdf', 200, 'PDF filename');
		$this->files->validateFileContent($content, $sourceName, 'pdf');
		$counter = $this->counter($input['counter'] ?? null);
		$recipients = $this->recipientInput($input['recipients'] ?? [], $counter);
		$db = $this->store->db;
		$db->beginTransaction();
		try {
			$id = $this->store->insert('libresign_campaign', [...$values, 'owner_uid' => $user->getUID(), 'status' => 'draft', 'revision' => 1, 'created_at' => time(),
				'source_name' => $sourceName, 'fields' => '[]', 'counter' => json_encode($counter, JSON_THROW_ON_ERROR), 'pdf_metadata' => '{}']);
			$node = $this->storage->write($id, $content);
			$this->pdfMetadata->setFile($node);
			$pdfMetadata = $this->pdfMetadata->getPageDimensions();
			$fields = CampaignDefinition::fields($input['fields'] ?? [], $pdfMetadata['d'] ?? [], $counter !== null);
			$this->store->update('libresign_campaign', $id, ['source_file' => $node->getName(), 'source_hash' => hash('sha256', $content),
				'pdf_metadata' => json_encode($pdfMetadata, JSON_THROW_ON_ERROR), 'fields' => json_encode($fields, JSON_THROW_ON_ERROR)]);
			$this->store->replaceDraftRecipients($id, $recipients);
			$this->store->event($id, $user->getUID(), 'created', ['recipients' => count($recipients)]);
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
		return $this->get($user, $id);
	}

	public function update(IUser $user, int $id, int $revision, array $input): array {
		$campaign = $this->authorised($user, $id);
		if ($campaign['status'] !== 'draft') throw new \RuntimeException('Published campaign documents, recipients and layouts cannot be edited. Reuse the campaign to create a new version.', 409);
		$values = $this->metadata($input);
		$counter = $this->counter($input['counter'] ?? null);
		$recipients = $this->recipientInput($input['recipients'] ?? [], $counter);
		$pdfMetadata = json_decode($campaign['pdf_metadata'], true, 512, JSON_THROW_ON_ERROR);
		if (!empty($input['pdf'])) {
			$content = CampaignDefinition::pdf($input['pdf']);
			$sourceName = CampaignDefinition::text($input['sourceName'] ?? 'document.pdf', 200, 'PDF filename');
			$this->files->validateFileContent($content, $sourceName, 'pdf');
			$node = $this->storage->write($id, $content);
			$this->pdfMetadata->setFile($node);
			$pdfMetadata = $this->pdfMetadata->getPageDimensions();
			$values += ['source_file' => $node->getName(), 'source_hash' => hash('sha256', $content), 'source_name' => $sourceName, 'pdf_metadata' => json_encode($pdfMetadata, JSON_THROW_ON_ERROR)];
			// Positions for the previous PDF must never silently carry over.
			$input['fields'] = [];
		}
		$fields = CampaignDefinition::fields($input['fields'] ?? [], $pdfMetadata['d'] ?? [], $counter !== null);
		$values += ['counter' => json_encode($counter, JSON_THROW_ON_ERROR), 'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'revision' => $revision + 1];
		$db = $this->store->db;
		$db->beginTransaction();
		try {
			if (!$this->store->update('libresign_campaign', $id, $values, ['status' => 'draft', 'revision' => $revision])) throw new \RuntimeException('The campaign changed in another window. Reload before saving.', 409);
			$this->store->replaceDraftRecipients($id, $recipients);
			$this->store->event($id, $user->getUID(), 'draft_updated', ['revision' => $revision + 1, 'recipients' => count($recipients)]);
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
		return $this->get($user, $id);
	}

	public function duplicate(IUser $user, int $id): array {
		$c = $this->authorised($user, $id);
		$counter = json_decode($c['counter'], true, 512, JSON_THROW_ON_ERROR);
		return $this->create($user, ['name' => $c['name'], 'version' => mb_strcut($c['version'], 0, 65) . ' (copy)', 'message' => $c['message'],
			'pdf' => base64_encode($this->storage->verifiedContent($c)), 'sourceName' => $c['source_name'],
			'fields' => json_decode($c['fields'], true, 512, JSON_THROW_ON_ERROR), 'counter' => $counter, 'recipients' => []]);
	}

	public function publish(IUser $user, int $id, int $revision): array {
		$c = $this->authorised($user, $id);
		if ($c['status'] === 'active') return $this->get($user, $id); // Safe repeat after a lost response.
		if ($c['status'] !== 'draft') throw new \RuntimeException('This campaign cannot be published.', 409);
		if ($c['due_at'] !== null && (int)$c['due_at'] < time()) throw new \InvalidArgumentException('Choose a deadline in the future before sending.');
		$counter = $this->counter(json_decode($c['counter'], true, 512, JSON_THROW_ON_ERROR));
		$raw = array_map(fn ($r) => ['name' => $r['name'], 'email' => $r['email'], 'uid' => $r['user_uid']], $this->store->recipients($id));
		$recipients = $this->recipientInput($raw, $counter);
		if (!$recipients) throw new \InvalidArgumentException('Add at least one campaign recipient.');
		$meta = json_decode($c['pdf_metadata'], true, 512, JSON_THROW_ON_ERROR);
		CampaignDefinition::fields(json_decode($c['fields'], true, 512, JSON_THROW_ON_ERROR), $meta['d'] ?? [], $counter !== null, true);
		$content = $this->storage->verifiedContent($c);
		// Validate each identity method against the instance's actual enabled
		// methods; this does not turn on email signing or weaken authentication.
		foreach ($recipients as $r) {
			$this->identify->clearCache();
			$this->requests->validateSigners(['signers' => $this->signers($r, $counter, $c['message']), 'status' => 1]);
		}
		// Also validate PDF/DocMDP constraints before any invitations are queued.
		$this->requests->validateNewRequestToFile(['name' => $c['name'], 'file' => ['base64' => base64_encode($content)], 'userManager' => $this->users->get($c['owner_uid']),
			'signers' => $this->signers($recipients[0], $counter, $c['message']), 'status' => 1, 'settings' => []]);
		$db = $this->store->db;
		$db->beginTransaction();
		try {
			if (!$this->store->update('libresign_campaign', $id, ['status' => 'active', 'published_at' => time(), 'revision' => $revision + 1,
				'counter' => json_encode($counter, JSON_THROW_ON_ERROR)], ['status' => 'draft', 'revision' => $revision])) throw new \RuntimeException('The campaign changed in another window. Reload before sending.', 409);
			// Freeze the just-resolved identities; never reinterpret recipients in cron.
			$this->store->replaceDraftRecipients($id, $recipients);
			$this->store->activateRecipients($id);
			$this->store->event($id, $user->getUID(), 'published', ['recipients' => count($recipients), 'sourceHash' => $c['source_hash']]);
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
		$this->jobs->add(CampaignDispatch::class);
		return $this->get($user, $id);
	}

	private function signers(array $recipient, ?array $counter, string $message): array {
		$result = [];
		foreach (array_filter([$recipient, $counter]) as $index => $row) {
			$uid = $row['uid'] ?? $row['user_uid'] ?? null;
			$result[] = ['displayName' => $row['name'], 'description' => $message, 'notify' => 0, 'signingOrder' => $index + 1,
				'identifyMethods' => [['method' => $uid !== null ? 'account' : 'email', 'value' => $uid ?? $row['email']]]];
		}
		return $result;
	}

	/** Cron and the short authenticated progress tick use the same leased queue. */
	public function dispatchBatch(int $limit = 10, ?int $campaignId = null): array {
		$result = ['processed' => 0, 'failed' => 0];
		foreach ($this->store->claim($limit, $campaignId) as $row) {
			try {
				$c = $this->store->get((int)$row['campaign_id']);
				$owner = $this->users->get($c['owner_uid']);
				$this->access->assertCreator($owner);
				if ($c['status'] !== 'active') throw new \RuntimeException('Campaign no longer active.');
				if (empty($row['file_id'])) $row = $this->createRequest($c, $row, $owner);
				$file = $this->fileMapper->getById((int)$row['file_id']);
				$this->policy->assertCanSign($file);
				if ($file->getStatus() !== 3) $this->notifyPending($file);
				$this->store->update('libresign_camp_rcpt', (int)$row['id'], ['queue_state' => 'pending', 'notify_pending' => 0,
					'notified_at' => $row['notified_at'] ?? time(), 'last_notified_at' => time(), 'last_error' => null, 'attempts' => 0, 'lease_token' => null, 'lease_until' => null], ['lease_token' => $row['lease_token']]);
				$result['processed']++;
			} catch (\Throwable $e) {
				$attempt = (int)$row['attempts'] + 1;
				$this->store->update('libresign_camp_rcpt', (int)$row['id'], ['queue_state' => $attempt >= 3 ? 'failed' : 'queued', 'attempts' => $attempt,
					'available_at' => time() + min(1800, 60 * (2 ** $attempt)), 'last_error' => mb_strcut($e->getMessage(), 0, 1000), 'lease_token' => null, 'lease_until' => null], ['lease_token' => $row['lease_token']]);
				$this->logger->warning('Campaign dispatch failed', ['campaignId' => $row['campaign_id'], 'recipientId' => $row['id'], 'exception' => $e]);
				$result['failed']++;
			}
		}
		return $result;
	}

	private function createRequest(array $c, array $row, IUser $owner): array {
		$content = $this->storage->verifiedContent($c);
		// Revalidate frozen account bindings, without silently changing them.
		$resolved = $this->resolver->resolve(['name' => $row['name'], 'email' => $row['email'], 'uid' => $row['user_uid']]);
		if ($resolved['uid'] !== $row['user_uid']) throw new \RuntimeException('The recipient identity changed after publication. Review this campaign before retrying.');
		$counter = $this->counter(json_decode($c['counter'], true, 512, JSON_THROW_ON_ERROR));
		$frozenCounter = json_decode($c['counter'], true, 512, JSON_THROW_ON_ERROR);
		if ($counter && $counter['uid'] !== ($frozenCounter['uid'] ?? null)) throw new \RuntimeException('The countersigner identity changed after publication. Review this campaign before retrying.');
		$data = ['name' => $c['name'] . ' — ' . $row['name'], 'file' => ['base64' => base64_encode($content)], 'userManager' => $owner,
			'status' => 1, 'settings' => [], 'signatureFlow' => 'ordered_numeric', 'signers' => $this->signers($resolved, $counter, $c['message'])];
		$this->identify->clearCache();
		$this->requests->validateNewRequestToFile($data);
		$node = $this->storage->write((int)$c['id'], $content, 'recipient-' . $row['id']);
		$data['file'] = ['fileNode' => $node];
		$db = $this->store->db;
		$db->beginTransaction();
		try {
			// The lease and campaign must still be valid before committing a copy.
			$current = $this->store->recipient((int)$row['id']);
			if ($current['lease_token'] !== $row['lease_token'] || $this->store->get((int)$c['id'])['status'] !== 'active') throw new \RuntimeException('Campaign dispatch lease changed.');
			if (!empty($current['file_id'])) { $db->commit(); return $current; }
			$file = $this->requests->save($data);
			$signers = $this->signRequests->getByFileId($file->getId());
			$roles = [];
			foreach ($signers as $signer) $roles[$signer->getSigningOrder() === 1 ? 'recipient' : 'counter'] = $signer->getId();
			foreach (json_decode($c['fields'], true, 512, JSON_THROW_ON_ERROR) as $field) {
				if (!isset($roles[$field['role']])) throw new \RuntimeException('Campaign signer role was not created.');
				$this->elements->saveVisibleElement(['fileId' => $file->getId(), 'signRequestId' => $roles[$field['role']], 'type' => $field['type'], 'coordinates' => $field['coordinates'], 'metadata' => $field['metadata']]);
			}
			$metadata = $file->getMetadata() ?? [];
			$metadata['campaign'] = ['id' => (int)$c['id'], 'recipientId' => (int)$row['id'], 'sourceHash' => $c['source_hash'], 'version' => $c['version']];
			$file->setMetadata($metadata);
			$this->fileMapper->update($file);
			if (!$this->store->update('libresign_camp_rcpt', (int)$row['id'], ['file_id' => $file->getId()], ['lease_token' => $row['lease_token'], 'file_id' => null])) throw new \RuntimeException('Campaign dispatch was already claimed by another worker.');
			$this->store->event((int)$c['id'], $owner->getUID(), 'request_created', ['recipientId' => (int)$row['id'], 'fileId' => $file->getId()]);
			$db->commit();
			$row['file_id'] = $file->getId();
			return $row;
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
	}

	private function notifyPending(File $file): void {
		$this->identify->clearCache();
		foreach ($this->signRequests->getByFileId($file->getId()) as $request) {
			if ($request->getSigned() || $request->getStatus() !== 1) continue;
			foreach ($this->identify->getIdentifyMethodsFromSignRequestId($request->getId()) as $instances) {
				foreach ($instances as $method) { $method->willNotifyUser(true); $method->notify(); }
			}
		}
	}

	public function queueNotifications(IUser $user, int $id, array $ids, bool $retry): array {
		$c = $this->authorised($user, $id);
		if ($c['status'] !== 'active') throw new \RuntimeException('Only active campaigns can send reminders or retry requests.', 409);
		$ids = array_values(array_unique(array_map('intval', $ids)));
		if (count($ids) > CampaignDefinition::MAX_RECIPIENTS) throw new \InvalidArgumentException('Too many recipients.');
		$count = 0;
		foreach ($this->store->recipients($id) as $row) {
			if ($ids && !in_array((int)$row['id'], $ids, true)) continue;
			$status = CampaignDefinition::recipientStatus($row);
			if (in_array($status, ['completed', 'exempt', 'cancelled'], true) || (!$retry && empty($row['file_id'])) || ($retry && $row['queue_state'] !== 'failed')) continue;
			if (!empty($row['last_notified_at']) && (int)$row['last_notified_at'] > time() - 60) continue;
			if (!empty($row['lease_until']) && (int)$row['lease_until'] > time()) continue;
			if ($this->store->update('libresign_camp_rcpt', (int)$row['id'], ['queue_state' => empty($row['file_id']) ? 'queued' : 'pending', 'notify_pending' => 1, 'attempts' => 0, 'available_at' => time(), 'last_error' => null], ['lease_token' => $row['lease_token']])) $count++;
		}
		$this->store->event($id, $user->getUID(), $retry ? 'retry_queued' : 'reminder_queued', ['count' => $count]);
		$this->jobs->add(CampaignDispatch::class);
		return ['queued' => $count];
	}

	public function cancel(IUser $user, int $id, string $reason): array {
		$c = $this->authorised($user, $id);
		$reason = CampaignDefinition::text($reason, 1000, 'cancellation reason');
		if ($c['status'] !== 'cancelled') {
			$this->store->update('libresign_campaign', $id, ['status' => 'cancelled', 'cancelled_at' => time()], ['status' => $c['status']]);
			$this->store->event($id, $user->getUID(), 'cancelled', ['reason' => $reason]);
		}
		return $this->get($user, $id);
	}

	public function exempt(IUser $user, int $id, int $recipientId, string $reason): array {
		$this->authorised($user, $id);
		$row = $this->store->recipient($recipientId);
		if ((int)$row['campaign_id'] !== $id) throw new NotPermittedException('Recipient does not belong to this campaign.');
		$reason = CampaignDefinition::text($reason, 1000, 'exemption reason');
		if (!empty($row['lease_until']) && (int)$row['lease_until'] > time()) throw new \RuntimeException('A request is being processed. Try again shortly.', 409);
		if ($row['file_id'] && $this->archive->hasSignatures($this->fileMapper->getById((int)$row['file_id']))) throw new \RuntimeException('A signed or partially signed request cannot be exempted. Its signing record is preserved.', 409);
		if (!$this->store->update('libresign_camp_rcpt', $recipientId, ['queue_state' => 'exempt', 'exemption_reason' => $reason, 'notify_pending' => 0], ['lease_token' => $row['lease_token']])) throw new \RuntimeException('The recipient changed in another window. Reload and try again.', 409);
		$this->store->event($id, $user->getUID(), 'exempted', ['recipientId' => $recipientId, 'reason' => $reason]);
		return $this->get($user, $id);
	}

	public function recipientFile(IUser $user, int $id, int $recipientId): \OCP\Files\File {
		$this->authorised($user, $id);
		$row = $this->store->recipient($recipientId);
		if ((int)$row['campaign_id'] !== $id || empty($row['file_id'])) throw new \OCP\Files\NotFoundException('Campaign document not found.');
		$file = $this->fileMapper->getById((int)$row['file_id']);
		$metadata = ($file->getMetadata() ?? [])['campaign'] ?? [];
		if (($metadata['id'] ?? null) !== $id || ($metadata['recipientId'] ?? null) !== $recipientId) throw new NotPermittedException('Campaign document binding is invalid.');
		return $this->archive->resolve($file);
	}

	private function present(array $c, bool $detail): array {
		$rows = $this->store->recipients((int)$c['id']);
		$summary = CampaignDefinition::summary($rows, $c['due_at'] !== null ? (int)$c['due_at'] : null);
		$result = ['id' => (int)$c['id'], 'name' => $c['name'], 'version' => $c['version'], 'message' => $c['message'], 'status' => $c['status'], 'revision' => (int)$c['revision'],
			'owner' => ['uid' => $c['owner_uid'], 'name' => $this->users->get($c['owner_uid'])?->getDisplayName() ?? $c['owner_uid']],
			'createdAt' => (int)$c['created_at'], 'requestedAt' => $c['published_at'] !== null ? (int)$c['published_at'] : null,
			'dueAt' => $c['due_at'] !== null ? (int)$c['due_at'] : null, 'sourceName' => $c['source_name'], 'sourceHash' => $c['source_hash'], 'summary' => $summary];
		if ($detail) {
			$result += ['fields' => json_decode($c['fields'], true, 512, JSON_THROW_ON_ERROR), 'counter' => json_decode($c['counter'], true, 512, JSON_THROW_ON_ERROR),
				'recipients' => array_map(fn ($row) => ['id' => (int)$row['id'], 'name' => $row['name'], 'email' => $row['email'], 'uid' => $row['user_uid'],
					'kind' => $row['user_uid'] !== null ? 'internal' : 'external', 'status' => CampaignDefinition::recipientStatus($row), 'fileId' => $row['file_id'] ? (int)$row['file_id'] : null,
					'fileUuid' => $row['uuid'], 'requestedAt' => $row['requested_at'], 'completedAt' => $row['completed_at'], 'recipientSignedAt' => $row['recipient_signed'],
					'notifiedAt' => $row['notified_at'] ? (int)$row['notified_at'] : null, 'error' => $row['last_error'], 'exemptionReason' => $row['exemption_reason']], $rows),
				'events' => array_map(fn ($event) => ['id' => (int)$event['id'], 'actor' => $event['actor'], 'action' => $event['action'], 'createdAt' => (int)$event['created_at'], 'details' => json_decode($event['details'], true, 512, JSON_THROW_ON_ERROR)], $this->store->events((int)$c['id']))];
		}
		return $result;
	}
}
