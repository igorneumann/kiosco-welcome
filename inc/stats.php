<?php
declare(strict_types=1);
/*
 * Estadísticas de interacción del kiosco.
 *
 * El kiosco envía eventos a api/track.php y aquí se guardan AGREGADOS por día (sin datos personales):
 * un archivo por mes en la carpeta de datos, stats/AAAA-MM.json:
 *   { "days": { "2026-11-23": {
 *       "start": 3,                     arranques/recargas de la pantalla del kiosco
 *       "reload": 1,                    recargas con 3 clics en el fondo
 *       "open": {"web": 12, ...},       aperturas de cada icono del rodapié
 *       "dur": {"web": [340, 11], ...}, segundos totales y nº de cierres por icono (tiempo medio abierto)
 *       "idle": 2,                      ventanas cerradas por inactividad
 *       "nl": {"newsletter": 4},        suscripciones a la newsletter (formulario de Bezares Mailing)
 *       "hours": [0, 0, …]              aperturas por hora del día (24 valores)
 *   } } }
 */

const STATS_EVENTS = ['start', 'reload', 'open', 'close', 'nl'];

function stats_dir(): string
{
    $d = data_path('stats');
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

/** Token diario que el kiosco recibe con su configuración y devuelve con cada evento. */
function stats_token(?string $day = null): string
{
    return substr(hash_hmac('sha256', 'track|' . ($day ?? date('Y-m-d')), app_secret()), 0, 32);
}

function stats_token_valid(string $t): bool
{
    foreach ([date('Y-m-d'), date('Y-m-d', time() - 86400)] as $day) {
        if (hash_equals(stats_token($day), $t)) {
            return true;
        }
    }
    return false;
}

/** Registra un evento. Devuelve false si se rechaza por límite de frecuencia. */
function stats_record(string $event, string $icon = '', float $dur = 0, string $reason = ''): bool
{
    $day = date('Y-m-d');
    $hour = (int)date('G');
    $file = stats_dir() . '/' . date('Y-m') . '.json';
    $ipKey = substr(hash_hmac('sha256', client_ip(), app_secret()), 0, 16); // IP seudonimizada, solo para el límite
    return (bool)json_update($file, function (array &$s) use ($event, $icon, $dur, $reason, $day, $hour, $ipKey) {
        // Límite: 60 eventos por minuto y dirección
        $min = intdiv(time(), 60);
        foreach ($s['rl'] ?? [] as $k => $v) {
            if ($v[0] < $min - 1) { unset($s['rl'][$k]); }
        }
        $rl = $s['rl'][$ipKey] ?? [$min, 0];
        if ($rl[0] !== $min) { $rl = [$min, 0]; }
        if (++$rl[1] > 60) { $s['rl'][$ipKey] = $rl; return false; }
        $s['rl'][$ipKey] = $rl;

        $d = $s['days'][$day] ?? [];
        switch ($event) {
            case 'start':
            case 'reload':
                $d[$event] = ($d[$event] ?? 0) + 1;
                break;
            case 'open':
                $d['open'][$icon] = ($d['open'][$icon] ?? 0) + 1;
                $d['hours'] = $d['hours'] ?? array_fill(0, 24, 0);
                $d['hours'][$hour]++;
                break;
            case 'close':
                $cur = $d['dur'][$icon] ?? [0, 0];
                $d['dur'][$icon] = [$cur[0] + (int)round(max(0, min($dur, 3600))), $cur[1] + 1];
                if ($reason === 'idle') { $d['idle'] = ($d['idle'] ?? 0) + 1; }
                break;
            case 'nl':
                $d['nl'][$icon] = ($d['nl'][$icon] ?? 0) + 1;
                break;
        }
        $s['days'][$day] = $d;
        return true;
    }, ['days' => []]);
}

/** Suma las estadísticas de un intervalo de fechas (AAAA-MM-DD, ambos incluidos). */
function stats_query(string $from, string $to): array
{
    $r = ['start' => 0, 'reload' => 0, 'idle' => 0, 'open' => [], 'dur' => [], 'nl' => [], 'hours' => array_fill(0, 24, 0),
          'daily' => [], 'opens' => 0, 'subs' => 0];
    $d0 = new DateTimeImmutable($from);
    $d1 = new DateTimeImmutable($to);
    $months = [];
    for ($m = $d0->modify('first day of this month'); $m <= $d1; $m = $m->modify('+1 month')) {
        $months[$m->format('Y-m')] = json_read(stats_dir() . '/' . $m->format('Y-m') . '.json', ['days' => []])['days'] ?? [];
    }
    for ($d = $d0; $d <= $d1; $d = $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $day = $months[$d->format('Y-m')][$key] ?? [];
        $opens = array_sum($day['open'] ?? []);
        $subs = array_sum($day['nl'] ?? []);
        $r['daily'][$key] = ['opens' => $opens, 'subs' => $subs, 'start' => (int)($day['start'] ?? 0)];
        $r['opens'] += $opens;
        $r['subs'] += $subs;
        foreach (['start', 'reload', 'idle'] as $k) { $r[$k] += (int)($day[$k] ?? 0); }
        foreach ($day['open'] ?? [] as $ic => $n) { $r['open'][$ic] = ($r['open'][$ic] ?? 0) + $n; }
        foreach ($day['nl'] ?? [] as $ic => $n) { $r['nl'][$ic] = ($r['nl'][$ic] ?? 0) + $n; }
        foreach ($day['dur'] ?? [] as $ic => [$sec, $n]) {
            $cur = $r['dur'][$ic] ?? [0, 0];
            $r['dur'][$ic] = [$cur[0] + $sec, $cur[1] + $n];
        }
        foreach ($day['hours'] ?? [] as $h => $n) { $r['hours'][$h] += $n; }
    }
    return $r;
}

/** Intervalo por defecto y de comparación a partir de los parámetros de la página. */
function stats_periods(array $q): array
{
    $today = new DateTimeImmutable('today');
    $preset = in_array($q['preset'] ?? '', ['lastmonth', 'thismonth', 'last7', 'last30', 'thisyear', 'lastyear', 'custom'], true)
        ? $q['preset'] : 'lastmonth';
    $valid = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    switch ($preset) {
        case 'thismonth': $from = $today->modify('first day of this month'); $to = $today; break;
        case 'last7':     $from = $today->modify('-6 days'); $to = $today; break;
        case 'last30':    $from = $today->modify('-29 days'); $to = $today; break;
        case 'thisyear':  $from = $today->setDate((int)$today->format('Y'), 1, 1); $to = $today; break;
        case 'lastyear':  $y = (int)$today->format('Y') - 1; $from = $today->setDate($y, 1, 1); $to = $today->setDate($y, 12, 31); break;
        case 'custom':
            $from = $valid($q['from'] ?? null) ? new DateTimeImmutable($q['from']) : $today->modify('first day of last month');
            $to = $valid($q['to'] ?? null) ? new DateTimeImmutable($q['to']) : $today->modify('last day of last month');
            if ($to < $from) { [$from, $to] = [$to, $from]; }
            break;
        default:          $from = $today->modify('first day of last month'); $to = $today->modify('last day of last month');
    }
    if ($from->diff($to)->days > 1100) { $from = $to->modify('-1100 days'); } // máx. ~3 años por consulta
    $days = $from->diff($to)->days + 1;

    $cmp = in_array($q['cmp'] ?? '', ['prev', 'year', 'custom', 'none'], true) ? $q['cmp'] : 'prev';
    $cfrom = $cto = null;
    if ($cmp === 'prev') {
        if ($preset === 'lastmonth' || ($preset === 'custom' && $from->format('d') === '01' && $to->format('Y-m-d') === $to->modify('last day of this month')->format('Y-m-d') && $from->format('Y-m') === $to->format('Y-m'))) {
            $cfrom = $from->modify('first day of last month');            // mes natural anterior
            $cto = $cfrom->modify('last day of this month');
        } elseif ($preset === 'thismonth') {
            $cfrom = $from->modify('first day of last month');            // mismos días del mes anterior
            $cto = min($cfrom->modify('+' . ($days - 1) . ' days'), $cfrom->modify('last day of this month'));
        } elseif ($preset === 'thisyear' || $preset === 'lastyear') {
            $cfrom = $from->modify('-1 year'); $cto = $to->modify('-1 year');
        } else {
            $cto = $from->modify('-1 day');
            $cfrom = $cto->modify('-' . ($days - 1) . ' days');
        }
    } elseif ($cmp === 'year') {
        $cfrom = $from->modify('-1 year'); $cto = $to->modify('-1 year');
    } elseif ($cmp === 'custom') {
        $cfrom = $valid($q['cfrom'] ?? null) ? new DateTimeImmutable($q['cfrom']) : $from->modify('-' . $days . ' days');
        $cto = $valid($q['cto'] ?? null) ? new DateTimeImmutable($q['cto']) : $from->modify('-1 day');
        if ($cto < $cfrom) { [$cfrom, $cto] = [$cto, $cfrom]; }
        if ($cfrom->diff($cto)->days > 1100) { $cfrom = $cto->modify('-1100 days'); }
    }
    return [
        'preset' => $preset, 'cmp' => $cmp,
        'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
        'cfrom' => $cfrom ? $cfrom->format('Y-m-d') : '', 'cto' => $cto ? $cto->format('Y-m-d') : '',
    ];
}

/** Variación porcentual con texto y signo (para las tarjetas). */
function stats_delta(float $cur, ?float $prev): array
{
    if ($prev === null) {
        return ['', ''];
    }
    if ($prev == 0.0) {
        return $cur == 0.0 ? ['=', 'flat'] : ['nuevo', 'up'];
    }
    $p = ($cur - $prev) / $prev * 100;
    if (abs($p) < 0.5) {
        return ['=', 'flat'];
    }
    return [($p > 0 ? '+' : '−') . number_format(abs($p), abs($p) < 10 ? 1 : 0, ',', '.') . ' %', $p > 0 ? 'up' : 'down'];
}

function stats_fmt_date(string $ymd): string
{
    $m = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    [$y, $mo, $d] = explode('-', $ymd);
    return (int)$d . ' ' . $m[(int)$mo] . ' ' . $y;
}

function stats_fmt_secs(float $s): string
{
    $s = (int)round($s);
    return $s >= 60 ? intdiv($s, 60) . ' min ' . sprintf('%02d', $s % 60) . ' s' : $s . ' s';
}

/**
 * Gráfico de barras (SVG en línea, sin scripts): serie actual en azul y comparación en gris, por posición.
 * $labels: etiquetas del eje X de la serie actual.
 */
function stats_bar_svg(array $cur, ?array $cmp, array $labels, string $title, int $w = 920, int $h = 260): string
{
    $n = max(count($cur), $cmp ? count($cmp) : 0, 1);
    $max = max(1, max($cur ?: [0]), $cmp ? max($cmp ?: [0]) : 0);
    $nice = (int)ceil($max / max(1, 10 ** (strlen((string)$max) - 1))) * max(1, 10 ** (strlen((string)$max) - 1));
    $nice = max($nice, 1);
    $pl = 44; $pr = 8; $pt = 12; $pb = 34;
    $cw = ($w - $pl - $pr) / $n;
    $bw = $cmp ? max(1.5, $cw * 0.38) : max(2, $cw * 0.7);
    $y = fn($v) => $pt + ($h - $pt - $pb) * (1 - $v / $nice);
    $o = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($title) . '" preserveAspectRatio="none">';
    for ($g = 0; $g <= 4; $g++) {
        $v = $nice * $g / 4;
        $yy = round($y($v), 1);
        $o .= '<line x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="#e6eaef" stroke-width="1"/>';
        $o .= '<text x="' . ($pl - 6) . '" y="' . ($yy + 4) . '" text-anchor="end" font-size="11" fill="#667085">' . (fmod($v, 1.0) ? number_format($v, 1, ',', '') : (int)$v) . '</text>';
    }
    $step = max(1, (int)ceil($n / 12));
    for ($i = 0; $i < $n; $i++) {
        $x = $pl + $cw * $i + ($cw - ($cmp ? $bw * 2 + 1 : $bw)) / 2;
        if ($cmp) {
            $v = (float)($cmp[$i] ?? 0);
            if ($v > 0) {
                $o .= '<rect x="' . round($x, 1) . '" y="' . round($y($v), 1) . '" width="' . round($bw, 1) . '" height="' . round($h - $pb - $y($v), 1) . '" rx="1.5" fill="#c7d0d9"><title>' . e(($title) . ' · comparación: ' . $v) . '</title></rect>';
            }
            $x += $bw + 1;
        }
        $v = (float)($cur[$i] ?? 0);
        if ($v > 0) {
            $o .= '<rect x="' . round($x, 1) . '" y="' . round($y($v), 1) . '" width="' . round($bw, 1) . '" height="' . round($h - $pb - $y($v), 1) . '" rx="1.5" fill="#00567d"><title>' . e(($labels[$i] ?? '') . ': ' . $v) . '</title></rect>';
        }
        if ($i % $step === 0 && isset($labels[$i])) {
            $o .= '<text x="' . round($pl + $cw * $i + $cw / 2, 1) . '" y="' . ($h - 12) . '" text-anchor="middle" font-size="11" fill="#667085">' . e($labels[$i]) . '</text>';
        }
    }
    $o .= '<line x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . ($h - $pb) . '" y2="' . ($h - $pb) . '" stroke="#c9d1da" stroke-width="1"/>';
    return $o . '</svg>';
}
