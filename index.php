<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

require_allowed_ip();
session_boot();
security_headers();

if (current_user()) {
    redirect('admin.php');
}

$setupMode = !users_exist();
$error = '';
$ip = client_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($setupMode) {
        /* ---------- Instalación inicial ---------- */
        $wait = throttle_wait(['setup:' . $ip]);
        $key  = (string)($_POST['setup_key'] ?? '');
        if ($wait > 0) {
            $error = 'Demasiados intentos. Espere ' . ceil($wait / 60) . ' min.';
        } elseif (SETUP_KEY === 'CAMBIAR-ESTA-CLAVE' || strlen(SETUP_KEY) < 12) {
            $error = 'Edite inc/settings.php y cambie SETUP_KEY (mín. 12 caracteres) antes de instalar.';
        } elseif (!hash_equals(SETUP_KEY, $key)) {
            throttle_fail(['setup:' . $ip => 5]);
            auth_log('setup_fail');
            sleep(1);
            $error = 'Clave de instalación incorrecta.';
        } else {
            $au = post_str('admin_user', 100);
            $ap = (string)($_POST['admin_pass'] ?? '');
            $mu = post_str('maint_user', 100);
            $mp = (string)($_POST['maint_pass'] ?? '');
            $ae = post_str('admin_email', 100);
            $me = post_str('maint_email', 100);
            if (!username_valid($au) || !username_valid($mu)) {
                $error = 'Usuario: una dirección de correo o 3–64 caracteres (letras, números, punto, guion).';
            } elseif (!email_valid($ae) || !email_valid($me)) {
                $error = 'El correo indicado no es válido.';
            } elseif (strcasecmp($au, $mu) === 0) {
                $error = 'Los dos usuarios deben ser distintos.';
            } elseif ($p = password_problem($ap, $au) ?? password_problem($mp, $mu)) {
                $error = $p;
            } else {
                users_update(function (array &$users) use ($au, $ap, $mu, $mp, $ae, $me) {
                    if ($users) { return; } // carrera: ya instalado
                    $users[] = ['username' => $au, 'role' => 'admin', 'hash' => password_make($ap), 'email' => $ae];
                    $users[] = ['username' => $mu, 'role' => 'maintainer', 'hash' => password_make($mp), 'email' => $me];
                });
                auth_log('setup_ok', $au);
                $_SESSION['flash'][] = ['ok', 'Instalación completada. Inicie sesión.'];
                redirect('index.php');
            }
        }
    } else {
        /* ---------- Inicio de sesión ---------- */
        $user = post_str('username', 100);
        $pass = (string)($_POST['password'] ?? '');
        $pass = mb_substr($pass, 0, 256);
        $acct    = login_account_key($user);           // mismo contador entre usuario y correo
        $trusted = device_trusted($acct);              // navegador donde esa cuenta ya entró antes
        $wait    = login_wait($ip, $acct, $trusted);

        if ($wait > 0) {
            auth_log('blocked' . ($trusted ? '_trusted' : ''), $user);
            $error = $wait <= 60
                ? 'Demasiados intentos. Espere ' . $wait . ' s y vuelva a intentarlo.'
                : 'Demasiados intentos fallidos desde esta conexión. Inténtelo de nuevo en ' . ceil($wait / 60) . ' min o use «¿Olvidó su contraseña?».';
        } else {
            $u  = user_find_login($user);
            $ok = password_verify($pass, $u['hash'] ?? DUMMY_HASH) && $u !== null;
            if ($ok) {
                login_success($ip, $acct, $trusted);
                device_issue($u['username']);
                if (password_needs_rehash($u['hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
                    $newHash = password_make($pass);
                    users_update(function (array &$users) use ($u, $newHash) {
                        foreach ($users as &$x) {
                            if ($x['username'] === $u['username']) { $x['hash'] = $newHash; }
                        }
                    });
                    $u['hash'] = $newHash;
                }
                login_user($u, !empty($_POST['remember']));
                auth_log('login_ok', $u['username']);
                redirect('admin.php');
            }
            if (login_fail($ip, $acct, $trusted)) {
                alert_admins($acct);
            }
            auth_log('login_fail' . ($trusted ? '_trusted' : ''), $user);
            sleep(1);
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}

$flashes = flash_take();
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Acceso · Welcome Bezares</title>
<link rel="stylesheet" href="assets/admin.css">
<script src="assets/login.js" defer></script>
<link rel="icon" href="assets/favicon-light.svg" type="image/svg+xml" media="(prefers-color-scheme: light)">
<link rel="icon" href="assets/favicon-dark.svg" type="image/svg+xml" media="(prefers-color-scheme: dark)">
</head>
<body class="login-page">
<main class="login-card">
  <img class="login-logo" src="<?= e(config_get()['default_logo']) ?>" alt="Bezares">
  <?php foreach ($flashes as [$t, $m]): ?><p class="msg <?= e($t) ?>"><?= e($m) ?></p><?php endforeach; ?>
  <?php if ($error): ?><p class="msg err"><?= e($error) ?></p><?php endif; ?>

  <?php if ($setupMode): ?>
    <h1>Instalación inicial</h1>
    <p class="hint">Cree el usuario administrador y el usuario mantenedor. Necesita la clave definida en <code>inc/settings.php</code>.</p>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>Clave de instalación<input type="password" name="setup_key" required></label>
      <fieldset><legend>Administrador</legend>
        <label>Usuario<input name="admin_user" required maxlength="100" autocomplete="username"></label>
        <label>Contraseña<input type="password" name="admin_pass" required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password"></label>
        <label>Correo para recuperar la contraseña (si el usuario no es ya un correo)<input type="email" name="admin_email" maxlength="100"></label>
      </fieldset>
      <fieldset><legend>Mantenedor</legend>
        <label>Usuario<input name="maint_user" required maxlength="100" autocomplete="username"></label>
        <label>Contraseña<input type="password" name="maint_pass" required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password"></label>
        <label>Correo para recuperar la contraseña (si el usuario no es ya un correo)<input type="email" name="maint_email" maxlength="100"></label>
      </fieldset>
      <p class="hint">Contraseñas: mínimo <?= MIN_PASSWORD ?> caracteres y 3 tipos (minúsculas, mayúsculas, números, símbolos).</p>
      <button class="btn primary" type="submit">Crear usuarios</button>
    </form>
  <?php else: ?>
    <h1>Acceso</h1>
    <form method="post">
      <?= csrf_field() ?>
      <label>Usuario o correo<input name="username" required autocomplete="username" autofocus maxlength="100"></label>
      <label>Contraseña<input type="password" name="password" required autocomplete="current-password" maxlength="256"></label>
      <label class="radio remember"><input type="checkbox" name="remember" value="1"> Mantener sesión iniciada</label>
      <p class="hint remember-hint">Durante <?= REMEMBER_DAYS ?> días en este navegador. No lo marque en equipos compartidos.</p>
      <button class="btn primary" type="submit">Entrar</button>
    </form>
    <p class="login-links"><a href="forgot.php">¿Olvidó su contraseña?</a></p>
  <?php endif; ?>
</main>
</body>
</html>
