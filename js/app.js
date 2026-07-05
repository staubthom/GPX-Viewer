/**
 * Wanderwege Schweiz – GPX Viewer
 * Vollbild-Karte, Track-Auswahl, Höhenprofil, Layer-Wechsler, Dark Mode.
 */
'use strict';

const STORE_KEY = 'gpx-viewer-v2';

const BASE_LAYERS = {
    farbe: {
        label: 'Karte farbig',
        url: 'https://wmts20.geo.admin.ch/1.0.0/ch.swisstopo.pixelkarte-farbe/default/current/3857/{z}/{x}/{y}.jpeg'
    },
    grau: {
        label: 'Karte grau',
        url: 'https://wmts20.geo.admin.ch/1.0.0/ch.swisstopo.pixelkarte-grau/default/current/3857/{z}/{x}/{y}.jpeg'
    },
    luftbild: {
        label: 'Luftbild',
        url: 'https://wmts20.geo.admin.ch/1.0.0/ch.swisstopo.swissimage/default/current/3857/{z}/{x}/{y}.jpeg'
    }
};

const OVERLAY_WANDERWEGE_URL = 'https://wmts20.geo.admin.ch/1.0.0/ch.swisstopo.swisstlm3d-wanderwege/default/current/3857/{z}/{x}/{y}.png';

const FALLBACK_COLORS = [
    '#e74c3c', '#3498db', '#2ecc71', '#f39c12',
    '#9b59b6', '#1abc9c', '#e67e22', '#34495e',
    '#f1c40f', '#e91e63', '#00bcd4', '#8bc34a'
];

class TrailApp {
    constructor(structure) {
        this.structure = structure && typeof structure === 'object' ? structure : {};
        this.catalog = new Map();      // path -> { name, fileName, folderKey, folderName, color, stats, searchText }
        this.loaded = new Map();       // path -> { detail, data }
        this.loading = new Map();      // "path|detail" -> Promise
        this.requested = new Map();    // path -> zuletzt angefordertes Detail-Level
        this.trackLayers = new Map();  // path -> Leaflet-Layer[]
        this.activePath = null;
        this.activeLayers = [];        // Start-/Endmarker der aktiven Tour
        this.profilePoints = null;
        this.hoverMarker = null;
        this.locateMarker = null;
        this.mobileQuery = window.matchMedia('(max-width: 820px)');

        this.store = this.readStore();
        this.buildCatalog();
        this.applyTheme(this.store.theme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
        this.initMap();
        this.renderTrackList();
        this.bindUI();
        this.applyPanelState(this.mobileQuery.matches);
        this.updateStats();
        this.loadInitialTracks();
    }

    /* ---------------------------------------------------------- Storage */

    readStore() {
        try {
            const raw = localStorage.getItem(STORE_KEY);
            const data = raw ? JSON.parse(raw) : {};
            return {
                theme: data.theme || null,
                base: BASE_LAYERS[data.base] ? data.base : 'farbe',
                wanderwege: data.wanderwege === true,
                pinned: data.pinned === true,
                off: Array.isArray(data.off) ? data.off : [],
                collapsed: Array.isArray(data.collapsed) ? data.collapsed : null
            };
        } catch (e) {
            return { theme: null, base: 'farbe', wanderwege: false, pinned: false, off: [], collapsed: null };
        }
    }

    saveStore() {
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify(this.store));
        } catch (e) { /* Speicher voll oder blockiert – ignorieren */ }
    }

    /* ---------------------------------------------------------- Katalog */

    hashColor(path) {
        let hash = 0;
        for (let i = 0; i < path.length; i++) {
            hash = path.charCodeAt(i) + ((hash << 5) - hash);
        }
        return FALLBACK_COLORS[Math.abs(hash) % FALLBACK_COLORS.length];
    }

    displayName(file) {
        const raw = (file.stats && file.stats.name) || file.name;
        return raw.replace(/\.gpx$/i, '');
    }

    buildCatalog() {
        const folders = this.structure.folders || {};
        for (const [folderKey, folder] of Object.entries(folders)) {
            for (const file of folder.files || []) {
                const displayName = this.displayName(file);
                this.catalog.set(file.path, {
                    name: displayName,
                    fileName: file.name,
                    folderKey,
                    folderName: folder.name,
                    folderDate: folder.date || '',
                    color: folder.color || this.hashColor(file.path),
                    stats: file.stats || {},
                    searchText: (displayName + ' ' + file.name + ' ' + folder.name).toLowerCase()
                });
            }
        }
        for (const file of this.structure.files || []) {
            const displayName = this.displayName(file);
            this.catalog.set(file.path, {
                name: displayName,
                fileName: file.name,
                folderKey: '__loose__',
                folderName: 'Einzelne Touren',
                folderDate: '',
                color: this.hashColor(file.path),
                stats: file.stats || {},
                searchText: (displayName + ' ' + file.name).toLowerCase()
            });
        }
    }

    isSelected(path) {
        return !this.store.off.includes(path);
    }

    setSelected(path, selected) {
        const idx = this.store.off.indexOf(path);
        if (selected && idx !== -1) {
            this.store.off.splice(idx, 1);
        } else if (!selected && idx === -1) {
            this.store.off.push(path);
        }
        this.saveStore();
        this.updateStats();
    }

    /**
     * Aktualisiert die Kopf-Statistik anhand der aktuell eingeschalteten Touren
     */
    updateStats() {
        let count = 0, distance = 0, gain = 0;
        for (const [path, entry] of this.catalog) {
            if (!this.isSelected(path)) continue;
            count++;
            distance += entry.stats.distance || 0;
            gain += entry.stats.elevation_gain || 0;
        }

        const countEl = document.getElementById('stat-count');
        const distEl = document.getElementById('stat-distance');
        const gainEl = document.getElementById('stat-gain');
        if (!countEl || !distEl || !gainEl) return;

        const nf = new Intl.NumberFormat('de-CH');
        countEl.textContent = String(count);
        distEl.textContent = distance < 100 ? distance.toFixed(1) : nf.format(Math.round(distance));
        gainEl.textContent = nf.format(Math.round(gain));
    }

    /* ---------------------------------------------------------- Karte */

    initMap() {
        this.map = L.map('map', {
            zoomControl: false,
            minZoom: 6,
            maxZoom: 18
        });
        this.map.setView([46.80121, 8.22651], 8);

        this.baseLayer = L.tileLayer(BASE_LAYERS[this.store.base].url, {
            attribution: '© <a href="https://www.swisstopo.admin.ch/" target="_blank" rel="noopener">swisstopo</a>',
            maxZoom: 18
        }).addTo(this.map);

        this.wanderwegeLayer = L.tileLayer(OVERLAY_WANDERWEGE_URL, { maxZoom: 18, opacity: 0.85 });
        if (this.store.wanderwege) {
            this.wanderwegeLayer.addTo(this.map);
        }

        L.control.scale({ imperial: false, position: 'bottomright' }).addTo(this.map);

        this.map.on('zoomend moveend', () => this.refreshVisibleTracks());
        this.map.on('click', () => {
            this.closeLayersPop();
            // Mobil: Tippen auf die Karte schliesst das Menü, ausser es ist fixiert
            if (this.mobileQuery.matches && !this.store.pinned
                && !document.body.classList.contains('panel-collapsed')) {
                this.applyPanelState(true);
            }
        });
    }

    setBaseLayer(key) {
        if (!BASE_LAYERS[key] || key === this.store.base) return;
        this.store.base = key;
        this.saveStore();
        this.baseLayer.setUrl(BASE_LAYERS[key].url);
    }

    setWanderwege(enabled) {
        this.store.wanderwege = enabled;
        this.saveStore();
        if (enabled) {
            this.wanderwegeLayer.addTo(this.map);
        } else {
            this.map.removeLayer(this.wanderwegeLayer);
        }
    }

    /* ---------------------------------------------------------- Theme */

    applyTheme(theme) {
        this.store.theme = theme;
        document.documentElement.dataset.theme = theme;
        this.saveStore();
    }

    /* ---------------------------------------------------------- Sidebar */

    renderTrackList() {
        const list = document.getElementById('track-list');
        list.textContent = '';

        const groups = new Map(); // folderKey -> { meta, paths }
        for (const [path, entry] of this.catalog) {
            if (!groups.has(entry.folderKey)) {
                groups.set(entry.folderKey, { meta: entry, paths: [] });
            }
            groups.get(entry.folderKey).paths.push(path);
        }

        const collapsedDefault = this.store.collapsed === null;
        for (const [folderKey, group] of groups) {
            const folderStats = this.folderStats(group.paths);
            const section = document.createElement('section');
            section.className = 'folder';
            section.dataset.folder = folderKey;
            const isCollapsed = collapsedDefault ? true : this.store.collapsed.includes(folderKey);
            if (isCollapsed) section.classList.add('collapsed');

            const head = document.createElement('div');
            head.className = 'folder-head';
            head.setAttribute('role', 'button');
            head.tabIndex = 0;

            const chevron = document.createElement('span');
            chevron.className = 'chevron';
            chevron.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>';

            const dot = document.createElement('span');
            dot.className = 'color-dot';
            dot.style.background = group.meta.color;

            const info = document.createElement('div');
            info.className = 'folder-info';
            const title = document.createElement('span');
            title.className = 'folder-name';
            title.textContent = group.meta.folderName;
            const meta = document.createElement('span');
            meta.className = 'folder-meta';
            meta.textContent = `${group.paths.length} Touren · ${folderStats.distance.toFixed(0)} km · ↑ ${folderStats.gain} m`;
            info.append(title, meta);

            const toggle = this.createSwitch(true, (checked) => {
                for (const path of group.paths) {
                    this.setSelected(path, checked);
                    const row = list.querySelector(`.file-row[data-path="${CSS.escape(path)}"]`);
                    if (row) row.querySelector('input').checked = checked;
                    if (checked) {
                        this.ensureTrackVisible(path);
                    } else {
                        this.hideTrack(path);
                    }
                }
            });
            toggle.classList.add('folder-switch');

            head.append(chevron, dot, info, toggle);
            head.addEventListener('click', (e) => {
                if (e.target.closest('.switch')) return;
                section.classList.toggle('collapsed');
                this.persistCollapsed();
            });
            head.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    section.classList.toggle('collapsed');
                    this.persistCollapsed();
                }
            });

            const body = document.createElement('div');
            body.className = 'folder-body';

            for (const path of group.paths) {
                body.appendChild(this.createFileRow(path));
            }

            section.append(head, body);
            list.appendChild(section);
        }

        this.persistCollapsed();
    }

    persistCollapsed() {
        this.store.collapsed = Array.from(document.querySelectorAll('.folder.collapsed'))
            .map(el => el.dataset.folder);
        this.saveStore();
    }

    folderStats(paths) {
        let distance = 0, gain = 0;
        for (const path of paths) {
            const stats = this.catalog.get(path).stats;
            distance += stats.distance || 0;
            gain += stats.elevation_gain || 0;
        }
        return { distance, gain };
    }

    createSwitch(checked, onChange) {
        const label = document.createElement('label');
        label.className = 'switch';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = checked;
        const slider = document.createElement('span');
        slider.className = 'slider';
        label.append(input, slider);
        input.addEventListener('change', () => onChange(input.checked));
        label.addEventListener('click', (e) => e.stopPropagation());
        return label;
    }

    createFileRow(path) {
        const entry = this.catalog.get(path);
        const row = document.createElement('div');
        row.className = 'file-row';
        row.dataset.path = path;
        row.dataset.search = entry.searchText;
        row.setAttribute('role', 'listitem');

        const main = document.createElement('div');
        main.className = 'file-main';
        const name = document.createElement('span');
        name.className = 'file-name';
        name.textContent = entry.name;
        const meta = document.createElement('span');
        meta.className = 'file-meta';
        const s = entry.stats;
        const parts = [];
        if (s.distance) parts.push(`${s.distance} km`);
        if (s.elevation_gain) parts.push(`↑ ${s.elevation_gain} m`);
        if (s.elevation_loss) parts.push(`↓ ${s.elevation_loss} m`);
        meta.textContent = parts.join(' · ') || '–';
        main.append(name, meta);
        main.title = entry.fileName;

        const toggle = this.createSwitch(this.isSelected(path), (checked) => {
            this.setSelected(path, checked);
            if (checked) {
                this.ensureTrackVisible(path);
            } else {
                this.hideTrack(path);
                if (this.activePath === path) this.deselectTrack();
            }
            this.syncFolderSwitch(path);
        });

        main.addEventListener('click', () => this.focusTrack(path));

        row.append(main, toggle);
        return row;
    }

    syncFolderSwitch(path) {
        const entry = this.catalog.get(path);
        const section = document.querySelector(`.folder[data-folder="${CSS.escape(entry.folderKey)}"]`);
        if (!section) return;
        const fileInputs = section.querySelectorAll('.file-row .switch input');
        const anyOn = Array.from(fileInputs).some(i => i.checked);
        const folderInput = section.querySelector('.folder-switch input');
        if (folderInput) folderInput.checked = anyOn;
    }

    /* ---------------------------------------------------------- UI-Events */

    bindUI() {
        document.getElementById('zoom-in').addEventListener('click', () => this.map.zoomIn());
        document.getElementById('zoom-out').addEventListener('click', () => this.map.zoomOut());
        document.getElementById('btn-fit').addEventListener('click', () => this.fitAll());
        document.getElementById('fit-all').addEventListener('click', () => this.fitAll());
        document.getElementById('btn-locate').addEventListener('click', () => this.locateMe());
        document.getElementById('btn-theme').addEventListener('click', () => {
            this.applyTheme(this.store.theme === 'dark' ? 'light' : 'dark');
        });

        // Panel öffnen/schliessen
        document.getElementById('panel-close').addEventListener('click', () => this.applyPanelState(true));
        document.getElementById('panel-open').addEventListener('click', () => this.applyPanelState(false));
        const brand = document.querySelector('.panel .brand');
        brand.addEventListener('click', () => this.applyPanelState(true));
        brand.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.applyPanelState(true);
            }
        });
        this.mobileQuery.addEventListener('change', (e) => this.applyPanelState(e.matches));

        // Menü fixieren (mobil)
        const pinBtn = document.getElementById('panel-pin');
        const syncPin = () => {
            pinBtn.classList.toggle('pinned', this.store.pinned);
            pinBtn.setAttribute('aria-pressed', String(this.store.pinned));
            pinBtn.title = this.store.pinned ? 'Menü nicht mehr fixieren' : 'Menü offen halten';
        };
        syncPin();
        pinBtn.addEventListener('click', () => {
            this.store.pinned = !this.store.pinned;
            this.saveStore();
            syncPin();
            this.toast(this.store.pinned ? 'Menü bleibt geöffnet' : 'Menü schliesst beim Tippen auf die Karte');
        });

        // Layer-Popover
        const layersBtn = document.getElementById('btn-layers');
        layersBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const pop = document.getElementById('layers-pop');
            const open = pop.hidden;
            pop.hidden = !open;
            layersBtn.setAttribute('aria-expanded', String(open));
        });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#layers-pop') && !e.target.closest('#btn-layers')) {
                this.closeLayersPop();
            }
        });

        const baseRadio = document.querySelector(`#base-options input[value="${this.store.base}"]`);
        if (baseRadio) baseRadio.checked = true;
        document.querySelectorAll('#base-options input').forEach(input => {
            input.addEventListener('change', () => this.setBaseLayer(input.value));
        });
        const overlayInput = document.getElementById('overlay-wanderwege');
        overlayInput.checked = this.store.wanderwege;
        overlayInput.addEventListener('change', () => this.setWanderwege(overlayInput.checked));

        // Alle an/aus
        document.getElementById('show-all').addEventListener('click', () => this.setAllTracks(true));
        document.getElementById('hide-all').addEventListener('click', () => this.setAllTracks(false));

        // Suche
        const search = document.getElementById('search');
        search.addEventListener('input', () => this.applySearch(search.value));

        // Detail schliessen
        document.getElementById('detail-close').addEventListener('click', () => this.deselectTrack());
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.deselectTrack();
                this.closeLayersPop();
            }
        });

        // Höhenprofil-Interaktion
        const wrap = document.getElementById('profile-wrap');
        wrap.addEventListener('mousemove', (e) => this.onProfileHover(e.clientX));
        wrap.addEventListener('touchmove', (e) => {
            if (e.touches.length === 1) this.onProfileHover(e.touches[0].clientX);
        }, { passive: true });
        wrap.addEventListener('mouseleave', () => this.hideProfileCursor());
        wrap.addEventListener('touchend', () => this.hideProfileCursor());

        window.addEventListener('resize', () => {
            clearTimeout(this.resizeTimer);
            this.resizeTimer = setTimeout(() => {
                if (this.profilePoints) this.drawProfile();
            }, 150);
        });
    }

    closeLayersPop() {
        const pop = document.getElementById('layers-pop');
        pop.hidden = true;
        document.getElementById('btn-layers').setAttribute('aria-expanded', 'false');
    }

    applyPanelState(collapsed) {
        document.body.classList.toggle('panel-collapsed', collapsed);
        document.getElementById('panel-open').hidden = !collapsed;
        window.setTimeout(() => this.map.invalidateSize(), 250);
    }

    applySearch(query) {
        const q = query.trim().toLowerCase();
        document.querySelectorAll('.folder').forEach(section => {
            let visibleCount = 0;
            section.querySelectorAll('.file-row').forEach(row => {
                const match = q === '' || row.dataset.search.includes(q);
                row.classList.toggle('hidden', !match);
                if (match) visibleCount++;
            });
            section.classList.toggle('hidden', visibleCount === 0);
            if (q !== '') {
                section.classList.toggle('collapsed', false);
            }
        });
        if (q === '') {
            // Zusammengeklappten Zustand wiederherstellen
            document.querySelectorAll('.folder').forEach(section => {
                const wasCollapsed = (this.store.collapsed || []).includes(section.dataset.folder);
                section.classList.toggle('collapsed', wasCollapsed);
            });
        }
    }

    setAllTracks(visible) {
        for (const path of this.catalog.keys()) {
            this.setSelected(path, visible);
            if (visible) {
                this.ensureTrackVisible(path);
            } else {
                this.hideTrack(path);
            }
        }
        document.querySelectorAll('.track-list .switch input').forEach(i => { i.checked = visible; });
        if (!visible) this.deselectTrack();
    }

    /* ---------------------------------------------------------- Laden & Anzeigen */

    detailForZoom(zoom) {
        if (zoom <= 8) return 'overview';
        if (zoom <= 11) return 'medium';
        return 'full';
    }

    async loadInitialTracks() {
        const selected = Array.from(this.catalog.keys()).filter(p => this.isSelected(p));
        if (selected.length === 0) return;
        await Promise.allSettled(selected.map(path => this.ensureTrackVisible(path)));
        this.fitAll();
    }

    refreshVisibleTracks() {
        const wanted = this.detailForZoom(this.map.getZoom());
        for (const path of this.catalog.keys()) {
            if (!this.isSelected(path)) continue;
            const entry = this.loaded.get(path);
            // Aktive Tour bleibt immer auf "full"
            const target = this.activePath === path ? 'full' : wanted;
            if (entry && entry.detail === target) continue;
            if (!this.isInView(path)) continue;
            this.ensureTrackVisible(path);
        }
    }

    isInView(path) {
        const stats = this.catalog.get(path)?.stats;
        const bounds = stats?.bounds || this.loaded.get(path)?.data?.bounds;
        if (!bounds?.southWest || !bounds?.northEast) return true;
        return this.map.getBounds().intersects(L.latLngBounds(bounds.southWest, bounds.northEast));
    }

    async ensureTrackVisible(path) {
        try {
            const detail = this.activePath === path ? 'full' : this.detailForZoom(this.map.getZoom());
            await this.ensureTrackLoaded(path, detail);
            if (this.isSelected(path)) {
                this.renderTrack(path);
            }
        } catch (error) {
            console.error(`Fehler beim Laden von ${path}:`, error);
            this.toast(`Fehler beim Laden: ${this.catalog.get(path)?.name || path}`);
        }
    }

    async ensureTrackLoaded(path, detail) {
        const entry = this.loaded.get(path);
        if (entry && entry.detail === detail) return entry.data;

        const key = `${path}|${detail}`;
        if (this.loading.has(key)) return this.loading.get(key);

        this.requested.set(path, detail);

        const request = (async () => {
            const response = await fetch(`gpx_data.php?file=${encodeURIComponent(path)}&detail=${encodeURIComponent(detail)}`);
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }
            if (this.requested.get(path) === detail) {
                this.loaded.set(path, { detail, data });
            }
            return data;
        })();

        this.loading.set(key, request);
        try {
            return await request;
        } finally {
            this.loading.delete(key);
        }
    }

    renderTrack(path) {
        const entry = this.loaded.get(path);
        if (!entry) return;
        this.removeTrackLayers(path);

        const catalogEntry = this.catalog.get(path);
        const color = catalogEntry?.color || '#3498db';
        const isActive = this.activePath === path;
        const dimmed = this.activePath !== null && !isActive;
        const layers = [];

        for (const track of entry.data.tracks || []) {
            for (const segment of track.segments || []) {
                if (segment.length < 2) continue;
                const latlngs = segment.map(p => [p.lat, p.lng]);

                const casing = L.polyline(latlngs, {
                    color: '#ffffff',
                    weight: isActive ? 10 : 8,
                    opacity: dimmed ? 0.15 : 0.75,
                    interactive: false
                });
                const line = L.polyline(latlngs, {
                    color,
                    weight: isActive ? 6 : 4.5,
                    opacity: dimmed ? 0.3 : 0.95
                });

                line.bindTooltip(catalogEntry?.name || path, { sticky: true, direction: 'top', className: 'track-tip' });
                line.on('click', (e) => {
                    L.DomEvent.stopPropagation(e);
                    this.selectTrack(path);
                });
                line.on('mouseover', () => { if (this.activePath !== path) line.setStyle({ weight: 6.5 }); });
                line.on('mouseout', () => { if (this.activePath !== path) line.setStyle({ weight: 4.5 }); });

                casing.addTo(this.map);
                line.addTo(this.map);
                layers.push(casing, line);
            }
        }

        for (const wp of entry.data.waypoints || []) {
            const marker = L.marker([wp.lat, wp.lng], {
                icon: L.divIcon({
                    className: 'waypoint-marker',
                    html: `<span class="wp-dot" style="background:${color}"></span>`,
                    iconSize: [18, 18],
                    iconAnchor: [9, 9]
                })
            });
            marker.bindPopup(this.waypointPopup(wp));
            marker.addTo(this.map);
            layers.push(marker);
        }

        this.trackLayers.set(path, layers);
    }

    waypointPopup(wp) {
        const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        let html = `<div class="wp-popup"><h4>${esc(wp.name || 'Wegpunkt')}</h4>`;
        if (wp.description) html += `<p>${esc(wp.description)}</p>`;
        if (wp.elevation !== null && wp.elevation !== undefined) html += `<p>Höhe: ${Math.round(wp.elevation)} m ü. M.</p>`;
        html += '</div>';
        return html;
    }

    removeTrackLayers(path) {
        const layers = this.trackLayers.get(path);
        if (!layers) return;
        for (const layer of layers) this.map.removeLayer(layer);
        this.trackLayers.delete(path);
    }

    hideTrack(path) {
        this.removeTrackLayers(path);
    }

    fitAll() {
        let minLat = Infinity, minLng = Infinity, maxLat = -Infinity, maxLng = -Infinity;
        let found = false;
        for (const path of this.catalog.keys()) {
            if (!this.isSelected(path)) continue;
            const bounds = this.catalog.get(path).stats?.bounds;
            if (!bounds?.southWest) continue;
            minLat = Math.min(minLat, bounds.southWest[0]);
            minLng = Math.min(minLng, bounds.southWest[1]);
            maxLat = Math.max(maxLat, bounds.northEast[0]);
            maxLng = Math.max(maxLng, bounds.northEast[1]);
            found = true;
        }
        if (!found) {
            this.toast('Keine sichtbaren Touren');
            return;
        }
        this.map.fitBounds(L.latLngBounds([minLat, minLng], [maxLat, maxLng]), { padding: [40, 40] });
    }

    locateMe() {
        if (!navigator.geolocation) {
            this.toast('Standortbestimmung nicht verfügbar');
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const latlng = [pos.coords.latitude, pos.coords.longitude];
                if (this.locateMarker) this.map.removeLayer(this.locateMarker);
                this.locateMarker = L.marker(latlng, {
                    icon: L.divIcon({
                        className: 'locate-marker',
                        html: '<span class="locate-dot"></span>',
                        iconSize: [20, 20],
                        iconAnchor: [10, 10]
                    })
                }).addTo(this.map);
                this.map.setView(latlng, Math.max(this.map.getZoom(), 13));
            },
            () => this.toast('Standort konnte nicht ermittelt werden')
        );
    }

    /* ---------------------------------------------------------- Auswahl & Detail */

    focusTrack(path) {
        // Aus der Liste: Tour aktivieren, zoomen, Details öffnen
        if (!this.isSelected(path)) {
            this.setSelected(path, true);
            const row = document.querySelector(`.file-row[data-path="${CSS.escape(path)}"] .switch input`);
            if (row) row.checked = true;
            this.syncFolderSwitch(path);
        }
        const bounds = this.catalog.get(path)?.stats?.bounds;
        if (bounds?.southWest) {
            this.map.fitBounds(L.latLngBounds(bounds.southWest, bounds.northEast), { padding: [60, 60] });
        }
        this.selectTrack(path);
        if (this.mobileQuery.matches) {
            this.applyPanelState(true);
        }
    }

    async selectTrack(path) {
        if (this.activePath === path) return;
        const previous = this.activePath;
        this.activePath = path;

        document.querySelectorAll('.file-row.active').forEach(el => el.classList.remove('active'));
        const row = document.querySelector(`.file-row[data-path="${CSS.escape(path)}"]`);
        if (row) {
            row.classList.add('active');
            row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }

        this.openDetailPanel(path);

        try {
            await this.ensureTrackLoaded(path, 'full');
        } catch (error) {
            this.toast('Tour-Daten konnten nicht geladen werden');
            return;
        }

        if (this.activePath !== path) return; // inzwischen andere Auswahl

        // Alle sichtbaren Tracks neu zeichnen (Dimmen), aktive Tour hervorheben
        if (previous) this.renderTrack(previous);
        for (const p of Array.from(this.trackLayers.keys())) {
            if (p !== path) this.renderTrack(p);
        }
        this.renderTrack(path);
        this.renderEndpoints(path);
        this.buildProfile(path);
    }

    deselectTrack() {
        if (!this.activePath) return;
        const previous = this.activePath;
        this.activePath = null;
        this.profilePoints = null;

        document.getElementById('detail').hidden = true;
        document.querySelectorAll('.file-row.active').forEach(el => el.classList.remove('active'));

        for (const layer of this.activeLayers) this.map.removeLayer(layer);
        this.activeLayers = [];
        if (this.hoverMarker) {
            this.map.removeLayer(this.hoverMarker);
            this.hoverMarker = null;
        }

        for (const p of Array.from(this.trackLayers.keys())) this.renderTrack(p);
        // Detailgrad der vormals aktiven Tour ggf. an Zoom anpassen
        this.ensureTrackVisible(previous);
    }

    renderEndpoints(path) {
        for (const layer of this.activeLayers) this.map.removeLayer(layer);
        this.activeLayers = [];

        const data = this.loaded.get(path)?.data;
        if (!data) return;
        const segments = [];
        for (const track of data.tracks || []) {
            for (const seg of track.segments || []) {
                if (seg.length > 1) segments.push(seg);
            }
        }
        if (segments.length === 0) return;

        const first = segments[0][0];
        const lastSeg = segments[segments.length - 1];
        const last = lastSeg[lastSeg.length - 1];

        const start = L.circleMarker([first.lat, first.lng], {
            radius: 7, color: '#fff', weight: 2.5, fillColor: '#2f9e44', fillOpacity: 1
        }).bindTooltip('Start');
        const end = L.circleMarker([last.lat, last.lng], {
            radius: 7, color: '#fff', weight: 2.5, fillColor: '#e03131', fillOpacity: 1
        }).bindTooltip('Ziel');
        start.addTo(this.map);
        end.addTo(this.map);
        this.activeLayers = [start, end];
    }

    openDetailPanel(path) {
        const entry = this.catalog.get(path);
        const detail = document.getElementById('detail');
        document.getElementById('detail-dot').style.background = entry?.color || '#888';
        document.getElementById('detail-title').textContent = entry?.name || path;
        const subParts = [entry?.folderName];
        if (entry?.folderDate) subParts.push(entry.folderDate);
        document.getElementById('detail-sub').textContent = subParts.filter(Boolean).join(' · ');

        this.renderChips(path, null);
        document.getElementById('profile-empty').hidden = true;
        document.getElementById('profile-canvas').getContext('2d')
            .clearRect(0, 0, 9999, 9999);
        detail.hidden = false;
    }

    renderChips(path, profileInfo) {
        const stats = this.catalog.get(path)?.stats || {};
        const chips = [];
        if (stats.distance) chips.push(['📏', `${stats.distance} km`, 'Distanz']);
        if (stats.elevation_gain) chips.push(['⬆️', `${stats.elevation_gain} m`, 'Aufstieg']);
        if (stats.elevation_loss) chips.push(['⬇️', `${stats.elevation_loss} m`, 'Abstieg']);
        if (profileInfo) {
            chips.push(['⛰️', `${Math.round(profileInfo.maxEle)} m`, 'Höchster Punkt']);
        }
        if (profileInfo && stats.distance) {
            // Leistungskilometer: Horizontaldistanz + 1 km je 100 m Aufstieg
            // + 1 km je 150 m Abstieg in starkem Gefälle (> 20 %)
            const lkm = stats.distance
                + (stats.elevation_gain || 0) / 100
                + (profileInfo.steepDescent || 0) / 150;
            chips.push(['🥾', `${lkm.toFixed(1)} km`, 'Leistungs-km']);
        }
        if (stats.distance) {
            const hours = stats.distance / 4.2 + (stats.elevation_gain || 0) / 400 + (stats.elevation_loss || 0) / 800;
            const h = Math.floor(hours);
            const m = Math.round((hours - h) * 60);
            chips.push(['⏱️', `${h} h ${String(m).padStart(2, '0')}`, 'Wanderzeit ca.']);
        }

        const container = document.getElementById('detail-chips');
        container.textContent = '';
        for (const [icon, value, label] of chips) {
            const chip = document.createElement('div');
            chip.className = 'chip';
            const iconEl = document.createElement('span');
            iconEl.className = 'chip-icon';
            iconEl.textContent = icon;
            const textEl = document.createElement('span');
            textEl.className = 'chip-text';
            const valueEl = document.createElement('strong');
            valueEl.textContent = value;
            const labelEl = document.createElement('small');
            labelEl.textContent = label;
            textEl.append(valueEl, labelEl);
            chip.append(iconEl, textEl);
            container.appendChild(chip);
        }
    }

    /* ---------------------------------------------------------- Höhenprofil */

    haversine(a, b) {
        const R = 6371000;
        const dLat = (b.lat - a.lat) * Math.PI / 180;
        const dLng = (b.lng - a.lng) * Math.PI / 180;
        const s = Math.sin(dLat / 2) ** 2
            + Math.cos(a.lat * Math.PI / 180) * Math.cos(b.lat * Math.PI / 180) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s));
    }

    buildProfile(path) {
        const data = this.loaded.get(path)?.data;
        this.profilePoints = null;
        if (!data) return;

        const points = [];
        let dist = 0;
        let prev = null;
        let eleCount = 0;

        for (const track of data.tracks || []) {
            for (const seg of track.segments || []) {
                for (const p of seg) {
                    if (prev) dist += this.haversine(prev, p);
                    if (p.elevation !== null && p.elevation !== undefined) eleCount++;
                    points.push({ d: dist / 1000, ele: p.elevation, lat: p.lat, lng: p.lng });
                    prev = p;
                }
            }
        }

        const empty = document.getElementById('profile-empty');
        if (points.length < 2 || eleCount / points.length < 0.5) {
            empty.hidden = false;
            const canvas = document.getElementById('profile-canvas');
            canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
            return;
        }
        empty.hidden = true;

        // Lücken in den Höhendaten mit letztem Wert füllen
        let lastEle = points.find(p => p.ele !== null && p.ele !== undefined)?.ele ?? 0;
        for (const p of points) {
            if (p.ele === null || p.ele === undefined) {
                p.ele = lastEle;
            } else {
                lastEle = p.ele;
            }
        }

        // Auf max. 900 Punkte reduzieren
        let sampled = points;
        if (points.length > 900) {
            const step = points.length / 900;
            sampled = [];
            for (let i = 0; i < 900; i++) {
                sampled.push(points[Math.floor(i * step)]);
            }
            sampled.push(points[points.length - 1]);
        }

        this.profilePoints = sampled;
        this.drawProfile();

        const maxEle = Math.max(...sampled.map(p => p.ele));
        const steepDescent = this.calcSteepDescent(points);
        this.renderChips(path, { maxEle, steepDescent });
    }

    /**
     * Summiert Höhenmeter in starkem Gefälle (> 20 %).
     * Ausgewertet in ~100-m-Abschnitten, damit GPS-Rauschen keine
     * falschen Steilstücke erzeugt.
     * @param {Array} points - Profilpunkte mit d (km) und ele (m)
     * @returns {number} Abstiegs-Höhenmeter in Steilstücken
     */
    calcSteepDescent(points) {
        let steep = 0;
        let startD = points[0].d;
        let startEle = points[0].ele;

        const evaluate = (distMeters, eleDiff) => {
            if (distMeters > 0 && eleDiff < 0 && (-eleDiff / distMeters) > 0.20) {
                steep += -eleDiff;
            }
        };

        for (let i = 1; i < points.length; i++) {
            const distMeters = (points[i].d - startD) * 1000;
            if (distMeters >= 100) {
                evaluate(distMeters, points[i].ele - startEle);
                startD = points[i].d;
                startEle = points[i].ele;
            }
        }

        // Letzter, unvollständiger Abschnitt
        const rest = points[points.length - 1];
        evaluate((rest.d - startD) * 1000, rest.ele - startEle);

        return steep;
    }

    drawProfile() {
        const points = this.profilePoints;
        if (!points) return;
        const canvas = document.getElementById('profile-canvas');
        const wrap = document.getElementById('profile-wrap');
        const rect = wrap.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;
        const W = Math.max(rect.width, 100);
        const H = Math.max(rect.height, 80);
        canvas.width = W * dpr;
        canvas.height = H * dpr;
        canvas.style.width = W + 'px';
        canvas.style.height = H + 'px';

        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, W, H);

        const padL = 8, padR = 8, padT = 18, padB = 20;
        const plotW = W - padL - padR;
        const plotH = H - padT - padB;

        const maxD = points[points.length - 1].d || 1;
        let minEle = Infinity, maxEle = -Infinity;
        for (const p of points) {
            minEle = Math.min(minEle, p.ele);
            maxEle = Math.max(maxEle, p.ele);
        }
        const span = Math.max(maxEle - minEle, 50);
        minEle -= span * 0.05;
        const range = span * 1.15;

        const isDark = document.documentElement.dataset.theme === 'dark';
        const styles = getComputedStyle(document.documentElement);
        const accent = styles.getPropertyValue('--accent').trim() || '#dc3d43';
        const gridColor = isDark ? 'rgba(255,255,255,0.09)' : 'rgba(20,40,60,0.09)';
        const textColor = isDark ? 'rgba(235,240,245,0.65)' : 'rgba(30,45,60,0.6)';

        const toX = (d) => padL + (d / maxD) * plotW;
        const toY = (ele) => padT + plotH - ((ele - minEle) / range) * plotH;

        // Gitterlinien + Beschriftung
        ctx.font = '10px system-ui, sans-serif';
        ctx.fillStyle = textColor;
        ctx.strokeStyle = gridColor;
        ctx.lineWidth = 1;
        const eleSteps = 3;
        for (let i = 0; i <= eleSteps; i++) {
            const ele = minEle + (range * i) / eleSteps;
            const y = toY(ele);
            ctx.beginPath();
            ctx.moveTo(padL, y);
            ctx.lineTo(W - padR, y);
            ctx.stroke();
            ctx.fillText(`${Math.round(ele)} m`, padL + 2, y - 3);
        }
        // km-Beschriftung
        const kmSteps = Math.min(6, Math.max(2, Math.floor(maxD / 2)));
        for (let i = 0; i <= kmSteps; i++) {
            const d = (maxD * i) / kmSteps;
            ctx.textAlign = i === 0 ? 'left' : (i === kmSteps ? 'right' : 'center');
            ctx.fillText(`${d.toFixed(d < 10 && kmSteps > 4 ? 1 : 0)} km`, toX(d), H - 6);
        }
        ctx.textAlign = 'left';

        // Fläche
        const gradient = ctx.createLinearGradient(0, padT, 0, H - padB);
        gradient.addColorStop(0, accent + '55');
        gradient.addColorStop(1, accent + '08');
        ctx.beginPath();
        ctx.moveTo(toX(points[0].d), toY(points[0].ele));
        for (const p of points) ctx.lineTo(toX(p.d), toY(p.ele));
        ctx.lineTo(toX(points[points.length - 1].d), H - padB);
        ctx.lineTo(toX(points[0].d), H - padB);
        ctx.closePath();
        ctx.fillStyle = gradient;
        ctx.fill();

        // Linie
        ctx.beginPath();
        ctx.moveTo(toX(points[0].d), toY(points[0].ele));
        for (const p of points) ctx.lineTo(toX(p.d), toY(p.ele));
        ctx.strokeStyle = accent;
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.stroke();

        this.profileGeom = { padL, padR, padT, padB, W, H, maxD, minEle, range, toX, toY };
    }

    onProfileHover(clientX) {
        const points = this.profilePoints;
        const geom = this.profileGeom;
        if (!points || !geom) return;

        const wrap = document.getElementById('profile-wrap');
        const rect = wrap.getBoundingClientRect();
        const x = Math.min(Math.max(clientX - rect.left, geom.padL), geom.W - geom.padR);
        const d = ((x - geom.padL) / (geom.W - geom.padL - geom.padR)) * geom.maxD;

        // Nächstgelegenen Punkt suchen (binäre Suche)
        let lo = 0, hi = points.length - 1;
        while (lo < hi) {
            const mid = (lo + hi) >> 1;
            if (points[mid].d < d) lo = mid + 1; else hi = mid;
        }
        const p = points[lo];

        const cursor = document.getElementById('profile-cursor');
        cursor.hidden = false;
        const px = geom.toX(p.d);
        const py = geom.toY(p.ele);
        cursor.querySelector('.cursor-line').style.left = px + 'px';
        const dot = cursor.querySelector('.cursor-dot');
        dot.style.left = px + 'px';
        dot.style.top = py + 'px';
        const label = cursor.querySelector('.cursor-label');
        label.textContent = `km ${p.d.toFixed(1)} · ${Math.round(p.ele)} m`;
        const labelLeft = Math.min(Math.max(px, 55), geom.W - 55);
        label.style.left = labelLeft + 'px';

        // Position auf der Karte markieren
        if (!this.hoverMarker) {
            this.hoverMarker = L.circleMarker([p.lat, p.lng], {
                radius: 8, color: '#fff', weight: 3, fillColor: '#1971c2', fillOpacity: 1, interactive: false
            }).addTo(this.map);
        } else {
            this.hoverMarker.setLatLng([p.lat, p.lng]);
        }
    }

    hideProfileCursor() {
        document.getElementById('profile-cursor').hidden = true;
        if (this.hoverMarker) {
            this.map.removeLayer(this.hoverMarker);
            this.hoverMarker = null;
        }
    }

    /* ---------------------------------------------------------- Toast */

    toast(message) {
        const el = document.getElementById('toast');
        el.textContent = message;
        el.hidden = false;
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(() => { el.hidden = true; }, 3200);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new TrailApp(typeof GPX_STRUCTURE !== 'undefined' ? GPX_STRUCTURE : {});
});
