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

// Zona horaria (fechas de las estadísticas y de los registros)
const TIMEZONE = 'Europe/Madrid';

// Remitente de los correos de recuperación de contraseña (debe ser un buzón del dominio para que no acabe en spam)
const MAIL_FROM = 'no-reply@bezares.com';
const MAIL_FROM_NAME = 'Welcome Bezares';

// Recuperación de contraseña: validez del enlace y máximo de correos por usuario y hora
const RESET_TTL = 1800;        // 30 min
const RESET_MAX_PER_HOUR = 3;

// Nombre del archivo del modo kiosco (ruta secreta).
const KIOSK_FILE = 'VXM7D4mGu83ERwJC.html';

// Carpeta de datos (configuración, usuarios, intentos, sesiones, registros). DEBE quedar fuera de la web.
// Vacío = automático: carpeta "welcome-kiosk-data" en el directorio personal de la cuenta (en cPanel,
// /home/USUARIO/welcome-kiosk-data, fuera de public_html). Para fijarla a mano, p. ej.:
//   const DATA_DIR = '/home/USUARIO/welcome-kiosk-data';
// Si existía la carpeta antigua data/ dentro del sitio, sus datos se trasladan solos en la primera visita.
const DATA_DIR = '';

// Opcional: limitar el acceso al panel (login, recuperación y administración) a estas IP o rangos CIDR.
// Vacío = sin restricción. Ej.: ['203.0.113.10', '198.51.100.0/24', '2001:db8::/32']
// La pantalla del kiosco no se ve afectada.
const ADMIN_ALLOW_IPS = [];

// Sesión: cierre por inactividad y duración máxima (segundos)
const SESSION_IDLE = 1800;   // 30 min
const SESSION_MAX  = 28800;  // 8 h

// Protección contra fuerza bruta
// - Por IP + usuario: 5 fallos → bloqueo de 5 min que se duplica en cada reincidencia (máx. 1 h).
//   El contador es distinto para los navegadores de confianza (donde el usuario ya entró antes), así que
//   un tercero no puede bloquear a un usuario en su propio equipo.
// - Por IP (cualquier usuario): 30 fallos → mismo bloqueo. Frena barridos desde una misma dirección.
// - Por cuenta (desde cualquier IP): a partir de 20 fallos NO se bloquea; se activa un modo lento
//   (1 intento cada 10 s para navegadores no reconocidos) y se avisa por correo a los administradores.
const LOGIN_MAX_PAIR    = 5;
const LOGIN_MAX_IP      = 30;
const LOGIN_USER_SOFT   = 20;
const LOGIN_SOFT_DELAY  = 10;     // segundos entre intentos en modo lento
const LOGIN_WINDOW      = 900;    // ventana de recuento de fallos (15 min)
const LOGIN_LOCK        = 300;    // primer bloqueo: 5 min
const LOGIN_LOCK_MAX    = 3600;   // bloqueo máximo: 1 h
const TRUSTED_DEVICE_DAYS = 90;   // validez de la marca de "navegador de confianza"

// Subidas
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024; // 8 MB
// Si el servidor tiene Imagick con soporte SVG, los SVG subidos se convierten a PNG (más seguro).
// Si no, se guardan como SVG depurado con una lista cerrada de etiquetas y atributos.
const SVG_TO_PNG = true;

// Contraseñas
const MIN_PASSWORD = 10;
