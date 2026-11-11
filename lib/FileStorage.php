<?php
declare(strict_types=1);

/**
 * เก็บไฟล์แนบและลายเซ็นใน storage/ (เข้าจากเว็บตรงไม่ได้)
 * ชื่อไฟล์สุ่มใหม่ทั้งหมด แยกโฟลเดอร์ตามปี/เดือน
 */
final class FileStorage
{
    public function __construct(private readonly string $baseDir)
    {
    }

    public static function default(): self
    {
        return new self(APP_ROOT . '/storage');
    }

    /**
     * @param array $upload จาก SubmissionValidator (tmp_name, name, size, mime, ext)
     * @return array{stored_path: string, original_name: string, mime_type: string, size_bytes: int}
     */
    public function saveUpload(array $upload): array
    {
        $rel = $this->newPath('uploads', $upload['ext']);
        $abs = $this->absolute($rel);
        $moved = defined('JF_TESTING') ? copy($upload['tmp_name'], $abs) : move_uploaded_file($upload['tmp_name'], $abs);
        if (!$moved) {
            throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ: ' . $upload['name']);
        }
        return ['stored_path' => $rel, 'original_name' => $upload['name'], 'mime_type' => $upload['mime'], 'size_bytes' => (int) $upload['size']];
    }

    /** @return array{stored_path: string, original_name: string, mime_type: string, size_bytes: int} */
    public function saveSignature(string $png): array
    {
        $rel = $this->newPath('signatures', 'png');
        if (file_put_contents($this->absolute($rel), $png, LOCK_EX) === false) {
            throw new RuntimeException('บันทึกลายเซ็นไม่สำเร็จ');
        }
        return ['stored_path' => $rel, 'original_name' => 'signature.png', 'mime_type' => 'image/png', 'size_bytes' => strlen($png)];
    }

    /** path จริงบนดิสก์ — กัน path traversal */
    public function absolute(string $relative): string
    {
        if (!preg_match('#^(uploads|signatures)/\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,5}$#', $relative)) {
            throw new RuntimeException('path ไม่ถูกต้อง');
        }
        return $this->baseDir . '/' . $relative;
    }

    public function delete(string $relative): void
    {
        try {
            @unlink($this->absolute($relative));
        } catch (RuntimeException) {
        }
    }

    private function newPath(string $kind, string $ext): string
    {
        $dir = $kind . '/' . date('Y') . '/' . date('m');
        if (!is_dir($this->baseDir . '/' . $dir) && !mkdir($this->baseDir . '/' . $dir, 0775, true) && !is_dir($this->baseDir . '/' . $dir)) {
            throw new RuntimeException('สร้างโฟลเดอร์เก็บไฟล์ไม่ได้');
        }
        return $dir . '/' . bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    }
}
