<?php
declare(strict_types=1);

// CLI-only boundary downloader. Uses a California county polygon layer,
// then identifies Shasta by inspecting returned feature attributes.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$dir = '/var/lib/shastaexchange/radio'; // Keep this identical to RADIO_STORAGE_DIR.
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "Could not create storage directory: $dir\n");
    exit(1);
}
if (!is_writable($dir)) {
    fwrite(STDERR, "Storage directory is not writable: $dir\n");
    exit(1);
}

$endpoint = 'https://services1.arcgis.com/jUJYIo9tSA7EHvfZ/ArcGIS/rest/services/California_County_Boundaries/FeatureServer/0/query';
$params = [
    'where' => '1=1',
    'outFields' => '*',
    'returnGeometry' => 'true',
    'outSR' => '4326',
    'f' => 'geojson',
];
$url = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

$context = stream_context_create([
    'http' => [
        'timeout' => 45,
        'user_agent' => 'ShastaExchangeRadio/1.2',
        'ignore_errors' => true,
    ],
]);
$data = @file_get_contents($url, false, $context);
if ($data === false) {
    fwrite(STDERR, "Could not contact the California GIS service. Check outbound HTTPS and PHP URL fopen settings.\n");
    exit(1);
}

$decoded = json_decode($data, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "GIS service response was not valid JSON. Existing boundary file was left unchanged.\n");
    exit(1);
}
if (isset($decoded['error'])) {
    $message = $decoded['error']['message'] ?? 'Unknown ArcGIS error';
    fwrite(STDERR, "GIS service error: $message. Existing boundary file was left unchanged.\n");
    exit(1);
}
if (!isset($decoded['features']) || !is_array($decoded['features'])) {
    fwrite(STDERR, "GIS response did not contain a GeoJSON features array. Existing boundary file was left unchanged.\n");
    exit(1);
}

$matches = [];
foreach ($decoded['features'] as $feature) {
    $properties = $feature['properties'] ?? [];
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($properties) || !is_array($geometry)) {
        continue;
    }

    $attributeText = strtolower(implode(' ', array_map(
        static fn($value): string => is_scalar($value) ? (string)$value : '',
        $properties
    )));

    $hasShastaName = preg_match('/(^|[^a-z])shasta([^a-z]|$)/i', $attributeText) === 1;
    $validGeometry = in_array($geometry['type'] ?? '', ['Polygon', 'MultiPolygon'], true)
        && !empty($geometry['coordinates']);

    if ($hasShastaName && $validGeometry) {
        $matches[] = $feature;
    }
}

if (count($matches) < 1) {
    $sample = [];
    foreach (array_slice($decoded['features'], 0, 3) as $feature) {
        $sample[] = $feature['properties'] ?? [];
    }
    fwrite(STDERR, "No Shasta polygon was found in the returned county features. Existing boundary file was left unchanged.\n");
    fwrite(STDERR, "Returned feature count: " . count($decoded['features']) . "\n");
    fwrite(STDERR, "Sample returned attributes: " . json_encode($sample) . "\n");
    exit(1);
}

// The statewide dataset can represent islands/parts as multiple features.
// Preserve all matching Shasta polygons in one FeatureCollection.
$outFeatures = [];
foreach ($matches as $feature) {
    $outFeatures[] = [
        'type' => 'Feature',
        'properties' => ['county' => 'Shasta', 'source' => 'California County Boundaries GIS layer'],
        'geometry' => $feature['geometry'],
    ];
}

$output = [
    'type' => 'FeatureCollection',
    'features' => $outFeatures,
];

$encoded = json_encode($output, JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
    fwrite(STDERR, "Could not encode boundary GeoJSON. Existing boundary file was left unchanged.\n");
    exit(1);
}

$target = $dir . '/shasta-county.geojson';
$temp = $target . '.tmp';
if (file_put_contents($temp, $encoded, LOCK_EX) === false) {
    @unlink($temp);
    fwrite(STDERR, "Could not write temporary boundary file. Existing boundary file was left unchanged.\n");
    exit(1);
}
if (!rename($temp, $target)) {
    @unlink($temp);
    fwrite(STDERR, "Could not install new boundary file. Existing boundary file was left unchanged.\n");
    exit(1);
}

echo "SUCCESS: Installed Shasta County boundary at $target\n";
echo "Matching polygon feature(s): " . count($outFeatures) . "\n";
echo "Coordinate system: WGS84 (EPSG:4326)\n";
