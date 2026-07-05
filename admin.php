<?php
require_once __DIR__ . '/gpx_metadata.php';

// Sichere Session-Konfiguration BEVOR session_start()
$isHttpsRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', $isHttpsRequest ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

// Session starten
session_start();

// Regeneriere Session-ID bei Login
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// Konfiguration für sicheres Login
$config = [
    // Ändere diese Werte für deine Installation!
    'admin_username' => 'admin',
    // Passwort-Hash für 'secure123' - ändere das!
    'admin_password_hash' => '$2y$12$h1Ev64nwk0GZtsDDow2NkO6dPjFqT5MA8vkztYypZm0cz/5mosS8a',
    'max_login_attempts' => 5,
    'lockout_time' => 900, // 15 Minuten
    'session_timeout' => 3600, // 1 Stunde
    'max_upload_size' => 15 * 1024 * 1024 // 15 MB
];

function isAdminAuthenticated() {
    return !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function readJsonFileAsArray($file) {
    if (!file_exists($file)) return [];

    $content = file_get_contents($file);
    if ($content === false) return [];

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function sanitizeFolderName($folderName) {
    return trim((string)$folderName);
}

function isValidFolderName($folderName) {
    return $folderName !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $folderName) === 1;
}

function getGpxBaseDirectory() {
    return gpxviewer_get_gpx_root();
}

function resolveFolderPath($folderName) {
    $folderName = sanitizeFolderName($folderName);
    if (!isValidFolderName($folderName)) {
        return null;
    }

    $folderPath = getGpxBaseDirectory() . DIRECTORY_SEPARATOR . $folderName;
    $realBasePath = realpath(getGpxBaseDirectory());
    $realFolderPath = realpath($folderPath);

    if ($realBasePath === false || $realFolderPath === false || strpos($realFolderPath, $realBasePath) !== 0 || !is_dir($realFolderPath)) {
        return null;
    }

    return $realFolderPath;
}

function deleteDirectoryRecursive($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);

    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        if (!deleteDirectoryRecursive($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }

    return rmdir($dir);
}

function sanitizeUploadedFilename($filename) {
    $baseName = basename((string)$filename);
    $sanitized = preg_replace('/[^A-Za-z0-9._ -]/', '_', $baseName);
    $sanitized = trim((string)$sanitized, ". ");
    // Leerzeichen vermeiden: ergeben problematische URLs beim Download
    $sanitized = str_replace(' ', '_', $sanitized);
    return $sanitized;
}

function validateUploadedGpxFile($file) {
    global $config;

    if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
        return 'Keine Upload-Datei empfangen.';
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return 'Fehler beim Upload der Datei.';
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return 'Ungültige Upload-Quelle.';
    }

    if ((int)$file['size'] <= 0 || (int)$file['size'] > $config['max_upload_size']) {
        return 'Die Datei ist leer oder überschreitet das Upload-Limit.';
    }

    $sanitizedFilename = sanitizeUploadedFilename($file['name']);
    if ($sanitizedFilename === '' || strtolower((string)pathinfo($sanitizedFilename, PATHINFO_EXTENSION)) !== 'gpx') {
        return 'Nur GPX-Dateien sind erlaubt.';
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file['tmp_name']);
    if ($xml === false || strtolower($xml->getName()) !== 'gpx') {
        libxml_clear_errors();
        return 'Die hochgeladene Datei ist keine gültige GPX-Datei.';
    }
    libxml_clear_errors();

    return null;
}

function resolveUploadDirectory($targetFolder) {
    $baseDirectory = getGpxBaseDirectory();
    if ($targetFolder === null || trim((string)$targetFolder) === '') {
        return $baseDirectory;
    }

    return resolveFolderPath($targetFolder);
}

function hasValidAuthenticatedCsrf() {
    return isset($_POST['csrf_token']) && validateCSRFToken((string)$_POST['csrf_token']);
}

// Rate Limiting für Login-Versuche
function getRateLimitFile() {
    return __DIR__ . '/login_attempts.json';
}

function getLoginAttempts($ip) {
    $file = getRateLimitFile();
    $data = readJsonFileAsArray($file);
    $userAttempts = $data[$ip] ?? [];

    // Bereinige alte Versuche
    $cutoff = time() - $GLOBALS['config']['lockout_time'];
    return array_filter($userAttempts, function($timestamp) use ($cutoff) {
        return $timestamp > $cutoff;
    });
}

function addLoginAttempt($ip) {
    $file = getRateLimitFile();
    $data = readJsonFileAsArray($file);

    if (!isset($data[$ip])) $data[$ip] = [];
    $data[$ip][] = time();

    // Halte nur die letzten Versuche
    $cutoff = time() - $GLOBALS['config']['lockout_time'];
    $data[$ip] = array_filter($data[$ip], function($timestamp) use ($cutoff) {
        return $timestamp > $cutoff;
    });

    gpxviewer_write_json_file($file, $data);
}

function isLockedOut($ip) {
    global $config;
    $attempts = getLoginAttempts($ip);
    return count($attempts) >= $config['max_login_attempts'];
}

// CSRF-Token generieren
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Session-Timeout prüfen
function checkSessionTimeout() {
    global $config;
    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > $config['session_timeout']) {
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

// Login-Verarbeitung
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$message = '';
$messageType = '';
$authenticatedPostHasValidCsrf = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdminAuthenticated()) {
    $authenticatedPostHasValidCsrf = hasValidAuthenticatedCsrf();
    if (!$authenticatedPostHasValidCsrf) {
        $message = 'Ungültiger Sicherheits-Token. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        $messageType = 'error';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    // Rate Limiting prüfen
    if (isLockedOut($clientIP)) {
        $message = "Zu viele fehlgeschlagene Login-Versuche. Bitte warten Sie " .
                   ceil($config['lockout_time'] / 60) . " Minuten.";
        $messageType = "error";
    }
    // CSRF-Token prüfen
    elseif (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $message = "Ungültiger Sicherheits-Token. Bitte versuchen Sie es erneut.";
        $messageType = "error";
        addLoginAttempt($clientIP);
    }
    // Credentials prüfen
    elseif (isset($_POST['username']) && isset($_POST['password']) &&
            $_POST['username'] === $config['admin_username'] &&
            password_verify($_POST['password'], $config['admin_password_hash'])) {

        // Erfolgreicher Login
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['user_ip'] = $clientIP;

        // Lösche alte Login-Versuche
        $file = getRateLimitFile();
        if (file_exists($file)) {
            $data = readJsonFileAsArray($file);
            unset($data[$clientIP]);
            gpxviewer_write_json_file($file, $data);
        }

        header('Location: admin.php');
        exit;
    } else {
        // Fehlgeschlagener Login
        addLoginAttempt($clientIP);
        $message = "Ungültige Anmeldedaten.";
        $messageType = "error";
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'logout' && $authenticatedPostHasValidCsrf) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Prüfe Session-Gültigkeit
if (isAdminAuthenticated()) {
    if (!checkSessionTimeout()) {
        header('Location: admin.php');
        exit;
    }

    // Zusätzliche Sicherheitsprüfung: IP-Adresse
    if (isset($_SESSION['user_ip']) && $_SESSION['user_ip'] !== $clientIP) {
        session_destroy();
        $message = "Session ungültig. Bitte melden Sie sich erneut an.";
        $messageType = "error";
    }
}

// Ordner erstellen
if (isset($_POST['create_folder']) && isAdminAuthenticated() && $authenticatedPostHasValidCsrf) {
    $folderName = sanitizeFolderName($_POST['folder_name'] ?? '');
    $folderPath = getGpxBaseDirectory() . DIRECTORY_SEPARATOR . $folderName;

    if (isValidFolderName($folderName)) {
        if (!file_exists($folderPath)) {
            if (mkdir($folderPath, 0755, true)) {
                // Erstelle Standard-Konfigurationsdatei
                $defaultConfig = [
                    'name' => $folderName,
                    'description' => '',
                    'color' => '#3498db',
                    'date' => date('Y-m-d'),
                    'created' => date('Y-m-d H:i:s')
                ];
                gpxviewer_write_json_file($folderPath . '/folder.json', $defaultConfig);
                gpxviewer_clear_all_caches();
                $message = "Ordner '$folderName' erfolgreich erstellt.";
                $messageType = "success";
            } else {
                $message = "Fehler beim Erstellen des Ordners.";
                $messageType = "error";
            }
        } else {
            $message = "Ordner existiert bereits.";
            $messageType = "error";
        }
    } else {
        $message = "Ungültiger Ordnername. Nur Buchstaben, Zahlen, _ und - sind erlaubt.";
        $messageType = "error";
    }
}

// Ordner löschen
if (isset($_POST['delete_folder']) && isAdminAuthenticated() && $authenticatedPostHasValidCsrf) {
    $folderName = sanitizeFolderName($_POST['folder_name'] ?? '');
    $folderPath = resolveFolderPath($folderName);

    if (isValidFolderName($folderName)) {
        if ($folderPath !== null) {
            if (deleteDirectoryRecursive($folderPath)) {
                gpxviewer_clear_all_caches();
                $message = "Ordner '$folderName' erfolgreich gelöscht.";
                $messageType = "success";
            } else {
                $message = "Fehler beim Löschen des Ordners.";
                $messageType = "error";
            }
        } else {
            $message = "Ordner existiert nicht.";
            $messageType = "error";
        }
    } else {
        $message = "Ungültiger Ordnername.";
        $messageType = "error";
    }
}

// Ordner-Konfiguration aktualisieren
if (isset($_POST['update_folder_config']) && isAdminAuthenticated() && $authenticatedPostHasValidCsrf) {
    $folderName = sanitizeFolderName($_POST['config_folder'] ?? '');
    $folderPath = resolveFolderPath($folderName);
    $configPath = $folderPath ? $folderPath . DIRECTORY_SEPARATOR . 'folder.json' : null;

    if ($configPath !== null && file_exists($configPath)) {
        $config = [
            'name' => trim((string)($_POST['config_name'] ?? $folderName)),
            'description' => trim((string)($_POST['config_description'] ?? '')),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['config_color'] ?? '')) ? $_POST['config_color'] : '#3498db',
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['config_date'] ?? '')) ? $_POST['config_date'] : '',
            'updated' => date('Y-m-d H:i:s')
        ];

        if (gpxviewer_write_json_file($configPath, $config)) {
            $message = "Konfiguration erfolgreich aktualisiert.";
            $messageType = "success";
        } else {
            $message = "Fehler beim Speichern der Konfiguration.";
            $messageType = "error";
        }
    }
}

// Upload-Funktion (mehrere Dateien gleichzeitig, erweitert für Ordner)
if (isset($_POST['upload']) && isAdminAuthenticated() && $authenticatedPostHasValidCsrf) {
    $targetFolder = sanitizeFolderName($_POST['target_folder'] ?? '');
    $uploadDir = resolveUploadDirectory($targetFolder);

    if ($uploadDir === null) {
        $message = 'Ungültiger Zielordner.';
        $messageType = 'error';
    } else {
        // $_FILES-Struktur normalisieren (einzelne Datei oder Array)
        $rawFiles = $_FILES['gpx_files'] ?? null;
        $fileList = [];
        if (is_array($rawFiles) && isset($rawFiles['name'])) {
            if (is_array($rawFiles['name'])) {
                foreach ($rawFiles['name'] as $index => $name) {
                    $fileList[] = [
                        'name' => $name,
                        'tmp_name' => $rawFiles['tmp_name'][$index] ?? '',
                        'error' => $rawFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $rawFiles['size'][$index] ?? 0
                    ];
                }
            } else {
                $fileList[] = $rawFiles;
            }
        }

        $uploadedCount = 0;
        $uploadErrors = [];

        foreach ($fileList as $file) {
            $sanitizedFilename = sanitizeUploadedFilename($file['name'] ?? '');
            $validationError = validateUploadedGpxFile($file);

            if ($validationError !== null) {
                $uploadErrors[] = $sanitizedFilename . ': ' . $validationError;
                continue;
            }

            $uploadFile = $uploadDir . DIRECTORY_SEPARATOR . $sanitizedFilename;
            if (file_exists($uploadFile)) {
                $uploadErrors[] = $sanitizedFilename . ': Datei existiert bereits.';
                continue;
            }

            if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                $uploadedCount++;
            } else {
                $uploadErrors[] = $sanitizedFilename . ': Fehler beim Speichern.';
            }
        }

        if ($uploadedCount > 0) {
            gpxviewer_clear_all_caches();
        }

        if ($fileList === []) {
            $message = 'Keine Upload-Datei empfangen.';
            $messageType = 'error';
        } elseif ($uploadErrors === []) {
            $message = $uploadedCount . ' Datei(en) erfolgreich hochgeladen.';
            $messageType = 'success';
        } else {
            $prefix = $uploadedCount > 0 ? $uploadedCount . ' Datei(en) hochgeladen. ' : '';
            $message = $prefix . 'Probleme: ' . implode(' — ', $uploadErrors);
            $messageType = $uploadedCount > 0 ? 'success' : 'error';
        }
    }
}

// Lösch-Funktion (erweitert für Unterordner)
if (isset($_POST['delete']) && isAdminAuthenticated() && $authenticatedPostHasValidCsrf) {
    $fileToDelete = gpxviewer_normalize_relative_path((string)($_POST['file_to_delete'] ?? ''));

    // Sicherheitscheck: Nur Dateien im gpx-files Ordner erlauben
    $fullPath = gpxviewer_resolve_gpx_file_path($fileToDelete);

    if ($fullPath !== null) {
        if (unlink($fullPath)) {
            gpxviewer_clear_all_caches();
            $message = "Datei erfolgreich gelöscht.";
            $messageType = "success";
        } else {
            $message = "Fehler beim Löschen der Datei.";
            $messageType = "error";
        }
    } else {
        $message = "Datei nicht gefunden oder ungültiger Pfad.";
        $messageType = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verwaltung · Wanderwege Schweiz</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20100%20100'%3E%3Ctext%20y='.9em'%20font-size='90'%3E%E2%9B%B0%EF%B8%8F%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="css/app.css">
    <script>
        // Theme aus der Karten-Ansicht übernehmen (vor dem Rendern, verhindert Flackern)
        try {
            const stored = JSON.parse(localStorage.getItem('gpx-viewer-v2') || '{}');
            const theme = stored.theme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.dataset.theme = theme;
        } catch (e) {}
    </script>
</head>
<body class="admin">
    <div class="admin-topbar">
        <div class="brand">
            <div class="brand-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3l4 8 5-5 5 15H2L8 3z"/></svg>
            </div>
            <div class="brand-text">
                <h1>Verwaltung</h1>
                <p>Wanderwege Schweiz</p>
            </div>
        </div>
        <nav>
            <a href="index.php">🗺️ Zur Karte</a>
            <?php if (isAdminAuthenticated()): ?>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <button type="submit">Abmelden</button>
                </form>
            <?php endif; ?>
        </nav>
    </div>

    <div class="admin-wrap">
        <?php if (!isAdminAuthenticated()): ?>
            <!-- Login-Formular -->
            <div class="login-form">
                <h2>Admin Login</h2>

                <?php if (!empty($message)): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if (isLockedOut($clientIP)): ?>
                    <div class="lockout-info">
                        <p><strong>Account temporär gesperrt</strong></p>
                        <p>Zu viele fehlgeschlagene Login-Versuche.</p>
                        <p>Warten Sie <?php echo ceil($config['lockout_time'] / 60); ?> Minuten und versuchen Sie es erneut.</p>
                    </div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                        <div class="form-group">
                            <label for="username">Benutzername:</label>
                            <input type="text" id="username" name="username" required autocomplete="username">
                        </div>

                        <div class="form-group">
                            <label for="password">Passwort:</label>
                            <input type="password" id="password" name="password" required autocomplete="current-password">
                        </div>

                        <button type="submit">Anmelden</button>
                    </form>

                    <div class="login-info">

                        <small>Max. <?php echo $config['max_login_attempts']; ?> Versuche alle <?php echo $config['lockout_time']/60; ?> Minuten</small>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Admin-Bereich -->
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="admin-grid">
                <!-- Upload-Formular -->
                <div class="admin-card">
                    <h3>⬆️ GPX-Dateien hochladen</h3>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <div class="form-group">
                            <label for="target_folder">Zielordner</label>
                            <select id="target_folder" name="target_folder">
                                <option value="">Hauptverzeichnis</option>
                                <?php
                                $gpxDir = __DIR__ . '/gpx-files/';
                                if (is_dir($gpxDir)) {
                                    $folders = array_filter(scandir($gpxDir), function($item) use ($gpxDir) {
                                        return is_dir($gpxDir . $item) && $item !== '.' && $item !== '..';
                                    });
                                    foreach ($folders as $folder) {
                                        echo '<option value="' . htmlspecialchars($folder) . '">' . htmlspecialchars($folder) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <label class="dropzone" id="dropzone">
                            <span class="dz-icon">🗺️</span>
                            <strong>GPX-Dateien hierher ziehen</strong>
                            <p>oder klicken zum Auswählen — mehrere Dateien möglich</p>
                            <input type="file" id="gpx_files" name="gpx_files[]" accept=".gpx" multiple required>
                            <div class="dz-files" id="dz-files"></div>
                        </label>
                        <button type="submit" name="upload">Hochladen</button>
                    </form>
                </div>

                <!-- Ordner erstellen -->
                <div class="admin-card">
                    <h3>📁 Neuen Ordner erstellen</h3>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <div class="form-group">
                            <label for="folder_name">Ordnername</label>
                            <input type="text" id="folder_name" name="folder_name" pattern="[a-zA-Z0-9_-]+"
                                   title="Nur Buchstaben, Zahlen, _ und - sind erlaubt" required>
                        </div>
                        <button type="submit" name="create_folder">Ordner erstellen</button>
                    </form>
                </div>
            </div>

            <!-- Verstecktes Formular für Ordner löschen -->
            <form id="delete-folder-form" method="post" style="display: none;">
                <input type="hidden" id="delete-folder-name" name="folder_name" value="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="delete_folder" value="1">
            </form>

            <!-- Dateiliste -->
            <div class="admin-card file-management">
                <h3>🗂️ Ordner und Dateien verwalten</h3>
                <?php
                $gpxDir = __DIR__ . '/gpx-files/';
                if (is_dir($gpxDir)) {
                    $items = scandir($gpxDir);
                    $folders = [];
                    $files = [];

                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') continue;
                        $fullPath = $gpxDir . $item;

                        if (is_dir($fullPath)) {
                            $configFile = $fullPath . '/folder.json';
                            $config = [];
                            if (file_exists($configFile)) {
                                $configContent = file_get_contents($configFile);
                                $config = json_decode($configContent, true) ?: [];
                            }
                            $folders[$item] = $config;
                        } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'gpx') {
                            $files[] = $item;
                        }
                    }

                    // Zeige Ordner an
                    if (!empty($folders)) {
                        echo '<div class="folders-grid">';
                        foreach ($folders as $folderName => $config) {
                            $folderPath = $gpxDir . $folderName;
                            $gpxCount = count(array_filter(scandir($folderPath), function($f) {
                                return pathinfo($f, PATHINFO_EXTENSION) === 'gpx';
                            }));

                            echo '<div class="folder-card">';
                            echo '<div class="folder-card-header" style="border-left: 4px solid ' . ($config['color'] ?? '#3498db') . '">';
                            echo '<h4>' . htmlspecialchars($config['name'] ?? $folderName) . '</h4>';
                            echo '<span class="file-count">' . $gpxCount . ' Dateien</span>';
                            echo '</div>';

                            if (!empty($config['description']) || !empty($config['date'])) {
                                echo '<div class="folder-card-info">';
                                if (!empty($config['description'])) {
                                    echo '<p>' . htmlspecialchars($config['description']) . '</p>';
                                }
                                if (!empty($config['date'])) {
                                    echo '<p><strong>Datum:</strong> ' . htmlspecialchars($config['date']) . '</p>';
                                }
                                echo '</div>';
                            }

            echo '<div class="folder-card-actions">';
            echo '<button class="btn-config" onclick="showConfigForm(\'' . htmlspecialchars($folderName) . '\')">Konfigurieren</button>';
            echo '<button class="btn-toggle" onclick="toggleFolderFiles(\'' . htmlspecialchars($folderName) . '\')">Dateien anzeigen</button>';
            echo '<button class="btn-delete-folder" onclick="deleteFolderConfirm(\'' . htmlspecialchars($folderName) . '\')">Ordner löschen</button>';
            echo '</div>';                            // Dateien im Ordner anzeigen (versteckt)
                            echo '<div id="folder-files-' . htmlspecialchars($folderName) . '" class="folder-files-list" style="display: none;">';
                            $folderFiles = array_filter(scandir($folderPath), function($f) {
                                return pathinfo($f, PATHINFO_EXTENSION) === 'gpx';
                            });

                            if (!empty($folderFiles)) {
                                echo '<h5>Dateien in diesem Ordner:</h5>';
                                echo '<div class="folder-file-grid">';
                                foreach ($folderFiles as $file) {
                                    $filePath = $folderPath . '/' . $file;
                                    $fileSize = filesize($filePath);
                                    $fileDate = date('d.m.Y H:i', filemtime($filePath));
                                    $relativePath = $folderName . '/' . $file;

                                    echo '<div class="folder-file-card">';
                                    echo '<div class="file-info">';
                                    echo '<h6>' . htmlspecialchars($file) . '</h6>';
                                    echo '<p>Größe: ' . number_format($fileSize / 1024, 2) . ' KB</p>';
                                    echo '<p>Datum: ' . $fileDate . '</p>';
                                    echo '</div>';
                                    echo '<div class="file-actions">';
                                    // Korrekte URL-Kodierung: rawurlencode kodiert Leerzeichen als %20
                                    // (urlencode ergibt '+', das Apache im Pfad nicht dekodiert -> 404)
                                    $pathParts = explode('/', $relativePath);
                                    $encodedPath = implode('/', array_map('rawurlencode', $pathParts));
                                    echo '<a href="gpx-files/' . $encodedPath . '" download class="btn-download">Download</a>';
                                    echo '<form method="post" style="display: inline;" onsubmit="return confirm(\'Möchten Sie diese Datei wirklich löschen?\')">';
                                    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
                                    echo '<input type="hidden" name="file_to_delete" value="' . htmlspecialchars($relativePath) . '">';
                                    echo '<button type="submit" name="delete" class="btn-delete">Löschen</button>';
                                    echo '</form>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                                echo '</div>';
                            } else {
                                echo '<p>Keine GPX-Dateien in diesem Ordner.</p>';
                            }
                            echo '</div>';

                            // Konfigurationsformular (versteckt)
                            echo '<div id="config-form-' . htmlspecialchars($folderName) . '" class="config-form" style="display: none;">';
                            echo '<form method="post">';
                            echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
                            echo '<input type="hidden" name="config_folder" value="' . htmlspecialchars($folderName) . '">';
                            echo '<div class="form-group">';
                            echo '<label>Name:</label>';
                            echo '<input type="text" name="config_name" value="' . htmlspecialchars($config['name'] ?? $folderName) . '" required>';
                            echo '</div>';
                            echo '<div class="form-group">';
                            echo '<label>Beschreibung:</label>';
                            echo '<textarea name="config_description">' . htmlspecialchars($config['description'] ?? '') . '</textarea>';
                            echo '</div>';
                            echo '<div class="form-group">';
                            echo '<label>Farbe:</label>';
                            echo '<input type="color" name="config_color" value="' . htmlspecialchars($config['color'] ?? '#3498db') . '">';
                            echo '</div>';
                            echo '<div class="form-group">';
                            echo '<label>Datum:</label>';
                            echo '<input type="date" name="config_date" value="' . htmlspecialchars($config['date'] ?? '') . '">';
                            echo '</div>';
                            echo '<button type="submit" name="update_folder_config">Speichern</button>';
                            echo '<button type="button" onclick="hideConfigForm(\'' . htmlspecialchars($folderName) . '\')">Abbrechen</button>';
                            echo '</form>';
                            echo '</div>';

                            echo '</div>';
                        }
                        echo '</div>';
                    }

                    // Zeige lose Dateien an
                    if (!empty($files)) {
                        echo '<h4>Einzelne Dateien</h4>';
                        echo '<div class="file-grid">';
                        foreach ($files as $file) {
                            $filePath = $gpxDir . $file;
                            $fileSize = filesize($filePath);
                            $fileDate = date('d.m.Y H:i', filemtime($filePath));

                            echo '<div class="file-card">';
                            echo '<div class="file-info">';
                            echo '<h4>' . htmlspecialchars($file) . '</h4>';
                            echo '<p>Größe: ' . number_format($fileSize / 1024, 2) . ' KB</p>';
                            echo '<p>Datum: ' . $fileDate . '</p>';
                            echo '</div>';
                            echo '<div class="file-actions">';
                            echo '<a href="gpx-files/' . rawurlencode($file) . '" download class="btn-download">Download</a>';
                            echo '<form method="post" style="display: inline;" onsubmit="return confirm(\'Möchten Sie diese Datei wirklich löschen?\')">';
                            echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
                            echo '<input type="hidden" name="file_to_delete" value="' . htmlspecialchars($file) . '">';
                            echo '<button type="submit" name="delete" class="btn-delete">Löschen</button>';
                            echo '</form>';
                            echo '</div>';
                            echo '</div>';
                        }
                        echo '</div>';
                    }

                    if (empty($folders) && empty($files)) {
                        echo '<p>Keine GPX Dateien oder Ordner vorhanden.</p>';
                    }
                }
                ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function showConfigForm(folderName) {
            document.getElementById('config-form-' + folderName).style.display = 'block';
        }

        function hideConfigForm(folderName) {
            document.getElementById('config-form-' + folderName).style.display = 'none';
        }

        function toggleFolderFiles(folderName) {
            const filesDiv = document.getElementById('folder-files-' + folderName);
            const toggleBtn = event.target;

            if (filesDiv.style.display === 'none') {
                filesDiv.style.display = 'block';
                toggleBtn.textContent = 'Dateien verstecken';
            } else {
                filesDiv.style.display = 'none';
                toggleBtn.textContent = 'Dateien anzeigen';
            }
        }

        function deleteFolderConfirm(folderName) {
            if (confirm('Möchten Sie den Ordner "' + folderName + '" wirklich löschen? Alle Dateien im Ordner werden ebenfalls gelöscht!')) {
                document.getElementById('delete-folder-name').value = folderName;
                document.getElementById('delete-folder-form').submit();
            }
        }

        // Drag & Drop für den Upload
        (function () {
            const dropzone = document.getElementById('dropzone');
            const input = document.getElementById('gpx_files');
            const filesLabel = document.getElementById('dz-files');
            if (!dropzone || !input) return;

            function showSelection() {
                const names = Array.from(input.files).map(f => f.name);
                filesLabel.textContent = names.length
                    ? names.length + ' Datei(en): ' + names.join(', ')
                    : '';
            }

            input.addEventListener('change', showSelection);

            ['dragenter', 'dragover'].forEach(eventName => {
                dropzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    dropzone.classList.add('dragover');
                });
            });
            ['dragleave', 'drop'].forEach(eventName => {
                dropzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    dropzone.classList.remove('dragover');
                });
            });
            dropzone.addEventListener('drop', (e) => {
                if (e.dataTransfer && e.dataTransfer.files.length) {
                    input.files = e.dataTransfer.files;
                    showSelection();
                }
            });
        })();
    </script>
</body>
</html>
