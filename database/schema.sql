-- Journal Form — สร้างตารางทั้งหมด (SQL Server 2016+)
-- วิธีใช้: สร้างฐานข้อมูลก่อน แล้วรันไฟล์นี้ในฐานข้อมูลนั้น
--   CREATE DATABASE JournalForm COLLATE Thai_CI_AS;
--   sqlcmd -S <server> -d JournalForm -U <user> -P <pass> -i database\schema.sql

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

CREATE TABLE app_admins (
    employee_id   INT          NOT NULL PRIMARY KEY,
    created_at    DATETIME2(0) NOT NULL DEFAULT SYSDATETIME()
);
GO

CREATE TABLE forms (
    id              INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    slug            NVARCHAR(100) NOT NULL,
    title           NVARCHAR(255) NOT NULL,
    status          NVARCHAR(20)  NOT NULL DEFAULT 'draft',   -- draft | published | closed
    current_version INT           NOT NULL DEFAULT 0,         -- 0 = ยังไม่เคยเผยแพร่
    draft_json      NVARCHAR(MAX) NOT NULL,                   -- schema ที่กำลังแก้
    created_by      INT           NOT NULL,
    updated_by      INT           NOT NULL,
    created_at      DATETIME2(0)  NOT NULL DEFAULT SYSDATETIME(),
    updated_at      DATETIME2(0)  NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT uq_forms_slug UNIQUE (slug),
    CONSTRAINT ck_forms_status CHECK (status IN ('draft', 'published', 'closed')),
    CONSTRAINT ck_forms_draft_json CHECK (ISJSON(draft_json) = 1)
);
GO

CREATE TABLE form_versions (
    form_id     INT           NOT NULL,
    version     INT           NOT NULL,
    schema_json NVARCHAR(MAX) NOT NULL,
    created_by  INT           NOT NULL,
    created_at  DATETIME2(0)  NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT pk_form_versions PRIMARY KEY (form_id, version),
    CONSTRAINT fk_form_versions_form FOREIGN KEY (form_id) REFERENCES forms(id),
    CONSTRAINT ck_form_versions_json CHECK (ISJSON(schema_json) = 1)
);
GO

CREATE TABLE form_permissions (
    form_id     INT          NOT NULL,
    employee_id INT          NOT NULL,
    role        NVARCHAR(20) NOT NULL DEFAULT 'viewer',
    created_by  INT          NOT NULL,
    created_at  DATETIME2(0) NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT pk_form_permissions PRIMARY KEY (form_id, employee_id),
    CONSTRAINT fk_form_permissions_form FOREIGN KEY (form_id) REFERENCES forms(id)
);
GO

CREATE TABLE submissions (
    id              BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    form_id         INT           NOT NULL,
    form_version    INT           NOT NULL,
    data_json       NVARCHAR(MAX) NOT NULL,
    status          NVARCHAR(20)  NOT NULL DEFAULT 'submitted',  -- เฟส 2: pending | approved | rejected
    submitted_by    INT           NOT NULL,                      -- employee_id
    submitter_name  NVARCHAR(255) NULL,
    submitter_email NVARCHAR(255) NULL,
    submitter_dept  NVARCHAR(255) NULL,
    ip_address      NVARCHAR(45)  NULL,
    created_at      DATETIME2(0)  NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT fk_submissions_version FOREIGN KEY (form_id, form_version) REFERENCES form_versions(form_id, version),
    CONSTRAINT ck_submissions_json CHECK (ISJSON(data_json) = 1)
);
GO
CREATE INDEX ix_submissions_form ON submissions (form_id, created_at);
CREATE INDEX ix_submissions_user ON submissions (submitted_by, created_at);
GO

CREATE TABLE submission_files (
    id            BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    submission_id BIGINT        NOT NULL,
    field_name    NVARCHAR(100) NOT NULL,
    kind          NVARCHAR(20)  NOT NULL,       -- file | signature
    original_name NVARCHAR(255) NULL,
    stored_path   NVARCHAR(500) NOT NULL,
    mime_type     NVARCHAR(100) NULL,
    size_bytes    BIGINT        NULL,
    CONSTRAINT fk_submission_files_submission FOREIGN KEY (submission_id) REFERENCES submissions(id)
);
GO
CREATE INDEX ix_submission_files_submission ON submission_files (submission_id);
GO

CREATE TABLE mail_log (
    id            BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    submission_id BIGINT        NULL,
    recipients    NVARCHAR(MAX) NULL,
    subject       NVARCHAR(500) NULL,
    status        NVARCHAR(20)  NOT NULL,       -- sent | failed
    error         NVARCHAR(MAX) NULL,
    created_at    DATETIME2(0)  NOT NULL DEFAULT SYSDATETIME()
);
GO

-- เฟส 2 (ยังไม่สร้าง): approvals (submission_id, step, approver_id, decision, comment, decided_at)
