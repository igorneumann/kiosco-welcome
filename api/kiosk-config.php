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

$icons = [];
foreach ($c['icons'] as $ic) {
    $icons[] = [
        'label'          => (string)$ic['label'],
        'icon'           => in_array($ic['icon'], BUILTIN_ICONS, true) ? $ic['icon'] : 'info',
        'icon_image'     => (string)$ic['icon_image'],
        'type'           => $ic['type'] === 'youtube' ? 'youtube' : 'url',
        'url'            => (string)$ic['url'],
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
    'logo'              => $c['bottom_logo'] ?: $c['default_logo'],
    'icons'             => $icons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
