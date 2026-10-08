<?php
/**
 * Conexión a la base de la plataforma (el plano master: empresas, superadmins).
 *
 * **Una sola conexión por request, compartida.** `new MasterDB()` aparece en 46
 * archivos y antes cada uno abría su propia conexión TCP. Una pantalla cualquiera
 * del portal abría tres: la que arma `TenantDB` para leer los datos de la
 * empresa, la de la base del tenant, y otra más para el nombre de la empresa que
 * va en el encabezado. Las que miran permisos de plataforma abrían una cuarta.
 *
 * En hosting compartido eso se paga. Hostinger limita las conexiones
 * simultáneas por usuario, y cuando el límite se toca `mysqli` no devuelve
 * «demasiadas conexiones» sino **`Operation not permitted`** — que no se parece
 * en nada a lo que pasó. Esa excepción la agarra `backend/db.php`, que la
 * interpreta como «el entorno no está preparado» y redirige a `/install.php`:
 * una persona con la sesión abierta, en medio de la papelera, terminaba en el
 * instalador porque el servidor estaba ocupado un instante.
 *
 * Apareció con las pruebas de punta a punta, que hacen treinta pedidos en dos
 * segundos: fallaba uno distinto en cada corrida y el síntoma era siempre otro
 * —un 419 acá, un «el activo sigue ahí» allá—. Nunca el mismo, porque la causa
 * no era ninguno de los dos.
 *
 * Compartir es seguro: es siempre el mismo host, usuario y base —las constantes
 * de `platform.php`, no hay un segundo juego de credenciales— y nadie cierra la
 * conexión ni la deja con una transacción abierta. Sólo se guarda si conectó
 * bien, así que un error no queda cacheado y el pedido siguiente vuelve a
 * intentar.
 */

require_once __DIR__ . '/../config/platform.php';

if (!class_exists('MasterDB')) {
class MasterDB {
    public mysqli $conn;

    /** La conexión del request. Se arma la primera vez y se reusa. */
    private static ?mysqli $compartida = null;

    public function __construct(){
        if (self::$compartida instanceof mysqli) {
            $this->conn = self::$compartida;
            return;
        }
        /* `mysqli` lanza una excepción al no poder conectar —es el comportamiento
           por omisión desde PHP 8.1— y la arroba NO la suprime. Así que el `if
           (connect_errno)` que había acá nunca se ejecutaba: el mensaje salía
           pelado («Operation not permitted», sin más) desde el otro extremo y no
           se podía saber si lo que había fallado era la master o la base del
           tenant, que son dos conexiones distintas a dos bases distintas con
           credenciales distintas. Media hora de leer logs para eso. */
        /* Se reintenta, con una espera corta y creciente. El límite de
           conexiones simultáneas se toca y se suelta en milisegundos: el
           pedido de al lado está cerrando la suya justo ahora. Reintentar en
           el mismo instante encuentra el mismo socket ocupado y falla igual,
           así que la espera es la parte que importa.

           Es la misma idea que ya tenía `backend/db.php` alrededor del
           arranque del tenant; acá adentro sirve a los DOS portales, que es
           donde se notó que faltaba: el panel devolvía un 500 con el cuerpo
           vacío mientras el portal del cliente seguía andando. */
        $ultimo = null;
        for ($intento = 1; $intento <= 3; $intento++) {
            try {
                $this->conn = new mysqli(MASTER_DB_HOST, MASTER_DB_USER, MASTER_DB_PASS, MASTER_DB_NAME);
                $ultimo = null;
                break;
            } catch (Throwable $e) {
                $ultimo = $e;
                if ($intento < 3) { usleep($intento * 30000); }
            }
        }
        if ($ultimo !== null) {
            platform_log('ERROR DB MASTER (' . $ultimo->getCode() . ') tras 3 intentos: ' . $ultimo->getMessage());
            throw new RuntimeException('No se puede conectar a la DB master [' . $ultimo->getCode() . ']: '
                . $ultimo->getMessage(), (int)$ultimo->getCode(), $ultimo);
        }
        $this->conn->set_charset('utf8mb4');
        self::$compartida = $this->conn;
    }

    /**
     * Suelta la conexión compartida: la próxima vez se arma de nuevo.
     *
     * La llama `backend/db.php` entre los dos intentos del arranque. Sin esto,
     * reintentar sería inútil: si lo que falló fue esta conexión, el segundo
     * intento la encontraría guardada y volvería a usar la que no sirve.
     */
    public static function olvidar(): void {
        self::$compartida = null;
    }

    public function pquery(string $sql, string $types = '', array $params = []): mysqli_stmt {
        $stmt = $this->conn->prepare($sql);
        if(!$stmt){ throw new Exception('Prepare failed: '.$this->conn->error); }
        if($types && $params){
            $stmt->bind_param($types, ...$params);
        }
        if(!$stmt->execute()){
            throw new Exception('Exec failed: '.$stmt->error);
        }
        return $stmt;
    }
}
}
