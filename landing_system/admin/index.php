<?php
/**
 * AutoWhats License Manager - Dashboard Principal
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/brevo.php';

require_login();

$pdo = get_db_connection();
$msg = '';
$msg_type = 'success';

// Procesar Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf   = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf)) {
        $msg = 'Token de seguridad inválido.';
        $msg_type = 'danger';
    } else {
        // 1. Crear Nueva Licencia
        if ($action === 'create_license') {
            $name    = trim($_POST['client_name'] ?? '');
            $email   = trim($_POST['client_email'] ?? '');
            $plan    = trim($_POST['plan_name'] ?? 'Pro Anual');
            $custom_key = trim($_POST['custom_key'] ?? '');
            $duration= $_POST['duration'] ?? '1year';
            $send_mail = isset($_POST['send_email']);

            $key = !empty($custom_key) ? $custom_key : generate_license_key();

            $expires_at = null;
            if ($duration === '1month') {
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 month'));
            } elseif ($duration === '1year') {
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 year'));
            } elseif ($duration === 'lifetime') {
                $expires_at = null;
            }

            try {
                $stmt = $pdo->prepare('INSERT INTO licenses (license_key, client_name, client_email, plan_name, expires_at, status) VALUES (?, ?, ?, ?, ?, "active")');
                $stmt->execute([$key, $name, $email, $plan, $expires_at]);

                if ($send_mail && !empty($email)) {
                    send_license_email_brevo($email, $name, $key, $plan, $expires_at);
                }

                $msg = "Licencia <strong>$key</strong> creada exitosamente.";
            } catch (PDOException $e) {
                $msg = 'Error al crear licencia: ' . (strpos($e->getMessage(), 'Duplicate') !== false ? 'La clave ya existe.' : $e->getMessage());
                $msg_type = 'danger';
            }
        }

        // 2. Cambiar Estado
        elseif ($action === 'change_status') {
            $id     = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            if (in_array($status, ['active', 'expired', 'suspended'])) {
                $stmt = $pdo->prepare('UPDATE licenses SET status = ? WHERE id = ?');
                $stmt->execute([$status, $id]);
                $msg = "Estado actualizado a <strong>$status</strong>.";
            }
        }

        // 3. Extender / Renovar
        elseif ($action === 'extend_license') {
            $id   = (int)($_POST['id'] ?? 0);
            $type = $_POST['extend_type'] ?? '1year';

            $stmt = $pdo->prepare('SELECT expires_at FROM licenses WHERE id = ?');
            $stmt->execute([$id]);
            $current = $stmt->fetch();

            if ($current) {
                $base = (!empty($current['expires_at']) && strtotime($current['expires_at']) > time()) 
                        ? strtotime($current['expires_at']) 
                        : time();

                $new_expires = null;
                if ($type === '1month') {
                    $new_expires = date('Y-m-d H:i:s', strtotime('+1 month', $base));
                } elseif ($type === '1year') {
                    $new_expires = date('Y-m-d H:i:s', strtotime('+1 year', $base));
                } elseif ($type === 'lifetime') {
                    $new_expires = null;
                }

                $stmt = $pdo->prepare('UPDATE licenses SET expires_at = ?, status = "active" WHERE id = ?');
                $stmt->execute([$new_expires, $id]);
                $msg = 'Licencia renovada exitosamente.';
            }
        }

        // 4. Desvincular Dominio
        elseif ($action === 'reset_domain') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE licenses SET site_url = NULL WHERE id = ?');
            $stmt->execute([$id]);
            $msg = 'Dominio desvinculado. El cliente puede activarla en otro sitio.';
        }

        // 5. Reenviar Email con Brevo
        elseif ($action === 'resend_email') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM licenses WHERE id = ?');
            $stmt->execute([$id]);
            $lic = $stmt->fetch();

            if ($lic) {
                $sent = send_license_email_brevo($lic['client_email'], $lic['client_name'], $lic['license_key'], $lic['plan_name'], $lic['expires_at']);
                if ($sent) {
                    $msg = "Correo reenviado exitosamente a <strong>{$lic['client_email']}</strong>.";
                } else {
                    $msg = "No se pudo enviar el correo. Revisa la clave API de Brevo en config.php.";
                    $msg_type = 'warning';
                }
            }
        }

        // 6. Eliminar Licencia
        elseif ($action === 'delete_license') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM licenses WHERE id = ?');
            $stmt->execute([$id]);
            $msg = 'Licencia eliminada.';
        }

        // 7. Cambiar Contraseña del Administrador
        elseif ($action === 'change_password') {
            $new_pass = trim($_POST['new_password'] ?? '');
            if (strlen($new_pass) >= 6) {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
                $stmt->execute([$hash, $_SESSION['admin_id']]);
                $msg = 'Contraseña actualizada con éxito.';
            } else {
                $msg = 'La contraseña debe tener al menos 6 caracteres.';
                $msg_type = 'danger';
            }
        }
    }
}

// Filtros y Búsqueda
$search = trim($_GET['search'] ?? '');
$filter_status = trim($_GET['status'] ?? '');

$query = 'SELECT * FROM licenses WHERE 1=1';
$params = [];

if (!empty($search)) {
    $query .= ' AND (license_key LIKE ? OR client_name LIKE ? OR client_email LIKE ? OR site_url LIKE ?)';
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if (!empty($filter_status)) {
    $query .= ' AND status = ?';
    $params[] = $filter_status;
}

$query .= ' ORDER BY id DESC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$licenses = $stmt->fetchAll();

// Métricas
$total_count  = (int)$pdo->query('SELECT COUNT(*) FROM licenses')->fetchColumn();
$active_count = (int)$pdo->query('SELECT COUNT(*) FROM licenses WHERE status = "active"')->fetchColumn();
$exp_count    = (int)$pdo->query('SELECT COUNT(*) FROM licenses WHERE status = "expired"')->fetchColumn();
$susp_count   = (int)$pdo->query('SELECT COUNT(*) FROM licenses WHERE status = "suspended"')->fetchColumn();

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoWhats - Administrador de Licencias</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; }
        .navbar-brand { font-weight: 700; color: #25d366 !important; }
        .card { border: none; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); }
        .badge-active { background-color: #22c55e; color: white; }
        .badge-expired { background-color: #f59e0b; color: white; }
        .badge-suspended { background-color: #ef4444; color: white; }
        .key-text { font-family: monospace; font-weight: 600; font-size: 13px; color: #0f172a; }
        .btn-brand { background-color: #25d366; color: white; font-weight: 600; }
        .btn-brand:hover { background-color: #1eb954; color: white; }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark py-3">
    <div class="container">
        <a class="navbar-brand" href="index.php"><i class="bi bi-whatsapp"></i> AutoWhats <span class="text-white fw-light fs-6">| License Manager</span></a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-light"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['admin_username'], ENT_QUOTES, 'UTF-8') ?></span>
            <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#modalPassword"><i class="bi bi-key"></i> Clave</button>
            <a href="logout.php" class="btn btn-sm btn-danger"><i class="bi bi-box-arrow-right"></i> Salir</a>
        </div>
    </div>
</nav>

<div class="container my-4">

    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Métricas -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card p-3 bg-white">
                <div class="text-muted small">Total Licencias</div>
                <div class="fs-3 fw-bold"><?= $total_count ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 bg-white border-start border-success border-4">
                <div class="text-muted small">Activas</div>
                <div class="fs-3 fw-bold text-success"><?= $active_count ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 bg-white border-start border-warning border-4">
                <div class="text-muted small">Expiradas</div>
                <div class="fs-3 fw-bold text-warning"><?= $exp_count ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 bg-white border-start border-danger border-4">
                <div class="text-muted small">Suspendidas</div>
                <div class="fs-3 fw-bold text-danger"><?= $susp_count ?></div>
            </div>
        </div>
    </div>

    <!-- Barra de Búsqueda y Botón Crear -->
    <div class="card p-3 mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-md-6">
                <form method="GET" action="index.php" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Buscar por clave, cliente, email o dominio..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    <select name="status" class="form-select form-select-sm" style="width: 140px;">
                        <option value="">Todos</option>
                        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Activas</option>
                        <option value="expired" <?= $filter_status === 'expired' ? 'selected' : '' ?>>Expiradas</option>
                        <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspendidas</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-search"></i></button>
                    <?php if (!empty($search) || !empty($filter_status)): ?>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="col-md-6 text-md-end">
                <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#modalCreateLicense">
                    <i class="bi bi-plus-circle"></i> Nueva Licencia
                </button>
            </div>
        </div>
    </div>

    <!-- Tabla de Licencias -->
    <div class="card p-0 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Clave</th>
                        <th>Cliente</th>
                        <th>Email</th>
                        <th>Plan</th>
                        <th>Dominio Vinculado</th>
                        <th>Vencimiento</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($licenses)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">No se encontraron licencias registradas.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($licenses as $row): ?>
                            <tr>
                                <td><span class="key-text"><?= htmlspecialchars($row['license_key'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><strong><?= htmlspecialchars($row['client_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td><small><?= htmlspecialchars($row['client_email'], ENT_QUOTES, 'UTF-8') ?></small></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($row['plan_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td>
                                    <?php if (!empty($row['site_url'])): ?>
                                        <code><?= htmlspecialchars($row['site_url'], ENT_QUOTES, 'UTF-8') ?></code>
                                        <form method="POST" action="index.php" class="d-inline" onsubmit="return confirm('¿Desvincular dominio para permitir activar en otro sitio?');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="action" value="reset_domain">
                                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-link btn-sm p-0 text-danger" title="Desvincular"><i class="bi bi-x-circle"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">Sin vincular</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['expires_at']): ?>
                                        <?= date('d/m/Y', strtotime($row['expires_at'])) ?>
                                        <?php if (strtotime($row['expires_at']) < time() && $row['status'] === 'active'): ?>
                                            <span class="badge bg-warning text-dark">Vencida</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-success fw-bold">De por vida</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $row['status'] ?>">
                                        <?= strtoupper($row['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                            <!-- Cambiar Estado -->
                                            <li><h6 class="dropdown-header">Estado</h6></li>
                                            <li>
                                                <form method="POST" action="index.php">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                    <input type="hidden" name="action" value="change_status">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="status" value="active">
                                                    <button type="submit" class="dropdown-item text-success"><i class="bi bi-check-circle"></i> Activar</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST" action="index.php">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                    <input type="hidden" name="action" value="change_status">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="status" value="suspended">
                                                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-slash-circle"></i> Suspender</button>
                                                </form>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <!-- Renovar -->
                                            <li><h6 class="dropdown-header">Renovar</h6></li>
                                            <li>
                                                <form method="POST" action="index.php">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                    <input type="hidden" name="action" value="extend_license">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="extend_type" value="1year">
                                                    <button type="submit" class="dropdown-item"><i class="bi bi-calendar-plus"></i> +1 Año</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST" action="index.php">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                    <input type="hidden" name="action" value="extend_license">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="extend_type" value="lifetime">
                                                    <button type="submit" class="dropdown-item"><i class="bi bi-infinity"></i> Hacer Lifetime</button>
                                                </form>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <!-- Email y Eliminar -->
                                            <li>
                                                <form method="POST" action="index.php" onsubmit="return confirm('¿Reenviar correo de activación al cliente?');">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                    <input type="hidden" name="action" value="resend_email">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <button type="submit" class="dropdown-item text-primary"><i class="bi bi-envelope"></i> Reenviar Correo</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST" action="index.php" onsubmit="return confirm('¿Eliminar permanentemente esta licencia?');">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                    <input type="hidden" name="action" value="delete_license">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash"></i> Eliminar</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Crear Licencia -->
<div class="modal fade" id="modalCreateLicense" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="create_license">
                <div class="modal-header">
                    <h5 class="modal-title">Nueva Licencia</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nombre del Cliente *</label>
                        <input type="text" name="client_name" class="form-control" required placeholder="Juan Pérez">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email del Cliente *</label>
                        <input type="email" name="client_email" class="form-control" required placeholder="juan@ejemplo.com">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Plan</label>
                            <input type="text" name="plan_name" class="form-control" value="Pro Anual">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Duración</label>
                            <select name="duration" class="form-select">
                                <option value="1year" selected>1 Año</option>
                                <option value="1month">1 Mes</option>
                                <option value="lifetime">De por vida (Lifetime)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Clave Personalizada (Opcional, en blanco para auto-generar)</label>
                        <input type="text" name="custom_key" class="form-control" placeholder="AW-XXXX-XXXX-XXXX">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="send_email" id="sendEmailCheck" checked>
                        <label class="form-check-label" for="sendEmailCheck">
                            Enviar correo al cliente con Brevo
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-brand">Crear y Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Cambiar Contraseña Admin -->
<div class="modal fade" id="modalPassword" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="change_password">
                <div class="modal-header">
                    <h5 class="modal-title">Cambiar Clave</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nueva Contraseña</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-dark w-100">Guardar Contraseña</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
