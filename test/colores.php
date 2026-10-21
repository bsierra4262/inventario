<?php
/**
 * ¿Hay algún color que no sea de la marca?
 *
 *   php test/colores.php
 *
 * La identidad visual de INTELLIHELP son cuatro colores:
 *
 *   Primario    #663399
 *   Lila        #9f88b1
 *   Fondo       #fff6ff   — blanco con una gota de violeta
 *   Crema       #fffade
 *
 * sobre texto #161922, más la escala de grises violáceos y los dos colores de
 * estado —error y advertencia— que no pueden ser violetas sin dejar de
 * leerse. Todo eso vive en los tokens de assets/css/app.css y de
 * assets/css/intellihelp.css, y está acá como lista blanca.
 *
 * El acento verde #9CC930 YA NO ESTÁ. Era el color de lo positivo —guardar, la
 * insignia «Activo», el aviso de éxito— y ahora eso es violeta, igual que en el
 * portal de RRHH. Si aparece un #9cc930 en el árbol, este test lo marca: es
 * paleta vieja que quedó.
 *
 * Un color escrito a mano en una pantalla no se ve mal por sí solo: se ve mal
 * al lado del de al lado. Los avisos del listado usaban #16a34a, #d97706 y
 * #b91c1c —un verde, un ámbar y un rojo de otra paleta— y como la hoja de la
 * página carga después de la del sistema, le ganaban. En la misma pantalla
 * convivían dos verdes distintos.
 */

declare(strict_types=1);

defined('RAIZ') || define('RAIZ', dirname(__DIR__));

/**
 * Los colores permitidos, en minúscula.
 *
 * Salen de los tokens de app.css: si se agrega uno ahí, se agrega acá. Que haya
 * que tocar dos lugares es a propósito — sumar un color a la identidad visual
 * no debería ser algo que pase sin que nadie lo note.
 */
const COLORES_MARCA = [
    // Los cuatro de la paleta, más el texto
    '#ffffff', '#fff', '#161922', '#663399', '#9f88b1', '#fff6ff', '#fffade',
    // Violetas derivados
    '#522980', '#3d1f5c', '#e6dcec', '#8c5bb8', '#d8cbe3',
    // Estados: error y advertencia. No pueden ser violetas sin dejar de leerse.
    '#6e5a00', '#ece3a6',
    '#c0392b', '#fcedeb', '#f0c9c4',
    // Grises violáceos
    '#ebe2ef', '#f5eef7',
    '#6b6f7b', '#ddd8e4', '#a8a2b4', '#565a68', '#3d4150', '#2b2836',
];

/**
 * Los colores de marcas ajenas que SÍ pueden aparecer.
 *
 * El logo de Google es reconocible porque es rojo, azul, amarillo y verde: si
 * se lo pinta de violeta deja de ser el logo de Google y la gente no lo
 * encuentra. Lo mismo con Microsoft y con los iconos de Drive y OneDrive.
 *
 * Esto NO es una puerta para colar colores propios: sólo entra lo que forma
 * parte del logotipo de otro, y siempre dentro de un SVG.
 */
const COLORES_TERCEROS = [
    // Google
    '#ea4335', '#4285f4', '#fbbc05', '#34a853',
    // Microsoft
    '#f25022', '#00a4ef', '#7fba00', '#ffb900',
    // Google Drive
    '#0066da', '#00ac47', '#00832d', '#2684fc', '#ffba00',
    // OneDrive
    '#0364b8', '#0078d4', '#1490df', '#28a8ea',
];

if (!function_exists('colores_fuera_de_paleta')) {
/**
 * Colores fuera de la paleta, agrupados por archivo.
 *
 * Se ignoran los comentarios: explicar por qué se sacó un #16a34a no puede
 * contar como usarlo. Se usa el lexer de PHP, igual que en test/ortografia.php.
 */
/* `dashboard` entró en la lista recién ahora: el panel superadmin nunca se
   miraba, y tenía veinte colores escritos a mano —#ef4444, #10b981, #1a1a2e,
   #71717A— puestos en atributos `style=`. Un auditor que mira cuatro de las
   cinco carpetas da verde y no cuida nada en la quinta. */
function colores_fuera_de_paleta(array $dirs = ['inventario', 'backend', 'includes', 'includes/layout', 'auth', 'dashboard']): array {
    $permitidos = array_flip(array_merge(COLORES_MARCA, COLORES_TERCEROS));
    $out = [];

    foreach ($dirs as $dir) {
        foreach (glob(RAIZ . '/' . $dir . '/*.php') ?: [] as $f) {
            $rel = $dir . '/' . basename($f);
            $src = (string)file_get_contents($f);

            $limpio = '';
            foreach (@token_get_all($src) as $t) {
                if (is_array($t)) {
                    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                    $limpio .= $t[1];
                } else {
                    $limpio .= $t;
                }
            }
            // Los comentarios del HTML y del CSS embebido tampoco cuentan.
            $limpio = (string)preg_replace('/<!--.*?-->/s', '', $limpio);
            $limpio = (string)preg_replace('#/\*.*?\*/#s', '', $limpio);

            if (!preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $limpio, $mm)) { continue; }
            foreach ($mm[0] as $c) {
                $c = strtolower($c);
                // Sólo #rgb y #rrggbb: lo demás no es un color escrito a mano.
                if (strlen($c) !== 4 && strlen($c) !== 7) { continue; }
                if (isset($permitidos[$c])) { continue; }
                $out[$rel][$c] = ($out[$rel][$c] ?? 0) + 1;
            }
        }
    }
    ksort($out);
    return $out;
}
}

// Incluido desde la batería: sólo define la función.
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

echo "\n\033[1m  Colores fuera de la identidad visual\033[0m\n";
$hallazgos = colores_fuera_de_paleta();

if ($hallazgos === []) {
    echo "  \033[32m✓\033[0m No hay ningún color escrito a mano fuera de la paleta.\n\n";
    exit(0);
}

$total = 0;
foreach ($hallazgos as $arch => $colores) {
    $total += count($colores);
    echo "\n  \033[1m{$arch}\033[0m\n";
    foreach ($colores as $c => $veces) {
        echo "      \033[31m{$c}\033[0m" . ($veces > 1 ? " (x{$veces})" : '') . "\n";
    }
}
echo "\n  \033[31m{$total}\033[0m colores fuera de la paleta\n\n";
exit(1);
