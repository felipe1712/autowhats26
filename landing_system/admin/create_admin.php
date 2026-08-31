<?php
/**
 * AutoWhats - Creador / Restablecedor de Usuario Administrador
 * Sube este archivo a /admin/ y ábrelo en tu navegador para crear o actualizar un usuario.
 * (Por seguridad, bórralo una vez que hayas creado tu usuario).
 */

require_once __DIR__ . '/db.php';

$msg = '';
$msg_type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        $pdo = get_db_connection();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Verificar si el usuario ya existe para actualizarlo o insertarlo
        $stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $exists = $stmt->fetch();

        if ($exists) {
            $update = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE username = ?');
            $update->execute([$hash, $username]);
            $msg = "¡La contraseña del usuario <strong>$username</strong> ha sido actualizada con éxito!";
            $msg_type = 'success';
        } else {
            $insert = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
            $insert->execute([$username, $hash]);
            $msg = "¡El usuario <strong>$username</strong> ha sido creado con éxito!";
            $msg_type = 'success';
        }
    } else {
        $msg = 'Por favor completa usuario y contraseña.';
        $msg_type = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Crear / Restablecer Administrador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: sans-serif; }
        .card { width: 100%; max-width: 440px; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

<div class="card bg-white">
    <h4 class="mb-3 text-center">Crear / Actualizar Admin</h4>
    
    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> text-center">
            <?= $msg ?>
            <?php if ($msg_type === 'success'): ?>
                <div class="mt-2"><a href="login.php" class="btn btn-sm btn-primary">Ir al Login</a></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label">Usuario</label>
            <input type="text" name="username" class="form-control" value="admin" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Contraseña Deseada</label>
            <input type="text" name="password" class="form-control" placeholder="Escribe tu contraseña aquí" required>
        </div>
        <button type="submit" class="btn btn-success w-100 py-2">Guardar Usuario en Base de Datos</button>
    </form>
</div>

</body>
</html>
