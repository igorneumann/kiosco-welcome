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
4. Permisos: carpeta `uploads/` → 755; archivos → 644. Los datos (usuarios, contraseñas, registros, sesiones) se guardan **fuera de la web**, en `/home/USUARIO/welcome-kiosk-data` (se crea sola con permisos 700). Si su hosting no lo permite, fije la ruta en `DATA_DIR`.
5. *MultiPHP Manager*: PHP 8.1 o superior. *MultiPHP INI Editor*: `upload_max_filesize = 8M`, `post_max_size = 10M`. Extensión `fileinfo` activada (*Select PHP Version → Extensions*).
6. Abra `https://welcome.bezares.com/`, introduzca la clave de instalación y cree el usuario **administrador** y el **mantenedor**. A partir de ahí el formulario de instalación desaparece.
7. En el equipo del kiosco, abra `https://welcome.bezares.com/VXM7D4mGu83ERwJC.html` en modo pantalla completa / kiosco del navegador.

Si venía de una versión anterior con los datos en `data/` dentro del sitio, se trasladan automáticamente a la nueva carpeta en la primera visita. El traslado es «todo o nada»: se copian y verifican (SHA-256) todos los archivos, y solo entonces se escribe la marca `.migrated` y se borran los originales; si algo falla, los originales quedan intactos, el sitio muestra un error de configuración y se reintenta en la siguiente visita. Si un archivo existe en los dos sitios con contenido distinto, la migración se detiene sin copiar ni borrar nada y el registro de errores de PHP indica qué archivos comparar. Si la carpeta de datos quedara dentro de la web, el panel del administrador muestra un aviso en rojo.

La URL del kiosco la sirve `kiosk.php` (regla de `.htaccess`) para enviar una CSP con los dominios permitidos. Si cambia `KIOSK_FILE`, cambie también esa regla.

## Roles

**Mantenedor**: dos textos centrales, uno debajo del otro (varias líneas cada uno), cada uno con su tamaño manual o botón **AUTO** (ajusta la fuente al ancho; si juntos no caben en el alto, se reducen en proporción) y opción de **negrita**, biblioteca de logos inferiores (subir, elegir, borrar), **Restablecer** en dos variantes: *conservar logos* (sin textos centrales, tema claro, logo de Bezares; la biblioteca de logos se mantiene) o *borrar logos* (además elimina los logos subidos), su contraseña.

**Administrador**: todo lo anterior, más imagen de encabezado, imagen de fondo, logo por defecto, textos de bienvenida (uno por línea, se alternan), colores (rodapié, líneas, textos, fondo), intensidad y velocidad del latido del fondo, iconos del rodapié (añadir hasta 8, quitar, ordenar, icono integrado o imagen propia, URL / código iframe / YouTube, ventana emergente o página completa, ancho, alto, texto, color, posición y tamaño del botón de cierre), usuarios y restablecimiento total.

## Tema claro / oscuro

El tema **claro** es el predeterminado. El mantenedor (y el administrador) lo cambia con el selector deslizante **Tema de la pantalla**, arriba del panel; el kiosco lo aplica en menos de un minuto. El tema oscuro usa versiones oscuras del encabezado, del fondo y de los tres iconos (incluidas en `assets/defaults/*-dark.svg`) y muestra el logo inferior sobre una placa blanca, como pide el manual de marca. El administrador define sus imágenes y colores en la sección **Tema oscuro**, y cada icono del rodapié admite una imagen opcional para el tema oscuro. *Restablecer por defecto* vuelve al tema claro.

## Kiosco dentro de otras webs

Por defecto el kiosco y el panel solo se pueden mostrar dentro de un iframe en el propio sitio. En **Integración → Webs de confianza** el administrador añade las webs que pueden incrustarlos (`www.bezares.com`, `*.bezares.com`, con puerto opcional; solo https). La casilla *Permitir también el panel* (activada por defecto) extiende el permiso al acceso, la recuperación de contraseña y la administración. Se aplica con la cabecera CSP `frame-ancestors` (kiosco: `kiosk.php`; panel: `security_headers()`); sin webs de confianza, el panel envía `X-Frame-Options: DENY`. El inicio de sesión dentro del iframe funciona en webs del mismo dominio (`cloud.bezares.com`); en dominios ajenos el navegador bloquea la cookie de sesión.

## Icono

`assets/favicon-light.svg` y `assets/favicon-dark.svg` (32×32): un kiosco con la Bz en la pantalla y una W en la base. Se usan como icono de pestaña (claro u oscuro según el tema del navegador) y en la barra del panel.

## Estadísticas

Página **Estadísticas** del panel del administrador: interacciones (aperturas de cada icono), suscripciones a la newsletter, reproducciones de vídeo, visitas a páginas web, tiempo medio por ventana y arranques del kiosco, con gráficos por día y por hora y tabla por icono. Periodo seleccionable (por defecto, el **mes anterior**) y comparación con otro periodo (por defecto, el **periodo anterior**; también el mismo periodo del año anterior o fechas a medida). Exportación a CSV.

- El kiosco envía los eventos a `api/track.php` (solo POST del mismo origen, token diario, lista cerrada de eventos, 60 eventos/min por dirección). Se guardan contadores agregados por día en `stats/AAAA-MM.json` de la carpeta de datos, sin datos personales. La vista previa del panel no cuenta.
- Suscripciones: cada clic en el botón de enviar del formulario de la newsletter, sin cambios en Bezares Mailing. Se detecta por la recarga del formulario al enviarlo, descontando la recarga automática del modo kiosco (ajuste *segundos del mensaje* en Apariencia, por defecto 5; debe coincidir con el de Bezares Mailing).

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

**Fuerza bruta sin bloqueos abusivos**
- Contador por IP + cuenta: 5 fallos → bloqueo de 5 min, que se duplica en cada reincidencia (máx. **1 h**).
- Contador por IP: 30 fallos con cualquier usuario → mismo bloqueo (frena barridos desde una dirección).
- **Navegador de confianza**: tras entrar correctamente, el navegador recibe una cookie firmada (90 días). En ese navegador el usuario tiene su propio contador y no le afecta el límite por IP, así que un tercero (aunque comparta la IP de la oficina) **no puede bloquearle**.
- Ataque distribuido contra una cuenta (20 fallos desde varias IP en 15 min): la cuenta **no se bloquea**; los navegadores no reconocidos pasan a 1 intento cada 10 s y se **avisa por correo a los administradores** (máx. 1 aviso por hora).
- Respuesta genérica, retardo fijo de 1 s por fallo y registro en `auth.log` (carpeta de datos).
- Opcional: `ADMIN_ALLOW_IPS` en `inc/settings.php` limita el panel a IP/rangos concretos (el kiosco sigue público).
- Protección en el servidor (recomendada): en cPanel, *Seguridad → Bloqueador de IP* para IP concretas; si el hosting tiene **cPHulk** o **ModSecurity**, actívelos. En un VPS con fail2ban se puede usar `auth.log` con el patrón `\t<HOST>\t[^\t]*\tlogin_fail`.

**Subidas de imágenes**
- PNG/JPG/WEBP/GIF: se vuelven a codificar con GD (**obligatoria**: sin GD no se acepta ninguna imagen) (se eliminan metadatos y cualquier contenido oculto; un GIF animado queda en su primer fotograma).
- SVG: se reconstruyen con una **lista cerrada** de etiquetas y atributos (sin scripts, eventos, `foreignObject`, SVG anidados, enlaces externos ni `data:`); si el servidor tiene Imagick con soporte SVG, se convierten a **PNG**. Antes de convertir se rechazan los SVG de más de 2 MB, más de 20 000 elementos, dimensiones declaradas excesivas o desproporcionadas, y el lienzo de salida se fija a 2048 px como máximo, con límites de memoria (256 MB), píxeles y tiempo (20 s) en Imagick.
- `uploads/` solo sirve imágenes, sin ejecución de código y con CSP `sandbox`.

**Ventanas del kiosco (iframes)**
- Solo `https://` y solo dominios de la **lista de dominios permitidos** (sección Iconos). Nunca el propio kiosco, IP ni nombres de red local. Se comprueba al guardar, al servir la configuración y en el navegador.
- La CSP de la página del kiosco se genera con esa misma lista.
- `sandbox` sin `allow-top-navigation`; `allow-same-origin` solo en los iconos que lo tengan activado (por defecto: newsletter y vídeo, que lo necesitan; la web de Bezares va aislada; si alguna página no funcionara bien, actívelo en su icono).

**Datos y sesiones**
- Fuera de `public_html`, permisos 700/600, escrituras atómicas (archivo temporal + renombrado).
- Contraseñas con Argon2id/bcrypt (mín. 10 caracteres y 3 tipos). Sesión `__Host-`, HttpOnly, Secure, SameSite=Strict, caducidad 30 min sin uso / 8 h; cambiar la contraseña cierra las demás sesiones.
- Pantalla de acceso con botón **Ver contraseña** y casilla **Mantener sesión iniciada**: la sesión dura hasta `REMEMBER_DAYS` (30) días en ese navegador aunque se cierre; se invalida igualmente al cerrar sesión, al cambiar la contraseña o si cambia el navegador.
- Token CSRF en todos los formularios; permisos comprobados en el servidor; CSP estricta; HTTPS forzado; `robots.txt` + `noindex`.

**Recuperación de contraseña**
- Enlace de un solo uso, 30 min, ligado a la contraseña actual (si cambia por otra vía, el enlace deja de valer).
- Abrir enlaces caducados o erróneos nunca bloquea (solo se registra); el límite estricto se aplica a los envíos del formulario con enlace no válido.
- La contraseña nueva se guarda antes de invalidar el enlace: si falla la escritura, el enlace sigue sirviendo.

## Notas

- Si una página no aparece dentro de la ventana (queda en blanco), es que su servidor prohíbe mostrarse en iframes (`X-Frame-Options` / `frame-ancestors`). Hay que permitir `welcome.bezares.com` en ese servidor.
- `assets/defaults/` contiene los SVG originales del WordPress (encabezado, fondo, logo e iconos). Las subidas admiten SVG (se rechazan los que contienen scripts o enlaces externos), PNG, JPG, WEBP y GIF.
- Si el sitio está detrás de Cloudflare u otro proxy, todas las IPs parecerán la del proxy; avise para adaptar `client_ip()`.
