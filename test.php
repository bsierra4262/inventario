<?php
// Esto es un script de prueba para verificar si la conexión a la base de datos funciona

// Incluir el archivo de configuración de la base de datos
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/logs/logger.php';  // Incluir logger para auditoría

// Verificar la conexión a la base de datos
if ($conn->connect_error) {
    $connection_status = "Error de conexión a la base de datos: " . htmlspecialchars($conn->connect_error);
    log_event("test", "ERROR", "Error de conexión a la base de datos: " . $conn->connect_error, "instalacion");
} else {
    $connection_status = "Conexión exitosa a la base de datos.";
    log_event("test", "INSTALACION", "Conexión exitosa a la base de datos.", "instalacion");
}

// Intentar realizar una consulta simple a la base de datos
$tables_list = '';
$sql = "SHOW TABLES";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $tables_list .= "<p>Las tablas en la base de datos son:</p>";
    $tables_list .= "<ul>";
    while ($row = $result->fetch_array()) {
        $table_name = $row[0];
        $tables_list .= "<li>" . htmlspecialchars($table_name) . "</li>";
    }
    $tables_list .= "</ul>";
    log_event("test", "INSTALACION", "Consulta SHOW TABLES ejecutada correctamente. Tablas encontradas: " . $result->num_rows, "instalacion");
} else {
    $tables_list = "No se encontraron tablas en la base de datos o no se pudo ejecutar la consulta.";
    log_event("test", "ERROR", "No se encontraron tablas o error en consulta SHOW TABLES.", "instalacion");
}

// Cerrar la conexión
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prueba de Conexión - Intellihelp</title>
    <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            font-family: 'Saira', sans-serif;
            background-color: #f5f5f5;
            animation: fadeIn 1s ease-in-out;
        }

        .container {
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 1s ease-out forwards;
        }

        footer {
            text-align: center;
            padding: 20px 0;
            width: 100%;
            background-color: #663399;
            color: #ffffff;
            position: fixed;
            bottom: 0;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2 class="text-center mb-4">Prueba de Conexión a la Base de Datos</h2>
        <p>Este script verifica la conexión a la base de datos y lista las tablas disponibles.</p>
        <div class="alert alert-info">
            <?php echo $connection_status; ?>
        </div>
        <div>
            <?php echo $tables_list; ?>
        </div>
    </div>

    <footer>
        © 2025 ITH - Todos los derechos reservados
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
