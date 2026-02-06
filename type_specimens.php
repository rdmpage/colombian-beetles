<?php

/**
 * type_specimens.php
 *
 * Analyses type specimen data across all datasets.
 * Reports:
 *   - which institutions hold type specimens and how many
 *   - which taxa across all datasets lack type specimen information
 *
 * Usage:  php type_specimens.php
 * Output: type_specimens_institutions.tsv       (raw institution strings)
 *         type_specimens_normalised.tsv          (grouped by acronym)
 *         taxa_without_types.tsv
 */

require_once __DIR__ . '/functions.php';

$base_dir = __DIR__;
$dataset_dirs = get_dataset_dirs($base_dir);

// ---------- helper: find column index for a term in TypesAndSpecimen ----------

/**
 * Parse meta.xml to find the column index of a given term in the
 * TypesAndSpecimen extension.
 *
 * @param string $dataset_dir Path to the dataset directory.
 * @param string $term        The full term URI to look for.
 * @return int|false Column index, or false if not found.
 */
function get_types_column($dataset_dir, $term)
{
    $meta_file = rtrim($dataset_dir, '/') . '/meta.xml';

    if (!file_exists($meta_file)) {
        return false;
    }

    $xml = simplexml_load_file($meta_file);
    if ($xml === false) {
        return false;
    }

    foreach ($xml->extension as $extension) {
        $rowType = (string) $extension['rowType'];
        if (strpos($rowType, 'TypesAndSpecimen') !== false) {
            foreach ($extension->field as $field) {
                if ((string) $field['term'] === $term) {
                    return (int) $field['index'];
                }
            }
            return false;
        }
    }

    return false;
}

/**
 * Check whether a dataset has a TypesAndSpecimen extension at all.
 *
 * @param string $dataset_dir Path to the dataset directory.
 * @return bool
 */
function has_types_extension($dataset_dir)
{
    $meta_file = rtrim($dataset_dir, '/') . '/meta.xml';

    if (!file_exists($meta_file)) {
        return false;
    }

    $xml = simplexml_load_file($meta_file);
    if ($xml === false) {
        return false;
    }

    foreach ($xml->extension as $extension) {
        $rowType = (string) $extension['rowType'];
        if (strpos($rowType, 'TypesAndSpecimen') !== false) {
            return true;
        }
    }

    return false;
}

// ---------- helper: extract acronym from institution string ----------

/**
 * Attempt to extract an institutional acronym/code from a raw
 * institutionCode string.
 *
 * Handles two common patterns:
 *   1. "CODE - Full Name ..."          → CODE
 *   2. "Full Name (CODE)"              → CODE
 *
 * Also strips surrounding quotes. Returns the original string
 * (trimmed) if no acronym can be extracted.
 *
 * @param string $raw The raw institutionCode value.
 * @return string The extracted acronym or the cleaned original.
 */
function extract_acronym($raw)
{
    // Strip surrounding quotes
    $s = trim($raw, "\" \t");

    // Pattern 1: starts with uppercase acronym followed by " - "
    // e.g. "BMNH - The Natural History Museum, Londres"
    // Also handles "BMNH The Natural History Museum" (space, no dash)
    if (preg_match('/^([A-Z][A-Z0-9\-]{1,15})\s+-\s+/', $s, $m)) {
        return $m[1];
    }

    // Some entries use "CODE Full Name" without the dash
    // e.g. "BMNH The Natural History Museum, London"
    // but we need to be careful not to match regular words
    if (preg_match('/^([A-Z]{2,10})\s+[A-Z]/', $s, $m)) {
        return $m[1];
    }

    // Pattern 2: ends with "(CODE)" where CODE is a short identifier
    // e.g. "Smithsonian Institution, National Museum of Natural History (USNM)"
    // Allow mixed case and accented chars for codes like IAvH, UniQuindío
    if (preg_match('/\(([A-Z\p{Lu}][\p{L}0-9\-]{1,15})\)\s*$/u', $s, $m)) {
        return $m[1];
    }

    // No acronym found — return cleaned string as-is
    return $s;
}

// ======================== PART 1: Institution counts ========================

$institution_tsv_file = $base_dir . '/type_specimens_institutions.tsv';
$institution_tsv = fopen($institution_tsv_file, 'w');
fwrite($institution_tsv, "institutionCode\tcount\n");

$institutions = array();            // raw institutionCode => count
$normalised = array();              // acronym => count
$normalised_variants = array();     // acronym => array of raw strings seen
$total_type_rows = 0;
$datasets_with_types = 0;
$datasets_without_types = 0;
$empty_institution_count = 0;

// We also collect the set of coreid values that appear in typesandspecimen.txt
// per dataset, so we can later check which taxa lack type information.
$taxa_with_types = array();  // dataset_name => array of coreid values

echo "Analysing type specimens across all datasets...\n\n";

foreach ($dataset_dirs as $dir) {
    $dataset_name = basename($dir);
    $types_file = $dir . '/typesandspecimen.txt';

    if (!has_types_extension($dir) || !file_exists($types_file)) {
        $datasets_without_types++;
        continue;
    }

    $datasets_with_types++;

    $institution_col = get_types_column($dir, 'http://rs.tdwg.org/dwc/terms/institutionCode');

    $handle = fopen($types_file, 'r');
    if ($handle === false) {
        continue;
    }

    $taxa_with_types[$dataset_name] = array();

    $line_num = 0;
    while (($line = fgets($handle)) !== false) {
        $line_num++;

        // Skip header
        if ($line_num === 1) {
            continue;
        }

        $line = rtrim($line, "\n\r");
        if ($line === '') {
            continue;
        }

        $total_type_rows++;
        $fields = explode("\t", $line);

        // Track which taxa (coreid, column 0) have type information
        if (isset($fields[0]) && trim($fields[0]) !== '') {
            $taxa_with_types[$dataset_name][trim($fields[0])] = true;
        }

        // Count institutions (raw and normalised)
        if ($institution_col !== false && isset($fields[$institution_col])) {
            $inst = trim($fields[$institution_col]);
            if ($inst !== '') {
                if (!isset($institutions[$inst])) {
                    $institutions[$inst] = 0;
                }
                $institutions[$inst]++;

                $acronym = extract_acronym($inst);
                if (!isset($normalised[$acronym])) {
                    $normalised[$acronym] = 0;
                    $normalised_variants[$acronym] = array();
                }
                $normalised[$acronym]++;
                if (!in_array($inst, $normalised_variants[$acronym])) {
                    $normalised_variants[$acronym][] = $inst;
                }
            } else {
                $empty_institution_count++;
            }
        } else {
            $empty_institution_count++;
        }
    }

    fclose($handle);
}

// Sort institutions by count descending
arsort($institutions);

foreach ($institutions as $inst => $count) {
    fwrite($institution_tsv, "$inst\t$count\n");
}

fclose($institution_tsv);

// Write normalised institutions TSV
arsort($normalised);

$normalised_tsv_file = $base_dir . '/type_specimens_normalised.tsv';
$normalised_tsv = fopen($normalised_tsv_file, 'w');
fwrite($normalised_tsv, "acronym\tcount\tvariants\n");

foreach ($normalised as $acronym => $count) {
    $variants = implode(' | ', $normalised_variants[$acronym]);
    fwrite($normalised_tsv, "$acronym\t$count\t$variants\n");
}

fclose($normalised_tsv);

// ======================== PART 2: Taxa without types ========================

$taxa_tsv_file = $base_dir . '/taxa_without_types.tsv';
$taxa_tsv = fopen($taxa_tsv_file, 'w');
fwrite($taxa_tsv, "dataset\ttaxon_id\tscientificName\ttaxonRank\thas_types_extension\n");

$grand_total_taxa = 0;
$grand_with_types = 0;
$grand_without_types = 0;
$per_dataset_stats = array();

foreach ($dataset_dirs as $dir) {
    $dataset_name = basename($dir);
    $taxon_file = $dir . '/taxon.txt';

    if (!file_exists($taxon_file)) {
        continue;
    }

    $has_extension = has_types_extension($dir);

    // Read taxon.txt — we need id (col 0), scientificName and taxonRank
    // Parse meta.xml for the taxon core to find the right columns
    $meta_file = $dir . '/meta.xml';
    $name_col = false;
    $rank_col = false;

    if (file_exists($meta_file)) {
        $xml = simplexml_load_file($meta_file);
        if ($xml !== false) {
            foreach ($xml->core->field as $field) {
                $term = (string) $field['term'];
                if ($term === 'http://rs.tdwg.org/dwc/terms/scientificName') {
                    $name_col = (int) $field['index'];
                }
                if ($term === 'http://rs.tdwg.org/dwc/terms/taxonRank') {
                    $rank_col = (int) $field['index'];
                }
            }
        }
    }

    $handle = fopen($taxon_file, 'r');
    if ($handle === false) {
        continue;
    }

    $dataset_total = 0;
    $dataset_without = 0;

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
        $taxon_id = isset($fields[0]) ? trim($fields[0]) : '';
        $sci_name = ($name_col !== false && isset($fields[$name_col])) ? trim($fields[$name_col]) : '';
        $rank     = ($rank_col !== false && isset($fields[$rank_col])) ? trim($fields[$rank_col]) : '';

        $dataset_total++;
        $grand_total_taxa++;

        // Check if this taxon has type information
        $has_type = false;
        if ($has_extension && isset($taxa_with_types[$dataset_name][$taxon_id])) {
            $has_type = true;
        }

        if ($has_type) {
            $grand_with_types++;
        } else {
            $grand_without_types++;
            $dataset_without++;
            $ext_flag = $has_extension ? 'yes' : 'no';
            fwrite($taxa_tsv, "$dataset_name\t$taxon_id\t$sci_name\t$rank\t$ext_flag\n");
        }
    }

    fclose($handle);

    $per_dataset_stats[] = array(
        'name' => $dataset_name,
        'total' => $dataset_total,
        'without' => $dataset_without,
        'has_extension' => $has_extension
    );
}

fclose($taxa_tsv);

// ======================== Console output ========================

echo "--- Normalised institution summary (by acronym) ---\n";
echo sprintf("%-20s %6s  %s\n", "Acronym", "Types", "Variants");
echo str_repeat('-', 90) . "\n";
foreach ($normalised as $acronym => $count) {
    $num_variants = count($normalised_variants[$acronym]);
    $variant_note = ($num_variants > 1) ? "($num_variants variants)" : '';
    echo sprintf("%-20s %6d  %s\n", $acronym, $count, $variant_note);
}
if ($empty_institution_count > 0) {
    echo sprintf("%-20s %6d\n", "(empty/missing)", $empty_institution_count);
}

echo "\n--- Type specimen overview ---\n";
echo "Total datasets:                    " . count($dataset_dirs) . "\n";
echo "  With TypesAndSpecimen extension: $datasets_with_types\n";
echo "  Without:                         $datasets_without_types\n";
echo "Total type specimen rows:          $total_type_rows\n";
echo "Distinct raw institution strings:  " . count($institutions) . "\n";
echo "Distinct acronyms (normalised):    " . count($normalised) . "\n";

echo "\n--- Taxa with/without type information ---\n";
echo "Total taxa:                        $grand_total_taxa\n";
echo "  With type info:                  $grand_with_types (" . ($grand_total_taxa > 0 ? round(100 * $grand_with_types / $grand_total_taxa, 1) : 0) . "%)\n";
echo "  Without type info:               $grand_without_types (" . ($grand_total_taxa > 0 ? round(100 * $grand_without_types / $grand_total_taxa, 1) : 0) . "%)\n";

echo "\n--- Per-dataset breakdown ---\n";
echo sprintf("%-40s %7s %7s %7s %s\n", "Dataset", "Total", "With", "Without", "Has ext?");
echo str_repeat('-', 80) . "\n";
foreach ($per_dataset_stats as $s) {
    $with = $s['total'] - $s['without'];
    $ext = $s['has_extension'] ? 'yes' : 'no';
    echo sprintf("%-40s %7d %7d %7d %s\n", $s['name'], $s['total'], $with, $s['without'], $ext);
}

echo "\nTSV files written to:\n";
echo "  $institution_tsv_file\n";
echo "  $normalised_tsv_file\n";
echo "  $taxa_tsv_file\n";
