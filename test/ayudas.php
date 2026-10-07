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
/* `dashboard` entró recién ahora: el panel superadmin nunca se miraba. Es el
   mismo agujero que tenían el auditor de colores y el de estilos de pantalla —
   cuatro carpetas de cinco— y por eso daban verde sin cuidar nada ahí. */
function ayudas_campos_sin_ejemplo(array $dirs = ['inventario', 'backend', 'auth', 'includes', 'dashboard']): array {
    $out = [];
    foreach ($dirs as $dir) {
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
            // Y fuera lo que hay dentro de <script> y <style>. El lexer de PHP no
            // los ve como comentarios —para él son HTML suelto—, así que un
            // comentario de JavaScript que menciona «<input>» entraba como si
            // fuera un campo de formulario sin ejemplo. Pasó en importar.php.
            $limpio = (string)preg_replace('#<script[^>]*>.*?</script>#is', '', $limpio);
            $limpio = (string)preg_replace('#<style[^>]*>.*?</style>#is', '', $limpio);

            $src = $limpio;

            /* Cada <input> o <textarea> con su contexto: 400 caracteres después,
               que es donde cabe el hint si lo hay.

               El `(?:<\?.*?\?>|[^>])*` es lo que permite que la etiqueta pase por
               arriba de un bloque PHP. Con el `[^>]*` de antes, la captura se
               cortaba en el `?>` del primer `value="<?= … ?>"` — que es el primer
               atributo de casi cualquier campo— y todo lo que venía después,
               incluido el `placeholder`, quedaba afuera. El buscador del listado
               de activos aparecía como «sin ejemplo» teniendo uno. */
            if (!preg_match_all('/<(input|textarea)\b(?:<\?.*?\?>|[^>])*>/is', $src, $mm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($mm[0] as $i => [$tag, $pos]) {
                $tipo = 'text';
                if (preg_match('/type\s*=\s*"([^"]+)"/i', $tag, $mt)) { $tipo = strtolower($mt[1]); }
                if (strtolower($mm[1][$i][0]) === 'textarea') { $tipo = 'text'; }

                if (in_array($tipo, AYUDA_EXCEPTUADOS, true)) { continue; }
                if (!in_array($tipo, AYUDA_TIPOS, true)) { continue; }

                /* Sólo se omite cuando el TYPE está armado con PHP, porque ahí
                   no se sabe qué clase de campo es.

                   Antes decía `str_contains($tag, '<?')`, que descarta la
                   etiqueta entera si tiene un `<?php` en CUALQUIER atributo — y
                   casi todo campo real trae `value="<?= … ?>"`. O sea: la prueba
                   se estaba salteando la mayoría de los campos del proyecto y
                   daba verde. Los diecinueve filtros de reportes no tenían ni un
                   ejemplo y nadie se enteró. */
                if (preg_match('/type\s*=\s*"[^"]*<\?/i', $tag)) { continue; }

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
