<?php

/**
 * Check whether reference identifiers can be resolved (HTTP status).
 *
 * Usage: php check_identifiers.php <dataset_directory>
 * Example: php check_identifiers.php brentidae_colombia
 */

require_once dirname(__FILE__) . '/functions.php';

if (!isset($argv[1])) {
    echo "Usage: php check_identifiers.php <dataset_directory>\n";
    exit(1);
}

$dataset_name = $argv[1];
$dataset_dir = dirname(__FILE__) . '/' . $dataset_name;

if (!is_dir($dataset_dir)) {
    echo "Error: directory '$dataset_dir' not found.\n";
    exit(1);
}

// Check if the dataset has an identifier column
$column_index = get_identifier_column($dataset_dir);
if ($column_index === false) {
    echo "Dataset '$dataset_name' does not have an identifier field in its Reference extension.\n";
    exit(0);
}

// Extract unique identifiers
$identifiers = get_unique_identifiers($dataset_dir, $column_index);

if (empty($identifiers)) {
    echo "No identifiers found in '$dataset_name/reference.txt'.\n";
    exit(0);
}

echo "Dataset: $dataset_name\n";
echo "Checking " . count($identifiers) . " unique identifiers...\n\n";

// TSV header
echo implode("\t", array('identifier', 'http_code', 'final_url', 'status')) . "\n";

// Counters for summary
$counts = array(
    'ok' => 0,
    'redirect' => 0,
    'not_found' => 0,
    'error' => 0,
);

foreach ($identifiers as $identifier) {
    $url = ensure_scheme($identifier);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; colombian-beetles/1.0)');

    curl_exec($ch);

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $curl_error = curl_errno($ch);

    curl_close($ch);

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

    echo implode("\t", array($identifier, $http_code, $final_url, $status)) . "\n";
}

// Summary
echo "\n=== Summary ===\n";
echo "OK (200, no redirect):  " . $counts['ok'] . "\n";
echo "Redirected:             " . $counts['redirect'] . "\n";
echo "Not found (404):        " . $counts['not_found'] . "\n";
echo "Other errors:           " . $counts['error'] . "\n";
echo "Total:                  " . count($identifiers) . "\n";
