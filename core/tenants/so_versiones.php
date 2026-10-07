<?php
/**
 * Qué versiones y qué ediciones tiene cada sistema operativo.
 *
 * El problema que resuelve: «Versión» y «Edición» eran desplegables vacíos, con
 * el cartel «La lista está vacía. Cargá los valores en Listas desplegables». O
 * sea, para anotar que una máquina tiene Windows 11 24H2 Pro había que ir a otra
 * pantalla, dar de alta «24H2», dar de alta «Pro», y recién volver. Nadie hace
 * eso: escribe cualquier cosa o deja el campo en blanco, y la pregunta que
 * motivó todo el módulo —cuántas máquinas siguen en una versión sin soporte—
 * vuelve a no poder contestarse.
 *
 * Ahora cada sistema trae su lista. Elegís «Windows 11» y la lista de versiones
 * pasa a ser la de Windows 11.
 *
 * ## Por qué en el código y no en la base
 *
 * Porque no es un dato de la empresa: que Windows 11 tenga 24H2 no depende de
 * qué inventario sea. Es lo mismo que `RED_TIPOS_PUERTO`. Ponerlo en `catalogos`
 * obligaría a sembrar mil filas en cada empresa nueva y a migrar cada vez que
 * sale una versión.
 *
 * ## Lo que la empresa agrega igual se usa
 *
 * `so_versiones_de()` devuelve esta lista MÁS lo que el tenant haya cargado en
 * `catalogos` con tipo `so_version`. El botón `+` del campo sigue funcionando y
 * lo que se agrega por ahí queda disponible para siempre. Eso cubre dos casos:
 * una versión más nueva que esta lista, y una distribución interna que sólo
 * existe en esa empresa.
 *
 * ## Hasta dónde llega
 *
 * Hasta lo que se puede afirmar. Una lista de versiones inventadas es peor que
 * una lista corta: alguien elige «26H2» de un desplegable, lo da por bueno, y
 * queda un inventario con un dato que no existe. Lo que falte se agrega con el
 * `+` en el momento, sin salir de la pantalla.
 *
 * El orden es **de la más nueva a la más vieja**: lo que se carga hoy es casi
 * siempre lo último, y tenerlo primero evita recorrer veinte opciones.
 */

declare(strict_types=1);

if (!defined('SO_CATALOGO')) {
    /**
     * sistema => ['versiones' => [...], 'ediciones' => [...]]
     *
     * Un sistema que no está acá simplemente no filtra: muestra lo que la
     * empresa haya cargado, y nada más.
     */
    define('SO_CATALOGO', [

        // ── Windows de escritorio ───────────────────────────────────────────
        'Windows 11' => [
            'versiones' => ['25H2', '24H2', '23H2', '22H2', '21H2'],
            'ediciones' => ['Home', 'Home Single Language', 'Pro', 'Pro Education',
                            'Pro for Workstations', 'Education', 'Enterprise',
                            'Enterprise LTSC', 'IoT Enterprise'],
        ],
        'Windows 10' => [
            'versiones' => ['22H2', '21H2', '21H1', '20H2', '2004', '1909', '1903',
                            '1809', '1803', '1709', '1703', '1607', '1511', '1507'],
            'ediciones' => ['Home', 'Home Single Language', 'Pro', 'Pro Education',
                            'Pro for Workstations', 'Education', 'Enterprise',
                            'Enterprise LTSC', 'IoT Enterprise'],
        ],
        'Windows 8.1' => [
            'versiones' => ['Update 1', 'Original'],
            'ediciones' => ['Core', 'Pro', 'Enterprise'],
        ],
        'Windows 7' => [
            'versiones' => ['SP1', 'Original'],
            'ediciones' => ['Starter', 'Home Basic', 'Home Premium', 'Professional',
                            'Enterprise', 'Ultimate'],
        ],

        // ── Windows Server ──────────────────────────────────────────────────
        //  Acá el año ES la versión del producto, así que lo que varía es la
        //  edición y, en los que lo tienen, el nivel de actualización.
        'Windows Server 2025' => [
            'versiones' => [],
            'ediciones' => ['Standard', 'Datacenter', 'Datacenter: Azure Edition'],
        ],
        'Windows Server 2022' => [
            'versiones' => [],
            'ediciones' => ['Standard', 'Datacenter', 'Datacenter: Azure Edition'],
        ],
        'Windows Server 2019' => [
            'versiones' => [],
            'ediciones' => ['Standard', 'Datacenter', 'Essentials'],
        ],
        'Windows Server 2016' => [
            'versiones' => [],
            'ediciones' => ['Standard', 'Datacenter', 'Essentials'],
        ],
        'Windows Server 2012 R2' => [
            'versiones' => [],
            'ediciones' => ['Foundation', 'Essentials', 'Standard', 'Datacenter'],
        ],

        // ── Linux ───────────────────────────────────────────────────────────
        //  En Ubuntu la versión ES el número de release; las LTS se marcan
        //  porque es lo que decide si la máquina tiene soporte o no.
        'Ubuntu' => [
            'versiones' => ['25.04', '24.10', '24.04 LTS', '23.10', '23.04',
                            '22.10', '22.04 LTS', '20.04 LTS', '18.04 LTS', '16.04 LTS'],
            'ediciones' => ['Desktop', 'Server', 'Core'],
        ],
        'Ubuntu Server' => [
            'versiones' => ['25.04', '24.10', '24.04 LTS', '23.10', '23.04',
                            '22.10', '22.04 LTS', '20.04 LTS', '18.04 LTS', '16.04 LTS'],
            'ediciones' => [],
        ],
        'Debian' => [
            'versiones' => ['13 (Trixie)', '12 (Bookworm)', '11 (Bullseye)',
                            '10 (Buster)', '9 (Stretch)'],
            'ediciones' => [],
        ],
        'Linux Mint' => [
            'versiones' => ['22', '21.3', '21.2', '21.1', '21', '20.3'],
            'ediciones' => ['Cinnamon', 'MATE', 'Xfce'],
        ],
        'Red Hat Enterprise Linux' => [
            'versiones' => ['10', '9', '8', '7'],
            'ediciones' => ['Server', 'Workstation'],
        ],
        'Rocky Linux' => [
            'versiones' => ['10', '9', '8'],
            'ediciones' => [],
        ],
        'AlmaLinux' => [
            'versiones' => ['10', '9', '8'],
            'ediciones' => [],
        ],
        'CentOS' => [
            'versiones' => ['Stream 10', 'Stream 9', 'Stream 8', '8', '7'],
            'ediciones' => [],
        ],
        'Oracle Linux' => [
            'versiones' => ['9', '8', '7'],
            'ediciones' => [],
        ],
        'SUSE Linux Enterprise' => [
            'versiones' => ['15 SP6', '15 SP5', '15 SP4', '15 SP3', '12 SP5'],
            'ediciones' => ['Server', 'Desktop'],
        ],
        'openSUSE' => [
            'versiones' => ['Tumbleweed', 'Leap 15.6', 'Leap 15.5', 'Leap 15.4'],
            'ediciones' => [],
        ],
        'Fedora' => [
            'versiones' => ['42', '41', '40', '39', '38'],
            'ediciones' => ['Workstation', 'Server', 'IoT', 'CoreOS'],
        ],
        'Arch Linux' => [
            // Arch es rolling: no tiene versiones, se actualiza y listo.
            'versiones' => ['Rolling'],
            'ediciones' => [],
        ],
        'Alpine Linux' => [
            'versiones' => ['3.21', '3.20', '3.19', '3.18'],
            'ediciones' => [],
        ],
        'Raspberry Pi OS' => [
            'versiones' => ['Bookworm', 'Bullseye', 'Buster'],
            'ediciones' => ['Desktop', 'Lite', 'Full'],
        ],

        // ── Apple ───────────────────────────────────────────────────────────
        //  Desde 2025 Apple numera por año: macOS 26 viene después de macOS 15.
        'macOS' => [
            'versiones' => ['26 (Tahoe)', '15 (Sequoia)', '14 (Sonoma)', '13 (Ventura)',
                            '12 (Monterey)', '11 (Big Sur)', '10.15 (Catalina)',
                            '10.14 (Mojave)'],
            'ediciones' => [],
        ],
        'iOS' => [
            'versiones' => ['26', '18', '17', '16', '15', '14'],
            'ediciones' => [],
        ],
        'iPadOS' => [
            'versiones' => ['26', '18', '17', '16', '15'],
            'ediciones' => [],
        ],

        // ── Móviles y livianos ──────────────────────────────────────────────
        'Android' => [
            'versiones' => ['16', '15', '14', '13', '12', '11', '10'],
            'ediciones' => [],
        ],
        'ChromeOS' => [
            // Canal, no versión: el número cambia cada cuatro semanas solo.
            'versiones' => [],
            'ediciones' => ['Stable', 'Beta', 'Dev', 'LTS'],
        ],

        // ── BSD y appliances de red ─────────────────────────────────────────
        'FreeBSD'  => ['versiones' => ['14', '13', '12'], 'ediciones' => []],
        'OpenBSD'  => ['versiones' => ['7.6', '7.5', '7.4', '7.3'], 'ediciones' => []],
        'pfSense'  => ['versiones' => ['2.7', '2.6', '2.5'], 'ediciones' => ['CE', 'Plus']],
        'OPNsense' => ['versiones' => ['25.1', '24.7', '24.1', '23.7'], 'ediciones' => ['Community', 'Business']],

        // ── Virtualización ──────────────────────────────────────────────────
        'VMware ESXi' => [
            'versiones' => ['8.0', '7.0', '6.7', '6.5'],
            'ediciones' => ['Standard', 'Enterprise Plus', 'Essentials', 'Essentials Plus', 'Free'],
        ],
        'VMware vCenter Server' => [
            'versiones' => ['8.0', '7.0', '6.7'],
            'ediciones' => ['Standard', 'Foundation', 'Essentials'],
        ],
        'Proxmox VE' => [
            'versiones' => ['8.3', '8.2', '8.1', '8.0', '7.4'],
            'ediciones' => ['Community', 'Basic', 'Standard', 'Premium'],
        ],
        'Microsoft Hyper-V Server' => [
            'versiones' => ['2019', '2016', '2012 R2'],
            'ediciones' => [],
        ],
        'XCP-ng' => ['versiones' => ['8.3', '8.2', '8.0'], 'ediciones' => []],
        'Citrix Hypervisor' => ['versiones' => ['8.2', '8.1'], 'ediciones' => ['Standard', 'Premium']],
        'Nutanix AHV' => ['versiones' => [], 'ediciones' => ['Starter', 'Pro', 'Ultimate']],
        'Oracle VM VirtualBox' => ['versiones' => ['7.1', '7.0', '6.1'], 'ediciones' => []],

        // ── Equipos de red ──────────────────────────────────────────────────
        'MikroTik RouterOS' => [
            'versiones' => ['7.x', '6.x'],
            'ediciones' => ['Level 6', 'Level 5', 'Level 4', 'Level 3', 'CHR'],
        ],
        'Cisco IOS'    => ['versiones' => ['15.x', '12.x'], 'ediciones' => []],
        'Cisco IOS XE' => ['versiones' => ['17.x', '16.x'], 'ediciones' => []],
        'Cisco NX-OS'  => ['versiones' => ['10.x', '9.x', '7.x'], 'ediciones' => []],
        'Cisco ASA'    => ['versiones' => ['9.x', '8.x'], 'ediciones' => []],
        'Juniper Junos' => ['versiones' => ['23.x', '22.x', '21.x', '20.x'], 'ediciones' => []],
        'FortiOS'      => ['versiones' => ['7.6', '7.4', '7.2', '7.0', '6.4'], 'ediciones' => []],
        'Ubiquiti UniFi OS' => ['versiones' => ['4.x', '3.x', '2.x'], 'ediciones' => []],
        'Ubiquiti EdgeOS'   => ['versiones' => ['2.x', '1.x'], 'ediciones' => []],
        'Aruba OS'     => ['versiones' => ['10.x', '8.x'], 'ediciones' => []],
        'HPE Comware'  => ['versiones' => ['7', '5'], 'ediciones' => []],
        'Huawei VRP'   => ['versiones' => ['V8', 'V5'], 'ediciones' => []],
        'TP-Link Omada' => ['versiones' => ['5.x', '4.x'], 'ediciones' => []],

        // ── Almacenamiento ──────────────────────────────────────────────────
        'Synology DSM' => ['versiones' => ['7.2', '7.1', '7.0', '6.2'], 'ediciones' => []],
        'QNAP QTS'     => ['versiones' => ['5.x', '4.5'], 'ediciones' => ['QTS', 'QuTS hero']],
        'TrueNAS'      => ['versiones' => ['24.10', '24.04', '13.0'], 'ediciones' => ['SCALE', 'CORE', 'Enterprise']],
        'Unraid'       => ['versiones' => ['7.0', '6.12', '6.11'], 'ediciones' => ['Starter', 'Unleashed', 'Lifetime']],

        // ── Unix heredado ───────────────────────────────────────────────────
        'Solaris'  => ['versiones' => ['11.4', '11.3', '10'], 'ediciones' => []],
        'IBM AIX'  => ['versiones' => ['7.3', '7.2', '7.1'], 'ediciones' => []],
        'HP-UX'    => ['versiones' => ['11i v3', '11i v2'], 'ediciones' => []],

        // ── Los que no son un sistema operativo ─────────────────────────────
        'Firmware propietario' => ['versiones' => [], 'ediciones' => []],
        'Sin sistema operativo' => ['versiones' => [], 'ediciones' => []],
    ]);
}

if (!function_exists('so_catalogo_de')) {
/**
 * Lo que trae de fábrica un sistema: `['versiones' => [], 'ediciones' => []]`.
 *
 * Un sistema desconocido devuelve las dos listas vacías, no un error: la
 * empresa puede haber dado de alta un sistema propio y eso tiene que seguir
 * andando.
 */
function so_catalogo_de(string $so): array
{
    $so = trim($so);
    $fijo = SO_CATALOGO[$so] ?? ['versiones' => [], 'ediciones' => []];
    return [
        'versiones' => $fijo['versiones'] ?? [],
        'ediciones' => $fijo['ediciones'] ?? [],
    ];
}
}

if (!function_exists('so_catalogo_completo')) {
/**
 * El catálogo entero, con lo que la empresa haya agregado mezclado adentro.
 *
 * Se devuelve todo junto porque la pantalla lo necesita todo junto: el
 * JavaScript cambia las listas cuando cambia el sistema, sin ir al servidor.
 *
 * Los valores propios de la empresa (`catalogos` con tipo `so_version` o
 * `so_edicion`) no están atados a un sistema —la tabla no tiene esa columna—
 * así que se ofrecen en TODOS, al final y separados. Es a propósito: es mejor
 * que aparezcan de más y no que una versión que alguien cargó quede escondida
 * porque el sistema no coincide.
 */
function so_catalogo_completo(mysqli $conn): array
{
    $propias = ['so_version' => [], 'so_edicion' => []];
    try {
        $t = $conn->real_escape_string(tn('catalogos'));
        $rs = $conn->query("SELECT tipo, valor FROM `{$t}`
                             WHERE tipo IN ('so_version', 'so_edicion') AND activo = 1
                             ORDER BY orden, valor");
        while ($rs && ($f = $rs->fetch_assoc())) {
            $propias[$f['tipo']][] = (string)$f['valor'];
        }
    } catch (Throwable) {
        // Sin la tabla (una base vieja), se sigue con lo que trae el código.
    }

    $out = [];
    foreach (SO_CATALOGO as $so => $datos) {
        $out[$so] = [
            'versiones' => array_values(array_unique(
                array_merge($datos['versiones'] ?? [], $propias['so_version']))),
            'ediciones' => array_values(array_unique(
                array_merge($datos['ediciones'] ?? [], $propias['so_edicion']))),
        ];
    }
    // Y lo que la empresa cargó queda disponible también para un sistema que no
    // esté en la lista de arriba.
    $out['*'] = [
        'versiones' => $propias['so_version'],
        'ediciones' => $propias['so_edicion'],
    ];
    return $out;
}
}
