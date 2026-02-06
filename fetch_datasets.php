<?php

// DOIs to fetch (extracted from references.txt, "Listado de las especies" entries)
$dois = array(
    "10.15472/hxxkei",  // Anamorphidae
    "10.15472/3bphqb",  // Tenebrionidae
    "10.15472/wtd0tk",  // Dryophthorinae
    "10.15472/r77dxh",  // Disteniidae
    "10.15472/l1vnio",  // Cantharidae
    "10.15472/niicpm",  // Megalopodidae
    "10.15472/pgwexm",  // Haliplidae
    "10.15472/4ymj2e",  // Heteroceridae
    "10.15472/mew1hc",  // Ochodaeidae
    "10.15472/wqyqbs",  // Epimetopidae
    "10.15472/0msljd",  // Orphninae
    "10.15472/p9fc2m",  // Lucanidae
    "10.15472/g5u6tg",  // Buprestidae
    "10.15472/jdwfao",  // Entiminae
    "10.15472/qvrulo",  // Ceutorhynchinae
    "10.15472/tolans",  // Anthribidae
    "10.15472/iyrpyc",  // Brentidae
    "10.15472/mv3ql4",  // Cyclominae
    "10.15472/pisuan",  // Dermestidae
    "10.15472/suwoq6",  // Brachycerinae
    "10.15472/y3yios",  // Hyperinae
    "10.15472/pwyea3",  // Lixinae
    "10.15472/dleza0",  // Mesoptiliinae
    "10.15472/ompkp3",  // Ptiliidae
    "10.15472/yifvet",  // Conoderinae
    "10.15472/rwfj6h",  // Noteridae
    "10.15472/9mqxev",  // Cossoninae
    "10.15472/cnxkgm",  // Criocerinae
    "10.15472/l4lymo",  // Hydrophilidae
    "10.15472/bdcxbe",  // Georissidae
    "10.15472/3uxhlf",  // Hydrochidae
    "10.15472/33qho2",  // Hydraenidae
    "10.15472/m1c5nc",  // Dytiscidae
    "10.15472/aeddqk",  // Gyrinidae
    "10.15472/fmeri8",  // Limnichidae
    "10.15472/6mzbwp",  // Psephenidae
    "10.15472/5fywbh",  // Ptilodactylidae
    "10.15472/elddwn",  // Callirhipidae
    "10.15472/kxvmlk",  // Chelonariidae
    "10.15472/rsgvlo",  // Cneoglossidae
    "10.15472/xajvg0",  // Dryopidae
    "10.15472/8lqsij",  // Elmidae
    "10.15472/5w8sxt",  // Lutrochidae
    "10.15472/ii4qjk",  // Lampyridae
    "10.15472/abvxw3",  // Mordellidae
    "10.15472/9ixuud",  // Meloidae
    "10.15472/iftego",  // Lycidae
    "10.15472/off6un",  // Eumolpinae
    "10.15472/6xhtdd",  // Leiodidae
    "10.15472/bo97af",  // Aderidae
    "10.15472/vfcq3h",  // Archeocrypticidae
    "10.15472/3eiijl",  // Melandryidae
    "10.15472/8vgvor",  // Anthicidae
    "10.15472/bw5o1u",  // Scirtidae
    "10.15472/gest0x",  // Clambidae
    "10.15472/1bxorj",  // Phengodidae
    "10.15472/usxvea",  // Cryptocephalinae
    "10.15472/pzwjps",  // Lamprosomatinae
    "10.15472/tvuxwk",  // Bruchinae
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
