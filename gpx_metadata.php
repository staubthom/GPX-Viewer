<?php
declare(strict_types=1);

function gpxviewer_get_gpx_root(): string
{
    static $gpxRoot = null;

    if ($gpxRoot === null) {
        $resolvedRoot = realpath(__DIR__ . '/gpx-files');
        if ($resolvedRoot === false) {
            throw new RuntimeException('GPX-Verzeichnis nicht gefunden');
        }

        $gpxRoot = $resolvedRoot;
    }

    return $gpxRoot;
}

function gpxviewer_get_cache_directory(string $cacheName): string
{
    return __DIR__ . '/cache/' . $cacheName;
}

function gpxviewer_ensure_directory(string $directory): bool
{
    if (is_dir($directory)) {
        return true;
    }

    return mkdir($directory, 0755, true);
}

function gpxviewer_write_json_file(string $filePath, array $payload): bool
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($filePath, $json, LOCK_EX) !== false;
}

function gpxviewer_normalize_relative_path(string $relativePath): string
{
    $normalized = str_replace(['\\', "\0"], ['/', ''], trim($relativePath));
    return ltrim($normalized, '/');
}

function gpxviewer_resolve_gpx_file_path(string $relativePath): ?string
{
    $normalizedPath = gpxviewer_normalize_relative_path($relativePath);
    if ($normalizedPath === '') {
        return null;
    }

    $gpxRoot = gpxviewer_get_gpx_root();
    $fullPath = realpath($gpxRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath));
    if ($fullPath === false || strncmp($fullPath, $gpxRoot, strlen($gpxRoot)) !== 0 || !is_file($fullPath)) {
        return null;
    }

    if (strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION)) !== 'gpx') {
        return null;
    }

    return $fullPath;
}

function gpxviewer_get_metadata_cache_file(string $relativePath, string $fullPath): array
{
    $cacheDir = gpxviewer_get_cache_directory('metadata');
    $cacheEnabled = gpxviewer_ensure_directory($cacheDir);
    $normalizedPath = gpxviewer_normalize_relative_path($relativePath);

    clearstatcache(true, $fullPath);
    $cachePrefix = sha1($normalizedPath);
    $cacheKey = sha1($normalizedPath . '|' . (string) filemtime($fullPath) . '|' . (string) filesize($fullPath));

    return [
        'enabled' => $cacheEnabled,
        'directory' => $cacheDir,
        'file' => $cacheDir . '/' . $cachePrefix . '_' . $cacheKey . '.json',
        'prefix' => $cachePrefix . '_',
    ];
}

function gpxviewer_read_cached_metadata(string $cacheFile): ?array
{
    if (!is_file($cacheFile)) {
        return null;
    }

    $payload = file_get_contents($cacheFile);
    if ($payload === false) {
        return null;
    }

    $decoded = json_decode($payload, true);
    return is_array($decoded) ? $decoded : null;
}

function gpxviewer_write_cached_metadata(string $cacheFile, array $metadata): void
{
    $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    $tempFile = $cacheFile . '.' . uniqid('tmp_', true);
    if (file_put_contents($tempFile, $json, LOCK_EX) === false) {
        return;
    }

    rename($tempFile, $cacheFile);
}

function gpxviewer_prune_cache_files(string $cacheDir, string $prefix, string $activeCacheFile): void
{
    $cacheFiles = glob($cacheDir . '/' . $prefix . '*.json');
    if ($cacheFiles === false) {
        return;
    }

    foreach ($cacheFiles as $cacheFile) {
        if ($cacheFile !== $activeCacheFile && is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}

function gpxviewer_update_bounds(array &$bounds, float $lat, float $lng): void
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

function gpxviewer_calculate_distance(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

function gpxviewer_get_child_text(SimpleXMLElement $element, string $tagName): ?string
{
    $result = $element->xpath('./*[local-name() = "' . $tagName . '"]');
    if ($result === false || !isset($result[0])) {
        return null;
    }

    $value = trim((string) $result[0]);
    return $value === '' ? null : $value;
}

function gpxviewer_parse_file_metadata(string $relativePath, string $fullPath): array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($fullPath);

    if ($xml === false) {
        return [
            'name' => basename($fullPath),
            'description' => '',
            'distance' => 0.0,
            'elevation_gain' => 0,
            'elevation_loss' => 0,
            'bounds' => null,
        ];
    }

    $totalDistance = 0.0;
    $totalElevationGain = 0.0;
    $totalElevationLoss = 0.0;
    $bounds = ['southWest' => null, 'northEast' => null];

    $trackNodes = $xml->xpath('./*[local-name() = "trk"]') ?: [];
    foreach ($trackNodes as $trackNode) {
        $segmentNodes = $trackNode->xpath('./*[local-name() = "trkseg"]') ?: [];
        foreach ($segmentNodes as $segmentNode) {
            $points = [];
            $pointNodes = $segmentNode->xpath('./*[local-name() = "trkpt"]') ?: [];

            foreach ($pointNodes as $pointNode) {
                if (!isset($pointNode['lat'], $pointNode['lon'])) {
                    continue;
                }

                $lat = (float) $pointNode['lat'];
                $lng = (float) $pointNode['lon'];
                $elevation = gpxviewer_get_child_text($pointNode, 'ele');
                $ele = $elevation !== null && is_numeric($elevation) ? (float) $elevation : null;

                gpxviewer_update_bounds($bounds, $lat, $lng);
                $points[] = ['lat' => $lat, 'lng' => $lng, 'ele' => $ele];
            }

            $pointCount = count($points);
            for ($index = 1; $index < $pointCount; $index++) {
                $previous = $points[$index - 1];
                $current = $points[$index];

                $totalDistance += gpxviewer_calculate_distance(
                    $previous['lat'],
                    $previous['lng'],
                    $current['lat'],
                    $current['lng']
                );

                if ($previous['ele'] !== null && $current['ele'] !== null) {
                    $elevationDiff = $current['ele'] - $previous['ele'];
                    if ($elevationDiff > 0) {
                        $totalElevationGain += $elevationDiff;
                    } else {
                        $totalElevationLoss += abs($elevationDiff);
                    }
                }
            }
        }
    }

    $waypointNodes = $xml->xpath('./*[local-name() = "wpt"]') ?: [];
    foreach ($waypointNodes as $waypointNode) {
        if (!isset($waypointNode['lat'], $waypointNode['lon'])) {
            continue;
        }

        gpxviewer_update_bounds($bounds, (float) $waypointNode['lat'], (float) $waypointNode['lon']);
    }

    return [
        'name' => gpxviewer_get_child_text($xml, 'name') ?? basename($fullPath),
        'description' => gpxviewer_get_child_text($xml, 'desc') ?? '',
        'distance' => round($totalDistance / 1000, 1),
        'elevation_gain' => (int) round($totalElevationGain),
        'elevation_loss' => (int) round($totalElevationLoss),
        'bounds' => $bounds['southWest'] === null ? null : $bounds,
    ];
}

function gpxviewer_get_file_metadata(string $relativePath): array
{
    $normalizedPath = gpxviewer_normalize_relative_path($relativePath);
    $fullPath = gpxviewer_resolve_gpx_file_path($normalizedPath);

    if ($fullPath === null) {
        return [
            'name' => basename($normalizedPath),
            'description' => '',
            'distance' => 0.0,
            'elevation_gain' => 0,
            'elevation_loss' => 0,
            'bounds' => null,
        ];
    }

    $cacheConfig = gpxviewer_get_metadata_cache_file($normalizedPath, $fullPath);
    if ($cacheConfig['enabled']) {
        $cachedMetadata = gpxviewer_read_cached_metadata($cacheConfig['file']);
        if ($cachedMetadata !== null) {
            return $cachedMetadata;
        }
    }

    $metadata = gpxviewer_parse_file_metadata($normalizedPath, $fullPath);

    if ($cacheConfig['enabled']) {
        gpxviewer_write_cached_metadata($cacheConfig['file'], $metadata);
        gpxviewer_prune_cache_files($cacheConfig['directory'], $cacheConfig['prefix'], $cacheConfig['file']);
    }

    return $metadata;
}

function gpxviewer_load_structure(string $dir, string $relativePath = ''): array
{
    $structure = [];
    if (!is_dir($dir)) {
        return $structure;
    }

    $items = scandir($dir);
    if ($items === false) {
        return $structure;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullPath = $dir . '/' . $item;
        $relativeItemPath = $relativePath !== '' ? $relativePath . '/' . $item : $item;

        if (is_dir($fullPath)) {
            $configFile = $fullPath . '/folder.json';
            $config = [];
            if (is_file($configFile)) {
                $configContent = file_get_contents($configFile);
                $config = json_decode($configContent ?: '', true) ?: [];
            }

            $structure['folders'][$item] = [
                'name' => $config['name'] ?? $item,
                'description' => $config['description'] ?? '',
                'color' => $config['color'] ?? '#3498db',
                'date' => $config['date'] ?? '',
                'files' => [],
                'path' => $relativeItemPath,
                'config' => $config,
                'stats' => ['distance' => 0.0, 'elevation_gain' => 0, 'elevation_loss' => 0],
            ];

            $subItems = scandir($fullPath);
            if ($subItems === false) {
                continue;
            }

            foreach ($subItems as $subItem) {
                if (pathinfo($subItem, PATHINFO_EXTENSION) !== 'gpx') {
                    continue;
                }

                $gpxPath = str_replace('\\', '/', $relativeItemPath . '/' . $subItem);
                $fileStats = gpxviewer_get_file_metadata($gpxPath);

                $structure['folders'][$item]['files'][] = [
                    'path' => $gpxPath,
                    'name' => $subItem,
                    'stats' => $fileStats,
                ];

                $structure['folders'][$item]['stats']['distance'] += $fileStats['distance'];
                $structure['folders'][$item]['stats']['elevation_gain'] += $fileStats['elevation_gain'];
                $structure['folders'][$item]['stats']['elevation_loss'] += $fileStats['elevation_loss'];
            }

            $structure['folders'][$item]['stats']['distance'] = round($structure['folders'][$item]['stats']['distance'], 1);
            $structure['folders'][$item]['stats']['elevation_gain'] = (int) round($structure['folders'][$item]['stats']['elevation_gain']);
            $structure['folders'][$item]['stats']['elevation_loss'] = (int) round($structure['folders'][$item]['stats']['elevation_loss']);
            continue;
        }

        if (pathinfo($item, PATHINFO_EXTENSION) !== 'gpx') {
            continue;
        }

        $fileStats = gpxviewer_get_file_metadata($relativeItemPath);
        $structure['files'][] = [
            'path' => $relativeItemPath,
            'name' => $item,
            'stats' => $fileStats,
        ];
    }

    return $structure;
}

function gpxviewer_clear_cache_directory(string $cacheName): void
{
    $cacheDir = gpxviewer_get_cache_directory($cacheName);
    if (!is_dir($cacheDir)) {
        return;
    }

    $cacheFiles = glob($cacheDir . '/*.json');
    if ($cacheFiles === false) {
        return;
    }

    foreach ($cacheFiles as $cacheFile) {
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}

function gpxviewer_clear_all_caches(): void
{
    gpxviewer_clear_cache_directory('metadata');
    gpxviewer_clear_cache_directory('gpx-data');
}
