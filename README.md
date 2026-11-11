# Journal Form

ระบบฟอร์มภายในองค์กร (แทน Jotform) — ฟอร์มสร้างจาก JSON schema, login ผ่าน Employee API, เก็บข้อมูลใน MSSQL

เอกสารออกแบบ: [docs/design.md](docs/design.md)

## ติดตั้ง

ต้องมี: PHP 8.2+ (`pdo_sqlsrv`, `curl`, `fileinfo`, `mbstring`, `gd`), Composer, Apache + `mod_rewrite`, SQL Server 2016+

1. ติดตั้ง dependency
   ```
   composer install --no-dev
   ```
2. สร้างฐานข้อมูลและตาราง
   ```sql
   CREATE DATABASE JournalForm COLLATE Thai_CI_AS;
   ```
   ```
   sqlcmd -S <server> -d JournalForm -U <user> -P <pass> -i database\schema.sql
   ```
3. คัดลอก `.env.example` เป็น `.env` แล้วกรอกค่า (DB, `EMP_API_KEY`, อีเมล Office365 ฯลฯ)
   - `config.php` อ่านค่าจาก `.env` — เครื่อง dev/prod ใช้ `config.php` ตัวเดียวกัน ต่างกันแค่ `.env`
   - `.env` ห้าม commit (อยู่ใน `.gitignore` แล้ว)
   - `DB_USERNAME` ว่าง = Windows Authentication โดยใช้บัญชีที่รัน Apache
4. กำหนดผู้ดูแลระบบ: ใส่ `APP_ADMINS=110001,110002` ใน `.env` หรือรัน
   ```
   php database/admin.php add 110001
   ```
5. เปิด `http://<server>/journal-form/`

## ทดสอบบนเครื่องตัวเองโดยไม่ต่อ Employee API

ใน `.env` ตั้ง `APP_ENV=development` และ `FAKE_LOGIN=true` แล้ว login ด้วยรหัสพนักงานใดก็ได้ รหัสผ่าน `dev`
(`FAKE_LOGIN` ไม่มีผลเมื่อ `APP_ENV=production`)

`MAIL_TRANSPORT=file` เขียนเมลเป็นไฟล์ใน `storage/logs/mail/` แทนการส่งจริง (default ของ development)

## สร้างฟอร์มใหม่

1. login ด้วยบัญชี admin → เมนู **จัดการฟอร์ม** → **สร้างฟอร์มใหม่**
2. ตั้งลิงก์ฟอร์ม (slug) และเขียน JSON — ด้านขวาแสดงตัวอย่างฟอร์มทันที ตัวอย่างทุกประเภทช่องอยู่ใต้ช่อง JSON
3. **บันทึกฉบับร่าง** (ยังไม่มีผลกับผู้กรอก) หรือ **บันทึกและเผยแพร่**
4. ส่งลิงก์ `/journal-form/f/<slug>` ให้ผู้ใช้
5. ให้สิทธิ์คนที่ต้องดูคำตอบ (เช่น HR) ที่เมนู **สิทธิ์** ของฟอร์มนั้น

แก้ฟอร์มที่เผยแพร่แล้วได้ทุกเมื่อ: ระบบเก็บเป็นเวอร์ชันใหม่ คำตอบเก่ายังแสดงตามเวอร์ชันที่ใช้ตอนกรอก

## ก่อนขึ้น production (ความปลอดภัย)

- [ ] `.env`: `APP_ENV=production`, `FAKE_LOGIN=false`, กรอก `EMP_API_URL`, `EMP_API_KEY`, `EMAIL_USER`, `EMAIL_PASS` ครบ (production ไม่ครบจะไม่ยอมเปิด)
- [ ] ใช้ HTTPS (cookie session จะตั้ง `Secure` อัตโนมัติเมื่อเป็น HTTPS)
- [ ] Apache เปิด `mod_rewrite` และ `AllowOverride All` ให้โฟลเดอร์นี้ — `.htaccess` เป็นตัวบล็อก `lib/`, `storage/`, `vendor/`, `.env` ฯลฯ ถ้าไม่ทำงาน ไฟล์เหล่านี้จะเปิดจากเว็บได้
  ตรวจ: เปิด `/journal-form/.env` และ `/journal-form/storage/` ต้องได้ 403
- [ ] `APP_ADMINS` เป็นรหัสพนักงานจริง และลบรหัสทดสอบออก
- [ ] บัญชี Windows/SQL ที่ใช้ต่อฐานข้อมูลมีสิทธิ์แค่ฐานข้อมูลนี้

เอกสารภายในองค์กร (เช่น คู่มือ Employee API) ห้ามเก็บใน repo นี้ — วางไว้ที่ root หรือ `docs/private/` ได้ (อยู่ใน `.gitignore`)

## Test

```
composer install
vendor/bin/phpunit
```
