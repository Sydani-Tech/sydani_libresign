<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;
use OCP\AppFramework\Http;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\ArchiveQueryService;
use OCA\Libresign\Service\DocumentArchiveService;
use OCA\Libresign\Service\ExternalArchiveService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/** All archive routes require a signed-in user. */
class ArchiveController extends Controller {
	public function __construct(
		IRequest $request,
		private ArchiveQueryService $archiveQuery,
		private DocumentArchiveService $documentArchive,
		private ExternalArchiveService $externalArchive,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function importerScope(IUser $user): ?string {
		$uid = $user->getUID();
		return $this->groupManager->isInGroup($uid, 'admin') ? null : $uid;
	}

	/**
	 * List protected signed documents visible to the current user.
	 *
	 * @param int $page One-based page number
	 * @param int $length Maximum records per page
	 * @param string $name Document name search
	 * @param string $sender Exact sender account UID
	 * @param string $recipient Recipient name, email and optional account identifier
	 * @param string $status Document status filter
	 * @param string $dateBy Date Requested or Date Completed selector
	 * @param int|null $from Inclusive start timestamp
	 * @param int|null $to Inclusive end timestamp
	 * @param string $sortBy Sort field
	 * @param string $direction Sort direction
	 * @param string $recipientName Recipient name search
	 * @param string $recipientEmail Recipient email search matched to the same signer
	 * @param string $letterhead Letterheaded document filter
	 * @param string $signingOrigin Internal or external signing origin filter
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/archive/list')]
	public function index(int $page = 1, int $length = 25, string $name = '', string $sender = '', string $recipient = '', string $status = 'completed', string $dateBy = 'completed', ?int $from = null, ?int $to = null, string $sortBy = 'completed', string $direction = 'desc', string $recipientName = '', string $recipientEmail = '', string $letterhead = '', string $signingOrigin = ''): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['message' => 'Sign in to use Signed Archive'], 401);
		}
		if (($from !== null && $to !== null && $from > $to) || strlen($name) > 200 || strlen($recipient) > 200 || strlen($recipientName) > 200 || strlen($recipientEmail) > 200) {
			return new DataResponse(['message' => 'Invalid archive filter'], 400);
		}
		return new DataResponse($this->archiveQuery->list(compact('signingOrigin', 'letterhead', 'name', 'sender', 'recipient', 'recipientName', 'recipientEmail', 'status', 'dateBy', 'from', 'to', 'sortBy', 'direction'), $page, $length, $this->importerScope($user)), 200, ['Cache-Control' => 'private, no-store']);
	}

	/**
	 * Inspect cryptographic signatures in an uploaded PDF without archiving it.
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/archive/inspect')]
	public function inspect(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['message' => 'Sign in to use Signed Archive'], 401);
		}
		$pdf = $this->request->getUploadedFile('pdf');
		if (!$pdf) {
			return new DataResponse(['message' => 'Choose a completed PDF.'], 422);
		}
		try {
			return new DataResponse($this->externalArchive->inspect($pdf), 200, ['Cache-Control' => 'private, no-store']);
		} catch (\InvalidArgumentException|\OCA\Libresign\Exception\LibresignException $e) {
			return new DataResponse(['message' => $e->getMessage()], 422);
		}
	}

	/**
	 * Archive a completed externally signed PDF and optional audit report.
	 *
	 * @param string $confirmed Set to yes to confirm the PDF was completed externally
	 * @param string $senderName Reported sender name
	 * @param string $senderEmail Reported sender email
	 * @param string $provider Reported signing provider
	 * @param string $reference Provider envelope or agreement reference
	 * @param string $completedDate Reported completion date in YYYY-MM-DD
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/archive/import')]
	public function import(string $confirmed = '', string $senderName = '', string $senderEmail = '', string $provider = '', string $reference = '', string $completedDate = ''): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['message' => 'Sign in to use Signed Archive'], 401);
		}
		$pdf = $this->request->getUploadedFile('pdf');
		if (!$pdf) {
			return new DataResponse(['message' => 'Choose a completed PDF.'], 422);
		}
		if ($confirmed !== 'yes') {
			return new DataResponse(['message' => 'Confirm that the PDF was signed externally.'], 422);
		}
		$metadata = compact('senderName', 'senderEmail', 'provider', 'reference', 'completedDate');
		foreach ($metadata as $value) {
			if (strlen($value) > 250) {
				return new DataResponse(['message' => 'Archive details must be at most 250 bytes per field.'], 422);
			}
		}
		try {
			$file = $this->externalArchive->import($pdf, $this->request->getUploadedFile('audit') ?: null, $user->getUID(), $metadata);
			return new DataResponse($this->archiveQuery->describe($file), 201, ['Cache-Control' => 'private, no-store']);
		} catch (\InvalidArgumentException|\OCA\Libresign\Exception\LibresignException $e) {
			return new DataResponse(['message' => $e->getMessage()], 422);
		} catch (\OCP\Lock\LockedException) {
			return new DataResponse(['message' => 'This PDF is already being imported. Try again shortly.'], 409);
		}
	}

	/**
	 * Verify an imported PDF without changing its bytes.
	 *
	 * @param int $fileId LibreSign document identifier
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/archive/verify/{fileId}')]
	public function verifyExternal(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['message' => 'Sign in to use Signed Archive'], 401);
		}
		try {
			$file = $this->archiveQuery->getProtectedFile($fileId, $this->importerScope($user));
			if ($file->getExternallySigned()) {
				return new DataResponse($this->externalArchive->verify($file), 200, ['Cache-Control' => 'private, no-store']);
			}
		} catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\Files\NotFoundException) {
		}
		return new DataResponse(['message' => 'Imported PDF not found'], 404);
	}

	/**
	 * Download the audit report attached to an external import.
	 *
	 * @param int $fileId LibreSign document identifier
	 * @return FileDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/archive/audit/{fileId}')]
	public function audit(int $fileId): FileDisplayResponse|DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['message' => 'Sign in to use Signed Archive'], 401);
		}
		try {
			$file = $this->archiveQuery->getProtectedFile($fileId, $this->importerScope($user));
			if ($file->getExternallySigned()) {
				return new FileDisplayResponse($this->externalArchive->audit($file), 200, [
					'Content-Type' => 'application/pdf',
					'Content-Disposition' => 'attachment; filename="external-audit.pdf"',
					'Cache-Control' => 'private, no-store',
					'X-Content-Type-Options' => 'nosniff',
				]);
			}
		} catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\Files\NotFoundException) {
		}
		return new DataResponse(['message' => 'Audit report not found'], 404);
	}

	/**
	 * Read the protected signing history for a document.
	 *
	 * @param int $fileId LibreSign document identifier
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/archive/history/{fileId}')]
	public function history(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['message' => 'Sign in to use Signed Archive'], 401);
		}
		try {
			return new DataResponse($this->archiveQuery->describe($this->archiveQuery->getProtectedFile($fileId, $this->importerScope($user))), 200, ['Cache-Control' => 'private, no-store']);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return new DataResponse(['message' => 'Signed document not found'], 404);
		}
	}

	/**
	 * Retrieve a protected signed document.
	 *
	 * @param int $fileId LibreSign document identifier
	 * @param bool $download Return an attachment instead of an inline PDF
	 * @return FileDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
	 *
	 * 200: Operation completed
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/archive/file/{fileId}')]
	public function file(int $fileId, bool $download = false): FileDisplayResponse|DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['message' => 'Sign in to use Signed Archive'], 401);
		}
		try {
			$file = $this->archiveQuery->getProtectedFile($fileId, $this->importerScope($user));
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
