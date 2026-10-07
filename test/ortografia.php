<?php
/**
 * Revisión de ortografía y vocabulario del texto que ve el usuario.
 *
 *   php test/ortografia.php
 *
 * No es un corrector general: es una lista cerrada de errores concretos del
 * castellano técnico, más las decisiones de vocabulario que el proyecto ya
 * tomó. Una lista cerrada no encuentra todo, pero tampoco inventa: un corrector
 * genérico marcaría «S/N», «CSV» o «ThinkPad», y con ese ruido nadie volvería a
 * mirarlo.
 *
 * El texto se separa del código con token_get_all(), el lexer de PHP, y no con
 * expresiones regulares: la primera versión usaba regex y marcaba nombres de
 * columna («producto_id, accion, descripcion»), variables y comentarios.
 *
 * Aun con el lexer hay que descartar lo que es código pero vive dentro del HTML:
 * los bloques <script>, las cabeceras `Location:`, el SQL armado en cadenas y
 * los selectores de CSS. Todo eso se filtra en es_prosa().
 */

declare(strict_types=1);

// RAIZ puede venir ya definida: test/run_tests.php incluye este archivo.
defined('RAIZ') || define('RAIZ', dirname(__DIR__));

/**
 * Palabras mal escritas → cómo van. Se busca la palabra entera, sin distinguir
 * mayúsculas. Sólo van las que NO existen como otra palabra: «mas», «solo» y
 * «esta» quedan afuera porque son válidas sin tilde.
 */
const ORTO_FALTAS = [
    'categoria' => 'categoría', 'categorias' => 'categorías',
    'subcategoria' => 'subcategoría', 'subcategorias' => 'subcategorías',
    'descripcion' => 'descripción', 'ubicacion' => 'ubicación',
    'informacion' => 'información', 'configuracion' => 'configuración',
    'sesion' => 'sesión', 'contrasena' => 'contraseña',
    'numero' => 'número', 'numeros' => 'números',
    'codigo' => 'código', 'codigos' => 'códigos',
    'articulo' => 'artículo', 'articulos' => 'artículos',
    'telefono' => 'teléfono', 'direccion' => 'dirección',
    'garantia' => 'garantía', 'garantias' => 'garantías',
    'periodo' => 'período', 'ultimo' => 'último', 'ultima' => 'última',
    'proximo' => 'próximo', 'proxima' => 'próxima',
    'minimo' => 'mínimo', 'maximo' => 'máximo', 'aqui' => 'aquí',
    'asignacion' => 'asignación', 'operacion' => 'operación',
    'accion' => 'acción', 'edicion' => 'edición',
    'eliminacion' => 'eliminación', 'creacion' => 'creación',
    'validacion' => 'validación', 'integracion' => 'integración',
    'amortizacion' => 'amortización', 'depreciacion' => 'depreciación',
    'facturacion' => 'facturación', 'exportacion' => 'exportación',
    'importacion' => 'importación', 'duplicacion' => 'duplicación',
    'autenticacion' => 'autenticación', 'tecnico' => 'técnico',
    'tecnica' => 'técnica', 'tecnicos' => 'técnicos', 'tecnicas' => 'técnicas',
    'informatica' => 'informática', 'perifericos' => 'periféricos',
    'deposito' => 'depósito', 'logistica' => 'logística',
    'automatico' => 'automático', 'automatica' => 'automática',
    'basico' => 'básico', 'basica' => 'básica',
    'rapido' => 'rápido', 'rapida' => 'rápida', 'practico' => 'práctico',
    'historico' => 'histórico', 'estadistica' => 'estadística',
    'estadisticas' => 'estadísticas', 'analisis' => 'análisis',
    'gestion' => 'gestión', 'version' => 'versión',
    'opcion' => 'opción', 'seleccion' => 'selección',
    'relacion' => 'relación', 'facil' => 'fácil', 'dificil' => 'difícil',
    'segun' => 'según', 'tambien' => 'también', 'ademas' => 'además',
    'despues' => 'después', 'valida' => 'válida', 'valido' => 'válido',
    'invalido' => 'inválido', 'invalida' => 'inválida',
    'maquina' => 'máquina', 'pagina' => 'página', 'paginas' => 'páginas',
    'modulo' => 'módulo', 'modulos' => 'módulos',
    'bateria' => 'batería', 'baterias' => 'baterías',
    'extension' => 'extensión', 'extensiones' => 'extensiones',
    'anios' => 'años', 'anio' => 'año',
    'numeros' => 'números',
    'tamano' => 'tamaño', 'tamanos' => 'tamaños',
    'disenio' => 'diseño', 'conexion' => 'conexión',
    'ubicacion' => 'ubicación', 'instalacion' => 'instalación',
    'aplicacion' => 'aplicación',
];

/**
 * Vocabulario: la misma cosa tiene que llamarse siempre igual. Si en una
 * pantalla dice «Ubicación» y en otra «Locación», el usuario cree que son dos
 * campos distintos.
 */
const ORTO_TERMINOS = [
    'locacion' => 'Ubicación',   // calco del inglés, y además el mismo campo
    'locación' => 'Ubicación',
    'loguearse' => 'iniciar sesión',
    'logueo' => 'inicio de sesión',
    'deletear' => 'eliminar',
    'linkear' => 'vincular',
    'setear' => 'definir',
    'resetear' => 'restablecer',
    'resetea' => 'restablece',
];

/** ¿Es texto para leer, o es código que quedó suelto en una cadena? */
function es_prosa(string $s): bool {
    $s = trim($s);
    if (mb_strlen($s) < 8) { return false; }
    if (!str_contains($s, ' ')) { return false; }            // un identificador

    // Cabecera HTTP, ruta, URL: no es texto, es un destino.
    if (preg_match('/^(Location|Content-[A-Za-z-]+|Set-Cookie)\s*:/i', $s)) { return false; }
    if ($s[0] === '/' || str_starts_with($s, 'http')) { return false; }
    if (preg_match('/\.(php|css|js|json|csv|png|svg|pdf)\b/i', $s)) { return false; }

    // SQL armado en una cadena.
    if (str_contains($s, '`')) { return false; }
    if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|JOIN|ORDER\s+BY|GROUP\s+BY|CREATE\s+TABLE|ALTER\s+TABLE|COALESCE|IFNULL)\b/i', $s)) { return false; }
    if (preg_match('/\bLIKE\s*\?|\bLOWER\s*\(/i', $s)) { return false; }
    // `pm.accion = 'Archivado`, `p.modelo LIKE ?`: tabla.campo seguido de un
    // operador. El \b no sirve después de `=` porque lo que sigue es un espacio.
    if (preg_match('/\b[a-z_]+\.[a-z_]+\s*(=|!=|<|>|\bLIKE\b|\bIN\b|\bIS\b)/i', $s)) { return false; }
    if (preg_match('/^[a-z_]+(\s*,\s*[a-z_]+)+$/i', $s)) { return false; }

    // Resto de un atributo que quedó partido: `" href="?tab=amortizacion">`.
    if (preg_match('/\w+\s*=\s*["\']/', $s)) { return false; }
    if (preg_match('/^\?[a-z_]+=/i', $s)) { return false; }          // ?tab=...

    // JavaScript o CSS que se coló de un <script> partido por una etiqueta PHP.
    if (preg_match('/document\.|getElementById|querySelector|addEventListener|classList|=>/', $s)) { return false; }
    if (preg_match('/[{}]|\[\'|\'\]|;\s*$/', $s)) { return false; }
    if (preg_match('#^\s*(//|/\*|\*)#', $s)) { return false; }       // un comentario
    if (preg_match('/^[#.][a-z][\w-]*$/i', $s)) { return false; }    // selector CSS

    // Tiene que parecer una frase: al menos dos palabras de 3+ letras.
    return preg_match_all('/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]{3,}/u', $s) >= 2;
}

/**
 * Fragmentos de texto VISIBLE de un archivo.
 *
 * El estado `$enScript` se arrastra entre tokens a propósito: un bloque
 * <script> casi siempre está cortado por etiquetas <?php ?>, así que buscar el
 * par <script>…</script> dentro de un solo token no encuentra nada y el
 * JavaScript terminaba contándose como texto.
 */
function texto_visible(string $src): array {
    $frag = [];
    $enScript = false;

    // Los mapas de alias ($columnMap y parecidos) son claves de busqueda, no
    // texto: estan sin acentos a proposito, porque asi los normaliza el import.
    $src = (string)preg_replace('/\$\w*(?:[Mm]ap|[Aa]lias)\s*=\s*\[.*?\n\];/s', '', $src);

    foreach (@token_get_all($src) as $t) {
        if (!is_array($t)) { continue; }
        [$id, $txt] = $t;

        if ($id === T_INLINE_HTML) {
            // Se recorre el HTML marcando dónde empieza y termina cada script.
            $partes = preg_split('#(<script\b[^>]*>|</script>|<style\b[^>]*>|</style>)#i',
                                 $txt, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            foreach ($partes as $parte) {
                if (preg_match('#^<(script|style)\b#i', $parte)) { $enScript = true; continue; }
                if (preg_match('#^</(script|style)>#i', $parte)) { $enScript = false; continue; }
                if ($enScript) { continue; }

                if (preg_match_all('/(?:placeholder|title|aria-label|alt)\s*=\s*"([^"]*)"/i', $parte, $at)) {
                    foreach ($at[1] as $a) { $frag[] = $a; }
                }
                foreach (preg_split('/\R/', strip_tags($parte)) ?: [] as $l) {
                    $l = trim(html_entity_decode($l, ENT_QUOTES, 'UTF-8'));
                    // Un atributo partido por una etiqueta PHP llega hasta aca
                    // como si fuera texto: " href="?tab=amortizacion">
                    if ($l === '' || preg_match('/\w+\s*=\s*["\']/', $l)) { continue; }
                    $frag[] = $l;
                }
            }
            continue;
        }

        // Las cadenas del código: mensajes y etiquetas armadas en PHP. Los
        // comentarios quedan afuera a propósito: no los lee ningún usuario.
        if (!$enScript && ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE)) {
            $c = trim($txt, "'\"");
            if (es_prosa($c)) { $frag[] = $c; }
        }
    }
    return $frag;
}

/** Los .php que producen pantalla. */
function archivos(): array {
    $out = [];
    foreach (['auth', 'backend', 'dashboard', 'includes', 'inventario'] as $dir) {
        foreach (glob(RAIZ . '/' . $dir . '/*.php') ?: [] as $f) { $out[] = $f; }
        foreach (glob(RAIZ . '/' . $dir . '/*/*.php') ?: [] as $f) { $out[] = $f; }
    }
    sort($out);
    return $out;
}
/**
 * Hallazgos agrupados por archivo: ['archivo' => [[mal, bien, tipo, frase], …]].
 *
 * Es una función y no código suelto para que test/run_tests.php pueda incluir
 * este archivo y preguntar lo mismo, sin repetir las listas en dos lugares.
 */
function ortografia_hallazgos(): array {
    $porArchivo = [];
    foreach (archivos() as $f) {
        $rel = str_replace('\\', '/', substr($f, strlen(RAIZ) + 1));
        foreach (texto_visible((string)file_get_contents($f)) as $frag) {
            preg_match_all('/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]{3,}/u', $frag, $pal);
            foreach ($pal[0] as $p) {
                $min = mb_strtolower($p, 'UTF-8');
                if (isset(ORTO_FALTAS[$min]))       { $porArchivo[$rel][] = [$p, ORTO_FALTAS[$min], 'ortografía', $frag]; }
                elseif (isset(ORTO_TERMINOS[$min])) { $porArchivo[$rel][] = [$p, ORTO_TERMINOS[$min], 'vocabulario', $frag]; }
            }
        }
    }
    return $porArchivo;
}

// Si lo incluye otro archivo, acá termina: sólo quedan definidas las funciones.
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

echo "\n\033[1m  Revisión de ortografía y vocabulario\033[0m\n";
$porArchivo = ortografia_hallazgos();

if ($porArchivo === []) {
    echo "  \033[32m✓\033[0m Ni una falta ni un término fuera de lugar en el texto visible.\n\n";
    exit(0);
}

$total = 0;
foreach ($porArchivo as $arch => $lista) {
    echo "\n  \033[1m{$arch}\033[0m\n";
    $vistos = [];
    foreach ($lista as [$mal, $bien, $tipo, $frag]) {
        $k = $mal . '|' . $bien;
        if (isset($vistos[$k])) { continue; }
        $vistos[$k] = true; $total++;
        $corte = mb_strlen($frag) > 72 ? mb_substr($frag, 0, 72) . '…' : $frag;
        echo "    \033[31m{$mal}\033[0m → \033[32m{$bien}\033[0m  ({$tipo})\n";
        echo "        \033[2m{$corte}\033[0m\n";
    }
}
echo "\n  \033[31m{$total}\033[0m correcciones pendientes\n\n";
exit(1);
