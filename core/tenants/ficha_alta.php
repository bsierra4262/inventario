<?php
/**
 * Guardar la ficha técnica entera en el mismo paso que el alta.
 *
 * Antes el alta creaba el activo y redirigía a la ficha para que ahí se
 * cargaran los componentes, la red, las relaciones, los contratos, los
 * documentos y las notas. Eran dos pantallas y ocho formularios separados para
 * dar de alta UNA computadora, y cada uno con su propio envío: cargabas un
 * disco, se recargaba la página, cargabas la memoria, se recargaba otra vez.
 *
 * Ahora todo eso viaja en el mismo POST del alta y se guarda acá, con el activo
 * ya creado y dentro de la misma transacción.
 *
 * ## La forma de los datos
 *
 * Los campos repetibles llegan como arreglos paralelos, que es lo que manda un
 * formulario con filas: `componente_tipo[]`, `componente_designacion[]`,
 * `componente_cantidad[]`. La fila `i` de cada uno es el mismo componente. Las
 * filas vacías se descartan: el formulario arranca con una fila en blanco y
 * nadie tiene por qué borrarla si no la usó.
 *
 * ## Qué pasa si una fila está mal
 *
 * Se saltea y se sigue. Se devuelve el resumen de lo que entró y de lo que no,
 * y la pantalla lo muestra. La alternativa —abortar el alta entera porque una
 * MAC estaba mal escrita— pierde los quince campos que sí estaban bien.
 */

declare(strict_types=1);

require_once __DIR__ . '/componentes.php';
require_once __DIR__ . '/red.php';
require_once __DIR__ . '/relaciones.php';
require_once __DIR__ . '/contratos.php';
require_once __DIR__ . '/documentos.php';

if (!function_exists('ficha_alta_guardar')) {
/**
 * Guarda todo lo que el alta haya traído de la ficha técnica.
 *
 * @return array{guardado: array<string,int>, avisos: string[]}
 */
function ficha_alta_guardar(mysqli $conn, int $productoId, array $post, string $autor = ''): array
{
    $guardado = ['componentes' => 0, 'puertos' => 0, 'ips' => 0,
                 'relaciones' => 0, 'contratos' => 0, 'documentos' => 0, 'notas' => 0];
    $avisos = [];

    /** Las filas de un repetidor, ya alineadas y sin las vacías. */
    $filas = function (array $campos) use ($post): array {
        $largo = 0;
        foreach ($campos as $c) {
            if (isset($post[$c]) && is_array($post[$c])) { $largo = max($largo, count($post[$c])); }
        }
        $out = [];
        for ($i = 0; $i < $largo; $i++) {
            $fila = [];
            $vacia = true;
            foreach ($campos as $c) {
                $v = trim((string)($post[$c][$i] ?? ''));
                $fila[$c] = $v;
                if ($v !== '') { $vacia = false; }
            }
            if (!$vacia) { $out[] = $fila; }
        }
        return $out;
    };

    // ── Componentes ─────────────────────────────────────────────────────────
    if (componentes_disponible($conn)) {
        foreach ($filas(['componente_tipo', 'componente_designacion', 'componente_fabricante',
                         'componente_cantidad', 'componente_capacidad', 'componente_serie']) as $f) {
            if ($f['componente_designacion'] === '') {
                $avisos[] = 'Un componente quedó sin nombre y no se guardó.';
                continue;
            }
            $tipo = $f['componente_tipo'] !== '' ? $f['componente_tipo'] : 'otro';
            if (!componente_tipo_valido($tipo)) {
                $avisos[] = 'El componente «' . $f['componente_designacion'] . '» tenía un tipo desconocido.';
                continue;
            }
            // El catálogo es compartido: si ya existe se reusa, si no se da de alta.
            $compId = componente_alta($conn, $tipo, $f['componente_designacion'], $f['componente_fabricante']);
            if ($compId <= 0) {
                $avisos[] = 'No se pudo guardar el componente «' . $f['componente_designacion'] . '».';
                continue;
            }
            $datos = ['cantidad' => max(1, (int)($f['componente_cantidad'] ?: 1))];
            if ($f['componente_capacidad'] !== '') { $datos['capacidad_mb'] = (int)$f['componente_capacidad']; }
            if ($f['componente_serie'] !== '')     { $datos['numero_serie'] = $f['componente_serie']; }
            if (componente_asignar($conn, $productoId, $compId, $datos) > 0) { $guardado['componentes']++; }
        }
    }

    // ── Red: un puerto por fila, con su dirección principal ─────────────────
    if (red_disponible($conn)) {
        foreach ($filas(['puerto_nombre', 'puerto_tipo', 'puerto_mac', 'puerto_velocidad',
                         'puerto_ip', 'puerto_mascara', 'puerto_gateway']) as $f) {
            $datos = [
                'nombre' => $f['puerto_nombre'] !== '' ? $f['puerto_nombre'] : 'Puerto',
                'tipo'   => $f['puerto_tipo'] !== '' ? $f['puerto_tipo'] : 'ethernet',
            ];
            if ($f['puerto_mac'] !== '')       { $datos['mac'] = $f['puerto_mac']; }
            if ($f['puerto_velocidad'] !== '') { $datos['velocidad_mbps'] = (int)$f['puerto_velocidad']; }

            $puertoId = red_puerto_guardar($conn, $productoId, 0, $datos);
            if ($puertoId <= 0) {
                $avisos[] = 'No se pudo guardar el puerto «' . $datos['nombre'] . '».';
                continue;
            }
            $guardado['puertos']++;

            if ($f['puerto_ip'] !== '') {
                $ip = ['ip' => $f['puerto_ip']];
                if ($f['puerto_mascara'] !== '') { $ip['mascara'] = $f['puerto_mascara']; }
                if ($f['puerto_gateway'] !== '') { $ip['gateway'] = $f['puerto_gateway']; }
                if (red_ip_guardar($conn, $puertoId, $ip) > 0) { $guardado['ips']++; }
                else { $avisos[] = 'La dirección «' . $f['puerto_ip'] . '» no se pudo guardar.'; }
            }
        }
    }

    // ── Relaciones con otros activos ────────────────────────────────────────
    if (relaciones_disponible($conn)) {
        foreach ($filas(['relacion_tipo', 'relacion_destino', 'relacion_nota']) as $f) {
            $destino = (int)$f['relacion_destino'];
            if ($destino <= 0) { continue; }
            $r = relacion_crear($conn, $productoId, $destino,
                                $f['relacion_tipo'] !== '' ? $f['relacion_tipo'] : 'conectado',
                                $f['relacion_nota']);
            if ($r['ok'] ?? false) { $guardado['relaciones']++; }
            else { $avisos[] = 'Una relación no se pudo crear: ' . ($r['error'] ?? 'motivo desconocido'); }
        }
    }

    // ── Contratos y documentos: se eligen de los que ya existen ─────────────
    if (contratos_disponible($conn)) {
        foreach ((array)($post['contrato_id'] ?? []) as $cid) {
            $cid = (int)$cid;
            if ($cid > 0 && contrato_vincular($conn, $cid, $productoId)) { $guardado['contratos']++; }
        }
    }
    if (documentos_disponible($conn)) {
        foreach ((array)($post['documento_id'] ?? []) as $did) {
            $did = (int)$did;
            if ($did > 0 && documento_vincular($conn, $did, $productoId)) { $guardado['documentos']++; }
        }
    }

    // ── La nota inicial ─────────────────────────────────────────────────────
    $nota = trim((string)($post['nota_inicial'] ?? ''));
    if ($nota !== '' && documentos_disponible($conn)) {
        if (nota_agregar($conn, $productoId, $nota, $autor) > 0) { $guardado['notas']++; }
    }

    return ['guardado' => $guardado, 'avisos' => $avisos];
}
}

if (!function_exists('ficha_alta_resumen')) {
/**
 * Lo guardado, en una frase para el aviso de arriba.
 *
 * Se dice lo que entró y no «se guardó todo»: si alguien cargó tres discos y
 * entraron dos, el número lo delata y puede volver a mirar.
 */
function ficha_alta_resumen(array $guardado): string
{
    $partes = [];
    $nombres = [
        'componentes' => ['componente', 'componentes'],
        'puertos'     => ['puerto de red', 'puertos de red'],
        'ips'         => ['dirección IP', 'direcciones IP'],
        'relaciones'  => ['relación', 'relaciones'],
        'contratos'   => ['contrato', 'contratos'],
        'documentos'  => ['documento', 'documentos'],
        'notas'       => ['nota', 'notas'],
    ];
    foreach ($nombres as $k => [$uno, $varios]) {
        $n = (int)($guardado[$k] ?? 0);
        if ($n > 0) { $partes[] = $n . ' ' . ($n === 1 ? $uno : $varios); }
    }
    if ($partes === []) { return ''; }
    if (count($partes) === 1) { return 'Se guardó ' . $partes[0] . '.'; }
    $ultima = array_pop($partes);
    return 'Se guardaron ' . implode(', ', $partes) . ' y ' . $ultima . '.';
}
}
