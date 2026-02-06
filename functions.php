<?php

/**
 * Shared helper functions for Darwin Core Archive analysis scripts.
 */

/**
 * Parse meta.xml in a dataset directory and find the column index of the
 * dc:identifier field in the Reference extension.
 *
 * @param string $dataset_dir Path to the dataset directory.
 * @return int|false Column index of the identifier field, or false if not present.
 */
function get_identifier_column($dataset_dir)
{
    $meta_file = rtrim($dataset_dir, '/') . '/meta.xml';

    if (!file_exists($meta_file)) {
        return false;
    }

    $xml = simplexml_load_file($meta_file);
    if ($xml === false) {
        return false;
    }

    // Find the Reference extension
    foreach ($xml->extension as $extension) {
        $rowType = (string) $extension['rowType'];
        if (strpos($rowType, 'Reference') !== false) {
            // Look for the identifier field
            foreach ($extension->field as $field) {
                $term = (string) $field['term'];
                if ($term === 'http://purl.org/dc/terms/identifier') {
                    return (int) $field['index'];
                }
            }
            // Reference extension found but no identifier field
            return false;
        }
    }

    // No Reference extension found
    return false;
}

/**
 * Extract unique identifiers from a dataset's reference.txt file.
 *
 * @param string $dataset_dir  Path to the dataset directory.
 * @param int    $column_index Column index of the identifier field.
 * @return array Array of unique identifier strings.
 */
function get_unique_identifiers($dataset_dir, $column_index)
{
    $reference_file = rtrim($dataset_dir, '/') . '/reference.txt';

    if (!file_exists($reference_file)) {
        return array();
    }

    $identifiers = array();
    $handle = fopen($reference_file, 'r');
    if ($handle === false) {
        return array();
    }

    $line_num = 0;
    while (($line = fgets($handle)) !== false) {
        $line_num++;

        // Skip header line
        if ($line_num === 1) {
            continue;
        }

        $line = rtrim($line, "\n\r");
        $fields = explode("\t", $line);

        if (isset($fields[$column_index])) {
            $identifier = trim($fields[$column_index]);
            if ($identifier !== '') {
                $identifiers[$identifier] = true;
            }
        }
    }

    fclose($handle);

    return array_keys($identifiers);
}

/**
 * Get all dataset directories (subdirectories containing a meta.xml file).
 *
 * @param string $base_dir The base directory to scan.
 * @return array Sorted array of dataset directory paths.
 */
function get_dataset_dirs($base_dir)
{
    $dirs = array();
    $entries = scandir($base_dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $base_dir . '/' . $entry;
        if (is_dir($path) && file_exists($path . '/meta.xml')) {
            $dirs[] = $path;
        }
    }
    sort($dirs);
    return $dirs;
}

/**
 * Ensure a URL has a scheme. If no scheme is present, prepend https://.
 *
 * @param string $url The URL to normalise.
 * @return string The URL with a scheme.
 */
function ensure_scheme($url)
{
    $parsed = parse_url($url);
    if (!isset($parsed['scheme']) || $parsed['scheme'] === '') {
        return 'https://' . $url;
    }
    return $url;
}
