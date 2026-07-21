<?php
// ============================================================
// mailer.php  —  Función centralizada de PHPMailer
// Incluye este archivo en aprobar.php, procesar.php,
// recuperar.php y recordatorios.php en lugar de repetir
// la configuración SMTP en cada uno.
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

/**
 * Retorna un objeto PHPMailer ya configurado con SMTP.
 * Solo agrega destinatarios, asunto y body donde lo necesites.
 */
function crearMailer(): PHPMailer
{
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_HOST']    ?? 'smtp.zoho.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['MAIL_USER']    ?? '';
    $mail->Password   = $_ENV['MAIL_PASS']    ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = (int)($_ENV['MAIL_PORT'] ?? 465);
    $mail->CharSet    = 'UTF-8';

    $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'Agro-Costa RRHH';
    $fromAddr = $_ENV['MAIL_USER']      ?? '';
    $mail->setFrom($fromAddr, $fromName);

    return $mail;
}
?>
