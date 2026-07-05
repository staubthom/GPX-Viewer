<?php
declare(strict_types=1);

require_once __DIR__ . '/gpx_metadata.php';

$gpxStructure = gpxviewer_load_structure(gpxviewer_get_gpx_root());

$totalStats = ['distance' => 0.0, 'elevation_gain' => 0, 'elevation_loss' => 0];
$totalFiles = 0;

if (isset($gpxStructure['folders'])) {
    foreach ($gpxStructure['folders'] as $folderData) {
        $totalStats['distance'] += $folderData['stats']['distance'];
        $totalStats['elevation_gain'] += $folderData['stats']['elevation_gain'];
        $totalStats['elevation_loss'] += $folderData['stats']['elevation_loss'];
        $totalFiles += count($folderData['files']);
    }
}

if (isset($gpxStructure['files'])) {
    foreach ($gpxStructure['files'] as $file) {
        $totalStats['distance'] += $file['stats']['distance'];
        $totalStats['elevation_gain'] += $file['stats']['elevation_gain'];
        $totalStats['elevation_loss'] += $file['stats']['elevation_loss'];
        $totalFiles++;
    }
}

$totalStats['distance'] = round($totalStats['distance'], 1);
$totalStats['elevation_gain'] = (int) round($totalStats['elevation_gain']);
$totalStats['elevation_loss'] = (int) round($totalStats['elevation_loss']);

$structureJson = json_encode(
    $gpxStructure === [] ? new stdClass() : $gpxStructure,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10151d">
    <title>Wanderwege Schweiz · GPX Viewer</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20100%20100'%3E%3Ctext%20y='.9em'%20font-size='90'%3E%E2%9B%B0%EF%B8%8F%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="css/app.css">
</head>
<body>
    <div id="map" aria-label="Karte"></div>

    <!-- Seitenpanel -->
    <aside id="panel" class="panel" aria-label="Tourenliste">
        <header class="panel-head">
            <div class="brand" role="button" tabindex="0" title="Menü schliessen">
                <div class="brand-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3l4 8 5-5 5 15H2L8 3z"/></svg>
                </div>
                <div class="brand-text">
                    <h1>Wanderwege Schweiz</h1>
                    <p>GPX Route Viewer</p>
                </div>
            </div>
            <div class="panel-head-actions">
                <button type="button" id="panel-pin" class="icon-btn pin-btn" aria-label="Menü fixieren" aria-pressed="false" title="Menü offen halten">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4h6l1 6 3 3H5l3-3 1-6z"/><path d="M12 13v8"/></svg>
                </button>
                <button type="button" id="panel-close" class="icon-btn" aria-label="Seitenleiste schliessen">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 6l-6 6 6 6"/></svg>
                </button>
            </div>
        </header>

        <div class="stats-row">
            <div class="stat">
                <span class="stat-value"><span id="stat-count"><?php echo $totalFiles; ?></span></span>
                <span class="stat-label">Touren</span>
            </div>
            <div class="stat">
                <span class="stat-value"><span id="stat-distance"><?php echo number_format($totalStats['distance'], 0, '.', "'"); ?></span><small> km</small></span>
                <span class="stat-label">Distanz</span>
            </div>
            <div class="stat">
                <span class="stat-value"><span id="stat-gain"><?php echo number_format($totalStats['elevation_gain'], 0, '.', "'"); ?></span><small> m</small></span>
                <span class="stat-label">Aufstieg</span>
            </div>
        </div>

        <div class="search-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" id="search" placeholder="Tour oder Gebiet suchen…" autocomplete="off" aria-label="Touren durchsuchen">
        </div>

        <div class="list-actions">
            <button type="button" id="show-all" class="chip-btn">Alle anzeigen</button>
            <button type="button" id="hide-all" class="chip-btn">Alle ausblenden</button>
            <button type="button" id="fit-all" class="chip-btn chip-btn-accent">Auf Karte zoomen</button>
        </div>

        <div id="track-list" class="track-list" role="list"></div>

        <footer class="panel-foot">
            <a href="admin.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                Verwaltung
            </a>
            <span>© <?php echo date('Y'); ?> Thomas Staub</span>
        </footer>
    </aside>

    <!-- Panel öffnen (mobil / eingeklappt) -->
    <button type="button" id="panel-open" class="fab" aria-label="Tourenliste öffnen" hidden>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
    </button>

    <!-- Karten-Steuerung -->
    <div class="map-controls" aria-label="Kartensteuerung">
        <div class="ctrl-group">
            <button type="button" id="zoom-in" class="ctrl-btn" aria-label="Hineinzoomen">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <button type="button" id="zoom-out" class="ctrl-btn" aria-label="Herauszoomen">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14"/></svg>
            </button>
        </div>
        <div class="ctrl-group">
            <button type="button" id="btn-layers" class="ctrl-btn" aria-label="Kartenebenen wählen" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l10 6-10 6L2 8l10-6z"/><path d="M2 14l10 6 10-6"/></svg>
            </button>
            <button type="button" id="btn-locate" class="ctrl-btn" aria-label="Eigener Standort">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3.5"/><path d="M12 2v3.5M12 18.5V22M2 12h3.5M18.5 12H22"/></svg>
            </button>
            <button type="button" id="btn-fit" class="ctrl-btn" aria-label="Alle Touren einpassen">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>
            </button>
            <button type="button" id="btn-theme" class="ctrl-btn" aria-label="Dunkelmodus umschalten">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
            </button>
        </div>
    </div>

    <!-- Ebenen-Auswahl -->
    <div id="layers-pop" class="layers-pop" hidden>
        <h3>Hintergrund</h3>
        <div class="layers-options" id="base-options">
            <label class="layer-option"><input type="radio" name="baselayer" value="farbe"><span>Karte farbig</span></label>
            <label class="layer-option"><input type="radio" name="baselayer" value="grau"><span>Karte grau</span></label>
            <label class="layer-option"><input type="radio" name="baselayer" value="luftbild"><span>Luftbild</span></label>
        </div>
        <h3>Ebenen</h3>
        <div class="layers-options">
            <label class="layer-option"><input type="checkbox" id="overlay-wanderwege"><span>Wanderwege (swisstopo)</span></label>
        </div>
    </div>

    <!-- Detail-Panel mit Höhenprofil -->
    <section id="detail" class="detail" hidden aria-label="Tour-Details">
        <div class="detail-head">
            <span class="detail-dot" id="detail-dot"></span>
            <div class="detail-titles">
                <h2 id="detail-title"></h2>
                <p id="detail-sub"></p>
            </div>
            <button type="button" id="detail-close" class="icon-btn" aria-label="Details schliessen">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="detail-chips" id="detail-chips"></div>
        <div class="profile-wrap" id="profile-wrap">
            <canvas id="profile-canvas"></canvas>
            <div class="profile-cursor" id="profile-cursor" hidden>
                <div class="cursor-line"></div>
                <div class="cursor-dot"></div>
                <div class="cursor-label"></div>
            </div>
            <div class="profile-empty" id="profile-empty" hidden>Keine Höhendaten in dieser GPX-Datei</div>
        </div>
    </section>

    <div id="toast" class="toast" hidden></div>

    <script>
        const GPX_STRUCTURE = <?php echo $structureJson; ?>;
    </script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="js/app.js"></script>
</body>
</html>
