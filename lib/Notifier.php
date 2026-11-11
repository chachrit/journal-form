<?php
declare(strict_types=1);

/** สร้างและส่งเมลแจ้งเตือนเมื่อมีคนส่งฟอร์ม ตาม schema.notify */
final class Notifier
{
    public static function submissionReceived(array $form, array $schema, array $submission, array $files): void
    {
        $notify = $schema['notify'] ?? [];
        $renderer = new FormRenderer($schema);
        $mailer = Mailer::fromConfig();

        $subject = self::subject($notify['subject'] ?? '[' . $schema['title'] . '] คำตอบใหม่จาก {submitter}', $schema, $submission, $files);
        $answers = $renderer->renderAnswers($submission['data'], $files, true);
        $meta = '<p>ผู้ส่ง: ' . e($submission['submitter_name']) . ' (' . e($submission['submitted_by']) . ')'
            . ($submission['submitter_dept'] ? ' — ' . e($submission['submitter_dept']) : '')
            . '<br>เวลา: ' . e(format_datetime($submission['created_at'])) . '</p>';

        $to = $notify['to'] ?? [];
        if ($to) {
            $link = self::absoluteUrl('admin/submission.php', ['id' => $submission['id']]);
            $html = '<h2>' . e($schema['title']) . '</h2>' . $meta . $answers
                . '<p><a href="' . e($link) . '">ดูคำตอบในระบบ</a> (ไฟล์แนบและลายเซ็นดูได้ในระบบ)</p>';
            $mailer->send($to, $notify['cc'] ?? [], $subject, $html, (int) $submission['id']);
        }

        if (($notify['send_copy_to_submitter'] ?? false) === true && !empty($submission['submitter_email'])) {
            $link = self::absoluteUrl('my.php', ['id' => $submission['id']]);
            $html = '<h2>' . e($schema['title']) . '</h2><p>ระบบได้รับคำตอบของคุณแล้ว รายละเอียดดังนี้</p>'
                . $meta . $answers . '<p><a href="' . e($link) . '">ดูคำตอบในระบบ</a></p>';
            $mailer->send([$submission['submitter_email']], [], 'สำเนา: ' . $subject, $html, (int) $submission['id']);
        }
    }

    /** แทนค่า {ชื่อช่อง} และ {submitter} ในหัวข้อเมล */
    public static function subject(string $template, array $schema, array $submission, array $files): string
    {
        $byName = [];
        foreach (FormSchema::inputFields($schema) as $field) {
            $byName[$field['name']] = $field;
        }
        $result = preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', function (array $m) use ($byName, $submission, $files) {
            if ($m[1] === 'submitter') {
                return (string) $submission['submitter_name'];
            }
            if (!isset($byName[$m[1]])) {
                return $m[0];
            }
            return FormRenderer::plainValue($byName[$m[1]], $submission['data'][$m[1]] ?? null, $files[$m[1]] ?? []);
        }, $template) ?? $template;
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $result) ?? $result), 0, 200);
    }

    private static function absoluteUrl(string $path, array $query): string
    {
        $base = rtrim((string) config('app.public_url', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . url($path, $query);
    }
}
