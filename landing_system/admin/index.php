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
$active_tab = $_GET['tab'] ?? 'licenses';

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
            $email_lang = $_POST['email_lang'] ?? 'es';

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
                    send_template_email_brevo($email, $name, 'recibo', $email_lang, [
                        '{license_key}' => $key,
                        '{plan_name}'   => $plan,
                        '{expires_at}'  => $expires_at
                    ]);
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

        // 5. Enviar Email Específico vía Brevo
        elseif ($action === 'send_custom_email') {
            $email         = trim($_POST['target_email'] ?? '');
            $name          = trim($_POST['target_name'] ?? 'Cliente');
            $template_type = $_POST['template_type'] ?? 'recibo';
            $lang          = $_POST['email_lang'] ?? 'es';
            $license_key   = trim($_POST['license_key'] ?? '');
            $plan_name     = trim($_POST['plan_name'] ?? 'AutoWhats Pro');

            if (!empty($email)) {
                $sent = send_template_email_brevo($email, $name, $template_type, $lang, [
                    '{license_key}' => $license_key,
                    '{plan_name}'   => $plan_name,
                    '{subscription_name}' => $plan_name
                ]);

                if ($sent) {
                    $msg = "Correo (<strong>$template_type [$lang]</strong>) enviado exitosamente a <strong>$email</strong> vía Brevo.";
                } else {
                    $msg = "No se pudo enviar el correo. Revisa tu clave API de Brevo en config.php.";
                    $msg_type = 'warning';
                }
            } else {
                $msg = "El email de destino no puede estar vacío.";
                $msg_type = 'danger';
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
    <title>AutoWhats - Panel de Administración</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; }
        .navbar-brand { font-weight: 800; color: #25d366 !important; font-size: 18px; }
        .card { border: none; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); }
        .badge-active { background-color: #22c55e; color: white; }
        .badge-expired { background-color: #f59e0b; color: white; }
        .badge-suspended { background-color: #ef4444; color: white; }
        .key-text { font-family: monospace; font-weight: 600; font-size: 13px; color: #0f172a; }
        .btn-brand { background-color: #25d366; color: white; font-weight: 600; }
        .btn-brand:hover { background-color: #1eb954; color: white; }
        .nav-link.active-tab { color: #25d366 !important; font-weight: 600; border-bottom: 2px solid #25d366; }
        .nav-sub-menu { background: #1e293b; }
        .nav-sub-menu .nav-link { color: #cbd5e1; padding: 12px 18px; font-weight: 500; font-size: 14px; }
        .nav-sub-menu .nav-link:hover { color: #ffffff; }
        .nav-sub-menu .nav-link.active { color: #25d366; background: rgba(37, 211, 102, 0.1); border-radius: 6px; }
    </style>
</head>
<body>

<!-- 1. Header / Navbar Superior Principal -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark py-2 border-bottom border-secondary">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <i class="bi bi-whatsapp fs-4"></i> auto<span class="text-white">whats</span> <span class="badge bg-secondary fw-normal fs-6">Admin v1.5</span>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarAdminContent">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarAdminContent">
            <!-- Menú Horizontal de Opciones -->
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'licenses' ? 'text-success fw-bold' : 'text-light' ?>" href="index.php?tab=licenses">
                        <i class="bi bi-key-fill"></i> Licencias
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'emails' ? 'text-success fw-bold' : 'text-light' ?>" href="index.php?tab=emails">
                        <i class="bi bi-envelope-paper-fill"></i> Plantillas Brevo
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'system' ? 'text-success fw-bold' : 'text-light' ?>" href="index.php?tab=system">
                        <i class="bi bi-hdd-network-fill"></i> Conexiones & API
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-light" href="../index.html" target="_blank">
                        <i class="bi bi-box-arrow-up-right"></i> Ver Landing
                    </a>
                </li>
            </ul>

            <!-- Acciones de Usuario a la Derecha -->
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-sm btn-brand" data-bs-toggle="modal" data-bs-target="#modalCreateLicense">
                    <i class="bi bi-plus-circle-fill"></i> Nueva Licencia
                </button>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['admin_username'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><span class="dropdown-header">Administrador</span></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalPassword"><i class="bi bi-shield-lock"></i> Cambiar Contraseña</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="container my-4">

    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show shadow-sm" role="alert">
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- SECCIÓN 1: GESTIÓN DE LICENCIAS -->
    <?php if ($active_tab === 'licenses'): ?>
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

        <!-- Barra de Búsqueda y Filtros -->
        <div class="card p-3 mb-4">
            <div class="row g-3 align-items-center">
                <div class="col-md-8">
                    <form method="GET" action="index.php" class="d-flex gap-2">
                        <input type="hidden" name="tab" value="licenses">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Buscar por clave, cliente, email o dominio..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                        <select name="status" class="form-select form-select-sm" style="width: 140px;">
                            <option value="">Todos</option>
                            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Activas</option>
                            <option value="expired" <?= $filter_status === 'expired' ? 'selected' : '' ?>>Expiradas</option>
                            <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspendidas</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-search"></i> Buscar</button>
                        <?php if (!empty($search) || !empty($filter_status)): ?>
                            <a href="index.php?tab=licenses" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="col-md-4 text-md-end">
                    <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#modalCreateLicense">
                        <i class="bi bi-plus-circle"></i> Nueva Licencia
                    </button>
                </div>
            </div>
        </div>

        <!-- Tabla de Licencias -->
        <div class="card p-0 overflow-hidden shadow-sm">
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
                                            <form method="POST" action="index.php?tab=licenses" class="d-inline" onsubmit="return confirm('¿Desvincular dominio para permitir activar en otro sitio?');">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                <input type="hidden" name="action" value="reset_domain">
                                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                <button type="submit" class="btn btn-link btn-sm p-0 text-danger" title="Desvincular Dominio"><i class="bi bi-x-circle"></i></button>
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
                                                <!-- Enviar Email Brevo con Plantilla -->
                                                <li><h6 class="dropdown-header">Comunicación (Brevo)</h6></li>
                                                <li>
                                                    <button class="dropdown-item text-primary" data-bs-toggle="modal" data-bs-target="#modalSendEmailDirect"
                                                        onclick="prepareEmailModal('<?= htmlspecialchars($row['client_email'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($row['client_name'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($row['license_key'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($row['plan_name'], ENT_QUOTES, 'UTF-8') ?>')">
                                                        <i class="bi bi-envelope-paper"></i> Enviar Email (Plantilla)
                                                    </button>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <!-- Cambiar Estado -->
                                                <li><h6 class="dropdown-header">Estado</h6></li>
                                                <li>
                                                    <form method="POST" action="index.php?tab=licenses">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                        <input type="hidden" name="action" value="change_status">
                                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                        <input type="hidden" name="status" value="active">
                                                        <button type="submit" class="dropdown-item text-success"><i class="bi bi-check-circle"></i> Activar</button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" action="index.php?tab=licenses">
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
                                                    <form method="POST" action="index.php?tab=licenses">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                        <input type="hidden" name="action" value="extend_license">
                                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                        <input type="hidden" name="extend_type" value="1year">
                                                        <button type="submit" class="dropdown-item"><i class="bi bi-calendar-plus"></i> +1 Año</button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" action="index.php?tab=licenses">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                        <input type="hidden" name="action" value="extend_license">
                                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                        <input type="hidden" name="extend_type" value="lifetime">
                                                        <button type="submit" class="dropdown-item"><i class="bi bi-infinity"></i> Hacer Lifetime</button>
                                                    </form>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <!-- Eliminar -->
                                                <li>
                                                    <form method="POST" action="index.php?tab=licenses" onsubmit="return confirm('¿Eliminar permanentemente esta licencia?');">
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
    <?php endif; ?>

    <!-- SECCIÓN 2: CENTRO DE PLANTILLAS Y ENVÍOS BREVO -->
    <?php if ($active_tab === 'emails'): ?>
        <div class="row g-4">
            <div class="col-md-5">
                <div class="card p-4 bg-white shadow-sm">
                    <h5 class="mb-3 text-primary"><i class="bi bi-send-fill"></i> Despachar Correo</h5>
                    <form method="POST" action="index.php?tab=emails">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="send_custom_email">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre del Cliente *</label>
                            <input type="text" name="target_name" class="form-control" placeholder="Juan Pérez" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Email de Destino *</label>
                            <input type="email" name="target_email" class="form-control" placeholder="cliente@dominio.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Seleccionar Plantilla</label>
                            <select name="template_type" id="select_preview_template" class="form-select" onchange="updateEmailPreview()">
                                <option value="recibo">🚀 Recibo de Compra / Entrega de Licencia</option>
                                <option value="falla_cobro">⚠️ Falla en el Cobro / Recordatorio</option>
                                <option value="cancelacion">🛑 Suscripción Cancelada</option>
                                <option value="promocional">🎉 Anuncio Promocional / Novedades</option>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Idioma</label>
                                <select name="email_lang" id="select_preview_lang" class="form-select" onchange="updateEmailPreview()">
                                    <option value="es" selected>Español (ES)</option>
                                    <option value="en">Inglés (EN)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Plan Asociado</label>
                                <input type="text" name="plan_name" class="form-control" value="AutoWhats Pro Anual">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold">Clave de Licencia (Opcional)</label>
                            <input type="text" name="license_key" class="form-control" placeholder="AW-XXXX-XXXX-XXXX">
                        </div>

                        <button type="submit" class="btn btn-brand w-100 py-2">
                            <i class="bi bi-send"></i> Enviar Correo con Brevo
                        </button>
                    </form>
                </div>
            </div>

            <!-- Vista Previa de Plantilla -->
            <div class="col-md-7">
                <div class="card p-4 bg-white shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="m-0 text-secondary"><i class="bi bi-eye"></i> Plantillas HTML Disponibles</h5>
                        <span class="badge bg-dark">Brevo Transactional API</span>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <div class="border rounded p-2 text-center bg-light">
                                <strong class="d-block text-primary">email-recibo-edd.html</strong>
                                <small class="text-muted">Entrega de licencia y bienvenida</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-2 text-center bg-light">
                                <strong class="d-block text-warning text-dark">email-falla-cobro.html</strong>
                                <small class="text-muted">Aviso de tarjeta o pago rechazado</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-2 text-center bg-light">
                                <strong class="d-block text-secondary">email-cancelacion.html</strong>
                                <small class="text-muted">Confirmación de baja de servicio</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-2 text-center bg-light">
                                <strong class="d-block text-success">email-promocional.html</strong>
                                <small class="text-muted">Lanzamientos y nuevas funciones</small>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-info-circle-fill"></i> Todas las plantillas cuentan con su versión equivalente en inglés (<code>-en.html</code>) con diseño responsive listo para clientes internacionales.
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- SECCIÓN 3: CONEXIONES Y ESTADO DEL SISTEMA -->
    <?php if ($active_tab === 'system'): ?>
        <div class="row g-4">
            <div class="col-md-6">
                <div class="card p-4 bg-white shadow-sm">
                    <h5 class="mb-3 text-dark"><i class="bi bi-diagram-3-fill text-success"></i> Endpoints Activos</h5>
                    
                    <div class="mb-3 border-bottom pb-3">
                        <label class="text-muted small fw-bold">API DE VALIDACIÓN DE LICENCIAS (n8n / WordPress):</label>
                        <div class="input-group input-group-sm mt-1">
                            <input type="text" class="form-control" value="https://landing.autowhats.com.mx/api/check-license.php" readonly id="apiCheckUrl">
                            <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('apiCheckUrl').value); alert('Copiado');"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <small class="text-muted">Método POST: <code>{"license_key": "AW-XXXX-XXXX-XXXX", "site_url": "https://cliente.com"}</code></small>
                    </div>

                    <div class="mb-3 border-bottom pb-3">
                        <label class="text-muted small fw-bold">WEBHOOK DE PAGOS (Stripe):</label>
                        <div class="input-group input-group-sm mt-1">
                            <input type="text" class="form-control" value="https://landing.autowhats.com.mx/api/stripe-webhook.php" readonly id="apiStripeUrl">
                            <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('apiStripeUrl').value); alert('Copiado');"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <small class="text-muted">Evento a escuchar: <code>checkout.session.completed</code></small>
                    </div>

                    <div>
                        <label class="text-muted small fw-bold">DESCARGA DIRECTA DEL PLUGIN:</label>
                        <div class="input-group input-group-sm mt-1">
                            <input type="text" class="form-control" value="<?= htmlspecialchars(PLUGIN_DOWNLOAD_URL, ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card p-4 bg-white shadow-sm">
                    <h5 class="mb-3 text-dark"><i class="bi bi-shield-check text-primary"></i> Estado de Configuración</h5>
                    
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-database text-success me-2"></i> Base de Datos MySQL (PDO):</span>
                            <span class="badge bg-success">Conectado (<?= DB_NAME ?>)</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-envelope text-primary me-2"></i> Servicio Brevo Email:</span>
                            <?php if (strpos(BREVO_API_KEY, 'TU_CLAVE') === false && !empty(BREVO_API_KEY)): ?>
                                <span class="badge bg-success">Configurado</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Pendiente API Key</span>
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-send text-info me-2"></i> Remitente de Correo:</span>
                            <span class="fw-bold"><?= BREVO_SENDER_EMAIL ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-stripe text-primary me-2"></i> Webhook Secret Stripe:</span>
                            <?php if (strpos(STRIPE_WEBHOOK_SECRET, 'TU_SECRETO') === false && !empty(STRIPE_WEBHOOK_SECRET)): ?>
                                <span class="badge bg-success">Activo</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Opcional</span>
                            <?php endif; ?>
                        </li>
                    </ul>

                    <div class="alert alert-light border mt-3 small text-muted mb-0">
                        Para modificar cualquiera de estas credenciales, edita el archivo <code>admin/config.php</code> en tu servidor.
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Modal Crear Licencia -->
<div class="modal fade" id="modalCreateLicense" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php?tab=licenses">
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
                    <div class="row g-2 mb-3">
                        <div class="col-md-7">
                            <div class="form-check pt-2">
                                <input class="form-check-input" type="checkbox" name="send_email" id="sendEmailCheck" checked>
                                <label class="form-check-label" for="sendEmailCheck">
                                    Enviar email por Brevo
                                </label>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <select name="email_lang" class="form-select form-select-sm">
                                <option value="es" selected>Español (ES)</option>
                                <option value="en">Inglés (EN)</option>
                            </select>
                        </div>
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

<!-- Modal Enviar Email Específico / Plantilla -->
<div class="modal fade" id="modalSendEmailDirect" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php?tab=licenses">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="send_custom_email">
                <input type="hidden" name="license_key" id="modal_email_key">
                <input type="hidden" name="plan_name" id="modal_email_plan">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-send"></i> Enviar Email con Brevo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Destinatario</label>
                        <input type="text" name="target_name" id="modal_email_name" class="form-control mb-2" placeholder="Nombre">
                        <input type="email" name="target_email" id="modal_email_addr" class="form-control" required placeholder="correo@ejemplo.com">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-7">
                            <label class="form-label">Plantilla</label>
                            <select name="template_type" class="form-select" required>
                                <option value="recibo">🚀 Recibo de Compra / Licencia</option>
                                <option value="falla_cobro">⚠️ Falla en el Cobro / Recordatorio</option>
                                <option value="cancelacion">🛑 Suscripción Cancelada</option>
                                <option value="promocional">🎉 Anuncio Promocional / Novedades</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Idioma</label>
                            <select name="email_lang" class="form-select">
                                <option value="es" selected>Español (ES)</option>
                                <option value="en">Inglés (EN)</option>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-light border small text-muted">
                        El correo será enviado a través de la API oficial de Brevo con diseño responsivo y las variables del cliente ({name}, {license_key}, enlaces de descarga) insertadas automáticamente.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Enviar Ahora</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Cambiar Contraseña Admin -->
<div class="modal fade" id="modalPassword" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" action="index.php?tab=<?= htmlspecialchars($active_tab, ENT_QUOTES, 'UTF-8') ?>">
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
<script>
function prepareEmailModal(email, name, key, plan) {
    document.getElementById('modal_email_addr').value = email;
    document.getElementById('modal_email_name').value = name;
    document.getElementById('modal_email_key').value = key;
    document.getElementById('modal_email_plan').value = plan;
}
</script>
</body>
</html>
