<?php
/**
 * ¿El mismo componente se ve igual en los dos portales?
 *
 *   php test/tokens.php
 *
 * `assets/css/componentes.css` la cargan las dos pantallas —el inventario del
 * cliente y el panel de superadmin— y se apoya en tokens `--ih-*` que CADA
 * hoja define por separado: app.css para el cliente, intellihelp.css para el
 * panel. Una hoja compartida con tokens distintos no comparte nada: el mismo
 * desplegable, el mismo diálogo y el mismo campo de archivo se ven de una
 * manera en un portal y de otra en el otro, y nadie lo nota leyendo una sola.
 *
 * Esto resuelve los `var(--x)` que apuntan a otros tokens y compara el valor
 * FINAL. Hay dos clases de diferencia, y son distintas:
 *
 *   1. A propósito. Los `--ih-ctl-*` son la geometría de los controles, y los
 *      dos portales tienen controles de distinto tamaño: el panel es más
 *      compacto. Esa diferencia es el motivo por el que esos tokens existen.
 *
 *   2. Deuda. El panel todavía arrastra una paleta ajena —grises neutros y
 *      rojos, verdes y ámbares que no son los de la marca— de antes de que
 *      existiera la identidad visual. `--ih-success` vale #5f8a12 en el
 *      cliente y #16a34a en el panel: el mismo verde de la marca contra un
 *      verde cualquiera.
 *
 * La lista de abajo es la deuda que hay hoy, anotada una por una. Lo que esta
 * prueba impide es que CREZCA: si aparece una diferencia nueva, falla. Cuando
 * se unifique la paleta del panel, cada línea se va de la lista y la lista
 * queda vacía.
 */

declare(strict_types=1);

defined('RAIZ') || define('RAIZ', dirname(__DIR__));

/**
 * Diferencias que ya existen, con el por qué de cada una.
 *
 * `geometria` = a propósito, el panel es más compacto.
 * `deuda`     = la paleta vieja del panel, que hay que unificar.
 */
const TOKENS_QUE_DIFIEREN = [
    // ── A propósito: los controles del panel son más compactos ──────────────
    '--ih-ctl-pad'         => 'geometria',
    '--ih-ctl-min-h'       => 'geometria',
    '--ih-ctl-font'        => 'geometria',
    '--ih-ctl-line'        => 'geometria',
    '--ih-ctl-border'      => 'geometria',
    '--ih-ctl-radius'      => 'geometria',
    '--ih-ctl-hover'       => 'geometria',
    '--ih-ctl-ring'        => 'geometria',
    '--ih-ctl-placeholder' => 'geometria',

    // ── Deuda: el panel corre con otra escala, anterior a la identidad visual ─
    //
    // No son ajustes finos: el panel usa la escala de grises de Tailwind (zinc)
    // y la del cliente son los grises violaceos de la marca. Puestos uno al
    // lado del otro, #52525b es gris y #5b566a tira a violeta —poco, pero lo
    // suficiente para que un componente compartido se vea prestado.
    //
    // Los colores de estado son lo mas visible: el verde del panel es #16a34a,
    // que es justo el verde ajeno que hubo que sacar de las pantallas del
    // cliente. Convive con el #9CC930 de la marca en la misma interfaz.
    '--ih-danger'      => 'deuda',   // #a12a1e  contra #dc2626
    '--ih-danger-bg'   => 'deuda',   // #fdeceb  contra #fee2e2
    '--ih-danger-line' => 'deuda',   // #f3c9c4  contra #fecaca
    '--ih-warning'     => 'deuda',   // #a8620a  contra #ea580c
    '--ih-warning-bg'  => 'deuda',   // #fdf3e3  contra #ffedd5 — el fondo del otro
    '--ih-success'     => 'deuda',   // #5f8a12  contra #16a34a — dos verdes distintos
    '--ih-gray-50'     => 'deuda',   // #faf9fc  contra #fafafa
    '--ih-gray-100'    => 'deuda',   // #f6f5fa  contra #f4f4f5
    '--ih-gray-200'    => 'deuda',   // #e7e4ef  contra #e4e4e7
    '--ih-gray-300'    => 'deuda',   // #cfcbd8  contra #d4d4d8
    '--ih-gray-400'    => 'deuda',   // #a7a3b4  contra #a1a1aa
    '--ih-gray-500'    => 'deuda',   // #6f6a7d  contra #71717a
    '--ih-gray-600'    => 'deuda',   // #5b566a  contra #52525b
    '--ih-gray-700'    => 'deuda',   // #434050  contra #3f3f46
    '--ih-gray-800'    => 'deuda',   // #2b2836  contra #27272a
    '--ih-gray-900'    => 'deuda',   // #161922 (el de la marca) contra #18181b
    '--ih-purple-50'   => 'deuda',   // #f2ecf9  contra #f4f1f9
    '--ih-purple-dark' => 'deuda',   // #522a7a  contra #4e247a
    '--ih-radius-sm'   => 'deuda',   // 9px      contra 8px
    '--ih-radius-md'   => 'deuda',   // 10px     contra 12px
    '--ih-radius-lg'   => 'deuda',   // 15px — el radio de la marca — contra 16px
    '--ih-text-sm'     => 'deuda',   // 12.5px   contra 13px
    '--ih-text-md'     => 'deuda',   // 15.5px   contra 15px
    '--ih-text-lg'     => 'deuda',   // 18px     contra 17px
    '--ih-shadow-xs'   => 'deuda',
    '--ih-shadow-sm'   => 'deuda',
    '--ih-shadow-lg'   => 'deuda',
    '--ih-font'        => 'deuda',   // la misma lista de fuentes, escrita distinto
];

if (!function_exists('tokens_resueltos')) {
/**
 * Los tokens de una hoja, con los `var(--x)` ya resueltos.
 *
 * Cuatro pasadas alcanzan: las cadenas del proyecto son `--ih-danger` →
 * `--danger` → un color, nunca más largas.
 */
function tokens_resueltos(string $css): array {
    $out = [];

    /* Solo los bloques `:root`, que es donde se declaran los tokens.

       Buscar `--algo: valor;` en toda la hoja no sirve: un selector como
       `.ih-alert--danger:hover{background:...}` tambien encaja, y entonces
       `--danger` queda valiendo «hover{background:var(--danger-bg)». Pasó, y
       el resultado era que la comparacion marcaba como distinto algo que
       simplemente no se habia leido bien. */
    $bloques = '';
    if (preg_match_all('/:root[^{]*\{([^}]*)\}/is', $css, $mb)) {
        $bloques = implode("
", $mb[1]);
    }
    preg_match_all('/(--[a-zA-Z0-9-]+)\s*:\s*([^;]+);/', $bloques, $m, PREG_SET_ORDER);
    foreach ($m as $t) { $out[$t[1]] = trim($t[2]); }

    for ($i = 0; $i < 4; $i++) {
        foreach ($out as $k => $v) {
            if (preg_match('/^var\(\s*(--[a-zA-Z0-9-]+)\s*\)$/', $v, $mm) && isset($out[$mm[1]])) {
                $out[$k] = $out[$mm[1]];
            }
        }
    }
    // Se normaliza el espaciado: `0 1px 2px rgba(0,0,0,.1)` y la misma sombra
    // con un espacio después de cada coma son el mismo valor.
    return array_map(
        fn(string $v): string => strtolower((string)preg_replace('/\s*,\s*/', ',', trim($v))),
        $out
    );
}
}

if (!function_exists('tokens_divergentes')) {
/**
 * Los tokens que componentes.css usa y que las dos hojas resuelven distinto.
 *
 * Devuelve ['--token' => [valor en el cliente, valor en el panel]].
 */
function tokens_divergentes(): array {
    $comp  = (string)file_get_contents(RAIZ . '/assets/css/componentes.css');
    $clien = tokens_resueltos((string)file_get_contents(RAIZ . '/assets/css/app.css'));
    $panel = tokens_resueltos((string)file_get_contents(RAIZ . '/assets/css/intellihelp.css'));

    preg_match_all('/var\(\s*(--ih-[a-zA-Z0-9-]+)/', $comp, $usados);

    $out = [];
    foreach (array_unique($usados[1]) as $t) {
        if (!isset($clien[$t], $panel[$t])) { continue; }   // los cubre otra prueba
        if ($clien[$t] !== $panel[$t]) { $out[$t] = [$clien[$t], $panel[$t]]; }
    }
    ksort($out);
    return $out;
}
}

// Incluido desde la batería: sólo define las funciones.
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

echo "\n\033[1m  Tokens compartidos que valen distinto en cada portal\033[0m\n\n";

$div     = tokens_divergentes();
$nuevos  = array_diff_key($div, TOKENS_QUE_DIFIEREN);
$arreglados = array_diff_key(TOKENS_QUE_DIFIEREN, $div);

$porque = fn(string $t): string => TOKENS_QUE_DIFIEREN[$t] ?? 'NUEVO';
foreach ($div as $t => [$a, $b]) {
    $tipo = $porque($t);
    $color = $tipo === 'geometria' ? "\033[2m" : ($tipo === 'deuda' ? "\033[33m" : "\033[31m");
    printf("  %s%-10s\033[0m %-22s cliente: %-28s panel: %s\n",
        $color, $tipo, $t, $a, $b);
}

echo "\n";
if ($arreglados !== []) {
    echo "  \033[32m✓\033[0m Ya coinciden (sacar de TOKENS_QUE_DIFIEREN): "
       . implode(', ', array_keys($arreglados)) . "\n";
}
if ($nuevos !== []) {
    echo "  \033[31m✗\033[0m " . count($nuevos) . " diferencia(s) NUEVA(S): "
       . implode(', ', array_keys($nuevos)) . "\n\n";
    exit(1);
}

$deuda = array_keys(array_filter(TOKENS_QUE_DIFIEREN, fn($v) => $v === 'deuda'));
$deuda = array_intersect($deuda, array_keys($div));
echo '  ' . (count($deuda) === 0 ? "\033[32m✓\033[0m Los dos portales resuelven igual todo lo que no es geometría.\n"
          : "\033[33m·\033[0m " . count($deuda) . " tokens de deuda: la paleta del panel todavía no se unificó.\n");
echo "\n";
exit(0);
