<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Migration;

use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Service\DocumentArchiveService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/** Restartable upgrade step; never invent legacy Date Requested timestamps. */
class ArchiveSignedDocuments implements IRepairStep {
	public function __construct(
		private IDBConnection $db,
		private FileMapper $fileMapper,
		private SignRequestMapper $signRequestMapper,
		private DocumentArchiveService $documentArchive,
		private LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'Preserve signed documents in the organisational archive';
	}

	public function run(IOutput $output): void {
		$lastId = 0;
		$migrated = 0;
		$failed = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('libresign_file')
				->where($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->isNotNull('signed_node_id'))
				->andWhere($qb->expr()->isNotNull('signed_hash'))
				->orderBy('id', 'ASC')->setMaxResults(100);
			$rows = $qb->executeQuery()->fetchAll();
			foreach ($rows as $row) {
				$lastId = (int)$row['id'];
				try {
					$file = $this->fileMapper->getById($lastId);
					if ($file->getStatus() === FileStatus::SIGNED->value && !$file->getCompletedAt()) {
						$latest = null;
						foreach ($this->signRequestMapper->getByFileId($lastId) as $signer) {
							if ($signer->getSigned() && (!$latest || $signer->getSigned() > $latest)) {
								$latest = $signer->getSigned();
							}
						}
						if ($latest) {
							$file->setCompletedAt(clone $latest);
							$this->fileMapper->update($file);
						}
					}
					if ($this->documentArchive->archiveExisting($file)) {
						$migrated++;
					}
				} catch (\Throwable $e) {
					$failed++;
					$this->logger->error('Signed document archive migration failed', ['fileId' => $lastId, 'exception' => $e]);
					$output->warning('Archive migration requires attention for file ' . $lastId . ': ' . $e->getMessage());
				}
			}
		} while (count($rows) === 100);
		$output->info(sprintf('Archived %d signed documents; %d require attention. Original sources were not removed. Legacy request dates remain unknown unless already recorded.', $migrated, $failed));
		if ($failed) {
			throw new \RuntimeException('Archive migration incomplete; inspect the reported files before completing the upgrade.');
		}
	}
}
