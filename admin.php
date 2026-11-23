<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

require_allowed_ip();
session_boot();
security_headers();

$me = current_user();
if (!$me) {
    redirect('index.php');
}
$isAdmin = $me['role'] === 'admin';

/** Página del panel del administrador donde está cada ancla (para volver allí tras guardar). */
function admin_page_for_anchor(string $anchor): string
{
    if (str_starts_with($anchor, 'icon-') || $anchor === 'iconos') {
        return 'iconos';
    }
    $map = ['tema' => 'contenido', 'contenido' => 'contenido', 'logos' => 'contenido', 'apariencia' => 'apariencia',
            'oscuro' => 'oscuro', 'integracion' => 'integracion', 'usuarios' => 'usuarios', 'cuenta' => 'cuenta',
            'peligro' => 'sistema'];
    return $map[$anchor] ?? 'contenido';
}

/* ================================================================
 *  Acciones (POST → redirección)
 * ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action', 40);
    $anchor = '';

    $adminOnly = ['save_dark', 'upload_dark_header', 'upload_dark_background', 'remove_dark_header', 'remove_dark_background',
                  'save_embed', 'save_frame_domains', 'user_email', 'save_general', 'upload_header', 'remove_header', 'upload_background', 'remove_background',
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
                    foreach (['', '2'] as $n) {
                        $c['center_text' . $n]      = post_str('center_text' . $n, 2000);
                        $c['center_font_mode' . $n] = post_enum('center_font_mode' . $n, ['auto', 'fixed'], 'auto');
                        $c['center_font_size' . $n] = post_num('center_font_size' . $n, 1, 40, 6);
                        $c['center_bold' . $n]      = !empty($_POST['center_bold' . $n]);
                    }
                });
                flash('Textos centrales guardados.');
                break;

            case 'save_theme':
                $anchor = 'tema';
                $theme = !empty($_POST['theme_dark']) ? 'dark' : 'light';
                config_update(function (array &$c) use ($theme) { $c['theme'] = $theme; });
                auth_log('theme:' . $theme, $me['username']);
                flash($theme === 'dark' ? 'Tema oscuro activado en el kiosco.' : 'Tema claro activado en el kiosco.');
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
                $deleteLogos = post_str('mode', 10) === 'delete';
                config_update(function (array &$c) use ($deleteLogos) {
                    $d = config_defaults();
                    if ($deleteLogos) {
                        foreach ($c['logos'] as $l) { delete_upload($l); }
                        $c['logos'] = [];
                    }
                    $c['bottom_logo']      = '';
                    $c['theme']            = $d['theme'];
                    foreach (['center_text', 'center_font_mode', 'center_font_size', 'center_bold',
                              'center_text2', 'center_font_mode2', 'center_font_size2', 'center_bold2'] as $k) {
                        $c[$k] = $d[$k];
                    }
                });
                auth_log($deleteLogos ? 'reset_content_delete_logos' : 'reset_content', $me['username']);
                flash($deleteLogos
                    ? 'Configuración restablecida: sin textos centrales, tema claro, logo de Bezares y logos subidos eliminados.'
                    : 'Configuración restablecida: sin textos centrales, tema claro y logo de Bezares. Los logos subidos se conservan.');
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
                    $c['mailing_kiosk_secs'] = (int)post_num('mailing_kiosk_secs', 2, 120, 5);
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

            case 'save_dark':
                $anchor = 'oscuro';
                config_update(function (array &$c) {
                    $d = config_defaults()['dark'];
                    foreach (['page_bg', 'welcome_color', 'welcome_color2', 'text_color', 'line_color', 'footer_title_color',
                              'footer_bg', 'footer_fg', 'footer_strip'] as $k) {
                        $c['dark'][$k] = post_color('dark_' . $k, $d[$k]);
                    }
                    $c['dark']['logo_tile'] = !empty($_POST['dark_logo_tile']);
                });
                flash('Colores del tema oscuro guardados.');
                break;

            case 'upload_dark_header':
            case 'upload_dark_background':
                $anchor = 'oscuro';
                $key = $action === 'upload_dark_header' ? 'header_image' : 'background_image';
                $path = handle_upload('image', $action === 'upload_dark_header' ? 'darkheader' : 'darkbg');
                if (!$path) { throw new RuntimeException('Seleccione una imagen.'); }
                config_update(function (array &$c) use ($key, $path) {
                    delete_upload($c['dark'][$key] ?? '');
                    $c['dark'][$key] = $path;
                });
                flash('Imagen del tema oscuro actualizada.');
                break;

            case 'remove_dark_header':
            case 'remove_dark_background':
                $anchor = 'oscuro';
                $key = $action === 'remove_dark_header' ? 'header_image' : 'background_image';
                $mode = post_enum('mode', ['none', 'default'], 'none');
                config_update(function (array &$c) use ($key, $mode) {
                    delete_upload($c['dark'][$key] ?? '');
                    $c['dark'][$key] = $mode === 'default' ? config_defaults()['dark'][$key] : '';
                });
                flash($mode === 'default' ? 'Imagen original restaurada.' : 'Imagen quitada.');
                break;

            case 'save_embed':
                $anchor = 'integracion';
                $list = embed_origins_parse(post_str('embed_origins', 2000));
                $embedAdmin = !empty($_POST['embed_admin']);
                config_update(function (array &$c) use ($list, $embedAdmin) {
                    $c['embed_origins'] = implode("\n", $list);
                    $c['embed_admin'] = $embedAdmin;
                });
                auth_log('embed_origins:' . count($list), $me['username']);
                flash('Webs de confianza guardadas (' . count($list) . ').');
                break;

            case 'save_frame_domains':
                $anchor = 'iconos';
                $list = frame_domains_parse(post_str('frame_domains', 2000));
                config_update(function (array &$c) use ($list) {
                    $c['frame_domains'] = implode("\n", $list);
                });
                flash('Dominios permitidos guardados (' . count($list) . ').');
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
                $uploadDark = $action === 'save_icon' ? handle_upload('icon_file_dark', 'icondark') : null;
                config_update(function (array &$c) use ($action, $id, $upload, $uploadDark, &$anchor) {
                    $idx = null;
                    foreach ($c['icons'] as $i => $ic) { if ($ic['id'] === $id) { $idx = $i; } }
                    if ($idx === null) { delete_upload($upload); delete_upload($uploadDark); throw new RuntimeException('Icono no encontrado.'); }

                    if ($action === 'delete_icon') {
                        delete_upload($c['icons'][$idx]['icon_image']);
                        delete_upload($c['icons'][$idx]['icon_image_dark'] ?? '');
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
                    if ($uploadDark) {
                        delete_upload($ic['icon_image_dark'] ?? '');
                        $ic['icon_image_dark'] = $uploadDark;
                    } elseif (!empty($_POST['icon_remove_dark'])) {
                        delete_upload($ic['icon_image_dark'] ?? '');
                        $ic['icon_image_dark'] = '';
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
                        if ($url === '') { throw new RuntimeException('La dirección (URL o código iframe) no es válida. Debe empezar por https://'); }
                        if ($why = frame_url_problem($url, frame_domains_parse($c['frame_domains']))) { throw new RuntimeException($why); }
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
                    $ic['allow_same_origin'] = !empty($_POST['allow_same_origin']);
                    $c['icons'][$idx] = $ic;
                });
                flash($action === 'delete_icon' ? 'Icono eliminado.' : ($action === 'move_icon' ? 'Orden actualizado.' : 'Icono guardado.'));
                break;

            case 'user_add':
                $anchor = 'usuarios';
                $un = post_str('username', 100);
                $pw = (string)($_POST['password'] ?? '');
                $role = post_enum('role', ['admin', 'maintainer'], 'maintainer');
                $em = post_str('email', 100);
                if (!email_valid($em)) { throw new RuntimeException('El correo no es válido.'); }
                if (!username_valid($un)) { throw new RuntimeException('Usuario: una dirección de correo o 3–64 caracteres (letras, números, punto, guion).'); }
                if (user_find($un)) { throw new RuntimeException('Ese usuario ya existe.'); }
                if ($p = password_problem($pw, $un)) { throw new RuntimeException($p); }
                $hash = password_make($pw);
                users_update(function (array &$users) use ($un, $role, $hash, $em) {
                    $users[] = ['username' => $un, 'role' => $role, 'hash' => $hash, 'email' => $em];
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

            case 'user_email':
                $anchor = 'usuarios';
                $un = post_str('username', 100);
                $em = post_str('email', 100);
                if (!user_find($un)) { throw new RuntimeException('Usuario no encontrado.'); }
                if (!email_valid($em)) { throw new RuntimeException('El correo no es válido.'); }
                users_update(function (array &$users) use ($un, $em) {
                    foreach ($users as &$u) { if ($u['username'] === $un) { $u['email'] = $em; } }
                });
                auth_log('user_email_changed', $un);
                flash('Correo de ' . $un . ' guardado.');
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
    redirect('admin.php' . ($isAdmin ? '?p=' . admin_page_for_anchor($anchor) : '') . ($anchor ? '#' . $anchor : ''));
}

/* ================================================================
 *  Exportación CSV de estadísticas (administrador)
 * ================================================================ */
if ($isAdmin && ($_GET['p'] ?? '') === 'estadisticas' && isset($_GET['csv'])) {
    $per = stats_periods($_GET);
    $cur = stats_query($per['from'], $per['to']);
    $cfg = config_get();
    $ids = array_values(array_unique(array_merge(array_column($cfg['icons'], 'id'), array_keys($cur['open']))));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="welcome-estadisticas-' . $per['from'] . '_' . $per['to'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para Excel
    $labels = [];
    foreach ($cfg['icons'] as $ic) { $labels[$ic['id']] = $ic['label'] !== '' ? $ic['label'] : ucfirst($ic['id']); }
    $months = [];
    $head = ['Fecha', 'Arranques del kiosco', 'Interacciones', 'Suscripciones newsletter'];
    foreach ($ids as $id) { $head[] = 'Aperturas: ' . ($labels[$id] ?? $id); }
    fputcsv($out, $head, ';');
    for ($d = new DateTimeImmutable($per['from']); $d <= new DateTimeImmutable($per['to']); $d = $d->modify('+1 day')) {
        $m = $d->format('Y-m');
        $months[$m] ??= json_read(stats_dir() . '/' . $m . '.json', ['days' => []])['days'] ?? [];
        $day = $months[$m][$d->format('Y-m-d')] ?? [];
        $row = [$d->format('Y-m-d'), (int)($day['start'] ?? 0), array_sum($day['open'] ?? []), array_sum($day['nl'] ?? [])];
        foreach ($ids as $id) { $row[] = (int)($day['open'][$id] ?? 0); }
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

/* ================================================================
 *  Vista
 * ================================================================ */
$c = config_get();
$flashes = flash_take();

/* Administrador: panel con menú lateral, una página por sección. El mantenedor conserva la vista sencilla. */
$adminPages = [
    'contenido'   => ['Contenido', 'Textos centrales, logotipo inferior y tema de la pantalla.', ['tema', 'contenido', 'logos', 'reset'], true,
                      'M4 6h16M4 12h16M4 18h10'],
    'estadisticas' => ['Estadísticas', 'Interacciones con el kiosco y suscripciones a la newsletter, con comparación entre periodos.', ['estadisticas'], false,
                      'M3 3v18h18M7 15l4-4 3 3 6-6M20 8v4M20 8h-4'],
    'apariencia'  => ['Apariencia', 'Imágenes, colores, textos de bienvenida y animaciones del tema claro.', ['apariencia'], true,
                      'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15.5 8.5h.01'],
    'oscuro'      => ['Tema oscuro', 'Imágenes y colores que usa el kiosco cuando el mantenedor activa el tema oscuro.', ['oscuro'], true,
                      'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z'],
    'iconos'      => ['Iconos del rodapié', 'Botones del rodapié, su contenido y los dominios que se pueden mostrar.', ['iconos'], true,
                      'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z'],
    'integracion' => ['Integración', 'Webs de confianza que pueden mostrar el kiosco o el panel dentro de un iframe.', ['integracion'], false,
                      'M8 6l-6 6 6 6M16 6l6 6-6 6'],
    'usuarios'    => ['Usuarios', 'Administradores y mantenedores, contraseñas y correos de recuperación.', ['usuarios'], false,
                      'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75'],
    'cuenta'      => ['Mi cuenta', 'Cambie su contraseña.', ['cuenta'], false,
                      'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z'],
    'sistema'     => ['Mantenimiento', 'Restablecer la configuración de fábrica.', ['peligro'], false,
                      'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z'],
];
$page = (string)($_GET['p'] ?? 'contenido');
if (!isset($adminPages[$page])) {
    $page = 'contenido';
}
[$pageTitle, $pageDesc, $pageSections, $pagePreview] = $adminPages[$page];
$showPreview = !$isAdmin || $pagePreview;
/** ¿Se muestra esta sección? Mantenedor: todas las suyas (vista de siempre). Administrador: las de la página abierta. */
$sec = fn(string $id): bool => !$isAdmin || in_array($id, $pageSections, true);
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
<link rel="icon" href="assets/favicon-light.svg" type="image/svg+xml" media="(prefers-color-scheme: light)">
<link rel="icon" href="assets/favicon-dark.svg" type="image/svg+xml" media="(prefers-color-scheme: dark)">
<script src="assets/icons.js" defer></script>
<script src="assets/admin.js" defer></script>
</head>
<body<?= $isAdmin ? ' class="shell-body"' : '' ?>>
<?php if ($isAdmin): ?>
<div class="shell">
  <aside class="sidebar">
    <a class="sb-brand" href="admin.php" title="Welcome Bezares">
      <img src="assets/favicon-dark.svg" alt="" width="40" height="40">
      <span><b>Welcome</b><small>Bezares · Panel</small></span>
    </a>
    <nav class="sb-nav" aria-label="Secciones">
      <?php foreach ($adminPages as $k => [$label, , , , $ico]): ?>
        <a href="admin.php?p=<?= $k ?>" title="<?= e($label) ?>"<?= $k === $page ? ' class="on" aria-current="page"' : '' ?>><svg class="nav-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $ico ?>" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="sb-foot">
      <a class="sb-kiosk" href="<?= e(KIOSK_FILE) ?>" target="_blank" rel="noopener">Abrir kiosco ↗</a>
      <div class="sb-who" title="<?= e($me['username']) ?>"><?= e($me['username']) ?></div>
      <div class="sb-role">Administrador</div>
      <form method="post" action="logout.php"><?= csrf_field() ?><button class="sb-out" title="Cerrar sesión"><span class="sb-out-l">Cerrar sesión</span><span class="sb-out-s">Salir</span></button></form>
    </div>
  </aside>
  <main class="app">
    <div class="app-head">
      <h1><?= e($pageTitle) ?></h1>
      <p class="hint"><?= e($pageDesc) ?></p>
    </div>
    <?php if (data_dir_is_public()): ?>
      <p class="msg err">Seguridad: la carpeta de datos (usuarios, contraseñas, registros) está dentro de la web.
      Muévala fuera de public_html dejando <code>DATA_DIR</code> vacío o con una ruta como <code>/home/USUARIO/welcome-kiosk-data</code> en <code>inc/settings.php</code>.</p>
    <?php endif; ?>
<?php else: ?>
<header class="topbar">
  <strong class="brand"><img src="assets/favicon-light.svg" alt="" width="32" height="32"> Welcome Bezares · Panel</strong>
  <span class="who"><?= e($me['username']) ?> · <?= $isAdmin ? 'Administrador' : 'Mantenedor' ?></span>
  <a class="btn" href="<?= e(KIOSK_FILE) ?>" target="_blank" rel="noopener">Abrir kiosco ↗</a>
  <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="btn">Cerrar sesión</button></form>
</header>

<nav class="tabs">
  <a href="#tema">Tema</a>
  <a href="#contenido">Contenido</a>
  <?php if ($isAdmin): ?><a href="#apariencia">Apariencia</a><a href="#iconos">Iconos</a><a href="#usuarios">Usuarios</a><?php endif; ?>
  <?php if ($isAdmin): ?><a href="#integracion">Integración</a><?php endif; ?>
  <a href="#cuenta">Mi cuenta</a>
</nav>
<?php if ($isAdmin && data_dir_is_public()): ?>
  <p class="msg err banner">Seguridad: la carpeta de datos (usuarios, contraseñas, registros) está dentro de la web.
  Muévala fuera de public_html dejando <code>DATA_DIR</code> vacío o con una ruta como <code>/home/USUARIO/welcome-kiosk-data</code> en <code>inc/settings.php</code>.</p>
<?php endif; ?>

<?php endif; ?>
<div class="layout<?= $showPreview ? '' : ' single' ?>">
<div class="col-main">

<?php foreach ($flashes as [$t, $m]): ?><p class="msg <?= e($t) ?>"><?= e($m) ?></p><?php endforeach; ?>

<!-- ============ TEMA (mantenedor) ============ -->
<?php if ($sec('tema')): ?>
<section id="tema" class="card">
  <form method="post" class="theme-form" data-autosubmit>
    <?= csrf_field() ?><input type="hidden" name="action" value="save_theme">
    <div>
      <h2>Tema de la pantalla</h2>
      <p class="hint">El tema claro es el predeterminado. El oscuro cambia fondo, encabezado, colores e iconos del kiosco.</p>
    </div>
    <label class="switch">
      <span class="switch-label">Claro</span>
      <input type="checkbox" name="theme_dark" value="1" role="switch" aria-label="Tema oscuro"<?= ($c['theme'] ?? 'light') === 'dark' ? ' checked' : '' ?>>
      <span class="switch-track" aria-hidden="true"><span class="switch-thumb"></span></span>
      <span class="switch-label">Oscuro</span>
    </label>
    <noscript><button class="btn primary">Aplicar</button></noscript>
  </form>
</section>
<?php endif; ?>

<!-- ============ CONTENIDO (mantenedor) ============ -->
<?php if ($sec('contenido')): ?>
<section id="contenido" class="card">
  <h2>Textos centrales</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_center">
    <?php foreach (['' => 'Texto central 1 (arriba)', '2' => 'Texto central 2 (debajo)'] as $n => $title): ?>
    <fieldset class="ctext-block">
      <legend><?= $title ?></legend>
      <label>Texto (puede usar varias líneas; vacío = no se muestra)
        <textarea name="center_text<?= $n ?>" rows="3" maxlength="2000"><?= e($c['center_text' . $n]) ?></textarea></label>
      <div class="row">
        <label class="radio"><input type="radio" name="center_font_mode<?= $n ?>" value="auto" data-mode="auto"<?= chk($c['center_font_mode' . $n], 'auto') ?>> Auto (ajusta al ancho)</label>
        <label class="radio"><input type="radio" name="center_font_mode<?= $n ?>" value="fixed" data-mode="fixed"<?= chk($c['center_font_mode' . $n], 'fixed') ?>> Manual</label>
        <label class="radio"><input type="checkbox" name="center_bold<?= $n ?>" value="1"<?= $c['center_bold' . $n] ? ' checked' : '' ?>> <strong>Negrita</strong></label>
      </div>
      <div class="row size-row">
        <label class="grow">Tamaño manual: <output data-size-out><?= e($c['center_font_size' . $n]) ?></output> % del ancho de pantalla
          <input type="range" name="center_font_size<?= $n ?>" data-size min="1" max="40" step="0.5" value="<?= e($c['center_font_size' . $n]) ?>"></label>
        <button type="button" class="btn" data-auto>AUTO</button>
      </div>
    </fieldset>
    <?php endforeach; ?>
    <p class="hint">En modo Auto cada texto se ajusta al ancho de la pantalla; si entre los dos no caben en el alto disponible, se reducen ambos en proporción.</p>
    <button class="btn primary">Guardar textos</button>
  </form>
</section>
<?php endif; ?>

<?php if ($sec('logos')): ?>
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
<?php endif; ?>

<?php if ($sec('reset')): ?>
<section class="card warn">
  <h2>Restablecer configuración por defecto</h2>
  <p>Quita los dos textos centrales, vuelve al tema claro y al logo de Bezares.</p>
  <div class="row">
    <form method="post" class="inline" data-confirm="¿Restablecer? Se borrarán los textos centrales; los logos subidos se conservan.">
      <?= csrf_field() ?><input type="hidden" name="action" value="reset_content"><input type="hidden" name="mode" value="keep">
      <button class="btn danger">Restablecer (conservar logos)</button>
    </form>
    <form method="post" class="inline" data-confirm="¿Restablecer? Se borrarán los textos centrales y TODOS los logos subidos.">
      <?= csrf_field() ?><input type="hidden" name="action" value="reset_content"><input type="hidden" name="mode" value="delete">
      <button class="btn danger">Restablecer y borrar logos</button>
    </form>
  </div>
</section>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- ============ ESTADÍSTICAS (admin) ============ -->
<?php if ($sec('estadisticas')):
    $per = stats_periods($_GET);
    $st = stats_query($per['from'], $per['to']);
    $hasCmp = $per['cfrom'] !== '';
    $cs = $hasCmp ? stats_query($per['cfrom'], $per['cto']) : null;
    $iconLabel = []; $iconType = [];
    foreach ($c['icons'] as $ic) { $iconLabel[$ic['id']] = $ic['label'] !== '' ? $ic['label'] : ucfirst($ic['id']); $iconType[$ic['id']] = $ic['type']; }
    $sumType = function (?array $s, string $type) use ($iconType): int {
        if (!$s) { return 0; }
        $n = 0;
        foreach ($s['open'] as $id => $v) { if (($iconType[$id] ?? '') === $type) { $n += $v; } }
        return $n;
    };
    $avg = function (?array $s): float {
        if (!$s) { return 0.0; }
        $sec = 0; $n = 0;
        foreach ($s['dur'] as [$a, $b]) { $sec += $a; $n += $b; }
        return $n ? $sec / $n : 0.0;
    };
    $kpis = [
        ['Interacciones', 'Aperturas de los iconos del rodapié', $st['opens'], $cs['opens'] ?? null, false],
        ['Suscripciones a la newsletter', 'Clics en el botón de enviar del formulario', $st['subs'], $cs['subs'] ?? null, false],
        ['Reproducciones de vídeo', 'Aperturas de iconos de YouTube', $sumType($st, 'youtube'), $hasCmp ? $sumType($cs, 'youtube') : null, false],
        ['Visitas a páginas web', 'Aperturas de iconos de página web', $sumType($st, 'url'), $hasCmp ? $sumType($cs, 'url') : null, false],
        ['Tiempo medio por ventana', 'Desde que se abre hasta que se cierra', $avg($st), $hasCmp ? $avg($cs) : null, true],
        ['Arranques del kiosco', 'Cargas de la pantalla (encendidos y recargas)', $st['start'], $cs['start'] ?? null, false],
    ];
    $curDaily = array_values($st['daily']);
    $cmpDaily = $cs ? array_values($cs['daily']) : null;
    $dayLabels = array_map(fn($k) => (int)substr($k, 8, 2) . '/' . (int)substr($k, 5, 2), array_keys($st['daily']));
    $ids = array_values(array_unique(array_merge(array_column($c['icons'], 'id'), array_keys($st['open']), $cs ? array_keys($cs['open']) : [])));
    $presetNames = ['lastmonth' => 'Mes anterior', 'thismonth' => 'Este mes', 'last7' => 'Últimos 7 días', 'last30' => 'Últimos 30 días',
                    'thisyear' => 'Este año', 'lastyear' => 'Año anterior', 'custom' => 'Personalizado'];
    $cmpNames = ['prev' => 'Periodo anterior', 'year' => 'Mismo periodo del año anterior', 'custom' => 'Personalizado', 'none' => 'Sin comparación'];
?>
<section id="estadisticas">
  <form method="get" class="card stats-filter" data-stats-filter>
    <input type="hidden" name="p" value="estadisticas">
    <label>Periodo
      <select name="preset" data-preset><?php foreach ($presetNames as $k => $n): ?><option value="<?= $k ?>"<?= sel($per['preset'], $k) ?>><?= $n ?></option><?php endforeach; ?></select></label>
    <label data-dates="preset"<?= $per['preset'] === 'custom' ? '' : ' hidden' ?>>Desde<input type="date" name="from" value="<?= e($per['from']) ?>"></label>
    <label data-dates="preset"<?= $per['preset'] === 'custom' ? '' : ' hidden' ?>>Hasta<input type="date" name="to" value="<?= e($per['to']) ?>"></label>
    <label>Comparar con
      <select name="cmp" data-cmp><?php foreach ($cmpNames as $k => $n): ?><option value="<?= $k ?>"<?= sel($per['cmp'], $k) ?>><?= $n ?></option><?php endforeach; ?></select></label>
    <label data-dates="cmp"<?= $per['cmp'] === 'custom' ? '' : ' hidden' ?>>Desde<input type="date" name="cfrom" value="<?= e($per['cfrom']) ?>"></label>
    <label data-dates="cmp"<?= $per['cmp'] === 'custom' ? '' : ' hidden' ?>>Hasta<input type="date" name="cto" value="<?= e($per['cto']) ?>"></label>
    <button class="btn primary">Ver</button>
  </form>

  <p class="stats-range"><span><strong><?= e(stats_fmt_date($per['from'])) ?> – <?= e(stats_fmt_date($per['to'])) ?></strong>
    <?php if ($hasCmp): ?><span class="hint"> comparado con <?= e(stats_fmt_date($per['cfrom'])) ?> – <?= e(stats_fmt_date($per['cto'])) ?></span><?php endif; ?></span>
    <a href="admin.php?<?= e(http_build_query(['p' => 'estadisticas', 'csv' => 1, 'preset' => 'custom', 'from' => $per['from'], 'to' => $per['to']])) ?>">Descargar CSV</a></p>

  <div class="kpi-grid">
    <?php foreach ($kpis as [$title, $hint, $val, $prev, $isTime]): [$dtxt, $dcls] = stats_delta((float)$val, $prev === null ? null : (float)$prev); ?>
      <div class="card kpi">
        <span class="kpi-title"><?= e($title) ?></span>
        <b class="kpi-val"><?= $isTime ? e(stats_fmt_secs($val)) : number_format((float)$val, 0, ',', '.') ?></b>
        <?php if ($prev !== null): ?>
          <span class="kpi-cmp"><span class="delta <?= $dcls ?>"><?= e($dtxt) ?></span> antes: <?= $isTime ? e(stats_fmt_secs($prev)) : number_format((float)$prev, 0, ',', '.') ?></span>
        <?php endif; ?>
        <span class="hint"><?= e($hint) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="chart-head"><h2>Interacciones por día</h2>
      <span class="legend"><i class="sw cur"></i> Periodo seleccionado<?php if ($hasCmp): ?> <i class="sw cmp"></i> Comparación (día a día)<?php endif; ?></span></div>
    <?= stats_bar_svg(array_column($curDaily, 'opens'), $cmpDaily ? array_column($cmpDaily, 'opens') : null, $dayLabels, 'Interacciones por día') ?>
  </div>

  <div class="stats-two">
    <div class="card">
      <div class="chart-head"><h2>Suscripciones a la newsletter por día</h2></div>
      <?= stats_bar_svg(array_column($curDaily, 'subs'), $cmpDaily ? array_column($cmpDaily, 'subs') : null, $dayLabels, 'Suscripciones por día', 460, 220) ?>
    </div>
    <div class="card">
      <div class="chart-head"><h2>Interacciones por hora del día</h2></div>
      <?= stats_bar_svg($st['hours'], $cs ? $cs['hours'] : null, array_map(fn($h) => $h . 'h', range(0, 23)), 'Interacciones por hora', 460, 220) ?>
    </div>
  </div>

  <div class="card table-card">
    <h2>Por icono del rodapié</h2>
    <table class="users stats-table">
      <thead><tr><th>Icono</th><th>Aperturas</th><?php if ($hasCmp): ?><th>Antes</th><th>Variación</th><?php endif; ?><th>Tiempo medio</th><th>Suscripciones</th></tr></thead>
      <tbody>
      <?php foreach ($ids as $id): $o = $st['open'][$id] ?? 0; $po = $cs['open'][$id] ?? 0; $du = $st['dur'][$id] ?? [0, 0]; [$dt, $dc] = stats_delta((float)$o, $hasCmp ? (float)$po : null); ?>
        <tr>
          <td><?= e($iconLabel[$id] ?? $id) ?><?php if (!isset($iconLabel[$id])): ?> <span class="hint">(eliminado)</span><?php endif; ?></td>
          <td><?= number_format($o, 0, ',', '.') ?></td>
          <?php if ($hasCmp): ?><td><?= number_format($po, 0, ',', '.') ?></td><td><span class="delta <?= $dc ?>"><?= e($dt) ?></span></td><?php endif; ?>
          <td><?= $du[1] ? e(stats_fmt_secs($du[0] / $du[1])) : '—' ?></td>
          <td><?= isset($st['nl'][$id]) ? number_format($st['nl'][$id], 0, ',', '.') : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="hint">Cada clic en el botón de enviar del formulario de la newsletter cuenta como una suscripción: se detecta por la recarga del formulario al enviarlo,
      sin contar la recarga automática del modo kiosco de Bezares Mailing.
      Cierres por inactividad en el periodo: <?= (int)$st['idle'] ?> · Recargas con 3 clics: <?= (int)$st['reload'] ?>.
      Los datos se guardan agregados por día, sin información personal; la vista previa de este panel no cuenta.</p>
  </div>
</section>
<?php endif; ?>

<!-- ============ APARIENCIA (admin) ============ -->
<?php if ($sec('apariencia')): ?>
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

  <h2>Textos, colores y animaciones <small class="hint">(tema claro)</small></h2>
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
    <div class="grid3">
      <label>Estadísticas: segundos que Bezares Mailing muestra el mensaje tras un alta (modo kiosco)
        <input type="number" name="mailing_kiosk_secs" min="2" max="120" step="1" value="<?= e($c['mailing_kiosk_secs']) ?>"></label>
    </div>
    <button class="btn primary">Guardar apariencia</button>
  </form>
</section>
<?php endif; ?>

<!-- ============ TEMA OSCURO (admin) ============ -->
<?php if ($sec('oscuro')): ?>
<section id="oscuro" class="card">
  <h2>Tema oscuro</h2>
  <p class="hint">Lo activa el mantenedor con el selector «Tema de la pantalla». Textos, animaciones, iconos y ventanas son los mismos; aquí se definen las imágenes y los colores alternativos.</p>
  <div class="img-grid">
    <?php foreach ([['header_image', 'upload_dark_header', 'remove_dark_header', 'Encabezado (oscuro)'],
                    ['background_image', 'upload_dark_background', 'remove_dark_background', 'Fondo (oscuro)']] as [$k, $up, $rm, $title]): ?>
      <div class="img-box dark-box">
        <h3><?= e($title) ?></h3>
        <?php if ($c['dark'][$k]): ?><img src="<?= e($c['dark'][$k]) ?>" alt=""><?php else: ?><p class="hint">Sin imagen</p><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="<?= $up ?>">
          <input type="file" name="image" accept="image/svg+xml,image/png,image/jpeg,image/webp,image/gif" required>
          <button class="btn small primary">Cambiar</button>
        </form>
        <div class="row">
          <?php if ($c['dark'][$k]): ?>
            <form method="post" class="inline" data-confirm="¿Quitar esta imagen?"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $rm ?>"><input type="hidden" name="mode" value="none"><button class="btn small">Quitar</button></form>
          <?php endif; ?>
          <?php if ($c['dark'][$k] !== config_defaults()['dark'][$k]): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $rm ?>"><input type="hidden" name="mode" value="default"><button class="btn small">Restaurar original</button></form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_dark">
    <div class="colors">
      <?php foreach (['page_bg' => 'Fondo de página', 'welcome_color' => 'Bienvenida (1.º, 3.º…)', 'welcome_color2' => 'Bienvenida (2.º, 4.º…)',
                      'text_color' => 'Texto central', 'line_color' => 'Líneas decorativas', 'footer_title_color' => 'Título sobre rodapié',
                      'footer_bg' => 'Fondo rodapié', 'footer_fg' => 'Iconos/texto rodapié', 'footer_strip' => 'Franja inferior'] as $k => $n): ?>
        <label><?= e($n) ?><input type="color" name="dark_<?= $k ?>" value="<?= e($c['dark'][$k]) ?>"></label>
      <?php endforeach; ?>
    </div>
    <label class="radio"><input type="checkbox" name="dark_logo_tile" value="1"<?= !empty($c['dark']['logo_tile']) ? ' checked' : '' ?>>
      Mostrar el logo inferior sobre una placa blanca (el manual de marca pide el logo siempre sobre fondo claro)</label>
    <p><button class="btn primary">Guardar tema oscuro</button></p>
  </form>
</section>
<?php endif; ?>

<!-- ============ ICONOS (admin) ============ -->
<?php if ($sec('iconos')): ?>
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
          <label class="span2">Imagen para el tema oscuro (opcional; si no hay, se usa la misma)
            <input type="file" name="icon_file_dark" accept="image/svg+xml,image/png,image/jpeg,image/webp,image/gif">
            <?php if (!empty($ic['icon_image_dark'])): ?><span class="radio"><input type="checkbox" name="icon_remove_dark" value="1"> quitar imagen oscura</span><?php endif; ?></label>
          <?php if (icon_image_for_theme($ic, true) !== ''): ?><span class="icon-dark-preview"><img src="<?= e(icon_image_for_theme($ic, true)) ?>" alt="Vista en tema oscuro"></span><?php endif; ?>
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
        <label class="radio"><input type="checkbox" name="allow_same_origin" value="1"<?= !empty($ic['allow_same_origin']) ? ' checked' : '' ?>>
          Permitir cookies y almacenamiento de la página incrustada (<code>allow-same-origin</code>)</label>
        <p class="hint">Déjelo desmarcado salvo que la página lo necesite (formularios con sesión, reproductor de YouTube). Nunca se aplica a páginas del propio kiosco, que están prohibidas.</p>
        <button class="btn primary">Guardar icono</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (count($c['icons']) < 8): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_icon"><button class="btn">+ Añadir icono</button></form>
  <?php endif; ?>
  <p class="hint">Nota: algunas webs impiden mostrarse dentro de un iframe (cabecera X-Frame-Options/CSP). Si una página sale en blanco, debe permitirlo su servidor.</p>

  <h3>Dominios permitidos en las ventanas</h3>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_frame_domains">
    <label>Un dominio por línea. <code>bezares.com</code> = ese dominio exacto; <code>*.bezares.com</code> = cualquier subdominio.
      Solo https. El propio kiosco, las IP y los nombres de red local están siempre prohibidos.
      <textarea name="frame_domains" rows="4"><?= e($c['frame_domains']) ?></textarea></label>
    <button class="btn primary">Guardar dominios</button>
  </form>
</section>
<?php endif; ?>

<!-- ============ INTEGRACIÓN (admin) ============ -->
<?php if ($sec('integracion')): ?>
<section id="integracion" class="card">
  <h2>Webs de confianza (iframe)</h2>
  <p class="hint">Por seguridad, el kiosco y el panel solo se pueden mostrar dentro de un iframe en este mismo sitio. Añada aquí las webs que pueden incrustarlos,
    una por línea: <code>www.bezares.com</code> (esa web), <code>*.bezares.com</code> (cualquier subdominio) o con puerto <code>intranet.bezares.com:8443</code>. Solo https.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_embed">
    <label>Webs de confianza
      <textarea name="embed_origins" rows="4" placeholder="www.bezares.com"><?= e($c['embed_origins']) ?></textarea></label>
    <label class="radio"><input type="checkbox" name="embed_admin" value="1"<?= !empty($c['embed_admin']) ? ' checked' : '' ?>>
      Permitir también el panel (acceso, recuperación de contraseña y administración) en esas webs</label>
    <p class="hint">El inicio de sesión dentro del iframe funciona en webs del mismo dominio (p. ej. <code>cloud.bezares.com</code>); en dominios ajenos el navegador bloquea la cookie de sesión.</p>
    <p class="hint">Código para insertarlo en esas webs:<br>
      Kiosco: <code>&lt;iframe src="<?= e(rtrim(BASE_URL, '/') . '/' . KIOSK_FILE) ?>" style="width:100%;aspect-ratio:9/16;border:0" title="Welcome Bezares"&gt;&lt;/iframe&gt;</code><br>
      Panel: <code>&lt;iframe src="<?= e(rtrim(BASE_URL, '/') . '/') ?>" style="width:100%;height:100vh;border:0" title="Welcome Bezares · Panel"&gt;&lt;/iframe&gt;</code></p>
    <button class="btn primary">Guardar webs de confianza</button>
  </form>
</section>
<?php endif; ?>

<!-- ============ USUARIOS (admin) ============ -->
<?php if ($sec('usuarios')): ?>
<section id="usuarios" class="card">
  <h2>Usuarios</h2>
  <table class="users">
    <thead><tr><th>Usuario</th><th>Rol</th><th>Correo de recuperación</th><th>Nueva contraseña</th><th></th></tr></thead>
    <tbody>
    <?php foreach (users_all() as $u): ?>
      <tr>
        <td><?= e($u['username']) ?></td>
        <td><?= $u['role'] === 'admin' ? 'Administrador' : 'Mantenedor' ?></td>
        <td>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="user_email"><input type="hidden" name="username" value="<?= e($u['username']) ?>">
            <input type="email" name="email" maxlength="100" value="<?= e($u['email'] ?? '') ?>" placeholder="<?= e(user_email($u) ?: 'sin correo') ?>"><button class="btn small">Guardar</button></form>
        </td>
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
    <input type="email" name="email" placeholder="correo de recuperación (opcional)" maxlength="100">
    <select name="role"><option value="maintainer">Mantenedor</option><option value="admin">Administrador</option></select>
    <button class="btn primary">Crear</button>
  </form>
  <p class="hint">Contraseñas: mínimo <?= MIN_PASSWORD ?> caracteres y 3 tipos (minúsculas, mayúsculas, números, símbolos).
    El enlace de «¿Olvidó su contraseña?» se envía al correo de recuperación; si está vacío y el usuario es un correo, se usa ese.</p>
</section>
<?php endif; ?>

<?php if ($sec('peligro')): ?>
<section id="peligro" class="card warn">
  <h2>Restablecer todo de fábrica</h2>
  <p>Vuelve a la configuración inicial completa (imágenes, colores, iconos y contenido) y borra todas las imágenes subidas. Los usuarios se mantienen.</p>
  <form method="post" data-confirm="¿Seguro? Se perderá TODA la configuración y las imágenes subidas.">
    <?= csrf_field() ?><input type="hidden" name="action" value="reset_all"><button class="btn danger">Restablecer todo</button>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>

<!-- ============ MI CUENTA ============ -->
<?php if ($sec('cuenta')): ?>
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
<?php endif; ?>

</div>

<?php if ($showPreview): ?>
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
<?php endif; ?>
</div>
<?php if ($isAdmin): ?>
  </main>
</div>
<?php endif; ?>
</body>
</html>
