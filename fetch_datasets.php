<?php

// DOIs to fetch
$dois = array(
    "10.15472/iyrpyc"
);

$output_dir = dirname(__FILE__);

foreach ($dois as $doi) {
    echo "Processing DOI: $doi\n";

    // Step 1: Resolve DOI and fetch HTML
    $url = "https://doi.org/" . $doi;
    echo "  Resolving $url ...\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; colombian-beetles/1.0)');

    $html = curl_exec($ch);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo "  Error resolving DOI: " . curl_error($ch) . "\n";
        curl_close($ch);
        continue;
    }
    curl_close($ch);

    if ($http_code != 200) {
        echo "  HTTP error: $http_code\n";
        continue;
    }

    echo "  Resolved to: $effective_url\n";

    // Step 2: Extract archive download link
    // Look for links like archive.do?r=...
    if (!preg_match('/<a\s+[^>]*href=["\']([^"\']*archive\.do\?[^"\']*)["\']/', $html, $matches)) {
        echo "  Error: could not find archive download link in HTML\n";
        continue;
    }

    $archive_url = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');

    // Resolve relative URL against the effective URL
    if (strpos($archive_url, 'http') !== 0) {
        $parsed = parse_url($effective_url);
        $base = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $base .= ':' . $parsed['port'];
        }
        if ($archive_url[0] === '/') {
            $archive_url = $base . $archive_url;
        } else {
            // Relative to current path
            $path = isset($parsed['path']) ? $parsed['path'] : '/';
            $dir = substr($path, 0, strrpos($path, '/') + 1);
            $archive_url = $base . $dir . $archive_url;
        }
    }

    echo "  Archive URL: $archive_url\n";

    // Extract dataset name from archive URL (the r= parameter)
    $dataset_name = null;
    $archive_query = parse_url($archive_url, PHP_URL_QUERY);
    if ($archive_query) {
        parse_str($archive_query, $archive_params);
        if (isset($archive_params['r'])) {
            $dataset_name = $archive_params['r'];
        }
    }
    if (!$dataset_name) {
        // Fallback to DOI-based name
        $dataset_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $doi);
    }

    $dataset_dir = $output_dir . '/' . $dataset_name;
    echo "  Dataset directory: $dataset_dir\n";

    // Step 3: Download the zip file
    $zip_filename = $output_dir . '/' . $dataset_name . '.zip';
    echo "  Downloading to $zip_filename ...\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $archive_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; colombian-beetles/1.0)');

    $zip_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo "  Error downloading archive: " . curl_error($ch) . "\n";
        curl_close($ch);
        continue;
    }
    curl_close($ch);

    if ($http_code != 200) {
        echo "  HTTP error downloading archive: $http_code\n";
        continue;
    }

    file_put_contents($zip_filename, $zip_data);
    echo "  Downloaded " . strlen($zip_data) . " bytes\n";

    // Step 4: Extract the zip file
    $zip = new ZipArchive();
    $result = $zip->open($zip_filename);
    if ($result !== true) {
        echo "  Error opening zip file (code $result)\n";
        continue;
    }

    echo "  Extracting " . $zip->numFiles . " files to $dataset_dir ...\n";
    if (!is_dir($dataset_dir)) {
        mkdir($dataset_dir, 0755, true);
    }
    $zip->extractTo($dataset_dir);
    $zip->close();

    // Step 5: Delete the zip file
    unlink($zip_filename);
    echo "  Cleaned up zip file\n";

    echo "  Done with DOI: $doi\n\n";
}

echo "All DOIs processed.\n";
