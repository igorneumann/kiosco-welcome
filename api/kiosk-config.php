<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

// Configuración pública para la pantalla del kiosco (sin datos sensibles).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$c = config_get();
$domains = frame_domains_parse($c['frame_domains']);
$dark = ($c['theme'] ?? 'light') === 'dark';
if ($dark) {
    // El tema oscuro sustituye imágenes y colores; todo lo demás es común a los dos temas.
    foreach (['header_image', 'background_image', 'page_bg', 'welcome_color', 'welcome_color2', 'text_color', 'line_color',
              'footer_title_color', 'footer_bg', 'footer_fg', 'footer_strip'] as $k) {
        $c[$k] = $c['dark'][$k];
    }
}

$icons = [];
foreach ($c['icons'] as $ic) {
    $isYt = $ic['type'] === 'youtube';
    // Comprobación también al servir: una URL guardada antes de cambiar la lista deja de abrirse.
    $blocked = !$isYt && frame_url_problem((string)$ic['url'], $domains) !== null;
    $icons[] = [
        'blocked'        => $blocked,
        'allow_same_origin' => !empty($ic['allow_same_origin']),
        'label'          => (string)$ic['label'],
        'icon'           => in_array($ic['icon'], BUILTIN_ICONS, true) ? $ic['icon'] : 'info',
        'icon_image'     => icon_image_for_theme($ic, $dark),
        'type'           => $ic['type'] === 'youtube' ? 'youtube' : 'url',
        'url'            => $blocked ? '' : (string)$ic['url'],
        'youtube_id'     => (string)$ic['youtube_id'],
        'display'        => $ic['display'] === 'fullscreen' ? 'fullscreen' : 'popup',
        'width'          => (string)$ic['width'],
        'height'         => (string)$ic['height'],
        'close_text'     => (string)$ic['close_text'],
        'close_bg'       => (string)$ic['close_bg'],
        'close_fg'       => (string)$ic['close_fg'],
        'close_position' => (string)$ic['close_position'],
        'close_size'     => (string)$ic['close_size'],
    ];
}

echo json_encode([
    'version'           => $c['version'],
    'theme'             => $dark ? 'dark' : 'light',
    'logo_tile'         => $dark && !empty($c['dark']['logo_tile']),
    'frame_domains'     => $domains,
    'header_image'      => $c['header_image'],
    'background_image'  => $c['background_image'],
    'welcome_texts'     => array_values($c['welcome_texts']),
    'welcome_interval'  => (float)$c['welcome_interval'],
    'welcome_color'     => $c['welcome_color'],
    'welcome_color2'    => $c['welcome_color2'],
    'footer_title'      => $c['footer_title'],
    'footer_title_color'=> $c['footer_title_color'],
    'footer_strip'      => $c['footer_strip'],
    'footer_strip_height'=> (float)$c['footer_strip_height'],
    'text_color'        => $c['text_color'],
    'line_color'        => $c['line_color'],
    'page_bg'           => $c['page_bg'],
    'footer_bg'         => $c['footer_bg'],
    'footer_fg'         => $c['footer_fg'],
    'bg_pulse_scale'    => (float)$c['bg_pulse_scale'],
    'bg_pulse_duration' => (float)$c['bg_pulse_duration'],
    'popup_idle_close'  => (int)$c['popup_idle_close'],
    'center_text'       => $c['center_text'],
    'center_font_mode'  => $c['center_font_mode'],
    'center_font_size'  => (float)$c['center_font_size'],
    'center_bold'       => (bool)$c['center_bold'],
    'center_text2'      => $c['center_text2'],
    'center_font_mode2' => $c['center_font_mode2'],
    'center_font_size2' => (float)$c['center_font_size2'],
    'center_bold2'      => (bool)$c['center_bold2'],
    'logo'              => $c['bottom_logo'] ?: $c['default_logo'],
    'icons'             => $icons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
