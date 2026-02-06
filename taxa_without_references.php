<?php

/**
 * For each dataset, find taxa that have no corresponding rows in reference.txt.
 * Links via column 0: taxon.txt id == reference.txt coreid.
 *
 * Usage: php taxa_without_references.php [dataset_directory]
 *
 * If no argument given, runs across all datasets.
 */

require_once dirname(__FILE__) . '/functions.php';

$base_dir = dirname(__FILE__);

// Single dataset or all?
if (isset($argv[1])) {
    $dataset_dirs = array($base_dir . '/' . $argv[1]);
    if (!is_dir($dataset_dirs[0])) {
        echo "Error: directory '{$dataset_dirs[0]}' not found.\n";
        exit(1);
    }
} else {
    $dataset_dirs = get_dataset_dirs($base_dir);
}

// TSV output file (only when running across all datasets)
$tsv_file = null;
$tsv_handle = null;
if (!isset($argv[1])) {
    $tsv_file = $base_dir . '/taxa_without_references.tsv';
    $tsv_handle = fopen($tsv_file, 'w');
    fwrite($tsv_handle, implode("\t", array('dataset', 'taxon_id', 'scientificName', 'taxonRank')) . "\n");
}

// Global counters
$global_total_taxa = 0;
$global_taxa_with_refs = 0;
$global_taxa_without_refs = 0;
$per_dataset = array();

foreach ($dataset_dirs as $dataset_dir) {
    $dataset_name = basename($dataset_dir);

    $taxon_file = $dataset_dir . '/taxon.txt';
    $reference_file = $dataset_dir . '/reference.txt';

    if (!file_exists($taxon_file)) {
        continue;
    }

    // Step 1: Collect all coreid values from reference.txt
    $reference_coreids = array();
    if (file_exists($reference_file)) {
        $handle = fopen($reference_file, 'r');
        if ($handle) {
            $line_num = 0;
            while (($line = fgets($handle)) !== false) {
                $line_num++;
                if ($line_num === 1) {
                    continue; // skip header
                }
                $fields = explode("\t", rtrim($line, "\n\r"));
                if (isset($fields[0]) && trim($fields[0]) !== '') {
                    $reference_coreids[trim($fields[0])] = true;
                }
            }
            fclose($handle);
        }
    }

    // Step 2: Read taxon.txt, find the scientificName and taxonRank column indices
    // We need the header to find column positions
    $handle = fopen($taxon_file, 'r');
    if (!$handle) {
        continue;
    }

    $header_line = fgets($handle);
    $headers = explode("\t", rtrim($header_line, "\n\r"));

    $name_col = array_search('scientificName', $headers);
    $rank_col = array_search('taxonRank', $headers);

    // Step 3: Check each taxon
    $total_taxa = 0;
    $without_refs = array();

    while (($line = fgets($handle)) !== false) {
        $fields = explode("\t", rtrim($line, "\n\r"));
        $taxon_id = isset($fields[0]) ? trim($fields[0]) : '';

        if ($taxon_id === '') {
            continue;
        }

        $total_taxa++;

        if (!isset($reference_coreids[$taxon_id])) {
            $sci_name = ($name_col !== false && isset($fields[$name_col])) ? trim($fields[$name_col]) : '';
            $rank = ($rank_col !== false && isset($fields[$rank_col])) ? trim($fields[$rank_col]) : '';

            $without_refs[] = array(
                'id' => $taxon_id,
                'scientificName' => $sci_name,
                'taxonRank' => $rank,
            );

            if ($tsv_handle) {
                fwrite($tsv_handle, implode("\t", array($dataset_name, $taxon_id, $sci_name, $rank)) . "\n");
            }
        }
    }

    fclose($handle);

    $with_refs = $total_taxa - count($without_refs);
    $global_total_taxa += $total_taxa;
    $global_taxa_with_refs += $with_refs;
    $global_taxa_without_refs += count($without_refs);

    $per_dataset[] = array(
        'name' => $dataset_name,
        'total' => $total_taxa,
        'with_refs' => $with_refs,
        'without_refs' => count($without_refs),
        'missing' => $without_refs,
    );
}

if ($tsv_handle) {
    fclose($tsv_handle);
}

// === Console output ===

echo "=============================================================\n";
echo "  TAXA WITHOUT REFERENCES\n";
echo "=============================================================\n\n";

echo "Datasets scanned:         " . count($dataset_dirs) . "\n";
echo "Total taxa:               " . $global_total_taxa . "\n";
echo "Taxa with references:     " . $global_taxa_with_refs . "\n";
echo "Taxa without references:  " . $global_taxa_without_refs . "\n";

if ($global_total_taxa > 0) {
    $pct = round(100 * $global_taxa_without_refs / $global_total_taxa, 1);
    echo "Percentage missing:       " . $pct . "%\n";
}

if ($tsv_file) {
    echo "\nDetailed results saved to: $tsv_file\n";
}

echo "\n=== Per-dataset breakdown ===\n\n";
echo str_pad("Dataset", 40) . str_pad("Total", 8) . str_pad("With", 8) . str_pad("Without", 8) . "%\n";
echo str_repeat("-", 72) . "\n";

foreach ($per_dataset as $ds) {
    $pct = $ds['total'] > 0 ? round(100 * $ds['without_refs'] / $ds['total'], 1) : 0;
    echo str_pad($ds['name'], 40)
        . str_pad($ds['total'], 8)
        . str_pad($ds['with_refs'], 8)
        . str_pad($ds['without_refs'], 8)
        . $pct . "%\n";
}

// Show details for datasets with missing references
$any_missing = false;
foreach ($per_dataset as $ds) {
    if ($ds['without_refs'] > 0) {
        if (!$any_missing) {
            echo "\n=== Taxa without references (details) ===\n";
            $any_missing = true;
        }
        echo "\n" . $ds['name'] . " (" . $ds['without_refs'] . " taxa without references):\n";
        foreach ($ds['missing'] as $t) {
            echo "  " . $t['scientificName'];
            if ($t['taxonRank'] !== '') {
                echo " [" . $t['taxonRank'] . "]";
            }
            echo "\n";
        }
    }
}

if (!$any_missing) {
    echo "\nAll taxa have at least one reference.\n";
}
