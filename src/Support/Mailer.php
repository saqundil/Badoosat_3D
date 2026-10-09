<?php

declare(strict_types=1);

namespace App\Support;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Authenticated SMTP through PHPMailer.
 *
 * mail() is not used anywhere in this project. On shared hosting it hands the
 * message to a local binary with an envelope sender the receiving domain has no
 * reason to trust, and the message is filed as spam or dropped silently. There
 * is no bounce and no error return, so the shop finds out when a customer
 * telephones to ask whether their order went through.
 *
 * The 'log' driver writes to storage/logs/mail instead of sending, which is
 * what a developer machine and the test suite want; it reports success, because
 * the mail did go where it was told to go.
 */
final class Mailer
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public static function make(): self
    {
        return new self(Config::file('mail'));
    }

    public function isConfigured(): bool
    {
        if (($this->config['driver'] ?? 'log') === 'log') {
            return true;
        }

        return ($this->config['smtp']['host'] ?? '') !== '';
    }

    /**
     * @param string $html the body; a plain-text part is derived from it
     * @return array{sent:bool,driver:string,error:?string}
     */
    public function send(string $to, string $name, string $subject, string $html): array
    {
        $driver = (string) ($this->config['driver'] ?? 'log');

        if ($driver === 'log') {
            return $this->toLog($to, $name, $subject, $html);
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';
            $mail->Host       = (string) $this->config['smtp']['host'];
            $mail->Port       = (int) $this->config['smtp']['port'];
            $mail->Timeout    = (int) $this->config['smtp']['timeout'];
            $mail->SMTPAuth   = true;
            $mail->Username   = (string) $this->config['smtp']['username'];
            $mail->Password   = (string) $this->config['smtp']['password'];
            $mail->SMTPSecure = $this->config['smtp']['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPDebug  = SMTP::DEBUG_OFF;

            $mail->setFrom((string) $this->config['from']['address'], (string) $this->config['from']['name']);
            $mail->addAddress($to, $name);

            if (($this->config['bcc'] ?? '') !== '') {
                $mail->addBCC((string) $this->config['bcc']);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = self::toText($html);

            $mail->send();

            return ['sent' => true, 'driver' => 'smtp', 'error' => null];
        } catch (MailException $e) {
            return ['sent' => false, 'driver' => 'smtp', 'error' => $e->getMessage()];
        }
    }

    /** @return array{sent:bool,driver:string,error:?string} */
    private function toLog(string $to, string $name, string $subject, string $html): array
    {
        $directory = Config::get('app.paths.logs') . '/mail';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['sent' => false, 'driver' => 'log', 'error' => 'cannot write ' . $directory];
        }

        $path = $directory . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.html';

        $header = '<!-- to: ' . $name . ' <' . $to . '>' . "\n"
            . '     subject: ' . $subject . ' -->' . "\n";

        return file_put_contents($path, $header . $html) === false
            ? ['sent' => false, 'driver' => 'log', 'error' => 'write failed']
            : ['sent' => true, 'driver' => 'log', 'error' => null];
    }

    private static function toText(string $html): string
    {
        $spaced = preg_replace('#<(?:br|/p|/tr|/h[1-6])\s*/?>#i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
