# MySQL 8.0.46 — Root Password Recovery Assessment

**Verdict: 🔴 NO alternative administrator login exists. A supported password reset is required.**
**Nothing has been executed. This is an assessment and a plan awaiting approval.**

| | |
|---|---|
| Date | 2026-11-18 |
| Server | MySQL 8.0.46, service `MySQL80`, Running, PID 4716 → server 5996 |
| Port | 3306 (classic) · 33060 (X Protocol), both owned by PID 5996 |
| Service account | `NT AUTHORITY\NetworkService` |
| Datadir | `C:\ProgramData\MySQL\MySQL Server 8.0\Data` (ACL-restricted to `NetworkService`) |
| Config | `C:\ProgramData\MySQL\MySQL Server 8.0\my.ini` (**not modified**) |
| Duplicate service | `MySQL80_New` — Stopped, **Disabled**, left untouched |

No password hash was read, extracted or cracked. No password was guessed. No credential store was
decoded. No account, database or configuration file was modified.

---

## Phase 1 — Search for Alternative Access

### 1. Documented alternative administrator accounts

| Source searched | Result |
|---|---|
| `.env` | **No** admin/root key. No `DB_ROOT`, `MYSQL_ROOT` or `ROOT_PASSWORD` anywhere in the project |
| `config/`, `app/`, `bin/`, `database/` | **No** administrative credential |
| `docs/*.md` | **No** credential. Only the `-u root -p` *interactive* command form, and a note that a lost admin password is out of MAURO's scope |
| `.env.example`, `.env.production.example` | templates only, no populated values |
| `database/setup/mysql_create_databases.sql` | creates `mauro_dev` / `mauro_test` — **non-administrative**, each scoped to one database, and (per the 1045 diagnosis) not currently usable |

**No second administrative account is documented anywhere.**

### 2. MySQL client login paths and Windows configuration

| Check | Result |
|---|---|
| `mysql_config_editor print --all` | **"no login paths defined"** |
| `%APPDATA%\MySQL\.mylogin.cnf` | exists but **24 bytes — an empty header with no entries** (contents deliberately **not** decoded) |
| `~\.my.cnf`, `~\my.ini`, `C:\my.cnf`, `C:\my.ini`, `C:\Windows\my.ini`, `…\MySQL Server 8.0\my.ini` | **all absent** |
| **MySQL Workbench saved connection** | **EXISTS** — `root@127.0.0.1:3306`… |
| …but its stored password | `<value type="string" key="password"></value>` → **EMPTY** |
| **Windows Credential Manager** | **15 entries scanned — not one MySQL, `root@`, `3306` or Workbench entry.** Workbench stores connection passwords in the Windows Vault; there is none |
| `workbench_user_data.dat` | exists (294 bytes); **not decoded or parsed** |

The Workbench connection was the most promising lead and it is **definitively ruled out**: Workbench
would prompt for the password exactly as the CLI does.

### 3. Service configuration and actual data directory

```
MySQL80      Running   Auto      PID 4716   NT AUTHORITY\NetworkService
  "…\MySQL Server 8.0\bin\mysqld.exe" --defaults-file="C:\ProgramData\MySQL\MySQL Server 8.0\my.ini" MySQL80
MySQL80_New  Stopped   Disabled  PID 0      (remains disabled)

datadir          = C:/ProgramData/MySQL/MySQL Server 8.0\Data
log-error        = AYHM.err  (inside datadir)
secure-file-priv = C:/ProgramData/MySQL/MySQL Server 8.0/Uploads
skip-grant-tables = NOT present
init-file         = NOT present
```

The datadir is **unreadable unelevated** (`Access is denied` — confirmed against `mysql` and `sys`
as controls, so this reflects ACLs, not absence). Its **size could not be measured** and must be
checked in the elevated session before any copy.

### 4. Project documentation for previous setup credentials

No credential values are stored in the project. Notably, the project already anticipated this exact
situation — [docs/MYSQL_VERIFICATION.md:41-42](MYSQL_VERIFICATION.md#L41-L42):

> *"If the administrator password is lost, do **not** reset it as part of this project — that is a
> server-level change outside MAURO; use MySQL's documented `--init-file` recovery procedure
> deliberately, or reinstall."*

This assessment follows that guidance: `--init-file`, deliberately, with a backup first.

### 5. Is any other legitimate administrative login available?

| Candidate route | Available? |
|---|---|
| Stored CLI login path (`.mylogin.cnf`) | ❌ empty |
| MySQL Workbench saved password | ❌ none stored |
| Windows Credential Manager | ❌ no MySQL entry |
| Option file with client credentials | ❌ none exist |
| Project-held admin credential | ❌ none exist |
| `mauro_dev` / `mauro_test` | ❌ non-administrative **and** currently failing 1045 |
| Existing MySQL dump containing accounts | ❌ `storage/backups/` holds only **SQLite** dev backups and one `.webp` — no `.sql`/`.sql.gz` MySQL dump exists |
| Separate XAMPP MariaDB (`C:\xamppa01\mysql\data`, 8 schemas) | ⚠️ a **different server**, not running, and grants there do not apply to MySQL80 |

> ### ❌ No alternative administrative login is available. Password recovery is the only route.

---

## ⚠️ Data At Risk — Read This Before Approving Anything

This server almost certainly holds **coursework/exam databases that are not part of MAURO**.
`C:\MYSQL` contains ~20 SQL scripts whose `CREATE DATABASE` / `USE` statements name:

```
AIHAM                  gestion_biblio          mini_facebook        hotel_db
gestion_commerciale    gestion_ateliers        gestion_bibliotheque gestion_projets
lisa_co_db             controle                social_media_db      universite
bibliotheque           mysb                    gestion_commande26   atelier6
inscriptions_des_étudiants_dans_des_formations
```

File names include *"Examen blanc MASSAD"*, *"MASAAD ELAIHAM CONTROL"* and *"Exercice SQL complet"*.
Whether these databases are currently loaded in MySQL80 **cannot be confirmed without a login** —
but the possibility must be assumed.

**Consequence: the backup step in Phase 2 is mandatory, not optional.** MAURO itself has nothing to
lose here (its migrations have never run), but your academic work may.

**Backup space:** only drive `C:` exists, with **45.8 GB free**. The datadir size is unknown
(ACL-blocked) and must be measured first. An external drive is preferable if you have one.

---

## Phase 2 — Recovery Options

### Why the method matters on Windows

The service runs as `NT AUTHORITY\NetworkService`, and the datadir is ACL-restricted to that
account. If `mysqld` is launched manually from an Administrator console, it runs **as Administrator**
and can create or rewrite datadir files (redo logs, binlogs) owned by Administrator — after which
the service, running as `NetworkService`, may fail to start or write. This is a well-known Windows
pitfall and it is why the options below are ranked by *which account mysqld runs under*, not by
convenience.

### Option A — `--init-file` passed through the service (RECOMMENDED)

mysqld keeps running as `NetworkService`, so **no ownership or ACL damage is possible**, and
`my.ini` is not edited.

- ✅ Correct service account preserved
- ✅ No `my.ini` edit (respects your restriction)
- ✅ No `--skip-grant-tables`; the server never accepts unauthenticated connections
- ⚠️ Depends on the service accepting the extra argument from `net start`. If it rejects it, fall
  back to Option B or C. This will be visible immediately and is harmless either way.

### Option B — temporary `init_file=` line in `my.ini` (most reliable)

Technically the cleanest and most predictable: mysqld runs as `NetworkService`, and the option is
guaranteed to be read.

- ✅ Most reliable; correct service account
- ⚠️ **Requires you to waive the "do not edit `my.ini`" restriction.** It is one temporary line
  under `[mysqld]`, removed immediately afterwards, with a hash-verified backup of the file taken
  first (current sha256: `42ed51cc…5bfd0`).

### Option C — manual `mysqld --init-file` from an elevated console (official docs, last resort)

This is the procedure in MySQL's own manual, but on a *service* installation it carries the
ownership risk described above.

- ⚠️ Requires an `icacls` ACL repair afterwards, granting `NetworkService` full control of the
  datadir, before the service is restarted
- ✅ Still no `--skip-grant-tables`

### Not recommended

| Method | Why not |
|---|---|
| `--skip-grant-tables` | Opens a server with **all authentication disabled**. Needs `--skip-networking` to be even minimally safe, and your instructions require approval before use. Option A/B/C achieve the same result without ever disabling authentication. |
| MySQL Installer "Reconfigure" | Explicitly out of scope, and it is the most likely origin of the `MySQL80_New` duplicate service we just cleaned up. |
| Reinstall / uninstall | Forbidden, and it risks the coursework databases. |

---

## Phase 3 — Exact Commands For Your Review (NOT EXECUTED)

Every command below runs in **PowerShell as Administrator**. Steps 1–4 are the mandatory backup.

### Step 1 — Measure the datadir (read-only)

```powershell
$dd = 'C:\ProgramData\MySQL\MySQL Server 8.0\Data'
"{0:N1} MB" -f ((Get-ChildItem $dd -Recurse -File | Measure-Object Length -Sum).Sum / 1MB)
Get-ChildItem $dd -Directory | Select-Object Name      # <-- this finally lists your databases
```

The second line answers, for free, whether the coursework databases and
`mauro_library_dev` / `mauro_library_test` exist — **before** anything is changed.

### Step 2 — Stop the service ⛔ REQUIRES YOUR APPROVAL

```powershell
Stop-Service -Name MySQL80
Get-Service MySQL80 | Select-Object Name, Status          # expect: Stopped
Get-Process mysqld -ErrorAction SilentlyContinue          # expect: nothing
```

A clean stop is what makes the file-level copy in Step 3 a valid InnoDB backup.

### Step 3 — Cold backup of the data directory ⛔ THE SAFETY NET

```powershell
$stamp  = Get-Date -Format 'yyyyMMdd-HHmmss'
$backup = "C:\mysql-datadir-backup-$stamp"
robocopy "C:\ProgramData\MySQL\MySQL Server 8.0\Data" $backup /E /ZB /COPY:DAT /R:1 /W:1 /NFL /NDL
"exit code: $LASTEXITCODE"      # 0 or 1 = success; >= 8 = failure, STOP
```

### Step 4 — Verify the backup before proceeding

```powershell
$src = (Get-ChildItem "C:\ProgramData\MySQL\MySQL Server 8.0\Data" -Recurse -File | Measure-Object).Count
$dst = (Get-ChildItem $backup -Recurse -File | Measure-Object).Count
"source files: $src   backup files: $dst"     # must match
```

**If these do not match, stop and do not continue.**

### Step 5 — Write the init file

```powershell
"ALTER USER 'root'@'localhost' IDENTIFIED BY 'PUT-A-STRONG-PASSWORD-HERE';" |
    Set-Content -Path 'C:\mysql-init.txt' -Encoding ascii
```

> 🔑 **Type your chosen password directly into that command in your terminal. Do not send it to me
> or put it in any chat.** Choose something that satisfies MySQL's default policy — the
> `validate_password` component is installed on this server (`component_validate_password.dll`
> present; whether it is *active* needs a login): **≥ 8 characters, upper + lower case, a digit and
> a special character** is a safe target.
>
> `C:\mysql-init.txt` must be readable by `NetworkService`; the root of `C:` is, which is why MySQL's
> own documentation uses that location.

### Step 6 — Apply the reset (Option A first)

```powershell
net start MySQL80 --init-file=C:\mysql-init.txt
Get-Service MySQL80 | Select-Object Name, Status
```

If the service starts: the password is reset. If it refuses the argument or fails to start, **stop
and tell me** — I will switch you to Option B or C rather than improvising.

### Step 7 — Delete the init file immediately

```powershell
Remove-Item 'C:\mysql-init.txt' -Force
Test-Path 'C:\mysql-init.txt'        # expect: False
```

It contains your new password in plain text; it must not linger.

### Step 8 — Restart cleanly, without the init option

```powershell
Restart-Service -Name MySQL80
Get-Service MySQL80 | Select-Object Name, Status, StartType
Get-Process mysqld | Select-Object Id, StartTime     # expect exactly 2 (monitor + server)
```

### Step 9 — Verify access and data integrity

```powershell
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p
```

```sql
SELECT VERSION();
SHOW DATABASES;                      -- every coursework database must still be listed
SELECT user, host, plugin FROM mysql.user;
```

### Step 10 — Take a proper logical backup straight away

```powershell
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" -u root -p `
    --all-databases --routines --triggers --events `
    --result-file="C:\mysql-all-databases-$(Get-Date -Format 'yyyyMMdd').sql"
```

This is the backup you should have had all along, and it also proves `mysqldump` works — which MAURO
needs for `backup:run`.

---

## Risks and Rollback

| Risk | Likelihood | Mitigation |
|---|---|---|
| Coursework databases lost | Low | Cold datadir copy (Steps 3–4) taken **before** any change, file-count verified |
| Service won't restart after a manual `mysqld` run (Option C only) | Medium **if Option C is used** | Option A/B avoid it entirely; otherwise repair with `icacls "<datadir>" /grant "NT AUTHORITY\NetworkService:(OI)(CI)F" /T` |
| New password rejected by `validate_password` | Medium | Use a strong password per Step 5; the error appears in `AYHM.err`, now readable when elevated |
| `root@localhost` is not the actual account host | Low | Step 1's listing plus Step 9's `mysql.user` query confirm it; the `ALTER USER` can be re-targeted |
| Service is down during the work | Certain | Expected; nothing else on this machine depends on it (no dependent services — verified twice) |
| MySQL80_New interferes | **None** | Stopped and Disabled; `ExitCode 1077` proves it no longer even attempts to start |

### Rollback (if anything goes wrong)

```powershell
Stop-Service -Name MySQL80 -ErrorAction SilentlyContinue
Rename-Item "C:\ProgramData\MySQL\MySQL Server 8.0\Data" "Data.broken-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
robocopy $backup "C:\ProgramData\MySQL\MySQL Server 8.0\Data" /E /ZB /COPY:DAT /R:1 /W:1
icacls "C:\ProgramData\MySQL\MySQL Server 8.0\Data" /grant "NT AUTHORITY\NetworkService:(OI)(CI)F" /T
Start-Service -Name MySQL80
```

The original datadir is **renamed, never deleted**, so the rollback is itself reversible.

---

## Recommendation

1. **Run Step 1 now.** It is read-only, needs no approval beyond elevation, and it immediately
   answers two outstanding questions: what databases exist, and how much space the backup needs.
2. **Then approve Steps 2–4** (stop + cold backup). This is the real safety net.
3. **Then Steps 5–8** using **Option A**, falling back only on my guidance.
4. **Then Steps 9–10**, and we return to MAURO's own blocker — creating the scoped `mauro_dev` /
   `mauro_test` accounts, which needs this root access.

**Nothing in Phase 3 has been executed.** I will not stop `MySQL80`, write any file, or run any
recovery command until you approve, and I cannot run the elevated steps myself in any case — this
session is not elevated.
