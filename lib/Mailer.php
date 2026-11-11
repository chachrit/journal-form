<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * ส่งเมลผ่าน SMTP ภายใน (PHPMailer)
 * mail.transport = 'file' → เขียนเมลเป็นไฟล์ HTML ใน storage/logs/mail/ แทนการส่งจริง (ใช้ตอนพัฒนา/ยังไม่มีค่า SMTP)
 */
final class Mailer
{
    public function __construct(
        private readonly array $cfg,
        private readonly string $fileDir,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('mail', []), APP_ROOT . '/storage/logs/mail');
    }

    /**
     * ส่งเมล — ไม่ throw: ผลลัพธ์ถูกบันทึกใน mail_log
     * @return bool ส่งสำเร็จหรือไม่
     */
    public function send(array $to, array $cc, string $subject, string $html, ?int $submissionId = null): bool
    {
        $to = self::cleanAddresses($to);
        $cc = array_values(array_diff(self::cleanAddresses($cc), $to));
        $subject = trim(preg_replace('/[\r\n\t]+/', ' ', $subject) ?? '');
        if (!$to) {
            return false;
        }
        $all = [...$to, ...$cc];

        try {
            if (($this->cfg['transport'] ?? 'file') === 'smtp') {
                $this->sendSmtp($to, $cc, $subject, $html);
            } else {
                $this->writeFile($to, $cc, $subject, $html);
            }
            FormRepository::logMail($submissionId, $all, $subject, 'sent', null);
            return true;
        } catch (Throwable $ex) {
            error_log('[Mailer] ' . $ex->getMessage());
            FormRepository::logMail($submissionId, $all, $subject, 'failed', $ex->getMessage());
            return false;
        }
    }

    private function sendSmtp(array $to, array $cc, string $subject, string $html): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) ($this->cfg['host'] ?? '');
        $mail->Port = (int) ($this->cfg['port'] ?? 25);
        $mail->SMTPAuth = (bool) ($this->cfg['auth'] ?? false);
        if ($mail->SMTPAuth) {
            $mail->Username = (string) ($this->cfg['username'] ?? '');
            $mail->Password = (string) ($this->cfg['password'] ?? '');
        }
        $secure = (string) ($this->cfg['secure'] ?? '');
        if ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = (bool) ($this->cfg['auto_tls'] ?? true);
        }
        if (!empty($this->cfg['allow_self_signed'])) {
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom((string) ($this->cfg['from'] ?? ''), (string) ($this->cfg['from_name'] ?? 'Journal Form'));
        foreach ($to as $addr) {
            $mail->addAddress($addr);
        }
        foreach ($cc as $addr) {
            $mail->addCC($addr);
        }
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['</tr>', '<br>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));

        try {
            $mail->send();
        } catch (MailException $ex) {
            throw new RuntimeException('SMTP: ' . $mail->ErrorInfo, 0, $ex);
        }
    }

    private function writeFile(array $to, array $cc, string $subject, string $html): void
    {
        if (!is_dir($this->fileDir)) {
            mkdir($this->fileDir, 0775, true);
        }
        $file = $this->fileDir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.html';
        $head = '<!-- To: ' . implode(', ', $to) . "\n     Cc: " . implode(', ', $cc) . "\n     Subject: "
            . str_replace('--', '- -', $subject) . " -->\n<meta charset=\"utf-8\">\n";
        if (file_put_contents($file, $head . $html) === false) {
            throw new RuntimeException('เขียนไฟล์เมลไม่ได้');
        }
    }

    private static function cleanAddresses(array $list): array
    {
        $out = [];
        foreach ($list as $addr) {
            $addr = is_string($addr) ? trim($addr) : '';
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($addr)] = $addr;
            }
        }
        return array_values($out);
    }
}
