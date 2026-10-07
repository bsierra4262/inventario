<?php
/**
 * La fila de la empresa, leída una vez por sesión y no una vez por pedido.
 *
 * ## Por qué
 *
 * Cada pantalla del portal abría la base de la plataforma dos veces: `TenantDB`
 * para leer a qué base del tenant conectarse, y otra vez el `SELECT nombre FROM
 * companies` del encabezado —el mismo bloque `try { new MasterDB() … } catch
 * (Throwable) {}` copiado en veinte archivos—.
 *
 * El servidor corta a las **18 conexiones simultáneas** del usuario de la
 * master, y al pasarse mysqli devuelve `Operation not permitted` con errno 2002:
 * el error genérico de socket, que no se parece en nada a «hay demasiadas
 * conexiones». Con una ráfaga de dieciocho pedidos en dos segundos el corte
 * llega, los tres reintentos del pedido caen dentro de la misma ventana de
 * castigo y la pantalla termina en «servicio no disponible». Lo vimos en la
 * batería de punta a punta de la papelera, que hace exactamente eso.
 *
 * Reintentar no alcanza porque el límite es de **tasa**, no de instante. Lo que
 * alcanza es no pedir: la fila de una empresa —a qué base conectarse y cómo se
 * llama— no cambia entre dos clics de la misma persona. Guardada en la sesión,
 * una pantalla del tenant abre **una** conexión (la del tenant) en lugar de dos.
 *
 * ## Qué se guarda, y la contrapartida
 *
 * Se guarda la fila entera, credenciales incluidas, porque sin la contraseña no
 * se puede conectar y volver a buscarla es justamente lo que se quiere evitar.
 * Eso pone una contraseña de base de datos en el almacén de sesiones del
 * servidor, y hay que decirlo en lugar de disimularlo. Dos cosas la acotan: la
 * sesión ya guarda el `access_token` de Google —que da acceso al Drive de la
 * persona, bastante más que una base— y el MySQL de producción no es alcanzable
 * desde afuera, así que la credencial sólo sirve desde adentro del servidor,
 * que es donde está el archivo de sesión.
 *
 * El caché **vence a los diez minutos**. Un superadmin que cambie la base de una
 * empresa lo hace desde su propia sesión y no puede tocar la de los demás, así
 * que el vencimiento es lo que hace que el cambio llegue. Diez minutos para algo
 * que pasa una vez por año es un precio razonable; `empresa_cache_olvidar()`
 * está para el código que sí sabe que acaba de cambiar.
 */

declare(strict_types=1);

require_once __DIR__ . '/master_connection.php';

if (!defined('EMPRESA_CACHE_TTL')) {
    define('EMPRESA_CACHE_TTL', 600);
}

if (!function_exists('empresa_fila')) {
/**
 * La fila de `companies`, del caché si está fresca y de la master si no.
 *
 * @return array{id:int,nombre:string,tenant_mode:string,db_host:string,db_name:string,db_user:string,db_pass:string}
 * @throws RuntimeException si la empresa no existe.
 */
function empresa_fila(int $companyId): array
{
    /* Dos niveles. El del proceso sirve igual sin sesión —los scripts de `bin/`
       no tienen una— y evita releer la sesión varias veces en el mismo pedido. */
    static $delPedido = [];
    if (isset($delPedido[$companyId])) { return $delPedido[$companyId]; }

    $guardada = $_SESSION['_empresa'][$companyId] ?? null;
    if (is_array($guardada)
        && isset($guardada['_leida_en'], $guardada['fila'])
        && (time() - (int)$guardada['_leida_en']) < EMPRESA_CACHE_TTL) {
        return $delPedido[$companyId] = $guardada['fila'];
    }

    $mdb  = new MasterDB();
    $stmt = $mdb->pquery(
        'SELECT id, nombre, tenant_mode, db_host, db_name, db_user, db_pass
           FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]
    );
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$fila) {
        platform_log("empresa_fila: empresa {$companyId} no encontrada");
        throw new RuntimeException('Empresa no encontrada en master DB');
    }

    $fila = [
        'id'          => (int)$fila['id'],
        'nombre'      => (string)($fila['nombre'] ?? ''),
        'tenant_mode' => (string)($fila['tenant_mode'] ?? 'external'),
        'db_host'     => (string)($fila['db_host'] ?? ''),
        'db_name'     => (string)($fila['db_name'] ?? ''),
        'db_user'     => (string)($fila['db_user'] ?? ''),
        'db_pass'     => (string)($fila['db_pass'] ?? ''),
    ];

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_empresa'][$companyId] = ['_leida_en' => time(), 'fila' => $fila];
    }
    return $delPedido[$companyId] = $fila;
}
}

if (!function_exists('empresa_nombre')) {
/**
 * El nombre de la empresa activa, para el encabezado.
 *
 * Reemplaza el `try { new MasterDB(); SELECT nombre … } catch (Throwable) {}`
 * que estaba escrito en veinte pantallas. Nunca lanza: si no se puede averiguar,
 * el encabezado dice «Mi empresa» —que es lo que hacía cada copia— y la pantalla
 * se dibuja igual.
 */
function empresa_nombre(?int $companyId = null): string
{
    $id = $companyId ?? (int)($_SESSION['company_id'] ?? 0);
    if ($id <= 0) { return 'Mi empresa'; }
    try {
        $n = trim(empresa_fila($id)['nombre']);
        return $n !== '' ? $n : 'Mi empresa';
    } catch (Throwable) {
        return 'Mi empresa';
    }
}
}

if (!function_exists('empresa_cache_olvidar')) {
/**
 * Tira el caché: de una empresa, o de todas.
 *
 * La llama el código que acaba de cambiar la fila —el alta, la edición y la
 * baja de empresas del portal superadmin— para no esperar los diez minutos.
 */
function empresa_cache_olvidar(?int $companyId = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) { return; }
    if ($companyId === null) { unset($_SESSION['_empresa']); return; }
    unset($_SESSION['_empresa'][$companyId]);
}
}
