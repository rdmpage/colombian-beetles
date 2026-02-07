<?php

/**
 * check_oa.php
 *
 * Checks whether DOI-based identifiers are open access using the
 * Unpaywall API (https://api.oadoi.org/v2/).
 *
 * Collects all unique DOIs from reference identifiers across all
 * datasets, queries Unpaywall for each, and reports OA status.
 *
 * Usage:  php check_oa.php
 * Output: check_oa_results.tsv     (per-DOI results)
 *         check_oa_summary.tsv     (summary by OA status)
 */

require_once __DIR__ . '/functions.php';

$base_dir = __DIR__;
$dataset_dirs = get_dataset_dirs($base_dir);

// Collect all unique DOIs globally, tracking which datasets they appear in
$global_dois = array();  // doi (without prefix) => array of dataset names

foreach ($dataset_dirs as $dataset_dir) {
    $dataset_name = basename($dataset_dir);

    $column_index = get_identifier_column($dataset_dir);
    if ($column_index === false) {
        continue;
    }

    $identifiers = get_unique_identifiers($dataset_dir, $column_index);

    foreach ($identifiers as $identifier) {
        $doi = extract_doi($identifier);
        if ($doi === false) {
            continue;
        }

        if (!isset($global_dois[$doi])) {
            $global_dois[$doi] = array();
        }
        $global_dois[$doi][] = $dataset_name;
    }
}

$total_dois = count($global_dois);

echo "=============================================================\n";
echo "  OPEN ACCESS CHECK VIA UNPAYWALL\n";
echo "=============================================================\n\n";

echo "Total unique DOIs found: $total_dois\n\n";

if ($total_dois === 0) {
    echo "No DOIs to check.\n";
    exit(0);
}

echo "Checking DOIs against Unpaywall (this may take a while)...\n\n";

// Output files
$results_tsv_file = $base_dir . '/check_oa_results.tsv';
$results_tsv = fopen($results_tsv_file, 'w');
fwrite($results_tsv, "doi\tis_oa\toa_status\tjournal\tpublisher\tdatasets\n");

$summary_tsv_file = $base_dir . '/check_oa_summary.tsv';

// Counters
$oa_count = 0;
$closed_count = 0;
$error_count = 0;

// Per OA-status counts
$oa_status_counts = array();

$checked = 0;
foreach ($global_dois as $doi => $datasets) {
    $checked++;

    $result = query_unpaywall($doi);

    $datasets_str = implode(', ', $datasets);

    if ($result === false) {
        fwrite($results_tsv, "$doi\t\terror\t\t\t$datasets_str\n");
        $error_count++;
    } else {
        $is_oa = $result['is_oa'] ? 'true' : 'false';
        $oa_status = $result['oa_status'];
        $journal = $result['journal'];
        $publisher = $result['publisher'];

        fwrite($results_tsv, "$doi\t$is_oa\t$oa_status\t$journal\t$publisher\t$datasets_str\n");

        if ($result['is_oa']) {
            $oa_count++;
        } else {
            $closed_count++;
        }

        if (!isset($oa_status_counts[$oa_status])) {
            $oa_status_counts[$oa_status] = 0;
        }
        $oa_status_counts[$oa_status]++;
    }

    // Progress every 50 DOIs
    if ($checked % 50 === 0) {
        echo "  Checked $checked / $total_dois ...\n";
    }

    // Be polite to the API — small delay between requests
    usleep(100000);  // 100ms
}

fclose($results_tsv);

// Write summary TSV
arsort($oa_status_counts);

$summary_tsv = fopen($summary_tsv_file, 'w');
fwrite($summary_tsv, "oa_status\tcount\n");
foreach ($oa_status_counts as $status => $count) {
    fwrite($summary_tsv, "$status\t$count\n");
}
if ($error_count > 0) {
    fwrite($summary_tsv, "error\t$error_count\n");
}
fclose($summary_tsv);

// Console summary
$oa_pct = $total_dois > 0 ? round(100 * $oa_count / $total_dois, 1) : 0;

echo "\n=== Summary ===\n";
echo "Total DOIs checked:    $total_dois\n";
echo "Open access:           $oa_count ($oa_pct%)\n";
echo "Closed:                $closed_count\n";
echo "Errors:                $error_count\n";

echo "\n=== By OA status ===\n";
echo sprintf("%-20s %s\n", "Status", "Count");
echo str_repeat('-', 30) . "\n";
foreach ($oa_status_counts as $status => $count) {
    echo sprintf("%-20s %d\n", $status, $count);
}
if ($error_count > 0) {
    echo sprintf("%-20s %d\n", "error", $error_count);
}

echo "\nTSV files written to:\n";
echo "  $results_tsv_file\n";
echo "  $summary_tsv_file\n";

// ======================== Helper functions ========================

/**
 * Extract a DOI from an identifier string.
 *
 * Handles identifiers like:
 *   - https://doi.org/10.1234/foo
 *   - http://doi.org/10.1234/foo
 *   - https://dx.doi.org/10.1234/foo
 *   - doi.org/10.1234/foo
 *   - 10.1234/foo
 *
 * @param string $identifier The raw identifier string.
 * @return string|false The DOI (e.g. "10.1234/foo") or false if not a DOI.
 */
function extract_doi($identifier)
{
    $identifier = trim($identifier);

    // Match DOI pattern: 10.NNNN/... anywhere in the string
    if (preg_match('/(10\.\d{4,9}\/[^\s]+)/', $identifier, $m)) {
        return $m[1];
    }

    return false;
}

/**
 * Query the Unpaywall API for a DOI.
 *
 * @param string $doi The DOI (without doi.org prefix).
 * @return array|false Array with is_oa, oa_status, journal, publisher; or false on error.
 */
function query_unpaywall($doi)
{
    $url = 'https://api.oadoi.org/v2/' . strtolower($doi) . '?email=unpaywall@impactstory.org';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; colombian-beetles/1.0)');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_errno($ch);
    curl_close($ch);

    if ($curl_error || $http_code != 200 || empty($response)) {
        return false;
    }

    $data = json_decode($response, true);
    if ($data === null || !isset($data['is_oa'])) {
        return false;
    }

    return array(
        'is_oa'     => (bool) $data['is_oa'],
        'oa_status' => isset($data['oa_status']) ? $data['oa_status'] : '',
        'journal'   => isset($data['journal_name']) ? $data['journal_name'] : '',
        'publisher' => isset($data['publisher']) ? $data['publisher'] : '',
    );
}
