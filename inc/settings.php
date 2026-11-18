<?php
/*
 * Ajustes de instalación del kiosco welcome.bezares.com
 * ------------------------------------------------------
 * 1) Cambie SETUP_KEY antes de subir los archivos. Solo se usa una vez,
 *    para crear los usuarios iniciales en la primera visita.
 * 2) El resto de valores puede dejarse tal cual.
 */

// Clave de instalación (mín. 12 caracteres). Mientras valga el texto por defecto, la instalación está bloqueada.
const SETUP_KEY = 'CAMBIAR-ESTA-CLAVE';

// Dirección pública del sitio (se usa en el enlace del correo de recuperación; nunca se toma de la petición)
const BASE_URL = 'https://welcome.bezares.com';

// Remitente de los correos de recuperación de contraseña (debe ser un buzón del dominio para que no acabe en spam)
const MAIL_FROM = 'no-reply@bezares.com';
const MAIL_FROM_NAME = 'Welcome Bezares';

// Recuperación de contraseña: validez del enlace y máximo de correos por usuario y hora
const RESET_TTL = 1800;        // 30 min
const RESET_MAX_PER_HOUR = 3;

// Nombre del archivo del modo kiosco (ruta secreta).
const KIOSK_FILE = 'VXM7D4mGu83ERwJC.html';

// Carpeta de datos (JSON de configuración, usuarios, intentos, sesiones).
// Recomendado: moverla fuera de public_html, p. ej. '/home/USUARIO/welcome-data'
const DATA_DIR = __DIR__ . '/../data';

// Sesión: cierre por inactividad y duración máxima (segundos)
const SESSION_IDLE = 1800;   // 30 min
const SESSION_MAX  = 28800;  // 8 h

// Protección contra fuerza bruta
const LOGIN_MAX_IP     = 5;      // fallos por IP antes de bloquear
const LOGIN_MAX_USER   = 10;     // fallos por usuario (desde cualquier IP) antes de bloquear
const LOGIN_WINDOW     = 900;    // ventana de recuento de fallos (15 min)
const LOGIN_LOCK       = 900;    // primer bloqueo: 15 min (se duplica en cada bloqueo sucesivo)
const LOGIN_LOCK_MAX   = 86400;  // bloqueo máximo: 24 h

// Subidas
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024; // 8 MB

// Contraseñas
const MIN_PASSWORD = 10;
