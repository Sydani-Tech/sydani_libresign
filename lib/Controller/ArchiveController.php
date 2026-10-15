<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\ArchiveQueryService;
use OCA\Libresign\Service\DocumentArchiveService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/** No NoAdminRequired/PublicPage attributes: Nextcloud enforces admin login too. */
class ArchiveController extends Controller {
	public function __construct(
		IRequest $request,
		private ArchiveQueryService $archiveQuery,
		private DocumentArchiveService $documentArchive,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isInGroup($user->getUID(), 'admin');
	}

	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/archive/list')]
	public function index(int $page = 1, int $length = 25, string $name = '', string $sender = '', string $recipient = '', string $status = 'completed', string $dateBy = 'completed', ?int $from = null, ?int $to = null, string $sortBy = 'completed', string $direction = 'desc'): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Administrator access required'], 403);
		}
		if (($from !== null && $to !== null && $from > $to) || strlen($name) > 200 || strlen($recipient) > 200) {
			return new DataResponse(['message' => 'Invalid archive filter'], 400);
		}
		return new DataResponse($this->archiveQuery->list(compact('name', 'sender', 'recipient', 'status', 'dateBy', 'from', 'to', 'sortBy', 'direction'), $page, $length), 200, ['Cache-Control' => 'private, no-store']);
	}

	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/archive/history/{fileId}')]
	public function history(int $fileId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Administrator access required'], 403);
		}
		try {
			return new DataResponse($this->archiveQuery->describe($this->archiveQuery->getProtectedFile($fileId)), 200, ['Cache-Control' => 'private, no-store']);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return new DataResponse(['message' => 'Signed document not found'], 404);
		}
	}

	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/archive/file/{fileId}')]
	public function file(int $fileId, bool $download = false): FileDisplayResponse|DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Administrator access required'], 403);
		}
		try {
			$file = $this->archiveQuery->getProtectedFile($fileId);
			$node = $this->documentArchive->resolve($file);
			$filename = preg_replace('/[\r\n\x00-\x1f]/', '', basename($file->getName())) . '.signed.' . $node->getExtension();
			return new FileDisplayResponse($node, 200, [
				'Content-Type' => $node->getMimeType(),
				'Content-Disposition' => ($download ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($filename),
				'Cache-Control' => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\Files\NotFoundException) {
			return new DataResponse(['message' => 'Signed document not found'], 404);
		}
	}
}
