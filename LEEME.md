# Kiosco welcome.bezares.com

Sustituye a la instalación de WordPress. PHP 8.0 o superior (recomendado 8.1+), sin base de datos.

| Dirección | Qué muestra |
|---|---|
| `https://welcome.bezares.com/` | Solo la pantalla de acceso |
| `https://welcome.bezares.com/VXM7D4mGu83ERwJC.html` | Modo kiosco |
| `https://welcome.bezares.com/admin.php` | Panel (requiere sesión) |

## Instalación en cPanel

1. **Antes de subir**, edite `inc/settings.php` y cambie `SETUP_KEY` por una clave propia (mín. 12 caracteres). Revise también `BASE_URL` y `MAIL_FROM` (remitente de los correos de recuperación; debe ser un buzón del dominio bezares.com).
2. Haga copia de seguridad de la carpeta actual de WordPress de welcome.bezares.com (cPanel → *Administrador de archivos* → comprimir) y vacíela, o cree una carpeta nueva y apunte el subdominio a ella en *Dominios*.
3. Suba `welcome-kiosco.zip` a esa carpeta y use *Extraer*. Compruebe que se ven los archivos ocultos (`.htaccess`) en *Configuración → Mostrar archivos ocultos*.
4. Permisos: carpetas `data/` y `uploads/` → 755; archivos → 644.
5. *MultiPHP Manager*: PHP 8.1 o superior. *MultiPHP INI Editor*: `upload_max_filesize = 8M`, `post_max_size = 10M`. Extensión `fileinfo` activada (*Select PHP Version → Extensions*).
6. Abra `https://welcome.bezares.com/`, introduzca la clave de instalación y cree el usuario **administrador** y el **mantenedor**. A partir de ahí el formulario de instalación desaparece.
7. En el equipo del kiosco, abra `https://welcome.bezares.com/VXM7D4mGu83ERwJC.html` en modo pantalla completa / kiosco del navegador.

Recomendado: mueva `data/` fuera de `public_html` (p. ej. `/home/USUARIO/welcome-data`) y cambie `DATA_DIR` en `inc/settings.php`.

## Roles

**Mantenedor**: dos textos centrales, uno debajo del otro (varias líneas cada uno), cada uno con su tamaño manual o botón **AUTO** (ajusta la fuente al ancho; si juntos no caben en el alto, se reducen en proporción) y opción de **negrita**, biblioteca de logos inferiores (subir, elegir, borrar), **Restablecer por defecto** (sin textos centrales, logo de Bezares, borra los logos subidos), su contraseña.

**Administrador**: todo lo anterior, más imagen de encabezado, imagen de fondo, logo por defecto, textos de bienvenida (uno por línea, se alternan), colores (rodapié, líneas, textos, fondo), intensidad y velocidad del latido del fondo, iconos del rodapié (añadir hasta 8, quitar, ordenar, icono integrado o imagen propia, URL / código iframe / YouTube, ventana emergente o página completa, ancho, alto, texto, color, posición y tamaño del botón de cierre), usuarios y restablecimiento total.

## ¿Olvidó su contraseña?

En la pantalla de acceso hay un enlace **¿Olvidó su contraseña?**. Se indica el usuario o el correo y se envía un enlace válido 30 minutos y de un solo uso a:
- el *correo de recuperación* del usuario (se define al instalar o en *Usuarios*), o
- el propio nombre de usuario, si es una dirección de correo.

La respuesta es siempre la misma (no revela si el usuario existe), hay límite de 5 solicitudes cada 15 min por IP y 3 correos por usuario y hora, y al cambiar la contraseña se cierran todas las sesiones abiertas. El correo se envía con la función `mail()` de PHP del hosting; si no llega, revise el SPF del dominio o la carpeta de spam. El administrador siempre puede cambiar la contraseña de cualquier usuario desde el panel.

## Comportamiento del kiosco

- Welcome! ↔ Bienvenidos! animado, en bucle.
- Fondo con latido doble tipo corazón; logo inferior con "jiggle" cada 5 s.
- Líneas decorativas con punto central que se estiran para ocupar siempre el 100 % del alto.
- Los cambios hechos en el panel aparecen solos en el kiosco en ≤ 1 minuto (sin interrumpir una ventana abierta).
- **3 clics en el fondo en menos de 2 s** (cualquier zona libre de la página o del rodapié, no sobre los iconos) → recarga invalidando la caché.
- Si se cae la red, sigue mostrando la última configuración recibida.
- Las webs cargadas van en un iframe aislado: no pueden sacar al kiosco de la página.

## Seguridad

- Bloqueo de fuerza bruta: 5 fallos por IP o 10 por usuario en 15 min → bloqueo de 15 min, que se duplica en cada reincidencia (máx. 24 h). Retardo fijo de 1 s por fallo y mensaje genérico (no revela si el usuario existe).
- Contraseñas con Argon2id/bcrypt; mínimo 10 caracteres y 3 tipos.
- Sesión con cookie `__Host-`, HttpOnly, Secure, SameSite=Strict; caduca a los 30 min sin uso y a las 8 h; cambiar la contraseña cierra las demás sesiones.
- Token CSRF en todos los formularios; permisos comprobados en el servidor para cada acción.
- Subidas: solo PNG/JPG/WEBP/GIF verificados por contenido, nombre aleatorio, `uploads/` sin ejecución de código.
- `data/` e `inc/` inaccesibles desde la web; CSP estricta; HTTPS forzado.
- `robots.txt` con `Disallow: /` más cabecera `X-Robots-Tag: noindex` y meta robots.
- Registro de accesos en `data/auth.log`.

## Notas

- Si una página no aparece dentro de la ventana (queda en blanco), es que su servidor prohíbe mostrarse en iframes (`X-Frame-Options` / `frame-ancestors`). Hay que permitir `welcome.bezares.com` en ese servidor.
- `assets/defaults/` contiene los SVG originales del WordPress (encabezado, fondo, logo e iconos). Las subidas admiten SVG (se rechazan los que contienen scripts o enlaces externos), PNG, JPG, WEBP y GIF.
- Si el sitio está detrás de Cloudflare u otro proxy, todas las IPs parecerán la del proxy; avise para adaptar `client_ip()`.
