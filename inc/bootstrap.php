<?php
declare(strict_types=1);

require __DIR__ . '/settings.php';

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('DUMMY_HASH', '$2y$10$dM7.G7jzmD3TCXk.K/MZj.vqRrrMkGJFteSdPVJh4/aHtiW1d/IYG');

const BUILTIN_ICONS = ['globe', 'mail', 'play', 'video', 'info', 'phone', 'map', 'doc', 'star', 'home', 'link', 'calendar'];

ini_set('display_errors', '0');
error_reporting(E_ALL);

/* ---------------------------------------------------------------- *
 *  Almacenamiento JSON con bloqueo de archivo
 * ---------------------------------------------------------------- */

function data_path(string $name): string
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0750, true);
    }
    return rtrim(DATA_DIR, '/') . '/' . $name;
}

function json_read(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }
    $fp = fopen($file, 'r');
    if (!$fp) {
        return $default;
    }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $default;
}

/** Lee-modifica-escribe de forma atómica. $fn recibe el array por referencia. */
function json_update(string $file, callable $fn, array $default = [])
{
    $fp = fopen($file, 'c+');
    if (!$fp) {
        throw new RuntimeException('No se puede escribir en la carpeta de datos. Revise los permisos.');
    }
    @chmod($file, 0640);
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw !== '' ? json_decode((string)$raw, true) : null;
    if (!is_array($data)) {
        $data = $default;
    }
    $result = $fn($data);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
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
    return array_replace(config_defaults(), json_read(data_path('config.json')));
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

/** Envía el correo con el enlace de recuperación. Devuelve false si el servidor no lo acepta. */
function send_reset_mail(string $to, string $username, string $link): bool
{
    $minutes = (int)(RESET_TTL / 60);
    $subject = '=?UTF-8?B?' . base64_encode('Welcome Bezares · Cambiar contraseña / Reset password') . '?=';
    $body = "Hola {$username}:\r\n\r\n"
          . "Se ha solicitado cambiar la contraseña del panel del kiosco welcome.bezares.com.\r\n"
          . "Abra este enlace (válido {$minutes} minutos, un solo uso):\r\n\r\n{$link}\r\n\r\n"
          . "Si no lo ha solicitado usted, ignore este mensaje: su contraseña no cambiará.\r\n\r\n"
          . "---\r\n"
          . "A password reset was requested for the welcome.bezares.com kiosk panel.\r\n"
          . "Open the link above within {$minutes} minutes. If it wasn't you, ignore this email.\r\n";
    $fromName = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?=';
    $headers = "From: {$fromName} <" . MAIL_FROM . ">\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n"
             . "Auto-Submitted: auto-generated\r\n";
    return @mail($to, $subject, $body, $headers, '-f' . MAIL_FROM);
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
        @mkdir($dir, 0750, true);
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
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
         . "frame-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
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

function throttle_keys(string $ip, string $user): array
{
    $keys = ['ip:' . $ip => LOGIN_MAX_IP];
    if ($user !== '') {
        $keys['user:' . mb_strtolower(mb_substr($user, 0, 64))] = LOGIN_MAX_USER;
    }
    return $keys;
}

/** Segundos que faltan para poder volver a intentarlo (0 = permitido). */
function throttle_wait(string $ip, string $user): int
{
    $d = json_read(data_path('attempts.json'));
    $now = time();
    $wait = 0;
    foreach (array_keys(throttle_keys($ip, $user)) as $k) {
        $until = (int)($d[$k]['lock_until'] ?? 0);
        if ($until > $now) {
            $wait = max($wait, $until - $now);
        }
    }
    return $wait;
}

function throttle_fail(string $ip, string $user): void
{
    json_update(data_path('attempts.json'), function (array &$d) use ($ip, $user) {
        $now = time();
        // Limpieza de entradas antiguas
        foreach ($d as $k => $v) {
            if (($v['lock_until'] ?? 0) < $now && ($v['last'] ?? 0) < $now - 2 * LOGIN_LOCK_MAX) {
                unset($d[$k]);
            }
        }
        if (count($d) > 5000) { // tope de tamaño
            uasort($d, fn($a, $b) => ($a['last'] ?? 0) <=> ($b['last'] ?? 0));
            $d = array_slice($d, -4000, null, true);
        }
        foreach (throttle_keys($ip, $user) as $k => $max) {
            $e = $d[$k] ?? ['fails' => 0, 'first' => $now, 'lock_until' => 0, 'locks' => 0];
            if ($now - $e['first'] > LOGIN_WINDOW) {
                $e['fails'] = 0;
                $e['first'] = $now;
            }
            $e['fails']++;
            $e['last'] = $now;
            if ($e['fails'] >= $max) {
                $e['lock_until'] = $now + min(LOGIN_LOCK * (2 ** $e['locks']), LOGIN_LOCK_MAX);
                $e['locks']++;
                $e['fails'] = 0;
                $e['first'] = $now;
            }
            $d[$k] = $e;
        }
    });
}

function throttle_clear(string $ip, string $user): void
{
    json_update(data_path('attempts.json'), function (array &$d) use ($ip, $user) {
        foreach (array_keys(throttle_keys($ip, $user)) as $k) {
            unset($d[$k]);
        }
    });
}

function auth_log(string $event, string $user = ''): void
{
    $file = data_path('auth.log');
    if (is_file($file) && filesize($file) > 1024 * 1024) {
        @rename($file, $file . '.1');
    }
    $line = sprintf("%s\t%s\t%s\t%s\n", date('c'), client_ip(), str_replace(["\t", "\n", "\r"], ' ', mb_substr($user, 0, 64)), $event);
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/* ---------------------------------------------------------------- *
 *  Subida de imágenes
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
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
    $isSvg = $mime === 'image/svg+xml'
          || (in_array($mime, ['text/plain', 'text/xml', 'application/xml', 'text/html'], true)
              && preg_match('/\.svg$/i', (string)$f['name']));
    if ($isSvg) {
        $mime = 'image/svg+xml';
        svg_check((string)file_get_contents($f['tmp_name']));
    } elseif (!isset($map[$mime]) || @getimagesize($f['tmp_name']) === false) {
        throw new RuntimeException('Formato no admitido. Use SVG, PNG, JPG, WEBP o GIF.');
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    $name = $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $map[$mime];
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('No se pudo guardar la imagen. Revise los permisos de /uploads.');
    }
    @chmod(UPLOAD_DIR . '/' . $name, 0644);
    return 'uploads/' . $name;
}

/** Rechaza SVG con código activo o referencias externas. */
function svg_check(string $xml): void
{
    $bad = 'El SVG contiene elementos no permitidos (scripts, enlaces externos o contenido incrustado).';
    if ($xml === '' || stripos($xml, '<!ENTITY') !== false || stripos($xml, '<!DOCTYPE') !== false) {
        throw new RuntimeException($bad);
    }
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || !$dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') {
        throw new RuntimeException('El archivo no es un SVG válido.');
    }
    foreach ((new DOMXPath($dom))->query('//*') as $el) {
        $name = strtolower($el->localName);
        if (in_array($name, ['script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener'], true)) {
            throw new RuntimeException($bad);
        }
        foreach ($el->attributes as $a) {
            $an = strtolower($a->localName);
            $av = strtolower(preg_replace('/\s+/', '', $a->value));
            if (str_starts_with($an, 'on')
                // enlaces con esquema (http:, //, etc.) salvo imágenes incrustadas; las rutas relativas no se cargan en <img>
                || ($an === 'href' && preg_match('#^([a-z][a-z0-9+.-]*:|//)#', $av) && !str_starts_with($av, 'data:image/'))
                || str_contains($av, 'javascript:')) {
                throw new RuntimeException($bad);
            }
        }
    }
    if (preg_match('/url\(\s*[\'"]?\s*(?!#)[a-z]+:/i', $xml) || stripos($xml, '@import') !== false) {
        throw new RuntimeException($bad);
    }
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

/** Acepta una URL o un código <iframe ...> y devuelve una URL http(s) válida o ''. */
function parse_url_or_iframe(string $input): string
{
    if (stripos($input, '<iframe') !== false && preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $input, $m)) {
        $input = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $input = trim($input);
    if (!filter_var($input, FILTER_VALIDATE_URL)) {
        return '';
    }
    $scheme = strtolower((string)parse_url($input, PHP_URL_SCHEME));
    return in_array($scheme, ['https', 'http'], true) ? $input : '';
}

function youtube_id(string $url): string
{
    if (preg_match('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/))([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $url) ? $url : '';
}
