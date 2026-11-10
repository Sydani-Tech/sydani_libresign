<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\Controller;
use OCP\AppFramework\Http;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\CampaignAccessService;
use OCA\Libresign\Service\CampaignDefinition;
use OCA\Libresign\Service\CampaignRecipientResolver;
use OCA\Libresign\Service\CampaignRecipientImport;
use OCA\Libresign\Service\CampaignService;
use OCA\Libresign\Service\CampaignStorage;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class CampaignController extends OCSController {
	public function __construct(IRequest $request, private CampaignService $campaigns, private CampaignAccessService $access,
		private CampaignRecipientResolver $resolver, private CampaignStorage $storage, private IUserSession $session,
		private IUserManager $users, private IGroupManager $groups, private LoggerInterface $logger,
		private CampaignRecipientImport $importer) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function user(): IUser {
		$user = $this->session->getUser();
		$this->access->assertCreator($user);
		return $user;
	}

	private function respond(callable $action): DataResponse {
		try {
			return new DataResponse($action(), 200, ['Cache-Control' => 'private, no-store']);
		} catch (NotPermittedException $e) {
			return new DataResponse(['message' => $e->getMessage()], 403);
		} catch (DoesNotExistException|NotFoundException $e) {
			return new DataResponse(['message' => 'Campaign or recipient not found.'], 404);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], 422);
		} catch (\Throwable $e) {
			if ($e->getCode() === 409) return new DataResponse(['message' => $e->getMessage()], 409);
			// Validation errors from LibreSign itself are safe/actionable; never
			// expose database paths, credentials or an internal exception trace.
			if ($e instanceof \OCA\Libresign\Exception\LibresignException) return new DataResponse(['message' => $e->getMessage()], 422);
			$this->logger->error('Campaign operation failed', ['exception' => $e]);
			return new DataResponse(['message' => 'Unable to complete the campaign operation. Contact an administrator if this continues.'], 500);
		}
	}

	/**
	 * Read campaign creation permissions and upload limits.
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaign/access', requirements: ['apiVersion' => '(v1)'])]
	public function capability(): DataResponse {
		return new DataResponse(['canCreate' => $this->access->canCreate($this->session->getUser()), 'maxRecipients' => CampaignDefinition::MAX_RECIPIENTS, 'maxPdfBytes' => CampaignDefinition::MAX_PDF_BYTES]);
	}

	/**
	 * List authorised campaigns with filters and pagination.
	 *
	 * @param int $page One-based page number
	 * @param array<string, mixed> $filters Campaign filter values
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaigns', requirements: ['apiVersion' => '(v1)'])]
	public function index(int $page = 1, array $filters = []): DataResponse { return $this->respond(fn () => $this->campaigns->list($this->user(), $page, $filters)); }

	/**
	 * Read a campaign and its recipient completion records.
	 *
	 * @param int $id Record identifier
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaigns/{id}', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function show(int $id): DataResponse { return $this->respond(fn () => $this->campaigns->get($this->user(), $id)); }

	/**
	 * Create a draft campaign without sending invitations.
	 *
	 * @param array<string, mixed> $campaign Campaign definition with recipients, PDF and field layout
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns', requirements: ['apiVersion' => '(v1)'])]
	public function create(array $campaign): DataResponse { return $this->respond(fn () => $this->campaigns->create($this->user(), $campaign)); }

	/**
	 * Update a draft campaign using its current revision.
	 *
	 * @param int $id Record identifier
	 * @param int $revision Current revision for optimistic concurrency
	 * @param array<string, mixed> $campaign Campaign definition with recipients, PDF and field layout
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/{apiVersion}/campaigns/{id}', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function update(int $id, int $revision, array $campaign): DataResponse { return $this->respond(fn () => $this->campaigns->update($this->user(), $id, $revision, $campaign)); }

	/**
	 * Publish a campaign and queue independent signing requests.
	 *
	 * @param int $id Record identifier
	 * @param int $revision Current revision for optimistic concurrency
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/publish', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function publish(int $id, int $revision): DataResponse { return $this->respond(fn () => $this->campaigns->publish($this->user(), $id, $revision)); }

	/**
	 * Reuse a campaign layout without copying its recipients.
	 *
	 * @param int $id Record identifier
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/duplicate', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function duplicate(int $id): DataResponse { return $this->respond(fn () => $this->campaigns->duplicate($this->user(), $id)); }

	/**
	 * Process a bounded batch of queued campaign recipients.
	 *
	 * @param int $id Record identifier
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/process', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function process(int $id): DataResponse {
		return $this->respond(function () use ($id) {
			$this->campaigns->authorised($this->user(), $id);
			return $this->campaigns->dispatchBatch(2, $id);
		});
	}

	/**
	 * Queue reminders for selected eligible campaign recipients.
	 *
	 * @param int $id Record identifier
	 * @param list<int> $recipientIds Selected recipient identifiers; an empty list means eligible recipients
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/remind', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function remind(int $id, array $recipientIds = []): DataResponse { return $this->respond(fn () => $this->campaigns->queueNotifications($this->user(), $id, $recipientIds, false)); }

	/**
	 * Retry failed recipient notifications without replacing signed PDFs.
	 *
	 * @param int $id Record identifier
	 * @param list<int> $recipientIds Selected recipient identifiers; an empty list means eligible recipients
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/retry', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function retry(int $id, array $recipientIds = []): DataResponse { return $this->respond(fn () => $this->campaigns->queueNotifications($this->user(), $id, $recipientIds, true)); }

	/**
	 * Cancel pending signing links while preserving completed history.
	 *
	 * @param int $id Record identifier
	 * @param string $reason Recorded reason for the action
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/cancel', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function cancel(int $id, string $reason): DataResponse { return $this->respond(fn () => $this->campaigns->cancel($this->user(), $id, $reason)); }

	/**
	 * Exempt a recipient with a recorded reason.
	 *
	 * @param int $id Record identifier
	 * @param int $recipientId Campaign recipient identifier
	 * @param string $reason Recorded reason for the action
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/recipients/{recipientId}/exempt', requirements: ['apiVersion' => '(v1)', 'id' => '\d+', 'recipientId' => '\d+'])]
	public function exempt(int $id, int $recipientId, string $reason): DataResponse { return $this->respond(fn () => $this->campaigns->exempt($this->user(), $id, $recipientId, $reason)); }

	/**
	 * Retrieve the campaign source PDF for an authorised manager.
	 *
	 * @param int $id Record identifier
	 * @return FileDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaigns/{id}/source', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function source(int $id): FileDisplayResponse|DataResponse {
		$response = $this->respond(function () use ($id) { return $this->campaigns->authorised($this->user(), $id); });
		if ($response->getStatus() !== 200) return $response;
		try {
			$c = $response->getData();
			$this->storage->verifiedContent($c);
			return new FileDisplayResponse($this->storage->source($c), 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
		} catch (\Throwable $e) {
			$this->logger->error('Campaign source retrieval failed', ['campaignId' => $id, 'exception' => $e]);
			return new DataResponse(['message' => 'Campaign source PDF is not available.'], 404);
		}
	}

	/**
	 * Retrieve an independent recipient PDF for an authorised manager.
	 *
	 * @param int $id Record identifier
	 * @param int $recipientId Campaign recipient identifier
	 * @return FileDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaigns/{id}/recipients/{recipientId}/file', requirements: ['apiVersion' => '(v1)', 'id' => '\d+', 'recipientId' => '\d+'])]
	public function file(int $id, int $recipientId): FileDisplayResponse|DataResponse {
		$node = null;
		$response = $this->respond(function () use ($id, $recipientId, &$node) { $node = $this->campaigns->recipientFile($this->user(), $id, $recipientId); return []; });
		return $node ? new FileDisplayResponse($node, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']) : $response;
	}

	/**
	 * Export recipient completion records as CSV.
	 *
	 * @param int $id Record identifier
	 * @return DataDownloadResponse<Http::STATUS_OK, string, array{}>|DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaigns/{id}/export', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function export(int $id): DataDownloadResponse|DataResponse {
		$response = $this->respond(fn () => $this->campaigns->get($this->user(), $id));
		if ($response->getStatus() !== 200) return $response;
		$c = $response->getData();
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, "\xEF\xBB\xBF");
		fputcsv($stream, ['Recipient Name', 'Recipient Email', 'Type', 'Status', 'Date Requested', 'Date Completed', 'Recipient Signed', 'Deadline', 'Last Error', 'Exemption Reason'], ',', '"', '');
		foreach ($c['recipients'] as $r) {
			$values = [$r['name'], $r['email'], $r['kind'], $r['status'], $r['requestedAt'], $r['completedAt'], $r['recipientSignedAt'], $c['dueAt'] ? gmdate(\DateTimeInterface::ATOM, $c['dueAt']) : '', $r['error'], $r['exemptionReason']];
			fputcsv($stream, array_map(static function ($value) { $value = (string)$value; return preg_match('/^[=+@\-\t\r]/', $value) ? "'" . $value : $value; }, $values), ',', '"', '');
		}
		rewind($stream);
		$content = stream_get_contents($stream);
		fclose($stream);
		return new DataDownloadResponse($content, 'campaign-' . $id . '-completion.csv', 'text/csv; charset=utf-8');
	}

	/**
	 * Resolve a recipient to an existing account or external email.
	 *
	 * @param array<string, mixed> $recipient Recipient name, email and optional account identifier
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaign/resolve', requirements: ['apiVersion' => '(v1)'])]
	public function resolve(array $recipient): DataResponse { return $this->respond(function () use ($recipient) { $this->user(); return $this->resolver->resolve($recipient); }); }

	/**
	 * Parse recipient names and email addresses from CSV or XLSX.
	 *
	 * @param string $file Base64 encoded import file
	 * @param string $filename Original import filename
	 * @param string $sheet Worksheet name; empty selects the default sheet
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaign/import', requirements: ['apiVersion' => '(v1)'])]
	public function importRecipients(string $file, string $filename, string $sheet = ''): DataResponse {
		return $this->respond(function () use ($file, $filename, $sheet) { $this->user(); return $this->importer->parse($file, $filename, $sheet); });
	}

	/**
	 * Validate imported recipients and report errors per row.
	 *
	 * @param list<array<string, mixed>> $recipients Imported recipient rows to validate
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaign/resolve-import', requirements: ['apiVersion' => '(v1)'])]
	public function resolveImport(array $recipients): DataResponse {
		return $this->respond(function () use ($recipients) {
			$this->user();
			if (!$recipients || count($recipients) > CampaignDefinition::MAX_RECIPIENTS) throw new \InvalidArgumentException('Validate between 1 and 2000 recipients.');
			$result = []; $seen = [];
			foreach (array_values($recipients) as $index => $row) {
				try {
					if (!is_array($row)) throw new \InvalidArgumentException('Invalid recipient row.');
					$r = $this->resolver->resolve(['name' => $row['name'] ?? '', 'email' => $row['email'] ?? '']);
					if (isset($seen[$r['recipient_key']])) throw new \InvalidArgumentException('Duplicate recipient in this file.');
					$seen[$r['recipient_key']] = true; unset($r['recipient_key']); $result[] = ['index' => $index, 'recipient' => $r, 'error' => null];
				} catch (\InvalidArgumentException $e) { $result[] = ['index' => $index, 'recipient' => null, 'error' => $e->getMessage()]; }
			}
			return $result;
		});
	}

	/**
	 * Search active internal recipient accounts.
	 *
	 * @param string $search Search text
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaign/users', requirements: ['apiVersion' => '(v1)'])]
	public function users(string $search = ''): DataResponse {
		return $this->respond(function () use ($search) {
			$this->user();
			$search = CampaignDefinition::text($search, 200, 'user search', false);
			$found = $this->users->searchDisplayName($search, 20, 0);
			if (filter_var($search, FILTER_VALIDATE_EMAIL)) $found = [...$this->users->getByEmail($search), ...$found];
			$result = [];
			foreach ($found as $user) {
				if ($user->isEnabled() && filter_var($user->getEMailAddress(), FILTER_VALIDATE_EMAIL)) $result[$user->getUID()] = ['uid' => $user->getUID(), 'name' => $user->getDisplayName(), 'email' => $user->getEMailAddress()];
			}
			return array_values($result);
		});
	}

	/**
	 * Search available recipient groups.
	 *
	 * @param string $search Search text
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaign/groups', requirements: ['apiVersion' => '(v1)'])]
	public function groups(string $search = ''): DataResponse {
		return $this->respond(function () use ($search) {
			$this->user();
			$search = CampaignDefinition::text($search, 200, 'group search', false);
			return array_values(array_map(static fn ($group) => ['id' => $group->getGID(), 'displayname' => $group->getDisplayName()], $this->groups->search($search, 20, 0)));
		});
	}

	/**
	 * List eligible active recipients in a group.
	 *
	 * @param string $groupId Nextcloud group identifier
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaign/groups/{groupId}/users', requirements: ['apiVersion' => '(v1)'])]
	public function groupUsers(string $groupId): DataResponse {
		return $this->respond(function () use ($groupId) {
			$this->user();
			$group = $this->groups->get($groupId);
			if (!$group) throw new \InvalidArgumentException('Unknown recipient group.');
			$users = $group->searchUsers('', CampaignDefinition::MAX_RECIPIENTS + 1, 0);
			if (count($users) > CampaignDefinition::MAX_RECIPIENTS) throw new \InvalidArgumentException('This group exceeds the campaign recipient limit. Select a smaller group.');
			$result = [];
			foreach ($users as $user) {
				if ($user->isEnabled() && filter_var($user->getEMailAddress(), FILTER_VALIDATE_EMAIL)) $result[] = ['uid' => $user->getUID(), 'name' => $user->getDisplayName(), 'email' => $user->getEMailAddress(), 'kind' => 'internal'];
			}
			return $result;
		});
	}

	/**
	 * Read the groups authorised to create campaigns.
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaign-settings', requirements: ['apiVersion' => '(v1)'])]
	public function settings(): DataResponse {
		return $this->respond(function () { if (!$this->access->isAdmin($this->session->getUser())) throw new NotPermittedException('Administrator access required.'); return ['groups' => $this->access->settings()]; });
	}

	/**
	 * Set the groups authorised to create campaigns.
	 *
	 * @param list<string> $groups Authorised Nextcloud group identifiers
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaign-settings', requirements: ['apiVersion' => '(v1)'])]
	public function saveSettings(array $groups): DataResponse {
		return $this->respond(function () use ($groups) { if (!$this->access->isAdmin($this->session->getUser())) throw new NotPermittedException('Administrator access required.'); return ['groups' => $this->access->saveGroups($groups)]; });
	}
}
