<?php

/**
 * Run identifier domain classification across all datasets and produce
 * a consolidated summary.
 *
 * Usage: php classify_all.php
 */

require_once dirname(__FILE__) . '/functions.php';

$base_dir = dirname(__FILE__);
$dataset_dirs = get_dataset_dirs($base_dir);

// Global totals
$global_domains = array();    // domain => count of unique identifiers
$global_total = 0;
$datasets_with_ids = 0;
$datasets_without_ids = 0;

// Per-dataset summary
$per_dataset = array();

foreach ($dataset_dirs as $dataset_dir) {
    $dataset_name = basename($dataset_dir);

    $column_index = get_identifier_column($dataset_dir);
    if ($column_index === false) {
        $datasets_without_ids++;
        $per_dataset[] = array(
            'name' => $dataset_name,
            'count' => 0,
            'has_identifiers' => false,
        );
        continue;
    }

    $identifiers = get_unique_identifiers($dataset_dir, $column_index);
    $count = count($identifiers);

    if ($count === 0) {
        $datasets_without_ids++;
        $per_dataset[] = array(
            'name' => $dataset_name,
            'count' => 0,
            'has_identifiers' => true,
        );
        continue;
    }

    $datasets_with_ids++;
    $global_total += $count;

    // Classify by domain
    $local_domains = array();
    foreach ($identifiers as $identifier) {
        $url = ensure_scheme($identifier);
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            $host = '(unknown)';
        }

        if (!isset($global_domains[$host])) {
            $global_domains[$host] = 0;
        }
        $global_domains[$host]++;

        if (!isset($local_domains[$host])) {
            $local_domains[$host] = 0;
        }
        $local_domains[$host]++;
    }

    $per_dataset[] = array(
        'name' => $dataset_name,
        'count' => $count,
        'has_identifiers' => true,
        'domains' => $local_domains,
    );
}

// === Write TSV files ===

// 1. Per-identifier TSV: identifier, domain, dataset
$tsv_identifiers_file = $base_dir . '/classify_all_identifiers.tsv';
$tsv_handle = fopen($tsv_identifiers_file, 'w');
fwrite($tsv_handle, implode("\t", array('identifier', 'domain', 'dataset')) . "\n");

foreach ($dataset_dirs as $dataset_dir) {
    $dataset_name = basename($dataset_dir);
    $column_index = get_identifier_column($dataset_dir);
    if ($column_index === false) {
        continue;
    }
    $identifiers = get_unique_identifiers($dataset_dir, $column_index);
    foreach ($identifiers as $identifier) {
        $url = ensure_scheme($identifier);
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            $host = '(unknown)';
        }
        fwrite($tsv_handle, implode("\t", array($identifier, $host, $dataset_name)) . "\n");
    }
}
fclose($tsv_handle);

// 2. Domain summary TSV: domain, count
arsort($global_domains);

$tsv_domains_file = $base_dir . '/classify_all_domains.tsv';
$tsv_handle = fopen($tsv_domains_file, 'w');
fwrite($tsv_handle, implode("\t", array('domain', 'count')) . "\n");
foreach ($global_domains as $domain => $count) {
    fwrite($tsv_handle, implode("\t", array($domain, $count)) . "\n");
}
fclose($tsv_handle);

// === Console output ===

echo "=============================================================\n";
echo "  IDENTIFIER CLASSIFICATION ACROSS ALL DATASETS\n";
echo "=============================================================\n\n";

// Overview
echo "Datasets scanned:              " . count($dataset_dirs) . "\n";
echo "Datasets with identifiers:     " . $datasets_with_ids . "\n";
echo "Datasets without identifiers:  " . $datasets_without_ids . "\n";
echo "Total unique identifiers:      " . $global_total . "\n\n";

echo "Results saved to:\n";
echo "  $tsv_identifiers_file\n";
echo "  $tsv_domains_file\n\n";

// Global domain summary
echo "=== Global domain summary ===\n\n";
echo str_pad("Domain", 50) . "Count\n";
echo str_repeat("-", 60) . "\n";
foreach ($global_domains as $domain => $count) {
    echo str_pad($domain, 50) . $count . "\n";
}
echo str_repeat("-", 60) . "\n";
echo str_pad("TOTAL", 50) . $global_total . "\n\n";

// Per-dataset breakdown
echo "=== Per-dataset breakdown ===\n\n";

foreach ($per_dataset as $ds) {
    if (!$ds['has_identifiers']) {
        echo $ds['name'] . ": no identifier field\n";
        continue;
    }
    if ($ds['count'] === 0) {
        echo $ds['name'] . ": 0 identifiers\n";
        continue;
    }

    echo $ds['name'] . " (" . $ds['count'] . " identifiers)\n";
    arsort($ds['domains']);
    foreach ($ds['domains'] as $domain => $count) {
        echo "  " . str_pad($domain, 48) . $count . "\n";
    }
}
