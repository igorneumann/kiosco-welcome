<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

session_boot();
security_headers();

if (current_user()) {
    redirect('admin.php');
}

$ip = client_ip();
$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = post_str('username', 100);
    $wait = throttle_wait('reset:' . $ip, '');

    if ($wait > 0) {
        $error = 'Demasiadas solicitudes. Inténtelo de nuevo en ' . ceil($wait / 60) . ' min.';
    } elseif ($id === '') {
        $error = 'Indique su usuario o correo.';
    } else {
        // Cada solicitud cuenta para el límite por IP (5 cada 15 min), exista o no el usuario.
        throttle_fail('reset:' . $ip, '');
        $u = user_find_login($id);
        $email = $u ? user_email($u) : '';

        if ($u && $email !== '') {
            $token = bin2hex(random_bytes(32));
            $allowed = json_update(data_path('resets.json'), function (array &$d) use ($u, $token) {
                $now = time();
                foreach ($d as $k => $r) { // limpieza
                    if (($r['expires'] ?? 0) < $now && ($r['first'] ?? 0) < $now - 3600) { unset($d[$k]); }
                }
                $key = mb_strtolower($u['username']);
                $r = $d[$key] ?? ['count' => 0, 'first' => $now];
                if ($now - $r['first'] > 3600) { $r = ['count' => 0, 'first' => $now]; }
                if ($r['count'] >= RESET_MAX_PER_HOUR) { return false; }
                $r['count']++;
                $r['hash'] = hash('sha256', $token);
                $r['expires'] = $now + RESET_TTL;
                $d[$key] = $r;
                return true;
            });
            if ($allowed) {
                $link = rtrim(BASE_URL, '/') . '/reset.php?u=' . rawurlencode($u['username']) . '&t=' . $token;
                $ok = send_reset_mail($email, $u['username'], $link);
                auth_log($ok ? 'reset_mail_sent' : 'reset_mail_FAILED', $u['username']);
            } else {
                auth_log('reset_mail_limit', $u['username']);
            }
        } else {
            auth_log($u ? 'reset_no_email' : 'reset_unknown_user', $id);
            usleep(random_int(300000, 900000)); // mismo tiempo de respuesta aproximado que un envío real
        }
        // Respuesta idéntica en todos los casos: no revela si el usuario existe.
        $sent = true;
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Recuperar contraseña · Welcome Bezares</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
<main class="login-card">
  <img class="login-logo" src="<?= e(config_get()['default_logo']) ?>" alt="Bezares">
  <h1>¿Olvidó su contraseña?</h1>
  <?php if ($error): ?><p class="msg err"><?= e($error) ?></p><?php endif; ?>
  <?php if ($sent): ?>
    <p class="msg ok">Si el usuario existe y tiene un correo asociado, recibirá en unos minutos un enlace para cambiar la contraseña. El enlace caduca en <?= (int)(RESET_TTL / 60) ?> minutos.</p>
    <p class="hint">¿No le llega? Revise la carpeta de spam o pida al administrador que le cambie la contraseña desde el panel.</p>
  <?php else: ?>
    <p class="hint">Indique su usuario o su correo y le enviaremos un enlace para crear una contraseña nueva.</p>
    <form method="post">
      <?= csrf_field() ?>
      <label>Usuario o correo<input name="username" required maxlength="100" autocomplete="username" autofocus></label>
      <button class="btn primary" type="submit">Enviar enlace</button>
    </form>
  <?php endif; ?>
  <p class="login-links"><a href="index.php">← Volver al acceso</a></p>
</main>
</body>
</html>
