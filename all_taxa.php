<?php

/**
 * all_taxa.php
 *
 * Extracts all taxonomic names across all datasets and outputs them
 * sorted alphabetically by scientificName.
 *
 * Usage:  php all_taxa.php
 * Output: all_taxa.tsv
 */

require_once __DIR__ . '/functions.php';

$base_dir = __DIR__;
$dataset_dirs = get_dataset_dirs($base_dir);

$taxa = array();  // array of array('id' => ..., 'scientificName' => ..., 'dataset' => ...)

foreach ($dataset_dirs as $dir) {
    $dataset_name = basename($dir);
    $taxon_file = $dir . '/taxon.txt';

    if (!file_exists($taxon_file)) {
        continue;
    }

    // Parse meta.xml for scientificName column index
    $meta_file = $dir . '/meta.xml';
    $name_col = false;

    if (file_exists($meta_file)) {
        $xml = simplexml_load_file($meta_file);
        if ($xml !== false) {
            foreach ($xml->core->field as $field) {
                $term = (string) $field['term'];
                if ($term === 'http://rs.tdwg.org/dwc/terms/scientificName') {
                    $name_col = (int) $field['index'];
                }
            }
        }
    }

    $handle = fopen($taxon_file, 'r');
    if ($handle === false) {
        continue;
    }

    $line_num = 0;
    while (($line = fgets($handle)) !== false) {
        $line_num++;

        if ($line_num === 1) {
            continue;
        }

        $line = rtrim($line, "\n\r");
        if ($line === '') {
            continue;
        }

        $fields = explode("\t", $line);
        $id       = isset($fields[0]) ? trim($fields[0]) : '';
        $sci_name = ($name_col !== false && isset($fields[$name_col])) ? trim($fields[$name_col]) : '';

        $taxa[] = array('id' => $id, 'scientificName' => $sci_name, 'dataset' => $dataset_name);
    }

    fclose($handle);
}

// Sort alphabetically by scientificName
usort($taxa, function ($a, $b) {
    return strcmp($a['scientificName'], $b['scientificName']);
});

// Write TSV
$tsv_file = $base_dir . '/all_taxa.tsv';
$tsv = fopen($tsv_file, 'w');
fwrite($tsv, "id\tscientificName\tdataset\n");

foreach ($taxa as $t) {
    fwrite($tsv, "{$t['id']}\t{$t['scientificName']}\t{$t['dataset']}\n");
}

fclose($tsv);

echo "Total taxa: " . count($taxa) . "\n";
echo "TSV written to: $tsv_file\n";
