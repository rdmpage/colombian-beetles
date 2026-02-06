<?php

/**
 * references_without_identifiers.php
 *
 * Checks whether all references across all datasets have identifiers.
 * Reports:
 *   - datasets that lack the identifier column entirely
 *   - datasets that have the identifier column but some rows are empty
 *   - total reference rows with vs without identifiers
 *
 * Usage:  php references_without_identifiers.php
 * Output: references_without_identifiers.tsv
 */

require_once __DIR__ . '/functions.php';

$base_dir = __DIR__;
$dataset_dirs = get_dataset_dirs($base_dir);

$tsv_file = $base_dir . '/references_without_identifiers.tsv';
$tsv = fopen($tsv_file, 'w');
fwrite($tsv, "dataset\ttotal_references\twith_identifier\twithout_identifier\thas_identifier_column\n");

$grand_total = 0;
$grand_with = 0;
$grand_without = 0;
$datasets_no_column = 0;
$datasets_all_have = 0;
$datasets_some_missing = 0;

echo "Checking whether all references have identifiers...\n\n";

foreach ($dataset_dirs as $dir) {
    $dataset_name = basename($dir);
    $reference_file = $dir . '/reference.txt';

    if (!file_exists($reference_file)) {
        continue;
    }

    $column_index = get_identifier_column($dir);

    // Count total reference rows
    $total_rows = 0;
    $with_id = 0;
    $without_id = 0;

    $handle = fopen($reference_file, 'r');
    if ($handle === false) {
        continue;
    }

    $line_num = 0;
    while (($line = fgets($handle)) !== false) {
        $line_num++;

        // Skip header line
        if ($line_num === 1) {
            continue;
        }

        $line = rtrim($line, "\n\r");
        if ($line === '') {
            continue;
        }

        $total_rows++;

        if ($column_index === false) {
            // No identifier column at all — every row counts as without
            $without_id++;
        } else {
            $fields = explode("\t", $line);
            if (isset($fields[$column_index]) && trim($fields[$column_index]) !== '') {
                $with_id++;
            } else {
                $without_id++;
            }
        }
    }

    fclose($handle);

    $has_column = ($column_index !== false) ? 'yes' : 'no';
    fwrite($tsv, "$dataset_name\t$total_rows\t$with_id\t$without_id\t$has_column\n");

    $grand_total += $total_rows;
    $grand_with += $with_id;
    $grand_without += $without_id;

    if ($column_index === false) {
        $datasets_no_column++;
        echo "  $dataset_name: NO identifier column ($total_rows references)\n";
    } elseif ($without_id > 0) {
        $datasets_some_missing++;
        echo "  $dataset_name: $without_id/$total_rows references lack identifiers\n";
    } else {
        $datasets_all_have++;
    }
}

fclose($tsv);

echo "\n--- Summary ---\n";
echo "Total datasets:                    " . count($dataset_dirs) . "\n";
echo "  With identifier column:          " . ($datasets_all_have + $datasets_some_missing) . "\n";
echo "    All references have id:        $datasets_all_have\n";
echo "    Some references missing id:    $datasets_some_missing\n";
echo "  Without identifier column:       $datasets_no_column\n";
echo "\n";
echo "Total reference rows:              $grand_total\n";
echo "  With identifier:                 $grand_with (" . ($grand_total > 0 ? round(100 * $grand_with / $grand_total, 1) : 0) . "%)\n";
echo "  Without identifier:              $grand_without (" . ($grand_total > 0 ? round(100 * $grand_without / $grand_total, 1) : 0) . "%)\n";
echo "\nTSV written to: $tsv_file\n";
