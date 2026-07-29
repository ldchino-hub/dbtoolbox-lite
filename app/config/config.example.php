<?php
return [
    'app_name' => 'DB Tool Box Lite',
    'debug' => false,

    /*
     * ── Base de datos INTERNA de la app (metadatos) ─────────────────────────
     * Guarda usuarios, conexiones guardadas, permisos, historial, etc.
     * NO es donde conectas tus servidores MySQL/PostgreSQL — eso se hace en la UI.
     *
     * Por defecto: SQLite (archivo local, sin instalar nada más).
     * La app crea storage/database.sqlite sola en el primer arranque.
     */
    'database_path' => __DIR__ . '/../storage/database.sqlite',

    /*
     * Opcional: usar MySQL solo para metadatos (hosting compartido).
     * Descomenta y comenta database_path arriba si lo necesitas.
     *
     * 'database' => [
     *     'driver' => 'mysql',
     *     'host' => 'localhost',
     *     'port' => 3306,
     *     'database' => 'dbtoolbox_lite_meta',
     *     'username' => 'dbtoolbox_lite_user',
     *     'password' => 'your_mysql_password_here',
     * ],
     */

    // Cifrado de contraseñas guardadas — genera: openssl rand -hex 32
    'meta_enc_key' => 'replace_with_64_hex_chars_from_openssl_rand_hex_32',

    // Sesiones JWT — genera: openssl rand -hex 16 (mínimo 16 caracteres)
    'jwt_secret' => 'replace_with_random_secret_min_16_chars',

    'backup_dir' => __DIR__ . '/../storage/backups',

    // Solo scripts CLI de importación (opcional)
    'my_cnf_path' => getenv('HOME') . '/.my.cnf',
    'pg_service_path' => getenv('HOME') . '/.pg_service.conf',

    // Usuario administrador inicial.
    // La contraseña NO se lee en cada login: se usa UNA VEZ al crear el admin
    // con `php scripts/seed-admin.php` (o recover-admin.php). Después el login
    // valida contra la tabla users en storage/database.sqlite.
    'admin_email' => 'admin@example.com',
    'admin_password' => 'change_me_on_first_login',

    // VPN bajo demanda para conexiones *-vpn (requiere openvpn + sudo en el host)
    'vpn_enabled' => false,
    'vpn_config' => '/etc/openvpn/client/your-client.ovpn',
    'vpn_auth' => '/etc/openvpn/client/your-client.auth',
    'vpn_pidfile' => '/var/run/openvpn-dbtoolbox.pid',
    'vpn_log' => '/var/log/openvpn-dbtoolbox.log',
];
