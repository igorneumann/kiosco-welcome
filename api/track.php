<?php
declare(strict_types=1);
/*
 * Recepción de eventos de interacción del kiosco (navigator.sendBeacon desde assets/kiosk.js).
 * Solo guarda contadores agregados por día; ver inc/stats.php.
 * Protección: solo POST, mismo origen, token diario, lista cerrada de eventos e iconos existentes,
 * tamaño máximo y límite de 60 eventos por minuto y dirección.
 */
require __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$fail = function (int $code): void { http_response_code($code); exit; };

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail(405);
}
// Mismo origen (si el navegador envía Origin)
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && !in_array(strtolower((string)parse_url($origin, PHP_URL_HOST)), own_hosts(), true)) {
    $fail(403);
}
$raw = (string)file_get_contents('php://input', false, null, 0, 2048);
$in = json_decode($raw, true);
if (!is_array($in) || !stats_token_valid((string)($in['t'] ?? ''))) {
    $fail(403);
}
$event = (string)($in['e'] ?? '');
if (!in_array($event, STATS_EVENTS, true)) {
    $fail(400);
}
$icon = '';
if (in_array($event, ['open', 'close', 'nl'], true)) {
    $icon = (string)($in['i'] ?? '');
    $ids = array_column(config_get()['icons'], 'id');
    if (!in_array($icon, $ids, true)) {
        $fail(400);
    }
}
$dur = is_numeric($in['d'] ?? null) ? (float)$in['d'] : 0.0;
$reason = in_array($in['r'] ?? '', ['button', 'backdrop', 'idle'], true) ? $in['r'] : '';

if (!stats_record($event, $icon, $dur, $reason)) {
    $fail(429);
}
http_response_code(204);
