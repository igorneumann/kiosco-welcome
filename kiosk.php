<?php
declare(strict_types=1);
/*
 * Sirve la página del kiosco (VXM7D4mGu83ERwJC.html) con una Content-Security-Policy generada a partir
 * de la lista de dominios permitidos del panel: el navegador solo cargará en las ventanas esos dominios.
 * .htaccess redirige internamente la URL del kiosco a este archivo; si mod_rewrite no estuviera
 * disponible, el HTML estático sigue funcionando con la CSP genérica de .htaccess.
 */
require __DIR__ . '/inc/bootstrap.php';

// Solo responde a la URL secreta del kiosco (no a /kiosk.php directamente)
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (basename($path) !== KIOSK_FILE) {
    http_response_code(404);
    exit;
}

$sources = [];
foreach (frame_domains_parse(config_get()['frame_domains']) as $d) {
    $sources[] = 'https://' . $d;
}
// El reproductor de YouTube se carga siempre desde youtube-nocookie
$sources[] = 'https://www.youtube-nocookie.com';
$frameSrc = implode(' ', array_unique($sources));
// Webs de confianza (administración) que pueden mostrar el kiosco dentro de un iframe
$ancestors = implode(' ', array_merge(["'self'"], embed_origins_parse(config_get()['embed_origins'])));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self' blob:; "
     . "connect-src 'self'; frame-src {$frameSrc}; frame-ancestors {$ancestors}; base-uri 'none'; form-action 'none'; object-src 'none'");
readfile(__DIR__ . '/' . KIOSK_FILE);
