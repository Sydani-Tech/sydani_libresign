<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;

use OCP\ITempManager;

/** Bounded, value-only import. Never evaluates formulas or follows workbook links. */
class CampaignRecipientImport {
	public const MAX_BYTES = 5 * 1024 * 1024;
	private const MAX_XML_BYTES = 20 * 1024 * 1024;
	private const MAX_COLUMNS = 30;
	public function __construct(private ITempManager $temp) {}

	public function parse(string $base64, string $filename, string $sheet = ''): array {
		if (strlen($base64) > (int)ceil(self::MAX_BYTES * 4 / 3) + 8) throw new \InvalidArgumentException('Choose an Excel or CSV file no larger than 5 MB.');
		$content = base64_decode($base64, true);
		if ($content === false || $content === '' || strlen($content) > self::MAX_BYTES) throw new \InvalidArgumentException('The import file is empty, invalid or larger than 5 MB.');
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if ($extension === 'csv') return $this->csv($content);
		if ($extension !== 'xlsx') throw new \InvalidArgumentException('Choose an .xlsx or .csv file. Older .xls files must be saved as .xlsx first.');
		$path = $this->temp->getTemporaryFile('.xlsx');
		$zip = new \ZipArchive(); $opened = false;
		try {
			file_put_contents($path, $content);
			if ($zip->open($path, \ZipArchive::RDONLY) !== true) throw new \InvalidArgumentException('Unable to read this Excel file. It may be damaged or password-protected.');
			$opened = true;
			$total = 0;
			if ($zip->numFiles > 1000) throw new \InvalidArgumentException('This workbook is too complex. Export the recipient sheet as CSV.');
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				$total += $stat['size'];
				if ($total > self::MAX_XML_BYTES || !empty($stat['encryption_method'])) throw new \InvalidArgumentException('This workbook is too large when expanded or is encrypted. Export the recipient sheet as CSV.');
			}
			$workbook = $this->xml($this->entry($zip, 'xl/workbook.xml'));
			$rels = $this->xml($this->entry($zip, 'xl/_rels/workbook.xml.rels'));
			$targets = [];
			foreach ($rels->getElementsByTagName('Relationship') as $rel) {
				if ($rel->getAttribute('TargetMode') === 'External') continue;
				$target = $rel->getAttribute('Target');
				if (str_starts_with($target, '/xl/')) $target = substr($target, 1);
				elseif (!str_starts_with($target, 'xl/')) $target = 'xl/' . $target;
				if (preg_match('#^xl/worksheets/[A-Za-z0-9_.-]+\.xml$#D', $target)) $targets[$rel->getAttribute('Id')] = $target;
			}
			$sheets = [];
			foreach ($workbook->getElementsByTagName('sheet') as $item) {
				$rid = $item->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
				if (isset($targets[$rid])) $sheets[$item->getAttribute('name')] = $targets[$rid];
			}
			if (!$sheets) throw new \InvalidArgumentException('No readable worksheets found.');
			$selected = $sheet !== '' ? $sheet : array_key_first($sheets);
			if (!isset($sheets[$selected])) throw new \InvalidArgumentException('Choose a worksheet from this file.');
			$strings = [];
			if ($zip->locateName('xl/sharedStrings.xml') !== false) {
				$shared = $this->xml($this->entry($zip, 'xl/sharedStrings.xml'));
				foreach ($shared->getElementsByTagName('si') as $si) {
					$value = ''; foreach ($si->getElementsByTagName('t') as $text) $value .= $text->textContent;
					$strings[] = $this->cell($value);
				}
			}
			$xml = $this->xml($this->entry($zip, $sheets[$selected]));
			$rows = [];
			foreach ($xml->getElementsByTagName('row') as $row) {
				$values = []; $fallback = 0;
				foreach ($row->getElementsByTagName('c') as $cell) {
					$reference = $cell->getAttribute('r'); $column = $fallback;
					if (preg_match('/^([A-Z]+)[1-9][0-9]*$/D', $reference, $match)) { $column = 0; foreach (str_split($match[1]) as $letter) $column = $column * 26 + ord($letter) - 64; $column--; }
					$fallback = $column + 1;
					$type = $cell->getAttribute('t');
					$value = $cell->getElementsByTagName('v')->item(0)?->textContent ?? '';
					if ($type === 's') $value = $strings[(int)$value] ?? '';
					elseif ($type === 'inlineStr') { $value = ''; foreach ($cell->getElementsByTagName('t') as $text) $value .= $text->textContent; }
					// Formula results may be stale. Require plain values instead.
					if ($cell->getElementsByTagName('f')->length) $value = '[Formula: paste values first]';
					if ($column >= self::MAX_COLUMNS) { if (trim($value) !== '') throw new \InvalidArgumentException('Use a recipient sheet with no more than 30 columns.'); continue; }
					$values[$column] = $this->cell($value);
				}
				if (!array_filter($values, static fn ($v) => $v !== '')) continue;
				$width = $values ? max(array_keys($values)) + 1 : 0;
				$rows[] = ['row' => (int)($row->getAttribute('r') ?: count($rows) + 1), 'cells' => array_replace(array_fill(0, $width, ''), $values)];
				$this->rowLimit($rows);
			}
			return $this->result($rows, array_keys($sheets), $selected);
		} finally { if ($opened) $zip->close(); if (is_file($path)) unlink($path); }
	}

	private function csv(string $content): array {
		if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16');
		$content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
		if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) throw new \InvalidArgumentException('Save the CSV with UTF-8 encoding.');
		$first = strtok($content, "\r\n") ?: '';
		$delimiter = ','; $best = 0;
		foreach ([',', ';', "\t"] as $candidate) { $count = count(str_getcsv($first, $candidate, '"', '')); if ($count > $best) { $best = $count; $delimiter = $candidate; } }
		$stream = fopen('php://temp', 'w+'); fwrite($stream, $content); rewind($stream); $rows = []; $index = 0;
		try {
			while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
				$index++; $values = array_map(fn ($value) => $this->cell((string)$value), $values);
				if (!array_filter($values, static fn ($v) => $v !== '')) continue;
				if (count($values) > self::MAX_COLUMNS) throw new \InvalidArgumentException('Use a recipient file with no more than 30 columns.');
				$rows[] = ['row' => $index, 'cells' => $values]; $this->rowLimit($rows);
			}
		} finally { fclose($stream); }
		return $this->result($rows, [], '');
	}
	private function entry(\ZipArchive $zip, string $name): string {
		$content = $zip->getFromName($name, self::MAX_XML_BYTES + 1);
		if ($content === false || strlen($content) > self::MAX_XML_BYTES) throw new \InvalidArgumentException('The Excel file is incomplete or too large.');
		return $content;
	}
	private function xml(string $content): \DOMDocument {
		if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) throw new \InvalidArgumentException('Unsupported XML declarations in workbook.');
		$previous = libxml_use_internal_errors(true);
		try { $xml = new \DOMDocument(); if (!$xml->loadXML($content, LIBXML_NONET | LIBXML_COMPACT)) throw new \InvalidArgumentException('The Excel file contains invalid XML.'); return $xml; }
		finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
	}
	private function cell(string $value): string {
		$value = trim($value); if (!mb_check_encoding($value, 'UTF-8') || strlen($value) > 1000) throw new \InvalidArgumentException('A cell is invalid or longer than 1000 bytes. Use a simple name and email list.'); return $value;
	}
	private function rowLimit(array $rows): void { if (count($rows) > CampaignDefinition::MAX_RECIPIENTS + 1) throw new \InvalidArgumentException('Import up to 2000 recipients at a time, plus an optional header row.'); }
	private function result(array $rows, array $sheets, string $sheet): array {
		if (!$rows) throw new \InvalidArgumentException('This sheet contains no recipient rows.');
		return ['rows' => $rows, 'sheets' => $sheets, 'sheet' => $sheet, 'maxRecipients' => CampaignDefinition::MAX_RECIPIENTS];
	}
}
