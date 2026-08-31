<?php
/**
 * AutoWhats License Manager - Login
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username']  = $admin['username'];
            $_SESSION['admin_id']        = $admin['id'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
    } else {
        $error = 'Por favor ingresa usuario y contraseña.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - AutoWhats Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 30px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        .brand-badge {
            background: #25d366;
            color: white;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 15px;
        }
        .btn-brand {
            background-color: #25d366;
            color: white;
            font-weight: 600;
        }
        .btn-brand:hover {
            background-color: #1eb954;
            color: white;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="text-center">
        <span class="brand-badge">AutoWhats</span>
        <h4 class="mb-4">Panel de Licencias</h4>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2 text-center" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="mb-3">
            <label class="form-label text-muted">Usuario</label>
            <input type="text" name="username" class="form-control" placeholder="admin" required autofocus>
        </div>
        <div class="mb-4">
            <label class="form-label text-muted">Contraseña</label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-brand w-100 py-2">Ingresar al Panel</button>
    </form>
</div>

</body>
</html>
