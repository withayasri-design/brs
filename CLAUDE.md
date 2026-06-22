# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Project Overview

**BRS (Backup & Restore System)** is a reusable PHP framework for backing up and restoring any PHP/MariaDB web application running on XAMPP. It is not tied to any specific app — target paths and databases are configured per "backup job." Maximum reliability and security are the primary design goals.

Read documentation in this order before writing code: `01-PRD.md` → `02-SRS.md` → `03-ARCHITECTURE.md` → then the file specific to the component being developed (see table below).

---

## Common Commands

```bash
# Check environment before starting development
php cli\healthcheck.php

# Run a backup job
php cli\backup.php --job-id=1

# Dry-run restore (safe — does not touch real data)
php cli\restore.php --backup-log-id=<id> --mode=dry_run

# Re-create the metadata schema (dev only — destroys all data)
mysql -u root -p brs_system < sql\schema.sql
```

---

## Tech Stack Constraints (non-negotiable)

- **PHP 8.2+ native only** — no Laravel/Symfony for the core engine; portability across XAMPP environments is required
- **Composer** is allowed only for optional cloud adapters (AWS SDK, Google API Client) — keep autoload isolated from core
- **Database access**: PDO with prepared statements only — never `mysqli`, never string-concatenated SQL
- **Frontend**: Bootstrap 5 + Vanilla JS (`fetch` API) — no React, Vue, or jQuery
- **No build step** — JS/CSS are written and included directly; no webpack/vite

---

## Architecture

```
Web UI (Bootstrap 5)  ─┐
                         ├─▶  PHP Core Engine (lib/)  ─▶  brs_system MariaDB
CLI Scripts (cli/)    ─┘          │
    ▲                              └─▶  Storage Adapters (local / NAS / S3 / Drive / SFTP)
    │                                       │
Windows Task Scheduler / cron               └─▶  Target web app files + DB (via mysqldump)
```

Both the Web UI and CLI invoke the same core classes in `lib/` — no logic duplication between interfaces. All state is persisted in the `brs_system` database so the Web UI reflects CLI results in real time.

### Key Classes in `lib/`

| Class | Responsibility |
|-------|----------------|
| `BackupEngine.php` | Orchestrates full backup: lock → disk check → DB dump → zip → checksum → encrypt → upload → verify → cleanup → unlock → log |
| `RestoreEngine.php` | Orchestrates restore: checksum verify → decrypt → dry-run extract → (if real) pre-restore snapshot → extract → DB import → post-verify → log |
| `StorageAdapter\*` | Upload/download/list/delete via `StorageAdapterInterface` — every adapter must implement `testConnection()` |
| `EncryptionService.php` | AES-256-CBC encrypt/decrypt; key derivation |
| `ChecksumService.php` | SHA-256 generation and verification |
| `LockManager.php` | Per-job lock files in `temp/locks/{job_id}.lock`; detects stale locks by checking whether the PID is still alive |
| `RetentionPolicyService.php` | GFS (Grandfather-Father-Son) retention policy calculation |
| `AuditLogger.php` | Writes every significant action to the `audit_logs` table |
| `Database.php` | PDO wrapper; enforces prepared statements |

### `brs_system` Database — Core Tables

- `users` — RBAC (admin / operator / viewer)
- `backup_jobs` — job configuration (source path, DB credentials encrypted, schedule, retention policy)
- `storage_targets` + `job_storage_targets` — storage destinations per job
- `backup_logs` — one row per backup run; includes `verification_status` and `is_pinned`
- `backup_files` — individual files uploaded to each storage target per run
- `restore_logs` — restore history including pre-restore snapshot reference
- `audit_logs` — immutable action log

---

## Security Rules (never violate)

1. Passwords and credentials must never be stored as plaintext — always pass through `EncryptionService` before writing to the DB or logs.
2. All SQL must use PDO prepared statements — no string concatenation, no exceptions.
3. Any path derived from user input must be validated through `PathValidator::isWithinAllowedBase()` before use (prevents path traversal).
4. Backup deletion and real restore must include a confirmation step that matches the flow in `03-ARCHITECTURE.md §6`.
5. The post-backup verification step (checksum re-check + `ZipArchive::testArchive`) is mandatory — it must never be skipped.
6. Always check available disk space (≥ estimated size + 20% buffer) before starting a backup; abort with notification if insufficient.

---

## Component → Reference Document Map

| Developing | Read first |
|-----------|-----------|
| `lib/BackupEngine.php` | `03-ARCHITECTURE.md §5`, `02-SRS.md` FR-2 |
| `lib/RestoreEngine.php` | `03-ARCHITECTURE.md §6`, `02-SRS.md` FR-3 |
| `lib/StorageAdapter/*` | `07-STORAGE-PROVIDERS.md` |
| `lib/EncryptionService.php` | `06-SECURITY.md §1` |
| `lib/RetentionPolicyService.php` | `02-SRS.md` FR-5 |
| `public/api/*.php` | `05-API-SPEC.md` |
| `cli/*.php` | `08-CLI-GUIDE.md` |
| Database migrations | `04-DATABASE-SCHEMA.md` (schema is source of truth — update the doc when changing structure) |

---

## Definition of Done

A feature is complete only when:

- Logic matches the Functional Requirement in `02-SRS.md`
- Passes the Security checklist in `06-SECURITY.md`
- Error handling covers every failure case in the relevant sequence diagram (`03-ARCHITECTURE.md`)
- All significant actions are written to both the log file and `audit_logs` table
- No hardcoded credentials or paths — everything reads from `config/`
- When adding a new API endpoint: `05-API-SPEC.md` is updated to match
- When modifying the DB schema: `04-DATABASE-SCHEMA.md` is updated to match
