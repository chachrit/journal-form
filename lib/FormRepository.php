<?php
declare(strict_types=1);

/** อ่าน/เขียนข้อมูลฟอร์ม คำตอบ ไฟล์ และสิทธิ์ ใน MSSQL */
final class FormRepository
{
    // ----------------------------------------------------------------- forms

    /** ฟอร์มที่เปิดให้กรอก */
    public static function published(): array
    {
        return Db::all(
            "SELECT id, slug, title FROM forms WHERE status = 'published' AND current_version > 0 ORDER BY title"
        );
    }

    /** ทุกฟอร์ม + จำนวนคำตอบ (หน้าแอดมิน) */
    public static function allWithCounts(): array
    {
        return Db::all(
            'SELECT f.id, f.slug, f.title, f.status, f.current_version, f.updated_at,
                    CASE WHEN f.current_version = 0 THEN 1
                         WHEN f.draft_json <> v.schema_json THEN 1 ELSE 0 END AS has_draft_changes,
                    (SELECT COUNT(*) FROM submissions s WHERE s.form_id = f.id) AS submission_count,
                    (SELECT MAX(created_at) FROM submissions s WHERE s.form_id = f.id) AS last_submission_at
             FROM forms f
             LEFT JOIN form_versions v ON v.form_id = f.id AND v.version = f.current_version
             ORDER BY f.title'
        );
    }

    /** ฟอร์มที่ผู้ใช้คนนี้ได้รับสิทธิ์ดูคำตอบ */
    public static function viewableBy(int $employeeId): array
    {
        return Db::all(
            'SELECT f.id, f.slug, f.title, f.status,
                    (SELECT COUNT(*) FROM submissions s WHERE s.form_id = f.id) AS submission_count
             FROM forms f JOIN form_permissions p ON p.form_id = f.id
             WHERE p.employee_id = ? ORDER BY f.title',
            [$employeeId]
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM forms WHERE id = ?', [$id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return Db::one('SELECT * FROM forms WHERE slug = ?', [$slug]);
    }

    public static function slugExists(string $slug): bool
    {
        return Db::value('SELECT COUNT(*) FROM forms WHERE slug = ?', [$slug]) > 0;
    }

    /** schema ของเวอร์ชันที่ระบุ (decode แล้ว) */
    public static function schema(int $formId, int $version): ?array
    {
        $json = Db::value('SELECT schema_json FROM form_versions WHERE form_id = ? AND version = ?', [$formId, $version]);
        return is_string($json) ? json_decode($json, true) : null;
    }

    /** schema ทุกเวอร์ชันของฟอร์ม version => schema */
    public static function allSchemas(int $formId): array
    {
        $out = [];
        foreach (Db::all('SELECT version, schema_json FROM form_versions WHERE form_id = ? ORDER BY version', [$formId]) as $row) {
            $out[(int) $row['version']] = json_decode($row['schema_json'], true);
        }
        return $out;
    }

    public static function create(string $slug, string $title, string $schemaJson, int $userId): int
    {
        return Db::insert(
            "INSERT INTO forms (slug, title, status, current_version, draft_json, created_by, updated_by)
             OUTPUT INSERTED.id VALUES (?, ?, 'draft', 0, ?, ?, ?)",
            [$slug, $title, $schemaJson, $userId, $userId]
        );
    }

    public static function saveDraft(int $formId, string $title, string $schemaJson, int $userId): void
    {
        Db::exec(
            'UPDATE forms SET title = ?, draft_json = ?, updated_by = ?, updated_at = SYSDATETIME() WHERE id = ?',
            [$title, $schemaJson, $userId, $formId]
        );
    }

    /** เผยแพร่ฉบับร่างเป็นเวอร์ชันใหม่ คืนเลขเวอร์ชัน */
    public static function publish(int $formId, int $userId): int
    {
        return Db::transaction(function () use ($formId, $userId) {
            $form = Db::one('SELECT current_version, draft_json FROM forms WITH (UPDLOCK) WHERE id = ?', [$formId]);
            if ($form === null) {
                throw new RuntimeException('ไม่พบฟอร์ม');
            }
            $version = (int) $form['current_version'] + 1;
            Db::exec(
                'INSERT INTO form_versions (form_id, version, schema_json, created_by) VALUES (?, ?, ?, ?)',
                [$formId, $version, $form['draft_json'], $userId]
            );
            Db::exec(
                "UPDATE forms SET current_version = ?, status = 'published', updated_by = ?, updated_at = SYSDATETIME() WHERE id = ?",
                [$version, $userId, $formId]
            );
            return $version;
        });
    }

    public static function setStatus(int $formId, string $status, int $userId): void
    {
        if (!in_array($status, ['published', 'closed'], true)) {
            throw new InvalidArgumentException('status ไม่ถูกต้อง');
        }
        Db::exec(
            'UPDATE forms SET status = ?, updated_by = ?, updated_at = SYSDATETIME() WHERE id = ? AND current_version > 0',
            [$status, $userId, $formId]
        );
    }

    // ----------------------------------------------------------- submissions

    /**
     * บันทึกคำตอบ + ไฟล์ใน transaction เดียว
     * @param array $files list ของ [field_name, kind, stored_path, original_name, mime_type, size_bytes]
     */
    public static function createSubmission(array $form, array $user, array $values, array $files, string $ip): int
    {
        return Db::transaction(function () use ($form, $user, $values, $files, $ip) {
            $id = Db::insert(
                'INSERT INTO submissions (form_id, form_version, data_json, submitted_by, submitter_name, submitter_email, submitter_dept, ip_address)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $form['id'], $form['current_version'],
                    json_encode((object) $values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $user['employee_id'], $user['name'], $user['email'], $user['department'], $ip,
                ]
            );
            foreach ($files as $f) {
                Db::exec(
                    'INSERT INTO submission_files (submission_id, field_name, kind, original_name, stored_path, mime_type, size_bytes)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, $f['field_name'], $f['kind'], $f['original_name'], $f['stored_path'], $f['mime_type'], $f['size_bytes']]
                );
            }
            return $id;
        });
    }

    public static function submission(int $id): ?array
    {
        $row = Db::one('SELECT * FROM submissions WHERE id = ?', [$id]);
        if ($row !== null) {
            $row['data'] = json_decode($row['data_json'], true) ?: [];
        }
        return $row;
    }

    /** ไฟล์ของคำตอบ จัดกลุ่มตาม field_name */
    public static function submissionFiles(int $submissionId): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM submission_files WHERE submission_id = ? ORDER BY id', [$submissionId]) as $f) {
            $out[$f['field_name']][] = $f;
        }
        return $out;
    }

    /** ไฟล์ของหลายคำตอบ submission_id => field_name => list */
    public static function filesForSubmissions(array $submissionIds): array
    {
        if (!$submissionIds) {
            return [];
        }
        $out = [];
        // แบ่งเป็นชุด เพราะ MSSQL จำกัดจำนวนพารามิเตอร์ (2100)
        foreach (array_chunk(array_values($submissionIds), 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Db::all("SELECT * FROM submission_files WHERE submission_id IN ($in) ORDER BY id", $chunk) as $f) {
                $out[(int) $f['submission_id']][$f['field_name']][] = $f;
            }
        }
        return $out;
    }

    public static function file(int $id): ?array
    {
        return Db::one(
            'SELECT sf.*, s.form_id, s.submitted_by FROM submission_files sf
             JOIN submissions s ON s.id = sf.submission_id WHERE sf.id = ?',
            [$id]
        );
    }

    /**
     * @param array{from?: string, to?: string, q?: string} $filter
     */
    public static function submissions(int $formId, array $filter, int $limit, int $offset): array
    {
        [$where, $params] = self::submissionFilter($formId, $filter);
        $rows = Db::all(
            "SELECT * FROM submissions WHERE $where ORDER BY created_at DESC, id DESC
             OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY",
            $params
        );
        foreach ($rows as &$row) {
            $row['data'] = json_decode($row['data_json'], true) ?: [];
        }
        return $rows;
    }

    public static function countSubmissions(int $formId, array $filter): int
    {
        [$where, $params] = self::submissionFilter($formId, $filter);
        return (int) Db::value("SELECT COUNT(*) FROM submissions WHERE $where", $params);
    }

    /** วนอ่านคำตอบทีละชุด (ใช้ตอน export ไม่ให้กินหน่วยความจำ) */
    public static function eachSubmission(int $formId, array $filter, callable $fn, int $batch = 500): void
    {
        [$where, $params] = self::submissionFilter($formId, $filter);
        $lastId = 0;
        while (true) {
            $rows = Db::all(
                "SELECT TOP $batch * FROM submissions WHERE $where AND id > ? ORDER BY id",
                [...$params, $lastId]
            );
            if (!$rows) {
                return;
            }
            $files = self::filesForSubmissions(array_map(fn ($r) => (int) $r['id'], $rows));
            foreach ($rows as $row) {
                $row['data'] = json_decode($row['data_json'], true) ?: [];
                $fn($row, $files[(int) $row['id']] ?? []);
                $lastId = (int) $row['id'];
            }
        }
    }

    private static function submissionFilter(int $formId, array $filter): array
    {
        $where = 'form_id = ?';
        $params = [$formId];
        if (!empty($filter['from']) && FormSchema::isDate($filter['from'])) {
            $where .= ' AND created_at >= ?';
            $params[] = $filter['from'];
        }
        if (!empty($filter['to']) && FormSchema::isDate($filter['to'])) {
            $where .= ' AND created_at < DATEADD(day, 1, CAST(? AS date))';
            $params[] = $filter['to'];
        }
        if (!empty($filter['q'])) {
            $where .= " AND (submitter_name LIKE ? ESCAPE '\\' OR CAST(submitted_by AS NVARCHAR(20)) = ? OR data_json LIKE ? ESCAPE '\\')";
            $like = '%' . addcslashes($filter['q'], '%_[\\') . '%';
            array_push($params, $like, $filter['q'], $like);
        }
        return [$where, $params];
    }

    /** คำตอบล่าสุดของผู้ใช้ */
    public static function mySubmissions(int $employeeId, int $limit = 20): array
    {
        return Db::all(
            "SELECT TOP $limit s.id, s.created_at, s.status, f.title, f.slug
             FROM submissions s JOIN forms f ON f.id = s.form_id
             WHERE s.submitted_by = ? ORDER BY s.created_at DESC, s.id DESC",
            [$employeeId]
        );
    }

    // ----------------------------------------------------------- permissions

    public static function permissions(int $formId): array
    {
        return Db::all('SELECT employee_id, role, created_at FROM form_permissions WHERE form_id = ? ORDER BY employee_id', [$formId]);
    }

    public static function hasPermission(int $formId, int $employeeId): bool
    {
        return Db::value('SELECT COUNT(*) FROM form_permissions WHERE form_id = ? AND employee_id = ?', [$formId, $employeeId]) > 0;
    }

    public static function grant(int $formId, int $employeeId, int $byUser): void
    {
        if (!self::hasPermission($formId, $employeeId)) {
            Db::exec(
                "INSERT INTO form_permissions (form_id, employee_id, role, created_by) VALUES (?, ?, 'viewer', ?)",
                [$formId, $employeeId, $byUser]
            );
        }
    }

    public static function revoke(int $formId, int $employeeId): void
    {
        Db::exec('DELETE FROM form_permissions WHERE form_id = ? AND employee_id = ?', [$formId, $employeeId]);
    }

    public static function isAdmin(int $employeeId): bool
    {
        return Db::value('SELECT COUNT(*) FROM app_admins WHERE employee_id = ?', [$employeeId]) > 0;
    }

    // ------------------------------------------------------------------ mail

    public static function logMail(?int $submissionId, array $recipients, string $subject, string $status, ?string $error): void
    {
        try {
            Db::exec(
                'INSERT INTO mail_log (submission_id, recipients, subject, status, error) VALUES (?, ?, ?, ?, ?)',
                [$submissionId, implode(', ', $recipients), mb_substr($subject, 0, 500), $status, $error]
            );
        } catch (Throwable $ex) {
            error_log('[mail_log] ' . $ex->getMessage());
        }
    }
}
