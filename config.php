<?php
// ============================================================
// config.php
// - Credenciales leídas desde variables de entorno (.env)
// - session_start() centralizado aquí (único punto)
// - Errores de BD no expuestos al usuario final
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Bogota');

// Lee el archivo .env ubicado UN nivel arriba de public_html
// Ajusta la ruta si tu estructura de servidor es diferente
$env_path = dirname(dirname(__DIR__)) . '/private/.env';
if (file_exists($env_path)) {
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}

$host    = $_ENV['DB_HOST'] ?? 'localhost';
$db_name = $_ENV['DB_NAME'] ?? '';
$user    = $_ENV['DB_USER'] ?? '';
$pass    = $_ENV['DB_PASS'] ?? '';

try {
    $db = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("SET time_zone = '-05:00';");
} catch (PDOException $e) {
    // Registra el error real en el log del servidor, nunca al usuario
    error_log("DB connection error: " . $e->getMessage());
    die("Error de conexión con la base de datos. Por favor intenta más tarde.");
}
?>
