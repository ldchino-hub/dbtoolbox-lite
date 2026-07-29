<?php
/**
 * One-time web installer — open after upload, before first login.
 * Disabled automatically when storage/.installed exists.
 * Delete this file on production after setup (optional; lock file also blocks access).
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$root = dirname(__DIR__);
$configPath = $root . '/config/config.php';
$lockPath = $root . '/storage/.installed';
$examplePath = $root . '/config/config.example.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function random_hex(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

function config_looks_configured(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    /** @var array<string,mixed> $cfg */
    $cfg = require $path;
    $meta = (string)($cfg['meta_enc_key'] ?? '');
    $jwt = (string)($cfg['jwt_secret'] ?? '');
    if ($meta === '' || strlen($meta) < 32) {
        return false;
    }
    if (str_contains($meta, 'replace_with') || str_contains($meta, 'CHANGE_ME')) {
        return false;
    }
    if ($jwt === '' || strlen($jwt) < 16) {
        return false;
    }
    if (str_contains($jwt, 'replace_with') || str_contains($jwt, 'CHANGE_ME')) {
        return false;
    }
    return true;
}

function has_admin_user(string $root): bool
{
    if (!is_file($root . '/src/bootstrap.php')) {
        return false;
    }
    try {
        require_once $root . '/src/bootstrap.php';
        $count = (int)\DbToolBox\App::db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        return $count > 0;
    } catch (Throwable) {
        return false;
    }
}

function mark_installed(string $lockPath): void
{
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($lockPath, json_encode([
        'installed_at' => gmdate('c'),
        'version' => is_file(dirname($dir) . '/VERSION') ? trim((string)file_get_contents(dirname($dir) . '/VERSION')) : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function installer_disabled_page(): void
{
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Instalación completa</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;color:#333}</style></head><body>';
    echo '<h1>Ya está instalado</h1>';
    echo '<p>El asistente de instalación ya no está disponible.</p>';
    echo '<p><a href="./">Ir al login</a></p>';
    echo '</body></html>';
    exit;
}

// Already installed → behave as if install.php does not exist
if (is_file($lockPath)) {
    installer_disabled_page();
}

if (has_admin_user($root)) {
    mark_installed($lockPath);
    installer_disabled_page();
}

if (config_looks_configured($configPath) && has_admin_user($root)) {
    mark_installed($lockPath);
    installer_disabled_page();
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['admin_email'] ?? ''));
    $password = (string)($_POST['admin_password'] ?? '');
    $metaKey = trim((string)($_POST['meta_enc_key'] ?? ''));
    $jwtSecret = trim((string)($_POST['jwt_secret'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email de administrador inválido.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    }
    if (!preg_match('/^[a-f0-9]{64}$/i', $metaKey)) {
        $errors[] = 'meta_enc_key debe ser 64 caracteres hex (openssl rand -hex 32).';
    }
    if (strlen($jwtSecret) < 16) {
        $errors[] = 'jwt_secret debe tener al menos 16 caracteres.';
    }

    $storageDir = $root . '/storage';
    $backupDir = $storageDir . '/backups';
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0755, true)) {
        $errors[] = 'No se pudo crear storage/.';
    }
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
        $errors[] = 'No se pudo crear storage/backups/.';
    }
    if (!is_writable($storageDir)) {
        $errors[] = 'storage/ no es escribible.';
    }

    if ($errors === []) {
        $configDir = $root . '/config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $q = static fn(string $s): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";

        $configPhp = <<<PHP
<?php
return [
    'app_name' => 'DB Tool Box Lite',
    'debug' => false,
    'database_path' => __DIR__ . '/../storage/database.sqlite',
    'meta_enc_key' => {$q($metaKey)},
    'jwt_secret' => {$q($jwtSecret)},
    'backup_dir' => __DIR__ . '/../storage/backups',
    'my_cnf_path' => getenv('HOME') . '/.my.cnf',
    'pg_service_path' => getenv('HOME') . '/.pg_service.conf',
    'admin_email' => {$q($email)},
    'admin_password' => {$q($password)},
    'vpn_enabled' => false,
    'vpn_config' => '/etc/openvpn/client/your-client.ovpn',
    'vpn_auth' => '/etc/openvpn/client/your-client.auth',
    'vpn_pidfile' => '/var/run/openvpn-dbtoolbox.pid',
    'vpn_log' => '/var/log/openvpn-dbtoolbox.log',
];

PHP;

        if (@file_put_contents($configPath, $configPhp) === false) {
            $errors[] = 'No se pudo escribir config/config.php — revisa permisos.';
        } else {
            try {
                require_once $root . '/src/bootstrap.php';
                $db = \DbToolBox\App::db();
                $st = $db->prepare('SELECT id FROM users WHERE email = ?');
                $st->execute([$email]);
                if ($st->fetch()) {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $db->prepare('UPDATE users SET password_hash = ?, role = ? WHERE email = ?')
                        ->execute([$hash, 'admin', $email]);
                } else {
                    \DbToolBox\Auth\AuthService::createUser($email, $password, 'admin');
                }
                mark_installed($lockPath);
                @unlink(__FILE__);
                $success = true;
            } catch (Throwable $e) {
                $errors[] = 'Error al inicializar la app: ' . $e->getMessage();
                @unlink($configPath);
            }
        }
    }
}

if ($success) {
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Instalación completa</title>';
    echo '<meta http-equiv="refresh" content="2;url=./">';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem}</style></head><body>';
    echo '<h1>Instalación completa</h1>';
    echo '<p>Usuario <code>' . h($email ?? '') . '</code> creado. Redirigiendo al login…</p>';
    echo '<p><a href="./">Ir ahora</a></p></body></html>';
    exit;
}

$prefillEmail = (string)($_POST['admin_email'] ?? 'admin@example.com');
$prefillMeta = (string)($_POST['meta_enc_key'] ?? random_hex(32));
$prefillJwt = (string)($_POST['jwt_secret'] ?? random_hex(16));

$extOk = extension_loaded('pdo')
    && extension_loaded('pdo_sqlite')
    && extension_loaded('openssl')
    && extension_loaded('json')
    && extension_loaded('mbstring');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>DB Tool Box Lite — Instalación</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 520px; margin: 2rem auto; padding: 0 1rem; color: #1f2937; }
    h1 { font-size: 1.35rem; margin-bottom: 0.25rem; }
    .sub { color: #6b7280; font-size: 0.9rem; margin-bottom: 1.5rem; }
    label { display: block; font-size: 0.8rem; font-weight: 600; margin: 0.75rem 0 0.25rem; }
    input { width: 100%; box-sizing: border-box; padding: 0.55rem 0.65rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; }
    input.mono { font-family: ui-monospace, monospace; font-size: 0.75rem; }
    button { margin-top: 1.25rem; width: 100%; padding: 0.65rem; background: #1976d2; color: #fff; border: none; border-radius: 6px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
    button:hover { background: #1565c0; }
    .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 0.75rem; border-radius: 6px; margin: 1rem 0; font-size: 0.85rem; }
    .ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 0.75rem; border-radius: 6px; margin: 1rem 0; font-size: 0.85rem; }
    .hint { font-size: 0.75rem; color: #6b7280; margin-top: 0.2rem; }
    .checks { font-size: 0.85rem; margin: 1rem 0; padding: 0; list-style: none; }
    .checks li { padding: 0.2rem 0; }
  </style>
</head>
<body>
  <h1>DB Tool Box Lite</h1>
  <p class="sub">Asistente de instalación (una sola vez)</p>

  <ul class="checks">
    <li><?= $extOk ? '✓' : '✗' ?> PHP <?= h(PHP_VERSION) ?> + extensiones requeridas</li>
    <li><?= is_writable($root . '/storage') || @mkdir($root . '/storage', 0755, true) ? '✓' : '✗' ?> storage/ escribible</li>
    <li><?= is_file($examplePath) ? '✓' : '✗' ?> Archivos de la app presentes</li>
  </ul>

  <?php if (!$extOk): ?>
    <div class="err">Faltan extensiones PHP. Requiere: pdo, pdo_sqlite, openssl, json, mbstring.</div>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="err">
      <?php foreach ($errors as $err): ?>
        <div><?= h($err) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <label for="admin_email">Email administrador</label>
    <input id="admin_email" name="admin_email" type="email" required value="<?= h($prefillEmail) ?>" />

    <label for="admin_password">Contraseña administrador</label>
    <input id="admin_password" name="admin_password" type="password" required minlength="8" placeholder="Mínimo 8 caracteres" />

    <label for="meta_enc_key">Clave de cifrado (meta_enc_key)</label>
    <input id="meta_enc_key" name="meta_enc_key" class="mono" required pattern="[a-fA-F0-9]{64}" value="<?= h($prefillMeta) ?>" />
    <p class="hint">64 caracteres hex — se generó una clave aleatoria. No la pierdas.</p>

    <label for="jwt_secret">Secreto JWT (jwt_secret)</label>
    <input id="jwt_secret" name="jwt_secret" class="mono" required minlength="16" value="<?= h($prefillJwt) ?>" />

    <button type="submit" <?= $extOk ? '' : 'disabled' ?>>Instalar y crear admin</button>
  </form>

  <p class="hint" style="margin-top:1.5rem">
    Tras instalar, este archivo se desactiva (y se borra si el servidor lo permite).
    La base interna SQLite se crea en <code>storage/database.sqlite</code>.
  </p>
</body>
</html>
