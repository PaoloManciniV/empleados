<?php
include('config.php');
// Buscamos usuarios cuyas contraseñas no parezcan hashes (menos de 60 caracteres)
$stmt = $db->query("SELECT id, password FROM usuarios");
$count = 0;
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Si la contraseña no está encriptada (los hash de bcrypt empiezan por $2y$ y son largos)
    if (strlen($row['password']) < 60) {
        $hash = password_hash($row['password'], PASSWORD_DEFAULT);
        $upd = $db->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
        $upd->execute([$hash, $row['id']]);
        $count++;
    }
}
echo "Se han encriptado $count contraseñas. BORRA ESTE ARCHIVO AHORA.";
?>