<?php
// Einfacher Passwort-Hash-Generator (Minimal-Version)
$hash = '';
$error = '';

if ($_POST) {
    $password = $_POST['password'] ?? '';
    if ($password) {
        $hash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
    } else {
        $error = 'Bitte Passwort eingeben';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Passwort Generator</title>
    <style>
        body { font-family: Arial; max-width: 500px; margin: 50px auto; padding: 20px; }
        .form-group { margin: 15px 0; }
        label { display: block; margin-bottom: 5px; }
        input { width: 100%; padding: 8px; box-sizing: border-box; }
        button { background: #007cba; color: white; padding: 10px 20px; border: none; cursor: pointer; }
        .hash { background: #f5f5f5; padding: 15px; margin: 15px 0; word-break: break-all; font-family: monospace; }
        .error { color: red; }
        .success { color: green; }
        .warning { background: #fff3cd; padding: 10px; margin: 15px 0; border: 1px solid #ffeaa7; }
    </style>
</head>
<body>
    <h1>GPX Viewer - Passwort Generator</h1>
    
    <div class="warning">
        <strong>⚠️ WICHTIG:</strong> Lösche diese Datei nach der Verwendung!
    </div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label>Neues Passwort:</label>
            <input type="password" name="password" required minlength="8">
        </div>
        <button type="submit">Hash generieren</button>
    </form>

    <?php if ($hash): ?>
        <div class="success">Hash erfolgreich generiert!</div>
        <div class="hash"><?= htmlspecialchars($hash) ?></div>
        
        <h3>Anweisungen:</h3>
        <ol>
            <li>Kopiere den Hash oben</li>
            <li>Öffne admin.php</li>
            <li>Ersetze den Wert bei 'admin_password_hash'</li>
            <li>Speichere admin.php</li>
            <li>Lösche diese Datei!</li>
        </ol>
        
        <h4>Code für admin.php:</h4>
        <div class="hash">'admin_password_hash' => '<?= htmlspecialchars($hash) ?>',</div>
    <?php endif; ?>

    <p><a href="admin.php">← Zurück zum Admin</a></p>
</body>
</html>
