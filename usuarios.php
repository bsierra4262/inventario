<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/csrf.php';
require_once __DIR__ . '/logs/logger.php';

// --- Auth ---
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    header('Location: login.php');
    exit();
}

$usersTable = tn('users');
$stmt = $conn->prepare("SELECT * FROM `{$usersTable}` WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$currentUser) {
    session_destroy();
    header('Location: login.php');
    exit();
}

if ($currentUser['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// --- Cargar todos los usuarios ---
$allUsers = [];
$res = $conn->query("SELECT id, username, email, role, created_at FROM `{$usersTable}` ORDER BY id ASC");
while ($row = $res->fetch_assoc()) {
    $allUsers[] = $row;
}

// --- Token CSRF ---
$csrf = generateCsrfToken('users_crud');

// --- Mensaje de feedback ---
$feedbackMsg  = $_GET['msg']  ?? '';
$feedbackType = ($_GET['type'] ?? 'success') === 'error' ? 'danger' : 'success';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios – Asset Vault</title>
    <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Saira', sans-serif;
            background-color: #F5F5F5;
            color: #333;
        }
        body { display: flex; flex-direction: column; min-height: 100vh; }
        header {
            background-color: #663399;
            color: #fff;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header h1 { font-size: 1.5rem; margin: 0; }
        .logout-btn {
            background-color: #4E247A;
            color: #fff;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
        }
        .logout-btn:hover { background-color: #86B329; color: #fff; }
        .main-content {
            flex: 1;
            padding: 2rem 1rem 5rem 1rem;
            max-width: 1100px;
            margin: 0 auto;
            width: 100%;
        }
        h2 { color: #4E247A; font-weight: 700; }
        thead { background-color: #4E247A; color: #fff; }
        .btn-purple {
            background-color: #663399;
            color: #fff;
            border: none;
        }
        .btn-purple:hover { background-color: #4E247A; color: #fff; }
        .btn-green {
            background-color: #86B329;
            color: #fff;
            border: none;
        }
        .btn-green:hover { background-color: #5a8a1b; color: #fff; }
        .badge-admin { background-color: #663399; }
        .badge-user  { background-color: #86B329; }
        .footer {
            text-align: center;
            padding: 1rem;
            background-color: #663399;
            color: #fff;
            width: 100%;
            position: fixed;
            bottom: 0;
            left: 0;
        }
        .card-form {
            background: #fff;
            border: 1px solid #e0d0f0;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(102,51,153,0.07);
        }
    </style>
</head>
<body>
<header>
    <h1><i class="bi bi-people"></i> Gestión de Usuarios</h1>
    <a href="index.php" class="logout-btn"><i class="bi bi-arrow-left"></i> Volver</a>
</header>

<div class="main-content">

    <?php if ($feedbackMsg !== ''): ?>
    <div class="alert alert-<?= $feedbackType ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($feedbackMsg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ===== TABLA DE USUARIOS ===== -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i>Usuarios del sistema</h2>
    </div>

    <div class="table-responsive mb-5">
        <table class="table table-bordered table-hover align-middle" style="min-width:700px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Rol</th>
                    <th>Creado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allUsers as $u): ?>
            <tr>
                <td><?= $u['id'] ?></td>
                <td><strong><?= htmlspecialchars($u['username']) ?></strong>
                    <?php if ((int)$u['id'] === $user_id): ?>
                    <span class="badge bg-secondary ms-1">Vos</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                <td>
                    <span class="badge <?= $u['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>">
                        <?= htmlspecialchars($u['role']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($u['created_at'] ?? '') ?></td>
                <td>
                    <button class="btn btn-sm btn-purple me-1"
                        onclick="abrirEditar(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>', '<?= $u['role'] ?>')">
                        <i class="bi bi-pencil"></i> Editar
                    </button>
                    <?php if ((int)$u['id'] !== $user_id): ?>
                    <button class="btn btn-sm btn-danger"
                        onclick="confirmarEliminar(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($allUsers)): ?>
            <tr><td colspan="6" class="text-center text-muted">No hay usuarios registrados.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ===== FORMULARIO CREAR USUARIO ===== -->
    <div class="card-form">
        <h4 class="mb-3" style="color:#663399;"><i class="bi bi-person-plus me-2"></i>Crear nuevo usuario</h4>
        <form method="POST" action="backend/users_crud.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Usuario *</label>
                    <input type="text" name="username" class="form-control" required
                        pattern="[a-zA-Z0-9._\-]{3,120}"
                        title="Mínimo 3 caracteres, solo letras, números, punto, guión o guión bajo.">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="Opcional">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Contraseña *</label>
                    <input type="password" name="password" class="form-control" required
                        minlength="8" title="Mínimo 8 caracteres con letras y números.">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Rol *</label>
                    <select name="role" class="form-select" required>
                        <option value="user">Usuario</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-green w-100">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ===== MODAL EDITAR ===== -->
<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:#663399; color:#fff;">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Editar usuario: <span id="editUsername"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="backend/users_crud.php" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="target_id" id="editTargetId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="email" id="editEmail" class="form-control" placeholder="Opcional">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Rol</label>
                        <select name="role" id="editRole" class="form-select">
                            <option value="user">Usuario</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nueva contraseña <small class="text-muted">(dejá vacío para no cambiar)</small></label>
                        <input type="password" name="password" class="form-control"
                            minlength="8" autocomplete="new-password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-purple">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL ELIMINAR ===== -->
<div class="modal fade" id="modalEliminar" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Eliminar usuario</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                ¿Confirmás eliminar a <strong id="deleteUsername"></strong>?
                <br><small class="text-muted">Esta acción no se puede deshacer.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST" action="backend/users_crud.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="target_id" id="deleteTargetId">
                    <button type="submit" class="btn btn-danger">Eliminar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="footer">© <?= date('Y') ?> ITH — Asset Vault</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function abrirEditar(id, username, email, role) {
    document.getElementById('editTargetId').value = id;
    document.getElementById('editUsername').textContent = username;
    document.getElementById('editEmail').value = email;
    document.getElementById('editRole').value = role;
    new bootstrap.Modal(document.getElementById('modalEditar')).show();
}

function confirmarEliminar(id, username) {
    document.getElementById('deleteTargetId').value = id;
    document.getElementById('deleteUsername').textContent = username;
    new bootstrap.Modal(document.getElementById('modalEliminar')).show();
}
</script>
</body>
</html>
