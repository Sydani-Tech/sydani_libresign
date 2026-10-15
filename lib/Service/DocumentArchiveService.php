<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;

/**
 * The archive is app-owned, never mounted in a user's Files/WebDAV tree.
 * File.userId remains the sender, not the owner of the archived storage.
 * Callers must authorize access before resolving or returning file contents.
 */
class DocumentArchiveService {

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IRootFolder $root,
		private FileMapper $fileMapper,
		private SignRequestMapper $signRequestMapper,
		private IDBConnection $db,
	) {
	}

	private function appFolder(): Folder {
		$simpleFolder = $this->appDataFactory->get('libresign')->getFolder('/');
		// Same bridge used by FolderService for guest documents. Signing engines
		// need OCP\Files\File streams rather than the SimpleFS wrapper.
		$property = (new \ReflectionClass($simpleFolder))->getProperty('folder');
		return $property->getValue($simpleFolder);
	}

	private function archiveFolder(bool $create = false): ?Folder {
		try {
			$folder = $this->appFolder()->get('organisational-archive');
			return $folder instanceof Folder ? $folder : null;
		} catch (NotFoundException) {
			return $create ? $this->appFolder()->newFolder('organisational-archive') : null;
		}
	}

	public function findByNodeId(int $nodeId): ?File {
		$node = $this->archiveFolder()?->getFirstNodeById($nodeId);
		return $node instanceof File ? $node : null;
	}

	public function resolve(FileEntity $file, bool $preferSigned = true): File {
		$nodeId = $preferSigned && $file->getSignedNodeId()
			? $file->getSignedNodeId() : $file->getNodeId();
		if (!$nodeId) {
			throw new NotFoundException('Document file not found');
		}
		$archived = $this->findByNodeId($nodeId);
		if ($archived) {
			return $archived;
		}
		if ($file->getUserId() !== '') {
			$node = $this->root->getUserFolder($file->getUserId())->getFirstNodeById($nodeId);
			if ($node instanceof File) {
				return $node;
			}
		}
		$node = $this->appFolder()->getFirstNodeById($nodeId);
		if (!$node instanceof File) {
			throw new NotFoundException('Document file not found');
		}
		return $node;
	}

	public function createWorkingCopy(FileEntity $file, string $extension, string $content): File {
		if (!preg_match('/^[a-f0-9-]{36}$/i', $file->getUuid()) || !preg_match('/^[a-z0-9]+$/i', $extension)) {
			throw new \InvalidArgumentException('Invalid archive document identifier');
		}
		$root = $this->archiveFolder(true);
		try {
			$folder = $root->get($file->getUuid());
		} catch (NotFoundException) {
			$folder = $root->newFolder($file->getUuid());
		}
		if (!$folder instanceof Folder) {
			throw new NotPermittedException('Invalid archive folder');
		}
		// A failed signing attempt must never overwrite a previous signed copy.
		return $folder->newFile('signed-' . bin2hex(random_bytes(12)) . '.' . strtolower($extension), $content);
	}

	public function markArchived(FileEntity $file, File $node): void {
		$metadata = $file->getMetadata() ?? [];
		$metadata['organisational_archive'] = [
			'nodeId' => $node->getId(),
			'archivedAt' => $metadata['organisational_archive']['archivedAt'] ?? gmdate(\DateTimeInterface::ATOM),
			'version' => 1,
		];
		$file->setMetadata($metadata);
	}

	public function hasSignatures(FileEntity $file): bool {
		if ($file->getSignedHash() || in_array($file->getStatus(), [2, 3], true)) {
			return true;
		}
		foreach ($this->signRequestMapper->getByFileId($file->getId()) as $request) {
			if ($request->getSigned()) {
				return true;
			}
		}
		if ($file->isEnvelope()) {
			foreach ($this->fileMapper->getChildrenFiles($file->getId()) as $child) {
				if ($this->hasSignatures($child)) {
					return true;
				}
			}
		}
		return false;
	}

	public function assertCanDelete(FileEntity $file): void {
		if ($this->hasSignatures($file)) {
			throw new NotPermittedException('Signed documents and their signing history cannot be deleted. The original source file can still be deleted from Nextcloud Files.');
		}
	}

	public function isProtectedSignedNode(int $nodeId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id')->from('libresign_file', 'f')
			->where($qb->expr()->eq('f.signed_node_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)));
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			if ($this->hasSignatures($this->fileMapper->getById((int)$row['id']))) {
				return true;
			}
		}
		return false;
	}

	/** Keep signed records when a user removes an independent source file. */
	public function detachDeletedSource(int $nodeId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('libresign_file')
			->where($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)));
		$preserved = false;
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$file = $this->fileMapper->getById((int)$row['id']);
			if (!$this->hasSignatures($file)) {
				continue;
			}
			// Never let removing the source destroy the only signed copy.
			if (!$file->isEnvelope()) {
				$this->archiveExisting($file);
			}
			$metadata = $file->getMetadata() ?? [];
			$metadata['original_file_deleted'] = true;
			$metadata['original_file_deleted_at'] = gmdate(\DateTimeInterface::ATOM);
			$file->setNodeId(null);
			$file->setMetadata($metadata);
			$this->fileMapper->update($file);
			$preserved = true;
		}
		return $preserved;
	}

	/** Byte-preserving, restartable migration; the source is never removed. */
	public function archiveExisting(FileEntity $file): bool {
		if (!$file->getSignedNodeId() || !$this->hasSignatures($file) || $file->isEnvelope()) {
			return false;
		}
		if ($this->findByNodeId($file->getSignedNodeId())) {
			return false;
		}
		$old = $this->resolve($file);
		$content = $old->getContent();
		$hash = hash('sha256', $content);
		if (!$file->getSignedHash() || !hash_equals($file->getSignedHash(), $hash)) {
			throw new \RuntimeException('Archive migration refused: signed PDF does not match its recorded hash (file ' . $file->getId() . ')');
		}
		$archived = $this->createWorkingCopy($file, $old->getExtension(), $content);
		if (!hash_equals($hash, hash('sha256', $archived->getContent()))) {
			throw new \RuntimeException('Archive copy failed integrity verification');
		}
		$file->setSignedNodeId($archived->getId());
		$this->markArchived($file, $archived);
		$this->fileMapper->update($file);
		// The registered master now points to appdata. Remove only the old signed
		// duplicate, never the user's source. A deployment backup precedes migration.
		if ($old->getId() !== $file->getNodeId()) {
			$old->delete();
		}
		return true;
	}
}
