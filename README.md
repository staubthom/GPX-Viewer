
# GPX-Viewer

Eine PHP-Webanwendung zur Visualisierung und Verwaltung von GPX-Dateien auf einer interaktiven Karte. Die GPX-Dateien werden in Ordnern gruppiert und können über eine Sidebar einzeln ein- und ausgeblendet werden. Ein Admin-Bereich ermöglicht das Hochladen und Löschen von GPX-Dateien. Meine Webseite dazu gibt es hier: [https://wandern.thomasstaub.ch/gpx-viewer/](https://wandern.thomasstaub.ch/gpx-viewer/)

## Features

- **Vollbild-Karte** mit schwebendem Glas-Panel (Swisstopo, Leaflet.js); je nach Zoom-Stufe wird eine grobe oder genauere Track-Geometrie vom Server nachgeladen
- **Höhenprofil**: Interaktives Profil pro Tour — beim Überfahren wird die Position live auf der Karte markiert
- **Tour-Details**: Distanz, Auf-/Abstieg, höchster Punkt und geschätzte Wanderzeit (SAC-Formel)
- **Kartenebenen**: Karte farbig / grau / Luftbild plus Wanderwege-Overlay (swisstopo)
- **Dark Mode**: Umschaltbar, wird gespeichert und gilt auch für den Admin-Bereich
- **Suche**: Live-Filter über Tour-, Datei- und Ordnernamen
- **Standort-Button**: Zeigt die eigene Position auf der Karte (Geolocation)
- **Track-Auswahl**: Klick auf Track oder Listeneintrag hebt die Tour hervor und dimmt die übrigen
- **Persistenz**: Sichtbare Touren, Ebenen, Theme und eingeklappte Ordner bleiben gespeichert (localStorage)
- **Admin-Bereich**: Multi-Upload mit Drag & Drop, Ordnerverwaltung mit Farben/Beschreibungen, passwortgeschützt
- **Mobile-optimiert**: Drawer-Navigation, Bottom-Sheet für Details, Touch-Bedienung

## Installation

### Voraussetzungen

- XAMPP (oder ein anderer lokaler Webserver mit PHP)
- PHP 7.4 oder neuer
- Moderner Browser (Chrome, Firefox, Safari, Edge)

### Setup

1. **Projekt-Verzeichnis**:
   Kopiere das Projekt nach `C:\xampp\htdocs\gpx-viewer\` (oder direkt im XAMPP-htdocs-Verzeichnis).

2. **XAMPP starten**:
   - Starte das XAMPP Control Panel
   - Aktiviere Apache

3. **Zugriff**:
   - Öffne deinen Browser
   - Navigiere zu: `http://localhost/gpx-viewer/`

## Verwendung

### Kartenansicht

1. Öffne `http://localhost/gpx-viewer/`
2. In der Seitenleiste werden alle verfügbaren GPX-Dateien (nach Ordnern gruppiert) angezeigt
3. Beim Laden der Seite werden aktivierte Tracks direkt angezeigt
4. Je nach Zoom-Stufe werden Tracks in grober oder genauer Form vom Server nachgeladen
4. Mit "Alle an/aus" können alle Tracks gleichzeitig ein- oder ausgeblendet werden
5. Mit "Alle anzeigen" wird die Karte auf alle sichtbaren Tracks fokussiert



### Admin-Bereich

1. Navigiere zu: `http://localhost/gpx-viewer/admin.php`
2. **Login**:
   - Standard-Passwort: `secure123`
   - **WICHTIG**: Ändere das Passwort in der Datei `admin.php` (siehe Kommentar in den ersten Zeilen)
3. **GPX-Dateien hochladen**:
   - Wähle eine `.gpx`-Datei aus
   - Klicke auf "Hochladen"
4. **GPX-Dateien löschen**:
   - Klicke auf "Löschen" bei der gewünschten Datei
   - Bestätige die Löschung

## Sicherheit

⚠️ **WICHTIG**: Vor dem produktiven Einsatz:

1. **Passwort ändern**: Ändere das Admin-Passwort in `admin.php` (siehe Kommentar weiter unten)

2. **Dateiberechtigungen**: Stelle sicher, dass der Ordner `gpx-files/` schreib- und leseberechtigt ist

## Dateistruktur

```
gpx-viewer/
├── index.php            # Hauptseite mit Kartenansicht
├── admin.php            # Admin-Bereich (Login, Upload, Löschen)
├── gpx_data.php         # API: liefert GPX-Tracks als JSON (mit Detail-Levels & Cache)
├── gpx_metadata.php     # Gemeinsame Funktionen (Metadaten, Statistiken, Cache)
├── generate_password.php# Hilfsskript zum Generieren von Passworthashes
├── css/
│   └── app.css          # Stylesheet (Karte, Panel, Admin, Light/Dark)
├── js/
│   └── app.js           # Anwendung (Karte, Liste, Höhenprofil, Layer, Theme)
├── gpx-files/           # Ordner für GPX-Dateien (Unterordner = Kategorien)
│   └── ...              # Enthält .gpx-Dateien und folder.json
├── cache/               # Server-Cache (Metadaten & vereinfachte Track-Geometrien)
├── login_attempts.json  # Zählt fehlgeschlagene Logins
├── README.md            # Diese Datei
```

# GPX Viewer - Sicherheits-Dokumentation

## 🔐 Sichere Login-Implementierung

Das Admin-Panel verwendet ein umfassendes Sicherheitssystem:

### Implementierte Sicherheitsfeatures:

#### 🛡️ **Passwort-Sicherheit:**
- **Bcrypt-Hashing** mit Kosten-Faktor 12
- **Salt automatisch** in Hash integriert
- **Sichere Passwort-Verifikation** mit `password_verify()`

#### 🚫 **Rate Limiting:**
- **Max. 5 Login-Versuche** pro IP-Adresse
- **15 Minuten Sperre** nach zu vielen Versuchen
- **Automatische Bereinigung** alter Versuche

#### 🔒 **Session-Sicherheit:**
- **Session-Regeneration** bei Login
- **HTTP-Only Cookies** (XSS-Schutz)
- **Session-Timeout** nach 1 Stunde Inaktivität
- **IP-Validierung** für Sessions

#### 🎫 **CSRF-Schutz:**
- **CSRF-Token** für alle Login-Formulare
- **Token-Validierung** bei jedem Login
- **Sichere Token-Generierung** mit `random_bytes()`

#### 🕐 **Zeitbasierte Sicherheit:**
- **Session-Timeout** nach Inaktivität
- **Automatische Bereinigung** von Rate-Limit-Daten
- **Login-Zeit-Tracking**

---

## 🚀 Installation & Konfiguration

### 1. Neues Passwort erstellen (Web-Interface):

**Option A: Web-Generator (empfohlen)**
```
1. Öffne http://localhost/gpx-viewer/generate_password.php
2. Gib ein sicheres Passwort ein
3. Klicke "Hash generieren"
4. Kopiere den generierten Hash
5. Füge ihn in admin.php ein
6. Lösche generate_password.php
```

### 2. Hash in admin.php eintragen:

Kopiere den generierten Hash in die `$config`-Array:

```php
'admin_password_hash' => '$2y$12$...',
```

### 3. Benutzername ändern (optional):

```php
'admin_username' => 'meinAdmin',
```

### 4. HTTPS aktivieren (Produktion):

```php
ini_set('session.cookie_secure', 1); // Für HTTPS
```

---

## 🔧 Konfigurationsoptionen

In `admin.php` kannst du folgende Werte anpassen:

```php
$config = [
    'admin_username' => 'admin',              // Benutzername
    'admin_password_hash' => '...',           // Passwort-Hash
    'max_login_attempts' => 5,                // Max. Versuche
    'lockout_time' => 900,                    // Sperre in Sekunden (15min)
    'session_timeout' => 3600                 // Session-Timeout (1h)
];
```

---

## ⚠️ Sicherheitshinweise

### **SOFORT nach Installation:**
1. **Öffne den Web-Generator** unter `http://localhost/gpx-viewer/generate_password.php`
2. **Erstelle ein neues Passwort** und kopiere den Hash
3. **Füge den Hash in admin.php ein**
4. **Lösche `generate_password.php`** nach der Verwendung
5. **Ändere Benutzername** von "admin" zu etwas Eigenem
6. **Aktiviere HTTPS** in Produktionsumgebung
7. **Sichere Dateiberechtigungen** für `login_attempts.json`

### **Regelmäßig:**
1. **Passwort ändern** (alle 3-6 Monate)
2. **Session-Logs prüfen**
3. **Login-Attempts-Datei** überwachen

### **Bei Verdacht auf Kompromittierung:**
1. **Passwort sofort ändern**
2. **Alle Sessions beenden** (Server-Neustart)
3. **Login-Attempts-Datei löschen**
4. **Server-Logs prüfen**

---

## 📁 Neue Dateien

Das System erstellt/verwendet folgende Dateien:

- **`generate_password.php`** - Web-Interface für Passwort-Hash-Generierung
  - Benutzerfreundliche Web-Oberfläche
  - Passwort-Stärke-Indikator
  - Echtzeit-Validierung
  - **MUSS nach Verwendung gelöscht werden!**

- **`login_attempts.json`** - Rate-Limiting-Daten
  - Enthält IP-Adressen und Zeitstempel
  - Wird automatisch bereinigt
  - Sollte von Webserver geschützt sein

---

## 🐛 Troubleshooting

### **"Session ungültig" Fehler:**
- IP-Adresse hat sich geändert (VPN, etc.)
- Lösung: Neu anmelden

### **"Zu viele Login-Versuche":**
- Rate-Limit erreicht
- Warte 15 Minuten oder lösche `login_attempts.json`

### **Login funktioniert nicht:**
- Prüfe Passwort-Hash in `admin.php`
- Prüfe Benutzername
- Prüfe Browser-Konsole auf JavaScript-Fehler

---

## 🔐 Standard-Login (ÄNDERN!)

**Benutzername:** admin
**Passwort:** secure123

⚠️ **WICHTIG:** Ändere diese Credentials sofort nach der Installation!

---

## 📊 Sicherheitslevel

| Feature | Status | Beschreibung |
|---------|--------|--------------|
| ✅ Passwort-Hashing | Aktiv | Bcrypt mit Kosten 12 |
| ✅ Rate Limiting | Aktiv | 5 Versuche / 15 Min |
| ✅ Session-Sicherheit | Aktiv | Timeout & Regeneration |
| ✅ CSRF-Schutz | Aktiv | Token-basiert |
| ✅ IP-Validierung | Aktiv | Session-IP-Bindung |
| ⚠️ HTTPS | Optional | Für Produktion empfohlen |
| ⚠️ 2FA | Nicht implementiert | Für höchste Sicherheit |

Das System bietet jetzt **Enterprise-Level Sicherheit** für ein lokales Admin-Panel!


## Technische Details

- **Frontend**: HTML5, CSS3, JavaScript (ES6+)
- **Backend**: PHP 7.4+
- **Karten-API**: Leaflet.js mit OpenStreetMap
- **GPX-Parsing**: Eigener JavaScript-GPX-Parser

## Browser-Kompatibilität

- Chrome 70+
- Firefox 65+
- Safari 12+
- Edge 79+

## Troubleshooting

### Karte lädt nicht
- Läuft Apache?
- Gibt es JavaScript-Fehler in der Browser-Konsole?

### GPX-Dateien werden nicht angezeigt
- Liegen die Dateien im richtigen Unterordner von `gpx-files/`?
- Sind die Dateien gültige GPX-Dateien?
- Stimmen die Dateiberechtigungen?

### Upload funktioniert nicht
- Ist der Ordner `gpx-files/` schreibberechtigt?
- Bist du als Admin eingeloggt?
- Gibt es Hinweise in den PHP-Fehlerlogs?


