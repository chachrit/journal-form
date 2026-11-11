# Journal Form — เอกสารออกแบบ (เฟส 1)

ระบบฟอร์มภายในองค์กร ใช้แทน Jotform ฟอร์มสร้างจาก JSON schema โดยทีม IT

- อัปเดตล่าสุด: 2026-11-11
- สถานะ: ร่าง รอตรวจ

## 1. ขอบเขต

**เฟส 1 (ทำตอนนี้)**
- Login ผ่าน Employee API (`auth.php`)
- แสดงฟอร์มจาก JSON schema พร้อม condition (show_if)
- ประเภทช่อง: text, textarea, number, email, tel, date, time, select, radio, checkbox, file, signature, heading, paragraph
- ตรวจข้อมูลฝั่ง server ตาม schema
- บันทึกคำตอบลง MSSQL และเก็บไฟล์แนบ/ลายเซ็นใน storage
- เมลแจ้งเตือนผ่าน SMTP ภายใน (ส่งถึงผู้รับที่กำหนด และส่งสำเนาให้ผู้กรอกได้)
- หน้าแอดมิน: รายการฟอร์ม, สร้าง/แก้ JSON พร้อม preview, เปิด/ปิดฟอร์ม, ดูคำตอบ, export CSV, กำหนดสิทธิ์ดูคำตอบรายฟอร์ม

**เฟส 2 (ภายหลัง — เตรียมโครงไว้แล้ว)**
- Workflow อนุมัติ
- Export คำตอบเป็น PDF
- หน้าสร้างฟอร์มแบบตาราง (สร้าง JSON ให้อัตโนมัติ)

**ไม่ทำ**
- หน้าลากวางแบบ Jotform
- ย้ายคำตอบเก่าจาก Jotform

## 2. เทคโนโลยี

| ส่วน | เลือกใช้ |
|---|---|
| ภาษา | PHP 8.2 ไม่ใช้ framework |
| ฐานข้อมูล | MSSQL ผ่าน PDO `sqlsrv` |
| Package | Composer: `phpmailer/phpmailer`, `phpunit/phpunit` (dev) |
| Frontend | HTML + CSS + JS เปล่า, `signature_pad` สำหรับลายเซ็น |
| Web server | Apache (XAMPP) + `.htaccess` |

## 3. โครงสร้างโฟลเดอร์

แบบ **1 หน้า = 1 ไฟล์** ส่วน logic ที่ใช้ร่วมกันอยู่ใน `lib/`

```
journal-form/
├─ .htaccess              # URL สวย (/f/{slug} → form.php) + บล็อกโฟลเดอร์ภายใน
├─ .env.example           # ตัวอย่างค่า .env (commit ได้)
├─ .env                   # ค่าจริง: DB, API key, SMTP (ห้าม commit)
├─ config.php             # อ่านค่าจาก .env + กำหนด default (commit ได้)
│
├─ index.php              # หน้าแรก: ฟอร์มที่เปิดอยู่ + คำตอบของฉัน
├─ login.php
├─ logout.php
├─ form.php               # กรอกฟอร์ม (GET แสดง / POST ส่ง)
├─ done.php               # หน้าส่งสำเร็จ
├─ my.php                 # ดูคำตอบของตัวเอง
├─ file.php               # ดาวน์โหลดไฟล์แนบ (เช็กสิทธิ์)
├─ admin/
│  ├─ forms.php               # รายการฟอร์ม
│  ├─ form_edit.php           # สร้าง/แก้ JSON + preview
│  ├─ form_preview.php        # endpoint ให้ preview เรียก (AJAX)
│  ├─ form_permissions.php    # ให้สิทธิ์ดูคำตอบ
│  ├─ submissions.php         # ตารางคำตอบของฟอร์ม
│  ├─ submission.php          # รายละเอียดคำตอบ
│  └─ export.php              # CSV
│
├─ lib/
│  ├─ bootstrap.php           # ทุกหน้า require ไฟล์นี้: โหลด config, autoload, session, helper
│  ├─ helpers.php             # e() escape, redirect(), flash() ฯลฯ
│  ├─ Db.php                  # เชื่อม MSSQL + query helper
│  ├─ Auth.php                # session ผู้ใช้, requireLogin(), requireAdmin(), canViewForm()
│  ├─ Csrf.php
│  ├─ EmployeeApi.php         # เรียก auth.php / employee.php
│  ├─ FormRepository.php      # อ่าน/เขียนตาราง forms, versions, submissions
│  ├─ FormSchema.php          # ตรวจว่า JSON ของฟอร์มถูกต้อง
│  ├─ FormRenderer.php        # schema → HTML
│  ├─ ConditionEvaluator.php  # ประเมิน show_if ฝั่ง PHP
│  ├─ SubmissionValidator.php
│  ├─ FileStorage.php         # อัปโหลด/ลายเซ็น
│  ├─ Mailer.php              # ส่งเมล (SMTP หรือเขียนลงไฟล์)
│  └─ Notifier.php            # สร้างเนื้อหาเมลแจ้งเตือนเมื่อมีคนส่งฟอร์ม
│
├─ templates/             # header.php, footer.php, ชิ้นส่วน HTML ที่ใช้ซ้ำ
├─ assets/                # css, js (form.js = condition ฝั่ง browser, signature_pad)
├─ storage/               # uploads/, signatures/, cache/, logs/  (เข้าจากเว็บตรงไม่ได้)
├─ database/              # สคริปต์ SQL สร้างตาราง
├─ tests/                 # PHPUnit (ทดสอบไฟล์ใน lib/)
└─ docs/
```

รูปแบบของทุกหน้า:
```php
<?php
require __DIR__ . '/lib/bootstrap.php';
Auth::requireLogin();

// 1. อ่าน input / ดึงข้อมูล (เรียก class ใน lib/)
// 2. ถ้าเป็น POST → ตรวจ, บันทึก, redirect
// 3. แสดงผล
require __DIR__ . '/templates/header.php';
?>
<!-- HTML ของหน้านี้ -->
<?php require __DIR__ . '/templates/footer.php'; ?>
```

## 4. Login และสิทธิ์

### ขั้นตอน login
1. ผู้ใช้กรอก `employee_id` + รหัสผ่านในหน้า login ของเรา
2. Backend ของเราเรียก `POST /api/auth.php` (แนบ `X-API-KEY` จาก config)
3. สำเร็จ → เก็บใน session: `employee_id`, `name`, `email`, `token`, เวลาหมดอายุ (8 ชม.)
4. ดึงแผนก/ฝ่ายจาก `employee.php` (cache รายชื่อพนักงานทั้งหมดไว้ในไฟล์ 1 ชม. แล้วค้นด้วย `employee_id`)
5. Session หมดอายุพร้อม token (8 ชม.) แล้วต้อง login ใหม่

ระบบไม่เก็บรหัสผ่าน API key และ token อยู่ฝั่ง server เท่านั้น ตามข้อกำหนดในคู่มือ

### บทบาท
| บทบาท | ทำอะไรได้ | เก็บที่ |
|---|---|---|
| ผู้ใช้ทั่วไป | กรอกฟอร์มที่เปิดอยู่ และดูคำตอบของตัวเอง | ทุกคนที่ login ได้ |
| Form viewer | ดูคำตอบและ export ฟอร์มที่ได้รับสิทธิ์ | `form_permissions` |
| Admin (IT) | สร้าง/แก้ทุกฟอร์ม, ดูทุกคำตอบ, ให้สิทธิ์ | `app_admins` |

ตัวอย่าง: ให้ HR ดูคำตอบได้แค่ฟอร์มลา ไม่เห็นฟอร์มขอเบิกอุปกรณ์ IT

## 5. ฐานข้อมูล (MSSQL)

```sql
app_admins (
  employee_id   INT PRIMARY KEY,
  created_at    DATETIME2 DEFAULT SYSDATETIME()
)

forms (
  id            INT IDENTITY PRIMARY KEY,
  slug          NVARCHAR(100) NOT NULL UNIQUE,   -- ใช้ใน URL: /f/it-request
  title         NVARCHAR(255) NOT NULL,
  status        NVARCHAR(20)  NOT NULL DEFAULT 'draft',  -- draft | published | closed
  current_version INT NOT NULL DEFAULT 0,
  draft_json    NVARCHAR(MAX) NOT NULL,          -- schema ที่กำลังแก้ ยังไม่เผยแพร่
  created_by    INT NOT NULL,
  created_at    DATETIME2 DEFAULT SYSDATETIME(),
  updated_at    DATETIME2 DEFAULT SYSDATETIME()
)

form_versions (                                   -- เก็บ schema ทุกครั้งที่เผยแพร่
  form_id       INT NOT NULL REFERENCES forms(id),
  version       INT NOT NULL,
  schema_json   NVARCHAR(MAX) NOT NULL,
  created_by    INT NOT NULL,
  created_at    DATETIME2 DEFAULT SYSDATETIME(),
  PRIMARY KEY (form_id, version)
)

form_permissions (
  form_id       INT NOT NULL REFERENCES forms(id),
  employee_id   INT NOT NULL,
  role          NVARCHAR(20) NOT NULL DEFAULT 'viewer',
  PRIMARY KEY (form_id, employee_id)
)

submissions (
  id            BIGINT IDENTITY PRIMARY KEY,
  form_id       INT NOT NULL REFERENCES forms(id),
  form_version  INT NOT NULL,
  data_json     NVARCHAR(MAX) NOT NULL,
  status        NVARCHAR(20) NOT NULL DEFAULT 'submitted',  -- เผื่อเฟส 2: pending/approved/rejected
  submitted_by  INT NOT NULL,             -- employee_id
  submitter_name  NVARCHAR(255),
  submitter_email NVARCHAR(255),
  submitter_dept  NVARCHAR(255),
  ip_address    NVARCHAR(45),
  created_at    DATETIME2 DEFAULT SYSDATETIME()
)
INDEX ix_submissions_form (form_id, created_at)
INDEX ix_submissions_user (submitted_by, created_at)

submission_files (
  id            BIGINT IDENTITY PRIMARY KEY,
  submission_id BIGINT NOT NULL REFERENCES submissions(id),
  field_name    NVARCHAR(100) NOT NULL,
  kind          NVARCHAR(20) NOT NULL,    -- file | signature
  original_name NVARCHAR(255),
  stored_path   NVARCHAR(500) NOT NULL,
  mime_type     NVARCHAR(100),
  size_bytes    BIGINT
)

mail_log (
  id            BIGINT IDENTITY PRIMARY KEY,
  submission_id BIGINT NULL,
  recipients    NVARCHAR(MAX),
  subject       NVARCHAR(500),
  status        NVARCHAR(20),             -- sent | failed
  error         NVARCHAR(MAX),
  created_at    DATETIME2 DEFAULT SYSDATETIME()
)
```

**ทำไมต้องมี version:** ถ้าแก้ฟอร์ม (เช่น ลบช่อง หรือเปลี่ยนชื่อช่อง) คำตอบเก่ายังแสดงได้ถูกต้อง เพราะแต่ละคำตอบผูกกับ schema version ที่ใช้ตอนกรอก

**เฟส 2:** เพิ่มตาราง `approvals` (submission_id, step, approver_id, decision, comment, decided_at) และใช้คอลัมน์ `submissions.status` ที่เตรียมไว้แล้ว

## 6. Form schema (JSON)

### ระดับฟอร์ม
```json
{
  "title": "ขอเบิกอุปกรณ์ IT",
  "description": "กรอกเพื่อขอเบิกอุปกรณ์ ทีม IT จะติดต่อกลับภายใน 2 วันทำการ",
  "success_message": "ส่งคำขอเรียบร้อย",
  "notify": {
    "to": ["it@example.com"],
    "cc": [],
    "subject": "[ขอเบิกอุปกรณ์] {fullname} - {item}",
    "send_copy_to_submitter": true
  },
  "fields": [ ... ]
}
```

### ระดับช่อง
| Property | ใช้กับ | ความหมาย |
|---|---|---|
| `name` | ทุกช่อง | ชื่อภายใน (a-z, 0-9, _) ต้องไม่ซ้ำในฟอร์ม |
| `type` | ทุกช่อง | ประเภทช่อง |
| `label` | ทุกช่อง | ข้อความที่ผู้ใช้เห็น |
| `required` | ช่องกรอก | บังคับกรอก |
| `help` | ช่องกรอก | คำอธิบายใต้ช่อง |
| `placeholder` | text, textarea, number, email, tel | |
| `default` | ช่องกรอก | ค่าเริ่มต้น รองรับ `{user.name}`, `{user.email}`, `{user.employee_id}`, `{user.department}`, `{today}` |
| `readonly` | ช่องกรอก | ใช้คู่กับ `default` เช่น แสดงชื่อผู้กรอกแบบแก้ไม่ได้ |
| `options` | select, radio, checkbox | `["A","B"]` หรือ `[{"value":"a","label":"A"}]` |
| `min` / `max` | number, date | |
| `maxlength` | text, textarea | |
| `pattern` | text, tel | regex |
| `accept` | file | เช่น `".pdf,.jpg,.png"` |
| `max_size_mb` | file | ค่าเริ่มต้น 10 |
| `multiple` | file | หลายไฟล์ |
| `show_if` | ทุกช่อง | เงื่อนไขการแสดง (ดูด้านล่าง) |

### เงื่อนไข `show_if`
```json
{"field": "item", "op": "eq", "value": "อื่นๆ"}
{"all": [ {"field": "a", "op": "eq", "value": "x"}, {"field": "b", "op": "not_empty"} ]}
{"any": [ ... ]}
```
Operator: `eq`, `neq`, `in`, `not_in`, `empty`, `not_empty`, `gt`, `lt`, `contains` (สำหรับ checkbox)

**กฎสำคัญ:** เงื่อนไขประเมินทั้ง 2 ฝั่ง
- ฝั่ง JS (`form.js`) ซ่อน/แสดงช่องให้ผู้ใช้เห็น
- ฝั่ง PHP (`ConditionEvaluator`) ประเมินซ้ำตอนส่ง ช่องที่ถูกซ่อนจะไม่ตรวจ required และไม่บันทึกค่า (กันการแก้ HTML เพื่อส่งค่าที่ไม่ควรมี)

### ตรวจ schema ก่อนบันทึก
`FormSchema` ตรวจทุกครั้งที่แอดมินบันทึก: JSON ถูก syntax, `name` ไม่ซ้ำ, `type` รู้จัก, `options` มีครบสำหรับ select/radio/checkbox, `show_if` อ้างถึงช่องที่มีอยู่และอยู่ก่อนหน้า แสดง error พร้อมบอกตำแหน่งช่องที่ผิด

## 7. หน้าเว็บ

| ไฟล์ / URL | หน้า | สิทธิ์ |
|---|---|---|
| `login.php`, `logout.php` | Login | ทุกคน |
| `index.php` | รายการฟอร์มที่เปิดอยู่ + คำตอบของฉัน | ผู้ใช้ |
| `form.php?slug=…` (หรือ `/f/{slug}`) | กรอกฟอร์ม | ผู้ใช้ |
| `done.php?slug=…` | หน้าส่งสำเร็จ | ผู้ใช้ |
| `my.php?id=…` | ดูคำตอบของตัวเอง | เจ้าของ |
| `admin/forms.php` | รายการฟอร์มทั้งหมด | Admin |
| `admin/form_edit.php?id=…` (ไม่มี id = สร้างใหม่) | แก้ JSON + preview ข้างๆ (อัปเดตทันที) | Admin |
| `admin/form_permissions.php?id=…` | ให้สิทธิ์ดูคำตอบ (ค้นรายชื่อจาก Employee API) | Admin |
| `admin/submissions.php?form=…` | ตารางคำตอบ กรองตามวันที่ | Admin / viewer |
| `admin/submission.php?id=…` | รายละเอียดคำตอบ + ไฟล์ + ลายเซ็น | Admin / viewer |
| `admin/export.php?form=…` | Export CSV (UTF-8 BOM เปิดใน Excel แล้วภาษาไทยไม่เพี้ยน) | Admin / viewer |
| `file.php?id=…` | ดาวน์โหลดไฟล์แนบ (เช็กสิทธิ์ก่อนส่ง) | เจ้าของ / Admin / viewer |

## 8. ไฟล์แนบและลายเซ็น

- เก็บใน `storage/` ซึ่งเข้าจากเว็บตรงไม่ได้ (บล็อกด้วย `.htaccess`) ต้องดาวน์โหลดผ่าน `/files/{id}` ที่เช็กสิทธิ์ก่อน
- ตั้งชื่อไฟล์ใหม่แบบสุ่ม แยกโฟลเดอร์ตามปี/เดือน: `storage/uploads/2026/11/<random>.pdf`
- ตรวจนามสกุลตาม `accept` และตรวจ MIME จริงด้วย `finfo`, จำกัดขนาดตาม `max_size_mb`
- ลายเซ็น: JS ส่งเป็น PNG base64 → PHP ตรวจว่าเป็น PNG จริง ขนาดไม่เกิน 500 KB → บันทึกเป็นไฟล์

## 9. เมลแจ้งเตือน

- PHPMailer ผ่าน Office365 SMTP (smtp.office365.com:587 STARTTLS, ค่าอยู่ใน `.env`)
- ส่งหลังบันทึกคำตอบสำเร็จ ถ้าส่งเมลไม่ผ่าน คำตอบยังถูกบันทึกอยู่ และ error ถูกเก็บใน `mail_log`
- เนื้อเมล: ตารางคำถาม-คำตอบ + ลิงก์ไปหน้าดูคำตอบ (ไม่แนบไฟล์ เพื่อไม่ให้เมลใหญ่)
- หัวข้อเมลใส่ค่าจากช่องได้ เช่น `{fullname}`

## 10. ความปลอดภัย

- CSRF token ทุกฟอร์มที่เป็น POST
- ใช้ prepared statement ทุก query
- Escape ทุกค่าที่แสดงผล (`htmlspecialchars`)
- Session cookie: `HttpOnly`, `SameSite=Lax`, เปลี่ยน session id หลัง login
- จำกัดการลอง login ผิด: 5 ครั้ง / 15 นาที ต่อ employee_id และ 20 ครั้ง / 15 นาที ต่อ IP
- ความลับและค่าภายในองค์กรทั้งหมด (DB, ที่อยู่และ key ของ Employee API, SMTP) อยู่ใน `.env` ที่ไม่ commit
- เอกสารภายในองค์กร (เช่น คู่มือ Employee API) ไม่เก็บใน repo — ดู `.gitignore`
- `FAKE_LOGIN` ใช้ได้เฉพาะ `APP_ENV=development` และเฉพาะ request จากเครื่องตัวเอง (localhost)
- Production ต้องใช้ HTTPS และ Apache ต้องเปิด `AllowOverride` ให้ `.htaccess` ทำงาน (ไม่งั้นโฟลเดอร์ภายในจะเปิดจากเว็บได้)

## 11. การทดสอบ

- PHPUnit สำหรับส่วนที่เป็น logic หลัก: `FormSchema`, `ConditionEvaluator`, `SubmissionValidator`
- ทดสอบด้วยมือในเบราว์เซอร์: login, กรอกฟอร์มตัวอย่างที่มีทุกประเภทช่อง, condition, อัปโหลด, ลายเซ็น, export CSV

## 12. ข้อมูลที่ยังรอ

| เรื่อง | สถานะ |
|---|---|
| ค่าการเชื่อมต่อ MSSQL | กรอกใน `.env` |
| ที่อยู่และ `X-API-KEY` ของ Employee API | กรอกใน `.env` |
| ค่า SMTP (Office365) | กรอกใน `.env` — ระหว่างนี้ dev ใช้โหมดเขียนเมลลงไฟล์ `storage/logs/mail/` แทนการส่งจริง |
| รายชื่อ admin เริ่มต้น (employee_id) | กรอก `APP_ADMINS` ใน `.env` |
