<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

require_allowed_ip();
session_boot();
security_headers();

$ip = client_ip();
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$un = (string)($isPost ? ($_POST['u'] ?? '') : ($_GET['u'] ?? ''));
$token = (string)($isPost ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));
$un = mb_substr($un, 0, 100);
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
$error = '';
$done = false;

/**
 * Comprueba el enlace sin consumirlo: token correcto, no caducado, y la contraseña no ha cambiado
 * desde que se pidió (si cambió por otra vía, el enlace deja de valer).
 */
function reset_record_valid(?array $r, ?array $u, string $token): bool
{
    return $r && $u && !empty($r['hash']) && ($r['expires'] ?? 0) >= time()
        && hash_equals($r['hash'], hash('sha256', $token))
        && hash_equals((string)($r['pwfp'] ?? ''), password_fingerprint($u['hash']));
}

// Límites: visitar un enlace (GET) solo se limita de forma muy holgada para evitar abusos (abrir un
// enlace caducado no penaliza); los envíos del formulario (POST) con enlace no válido, de forma estricta.
$viewKey = 'resetview:' . $ip;
$postKey = 'resetlink:' . $ip;
$wait = throttle_wait($isPost ? [$postKey] : [$viewKey]);

if ($wait > 0) {
    $valid = false;
    $error = 'Demasiados intentos. Inténtelo de nuevo en ' . ceil($wait / 60) . ' min.';
} else {
    $valid = $un !== '' && $token !== ''
        && reset_record_valid(json_read(data_path('resets.json'))[mb_strtolower($un)] ?? null, user_find($un), $token);
    if (!$valid && ($un !== '' || $token !== '')) {
        throttle_fail($isPost ? [$postKey => 10] : [$viewKey => 60]);
        auth_log($isPost ? 'reset_post_invalid' : 'reset_link_invalid', $un);
    }
}

if ($valid && $isPost) {
    csrf_check();
    $new = (string)($_POST['new'] ?? '');
    $u = user_find($un);
    if ($new !== (string)($_POST['new2'] ?? '')) {
        $error = 'Las contraseñas no coinciden.';
    } elseif ($p = password_problem($new, $u['username'])) {
        $error = $p;
    } else {
        // 1) El hash se calcula ANTES de tocar ningún archivo.
        $hash = password_make($new);
        // 2) Con el enlace bloqueado: se revalida, se guarda la contraseña y SOLO si eso funciona se
        //    consume el enlace. Si falla la escritura de usuarios, el enlace sigue siendo válido.
        //    Si fallara la escritura del enlace, deja de valer igualmente porque la contraseña ya cambió.
        try {
            $ok = json_update(data_path('resets.json'), function (array &$d) use ($un, $token, $hash) {
                $k = mb_strtolower($un);
                $u = user_find($un);
                if (!reset_record_valid($d[$k] ?? null, $u, $token)) {
                    return false;
                }
                users_update(function (array &$users) use ($u, $hash) {
                    foreach ($users as &$x) { if ($x['username'] === $u['username']) { $x['hash'] = $hash; } }
                });
                unset($d[$k]['hash'], $d[$k]['expires'], $d[$k]['pwfp']);
                return true;
            });
        } catch (RuntimeException $ex) {
            error_log('[welcome-kiosk] reset: ' . $ex->getMessage());
            $ok = null;
        }
        if ($ok === true) {
            throttle_clear(['pair:anon:' . $ip . '|' . mb_strtolower($u['username'])]); // levanta el bloqueo de esta conexión para esa cuenta
            logout();
            auth_log('reset_password_ok', $u['username']);
            $done = true;
        } elseif ($ok === null) {
            $error = 'No se pudo guardar la contraseña por un error del servidor. El enlace sigue siendo válido: inténtelo de nuevo en unos minutos.';
        } else {
            $valid = false;
        }
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>Nueva contraseña · Welcome Bezares</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
<main class="login-card">
  <img class="login-logo" src="<?= e(config_get()['default_logo']) ?>" alt="Bezares">
  <h1>Nueva contraseña</h1>
  <?php if ($done): ?>
    <p class="msg ok">Contraseña cambiada. Todas las sesiones abiertas con la contraseña anterior se han cerrado.</p>
    <a class="btn primary" href="index.php">Iniciar sesión</a>
  <?php elseif (!$valid): ?>
    <p class="msg err"><?= e($error ?: 'El enlace no es válido o ha caducado.') ?></p>
    <p class="login-links"><a href="forgot.php">Solicitar un enlace nuevo</a> · <a href="index.php">Volver al acceso</a></p>
  <?php else: ?>
    <?php if ($error): ?><p class="msg err"><?= e($error) ?></p><?php endif; ?>
    <p class="hint">Usuario: <strong><?= e($un) ?></strong></p>
    <form method="post" action="reset.php">
      <?= csrf_field() ?>
      <input type="hidden" name="u" value="<?= e($un) ?>">
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <label>Nueva contraseña<input type="password" name="new" required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password" autofocus></label>
      <label>Repetir contraseña<input type="password" name="new2" required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password"></label>
      <p class="hint">Mínimo <?= MIN_PASSWORD ?> caracteres y 3 tipos (minúsculas, mayúsculas, números, símbolos).</p>
      <button class="btn primary" type="submit">Guardar contraseña</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
