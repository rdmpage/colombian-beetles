<?php

/**
 * Check URL resolution for identifiers across all datasets and produce
 * a consolidated summary.
 *
 * Collects all unique identifiers across all datasets, deduplicates them
 * globally, then checks each one once. Reports per-dataset and global results.
 *
 * Usage:  php check_all.php
 * Output: check_all_results.tsv    (per-identifier results)
 *         check_all_domains.tsv     (summary by domain)
 */

require_once dirname(__FILE__) . '/functions.php';

$base_dir = dirname(__FILE__);
$dataset_dirs = get_dataset_dirs($base_dir);

// Collect all unique identifiers globally, tracking which datasets they appear in
$global_identifiers = array();  // identifier => array of dataset names
$datasets_with_ids = 0;
$datasets_without_ids = 0;

foreach ($dataset_dirs as $dataset_dir) {
    $dataset_name = basename($dataset_dir);

    $column_index = get_identifier_column($dataset_dir);
    if ($column_index === false) {
        $datasets_without_ids++;
        continue;
    }

    $identifiers = get_unique_identifiers($dataset_dir, $column_index);
    if (empty($identifiers)) {
        $datasets_without_ids++;
        continue;
    }

    $datasets_with_ids++;

    foreach ($identifiers as $identifier) {
        if (!isset($global_identifiers[$identifier])) {
            $global_identifiers[$identifier] = array();
        }
        $global_identifiers[$identifier][] = $dataset_name;
    }
}

$total_unique = count($global_identifiers);

// Output TSV results to a file
$tsv_file = $base_dir . '/check_all_results.tsv';
$tsv_handle = fopen($tsv_file, 'w');
fwrite($tsv_handle, implode("\t", array('identifier', 'http_code', 'final_url', 'status', 'datasets')) . "\n");

echo "=============================================================\n";
echo "  IDENTIFIER URL RESOLUTION CHECK ACROSS ALL DATASETS\n";
echo "=============================================================\n\n";

echo "Datasets scanned:              " . count($dataset_dirs) . "\n";
echo "Datasets with identifiers:     " . $datasets_with_ids . "\n";
echo "Datasets without identifiers:  " . $datasets_without_ids . "\n";
echo "Total unique identifiers:      " . $total_unique . "\n\n";

echo "Checking URLs (this may take a while)...\n";
echo "Results will be saved to:\n";
echo "  $tsv_file\n";
echo "  " . $base_dir . "/check_all_domains.tsv\n\n";

// Counters
$counts = array(
    'ok' => 0,
    'redirect' => 0,
    'not_found' => 0,
    'error' => 0,
);

// Results by domain for the summary
$domain_results = array();

$checked = 0;
foreach ($global_identifiers as $identifier => $datasets) {
    $checked++;
    $url = ensure_scheme($identifier);

    // Build list of URLs to try: original, then HTTP fallback if HTTPS
    $urls_to_try = array($url);
    if (strpos($url, 'https://') === 0) {
        $urls_to_try[] = 'http://' . substr($url, 8);
    }

    $http_code = 0;
    $final_url = $url;
    $curl_error = 0;

    foreach ($urls_to_try as $try_url) {
        // Try HEAD first, fall back to GET
        foreach (array(true, false) as $head_request) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $try_url);
            curl_setopt($ch, CURLOPT_NOBODY, $head_request);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; colombian-beetles/1.0)');
            // Skip strict SSL verification — some servers have broken certificate chains
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            curl_exec($ch);

            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $curl_error = curl_errno($ch);

            curl_close($ch);

            // If we got a real HTTP response, stop trying
            if (!$curl_error && $http_code > 0) {
                break 2;
            }
        }
    }

    // Determine status
    if ($curl_error) {
        $status = 'error';
        $counts['error']++;
    } elseif ($http_code == 200) {
        if ($final_url !== $url) {
            $status = 'redirect_ok';
            $counts['redirect']++;
        } else {
            $status = 'ok';
            $counts['ok']++;
        }
    } elseif ($http_code == 301 || $http_code == 302 || $http_code == 303 || $http_code == 307 || $http_code == 308) {
        $status = 'redirect';
        $counts['redirect']++;
    } elseif ($http_code == 404) {
        $status = 'not_found';
        $counts['not_found']++;
    } else {
        $status = 'http_' . $http_code;
        $counts['error']++;
    }

    // Track by domain
    $host = parse_url($url, PHP_URL_HOST);
    if ($host === null || $host === false) {
        $host = '(unknown)';
    }
    if (!isset($domain_results[$host])) {
        $domain_results[$host] = array('ok' => 0, 'redirect' => 0, 'not_found' => 0, 'error' => 0, 'total' => 0);
    }
    $domain_results[$host]['total']++;
    if ($status === 'ok') {
        $domain_results[$host]['ok']++;
    } elseif ($status === 'redirect_ok' || $status === 'redirect') {
        $domain_results[$host]['redirect']++;
    } elseif ($status === 'not_found') {
        $domain_results[$host]['not_found']++;
    } else {
        $domain_results[$host]['error']++;
    }

    $datasets_str = implode(', ', $datasets);
    fwrite($tsv_handle, implode("\t", array($identifier, $http_code, $final_url, $status, $datasets_str)) . "\n");

    // Progress every 50 URLs
    if ($checked % 50 === 0) {
        echo "  Checked $checked / $total_unique ...\n";
    }
}

fclose($tsv_handle);

// === Summary ===

echo "\n=== Overall Summary ===\n";
echo "OK (200, direct):       " . $counts['ok'] . "\n";
echo "Redirected (to 200):    " . $counts['redirect'] . "\n";
echo "Not found (404):        " . $counts['not_found'] . "\n";
echo "Other errors:           " . $counts['error'] . "\n";
echo "Total checked:          " . $total_unique . "\n";

// Domain breakdown
echo "\n=== Results by domain ===\n\n";
echo str_pad("Domain", 45) . str_pad("Total", 8) . str_pad("OK", 8) . str_pad("Redir", 8) . str_pad("404", 8) . "Error\n";
echo str_repeat("-", 85) . "\n";

// Sort by total descending
uasort($domain_results, function ($a, $b) {
    return $b['total'] - $a['total'];
});

// Write domain summary TSV
$domain_tsv_file = $base_dir . '/check_all_domains.tsv';
$domain_tsv = fopen($domain_tsv_file, 'w');
fwrite($domain_tsv, "domain\ttotal\tok\tredirect\tnot_found\terror\n");

foreach ($domain_results as $domain => $r) {
    echo str_pad($domain, 45)
        . str_pad($r['total'], 8)
        . str_pad($r['ok'], 8)
        . str_pad($r['redirect'], 8)
        . str_pad($r['not_found'], 8)
        . $r['error'] . "\n";
    fwrite($domain_tsv, "$domain\t{$r['total']}\t{$r['ok']}\t{$r['redirect']}\t{$r['not_found']}\t{$r['error']}\n");
}

fclose($domain_tsv);

echo "\nTSV files written to:\n";
echo "  $tsv_file\n";
echo "  $domain_tsv_file\n";
