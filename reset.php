<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

session_boot();
security_headers();

$ip = client_ip();
$un = (string)($_GET['u'] ?? $_POST['u'] ?? '');
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$un = mb_substr($un, 0, 100);
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
$error = '';
$done = false;

/** Comprueba el enlace sin consumirlo. */
function reset_valid(string $un, string $token): bool
{
    if ($un === '' || $token === '') {
        return false;
    }
    $r = json_read(data_path('resets.json'))[mb_strtolower($un)] ?? null;
    return $r && !empty($r['hash']) && ($r['expires'] ?? 0) >= time()
        && hash_equals($r['hash'], hash('sha256', $token)) && user_find($un) !== null;
}

$wait = throttle_wait('resetlink:' . $ip, '');
if ($wait > 0) {
    $valid = false;
    $error = 'Demasiados intentos. Inténtelo de nuevo en ' . ceil($wait / 60) . ' min.';
} else {
    $valid = reset_valid($un, $token);
    if (!$valid) {
        throttle_fail('resetlink:' . $ip, '');
        auth_log('reset_link_invalid', $un);
    }
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = (string)($_POST['new'] ?? '');
    $u = user_find($un);
    if ($new !== (string)($_POST['new2'] ?? '')) {
        $error = 'Las contraseñas no coinciden.';
    } elseif ($p = password_problem($new, $u['username'])) {
        $error = $p;
    } else {
        // Consumir el enlace de forma atómica (un solo uso)
        $consumed = json_update(data_path('resets.json'), function (array &$d) use ($un, $token) {
            $k = mb_strtolower($un);
            $r = $d[$k] ?? null;
            if (!$r || empty($r['hash']) || ($r['expires'] ?? 0) < time() || !hash_equals($r['hash'], hash('sha256', $token))) {
                return false;
            }
            unset($d[$k]['hash'], $d[$k]['expires']);
            return true;
        });
        if ($consumed) {
            $hash = password_make($new);
            users_update(function (array &$users) use ($u, $hash) {
                foreach ($users as &$x) { if ($x['username'] === $u['username']) { $x['hash'] = $hash; } }
            });
            throttle_clear($ip, $u['username']); // desbloquea el acceso si estaba bloqueado por fallos
            logout();                              // cierra cualquier sesión de este navegador
            auth_log('reset_password_ok', $u['username']);
            $done = true;
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
