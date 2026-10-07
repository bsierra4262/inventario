<?php
/**
 * ¿Qué campos del formulario no le dicen nada a quien los tiene que llenar?
 *
 *   php test/ayudas.php
 *
 * Un campo rotulado «Service pack» y vacío no ayuda: quien no sepa qué es un
 * service pack no lo va a completar, y quien sepa no va a saber en qué formato
 * se espera. Un ejemplo —«Ej: SP1»— contesta las dos cosas de una.
 *
 * La regla: todo campo de texto, número o fecha donde la persona escribe algo
 * tiene que tener `placeholder` con un ejemplo, o un `.ih-field__hint` debajo
 * que lo explique. Los desplegables quedan afuera: ahí las opciones son la
 * ayuda. Las contraseñas también: un ejemplo de contraseña es una mala idea.
 *
 * No es una regla de estilo: es la diferencia entre un formulario que se puede
 * llenar y uno en el que hay que adivinar.
 */

declare(strict_types=1);

defined('RAIZ') || define('RAIZ', dirname(__DIR__));

/** Tipos donde un ejemplo sirve. */
const AYUDA_TIPOS = ['text', 'number', 'url', 'email', 'tel', 'search', 'date'];

/** Campos donde un ejemplo no corresponde o no aporta. */
const AYUDA_EXCEPTUADOS = [
    'password', 'hidden', 'checkbox', 'radio', 'file', 'submit', 'button',
    'csrf_token', 'accion', 'id', 'producto_id', 'target_id', 'page', 'per_page',
];

if (!function_exists('ayudas_campos_sin_ejemplo')) {
/**
 * Campos sin ejemplo, agrupados por archivo.
 *
 * Se mira el bloque que rodea al campo —el `.ih-field` o el `<label>`— porque
 * la ayuda puede estar en un `.ih-field__hint` que viene después del input, no
 * sólo en su propio atributo.
 */
function ayudas_campos_sin_ejemplo(): array {
    $out = [];
    foreach (['inventario', 'backend', 'auth', 'includes'] as $dir) {
        foreach (glob(RAIZ . '/' . $dir . '/*.php') ?: [] as $f) {
            $rel = $dir . '/' . basename($f);
            $src = (string)file_get_contents($f);

            // Fuera los comentarios: un docblock que menciona «<input
            // type="text">» para explicar qué reemplazó este archivo no es un
            // campo de formulario. Se usa el lexer de PHP y no una expresión,
            // por lo mismo que en test/ortografia.php.
            $limpio = '';
            foreach (@token_get_all($src) as $t) {
                if (is_array($t)) {
                    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                        // Se reemplaza por saltos para no mover las posiciones.
                        $limpio .= str_repeat("\n", substr_count($t[1], "\n"));
                        continue;
                    }
                    $limpio .= $t[1];
                } else {
                    $limpio .= $t;
                }
            }
            $src = $limpio;

            // Cada <input> o <textarea> con su contexto: 400 caracteres después,
            // que es donde cabe el hint si lo hay.
            if (!preg_match_all('/<(input|textarea)\b[^>]*>/i', $src, $mm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($mm[0] as $i => [$tag, $pos]) {
                $tipo = 'text';
                if (preg_match('/type\s*=\s*"([^"]+)"/i', $tag, $mt)) { $tipo = strtolower($mt[1]); }
                if (strtolower($mm[1][$i][0]) === 'textarea') { $tipo = 'text'; }

                if (in_array($tipo, AYUDA_EXCEPTUADOS, true)) { continue; }
                if (!in_array($tipo, AYUDA_TIPOS, true)) { continue; }
                // Un `type` armado con PHP no se puede clasificar: se omite.
                if (str_contains($tag, '<?')) { continue; }

                $name = '';
                if (preg_match('/name\s*=\s*"([^"]+)"/i', $tag, $mn)) { $name = $mn[1]; }
                if ($name !== '' && in_array($name, AYUDA_EXCEPTUADOS, true)) { continue; }
                // Los campos de búsqueda ya se explican solos por su lupa.
                if (str_contains($tag, 'placeholder=')) { continue; }

                // ¿Hay un hint cerca? El bloque del campo termina en el próximo
                // `</div>` que cierra el `.ih-field`, así que alcanza con mirar
                // un trecho corto después.
                $cerca = substr($src, $pos, 400);
                if (str_contains($cerca, 'ih-field__hint')) { continue; }
                if (str_contains($cerca, 'ih-form-hint')) { continue; }

                $out[$rel][] = ($name !== '' ? $name : $tipo) . ' (' . $tipo . ')';
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

echo "\n\033[1m  Campos sin un ejemplo que guíe a quien los llena\033[0m\n";
$hallazgos = ayudas_campos_sin_ejemplo();

if ($hallazgos === []) {
    echo "  \033[32m✓\033[0m Todos los campos tienen ejemplo o explicación.\n\n";
    exit(0);
}

$total = 0;
foreach ($hallazgos as $arch => $campos) {
    $campos = array_values(array_unique($campos));
    $total += count($campos);
    echo "\n  \033[1m{$arch}\033[0m  (" . count($campos) . ")\n";
    foreach (array_chunk($campos, 4) as $fila) {
        echo '      ' . implode('   ', $fila) . "\n";
    }
}
echo "\n  \033[31m{$total}\033[0m campos sin ejemplo\n\n";
exit(1);
