<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\CampaignAccessService;
use OCA\Libresign\Service\CampaignDefinition;
use OCA\Libresign\Service\CampaignRecipientResolver;
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
		private IUserManager $users, private IGroupManager $groups, private LoggerInterface $logger) {
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaign/access', requirements: ['apiVersion' => '(v1)'])]
	public function capability(): DataResponse {
		return new DataResponse(['canCreate' => $this->access->canCreate($this->session->getUser()), 'maxRecipients' => CampaignDefinition::MAX_RECIPIENTS, 'maxPdfBytes' => CampaignDefinition::MAX_PDF_BYTES]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaigns', requirements: ['apiVersion' => '(v1)'])]
	public function index(int $page = 1): DataResponse { return $this->respond(fn () => $this->campaigns->list($this->user(), $page)); }

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaigns/{id}', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function show(int $id): DataResponse { return $this->respond(fn () => $this->campaigns->get($this->user(), $id)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns', requirements: ['apiVersion' => '(v1)'])]
	public function create(array $campaign): DataResponse { return $this->respond(fn () => $this->campaigns->create($this->user(), $campaign)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/{apiVersion}/campaigns/{id}', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function update(int $id, int $revision, array $campaign): DataResponse { return $this->respond(fn () => $this->campaigns->update($this->user(), $id, $revision, $campaign)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/publish', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function publish(int $id, int $revision): DataResponse { return $this->respond(fn () => $this->campaigns->publish($this->user(), $id, $revision)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/duplicate', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function duplicate(int $id): DataResponse { return $this->respond(fn () => $this->campaigns->duplicate($this->user(), $id)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/process', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function process(int $id): DataResponse {
		return $this->respond(function () use ($id) {
			$this->campaigns->authorised($this->user(), $id);
			return $this->campaigns->dispatchBatch(2, $id);
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/remind', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function remind(int $id, array $recipientIds = []): DataResponse { return $this->respond(fn () => $this->campaigns->queueNotifications($this->user(), $id, $recipientIds, false)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/retry', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function retry(int $id, array $recipientIds = []): DataResponse { return $this->respond(fn () => $this->campaigns->queueNotifications($this->user(), $id, $recipientIds, true)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/cancel', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function cancel(int $id, string $reason): DataResponse { return $this->respond(fn () => $this->campaigns->cancel($this->user(), $id, $reason)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaigns/{id}/recipients/{recipientId}/exempt', requirements: ['apiVersion' => '(v1)', 'id' => '\d+', 'recipientId' => '\d+'])]
	public function exempt(int $id, int $recipientId, string $reason): DataResponse { return $this->respond(fn () => $this->campaigns->exempt($this->user(), $id, $recipientId, $reason)); }

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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaigns/{id}/recipients/{recipientId}/file', requirements: ['apiVersion' => '(v1)', 'id' => '\d+', 'recipientId' => '\d+'])]
	public function file(int $id, int $recipientId): FileDisplayResponse|DataResponse {
		$node = null;
		$response = $this->respond(function () use ($id, $recipientId, &$node) { $node = $this->campaigns->recipientFile($this->user(), $id, $recipientId); return []; });
		return $node ? new FileDisplayResponse($node, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']) : $response;
	}

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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaign/resolve', requirements: ['apiVersion' => '(v1)'])]
	public function resolve(array $recipient): DataResponse { return $this->respond(function () use ($recipient) { $this->user(); return $this->resolver->resolve($recipient); }); }

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

	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/campaign-settings', requirements: ['apiVersion' => '(v1)'])]
	public function settings(): DataResponse {
		return $this->respond(function () { if (!$this->access->isAdmin($this->session->getUser())) throw new NotPermittedException('Administrator access required.'); return ['groups' => $this->access->settings()]; });
	}

	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/campaign-settings', requirements: ['apiVersion' => '(v1)'])]
	public function saveSettings(array $groups): DataResponse {
		return $this->respond(function () use ($groups) { if (!$this->access->isAdmin($this->session->getUser())) throw new NotPermittedException('Administrator access required.'); return ['groups' => $this->access->saveGroups($groups)]; });
	}
}
