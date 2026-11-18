<?php
// Configuración de fábrica del kiosco.
return [
    'version'            => 1,

    // --- Administrador ---
    'header_image'       => 'assets/defaults/header.svg',
    'background_image'   => 'assets/defaults/background.svg',
    'default_logo'       => 'assets/defaults/logo-bezares.svg',
    'welcome_texts'      => ['WELCOME', '¡BIENVENIDOS!'],
    'welcome_interval'   => 3,          // segundos
    'welcome_color'      => '#00567d',   // textos 1, 3, 5…
    'welcome_color2'     => '#009fe3',   // textos 2, 4, 6…
    'text_color'         => '#00567d',
    'line_color'         => '#b3b3b3',
    'page_bg'            => '#ffffff',
    'footer_title'       => 'Más información / Additional Information',
    'footer_title_color' => '#12100b',
    'footer_bg'          => '#d5d5d5',
    'footer_fg'          => '#12100b',
    'footer_strip'       => '#f5333f',   // franja inferior
    'footer_strip_height'=> 2.5,         // % del alto de pantalla (0 = sin franja)
    'bg_pulse_scale'     => 1.05,       // 1.00 = sin pulso
    'bg_pulse_duration'  => 2.4,        // segundos por latido
    'popup_idle_close'   => 0,          // segundos; 0 = desactivado
    // Dominios que se pueden mostrar en las ventanas del kiosco ("*.dominio" = subdominios).
    // El propio kiosco (welcome.bezares.com) nunca se puede incrustar aunque encaje en la lista.
    'frame_domains'      => "bezares.com\n*.bezares.com\nwww.youtube-nocookie.com",


    'icons' => [
        [
            'id' => 'web', 'label' => '',
            'icon' => 'globe', 'icon_image' => 'assets/defaults/icon-web.svg',
            'type' => 'url', 'url' => 'https://www.bezares.com/', 'youtube_id' => '',
            'display' => 'fullscreen', 'width' => '100%', 'height' => '100%',
            'close_text' => 'CERRAR / CLOSE', 'close_bg' => '#f5333f', 'close_fg' => '#ffffff',
            'close_position' => 'bottom-center', 'close_size' => 'large',
            'allow_same_origin' => false,   // aislamiento estricto
        ],
        [
            'id' => 'newsletter', 'label' => '',
            'icon' => 'mail', 'icon_image' => 'assets/defaults/icon-newsletter.svg',
            'type' => 'url', 'url' => 'https://mailing.bezares.com/subscribe.php?lang=both&kiosco=86217ec2d996c794', 'youtube_id' => '',
            'display' => 'popup', 'width' => '580px', 'height' => '470px',
            'close_text' => 'CERRAR / CLOSE', 'close_bg' => '#f5333f', 'close_fg' => '#ffffff',
            'close_position' => 'bottom-center', 'close_size' => 'medium',
            'allow_same_origin' => true,    // formulario con sesión / reproductor de YouTube
        ],
        [
            'id' => 'video', 'label' => '',
            'icon' => 'play', 'icon_image' => 'assets/defaults/icon-video.svg',
            'type' => 'youtube', 'url' => 'https://youtu.be/oxlym8KrOlE', 'youtube_id' => 'oxlym8KrOlE',
            'display' => 'popup', 'width' => '100%', 'height' => 'auto',
            'close_text' => 'CERRAR / CLOSE', 'close_bg' => '#f5333f', 'close_fg' => '#ffffff',
            'close_position' => 'bottom-center', 'close_size' => 'medium',
            'allow_same_origin' => true,    // formulario con sesión / reproductor de YouTube
        ],
    ],

    // --- Mantenedor ---
    // Texto central 1 (arriba) y texto central 2 (debajo), cada uno con su tamaño y negrita
    'center_text'        => '',
    'center_font_mode'   => 'auto',     // auto | fixed
    'center_font_size'   => 6,          // en % del ancho de pantalla (vw) cuando es manual
    'center_bold'        => false,
    'center_text2'       => '',
    'center_font_mode2'  => 'auto',
    'center_font_size2'  => 4,
    'center_bold2'       => false,
    'bottom_logo'        => '',         // '' = logo por defecto
    'logos'              => [],         // biblioteca de logos subidos por el mantenedor
];
