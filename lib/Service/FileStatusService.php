<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use DateTime;
use DateTimeInterface;
use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Service\SignRequest\StatusCacheService;
use OCP\AppFramework\Db\DoesNotExistException;

class FileStatusService {
	public function __construct(
		private FileMapper $fileMapper,
		private StatusCacheService $statusCacheService,
		private \OCA\Libresign\Db\SignRequestMapper $signRequestMapper,
	) {
	}

	public function updateFileStatusIfUpgrade(FileEntity $file, int $newStatus): FileEntity {
		$currentStatus = $file->getStatus();
		if ($newStatus > $currentStatus) {
			$file->setStatus($newStatus);
			$this->touchStatusChangedAt($file);
			$this->fileMapper->update($file);
			$this->statusCacheService->setStatus($file->getUuid(), $newStatus);

			if ($file->hasParent()) {
				$this->propagateStatusToParent($file->getParentFileId());
			}
		}

		return $file;
	}

	public function canNotifySigners(?int $fileStatus): bool {
		return $fileStatus === FileStatus::ABLE_TO_SIGN->value;
	}

	public function propagateStatusToParent(int $parentId): void {
		try {
			$parent = $this->fileMapper->getById($parentId);
		} catch (DoesNotExistException) {
			return;
		}

		if (!$parent->isEnvelope()) {
			return;
		}

		$children = $this->fileMapper->getChildrenFiles($parentId);

		if (empty($children)) {
			return;
		}

		$minStatus = FileStatus::SIGNED->value;
		$maxStatus = FileStatus::DRAFT->value;

		foreach ($children as $child) {
			$status = $child->getStatus();
			if ($status < $minStatus) {
				$minStatus = $status;
			}
			if ($status > $maxStatus) {
				$maxStatus = $status;
			}
		}

		$newStatus = FileStatus::DRAFT->value;

		if ($minStatus === FileStatus::SIGNED->value && $maxStatus === FileStatus::SIGNED->value) {
			$newStatus = FileStatus::SIGNED->value;
		} elseif ($maxStatus >= FileStatus::PARTIAL_SIGNED->value) {
			$newStatus = FileStatus::PARTIAL_SIGNED->value;
		} elseif ($maxStatus >= FileStatus::ABLE_TO_SIGN->value) {
			$newStatus = FileStatus::ABLE_TO_SIGN->value;
		}

		if ($parent->getStatus() !== $newStatus) {
			$parent->setStatus($newStatus);
			$this->touchStatusChangedAt($parent);
			$this->fileMapper->update($parent);
			$this->statusCacheService->setStatus($parent->getUuid(), $newStatus);
		}
	}

	public function update(FileEntity $file): FileEntity {
		$this->touchStatusChangedAt($file);
		$this->fileMapper->update($file);
		$this->statusCacheService->setStatus($file->getUuid(), $file->getStatus());

		if ($file->hasParent()) {
			$this->propagateStatusToParent($file->getParentFileId());
		}

		return $file;
	}

	public function propagateStatusToChildren(int $envelopeId, int $newStatus): void {
		try {
			$envelope = $this->fileMapper->getById($envelopeId);
		} catch (DoesNotExistException) {
			return;
		}

		if (!$envelope->isEnvelope()) {
			return;
		}

		$children = $this->fileMapper->getChildrenFiles($envelopeId);

		foreach ($children as $child) {
			if ($child->getStatus() !== $newStatus) {
				$child->setStatus($newStatus);
				$this->touchStatusChangedAt($child);
				$this->fileMapper->update($child);
				$this->statusCacheService->setStatus($child->getUuid(), $newStatus);
			}
		}
	}

	private function touchStatusChangedAt(FileEntity $file): void {
		if ($file->getSignedHash() && !in_array($file->getStatus(), [FileStatus::PARTIAL_SIGNED->value, FileStatus::SIGNED->value], true)) {
			throw new \OCP\Files\NotPermittedException('Signed document records cannot be reset or deleted.');
		}
		if ($file->getStatus() === FileStatus::ABLE_TO_SIGN->value && !$file->getRequestedAt()) {
			$file->setRequestedAt(new DateTime('now', new \DateTimeZone('UTC')));
		}
		if ($file->getStatus() === FileStatus::SIGNED->value && !$file->getCompletedAt()) {
			$latest = null;
			foreach ($this->signRequestMapper->getByFileId($file->getId()) as $request) {
				if ($request->getSigned() && (!$latest || $request->getSigned() > $latest)) {
					$latest = $request->getSigned();
				}
			}
			$file->setCompletedAt($latest ? clone $latest : new DateTime('now', new \DateTimeZone('UTC')));
		}
		$metadata = $file->getMetadata() ?? [];
		$metadata['status_changed_at'] = (new DateTime())->format(DateTimeInterface::ATOM);
		$file->setMetadata($metadata);
	}
}
