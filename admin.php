<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

session_boot();
security_headers();

$me = current_user();
if (!$me) {
    redirect('index.php');
}
$isAdmin = $me['role'] === 'admin';

/* ================================================================
 *  Acciones (POST → redirección)
 * ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action', 40);
    $anchor = '';

    $adminOnly = ['save_general', 'upload_header', 'remove_header', 'upload_background', 'remove_background',
                  'upload_default_logo', 'save_icon', 'add_icon', 'delete_icon', 'move_icon',
                  'user_add', 'user_password', 'user_delete', 'reset_all'];
    if (in_array($action, $adminOnly, true) && !$isAdmin) {
        http_response_code(403);
        exit('No tiene permiso para esta acción.');
    }

    try {
        switch ($action) {

            /* ---------- Mantenedor (y administrador) ---------- */
            case 'save_center':
                $anchor = 'contenido';
                config_update(function (array &$c) {
                    $c['center_text']      = post_str('center_text', 2000);
                    $c['center_font_mode'] = post_enum('center_font_mode', ['auto', 'fixed'], 'auto');
                    $c['center_font_size'] = post_num('center_font_size', 1, 40, 6);
                });
                flash('Texto central guardado.');
                break;

            case 'upload_logo':
                $anchor = 'logos';
                $path = handle_upload('logo', 'logo');
                if (!$path) { throw new RuntimeException('Seleccione una imagen.'); }
                config_update(function (array &$c) use ($path) {
                    $c['logos'][] = $path;
                    $c['bottom_logo'] = $path;
                });
                flash('Logo subido y seleccionado.');
                break;

            case 'select_logo':
                $anchor = 'logos';
                $sel = post_str('logo', 200);
                config_update(function (array &$c) use ($sel) {
                    if ($sel === '' || in_array($sel, $c['logos'], true)) {
                        $c['bottom_logo'] = $sel;
                    }
                });
                flash('Logo seleccionado.');
                break;

            case 'delete_logo':
                $anchor = 'logos';
                $del = post_str('logo', 200);
                config_update(function (array &$c) use ($del) {
                    if (in_array($del, $c['logos'], true)) {
                        $c['logos'] = array_values(array_diff($c['logos'], [$del]));
                        if ($c['bottom_logo'] === $del) { $c['bottom_logo'] = ''; }
                        delete_upload($del);
                    }
                });
                flash('Logo eliminado.');
                break;

            case 'reset_content':
                $anchor = 'contenido';
                config_update(function (array &$c) {
                    $d = config_defaults();
                    foreach ($c['logos'] as $l) { delete_upload($l); }
                    $c['logos']            = [];
                    $c['bottom_logo']      = '';
                    $c['center_text']      = '';
                    $c['center_font_mode'] = $d['center_font_mode'];
                    $c['center_font_size'] = $d['center_font_size'];
                });
                auth_log('reset_content', $me['username']);
                flash('Configuración restablecida: sin texto central, logo de Bezares y logos subidos eliminados.');
                break;

            case 'change_own_password':
                $anchor = 'cuenta';
                $cur = (string)($_POST['current'] ?? '');
                $new = (string)($_POST['new'] ?? '');
                if (!password_verify($cur, $me['hash'])) { throw new RuntimeException('La contraseña actual no es correcta.'); }
                if ($new !== (string)($_POST['new2'] ?? '')) { throw new RuntimeException('Las contraseñas nuevas no coinciden.'); }
                if ($p = password_problem($new, $me['username'])) { throw new RuntimeException($p); }
                $hash = password_make($new);
                users_update(function (array &$users) use ($me, $hash) {
                    foreach ($users as &$u) { if ($u['username'] === $me['username']) { $u['hash'] = $hash; } }
                });
                $_SESSION['pwfp'] = password_fingerprint($hash);
                auth_log('password_changed', $me['username']);
                flash('Contraseña cambiada. Las demás sesiones abiertas se han cerrado.');
                break;

            /* ---------- Solo administrador ---------- */
            case 'save_general':
                $anchor = 'apariencia';
                config_update(function (array &$c) {
                    $d = config_defaults();
                    $texts = array_values(array_filter(array_map('trim', explode("\n", post_str('welcome_texts', 500))), 'strlen'));
                    $c['welcome_texts']     = $texts ? array_slice($texts, 0, 10) : $d['welcome_texts'];
                    $c['welcome_interval']  = post_num('welcome_interval', 1.5, 60, $d['welcome_interval']);
                    $c['welcome_color']     = post_color('welcome_color', $d['welcome_color']);
                    $c['welcome_color2']    = post_color('welcome_color2', $d['welcome_color2']);
                    $c['footer_title']      = post_str('footer_title', 120);
                    $c['footer_title_color']= post_color('footer_title_color', $d['footer_title_color']);
                    $c['footer_strip']      = post_color('footer_strip', $d['footer_strip']);
                    $c['footer_strip_height'] = post_num('footer_strip_height', 0, 15, $d['footer_strip_height']);
                    $c['text_color']        = post_color('text_color', $d['text_color']);
                    $c['line_color']        = post_color('line_color', $d['line_color']);
                    $c['page_bg']           = post_color('page_bg', $d['page_bg']);
                    $c['footer_bg']         = post_color('footer_bg', $d['footer_bg']);
                    $c['footer_fg']         = post_color('footer_fg', $d['footer_fg']);
                    $c['bg_pulse_scale']    = post_num('bg_pulse_scale', 1, 1.3, $d['bg_pulse_scale']);
                    $c['bg_pulse_duration'] = post_num('bg_pulse_duration', 0.5, 10, $d['bg_pulse_duration']);
                    $c['popup_idle_close']  = (int)post_num('popup_idle_close', 0, 3600, 0);
                });
                flash('Apariencia guardada.');
                break;

            case 'upload_header':
            case 'upload_background':
            case 'upload_default_logo':
                $anchor = 'apariencia';
                $map = ['upload_header' => ['header_image', 'header'], 'upload_background' => ['background_image', 'bg'],
                        'upload_default_logo' => ['default_logo', 'deflogo']];
                [$key, $prefix] = $map[$action];
                $path = handle_upload('image', $prefix);
                if (!$path) { throw new RuntimeException('Seleccione una imagen.'); }
                config_update(function (array &$c) use ($key, $path) {
                    delete_upload($c[$key]);
                    $c[$key] = $path;
                });
                flash('Imagen actualizada.');
                break;

            case 'remove_header':
            case 'remove_background':
                $anchor = 'apariencia';
                $key = $action === 'remove_header' ? 'header_image' : 'background_image';
                config_update(function (array &$c) use ($key) {
                    delete_upload($c[$key]);
                    $c[$key] = '';
                });
                flash('Imagen quitada.');
                break;

            case 'add_icon':
                $anchor = 'iconos';
                config_update(function (array &$c) {
                    if (count($c['icons']) >= 8) { throw new RuntimeException('Máximo 8 iconos.'); }
                    $tpl = config_defaults()['icons'][0];
                    $tpl['id'] = 'i' . bin2hex(random_bytes(4));
                    $tpl['label'] = 'Nuevo';
                    $tpl['icon'] = 'info';
                    $tpl['display'] = 'popup';
                    $tpl['width'] = '80%';
                    $tpl['height'] = '80vh';
                    $tpl['close_size'] = 'medium';
                    $c['icons'][] = $tpl;
                });
                flash('Icono añadido. Configúrelo abajo.');
                break;

            case 'delete_icon':
            case 'move_icon':
            case 'save_icon':
                $id = post_str('id', 20);
                $anchor = 'icon-' . $id;
                $upload = $action === 'save_icon' ? handle_upload('icon_file', 'icon') : null;
                config_update(function (array &$c) use ($action, $id, $upload, &$anchor) {
                    $idx = null;
                    foreach ($c['icons'] as $i => $ic) { if ($ic['id'] === $id) { $idx = $i; } }
                    if ($idx === null) { delete_upload($upload); throw new RuntimeException('Icono no encontrado.'); }

                    if ($action === 'delete_icon') {
                        delete_upload($c['icons'][$idx]['icon_image']);
                        array_splice($c['icons'], $idx, 1);
                        $anchor = 'iconos';
                        return;
                    }
                    if ($action === 'move_icon') {
                        $to = $idx + (post_str('dir', 4) === 'up' ? -1 : 1);
                        if ($to >= 0 && $to < count($c['icons'])) {
                            [$c['icons'][$idx], $c['icons'][$to]] = [$c['icons'][$to], $c['icons'][$idx]];
                        }
                        return;
                    }
                    // save_icon
                    $ic = $c['icons'][$idx];
                    $ic['label'] = post_str('label', 40);
                    $ic['icon']  = post_enum('icon', BUILTIN_ICONS, 'info');
                    if ($upload) {
                        delete_upload($ic['icon_image']);
                        $ic['icon_image'] = $upload;
                    } elseif (!empty($_POST['icon_remove'])) {
                        delete_upload($ic['icon_image']);
                        $ic['icon_image'] = '';
                    }
                    $ic['type'] = post_enum('type', ['url', 'youtube'], 'url');
                    $raw = post_str('url', 2000);
                    if ($ic['type'] === 'youtube') {
                        $yid = youtube_id(parse_url_or_iframe($raw) ?: $raw);
                        if ($yid === '') { throw new RuntimeException('El enlace de YouTube no es válido.'); }
                        $ic['youtube_id'] = $yid;
                        $ic['url'] = 'https://youtu.be/' . $yid;
                    } else {
                        $url = parse_url_or_iframe($raw);
                        if ($url === '') { throw new RuntimeException('La dirección (URL o código iframe) no es válida.'); }
                        $ic['url'] = $url;
                        $ic['youtube_id'] = '';
                        // Si se pegó un iframe con altura, la tomamos
                        if (stripos($raw, '<iframe') !== false && preg_match('/height\s*:\s*(\d{2,4}px)/i', $raw, $hm)
                            && post_str('height', 10) === '') {
                            $_POST['height'] = $hm[1];
                        }
                    }
                    $ic['display']        = post_enum('display', ['popup', 'fullscreen'], 'popup');
                    $ic['width']          = post_size('width', '80%', false);
                    $ic['height']         = post_size('height', 'auto');
                    $ic['close_text']     = post_str('close_text', 40);
                    $ic['close_bg']       = post_color('close_bg', '#e30613');
                    $ic['close_fg']       = post_color('close_fg', '#ffffff');
                    $ic['close_position'] = post_enum('close_position', ['bottom-center', 'top-right', 'top-left', 'bottom-right', 'bottom-left'], 'bottom-center');
                    $ic['close_size']     = post_enum('close_size', ['small', 'medium', 'large', 'xlarge'], 'large');
                    $c['icons'][$idx] = $ic;
                });
                flash($action === 'delete_icon' ? 'Icono eliminado.' : ($action === 'move_icon' ? 'Orden actualizado.' : 'Icono guardado.'));
                break;

            case 'user_add':
                $anchor = 'usuarios';
                $un = post_str('username', 100);
                $pw = (string)($_POST['password'] ?? '');
                $role = post_enum('role', ['admin', 'maintainer'], 'maintainer');
                if (!username_valid($un)) { throw new RuntimeException('Usuario: una dirección de correo o 3–64 caracteres (letras, números, punto, guion).'); }
                if (user_find($un)) { throw new RuntimeException('Ese usuario ya existe.'); }
                if ($p = password_problem($pw, $un)) { throw new RuntimeException($p); }
                $hash = password_make($pw);
                users_update(function (array &$users) use ($un, $role, $hash) {
                    $users[] = ['username' => $un, 'role' => $role, 'hash' => $hash];
                });
                auth_log('user_add:' . $role, $un);
                flash('Usuario creado.');
                break;

            case 'user_password':
                $anchor = 'usuarios';
                $un = post_str('username', 100);
                $pw = (string)($_POST['password'] ?? '');
                if (!user_find($un)) { throw new RuntimeException('Usuario no encontrado.'); }
                if ($p = password_problem($pw, $un)) { throw new RuntimeException($p); }
                $hash = password_make($pw);
                users_update(function (array &$users) use ($un, $hash) {
                    foreach ($users as &$u) { if ($u['username'] === $un) { $u['hash'] = $hash; } }
                });
                if ($un === $me['username']) { $_SESSION['pwfp'] = password_fingerprint($hash); }
                auth_log('password_reset', $un);
                flash('Contraseña de ' . $un . ' cambiada.');
                break;

            case 'user_delete':
                $anchor = 'usuarios';
                $un = post_str('username', 100);
                if ($un === $me['username']) { throw new RuntimeException('No puede eliminar su propio usuario.'); }
                users_update(function (array &$users) use ($un) {
                    $users = array_values(array_filter($users, fn($u) => $u['username'] !== $un));
                    if (!array_filter($users, fn($u) => $u['role'] === 'admin')) {
                        throw new RuntimeException('Debe quedar al menos un administrador.');
                    }
                });
                auth_log('user_delete', $un);
                flash('Usuario eliminado.');
                break;

            case 'reset_all':
                $anchor = 'peligro';
                foreach (glob(UPLOAD_DIR . '/*.{png,jpg,gif,webp,svg}', GLOB_BRACE) ?: [] as $f) {
                    delete_upload('uploads/' . basename($f));
                }
                json_update(data_path('config.json'), function (array &$c) {
                    $c = config_defaults();
                    $c['version'] = (int)(microtime(true) * 1000);
                });
                auth_log('reset_all', $me['username']);
                flash('Se ha restablecido toda la configuración de fábrica.');
                break;

            default:
                flash('Acción desconocida.', 'err');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('admin.php' . ($anchor ? '#' . $anchor : ''));
}

/* ================================================================
 *  Vista
 * ================================================================ */
$c = config_get();
$flashes = flash_take();
$currentLogo = $c['bottom_logo'] ?: '';
$iconNames = [
    'globe' => 'Web / globo', 'mail' => 'Correo', 'play' => 'Reproducir', 'video' => 'Cámara de vídeo',
    'info' => 'Información', 'phone' => 'Teléfono', 'map' => 'Ubicación', 'doc' => 'Documento',
    'star' => 'Estrella', 'home' => 'Inicio', 'link' => 'Enlace', 'calendar' => 'Calendario',
];
function sel($a, $b): string { return (string)$a === (string)$b ? ' selected' : ''; }
function chk($a, $b): string { return (string)$a === (string)$b ? ' checked' : ''; }
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel · Welcome Bezares</title>
<link rel="stylesheet" href="assets/admin.css">
<script src="assets/icons.js" defer></script>
<script src="assets/admin.js" defer></script>
</head>
<body>
<header class="topbar">
  <strong>Welcome Bezares · Panel</strong>
  <span class="who"><?= e($me['username']) ?> · <?= $isAdmin ? 'Administrador' : 'Mantenedor' ?></span>
  <a class="btn" href="<?= e(KIOSK_FILE) ?>" target="_blank" rel="noopener">Abrir kiosco ↗</a>
  <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="btn">Cerrar sesión</button></form>
</header>

<nav class="tabs">
  <a href="#contenido">Contenido</a>
  <?php if ($isAdmin): ?><a href="#apariencia">Apariencia</a><a href="#iconos">Iconos</a><a href="#usuarios">Usuarios</a><?php endif; ?>
  <a href="#cuenta">Mi cuenta</a>
</nav>

<div class="layout">
<div class="col-main">

<?php foreach ($flashes as [$t, $m]): ?><p class="msg <?= e($t) ?>"><?= e($m) ?></p><?php endforeach; ?>

<!-- ============ CONTENIDO (mantenedor) ============ -->
<section id="contenido" class="card">
  <h2>Texto central</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_center">
    <label>Texto (puede usar varias líneas)
      <textarea name="center_text" rows="4" maxlength="2000"><?= e($c['center_text']) ?></textarea></label>
    <div class="row">
      <label class="radio"><input type="radio" name="center_font_mode" value="auto"<?= chk($c['center_font_mode'], 'auto') ?>> Auto (ajusta al ancho)</label>
      <label class="radio"><input type="radio" name="center_font_mode" value="fixed"<?= chk($c['center_font_mode'], 'fixed') ?>> Manual</label>
    </div>
    <div class="row size-row">
      <label class="grow">Tamaño manual: <output id="sizeOut"><?= e($c['center_font_size']) ?></output> % del ancho de pantalla
        <input type="range" name="center_font_size" id="sizeRange" min="1" max="40" step="0.5" value="<?= e($c['center_font_size']) ?>"></label>
      <button type="button" class="btn" id="autoBtn">AUTO</button>
    </div>
    <button class="btn primary">Guardar texto</button>
  </form>
</section>

<section id="logos" class="card">
  <h2>Logotipo inferior</h2>
  <div class="logo-grid">
    <form method="post" class="logo-item<?= $currentLogo === '' ? ' active' : '' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="select_logo"><input type="hidden" name="logo" value="">
      <img src="<?= e($c['default_logo']) ?>" alt="">
      <span>Bezares (por defecto)</span>
      <?php if ($currentLogo !== ''): ?><button class="btn small">Usar</button><?php else: ?><em>En uso</em><?php endif; ?>
    </form>
    <?php foreach ($c['logos'] as $l): ?>
      <div class="logo-item<?= $currentLogo === $l ? ' active' : '' ?>">
        <img src="<?= e($l) ?>" alt="">
        <?php if ($currentLogo !== $l): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="select_logo"><input type="hidden" name="logo" value="<?= e($l) ?>"><button class="btn small">Usar</button></form>
        <?php else: ?><em>En uso</em><?php endif; ?>
        <form method="post" class="inline" data-confirm="¿Eliminar este logo?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_logo"><input type="hidden" name="logo" value="<?= e($l) ?>"><button class="btn small danger">Eliminar</button></form>
      </div>
    <?php endforeach; ?>
  </div>
  <form method="post" enctype="multipart/form-data" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload_logo">
    <input type="file" name="logo" accept="image/svg+xml,image/png,image/jpeg,image/webp,image/gif" required>
    <button class="btn primary">Subir y usar</button>
  </form>
  <p class="hint">SVG o PNG con fondo transparente recomendado. Máx. <?= MAX_UPLOAD_BYTES / 1048576 ?> MB.</p>
</section>

<section class="card warn">
  <h2>Restablecer configuración por defecto</h2>
  <p>Quita el texto central, vuelve al logo de Bezares y <strong>borra todos los logos subidos</strong>.</p>
  <form method="post" data-confirm="¿Restablecer? Se borrará el texto central y todos los logos subidos.">
    <?= csrf_field() ?><input type="hidden" name="action" value="reset_content">
    <button class="btn danger">Restablecer por defecto</button>
  </form>
</section>

<?php if ($isAdmin): ?>
<!-- ============ APARIENCIA (admin) ============ -->
<section id="apariencia" class="card">
  <h2>Imágenes</h2>
  <div class="img-grid">
    <?php foreach ([['header_image', 'upload_header', 'remove_header', 'Imagen de encabezado'],
                    ['background_image', 'upload_background', 'remove_background', 'Imagen de fondo'],
                    ['default_logo', 'upload_default_logo', '', 'Logo por defecto (Bezares)']] as [$k, $up, $rm, $title]): ?>
      <div class="img-box">
        <h3><?= e($title) ?></h3>
        <?php if ($c[$k]): ?><img src="<?= e($c[$k]) ?>" alt=""><?php else: ?><p class="hint">Sin imagen</p><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="<?= $up ?>">
          <input type="file" name="image" accept="image/svg+xml,image/png,image/jpeg,image/webp,image/gif" required>
          <button class="btn small primary">Cambiar</button>
        </form>
        <?php if ($rm && $c[$k]): ?>
          <form method="post" data-confirm="¿Quitar esta imagen?"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $rm ?>"><button class="btn small">Quitar</button></form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <h2>Textos, colores y animaciones</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_general">
    <div class="grid2">
      <label>Textos de bienvenida (uno por línea, se alternan)
        <textarea name="welcome_texts" rows="3"><?= e(implode("\n", $c['welcome_texts'])) ?></textarea></label>
      <label>Cambio de texto cada (segundos)
        <input type="number" name="welcome_interval" min="1.5" max="60" step="0.5" value="<?= e($c['welcome_interval']) ?>"></label>
    </div>
    <div class="colors">
      <label>Bienvenida (1.º, 3.º…)<input type="color" name="welcome_color" value="<?= e($c['welcome_color']) ?>"></label>
      <label>Bienvenida (2.º, 4.º…)<input type="color" name="welcome_color2" value="<?= e($c['welcome_color2']) ?>"></label>
      <label>Texto central<input type="color" name="text_color" value="<?= e($c['text_color']) ?>"></label>
      <label>Líneas decorativas<input type="color" name="line_color" value="<?= e($c['line_color']) ?>"></label>
      <label>Fondo de página<input type="color" name="page_bg" value="<?= e($c['page_bg']) ?>"></label>
      <label>Fondo rodapié<input type="color" name="footer_bg" value="<?= e($c['footer_bg']) ?>"></label>
      <label>Iconos/texto rodapié<input type="color" name="footer_fg" value="<?= e($c['footer_fg']) ?>"></label>
      <label>Franja inferior<input type="color" name="footer_strip" value="<?= e($c['footer_strip']) ?>"></label>
      <label>Título sobre rodapié<input type="color" name="footer_title_color" value="<?= e($c['footer_title_color']) ?>"></label>
    </div>
    <div class="grid2">
      <label>Título sobre el rodapié (vacío = sin título)<input name="footer_title" maxlength="120" value="<?= e($c['footer_title']) ?>"></label>
      <label>Alto de la franja inferior (% de pantalla, 0 = sin franja)<input type="number" name="footer_strip_height" min="0" max="15" step="0.1" value="<?= e($c['footer_strip_height']) ?>"></label>
    </div>
    <div class="grid3">
      <label>Intensidad del latido del fondo (1 = sin latido)
        <input type="number" name="bg_pulse_scale" min="1" max="1.3" step="0.01" value="<?= e($c['bg_pulse_scale']) ?>"></label>
      <label>Duración de cada latido (s)
        <input type="number" name="bg_pulse_duration" min="0.5" max="10" step="0.1" value="<?= e($c['bg_pulse_duration']) ?>"></label>
      <label>Cerrar ventanas emergentes tras inactividad (s, 0 = nunca)
        <input type="number" name="popup_idle_close" min="0" max="3600" step="1" value="<?= e($c['popup_idle_close']) ?>"></label>
    </div>
    <button class="btn primary">Guardar apariencia</button>
  </form>
</section>

<!-- ============ ICONOS (admin) ============ -->
<section id="iconos" class="card">
  <h2>Iconos del rodapié</h2>
  <?php foreach ($c['icons'] as $i => $ic): ?>
    <div class="icon-card" id="icon-<?= e($ic['id']) ?>">
      <div class="icon-head">
        <span class="icon-preview" data-icon="<?= e($ic['icon']) ?>"><?php if ($ic['icon_image']): ?><img src="<?= e($ic['icon_image']) ?>" alt=""><?php endif; ?></span>
        <strong><?= $i + 1 ?>. <?= e($ic['label'] ?: '(sin etiqueta)') ?></strong>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="move_icon"><input type="hidden" name="id" value="<?= e($ic['id']) ?>"><input type="hidden" name="dir" value="up"><button class="btn small" <?= $i === 0 ? 'disabled' : '' ?> title="Subir">↑</button></form>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="move_icon"><input type="hidden" name="id" value="<?= e($ic['id']) ?>"><input type="hidden" name="dir" value="down"><button class="btn small" <?= $i === count($c['icons']) - 1 ? 'disabled' : '' ?> title="Bajar">↓</button></form>
        <form method="post" class="inline" data-confirm="¿Eliminar este icono?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_icon"><input type="hidden" name="id" value="<?= e($ic['id']) ?>"><button class="btn small danger">Eliminar</button></form>
      </div>
      <form method="post" enctype="multipart/form-data" class="icon-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_icon"><input type="hidden" name="id" value="<?= e($ic['id']) ?>">
        <div class="grid3">
          <label>Etiqueta bajo el icono<input name="label" maxlength="40" value="<?= e($ic['label']) ?>"></label>
          <label>Icono
            <select name="icon" class="icon-select"><?php foreach ($iconNames as $k => $n): ?><option value="<?= $k ?>"<?= sel($ic['icon'], $k) ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
          <label>…o imagen propia (SVG, PNG, JPG, WEBP)
            <input type="file" name="icon_file" accept="image/svg+xml,image/png,image/jpeg,image/webp,image/gif">
            <?php if ($ic['icon_image']): ?><span class="radio"><input type="checkbox" name="icon_remove" value="1"> quitar imagen propia</span><?php endif; ?></label>
        </div>
        <div class="grid3">
          <label>Contenido
            <select name="type"><option value="url"<?= sel($ic['type'], 'url') ?>>Página web / iframe</option><option value="youtube"<?= sel($ic['type'], 'youtube') ?>>Vídeo de YouTube</option></select></label>
          <label class="span2">URL, código &lt;iframe&gt; o enlace de YouTube
            <textarea name="url" rows="2" maxlength="2000"><?= e($ic['url']) ?></textarea></label>
        </div>
        <div class="grid3">
          <label>Mostrar como
            <select name="display"><option value="popup"<?= sel($ic['display'], 'popup') ?>>Ventana emergente</option><option value="fullscreen"<?= sel($ic['display'], 'fullscreen') ?>>Página completa</option></select></label>
          <label>Ancho (px, %, vw)<input name="width" value="<?= e($ic['width']) ?>" placeholder="580px"></label>
          <label>Alto (px, %, vh o auto)<input name="height" value="<?= e($ic['height']) ?>" placeholder="auto"></label>
        </div>
        <div class="grid3">
          <label>Texto botón cerrar<input name="close_text" maxlength="40" value="<?= e($ic['close_text']) ?>" placeholder="✕"></label>
          <label>Posición del botón
            <select name="close_position"><?php foreach (['bottom-center' => 'Abajo centro', 'bottom-left' => 'Abajo izquierda', 'bottom-right' => 'Abajo derecha', 'top-right' => 'Arriba derecha', 'top-left' => 'Arriba izquierda'] as $k => $n): ?><option value="<?= $k ?>"<?= sel($ic['close_position'], $k) ?>><?= $n ?></option><?php endforeach; ?></select></label>
          <label>Tamaño del botón
            <select name="close_size"><?php foreach (['small' => 'Pequeño', 'medium' => 'Mediano', 'large' => 'Grande', 'xlarge' => 'Muy grande'] as $k => $n): ?><option value="<?= $k ?>"<?= sel($ic['close_size'], $k) ?>><?= $n ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="colors">
          <label>Color botón<input type="color" name="close_bg" value="<?= e($ic['close_bg']) ?>"></label>
          <label>Texto botón<input type="color" name="close_fg" value="<?= e($ic['close_fg']) ?>"></label>
        </div>
        <button class="btn primary">Guardar icono</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (count($c['icons']) < 8): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_icon"><button class="btn">+ Añadir icono</button></form>
  <?php endif; ?>
  <p class="hint">Nota: algunas webs impiden mostrarse dentro de un iframe (cabecera X-Frame-Options/CSP). Si una página sale en blanco, debe permitirlo su servidor.</p>
</section>

<!-- ============ USUARIOS (admin) ============ -->
<section id="usuarios" class="card">
  <h2>Usuarios</h2>
  <table class="users">
    <thead><tr><th>Usuario</th><th>Rol</th><th>Nueva contraseña</th><th></th></tr></thead>
    <tbody>
    <?php foreach (users_all() as $u): ?>
      <tr>
        <td><?= e($u['username']) ?></td>
        <td><?= $u['role'] === 'admin' ? 'Administrador' : 'Mantenedor' ?></td>
        <td>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="user_password"><input type="hidden" name="username" value="<?= e($u['username']) ?>">
            <input type="password" name="password" minlength="<?= MIN_PASSWORD ?>" required autocomplete="new-password"><button class="btn small">Cambiar</button></form>
        </td>
        <td><?php if ($u['username'] !== $me['username']): ?>
          <form method="post" class="inline" data-confirm="¿Eliminar el usuario <?= e($u['username']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="user_delete"><input type="hidden" name="username" value="<?= e($u['username']) ?>"><button class="btn small danger">Eliminar</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <h3>Añadir usuario</h3>
  <form method="post" class="row" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="user_add">
    <input name="username" placeholder="usuario o correo" required maxlength="100" autocomplete="username">
    <input type="password" name="password" placeholder="contraseña" required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password">
    <select name="role"><option value="maintainer">Mantenedor</option><option value="admin">Administrador</option></select>
    <button class="btn primary">Crear</button>
  </form>
  <p class="hint">Contraseñas: mínimo <?= MIN_PASSWORD ?> caracteres y 3 tipos (minúsculas, mayúsculas, números, símbolos).</p>
</section>

<section id="peligro" class="card warn">
  <h2>Restablecer todo de fábrica</h2>
  <p>Vuelve a la configuración inicial completa (imágenes, colores, iconos y contenido) y borra todas las imágenes subidas. Los usuarios se mantienen.</p>
  <form method="post" data-confirm="¿Seguro? Se perderá TODA la configuración y las imágenes subidas.">
    <?= csrf_field() ?><input type="hidden" name="action" value="reset_all"><button class="btn danger">Restablecer todo</button>
  </form>
</section>
<?php endif; ?>

<!-- ============ MI CUENTA ============ -->
<section id="cuenta" class="card">
  <h2>Mi contraseña</h2>
  <form method="post" class="grid3">
    <?= csrf_field() ?><input type="hidden" name="action" value="change_own_password">
    <label>Actual<input type="password" name="current" required autocomplete="current-password"></label>
    <label>Nueva<input type="password" name="new" required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password"></label>
    <label>Repetir nueva<input type="password" name="new2" required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password"></label>
    <button class="btn primary">Cambiar contraseña</button>
  </form>
</section>

</div>

<aside class="col-preview">
  <div class="preview-head">
    <strong>Vista previa</strong>
    <button type="button" class="btn small" id="orientBtn">Girar</button>
    <button type="button" class="btn small" id="reloadPreview">Recargar</button>
  </div>
  <div class="preview-frame portrait" id="previewBox">
    <iframe id="preview" src="<?= e(KIOSK_FILE) ?>?preview=1" title="Vista previa del kiosco"></iframe>
  </div>
</aside>
</div>
</body>
</html>
