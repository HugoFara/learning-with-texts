<?php

/**
 * Email Service
 *
 * Provides email sending functionality using PHPMailer.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Application\Services;

use DateTimeImmutable;
use Lwt\Shared\Infrastructure\Http\UrlUtilities;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Service for sending emails.
 *
 * Uses PHPMailer for SMTP email delivery. Configuration is loaded from
 * environment variables. When email is not configured, tokens are logged
 * instead of emailed (useful for development).
 *
 * @since 3.0.0
 */
class EmailService
{
    /**
     * @var array{
     *   enabled: bool,
     *   host: string,
     *   port: int,
     *   username: string,
     *   password: string,
     *   encryption: string,
     *   from_address: string,
     *   from_name: string
     * }
     */
    private array $config;

    /**
     * Create a new EmailService.
     *
     * @param array{
     *   enabled?: bool,
     *   host?: string,
     *   port?: int,
     *   username?: string,
     *   password?: string,
     *   encryption?: string,
     *   from_address?: string,
     *   from_name?: string
     * }|null $config Optional config array. If null, loads from environment.
     */
    public function __construct(?array $config = null)
    {
        $envConfig = $this->loadConfigFromEnv();
        if ($config !== null) {
            $this->config = array_merge($envConfig, $config);
        } else {
            $this->config = $envConfig;
        }
    }

    /**
     * Load email configuration from environment variables.
     *
     * @return array{
     *   enabled: bool,
     *   host: string,
     *   port: int,
     *   username: string,
     *   password: string,
     *   encryption: string,
     *   from_address: string,
     *   from_name: string
     * }
     */
    private function loadConfigFromEnv(): array
    {
        return [
            'enabled' => filter_var($_ENV['MAIL_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
            'host' => $_ENV['MAIL_HOST'] ?? '',
            'port' => (int) ($_ENV['MAIL_PORT'] ?? 587),
            'username' => $_ENV['MAIL_USERNAME'] ?? '',
            'password' => $_ENV['MAIL_PASSWORD'] ?? '',
            'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
            'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@localhost',
            'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'LWT',
        ];
    }

    /**
     * Check if email is enabled and configured.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'] && $this->config['host'] !== '';
    }

    /**
     * Send a password reset email.
     *
     * @param string            $email    Recipient email
     * @param string            $username Recipient username
     * @param string            $token    Reset token (plaintext)
     * @param DateTimeImmutable $expires  Token expiration time
     *
     * @return bool True if sent successfully
     *
     * @throws \RuntimeException If email sending fails and mail is enabled
     */
    public function sendPasswordResetEmail(
        string $email,
        string $username,
        string $token,
        DateTimeImmutable $expires
    ): bool {
        if (!$this->isEnabled()) {
            // Log for debugging in dev environments
            error_log(sprintf(
                "Password reset requested for %s (%s). Token: %s (expires: %s)",
                $username,
                $email,
                $token,
                $expires->format('Y-m-d H:i:s T')
            ));
            return true;
        }

        $resetUrl = $this->buildResetUrl($token);
        $expiryFormatted = $expires->format('Y-m-d H:i:s T');

        $subject = 'Password Reset Request - LWT';
        $body = $this->buildPasswordResetHtml($username, $resetUrl, $expiryFormatted);
        $altBody = $this->buildPasswordResetPlainText($username, $resetUrl, $expiryFormatted);

        return $this->send($email, $subject, $body, $altBody);
    }

    /**
     * Send an email verification email.
     *
     * @param string            $email    Recipient email
     * @param string            $username Recipient username
     * @param string            $token    Verification token (plaintext)
     * @param DateTimeImmutable $expires  Token expiration time
     *
     * @return bool True if sent successfully
     */
    public function sendVerificationEmail(
        string $email,
        string $username,
        string $token,
        DateTimeImmutable $expires
    ): bool {
        if (!$this->isEnabled()) {
            error_log(sprintf(
                "Email verification for %s (%s). Token: %s (expires: %s)",
                $username,
                $email,
                $token,
                $expires->format('Y-m-d H:i:s T')
            ));
            return true;
        }

        $verifyUrl = $this->buildVerifyUrl($token);
        $expiryFormatted = $expires->format('Y-m-d H:i:s T');

        $subject = 'Verify Your Email - LWT';
        $body = $this->buildVerificationHtml($username, $verifyUrl, $expiryFormatted);
        $altBody = $this->buildVerificationPlainText($username, $verifyUrl, $expiryFormatted);

        return $this->send($email, $subject, $body, $altBody);
    }

    /**
     * Build the email verification URL.
     *
     * @param string $token The verification token
     *
     * @return string
     */
    private function buildVerifyUrl(string $token): string
    {
        $origin = UrlUtilities::getAppOrigin();

        return "{$origin}/verify-email?token=" . urlencode($token);
    }

    /**
     * Build HTML email body for verification.
     *
     * @param string $username  Username for greeting
     * @param string $verifyUrl The verification URL
     * @param string $expiry    Expiration time formatted
     *
     * @return string HTML email body
     */
    private function buildVerificationHtml(string $username, string $verifyUrl, string $expiry): string
    {
        $escapedUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $escapedUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verify Your Email - LWT</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #3273dc;">Verify Your Email Address</h1>
        <p>Hello {$escapedUsername},</p>
        <p>Thank you for registering with LWT! Please verify your email address by clicking the button below:</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="{$escapedUrl}"
               style="background-color: #3273dc; color: white; padding: 12px 24px;
                      text-decoration: none; border-radius: 4px; display: inline-block;">
                Verify Email
            </a>
        </p>
        <p>Or copy and paste this link into your browser:</p>
        <p style="word-break: break-all; background: #f5f5f5; padding: 10px; border-radius: 4px;">
            <a href="{$escapedUrl}">{$escapedUrl}</a>
        </p>
        <p><strong>This link will expire at {$expiry}.</strong></p>
        <p>If you did not create an account, you can safely ignore this email.</p>
        <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">
        <p style="color: #777; font-size: 12px;">
            Learning With Texts (LWT) - Language Learning by Reading
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Build plain text email body for verification.
     *
     * @param string $username  Username for greeting
     * @param string $verifyUrl The verification URL
     * @param string $expiry    Expiration time formatted
     *
     * @return string Plain text email body
     */
    private function buildVerificationPlainText(string $username, string $verifyUrl, string $expiry): string
    {
        return <<<TEXT
Verify Your Email Address

Hello {$username},

Thank you for registering with LWT! Please verify your email address by visiting the following link:
{$verifyUrl}

This link will expire at {$expiry}.

If you did not create an account, you can safely ignore this email.

---
Learning With Texts (LWT) - Language Learning by Reading
TEXT;
    }

    /**
     * Build the reset URL.
     *
     * @param string $token The reset token
     *
     * @return string
     */
    private function buildResetUrl(string $token): string
    {
        $origin = UrlUtilities::getAppOrigin();

        return "{$origin}/password/reset?token=" . urlencode($token);
    }

    /**
     * Build HTML email body.
     *
     * @param string $username Username for greeting
     * @param string $resetUrl The password reset URL
     * @param string $expiry   Expiration time formatted
     *
     * @return string HTML email body
     */
    private function buildPasswordResetHtml(string $username, string $resetUrl, string $expiry): string
    {
        $escapedUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $escapedUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Password Reset - LWT</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #3273dc;">Password Reset Request</h1>
        <p>Hello {$escapedUsername},</p>
        <p>We received a request to reset your password for your LWT account.</p>
        <p>Click the button below to reset your password:</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="{$escapedUrl}"
               style="background-color: #3273dc; color: white; padding: 12px 24px;
                      text-decoration: none; border-radius: 4px; display: inline-block;">
                Reset Password
            </a>
        </p>
        <p>Or copy and paste this link into your browser:</p>
        <p style="word-break: break-all; background: #f5f5f5; padding: 10px; border-radius: 4px;">
            <a href="{$escapedUrl}">{$escapedUrl}</a>
        </p>
        <p><strong>This link will expire at {$expiry}.</strong></p>
        <p>If you did not request a password reset, you can safely ignore this email.</p>
        <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">
        <p style="color: #777; font-size: 12px;">
            Learning With Texts (LWT) - Language Learning by Reading
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Build plain text email body.
     *
     * @param string $username Username for greeting
     * @param string $resetUrl The password reset URL
     * @param string $expiry   Expiration time formatted
     *
     * @return string Plain text email body
     */
    private function buildPasswordResetPlainText(string $username, string $resetUrl, string $expiry): string
    {
        return <<<TEXT
Password Reset Request

Hello {$username},

We received a request to reset your password for your LWT account.

To reset your password, visit the following link:
{$resetUrl}

This link will expire at {$expiry}.

If you did not request a password reset, you can safely ignore this email.

---
Learning With Texts (LWT) - Language Learning by Reading
TEXT;
    }

    /**
     * Send an email via SMTP.
     *
     * @param string $to      Recipient email
     * @param string $subject Email subject
     * @param string $body    HTML body
     * @param string $altBody Plain text alternative
     *
     * @return bool True if sent successfully
     *
     * @throws \RuntimeException If sending fails
     */
    private function send(string $to, string $subject, string $body, string $altBody): bool
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'];
            $mail->SMTPSecure = $this->config['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->config['port'];

            // Recipients
            $mail->setFrom($this->config['from_address'], $this->config['from_name']);
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = $altBody;

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log("Email sending failed: " . $mail->ErrorInfo);
            throw new \RuntimeException("Failed to send email: " . $e->getMessage());
        }
    }
}
