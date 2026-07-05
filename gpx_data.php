<?php
declare(strict_types=1);

require_once __DIR__ . '/gpx_metadata.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $gpxRoot = gpxviewer_get_gpx_root();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'GPX-Verzeichnis nicht gefunden']);
    exit;
}

$relativePath = isset($_GET['file']) ? trim((string) $_GET['file']) : '';
$detailLevel = isset($_GET['detail']) ? strtolower(trim((string) $_GET['detail'])) : 'medium';

if (!in_array($detailLevel, ['overview', 'medium', 'full'], true)) {
    $detailLevel = 'medium';
}

if ($relativePath === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter "file" fehlt']);
    exit;
}

$normalizedPath = gpxviewer_normalize_relative_path($relativePath);
$fullPath = gpxviewer_resolve_gpx_file_path($normalizedPath);

if ($fullPath === null) {
    http_response_code(404);
    echo json_encode(['error' => 'GPX-Datei nicht gefunden']);
    exit;
}

$metadata = gpxviewer_get_file_metadata($normalizedPath);

function getCacheDirectory(): string
{
    return __DIR__ . '/cache/gpx-data';
}

function ensureCacheDirectoryExists(string $cacheDir): bool
{
    if (is_dir($cacheDir)) {
        return true;
    }

    return mkdir($cacheDir, 0755, true);
}

function buildCacheKey(string $relativePath, string $detailLevel, string $fullPath): string
{
    clearstatcache(true, $fullPath);
    $fileMTime = (string) filemtime($fullPath);
    $fileSize = (string) filesize($fullPath);

    return sha1($relativePath . '|' . $detailLevel . '|' . $fileMTime . '|' . $fileSize);
}

function readCachedPayload(string $cacheFile): ?string
{
    if (!is_file($cacheFile)) {
        return null;
    }

    $payload = file_get_contents($cacheFile);
    return $payload === false ? null : $payload;
}

function writeCachedPayload(string $cacheFile, string $payload): void
{
    $tempFile = $cacheFile . '.' . uniqid('tmp_', true);
    if (file_put_contents($tempFile, $payload, LOCK_EX) === false) {
        return;
    }

    rename($tempFile, $cacheFile);
}

function pruneObsoleteCacheFiles(string $cacheDir, string $relativePath, string $detailLevel, string $activeCacheFile): void
{
    $cachePrefix = sha1($relativePath . '|' . $detailLevel) . '_';
    $cacheFiles = glob($cacheDir . '/' . $cachePrefix . '*.json');
    if ($cacheFiles === false) {
        return;
    }

    foreach ($cacheFiles as $cacheFile) {
        if ($cacheFile !== $activeCacheFile && is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}

$cacheDir = getCacheDirectory();
$cacheEnabled = ensureCacheDirectoryExists($cacheDir);
$cachePrefix = sha1($normalizedPath . '|' . $detailLevel);
$cacheKey = buildCacheKey($normalizedPath, $detailLevel, $fullPath);
$cacheFile = $cacheDir . '/' . $cachePrefix . '_' . $cacheKey . '.json';

if ($cacheEnabled) {
    $cachedPayload = readCachedPayload($cacheFile);
    if ($cachedPayload !== null) {
        header('X-GPX-Cache: HIT');
        echo $cachedPayload;
        exit;
    }
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($fullPath);

if ($xml === false) {
    http_response_code(422);
    echo json_encode(['error' => 'GPX-Datei konnte nicht gelesen werden']);
    exit;
}

function getChildText(SimpleXMLElement $element, string $tagName): ?string
{
    $result = $element->xpath('./*[local-name() = "' . $tagName . '"]');
    if ($result === false || !isset($result[0])) {
        return null;
    }

    $value = trim((string) $result[0]);
    return $value === '' ? null : $value;
}

function parseFloatOrNull(?string $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (float) $value : null;
}

function updateBounds(array &$bounds, float $lat, float $lng): void
{
    if ($bounds['southWest'] === null) {
        $bounds['southWest'] = [$lat, $lng];
        $bounds['northEast'] = [$lat, $lng];
        return;
    }

    $bounds['southWest'][0] = min($bounds['southWest'][0], $lat);
    $bounds['southWest'][1] = min($bounds['southWest'][1], $lng);
    $bounds['northEast'][0] = max($bounds['northEast'][0], $lat);
    $bounds['northEast'][1] = max($bounds['northEast'][1], $lng);
}

function getSimplificationTolerance(string $detailLevel): float
{
    if ($detailLevel === 'overview') {
        return 0.0015;
    }

    if ($detailLevel === 'medium') {
        return 0.00035;
    }

    return 0.0;
}

function getPerpendicularDistance(array $point, array $start, array $end): float
{
    $dx = $end['lng'] - $start['lng'];
    $dy = $end['lat'] - $start['lat'];

    if ($dx === 0.0 && $dy === 0.0) {
        $deltaLng = $point['lng'] - $start['lng'];
        $deltaLat = $point['lat'] - $start['lat'];
        return sqrt(($deltaLng * $deltaLng) + ($deltaLat * $deltaLat));
    }

    $numerator = abs(($dy * $point['lng']) - ($dx * $point['lat']) + ($end['lng'] * $start['lat']) - ($end['lat'] * $start['lng']));
    $denominator = sqrt(($dx * $dx) + ($dy * $dy));

    return $denominator === 0.0 ? 0.0 : $numerator / $denominator;
}

function simplifySegmentRecursive(array $points, float $tolerance): array
{
    $pointCount = count($points);
    if ($pointCount <= 2 || $tolerance <= 0.0) {
        return $points;
    }

    $maxDistance = 0.0;
    $splitIndex = 0;
    $start = $points[0];
    $end = $points[$pointCount - 1];

    for ($index = 1; $index < $pointCount - 1; $index++) {
        $distance = getPerpendicularDistance($points[$index], $start, $end);
        if ($distance > $maxDistance) {
            $maxDistance = $distance;
            $splitIndex = $index;
        }
    }

    if ($maxDistance <= $tolerance) {
        return [$start, $end];
    }

    $left = simplifySegmentRecursive(array_slice($points, 0, $splitIndex + 1), $tolerance);
    $right = simplifySegmentRecursive(array_slice($points, $splitIndex), $tolerance);

    array_pop($left);
    return array_merge($left, $right);
}

function simplifySegment(array $points, float $tolerance): array
{
    if (count($points) <= 2 || $tolerance <= 0.0) {
        return $points;
    }

    return simplifySegmentRecursive($points, $tolerance);
}

$result = [
    'name' => $metadata['name'] ?? (getChildText($xml, 'name') ?? basename($fullPath)),
    'description' => $metadata['description'] ?? (getChildText($xml, 'desc') ?? ''),
    'detail' => $detailLevel,
    'tracks' => [],
    'waypoints' => [],
    'bounds' => $metadata['bounds'] ?? null,
];

$bounds = ['southWest' => null, 'northEast' => null];
$tolerance = getSimplificationTolerance($detailLevel);

$trackNodes = $xml->xpath('./*[local-name() = "trk"]') ?: [];
foreach ($trackNodes as $trackNode) {
    $trackData = [
        'name' => getChildText($trackNode, 'name') ?? 'Unbenannter Track',
        'description' => getChildText($trackNode, 'desc') ?? '',
        'segments' => [],
    ];

    $segmentNodes = $trackNode->xpath('./*[local-name() = "trkseg"]') ?: [];
    foreach ($segmentNodes as $segmentNode) {
        $segment = [];
        $pointNodes = $segmentNode->xpath('./*[local-name() = "trkpt"]') ?: [];

        foreach ($pointNodes as $pointNode) {
            $lat = isset($pointNode['lat']) ? (float) $pointNode['lat'] : null;
            $lng = isset($pointNode['lon']) ? (float) $pointNode['lon'] : null;

            if ($lat === null || $lng === null) {
                continue;
            }

            $point = [
                'lat' => $lat,
                'lng' => $lng,
                'elevation' => parseFloatOrNull(getChildText($pointNode, 'ele')),
                'time' => getChildText($pointNode, 'time'),
                'name' => getChildText($pointNode, 'name'),
            ];

            $segment[] = $point;
            updateBounds($bounds, $lat, $lng);
        }

        if ($segment !== []) {
            $trackData['segments'][] = simplifySegment($segment, $tolerance);
        }
    }

    if ($trackData['segments'] !== []) {
        $result['tracks'][] = $trackData;
    }
}

$waypointNodes = $xml->xpath('./*[local-name() = "wpt"]') ?: [];
foreach ($waypointNodes as $waypointNode) {
    $lat = isset($waypointNode['lat']) ? (float) $waypointNode['lat'] : null;
    $lng = isset($waypointNode['lon']) ? (float) $waypointNode['lon'] : null;

    if ($lat === null || $lng === null) {
        continue;
    }

    $result['waypoints'][] = [
        'lat' => $lat,
        'lng' => $lng,
        'name' => getChildText($waypointNode, 'name') ?? 'Waypoint',
        'description' => getChildText($waypointNode, 'desc') ?? '',
        'elevation' => parseFloatOrNull(getChildText($waypointNode, 'ele')),
        'symbol' => getChildText($waypointNode, 'sym'),
    ];

    updateBounds($bounds, $lat, $lng);
}

if ($bounds['southWest'] !== null && $bounds['northEast'] !== null) {
    $result['bounds'] = $bounds;
}

$payload = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($payload === false) {
    http_response_code(500);
    echo json_encode(['error' => 'GPX-Daten konnten nicht serialisiert werden']);
    exit;
}

if ($cacheEnabled) {
    writeCachedPayload($cacheFile, $payload);
    pruneObsoleteCacheFiles($cacheDir, $normalizedPath, $detailLevel, $cacheFile);
    header('X-GPX-Cache: MISS');
} else {
    header('X-GPX-Cache: BYPASS');
}

echo $payload;