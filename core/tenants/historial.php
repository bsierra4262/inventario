<?php
/**
 * Qué le pasó a un activo, contado como una historia y no como filas sueltas.
 *
 * `producto_movimientos` guarda un renglón por hecho: «Agregado», «Archivado»,
 * «Restaurado», «Eliminado». Eso está bien para guardar y mal para leer. Un
 * activo que se archivó por error y se restauró cinco minutos después dejaba
 * dos renglones, y los dos reportes que los mostraban —Archivados y
 * Trazabilidad— sólo miraban el primero: el activo figuraba archivado para
 * siempre aunque estuviera de vuelta en el inventario. Tres archivados y tres
 * restauraciones del mismo equipo se veían como tres equipos archivados.
 *
 * Acá se arma el par: a cada «Archivado» se le busca **qué pasó después**, y el
 * par se cuenta en un renglón solo —«Archivado el 15/09, volvió el 07/10, 22
 * días en la papelera»—. El dato crudo no se toca: la base sigue teniendo los
 * dos hechos, porque es el único rastro que queda si mañana hay que revisar qué
 * pasó con un bien. Lo que cambia es cómo se lee.
 *
 * Vive en un módulo y no dentro de `reportes.php` porque lo usan tres
 * pantallas: las dos pestañas y el detalle del activo. Escrito tres veces,
 * serían tres criterios distintos para la misma pregunta.
 */

declare(strict_types=1);

if (!function_exists('historial_despues_sql')) {
/**
 * El pedazo de SQL que, para cada movimiento, trae el que vino después.
 *
 * Es una subconsulta correlacionada y no un JOIN a propósito: lo que se quiere
 * es **el primero** posterior, y con un JOIN habría que desempatar después en
 * PHP y paginar sobre filas repetidas.
 *
 * @param string $movTable Nombre ya resuelto de `producto_movimientos`.
 */
function historial_despues_sql(string $movTable): string
{
    return "
      (SELECT m2.accion FROM `{$movTable}` m2
        WHERE m2.producto_id = pm.producto_id
          AND m2.fecha > pm.fecha
          AND m2.accion IN ('Restaurado','Eliminado')
        ORDER BY m2.fecha ASC LIMIT 1) AS despues_accion,
      (SELECT m2.fecha FROM `{$movTable}` m2
        WHERE m2.producto_id = pm.producto_id
          AND m2.fecha > pm.fecha
          AND m2.accion IN ('Restaurado','Eliminado')
        ORDER BY m2.fecha ASC LIMIT 1) AS despues_fecha";
}
}

if (!function_exists('historial_sin_restauraciones_sql')) {
/**
 * La condición que deja afuera las restauraciones que ya se cuentan en su
 * archivado.
 *
 * Sin esto, agrupar daría el resultado contrario al buscado: el ciclo se vería
 * una vez como «Archivado → Restaurado» y otra vez como «Restaurado» suelto.
 */
function historial_sin_restauraciones_sql(string $movTable): string
{
    return "NOT (pm.accion = 'Restaurado' AND EXISTS (
        SELECT 1 FROM `{$movTable}` m0
         WHERE m0.producto_id = pm.producto_id
           AND m0.accion = 'Archivado'
           AND m0.fecha < pm.fecha))";
}
}

if (!function_exists('historial_motivo')) {
/** El motivo del archivado, que viaja dentro del texto de la descripción. */
function historial_motivo(?string $descripcion): string
{
    if (!$descripcion) { return ''; }
    if (preg_match('/Motivo:\s*([^|·]+)/u', $descripcion, $m)) { return trim($m[1]); }
    return '';
}
}

if (!function_exists('historial_cuanto')) {
/**
 * Cuánto pasó entre dos fechas, dicho como lo diría una persona.
 *
 * «0 días» no es una respuesta: si se archivó y se restauró en la misma mañana,
 * lo que hay que decir es «el mismo día», porque eso es lo que significa —fue
 * una corrección, no un período—.
 */
function historial_cuanto(string $desde, string $hasta): string
{
    $a = strtotime($desde);
    $b = strtotime($hasta);
    if (!$a || !$b || $b < $a) { return ''; }
    if (date('Y-m-d', $a) === date('Y-m-d', $b)) { return 'el mismo día'; }
    $dias = (int)floor(($b - $a) / 86400);
    if ($dias <= 1)  { return 'al día siguiente'; }
    if ($dias < 31)  { return "{$dias} días después"; }
    $meses = (int)round($dias / 30.44);
    if ($meses < 24) { return $meses === 1 ? 'un mes después' : "{$meses} meses después"; }
    $anios = (int)round($dias / 365.25);
    return "{$anios} años después";
}
}

if (!function_exists('historial_que_paso')) {
/**
 * Qué decir de un movimiento, ya emparejado con lo que vino después.
 *
 * @param array $f Fila con `accion`, `fecha`, `descripcion` y, si se usó
 *                 `historial_despues_sql()`, `despues_accion` y `despues_fecha`.
 * @return array{titulo:string,detalle:string,badge:string,icono:string,abierto:bool}
 *         `abierto` es «esto todavía está pasando»: sigue en la papelera.
 */
function historial_que_paso(array $f): array
{
    $accion  = trim((string)($f['accion'] ?? ''));
    $fecha   = (string)($f['fecha'] ?? '');
    $motivo  = historial_motivo((string)($f['descripcion'] ?? ''));
    $dAccion = trim((string)($f['despues_accion'] ?? ''));
    $dFecha  = (string)($f['despues_fecha'] ?? '');

    if ($accion === 'Archivado') {
        if ($dAccion === 'Restaurado') {
            $cuando = historial_cuanto($fecha, $dFecha);
            return [
                'titulo'  => 'Archivado y restaurado',
                'detalle' => trim(($motivo !== '' ? "Se archivó por «{$motivo}»" : 'Se archivó')
                           . ($cuando !== '' ? " y volvió al inventario {$cuando}" : ' y volvió al inventario')),
                'badge'   => 'ih-badge--neutral',
                'icono'   => 'arrow-counterclockwise',
                'abierto' => false,
            ];
        }
        if ($dAccion === 'Eliminado') {
            $cuando = historial_cuanto($fecha, $dFecha);
            return [
                'titulo'  => 'Archivado y eliminado',
                'detalle' => trim(($motivo !== '' ? "Se archivó por «{$motivo}»" : 'Se archivó')
                           . ($cuando !== '' ? " y se eliminó {$cuando}" : ' y después se eliminó')),
                'badge'   => 'ih-badge--danger',
                'icono'   => 'trash3',
                'abierto' => false,
            ];
        }
        return [
            'titulo'  => 'Archivado',
            'detalle' => trim(($motivo !== '' ? "Motivo: {$motivo}. " : '') . 'Sigue en la papelera'),
            'badge'   => 'ih-badge--warning',
            'icono'   => 'archive',
            'abierto' => true,
        ];
    }

    return match ($accion) {
        'Agregado'   => ['titulo' => 'Agregado al inventario', 'detalle' => '',
                         'badge' => 'ih-badge--success', 'icono' => 'plus-circle', 'abierto' => false],
        'Restaurado' => ['titulo' => 'Restaurado', 'detalle' => 'Volvió al inventario desde la papelera',
                         'badge' => 'ih-badge--neutral', 'icono' => 'arrow-counterclockwise', 'abierto' => false],
        'Eliminado'  => ['titulo' => 'Eliminado definitivamente', 'detalle' => 'El activo ya no existe; queda este registro',
                         'badge' => 'ih-badge--danger', 'icono' => 'trash3', 'abierto' => false],
        default      => ['titulo' => $accion !== '' ? $accion : 'Movimiento', 'detalle' => '',
                         'badge' => 'ih-badge--neutral', 'icono' => 'dot', 'abierto' => false],
    };
}
}

if (!function_exists('historial_autor')) {
/**
 * Quién lo hizo.
 *
 * `realizado_por` es VARCHAR y guardó dos cosas distintas según el endpoint: el
 * ID del usuario en unos, el correo en otros. Los endpoints ya guardan todos el
 * ID, pero las filas viejas siguen teniendo el correo y son justamente las de
 * las restauraciones: si acá se exigiera un número, el historial que se arregló
 * volvería a verse vacío.
 *
 * @param array $f Fila con `realizado_por` y, si el JOIN encontró al usuario,
 *                 `username` y `email`.
 */
function historial_autor(array $f): array
{
    $usuario = trim((string)($f['username'] ?? ''));
    $correo  = trim((string)($f['email'] ?? ''));
    if ($usuario !== '') { return ['nombre' => $usuario, 'detalle' => $correo]; }

    $crudo = trim((string)($f['realizado_por'] ?? ''));
    if ($crudo === '' || $crudo === '0') { return ['nombre' => 'El sistema', 'detalle' => '']; }
    if (ctype_digit($crudo)) { return ['nombre' => 'Usuario #' . $crudo, 'detalle' => 'ya no está en la empresa']; }
    return ['nombre' => $crudo, 'detalle' => ''];
}
}

if (!function_exists('historial_de_activo')) {
/**
 * El historial de UN activo, ya emparejado y del más nuevo al más viejo.
 *
 * Lo usa el detalle del activo. Devuelve `[]` y no explota si la tabla todavía
 * no existe: un tenant sin migrar tiene que poder ver la pantalla igual.
 */
function historial_de_activo(mysqli $conn, int $productoId, callable $tabla, int $limite = 12): array
{
    $mov   = $tabla('producto_movimientos');
    $users = $tabla('users');
    $sql = "SELECT pm.fecha, pm.accion, pm.descripcion, pm.realizado_por,
                   u.username, u.email,"
         . historial_despues_sql($mov) . "
              FROM `{$mov}` pm
              LEFT JOIN `{$users}` u
                     ON pm.realizado_por REGEXP '^[0-9]+$' AND CAST(pm.realizado_por AS UNSIGNED) = u.id
             WHERE pm.producto_id = ?
               AND " . historial_sin_restauraciones_sql($mov) . "
             ORDER BY pm.fecha DESC, pm.id DESC
             LIMIT {$limite}";
    try {
        $st = $conn->prepare($sql);
        $st->bind_param('i', $productoId);
        $st->execute();
        $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $filas;
    } catch (Throwable) {
        return [];
    }
}
}
