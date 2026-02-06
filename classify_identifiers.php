<?php

/**
 * Classify reference identifiers by domain name.
 *
 * Usage: php classify_identifiers.php <dataset_directory>
 * Example: php classify_identifiers.php brentidae_colombia
 */

require_once dirname(__FILE__) . '/functions.php';

if (!isset($argv[1])) {
    echo "Usage: php classify_identifiers.php <dataset_directory>\n";
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
echo "Unique identifiers: " . count($identifiers) . "\n\n";

// Classify by domain
$by_domain = array();
foreach ($identifiers as $identifier) {
    $url = ensure_scheme($identifier);
    $host = parse_url($url, PHP_URL_HOST);

    if ($host === null || $host === false) {
        $host = '(unknown)';
    }

    if (!isset($by_domain[$host])) {
        $by_domain[$host] = array();
    }
    $by_domain[$host][] = $identifier;
}

// Sort domains by count descending
uasort($by_domain, function ($a, $b) {
    return count($b) - count($a);
});

// Output summary
echo "=== Summary by domain ===\n";
echo str_pad("Domain", 50) . "Count\n";
echo str_repeat("-", 60) . "\n";

foreach ($by_domain as $domain => $ids) {
    echo str_pad($domain, 50) . count($ids) . "\n";
}

echo "\n";

// Output details
echo "=== Identifiers by domain ===\n";
foreach ($by_domain as $domain => $ids) {
    echo "\n[$domain] (" . count($ids) . ")\n";
    sort($ids);
    foreach ($ids as $id) {
        echo "  $id\n";
    }
}
