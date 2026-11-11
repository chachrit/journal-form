<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

$user = Auth::requireAdmin();

$id = query_int('id');
$form = $id ? FormRepository::find($id) : null;
if ($id && $form === null) {
    abort(404, 'ไม่พบฟอร์ม');
}
$isNew = $form === null;

$starter = <<<'JSON'
{
  "title": "ชื่อฟอร์ม",
  "description": "คำอธิบายฟอร์ม (ไม่ใส่ก็ได้)",
  "success_message": "ส่งฟอร์มเรียบร้อยแล้ว",
  "notify": {
    "to": [],
    "subject": "[ชื่อฟอร์ม] คำตอบใหม่จาก {submitter}",
    "send_copy_to_submitter": false
  },
  "fields": [
    {"name": "fullname", "type": "text", "label": "ชื่อ-สกุล", "default": "{user.name}", "readonly": true},
    {"name": "department", "type": "text", "label": "แผนก", "default": "{user.department}"},
    {"name": "detail", "type": "textarea", "label": "รายละเอียด", "required": true}
  ]
}
JSON;

$slug = $form['slug'] ?? '';
$schemaText = $form['draft_json'] ?? $starter;
$errors = [];

if (is_post()) {
    Csrf::check();
    $action = post_str('action');
    $schemaText = str_replace("\r\n", "\n", (string) ($_POST['schema'] ?? ''));

    if ($isNew) {
        $slug = strtolower(post_str('slug'));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,98}$/', $slug)) {
            $errors[] = 'ลิงก์ฟอร์ม (slug) ใช้ได้แค่ a-z, 0-9 และ - ยาว 2–99 ตัว';
        } elseif (FormRepository::slugExists($slug)) {
            $errors[] = "ลิงก์ฟอร์ม \"$slug\" ถูกใช้แล้ว";
        }
    }

    $check = FormSchema::validateJson($schemaText);
    $errors = array_merge($errors, $check['errors']);

    if (!$errors) {
        $title = mb_substr(trim($check['schema']['title']), 0, 255);
        $userId = (int) $user['employee_id'];
        if ($isNew) {
            $id = FormRepository::create($slug, $title, $schemaText, $userId);
        } else {
            FormRepository::saveDraft($id, $title, $schemaText, $userId);
        }

        if ($action === 'publish') {
            $version = FormRepository::publish($id, $userId);
            flash('success', "เผยแพร่เวอร์ชัน v$version แล้ว ลิงก์ฟอร์ม: " . url('f/' . $slug));
        } else {
            flash('success', 'บันทึกฉบับร่างแล้ว (ผู้กรอกยังเห็นเวอร์ชันเดิมจนกว่าจะกดเผยแพร่)');
        }
        redirect('admin/form_edit.php', ['id' => $id]);
    }
}

$pageTitle = $isNew ? 'สร้างฟอร์มใหม่' : 'แก้ไข: ' . $form['title'];
$wide = true;
$scripts = ['assets/js/form.js', 'assets/js/editor.js'];
require __DIR__ . '/../templates/header.php';
?>
<p><a href="<?= e(url('admin/forms.php')) ?>">← รายการฟอร์ม</a></p>
<div class="page-head">
    <h1><?= e($pageTitle) ?></h1>
    <?php if (!$isNew): ?>
        <span class="muted">
            เวอร์ชันที่เผยแพร่: <?= (int) $form['current_version'] > 0 ? 'v' . e($form['current_version']) : 'ยังไม่เผยแพร่' ?>
            · <a href="<?= e(url('admin/submissions.php', ['form' => $form['id']])) ?>">ดูคำตอบ</a>
        </span>
    <?php endif; ?>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <strong>บันทึกไม่ได้ — แก้ไขสิ่งต่อไปนี้ก่อน:</strong>
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" class="editor" data-editor data-preview-url="<?= e(url('admin/form_preview.php')) ?>">
    <?= Csrf::field() ?>
    <div class="editor-pane">
        <?php if ($isNew): ?>
            <div class="field">
                <label class="label" for="slug">ลิงก์ฟอร์ม</label>
                <div class="input-prefix">
                    <span><?= e(url('f/')) ?></span>
                    <input type="text" id="slug" name="slug" value="<?= e($slug) ?>" required pattern="[a-z0-9][a-z0-9\-]{1,98}" placeholder="it-request">
                </div>
                <p class="help">a-z, 0-9 และ - เท่านั้น เปลี่ยนภายหลังไม่ได้</p>
            </div>
        <?php endif; ?>
        <label class="label" for="schema">Form schema (JSON)</label>
        <textarea id="schema" name="schema" class="code" spellcheck="false" data-schema><?= e($schemaText) ?></textarea>
        <div class="editor-status" data-editor-status></div>
        <div class="actions">
            <button type="submit" name="action" value="save" class="btn">บันทึกฉบับร่าง</button>
            <button type="submit" name="action" value="publish" class="btn btn-primary">บันทึกและเผยแพร่</button>
        </div>

        <details class="reference">
            <summary>ตัวอย่างช่องแต่ละประเภท (คลิกเพื่อดู)</summary>
            <pre class="code-sample">
{"name": "fullname", "type": "text", "label": "ชื่อ-สกุล", "required": true}
{"name": "detail", "type": "textarea", "label": "รายละเอียด", "maxlength": 2000}
{"name": "qty", "type": "number", "label": "จำนวน", "min": 1, "max": 100}
{"name": "email", "type": "email", "label": "อีเมล", "default": "{user.email}"}
{"name": "phone", "type": "tel", "label": "เบอร์โทร", "pattern": "0[0-9]{8,9}", "pattern_message": "เบอร์โทร 9–10 หลัก"}
{"name": "start_date", "type": "date", "label": "วันที่เริ่ม", "default": "{today}"}
{"name": "start_time", "type": "time", "label": "เวลา"}
{"name": "check_in", "type": "datetime", "label": "วันเวลาเข้างาน"}
{"name": "dept", "type": "select", "label": "แผนก", "options": ["IT", "HR", "บัญชี"]}
{"name": "type", "type": "radio", "label": "ประเภท", "options": [{"value": "new", "label": "ขอใหม่"}, {"value": "fix", "label": "แจ้งซ่อม"}]}
{"name": "items", "type": "checkbox", "label": "อุปกรณ์", "options": ["เมาส์", "คีย์บอร์ด", "จอ"], "columns": 2}
{"name": "doc", "type": "file", "label": "แนบไฟล์", "accept": ".pdf,.jpg,.png", "max_size_mb": 5, "multiple": true}
{"name": "photo", "type": "file", "label": "ถ่ายรูป", "accept": "image/*", "capture": true}   ← มือถือเปิดกล้องทันที
{"name": "sign", "type": "signature", "label": "ลายเซ็นผู้ขอ", "required": true}
{"type": "heading", "label": "ส่วนที่ 2: รายละเอียดเพิ่มเติม"}
{"type": "paragraph", "label": "ข้อความอธิบาย แสดงอย่างเดียว"}

<b>แสดงช่องตามเงื่อนไข (show_if)</b> — อ้างถึงช่องที่อยู่ก่อนหน้าเท่านั้น
"show_if": {"field": "type", "op": "eq", "value": "fix"}
"show_if": {"field": "items", "op": "contains", "value": "จอ"}
"show_if": {"field": "qty", "op": "gt", "value": 10}
"show_if": {"all": [{"field": "a", "op": "eq", "value": "x"}, {"field": "b", "op": "not_empty"}]}
"show_if": {"any": [ ... ]}
op: eq, neq, in, not_in, gt, lt, contains, empty, not_empty

<b>ค่าเริ่มต้นจากผู้ login</b> (ใช้ใน "default")
{user.name} {user.email} {user.employee_id} {user.department} {user.division} {today} {now}
ใส่ "readonly": true เพื่อให้แก้ไม่ได้ (ระบบใช้ค่าจริงของผู้ login เสมอ)

<b>หัวข้อเมล</b> ใส่ค่าจากช่องได้ด้วย {ชื่อช่อง} และ {submitter}
</pre>
        </details>
    </div>

    <div class="preview-pane">
        <div class="preview-label">ตัวอย่างฟอร์ม (อัปเดตอัตโนมัติ)</div>
        <div class="card preview" data-preview>
            <p class="muted">กำลังโหลด…</p>
        </div>
    </div>
</form>
<?php require __DIR__ . '/../templates/footer.php'; ?>
