# AutoWhats - Mini Administrador de Licencias y Landing Backend

Este paquete contiene el sistema completo para administrar las licencias de AutoWhats, validarlas desde n8n y el plugin, procesar pagos con Stripe y enviar correos automáticos con Brevo.

---

## 📁 Estructura del Sistema

- **`schema.sql`**: Script SQL para crear las tablas en tu base de datos MySQL (`admins` y `licenses`).
- **`admin/`**:
  - `config.php`: Credenciales de MySQL, clave de API de Brevo y secretos.
  - `db.php`: Conexión PDO segura.
  - `auth.php`: Seguridad de sesiones y generador de claves `AW-XXXX-XXXX-XXXX`.
  - `brevo.php`: Helper para enviar correos transaccionales con la API v3 de Brevo.
  - `login.php`: Inicio de sesión para administradores.
  - `index.php`: Panel de administración (crear, renovar, suspender, desvincular dominio, reenviar emails).
  - `logout.php`: Cierre de sesión seguro.
- **`api/`**:
  - `check-license.php`: Endpoint para validar licencias desde n8n y WordPress (`POST https://landing.autowhats.com.mx/api/check-license.php`).
  - `stripe-webhook.php`: Webhook para Stripe (`checkout.session.completed`).

---

## 🚀 Pasos de Instalación

1. **Crear Base de Datos MySQL:**
   - En tu servidor MySQL / cPanel / phpMyAdmin, importa el archivo `schema.sql`.
   - El usuario admin por defecto es:
     - **Usuario:** `admin`
     - **Contraseña inicial:** `AutoWhats2026!` *(Cámbiala desde el panel)*.

2. **Configurar Credenciales:**
   - Abre `admin/config.php` y coloca tus datos reales de:
     - `DB_NAME`, `DB_USER`, `DB_PASS`
     - `BREVO_API_KEY`, `BREVO_SENDER_EMAIL`
     - `STRIPE_WEBHOOK_SECRET` (opcional si usas webhooks de Stripe).

3. **Subir a tu Servidor:**
   - Sube la carpeta `admin/` y `api/` a la raíz de tu dominio `landing.autowhats.com.mx`.
   - Sube los archivos HTML/CSS/JS de tu plantilla Bootstrap (`landingbs/www`) a la misma raíz.

4. **Acceder al Panel:**
   - Ingresa a `https://landing.autowhats.com.mx/admin/login.php`.
