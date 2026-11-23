<?php
declare(strict_types=1);

require __DIR__ . '/settings.php';
require_once __DIR__ . '/stats.php';

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('DUMMY_HASH', '$2y$10$dM7.G7jzmD3TCXk.K/MZj.vqRrrMkGJFteSdPVJh4/aHtiW1d/IYG');

const BUILTIN_ICONS = ['globe', 'mail', 'play', 'video', 'info', 'phone', 'map', 'doc', 'star', 'home', 'link', 'calendar'];

ini_set('display_errors', '0');
date_default_timezone_set(defined('TIMEZONE') ? TIMEZONE : 'Europe/Madrid');
error_reporting(E_ALL);

/* ---------------------------------------------------------------- *
 *  Carpeta de datos (fuera de la web) y almacenamiento JSON
 * ---------------------------------------------------------------- */

/** Directorio personal de la cuenta del hosting (p. ej. /home/USUARIO en cPanel). */
function account_home(): string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (!empty($pw['dir']) && is_dir($pw['dir'])) {
            return rtrim($pw['dir'], '/');
        }
    }
    $h = getenv('HOME');
    if ($h && is_dir($h)) {
        return rtrim($h, '/');
    }
    // Último recurso: la carpeta que contiene el sitio
    return dirname(APP_ROOT);
}

function data_fail(string $detail): void
{
    error_log('[welcome-kiosk] ' . $detail);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Error de configuración: no se puede usar la carpeta de datos.\n"
       . "Revise DATA_DIR en inc/settings.php y los permisos (el detalle está en el registro de errores de PHP).");
}

/** Ruta absoluta de la carpeta de datos; la crea (0700) y traslada los datos antiguos si hace falta. */
function data_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $d = DATA_DIR !== '' ? rtrim(DATA_DIR, '/') : account_home() . '/welcome-kiosk-data';
    if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
        data_fail("No se puede crear la carpeta de datos {$d}");
    }
    if (!is_writable($d)) {
        data_fail("Sin permiso de escritura en {$d}");
    }
    @chmod($d, 0700);
    if (!is_file($d . '/.htaccess')) { // por si la carpeta acabara dentro de la web
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
    }
    $dir = realpath($d) ?: $d;
    migrate_legacy_data($dir);
    return $dir;
}

/** true si la carpeta de datos está dentro del sitio web (desaconsejado). */
function data_dir_is_public(): bool
{
    $data = realpath(data_dir()) ?: data_dir();
    foreach ([APP_ROOT, $_SERVER['DOCUMENT_ROOT'] ?? ''] as $root) {
        $r = $root !== '' ? realpath($root) : false;
        if ($r && str_starts_with($data . '/', rtrim($r, '/') . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * Traslada los datos de la antigua carpeta data/ del sitio a la nueva ubicación.
 *
 * Es "todo o nada":
 *  1. Con un bloqueo exclusivo, copia cada archivo a un temporal en el destino y comprueba que el
 *     contenido es idéntico (SHA-256).
 *  2. Solo si TODAS las copias son correctas, las renombra a su nombre final y escribe la marca
 *     .migrated. Hasta ese momento los originales no se tocan.
 *  3. Después borra los originales que se han copiado o verificado como idénticos (si no se pudieran
 *     borrar, la marca evita repetir la migración).
 * Si un archivo ya existe en el destino con un contenido DISTINTO, es un conflicto: la migración se
 * detiene sin copiar ni borrar nada, se conservan ambos archivos y se indica cuáles son para que el
 * administrador decida cuál conservar.
 * Si algo falla, se descartan los temporales, los originales quedan intactos, no se escribe la marca
 * y la petición se detiene con un error (para no arrancar con una carpeta de datos incompleta).
 * En la siguiente visita se vuelve a intentar.
 */
function migrate_legacy_data(string $dir): void
{
    $old = APP_ROOT . '/data';
    $oldReal = realpath($old);
    $marker = $dir . '/.migrated';
    if (!$oldReal || $oldReal === $dir || is_file($marker) || !is_file($old . '/users.json')) {
        return;
    }

    $lock = @fopen($dir . '/.migrate.lock', 'c');
    if (!$lock) {
        data_fail("Migración: no se puede crear el bloqueo en {$dir}");
    }
    flock($lock, LOCK_EX);
    try {
        if (is_file($marker)) { // otra petición terminó mientras esperábamos
            return;
        }
        $files = ['users.json', 'config.json', 'attempts.json', 'resets.json', 'auth.log', 'auth.log.1', 'secret.key'];
        // 1) Detección de conflictos ANTES de copiar nada
        $conflicts = [];
        foreach ($files as $f) {
            if (is_file("$old/$f") && is_file("$dir/$f")) {
                $a = @hash_file('sha256', "$old/$f");
                $b = @hash_file('sha256', "$dir/$f");
                if (!$a || !$b || $a !== $b) {
                    $conflicts[] = $f;
                }
            }
        }
        if ($conflicts) {
            data_fail('Migración detenida: ' . implode(', ', $conflicts) . " existe(n) en {$old} y en {$dir} con contenido distinto. "
                . 'No se ha copiado ni borrado nada. Compare ambos, deje solo la versión correcta (o cree el archivo '
                . "{$dir}/.migrated si los datos nuevos son los buenos) y recargue la página.");
        }

        $plan = [];      // temporal => destino final
        $verified = [];  // originales que se pueden borrar al final (copiados o idénticos)
        $failed = '';
        foreach ($files as $f) {
            $src = "$old/$f";
            $dst = "$dir/$f";
            if (!is_file($src)) {
                continue;
            }
            $srcHash = @hash_file('sha256', $src);
            if (is_file($dst)) {
                $verified[] = $f; // idéntico (comprobado arriba)
                continue;
            }
            $tmp = $dst . '.migrating';
            if (!$srcHash || !@copy($src, $tmp) || @hash_file('sha256', $tmp) !== $srcHash) {
                $failed = $f;
                break;
            }
            @chmod($tmp, 0600);
            $plan[$tmp] = $dst;
            $verified[] = $f;
        }

        if ($failed === '') {
            foreach ($plan as $tmp => $dst) {
                if (!@rename($tmp, $dst)) {
                    $failed = basename($dst);
                    // deshacer lo ya renombrado en esta pasada para no dejar el destino a medias
                    foreach ($plan as $t2 => $d2) {
                        if ($t2 === $tmp) { break; }
                        @unlink($d2);
                    }
                    break;
                }
            }
        }

        if ($failed !== '') {
            foreach (array_keys($plan) as $tmp) { @unlink($tmp); }
            data_fail("Migración de {$old} a {$dir} interrumpida al copiar {$failed}; los originales siguen intactos y se reintentará");
        }

        if (@file_put_contents($marker, date('c') . " migrado desde {$oldReal}\n") === false) {
            data_fail("Migración: no se puede escribir la marca {$marker}");
        }
        @chmod($marker, 0600);

        // Migración confirmada: se retiran solo los originales copiados o verificados como idénticos
        foreach ($verified as $f) {
            if (is_file("$dir/$f") && @hash_file('sha256', "$old/$f") === @hash_file('sha256', "$dir/$f")) {
                @unlink("$old/$f");
            }
        }
        error_log("[welcome-kiosk] Datos trasladados de {$old} a {$dir}");
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function data_path(string $name): string
{
    return data_dir() . '/' . $name;
}

function json_read(string $file, array $default = []): array
{
    // Las escrituras son atómicas (archivo temporal + rename), así que leer no necesita bloqueo.
    $raw = is_file($file) ? @file_get_contents($file) : false;
    if ($raw === false || $raw === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

/**
 * Lee-modifica-escribe de forma atómica: bloqueo exclusivo en un archivo .lock, escritura en un
 * temporal y rename. Si $fn lanza una excepción o la escritura falla, el archivo original no cambia.
 */
function json_update(string $file, callable $fn, array $default = [])
{
    $lock = @fopen($file . '.lock', 'c');
    if (!$lock) {
        throw new RuntimeException('No se puede escribir en la carpeta de datos. Revise los permisos.');
    }
    flock($lock, LOCK_EX);
    try {
        $data = json_read($file, $default);
        $result = $fn($data);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo guardar el archivo de datos (¿disco lleno o sin permisos?).');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo guardar el archivo de datos.');
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Clave secreta de la instalación (para firmar la marca de navegador de confianza). */
function app_secret(): string
{
    static $k = null;
    if ($k !== null) {
        return $k;
    }
    $f = data_path('secret.key');
    $k = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    if (strlen($k) < 64) {
        $fp = @fopen($f, 'x'); // creación exclusiva: si dos peticiones llegan a la vez, gana una
        if ($fp) {
            $k = bin2hex(random_bytes(32));
            fwrite($fp, $k);
            fclose($fp);
            @chmod($f, 0600);
        } else {
            usleep(100000);
            $k = trim((string)@file_get_contents($f));
            if (strlen($k) < 64) {
                data_fail('secret.key vacío o dañado: bórrelo para que se regenere');
            }
        }
    }
    return $k;
}

/* ---------------------------------------------------------------- *
 *  Configuración del kiosco
 * ---------------------------------------------------------------- */

function config_defaults(): array
{
    static $d = null;
    if ($d === null) {
        $d = require __DIR__ . '/defaults.php';
    }
    return $d;
}

function config_get(): array
{
    $c = array_replace(config_defaults(), json_read(data_path('config.json')));
    $c['dark'] = array_replace(config_defaults()['dark'], is_array($c['dark'] ?? null) ? $c['dark'] : []);
    return $c;
}

/** Imagen de un icono para el tema oscuro: la suya, o la variante -dark de un icono por defecto, o la clara. */
function icon_image_for_theme(array $ic, bool $dark): string
{
    $light = (string)($ic['icon_image'] ?? '');
    if (!$dark) {
        return $light;
    }
    $d = (string)($ic['icon_image_dark'] ?? '');
    if ($d !== '') {
        return $d;
    }
    if (preg_match('#^assets/defaults/(icon-[a-z]+)\.svg$#', $light, $m) && is_file(APP_ROOT . "/assets/defaults/{$m[1]}-dark.svg")) {
        return "assets/defaults/{$m[1]}-dark.svg";
    }
    return $light;
}

/**
 * Webs de confianza que pueden incrustar el kiosco (texto del administrador → orígenes https para CSP).
 * Acepta "www.bezares.com", "https://www.bezares.com", "*.bezares.com" y puerto opcional.
 */
function embed_origins_parse(string $text): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower($text)) as $e) {
        $e = trim($e);
        if ($e === '') {
            continue;
        }
        $e = preg_replace('#^https://#', '', $e);
        $e = rtrim($e, '/');
        if (preg_match('#^(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}(:\d{1,5})?$#', $e)) {
            $out[] = 'https://' . $e;
        }
    }
    return array_values(array_unique($out));
}

function config_update(callable $fn): void
{
    json_update(data_path('config.json'), function (array &$c) use ($fn) {
        $c = array_replace(config_defaults(), $c);
        $fn($c);
        $c['version'] = (int)(microtime(true) * 1000);
    });
}

/* ---------------------------------------------------------------- *
 *  Usuarios
 * ---------------------------------------------------------------- */

function users_all(): array
{
    return json_read(data_path('users.json'), ['users' => []])['users'] ?? [];
}

function users_exist(): bool
{
    return count(users_all()) > 0;
}

function user_find(string $username): ?array
{
    foreach (users_all() as $u) {
        if (hash_equals(mb_strtolower($u['username']), mb_strtolower($username))) {
            return $u;
        }
    }
    return null;
}

function users_update(callable $fn): void
{
    json_update(data_path('users.json'), function (array &$d) use ($fn) {
        $d['users'] = $d['users'] ?? [];
        $fn($d['users']);
        $d['users'] = array_values($d['users']);
    }, ['users' => []]);
}

/** Correo de un usuario: el campo email o, si el nombre de usuario es un correo, ese mismo. */
function user_email(array $u): string
{
    $e = (string)($u['email'] ?? '');
    if ($e === '' && filter_var($u['username'], FILTER_VALIDATE_EMAIL)) {
        $e = $u['username'];
    }
    return $e;
}

/** Busca por nombre de usuario o por correo (sin distinguir mayúsculas). */
function user_find_login(string $id): ?array
{
    $u = user_find($id);
    if ($u) {
        return $u;
    }
    foreach (users_all() as $x) {
        $e = user_email($x);
        if ($e !== '' && hash_equals(mb_strtolower($e), mb_strtolower($id))) {
            return $x;
        }
    }
    return null;
}

function email_valid(string $e): bool
{
    return $e === '' || (strlen($e) <= 100 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false);
}

/** Envía un correo de texto. Devuelve false si el servidor no lo acepta. */
function send_mail(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
        return false;
    }
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fromName = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?=';
    $headers = "From: {$fromName} <" . MAIL_FROM . ">\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n"
             . "Auto-Submitted: auto-generated\r\n";
    return @mail($to, $subj, $body, $headers, '-f' . MAIL_FROM);
}

/** Envía el correo con el enlace de recuperación. */
function send_reset_mail(string $to, string $username, string $link): bool
{
    $minutes = (int)(RESET_TTL / 60);
    $body = "Hola {$username}:\r\n\r\n"
          . "Se ha solicitado cambiar la contraseña del panel del kiosco welcome.bezares.com.\r\n"
          . "Abra este enlace (válido {$minutes} minutos, un solo uso):\r\n\r\n{$link}\r\n\r\n"
          . "Si no lo ha solicitado usted, ignore este mensaje: su contraseña no cambiará.\r\n\r\n"
          . "---\r\n"
          . "A password reset was requested for the welcome.bezares.com kiosk panel.\r\n"
          . "Open the link above within {$minutes} minutes. If it wasn't you, ignore this email.\r\n";
    return send_mail($to, 'Welcome Bezares · Cambiar contraseña / Reset password', $body);
}

function password_make(string $plain): string
{
    return defined('PASSWORD_ARGON2ID')
        ? password_hash($plain, PASSWORD_ARGON2ID)
        : password_hash($plain, PASSWORD_DEFAULT);
}

function password_fingerprint(string $hash): string
{
    return substr(hash('sha256', $hash), 0, 16);
}

function password_problem(string $p, string $username = ''): ?string
{
    if (mb_strlen($p) < MIN_PASSWORD) {
        return 'La contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres.';
    }
    if (mb_strlen($p) > 200) {
        return 'La contraseña es demasiado larga.';
    }
    if ($username !== '' && stripos($p, $username) !== false) {
        return 'La contraseña no puede contener el nombre de usuario.';
    }
    $classes = (int)preg_match('/[a-z]/', $p) + (int)preg_match('/[A-Z]/', $p)
             + (int)preg_match('/\d/', $p) + (int)preg_match('/[^a-zA-Z\d]/', $p);
    if ($classes < 3) {
        return 'Use al menos 3 de estos tipos: minúsculas, mayúsculas, números y símbolos.';
    }
    return null;
}

function username_valid(string $u): bool
{
    // Nombre corto (p. ej. "mantenedor") o dirección de correo (p. ej. "nombre@example.com")
    return (bool)preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $u)
        || (strlen($u) <= 100 && filter_var($u, FILTER_VALIDATE_EMAIL) !== false);
}

/* ---------------------------------------------------------------- *
 *  Sesión, CSRF y cabeceras
 * ---------------------------------------------------------------- */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = data_path('sessions');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '50');
    ini_set('session.gc_maxlifetime', (string)SESSION_MAX);
    ini_set('session.sid_length', '48');
    session_name(is_https() ? '__Host-WKSID' : 'WKSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Solicitud no válida o caducada. Vuelva atrás y recargue la página.');
    }
}

function security_headers(): void
{
    // Panel de administración dentro de un iframe: solo en las webs de confianza (Integración) y si el
    // administrador lo permite. Si no, prohibido (X-Frame-Options DENY + frame-ancestors 'none').
    $c = config_get();
    $origins = !empty($c['embed_admin']) ? embed_origins_parse((string)$c['embed_origins']) : [];
    if ($origins) {
        $ancestors = "'self' " . implode(' ', $origins);
    } else {
        header('X-Frame-Options: DENY');
        $ancestors = "'none'";
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
         . "frame-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors {$ancestors}; base-uri 'none'; object-src 'none'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Devuelve el usuario conectado o null. Aplica caducidad y revalida contra users.json. */
function current_user(): ?array
{
    if (empty($_SESSION['user'])) {
        return null;
    }
    $now = time();
    $s = $_SESSION;
    $u = user_find((string)$s['user']);
    $expired = ($now - ($s['last'] ?? 0)) > SESSION_IDLE
            || ($now - ($s['started'] ?? 0)) > SESSION_MAX;
    if ($expired || !$u || password_fingerprint($u['hash']) !== ($s['pwfp'] ?? '')
        || ($s['ua'] ?? '') !== hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
        logout();
        return null;
    }
    $_SESSION['last'] = $now;
    return $u;
}

function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'user'    => $u['username'],
        'pwfp'    => password_fingerprint($u['hash']),
        'started' => time(),
        'last'    => time(),
        'ua'      => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''),
        'csrf'    => bin2hex(random_bytes(32)),
    ];
}

function logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/* ---------------------------------------------------------------- *
 *  Protección contra fuerza bruta
 * ---------------------------------------------------------------- */

function client_ip(): string
{
    // Solo REMOTE_ADDR: las cabeceras X-Forwarded-For se pueden falsificar.
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Comprueba si una IP está en una lista de IP o rangos CIDR (IPv4 e IPv6). */
function ip_in_list(string $ip, array $list): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($list as $entry) {
        [$net, $bits] = array_pad(explode('/', trim((string)$entry), 2), 2, null);
        $nbin = @inet_pton((string)$net);
        if ($nbin === false || strlen($nbin) !== strlen($bin)) {
            continue;
        }
        $bits = $bits === null ? strlen($bin) * 8 : max(0, min(strlen($bin) * 8, (int)$bits));
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($bin, 0, $bytes) !== substr($nbin, 0, $bytes)) {
            continue;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        if ((($bin[$bytes] ^ $nbin[$bytes]) & $mask) === "\0") {
            return true;
        }
    }
    return false;
}

/** Corta la petición si ADMIN_ALLOW_IPS está definido y la IP no figura en él. */
function require_allowed_ip(): void
{
    if (ADMIN_ALLOW_IPS && !ip_in_list(client_ip(), ADMIN_ALLOW_IPS)) {
        auth_log('ip_not_allowed');
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Acceso no permitido desde esta red.');
    }
}

/* --- Contadores genéricos con bloqueo progresivo --- */

/** Segundos que faltan para que se levante el bloqueo de cualquiera de las claves (0 = permitido). */
function throttle_wait(array $keys): int
{
    $d = json_read(data_path('attempts.json'));
    $now = time();
    $wait = 0;
    foreach ($keys as $k) {
        $wait = max($wait, (int)($d[$k]['lock_until'] ?? 0) - $now);
    }
    return max(0, $wait);
}

/** Limpieza y suma de un fallo a una entrada (dentro de json_update). */
function throttle_bump(array &$d, string $k, int $max, int $now): void
{
    $e = $d[$k] ?? ['fails' => 0, 'first' => $now, 'lock_until' => 0, 'locks' => 0];
    if ($now - $e['first'] > LOGIN_WINDOW) {
        $e['fails'] = 0;
        $e['first'] = $now;
        if (($e['lock_until'] ?? 0) < $now - LOGIN_LOCK_MAX) {
            $e['locks'] = 0; // tras una hora tranquila, la escalada vuelve a empezar
        }
    }
    $e['fails']++;
    $e['last'] = $now;
    if ($e['fails'] >= $max) {
        $e['lock_until'] = $now + min(LOGIN_LOCK * (2 ** min($e['locks'], 10)), LOGIN_LOCK_MAX);
        $e['locks']++;
        $e['fails'] = 0;
        $e['first'] = $now;
    }
    $d[$k] = $e;
}

function throttle_gc(array &$d, int $now): void
{
    foreach ($d as $k => $v) {
        if (($v['lock_until'] ?? 0) < $now && ($v['soft_until'] ?? 0) < $now && ($v['last'] ?? 0) < $now - 2 * LOGIN_LOCK_MAX) {
            unset($d[$k]);
        }
    }
    if (count($d) > 5000) { // tope de tamaño
        uasort($d, fn($a, $b) => ($a['last'] ?? 0) <=> ($b['last'] ?? 0));
        $d = array_slice($d, -4000, null, true);
    }
}

/** Registra un fallo en cada clave: [clave => máximo de fallos]. */
function throttle_fail(array $limits): void
{
    json_update(data_path('attempts.json'), function (array &$d) use ($limits) {
        $now = time();
        throttle_gc($d, $now);
        foreach ($limits as $k => $max) {
            throttle_bump($d, $k, (int)$max, $now);
        }
    });
}

function throttle_clear(array $keys): void
{
    json_update(data_path('attempts.json'), function (array &$d) use ($keys) {
        foreach ($keys as $k) {
            unset($d[$k]);
        }
    });
}

/* --- Inicio de sesión --- */

/** Clave canónica de cuenta: el nombre de usuario real si existe (aunque se entre con el correo). */
function login_account_key(string $id): string
{
    $u = user_find_login($id);
    return mb_strtolower($u ? $u['username'] : mb_substr($id, 0, 100));
}

/**
 * Claves de bloqueo de un intento. Los navegadores de confianza tienen su propio contador por IP+cuenta y
 * no cuentan para el límite por IP: así un atacante (aunque comparta la IP de la oficina) no puede
 * bloquear a los usuarios en los equipos donde ya entraron.
 */
function login_keys(string $ip, string $acct, bool $trusted): array
{
    if ($trusted) {
        return ['pair:dev:' . $ip . '|' . $acct => LOGIN_MAX_PAIR];
    }
    return [
        'pair:anon:' . $ip . '|' . $acct => LOGIN_MAX_PAIR,
        'ip:' . $ip => LOGIN_MAX_IP,
    ];
}

/**
 * Espera necesaria antes de un intento de login: bloqueos por IP/IP+usuario y, si la cuenta está en
 * modo lento por un ataque distribuido, el intervalo mínimo entre intentos (salvo navegador de confianza).
 */
function login_wait(string $ip, string $acct, bool $trusted): int
{
    $wait = throttle_wait(array_keys(login_keys($ip, $acct, $trusted)));
    if (!$trusted) {
        $e = json_read(data_path('attempts.json'))['acct:' . $acct] ?? [];
        if (($e['soft_until'] ?? 0) > time()) {
            $wait = max($wait, (int)($e['last_try'] ?? 0) + LOGIN_SOFT_DELAY - time());
        }
    }
    return max(0, $wait);
}

/** Registra un fallo de login. Devuelve true si procede avisar a los administradores. */
function login_fail(string $ip, string $acct, bool $trusted): bool
{
    return (bool)json_update(data_path('attempts.json'), function (array &$d) use ($ip, $acct, $trusted) {
        $now = time();
        throttle_gc($d, $now);
        foreach (login_keys($ip, $acct, $trusted) as $k => $max) {
            throttle_bump($d, $k, $max, $now);
        }
        // Contador por cuenta (todas las IP): nunca bloquea, solo activa el modo lento y el aviso.
        $k = 'acct:' . $acct;
        $e = $d[$k] ?? ['fails' => 0, 'first' => $now];
        if ($now - $e['first'] > LOGIN_WINDOW) {
            $e['fails'] = 0;
            $e['first'] = $now;
        }
        $e['fails']++;
        $e['last'] = $now;
        if (!$trusted) {
            $e['last_try'] = $now;
        }
        $alert = false;
        if ($e['fails'] >= LOGIN_USER_SOFT) {
            $e['soft_until'] = $now + LOGIN_WINDOW;
            if (($e['alerted'] ?? 0) < $now - 3600) {
                $e['alerted'] = $now;
                $alert = true;
            }
        }
        $d[$k] = $e;
        return $alert;
    });
}

/** Tras un login correcto se borra solo el contador IP+cuenta usado (no el de la IP ni el de la otra categoría). */
function login_success(string $ip, string $acct, bool $trusted): void
{
    throttle_clear(['pair:' . ($trusted ? 'dev' : 'anon') . ':' . $ip . '|' . $acct]);
}

/** Avisa por correo a los administradores de un posible ataque contra una cuenta. */
function alert_admins(string $acct): void
{
    $sent = 0;
    foreach (users_all() as $u) {
        $to = user_email($u);
        if ($u['role'] === 'admin' && $to !== '') {
            $body = "Se han producido " . LOGIN_USER_SOFT . " o más intentos fallidos de acceso a la cuenta «{$acct}» "
                  . "del panel welcome.bezares.com en los últimos " . (LOGIN_WINDOW / 60) . " minutos, desde una o varias IP.\r\n\r\n"
                  . "La cuenta NO se ha bloqueado: los navegadores donde ya se inició sesión siguen entrando con normalidad y "
                  . "el resto solo puede intentarlo una vez cada " . LOGIN_SOFT_DELAY . " segundos.\r\n\r\n"
                  . "Revise el registro auth.log de la carpeta de datos. Si el ataque continúa, bloquee las IP en cPanel "
                  . "(Bloqueador de IP / cPHulk) o limite el panel con ADMIN_ALLOW_IPS.\r\n";
            $sent += (int)send_mail($to, 'Welcome Bezares · Aviso de seguridad: intentos de acceso fallidos', $body);
        }
    }
    auth_log('alert_admins:' . $sent, $acct);
}

/* --- Navegador de confianza (cookie firmada tras un login correcto) --- */

function device_cookie_name(): string
{
    return is_https() ? '__Host-WKDEV' : 'WKDEV';
}

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function device_issue(string $username): void
{
    $exp = time() + TRUSTED_DEVICE_DAYS * 86400;
    $payload = b64url(mb_strtolower($username)) . '.' . $exp;
    $val = $payload . '.' . hash_hmac('sha256', $payload, app_secret());
    setcookie(device_cookie_name(), $val, [
        'expires' => $exp, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict',
    ]);
}

/** true si este navegador ya inició sesión correctamente con esa cuenta. */
function device_trusted(string $acct): bool
{
    $v = (string)($_COOKIE[device_cookie_name()] ?? '');
    $p = explode('.', $v);
    if (count($p) !== 3 || !ctype_digit($p[1]) || (int)$p[1] < time()) {
        return false;
    }
    $payload = $p[0] . '.' . $p[1];
    if (!hash_equals(hash_hmac('sha256', $payload, app_secret()), $p[2])) {
        return false;
    }
    $u = base64_decode(strtr($p[0], '-_', '+/'), true);
    return $u !== false && hash_equals($u, mb_strtolower($acct));
}

function auth_log(string $event, string $user = ''): void
{
    $file = data_path('auth.log');
    if (is_file($file) && filesize($file) > 1024 * 1024) {
        @rename($file, $file . '.1');
    }
    $line = sprintf("%s\t%s\t%s\t%s\n", date('c'), client_ip(), str_replace(["\t", "\n", "\r"], ' ', mb_substr($user, 0, 100)), $event);
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    @chmod($file, 0600);
}

/* ---------------------------------------------------------------- *
 *  Subida de imágenes
 *  - Mapas de bits: se vuelven a codificar con GD (elimina metadatos y contenido oculto).
 *  - SVG: se depuran con una lista cerrada de etiquetas y atributos y, si el servidor tiene
 *    Imagick con soporte SVG, se convierten a PNG.
 * ---------------------------------------------------------------- */

/** Devuelve la ruta relativa 'uploads/...' o null si no se envió archivo. */
function handle_upload(string $field, string $prefix): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if (is_array($f['error'])) {
        throw new RuntimeException('Envío de archivo no válido.');
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('La imagen supera el tamaño máximo (' . (MAX_UPLOAD_BYTES / 1048576) . ' MB).');
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('No se pudo subir el archivo.');
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $isSvg = $mime === 'image/svg+xml'
          || (in_array($mime, ['text/plain', 'text/xml', 'application/xml', 'text/html'], true)
              && preg_match('/\.svg$/i', (string)$f['name']));

    if ($isSvg) {
        if ($f['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('El SVG supera el tamaño máximo (2 MB).');
        }
        $clean = svg_sanitize((string)file_get_contents($f['tmp_name']));
        $png = SVG_TO_PNG ? svg_to_png($clean) : null;
        [$data, $ext] = $png !== null ? [$png, 'png'] : [$clean, 'svg'];
    } else {
        $map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $info = @getimagesize($f['tmp_name']);
        if (!isset($map[$mime]) || $info === false || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException('Formato no admitido. Use SVG, PNG, JPG, WEBP o GIF.');
        }
        if ($info[0] * $info[1] > 40000000) { // evita imágenes gigantes que agoten la memoria
            throw new RuntimeException('La imagen tiene demasiados píxeles (máx. 40 megapíxeles).');
        }
        [$data, $ext] = raster_reencode($f['tmp_name'], $mime, $map[$mime]);
    }

    $name = $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (@file_put_contents(UPLOAD_DIR . '/' . $name, $data) !== strlen($data)) {
        @unlink(UPLOAD_DIR . '/' . $name);
        throw new RuntimeException('No se pudo guardar la imagen. Revise los permisos de /uploads.');
    }
    @chmod(UPLOAD_DIR . '/' . $name, 0644);
    return 'uploads/' . $name;
}

/**
 * Vuelve a codificar un mapa de bits con GD. PNG/GIF → PNG (conserva transparencia; un GIF animado
 * queda en su primer fotograma), JPEG → JPEG, WEBP → WEBP. Devuelve [bytes, extensión].
 * GD es obligatoria: sin ella no se acepta ninguna imagen (nunca se guarda el archivo original).
 */
function raster_reencode(string $file, string $mime, string $ext): array
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('La extensión GD es obligatoria para subir imágenes. Actívela en cPanel (Select PHP Version → Extensions → gd).');
    }
    $img = match ($mime) {
        'image/png'  => @imagecreatefrompng($file),
        'image/gif'  => @imagecreatefromgif($file),
        'image/jpeg' => @imagecreatefromjpeg($file),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
        default      => false,
    };
    if (!$img) {
        throw new RuntimeException('La imagen está dañada o no se puede procesar.');
    }
    ob_start();
    if ($mime === 'image/jpeg') {
        imagejpeg($img, null, 90);
        $out = 'jpg';
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagewebp($img, null, 90);
        $out = 'webp';
    } else {
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagepng($img, null, 6);
        $out = 'png';
    }
    $data = (string)ob_get_clean();
    imagedestroy($img);
    if ($data === '') {
        throw new RuntimeException('No se pudo procesar la imagen.');
    }
    return [$data, $out];
}

/** Límites de la conversión SVG → PNG (se comprueban ANTES de que Imagick lea el SVG). */
const SVG_MAX_ELEMENTS = 20000;     // elementos SVG
const SVG_MAX_DECLARED = 20000;     // px por lado declarados en width/height/viewBox
const SVG_RENDER_MAX   = 2048;      // px del lado mayor del PNG generado

/** Convierte una longitud SVG (px, pt, mm, cm, in, pc o sin unidad) a píxeles; null si no es válida. */
function svg_length_px(?string $v): ?float
{
    if ($v === null || !preg_match('/^\s*([0-9]*\.?[0-9]+(?:e[+-]?\d+)?)\s*(px|pt|pc|mm|cm|in)?\s*$/i', $v, $m)) {
        return null;
    }
    $f = ['' => 1, 'px' => 1, 'pt' => 4 / 3, 'pc' => 16, 'mm' => 96 / 25.4, 'cm' => 96 / 2.54, 'in' => 96];
    $px = (float)$m[1] * $f[strtolower($m[2] ?? '')];
    return is_finite($px) && $px > 0 ? $px : null;
}

/**
 * Convierte un SVG (ya depurado) a PNG con Imagick si el servidor lo permite; si no, null.
 * Antes de llamar a Imagick: se calcula el tamaño declarado y se rechazan dimensiones o complejidad
 * excesivas, se fija el tamaño exacto del lienzo de salida (como máximo SVG_RENDER_MAX px) y se
 * limitan memoria, píxeles y tiempo de Imagick.
 */
function svg_to_png(string $svg): ?string
{
    if (!class_exists('Imagick') || !in_array('SVG', Imagick::queryFormats('SVG'), true)) {
        return null;
    }

    // 1) Tamaño y complejidad declarados
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML($svg, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $ok ? $dom->documentElement : null;
    if (!$root) {
        return null;
    }
    if ($dom->getElementsByTagName('*')->length > SVG_MAX_ELEMENTS) {
        throw new RuntimeException('El SVG es demasiado complejo para convertirlo (más de ' . SVG_MAX_ELEMENTS . ' elementos).');
    }
    $w = svg_length_px($root->getAttribute('width') ?: null);
    $h = svg_length_px($root->getAttribute('height') ?: null);
    $vb = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
    $vbw = count($vb) === 4 ? (float)$vb[2] : 0.0;
    $vbh = count($vb) === 4 ? (float)$vb[3] : 0.0;
    if (count($vb) === 4 && (!is_finite($vbw) || !is_finite($vbh) || $vbw <= 0 || $vbh <= 0)) {
        throw new RuntimeException('El SVG tiene un viewBox no válido.');
    }
    // Proporción: width/height explícitos o, si faltan, el viewBox
    $w = $w ?? ($vbw > 0 ? ($h && $vbh > 0 ? $h * $vbw / $vbh : $vbw) : null);
    $h = $h ?? ($vbh > 0 ? ($w && $vbw > 0 ? $w * $vbh / $vbw : $vbh) : null);
    if (!$w || !$h) {
        return null; // sin tamaño determinable: se guarda como SVG depurado
    }
    if ($w > SVG_MAX_DECLARED || $h > SVG_MAX_DECLARED || $vbw > SVG_MAX_DECLARED * 10 || $vbh > SVG_MAX_DECLARED * 10
        || $w / $h > 50 || $h / $w > 50) {
        throw new RuntimeException('Las dimensiones del SVG son excesivas o desproporcionadas.');
    }

    // 2) Lienzo de salida acotado: se fijan width/height exactos en px (se conserva el viewBox)
    $scale = min(SVG_RENDER_MAX / max($w, $h), 4.0);
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));
    if (count($vb) !== 4) {
        $root->setAttribute('viewBox', '0 0 ' . round($w, 3) . ' ' . round($h, 3));
    }
    $root->setAttribute('width', (string)$tw);
    $root->setAttribute('height', (string)$th);
    $root->setAttribute('preserveAspectRatio', 'xMidYMid meet');
    $bounded = $dom->saveXML($root);

    // 3) Límites de recursos de Imagick antes de leer nada
    $limits = [
        'RESOURCETYPE_MEMORY' => 256 * 1024 * 1024,
        'RESOURCETYPE_MAP'    => 512 * 1024 * 1024,
        'RESOURCETYPE_AREA'   => 16 * 1024 * 1024,      // píxeles
        'RESOURCETYPE_DISK'   => 1024 * 1024 * 1024,
        'RESOURCETYPE_WIDTH'  => SVG_RENDER_MAX + 16,
        'RESOURCETYPE_HEIGHT' => SVG_RENDER_MAX + 16,
        'RESOURCETYPE_TIME'   => 20,                     // segundos
    ];
    foreach ($limits as $const => $value) {
        if (defined('Imagick::' . $const)) {
            Imagick::setResourceLimit(constant('Imagick::' . $const), $value);
        }
    }

    try {
        $im = new Imagick();
        $im->setResolution(72, 72);       // 1 unidad = 1 px: el lienzo es exactamente $tw × $th
        $im->setBackgroundColor(new ImagickPixel('transparent'));
        $im->setOption('svg:xml-parse-huge', 'false');
        $im->readImageBlob($bounded);
        if ($im->getImageWidth() > SVG_RENDER_MAX + 16 || $im->getImageHeight() > SVG_RENDER_MAX + 16) {
            $im->clear();
            throw new RuntimeException('El SVG generó una imagen demasiado grande.');
        }
        $im->setImageFormat('png32');
        $png = $im->getImageBlob();
        $im->clear();
        return $png !== '' ? $png : null;
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
        // Fallo o límite de recursos alcanzado en Imagick: se guarda como SVG depurado (seguro para <img>)
        error_log('[welcome-kiosk] SVG→PNG: ' . $e->getMessage());
        return null;
    }
}

/** Etiquetas SVG permitidas (lista cerrada). Todo lo demás se elimina con su contenido. */
const SVG_TAGS = [
    'svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse', 'line',
    'polyline', 'polygon', 'text', 'tspan', 'clippath', 'mask', 'lineargradient', 'radialgradient', 'stop',
    'pattern', 'style',
];

/** Atributos SVG permitidos (lista cerrada, en minúsculas). */
const SVG_ATTRS = [
    'id', 'class', 'style', 'viewbox', 'version', 'x', 'y', 'width', 'height', 'preserveaspectratio',
    'd', 'points', 'transform', 'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2', 'dx', 'dy', 'rotate',
    'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap',
    'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity',
    'clip-path', 'clip-rule', 'clippathunits', 'mask', 'maskunits', 'maskcontentunits',
    'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'spreadmethod', 'fx', 'fy',
    'patternunits', 'patterncontentunits', 'patterntransform',
    'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'letter-spacing',
    'word-spacing', 'dominant-baseline', 'alignment-baseline', 'baseline-shift', 'text-decoration',
    'display', 'visibility', 'overflow', 'color', 'enable-background', 'space', 'href', 'type', 'media',
];

/** CSS seguro: sin @import, sin url() externas, sin escapes ni expresiones. */
function svg_css_safe(string $css): bool
{
    $c = strtolower($css);
    if (preg_match('/@import|@font-face|expression\s*\(|javascript:|behavior\s*:|-moz-binding|\\\\|<|&#/', $c)) {
        return false;
    }
    // url(...) solo hacia referencias internas: url(#id)
    if (preg_match_all('/url\s*\(\s*([\'"]?)\s*([^\'")\s]*)/', $c, $m)) {
        foreach ($m[2] as $target) {
            if ($target === '' || $target[0] !== '#') {
                return false;
            }
        }
    }
    return true;
}

/**
 * Depura un SVG con listas cerradas y devuelve el SVG reconstruido.
 * Rechaza DOCTYPE/entidades, SVG anidados se eliminan, enlaces solo internos (#id), sin data: URI,
 * sin comentarios ni instrucciones de proceso. Lanza RuntimeException si el archivo no es un SVG válido.
 */
function svg_sanitize(string $xml): string
{
    if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
        throw new RuntimeException('El SVG contiene declaraciones no permitidas (DOCTYPE/ENTITY).');
    }
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $ok ? $dom->documentElement : null;
    if (!$root || strtolower($root->localName) !== 'svg') {
        throw new RuntimeException('El archivo no es un SVG válido.');
    }

    $xp = new DOMXPath($dom);
    // Comentarios e instrucciones de proceso fuera
    foreach (iterator_to_array($xp->query('//comment() | //processing-instruction()')) as $n) {
        $n->parentNode->removeChild($n);
    }

    $walk = function (DOMElement $el, bool $isRoot) use (&$walk) {
        // Atributos
        foreach (iterator_to_array($el->attributes) as $a) {
            $name = strtolower($a->localName);
            $ns = (string)$a->namespaceURI;
            $val = trim($a->value);
            $lv = strtolower(preg_replace('/\s+/', '', $val));
            $keep = in_array($name, SVG_ATTRS, true)
                && ($ns === '' || $ns === 'http://www.w3.org/1999/xlink' || $ns === 'http://www.w3.org/XML/1998/namespace');
            if ($keep && $name === 'href') {
                $keep = preg_match('/^#[A-Za-z_][\w.\-:]*$/', $val) === 1; // solo referencias internas
            }
            if ($keep && $name === 'space') {
                $keep = $ns === 'http://www.w3.org/XML/1998/namespace';
            }
            if ($keep && ($name === 'style' || str_contains($lv, 'url('))) {
                $keep = svg_css_safe($val);
            }
            if ($keep && preg_match('/javascript:|data:|vbscript:/', $lv)) {
                $keep = false;
            }
            if (!$keep) {
                $el->removeAttributeNode($a);
            }
        }
        // Hijos
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->localName);
                $svgNs = $child->namespaceURI === null || $child->namespaceURI === 'http://www.w3.org/2000/svg';
                if (!$svgNs || !in_array($tag, SVG_TAGS, true) || $tag === 'svg') { // incluye SVG anidados
                    $el->removeChild($child);
                    continue;
                }
                if ($tag === 'style') {
                    if (!svg_css_safe($child->textContent)) {
                        throw new RuntimeException('El SVG contiene estilos no permitidos (@import, url externas, etc.).');
                    }
                    $css = $child->textContent;
                    while ($child->firstChild) { $child->removeChild($child->firstChild); }
                    $child->appendChild($child->ownerDocument->createTextNode($css));
                }
                $walk($child, false);
            } elseif (!($child instanceof DOMText)) {
                $el->removeChild($child); // CDATA ya convertido a texto; cualquier otro nodo fuera
            }
        }
    };
    $walk($root, true);

    $out = $dom->saveXML($root);
    if ($out === false || $out === '') {
        throw new RuntimeException('No se pudo procesar el SVG.');
    }
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out;
}

/** Borra un archivo subido (solo dentro de /uploads y con nombre generado por la app). */
function delete_upload(?string $path): void
{
    if (!$path || !preg_match('#^uploads/([a-z]+-[a-f0-9]{16}\.(png|jpg|gif|webp|svg))$#', $path, $m)) {
        return;
    }
    $full = UPLOAD_DIR . '/' . $m[1];
    if (is_file($full)) {
        @unlink($full);
    }
}

/* ---------------------------------------------------------------- *
 *  Validación de campos
 * ---------------------------------------------------------------- */

function post_str(string $k, int $max = 500): string
{
    $v = $_POST[$k] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = str_replace("\r\n", "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function post_color(string $k, string $fallback): string
{
    $v = post_str($k, 7);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $fallback;
}

function post_size(string $k, string $fallback, bool $allowAuto = true): string
{
    $v = strtolower(str_replace(' ', '', post_str($k, 10)));
    if ($allowAuto && $v === 'auto') {
        return 'auto';
    }
    if (preg_match('/^\d{1,4}$/', $v)) {
        $v .= 'px';
    }
    return preg_match('/^\d{1,4}(px|%|vw|vh)$/', $v) ? $v : $fallback;
}

function post_num(string $k, float $min, float $max, float $fallback): float
{
    $v = str_replace(',', '.', post_str($k, 20));
    if (!is_numeric($v)) {
        return $fallback;
    }
    return max($min, min($max, (float)$v));
}

function post_enum(string $k, array $allowed, string $fallback): string
{
    $v = post_str($k, 50);
    return in_array($v, $allowed, true) ? $v : $fallback;
}

/** Acepta una URL o un código <iframe ...> y devuelve una URL https válida o ''. */
function parse_url_or_iframe(string $input): string
{
    if (stripos($input, '<iframe') !== false && preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $input, $m)) {
        $input = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $input = trim($input);
    if (!filter_var($input, FILTER_VALIDATE_URL)) {
        return '';
    }
    return strtolower((string)parse_url($input, PHP_URL_SCHEME)) === 'https' ? $input : '';
}

/** Lista de dominios que se pueden mostrar en las ventanas del kiosco (texto del administrador → array). */
function frame_domains_parse(string $text): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower($text)) as $d) {
        $d = trim($d);
        if ($d !== '' && preg_match('/^(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) {
            $out[] = $d;
        }
    }
    return array_values(array_unique($out));
}

/** "bezares.com" = ese host exacto; "*.bezares.com" = cualquier subdominio. */
function host_matches(string $host, array $list): bool
{
    $host = strtolower(rtrim($host, '.'));
    foreach ($list as $d) {
        if (str_starts_with($d, '*.') ? str_ends_with($host, substr($d, 1)) : $host === $d) {
            return true;
        }
    }
    return false;
}

/** Hosts del propio kiosco: nunca se incrustan (evita contenido del mismo origen en el iframe). */
function own_hosts(): array
{
    $h = [strtolower((string)parse_url(BASE_URL, PHP_URL_HOST))];
    if (!empty($_SERVER['HTTP_HOST'])) {
        $h[] = strtolower(preg_replace('/:\d+$/', '', (string)$_SERVER['HTTP_HOST']));
    }
    return array_values(array_unique(array_filter($h)));
}

/** Motivo por el que una URL no se puede incrustar, o null si es válida. */
function frame_url_problem(string $url, array $domains): ?string
{
    $p = parse_url($url);
    $host = strtolower((string)($p['host'] ?? ''));
    if (($p['scheme'] ?? '') !== 'https' || $host === '') {
        return 'Solo se admiten direcciones https://.';
    }
    if (isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
        return 'La dirección no puede llevar usuario, contraseña ni puerto.';
    }
    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || $host === 'localhost'
        || preg_match('/\.(local|localhost|internal|lan|home|corp|intranet)$/', $host) || !str_contains($host, '.')) {
        return 'No se permiten direcciones IP ni nombres de red local.';
    }
    if (in_array($host, own_hosts(), true)) {
        return 'No se puede incrustar el propio kiosco.';
    }
    if (!host_matches($host, $domains)) {
        return 'El dominio ' . $host . ' no está en la lista de dominios permitidos (sección Iconos).';
    }
    return null;
}

function youtube_id(string $url): string
{
    if (preg_match('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/))([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $url) ? $url : '';
}
