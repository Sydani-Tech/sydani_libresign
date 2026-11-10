<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;

use OCA\Libresign\Exception\LibresignException;

/** Decide whether an already-signed revision can receive a new signature. */
final class PdfCountersigningPolicy {
 public static function isLt(array $signature): bool {
  return ($signature['indication'] ?? '') === 'TOTAL_PASSED'
   && in_array($signature['format'] ?? '', ['PAdES_BASELINE_LT', 'PAdES_BASELINE_LTA'], true)
   && ($signature['timestampVerified'] ?? false) === true;
 }

 public static function existing(array $report): array {
  if (empty($report['checked']) || empty($report['signatures'])) {
   throw new LibresignException('LibreSign could not check the existing PDF signatures. No signed document was saved.');
  }
  $existing = [];
  foreach ($report['signatures'] as $signature) {
   $id = $signature['id'] ?? '';
   if (!preg_match('/^S-[A-F0-9]+$/D', $id) || isset($existing[$id])) {
    throw new LibresignException('LibreSign could not identify every existing signature. No signed document was saved.');
   }
   $existing[$id] = $signature;
  }
  return $existing;
 }

 /** Apply the locks in every signed revision, including locks on existing fields. */
 public static function permitsExistingField(array $existing, string $name): bool {
  foreach ($existing as $signature) {
   if (($signature['fieldLocked'] ?? null) === false) continue;
   if (empty($signature['fieldLocks'])) return false;
   foreach ($signature['fieldLocks'] as $lock) {
    if (!in_array($lock['permission'], ['', 'MINIMAL_CHANGES_PERMITTED', 'CHANGES_PERMITTED'], true)) return false;
    $action = strtoupper($lock['action']);
    if (!in_array($action, ['INCLUDE', 'EXCLUDE'], true) || !$lock['fields']) return false;
    $listed = false;
    foreach ($lock['fields'] as $field) {
     if ($name === $field || str_starts_with($name, $field . '.')) {
      $listed = true;
      break;
     }
    }
    if (($action === 'INCLUDE' && $listed) || ($action === 'EXCLUDE' && !$listed)) return false;
   }
  }
  return true;
 }

 public static function preflight(array $existing, array $fields, ?string $checkedField = null): void {
  if ($checkedField !== null && !self::permitsExistingField($existing, $checkedField)) {
   throw new LibresignException('A previous signature locks the selected PDF field.');
  }
  foreach ($existing as $signature) {
   if ((($signature['fieldLocked'] ?? null) !== false && $checkedField === null) || !in_array($signature['docMdp'] ?? null, ['', 'MINIMAL_CHANGES_PERMITTED', 'CHANGES_PERMITTED'], true)) {
    throw new LibresignException('The PDF has signature permissions or a field lock that LibreSign cannot safely countersign. Ask the sender for a copy that allows additional signatures.');
   }
   self::assertPreserved($signature);
  }
  if ($fields) {
   throw new LibresignException('LibreSign can add a signature to this externally signed PDF, but cannot add text, date or checkbox fields to it. Remove those fields, or have all parties sign a new copy that includes them.');
  }
 }

 public static function appended(string $source, string $candidate, array $existing, array $report, string $certificateId, bool $requireLt, ?string $checkedField = null): array {
  if (strlen($candidate) <= strlen($source) || !str_starts_with($candidate, $source)) {
   throw new LibresignException('The previous PDF revision was not preserved. No signed document was saved.');
  }
  $all = self::existing($report);
  foreach ($existing as $id => $prior) {
   if (!isset($all[$id])) throw new LibresignException('A previous signature is missing. No signed document was saved.');
   self::assertPreserved($all[$id]);
   if (self::isLt($prior) && !self::isLt($all[$id])) {
    throw new LibresignException('The new revision would invalidate a previous long-term signature. No signed document was saved.');
   }
  }
  $new = array_diff_key($all, $existing);
  if (!$new) throw new LibresignException('No new signature could be verified.');
  if ($checkedField !== null && (count($new) !== 1 || !in_array($checkedField, reset($new)['signatureFields'] ?? [], true))) {
   throw new LibresignException('The new signature did not use the selected PDF field. No signed document was saved.');
  }
  foreach ($new as $signature) {
   self::assertPreserved($signature);
   if (($signature['certificateId'] ?? '') !== $certificateId
    || ($signature['issuerTrusted'] ?? false) !== true
    || ($requireLt ? !self::isLt($signature) : (($signature['timestampAnchored'] ?? false) !== true || ($signature['format'] ?? '') !== 'PAdES_BASELINE_T'))) {
    throw new LibresignException('The new signature lacks verified long-term signing evidence. No signed document was saved.');
   }
  }
  return array_keys($new);
 }

 private static function assertPreserved(array $signature): void {
  $revision = $signature['revision'] ?? [];
  $indication = $signature['indication'] ?? '';
  if (($signature['cryptographicIntegrity'] ?? false) !== true
   || (($revision['checked'] ?? false) !== true && ($signature['coversFile'] ?? false) !== true)
   || ($revision['byteRangeValid'] ?? false) !== true
   || ($revision['dictionaryConsistent'] ?? false) !== true
   || !($indication === 'TOTAL_PASSED' || ($indication === 'INDETERMINATE' && in_array($signature['subIndication'] ?? '', ['NO_CERTIFICATE_CHAIN_FOUND', 'CERTIFICATE_CHAIN_GENERAL_FAILURE', 'OUT_OF_BOUNDS_NO_POE', 'TRY_LATER', 'REVOCATION_OUT_OF_BOUNDS_NO_POE'], true)))) {
   throw new LibresignException('A PDF signature or its previous revision could not be verified safely. No signed document was saved.');
  }
  foreach (['annotationChanges', 'undefinedChanges', 'visualDifferences', 'pageDifferences', 'annotationOverlaps'] as $key) {
   if (($revision[$key] ?? null) !== 0) {
    throw new LibresignException('The PDF contains changes beyond permitted signature revisions. No signed document was saved.');
   }
  }
 }
}
