# MAURO — MySQL Connection Diagnostic and Repair (Phase 1)

**Result: 🔴 BLOCKED — port misconfiguration repaired; authentication requires a manual administrator action.**

| | |
|---|---|
| Date | 2026-11-18 13:18 |
| Host | Windows 11 Pro (10.0.26300) |
| MySQL | 8.0.46, service `MySQL80`, Running |
| Scope | Read-only diagnosis + one surgical `.env` port repair |
| Data modified | **None.** No SQL executed beyond `SELECT VERSION()` on success paths (never reached). No database, table, user, grant, password or `my.ini` touched. |

No credential value appears anywhere in this document or in any command output produced during
this phase. Usernames and passwords were scrubbed from every error message before display.

---

## 1. Actual MySQL Server Configuration

Verified read-only. No destructive SQL.

| Property | Verified value | Method |
|---|---|---|
| Service | `MySQL80` — **Running**, `Status: OK`, `ExitCode: 0`, StartMode `Auto` | `Win32_Service` |
| Duplicate service | `MySQL80_New` — **Stopped**, **Disabled**, `ExitCode 1077` (never started) | `Win32_Service` |
| Processes | exactly **2**: monitor PID 4716 (PPID 704 `services.exe`) → server PID **5996** (40 threads) | `Win32_Process` |
| Server version | **8.0.46** | MySQL protocol handshake on 3306 (protocol v10, 78 bytes) |
| Port 3306 | **listening**, owned by PID **5996** | `Get-NetTCPConnection` |
| Port 33060 | **listening** (X Protocol), owned by PID **5996** | `Get-NetTCPConnection` |
| TCP accept | both ports accept connections | socket probe |
| Datadir | `C:/ProgramData/MySQL/MySQL Server 8.0\Data` (ACL-restricted to `NetworkService`) | `my.ini` |
| Config file | `C:\ProgramData\MySQL\MySQL Server 8.0\my.ini` — sha256 `42ed51cc…5bfd0`, **unchanged** | `sha256sum` |
| Default engine | `INNODB` | `my.ini` |
| `authentication_policy` | `*,,` → default plugin `caching_sha2_password` | `my.ini` |
| `lower_case_table_names` | `1` — compatible with MAURO (all identifiers are lowercase) | `my.ini` |

**Nothing on port 3308.** The server has only ever listened on 3306 and 33060.

---

## 2. Actual Application Configuration Locations

Every place `DB_*` is consumed, located by search rather than assumption:

| Location | Role | Variables consumed |
|---|---|---|
| **`.env`** (gitignored via `/.env.*`) | the real values | all |
| `.env.example`, `.env.production.example` | templates, tracked in git | documented keys only |
| **[app/Core/Env.php](../app/Core/Env.php)** | environment loader. Real OS environment variables win over the file ([Env.php:44-45](../app/Core/Env.php#L44-L45)) | — |
| **[config/database.php:10-20](../config/database.php#L10-L20)** | **application/dev connection** — `driver`, `sqlite_path`, `host`, `port`, `database`, `username`, `password`, `socket`. Hard-codes `charset=utf8mb4`, `collation=utf8mb4_unicode_ci` | `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SOCKET`, `DB_SQLITE_PATH` |
| **[app/Core/Database.php:31-51](../app/Core/Database.php#L31-L51)** | **PDO factory**. `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false` (real prepares), `STRINGIFY_FETCHES=false`, `ATTR_TIMEOUT`; sets `SET NAMES`, UTC time zone, strict `sql_mode` | consumes the config array |
| **[tests/Support/TestDatabase.php:33-63](../tests/Support/TestDatabase.php#L33-L63)** | **test connection**, with four hard refusals before any destructive test | `DB_TEST_HOST`, `DB_TEST_PORT`, `DB_TEST_DATABASE`, `DB_TEST_USERNAME`, `DB_TEST_PASSWORD`, `DB_TEST_SOCKET` |
| **[bin/console:68-80](../bin/console)** | `db:check` and `db:check --test` | overrides with `DB_TEST_*` |
| **[app/Core/Migrator.php](../app/Core/Migrator.php)** | migrations — uses the shared `Database::connection()` | indirect |

**PHPUnit configuration: none exists.** There is no `phpunit.xml` or `phpunit.xml.dist`; the suite
runs through the dependency-free runner [tests/run.php](../tests/run.php). There is therefore no
second place where test database settings could diverge.

### Sanitized configuration (values masked)

| Key | Value |
|---|---|
| `DB_DRIVER` | `sqlite` ← **the app still runs on SQLite, not MySQL** |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | **`3306`** (repaired this phase, was `3308`) |
| `DB_DATABASE` | `mauro_library_dev` |
| `DB_USERNAME` | *set, length 5* |
| `DB_PASSWORD` | *set, length 7* |
| `DB_TEST_HOST` | `127.0.0.1` |
| `DB_TEST_PORT` | **`3306`** (repaired this phase, was `3308`) |
| `DB_TEST_DATABASE` | `mauro_library_test` |
| `DB_TEST_USERNAME` | *set, length 10* |
| `DB_TEST_PASSWORD` | *set, length 64* |

---

## 3. Authentication Diagnosis (error 1045)

Each candidate cause was tested, not guessed. Six of the eight are **ruled out by evidence**.

| Candidate cause | Verdict | Evidence |
|---|---|---|
| **Incorrect environment loading** | ❌ **RULED OUT** | The app reads back exactly what `.env` contains: `config database.host = 127.0.0.1`, `config database.port = 3306` after repair. Loader behaves correctly. |
| **Incorrect connection configuration** | ❌ **RULED OUT** | DSN, charset, driver options all resolve correctly; the failure occurs inside the server, not in the client setup. |
| **Incorrect authentication plugin** | ❌ **RULED OUT** | Client is PHP 8.4.25 with **mysqlnd 8.4.25**, which supports `caching_sha2_password`. A plugin mismatch surfaces as error **2054** / "authentication method unknown", never 1045. We receive 1045. |
| **Incorrect host matching** | ❌ **RULED OUT** | The server resolves the `127.0.0.1` TCP connection to **`@'localhost'`**, which is exactly the host spec the project's own [mysql_create_databases.sql](../database/setup/mysql_create_databases.sql) grants (`'…'@'localhost'`). Client and intended grant agree. |
| **Missing database privileges** | ❌ **NOT THE CURRENT CAUSE** | A privilege problem returns **1044**; a missing database returns **1049**. Neither appears — the connection never reaches the privilege or database stage. |
| **Wrong port** | ✅ **CONFIRMED, AND REPAIRED** | `.env` said `3308`; nothing listens there (error **2002**). Server is on 3306. Fixed in §4 — the error advanced from 2002 to 1045, proving the network path now works. |
| **Incorrect username** | ⚠️ **POSSIBLE — cannot be distinguished** | see below |
| **Incorrect password** | ⚠️ **POSSIBLE — cannot be distinguished** | see below |

### Why the last two cannot be separated without an administrator

MySQL deliberately returns an identical 1045 for "no such user" and "wrong password", so an attacker
cannot enumerate accounts. This was verified with a control probe rather than assumed:

```
definitely NONEXISTENT user  : [1045] Access denied for user '<random-user>'@'localhost' (using password: YES)
configured TEST identity     : [1045] Access denied for user '<configured-user>'@'localhost' (using password: YES)
```

**Structurally identical.** Probes with and without a password were also run for both identities;
all four returned 1045, with `using password: YES`/`NO` correctly reflecting what was sent — meaning
the credentials are transmitted properly and rejected by the server.

### Conclusion

> **The MySQL accounts either do not exist, or their stored passwords differ from the values in
> `.env`.** Everything else in the chain — network, port, host matching, client capability,
> environment loading, connection configuration — is verified correct.

**Supporting observation (a hypothesis, not proof):** `DB_TEST_PASSWORD` is 64 characters, the shape
of a generated random secret, while `DB_USERNAME`/`DB_PASSWORD` are 5 and 7 characters, the shape of
a personal account. This is consistent with the test credentials having been *generated and written
into `.env`* while the corresponding `CREATE USER` was never executed on the server — which matches
[docs/MYSQL_VERIFICATION.md](MYSQL_VERIFICATION.md), where the project records itself as
"NOT VERIFIED — blocked on credentials" with no migration ever applied. It is a hypothesis; only the
administrator check in §7 can confirm it.

**No attempt was made to guess, brute-force, or try alternative credentials**, and no privileged
account was used.

---

## 4. Changes Made

**Exactly one file was modified: `.env`. Exactly two lines changed.**

```diff
- DB_PORT=3308          (line 31)
+ DB_PORT=3306
- DB_TEST_PORT=3308     (line 105)
+ DB_TEST_PORT=3306
```

Justification: the server demonstrably listens on 3306 and nothing listens on 3308 — connections to
3308 failed with error 2002 (no server), and after the change the same connections reach the server
and fail at authentication instead. The network path is proven repaired.

### Safety measures taken

- **Backup before edit:** `.env.backup-20261118-131810`, byte-identical to the original
  (sha256 match verified). It is **gitignored** — `.gitignore` carries `/.env.*` with explicit
  exceptions only for the two `*.example` templates, which was confirmed with `git check-ignore`
  before writing the file.
- **Surgical edit:** anchored `sed` on the two exact lines; no rewrite of the file.
- **Post-edit verification:**
  - `diff` against the backup shows **4 changed lines (2 pairs)** — only the two port lines.
  - Line count **107 → 107** (unchanged).
  - Key count **57 → 57**, and the ordered list of key **names** is identical.
  - No secret value was read, printed, or altered.

### Files NOT changed

- `.env.example` and `.env.production.example` — already correct (`DB_PORT=3306`,
  `# DB_TEST_PORT=3306`) and verified to contain **no populated credential values**.
- `my.ini` — sha256 unchanged.
- No application code, no database, no MySQL account.

---

## 5. Connection Test Results

All probes connect only. No `CREATE`, `DROP` or `ALTER` was issued.

**Effective targets after repair:** DEV → `127.0.0.1:3306` db `mauro_library_dev` · TEST →
`127.0.0.1:3306` db `mauro_library_test`

| Test | Target | Result |
|---|---|---|
| Network reachability | `127.0.0.1:3306` | ✅ **TCP OK, MySQL greeting present** |
| **1. Server connection** | DEV identity → server (no db) | 🔴 **AUTH FAILED (1045)** |
| **1. Server connection** | TEST identity → server (no db) | 🔴 **AUTH FAILED (1045)** |
| **2. Development database** | DEV identity → `mauro_library_dev` | 🔴 **AUTH FAILED (1045)** |
| **3. Test database** | TEST identity → `mauro_library_test` | 🔴 **AUTH FAILED (1045)** |

### Do the databases exist?

**UNDETERMINED — and it cannot be answered yet.** Authentication fails *before* MySQL resolves the
database name, so the server never reveals whether `mauro_library_dev` or `mauro_library_test`
exists. A missing database would surface as **1049** and a missing grant as **1044**; neither
appears. The data directory cannot answer it either — it is ACL-restricted to `NetworkService`
(verified: `Access is denied`, including for the known-present `mysql` and `sys` schemas used as
controls, which proves that method unusable here rather than proving absence).

**Does the configured account have the necessary access?** Also undetermined, for the same reason.
Access can only be assessed once authentication succeeds.

### Measurable progress

The failure mode advanced from **2002 (no server at that address)** to **1045 (server reached,
credentials rejected)**. That is a real, verifiable step: the transport is now correct and exactly
one problem remains.

---

## 6. Remaining Blockers

| ID | Blocker | Status |
|---|---|---|
| **B1** | Wrong port `3308` in `.env` | ✅ **RESOLVED** this phase |
| **B2** | **Error 1045 — accounts missing or passwords mismatched** | 🔴 **OPEN — needs administrator action (§7)** |
| **B3** | Existence of `mauro_library_dev` / `mauro_library_test` undetermined | 🔴 **OPEN — unanswerable until B2 clears** |
| **B4** | `DB_DRIVER=sqlite` — the app still runs on SQLite, so no MySQL path is exercised | 🟡 **OPEN — deliberately left alone; flipping it before B2 clears would only break the running dev site** |
| **B5** | [docs/MYSQL_VERIFICATION.md](MYSQL_VERIFICATION.md) is stale: claims "MySQL 26.7", service `MySQL267`, port 3308, "8 migrations", and contradicts itself on the port. Reality: **8.0.46**, `MySQL80`, **3306**, **11** migrations. | 🟡 **OPEN — documentation only** |

---

## 7. Manual Actions Required (administrator)

Everything below needs a MySQL **administrator** account, which this phase deliberately did not use
and does not possess. **Do not** reset the root password as part of this work — that is a
server-level change outside MAURO's scope.

### Step 1 — Determine which of the two causes applies (read-only)

```powershell
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -h 127.0.0.1 -P 3306 -u root -p
```

```sql
SELECT user, host, plugin FROM mysql.user WHERE user LIKE 'mauro%' OR user = '<the DB_USERNAME value>';
SHOW DATABASES LIKE 'mauro%';
```

This single query settles B2 **and** B3:

- **No rows** → the accounts were never created → go to Step 2a.
- **Rows exist** → the accounts exist but the passwords differ from `.env` → go to Step 2b.
- `SHOW DATABASES` simultaneously answers whether the two schemas exist.

### Step 2a — Accounts missing: create them

Use the project's own script, which is already correct for this server (its `@'localhost'` grants
match how MySQL resolves `127.0.0.1` connections here — verified in §3):

**[database/setup/mysql_create_databases.sql](../database/setup/mysql_create_databases.sql)**

Replace both `<SET_DEV_PASSWORD>` / `<SET_TEST_PASSWORD>` placeholders with strong, distinct
passwords. The script uses `IF NOT EXISTS` throughout, so it is safe against a partially-created
setup. It creates `mauro_library_dev` and `mauro_library_test` (utf8mb4 / utf8mb4_unicode_ci) and
two users, **each scoped to one database only**.

### Step 2b — Accounts exist with different passwords

Either set the `.env` values to the passwords actually in use, or set new passwords on the server.
**Do not guess**; and do not reuse any production credential in the test environment.

### Step 3 — Align `.env`

Set `DB_USERNAME` / `DB_PASSWORD` and `DB_TEST_USERNAME` / `DB_TEST_PASSWORD` to the accounts that
actually exist. If Step 2a was used, the dev user is `mauro_dev` and the test user is `mauro_test`.
Passwords go **only** into `.env` — never into git, chat, or a command line.

### Step 4 — Verify (non-destructive, no elevation needed)

```bash
php bin/console db:check          # expect: OK server 8.0.46 — InnoDB: DEFAULT
php bin/console db:check --test   # expect: the same
```

**Both must return OK before anything else proceeds.**

### Step 5 — Switch development onto MySQL (clears B4)

Comment out `DB_DRIVER=sqlite` in `.env`. Keep the SQLite file; it remains useful as a fast harness.

---

## Acceptance Criteria

| Criterion | Result |
|---|---|
| `MySQL80` remains healthy | ✅ **PASS** — Running, 8.0.46, both ports owned by PID 5996, `my.ini` sha256 unchanged |
| Port configuration is correct | ✅ **PASS** — `DB_PORT` and `DB_TEST_PORT` now `3306`, network path proven |
| Development connection succeeds | 🔴 **FAIL** — 1045 |
| Test connection succeeds | 🔴 **FAIL** — 1045 |
| No credentials exposed | ✅ **PASS** — all identities and secrets scrubbed from every output |
| No existing data modified | ✅ **PASS** — no SQL write, no schema change, no account change, no `my.ini` change |

### 🔴 PHASE RESULT: BLOCKED

Four of six criteria pass. The two connection criteria cannot pass without the administrator action
in §7, because the remaining cause lies in the MySQL account store, which this phase has no
credentials to read or modify — by design.

**Phase 2 has not been started.** No migration was run. Once `db:check` and `db:check --test` both
return OK, the environment is ready for migrations.
