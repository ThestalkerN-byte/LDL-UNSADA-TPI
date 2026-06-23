<?php
declare(strict_types=1);

namespace App\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * SERVICIO DE EMAIL — Integración SMTP lista para configurar
 * ===========================================================
 *
 * Envía emails transaccionales usando PHPMailer + SMTP externo.
 * La configuración se lee 100% desde variables de entorno (.env).
 *
 * ─── ACTIVACIÓN ──────────────────────────────────────────────────────────────
 * Para activar el envío real de emails, completar en .env:
 *
 *   MAIL_ENABLED=true
 *   MAIL_HOST=smtp.gmail.com          # o smtp-relay.brevo.com, smtp.sendgrid.net
 *   MAIL_PORT=587                     # 587 (TLS/STARTTLS) | 465 (SSL)
 *   MAIL_ENCRYPTION=tls               # tls | ssl
 *   MAIL_USERNAME=cuenta@dominio.com  # usuario de la cuenta SMTP
 *   MAIL_PASSWORD=xxxx xxxx xxxx xxxx # contraseña o App Password de Gmail
 *   MAIL_FROM=noreply@dominio.com     # dirección remitente visible
 *   MAIL_FROM_NAME=ICB Digital        # nombre que verá el destinatario
 *
 * ─── PROVEEDORES COMUNES ─────────────────────────────────────────────────────
 *   Gmail personal  → smtp.gmail.com : 587 : tls
 *                     Requiere "App Password" (Google → Seguridad → Contraseñas de apps)
 *   Brevo           → smtp-relay.brevo.com : 587 : tls
 *   SendGrid        → smtp.sendgrid.net : 587 : tls
 *
 * ─── ESTADO SIN CONFIGURAR ───────────────────────────────────────────────────
 * Si MAIL_ENABLED != 'true', sendRecoveryCode() lanza RuntimeException
 * y AuthController la captura para responder al usuario con un mensaje claro.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class EmailService
{
    private bool   $enabled;
    private string $host;
    private int    $port;
    private string $encryption;
    private string $username;
    private string $password;
    private string $from;
    private string $fromName;

    public function __construct()
    {
        $this->enabled    = ($_ENV['MAIL_ENABLED']   ?? getenv('MAIL_ENABLED')   ?: 'false') === 'true';
        $this->host       = $_ENV['MAIL_HOST']        ?? getenv('MAIL_HOST')        ?: '';
        $this->port       = (int)($_ENV['MAIL_PORT']  ?? getenv('MAIL_PORT')        ?: '587');
        $this->encryption = $_ENV['MAIL_ENCRYPTION']  ?? getenv('MAIL_ENCRYPTION')  ?: 'tls';
        $this->username   = $_ENV['MAIL_USERNAME']    ?? getenv('MAIL_USERNAME')    ?: '';
        $this->password   = $_ENV['MAIL_PASSWORD']    ?? getenv('MAIL_PASSWORD')    ?: '';
        $this->from       = $_ENV['MAIL_FROM']        ?? getenv('MAIL_FROM')        ?: '';
        $this->fromName   = $_ENV['MAIL_FROM_NAME']   ?? getenv('MAIL_FROM_NAME')   ?: 'ICB Digital';
    }

    /**
     * Retorna true si el servicio está habilitado y tiene configuración mínima.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->host) && !empty($this->username);
    }

    /**
     * Envía el código de recuperación de contraseña al email del usuario.
     *
     * @param  string $emailDestino  Email registrado del usuario
     * @param  string $codigo        Código de 4 dígitos generado
     * @param  string $nombreUsuario Nombre del usuario para personalizar el mensaje
     * @return bool                  true si el envío fue exitoso
     *
     * @throws \RuntimeException si MAIL_ENABLED=false o la config está incompleta
     */
    public function sendRecoveryCode(string $emailDestino, string $codigo, string $nombreUsuario): bool
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException(
                'El servicio de email no está habilitado. ' .
                'Configurá las variables MAIL_* en el archivo .env para activarlo.'
            );
        }

        $mail = new PHPMailer(true);

        try {
            // Configuración SMTP
            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->username;
            $mail->Password   = $this->password;
            $mail->SMTPSecure = $this->encryption === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->port;
            $mail->CharSet    = 'UTF-8';

            // Remitente y destinatario
            $mail->setFrom($this->from, $this->fromName);
            $mail->addAddress($emailDestino, $nombreUsuario);

            // Contenido
            $mail->isHTML(true);
            $mail->Subject = 'Código de recuperación — ICB Digital';
            $mail->Body    = $this->buildHtmlBody($codigo, $nombreUsuario);
            $mail->AltBody = $this->buildTextBody($codigo, $nombreUsuario);

            $mail->send();
            return true;

        } catch (MailerException $e) {
            error_log('[EmailService] Fallo al enviar a ' . $emailDestino . ': ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Template HTML del email de recuperación de contraseña.
     */
    private function buildHtmlBody(string $codigo, string $nombre): string
    {
        $year   = date('Y');
        $nombre = htmlspecialchars($nombre);
        $codigo = htmlspecialchars($codigo);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
        <body style="font-family:Arial,sans-serif;background-color:#f4f4f4;margin:0;padding:20px;">
          <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
            <div style="background-color:#0B2A4A;padding:28px 32px;text-align:center;">
              <h1 style="color:#fff;margin:0;font-size:22px;letter-spacing:1px;">ICB DIGITAL</h1>
              <p style="color:rgba(255,255,255,0.75);margin:6px 0 0;font-size:13px;">IGLESIA CRISTIANA BÍBLICA</p>
            </div>
            <div style="padding:36px 32px;">
              <p style="color:#333;font-size:15px;margin-top:0;">Hola, <strong>{$nombre}</strong>.</p>
              <p style="color:#555;font-size:14px;line-height:1.6;">
                Recibimos una solicitud para restablecer tu contraseña.<br>Tu código de recuperación es:
              </p>
              <div style="text-align:center;margin:28px 0;">
                <span style="display:inline-block;background:#f0f4f8;border:2px dashed #0B2A4A;border-radius:12px;padding:16px 40px;font-size:40px;font-weight:bold;letter-spacing:12px;color:#0B2A4A;">
                  {$codigo}
                </span>
              </div>
              <p style="color:#555;font-size:13px;line-height:1.6;">
                Ingresá este código en la pantalla de recuperación de contraseña.<br>
                <strong>Válido por la duración de tu sesión actual.</strong>
              </p>
              <p style="color:#888;font-size:12px;margin-top:24px;">
                Si no solicitaste este cambio, podés ignorar este mensaje. Tu contraseña no fue modificada.
              </p>
            </div>
            <div style="background:#f8f9fa;padding:16px 32px;text-align:center;border-top:1px solid #e9ecef;">
              <p style="color:#aaa;font-size:11px;margin:0;">© {$year} ICB Digital — Mensaje automático, no respondas a este email.</p>
            </div>
          </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Versión texto plano del email (fallback para clientes sin HTML).
     */
    private function buildTextBody(string $codigo, string $nombre): string
    {
        return "Hola, {$nombre}.\n\n"
             . "Tu código de recuperación de contraseña es: {$codigo}\n\n"
             . "Ingresá este código en la pantalla de recuperación.\n"
             . "Si no solicitaste este cambio, ignorá este mensaje.\n\n"
             . "— ICB Digital";
    }
}
