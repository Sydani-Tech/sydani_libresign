<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\Service;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Service\File\Pdf\PdfParser;
use OCP\Files\File as FileNode;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Sabre\DAV\UUIDUtil;

final class ExternalArchiveService {
	public function __construct(
		private FileService $files,
		private PdfParser $parser,
		private FileMapper $mapper,
		private DocumentArchiveService $archive,
		private PadesVerificationService $verification,
		private IDBConnection $db,
		private ILockingProvider $locks,
	) {
	}

	public function import(array $upload, ?array $audit, string $actor, array $metadata): File {
		$pdf = $this->readPdf($upload);
		$auditPdf = $audit === null ? null : $this->readPdf($audit);
		$senderEmail = trim($metadata['senderEmail']);
		if ($senderEmail !== '' && !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
			throw new \InvalidArgumentException('Enter a valid sender email address.');
		}
		$completed = trim($metadata['completedDate']);
		$date = $completed === '' ? null : \DateTime::createFromFormat('!Y-m-d', $completed, new \DateTimeZone('UTC'));
		if ($completed !== '' && (!$date || $date->format('Y-m-d') !== $completed)) {
			throw new \InvalidArgumentException('Enter a valid completion date.');
		}
		$name = trim(pathinfo(basename($upload['name']), PATHINFO_FILENAME));
		if ($name === '' || strlen($name) > 200) {
			throw new \InvalidArgumentException('The PDF needs a file name of up to 200 bytes.');
		}
		$hash = hash('sha256', $pdf);
		$lock = 'libresign/external-archive/' . $hash;
		$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		$node = $auditNode = null;
		try {
			$qb = $this->db->getQueryBuilder();
			$existing = $qb->select('id')->from('libresign_file')
				->where($qb->expr()->eq('signed_hash', $qb->createNamedParameter($hash)))
				->andWhere($qb->expr()->isNotNull('signed_node_id'))
				->setMaxResults(1)->executeQuery()->fetchOne();
			if ($existing !== false) {
				throw new \InvalidArgumentException('This exact PDF is already in Signed Archive.');
			}
			$file = new File();
			$file->setUuid(UUIDUtil::getUUID());
			$file->setName($name);
			$file->setUserId($actor);
			$file->setCreatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
			$file->setCompletedAt($date);
			$file->setStatusEnum(FileStatus::SIGNED);
			$file->setExternallySigned(1);
			$node = $this->archive->createWorkingCopy($file, 'pdf', $pdf);
			if (!hash_equals($hash, hash('sha256', $node->getContent()))) {
				throw new \RuntimeException('Archive copy did not match the uploaded PDF.');
			}
			if ($auditPdf !== null) {
				$auditNode = $node->getParent()->newFile('external-audit.pdf', $auditPdf);
			}
			$file->setSignedNodeId($node->getId());
			$file->setSignedHash($hash);
			$file->setMetadata(['external_archive' => [
				'senderName' => trim($metadata['senderName']),
				'senderEmail' => $senderEmail,
				'provider' => trim($metadata['provider']),
				'reference' => trim($metadata['reference']),
				'importedAt' => gmdate(\DateTimeInterface::ATOM),
				'completedDate' => $completed,
				'auditNodeId' => $auditNode?->getId(),
				'auditHash' => $auditPdf !== null ? hash('sha256', $auditPdf) : null,
			]]);
			$this->archive->markArchived($file, $node);
			return $this->mapper->insert($file);
		} catch (\Throwable $e) {
			$auditNode?->delete();
			$node?->delete();
			throw $e;
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function inspect(array $upload): array {
		return $this->verification->userReport($this->readPdf($upload));
	}

	public function verify(File $file): array {
		$pdf = $this->archive->resolve($file)->getContent();
		return [
			'fileMatch' => hash_equals($file->getSignedHash() ?? '', hash('sha256', $pdf)),
			'longTermValidation' => $this->verification->userReport($pdf),
		];
	}

	public function audit(File $file): FileNode {
		$id = (int)(($file->getMetadata() ?? [])['external_archive']['auditNodeId'] ?? 0);
		$audit = $id ? $this->archive->resolve($file)->getParent()->getFirstNodeById($id) : null;
		if (!$audit instanceof FileNode) {
			throw new NotFoundException('Audit report not found');
		}
		return $audit;
	}

	private function readPdf(array $upload): string {
		$this->files->validateUploadedFile($upload);
		$pdf = file_get_contents($upload['tmp_name']);
		if ($pdf === false || !str_starts_with($pdf, '%PDF-') || (new \finfo(FILEINFO_MIME_TYPE))->buffer($pdf) !== 'application/pdf') {
			throw new \InvalidArgumentException('Choose a valid PDF.');
		}
		$this->parser->parse($pdf, $upload['name']);
		return $pdf;
	}
}
