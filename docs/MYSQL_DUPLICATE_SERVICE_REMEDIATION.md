# MySQL `MySQL80_New` Duplicate Service — Remediation Record

**Status: PHASE 4 COMPLETE — reboot verified, 7/7 PASS. The duplicate instance is gone.**
`MySQL80_New` is **Stopped / Disabled** and started no processes at boot. `MySQL80` came up clean and
owns both ports. Exactly one MySQL server process exists, so **nothing is competing for the
datadir any more**. Phase 6 (deleting the service registration) remains **unauthorized** and
pending explicit approval.

| | |
|---|---|
| Host | Windows 11 Pro (10.0.26300), `MASSAD\DELL` |
| MySQL | 8.0.46 (`C:\Program Files\MySQL\MySQL Server 8.0`) |
| Diagnostic baseline | Investigation report, 2026-11-18 |
| This record started | 2026-11-18 12:38 |
| Last updated | 2026-11-18 13:08 (Phases 1–4 complete and verified; Phase 6 pending approval) |
| Reboot | 2026-11-18 13:01:30 (manual, operator-authorized) |

---

## 1. Initial Diagnosis

Two Windows services are registered against **one** MySQL installation:

```
MySQL80      "...\bin\mysqld.exe" --defaults-file="C:\ProgramData\MySQL\MySQL Server 8.0\my.ini" MySQL80
MySQL80_New  "...\bin\mysqld.exe" --defaults-file="C:\ProgramData\MySQL\MySQL Server 8.0\my.ini" MySQL80_New
```

The only difference is the trailing service-name argument. MySQL uses that argument to also read a
configuration section named after the service — so the decisive question is whether `my.ini`
defines per-service sections. **It does not.**

Complete section inventory of `C:\ProgramData\MySQL\MySQL Server 8.0\my.ini` (15,545 bytes,
sha256 `42ed51cc…5bfd0`, last modified 2026-11-12 16:13:31):

```
line  55: [client]
line  63: [mysql]
line  75: [mysqld]
```

There is no `[MySQL80]` section and no `[MySQL80_New]` section. **Both services therefore resolve to
the identical `[mysqld]` block**, and so to the same datadir and the same ports:

| Setting | Value (shared by both services) |
|---|---|
| `port` | 3306 |
| `mysqlx_port` | 33060 |
| `datadir` | `C:/ProgramData/MySQL/MySQL Server 8.0\Data` |
| `log-error` | `AYHM.err` (inside datadir) |
| `log-bin` | `AYHM-bin` — **binary logging enabled** |
| `server-id` | 1 |

There is exactly one `my.ini` and one `Data` directory on the system. `Data` was created
2026-11-12 16:13:32 — one second after `my.ini` was last written — indicating both were produced by
the same MySQL Installer run, which is the most likely origin of the duplicate registration.

### Root cause

`MySQL80_New` is a duplicate service registration that never received its own configuration
section. It consequently competes with `MySQL80` for the same datadir and the same TCP ports, loses
the race at every boot, and parks indefinitely in `Start Pending` with a live but wedged server
child process.

It is **not** a mere stale registry entry: it has two real processes. It is also **not** a
crash-restart loop: no process has restarted since boot.

---

## 2. Before / After Service Status

### Before (Phase 1 preflight, 2026-11-18 12:38 — re-identified, not taken from the earlier snapshot)

| Service | State | Status | StartMode | Monitor PID | Server PID | Threads (mon/srv) | AcceptStop |
|---|---|---|---|---|---|---|---|
| **MySQL80** | Running | OK | Auto | 5536 | **6432** | 4 / **40** | True |
| **MySQL80_New** | Start Pending | OK | Auto | 5528 | **6592** | 3 / **13** | **False** |

Process tree (`Win32_Process`, parent/child verified):

```
services.exe (1016)
├── mysqld.exe 5528   MySQL80_New  monitor   3 thr   -> mysqld.exe 6592  server  13 thr  (no sockets, wedged)
└── mysqld.exe 5536   MySQL80      monitor   4 thr   -> mysqld.exe 6432  server  40 thr  (owns 33060 + 3306)
```

All four processes started **2026-11-18 11:58:36**. PIDs and start times were identical at the start
and end of this session — **no restarts occurred**.

### After (Phase 2 applied by the administrator, then verified 2026-11-18)

| Service | State | Status | StartMode | Registry `Start` | Monitor PID | Server PID | Threads (mon/srv) | AcceptStop |
|---|---|---|---|---|---|---|---|---|
| **MySQL80** | Running | OK | Auto | **2** (unchanged) | 5536 | 6432 | 4 / 40 | True |
| **MySQL80_New** | Start Pending | OK | **Disabled** | **4** (was 2) | 5528 | 6592 | 3 / 13 | False |

`State: Start Pending` persisting for `MySQL80_New` is **expected and correct** — disabling startup
does not terminate an already-running service, and this one cannot be stopped gracefully in any case
(`AcceptStop = False`). The wedged processes clear at reboot.

**Exactly one value changed on the whole system:** `MySQL80_New`'s `START_TYPE`, from
`2 AUTO_START` to `4 DISABLED`. Everything else — `TYPE`, `ERROR_CONTROL`, `BINARY_PATH_NAME`,
`LOAD_ORDER_GROUP`, `DEPENDENCIES`, `SERVICE_START_NAME` — is byte-identical to the baseline on both
services, and `MySQL80`'s configuration is untouched.

---

## 3. Commands Executed

| # | Phase | Command | Result |
|---|---|---|---|
| 1 | 1 | `Get-Service MySQL80, MySQL80_New` | OK — read-only |
| 2 | 1 | `Get-CimInstance Win32_Service -Filter "Name='MySQL80' OR Name='MySQL80_New'"` | OK — read-only |
| 3 | 1 | `Get-CimInstance Win32_Process -Filter "Name='mysqld.exe'"` | OK — read-only |
| 4 | 1 | `sc.exe qc MySQL80` / `sc.exe qc MySQL80_New` | OK — read-only |
| 5 | 1 | `sc.exe enumdepend MySQL80_New` | OK — `entriesread = 0` |
| 6 | 1 | `netstat -ano` | OK — read-only (view truncated, see §4) |
| 7 | 1 | TCP connect + MySQL handshake read on 3306 / 33060 | OK — no authentication attempted |
| 8 | 1 | `Get-ItemProperty HKLM:\SYSTEM\CurrentControlSet\Services\MySQL80_New` | OK — read-only |
| 9 | 1 | `sha256sum my.ini`, `stat my.ini` | OK — baseline recorded |
| 10 | 2 | `sc.exe config MySQL80_New start= disabled` *(attempted by Claude, unelevated)* | FAILED — `[SC] OpenService FAILED 5: Access is denied.` exit code 5. **No change made.** |
| 11 | post | Re-ran checks 2, 3, 7, 9 | Confirmed nothing changed |
| 12 | **2** | **`sc.exe config MySQL80_New start= disabled`** *(executed by the administrator, elevated)* | **`[SC] ChangeServiceConfig SUCCESS`** |
| 13 | gate | Re-ran checks 1–8 (see §4, Phase 2 verification gate) | **8 / 8 PASS** |

**No command stopped, started, paused, or terminated any service or process. No file was written.**
The sole system change is `MySQL80_New`'s startup mode.

---

## 4. Verification Outputs

### Phase 1 — preflight safety gates

| Gate required by the runbook | Result |
|---|---|
| `MySQL80` is Running | ✅ `State: Running`, `Status: OK`, `ExitCode: 0` |
| Its server process is healthy | ✅ PID 6432, 40 threads, no restart since 11:58:36 |
| `MySQL80_New` is the duplicate | ✅ same binary, same `--defaults-file`, no own config section |
| No dependencies for `MySQL80_New` | ✅ `DependentServices: NONE`, `ServicesDependedOn: NONE`, `enumdepend entriesread = 0` |
| Both reference the same config file | ✅ byte-identical path in `Win32_Service`, `sc qc`, and the registry |

**All Phase 1 gates passed.**

### Primary instance health — live protocol handshake (no authentication)

```
bytes read      : 78
protocol        : v10
server version  : 8.0.46
```

Re-confirmed after the failed Phase 2 attempt: `handshake OK, version = 8.0.46`.

### Phase 2 — actual output

```
> sc.exe config MySQL80_New start= disabled
[SC] OpenService FAILED 5:

Access is denied.

exit code: 5
```

Post-command verification proves no change took effect:

```
Name        State         StartMode
MySQL80_New Start Pending Auto

MySQL80_New registry Start = 2      (2 = Auto; 4 would be Disabled)
```

### Integrity after the failed attempt

| Indicator | Baseline | After | Verdict |
|---|---|---|---|
| `my.ini` sha256 | `42ed51cc…5bfd0` | `42ed51cc…5bfd0` | ✅ identical |
| `my.ini` size / mtime | 15545 / 2026-11-12 16:13:31 | 15545 / 2026-11-12 16:13:31 | ✅ identical |
| `Data` LastWriteTime | 2026-11-18 11:58:40 | 2026-11-18 11:58:40 | ✅ identical |
| mysqld PIDs | 5528, 5536, 6432, 6592 | 5528, 5536, 6432, 6592 | ✅ identical |
| Process start times | all 11:58:36 | all 11:58:36 | ✅ no restarts |
| Port 3306 | handshake 8.0.46 | handshake 8.0.46 | ✅ healthy |

### Phase 2 verification gate — 8 / 8 PASS (read-only, post-change)

| # | Check | Result | Verdict |
|---|---|---|---|
| 1 | `MySQL80` remains Running | `Running`, `Status: OK`, `ExitCode: 0`, `AcceptStop: True` | ✅ PASS |
| 2 | `MySQL80_New` Start Pending + **Disabled** | `Start Pending` / `StartMode: Disabled` / registry `Start = 4` | ✅ PASS |
| 3 | mysqld processes re-identified | 4 processes, PIDs 5528/5536/6432/6592, all started 11:58:36, **no restarts**; mapping `MySQL80 5536→6432 (40 thr)`, `MySQL80_New 5528→6592 (13 thr)` | ✅ PASS |
| 4 | Handshake on 3306 | `bytes=78  protocol=v10  server version=8.0.46` | ✅ PASS |
| 5 | PID ownership of 33060 | `Get-NetTCPConnection` → `OwningProcess 6432`; `netstat` → `0.0.0.0:33060` and `[::]:33060` `LISTENING 6432`; X Protocol returned 9 bytes (`0x0b` frame). **6432's parent is 5536 = MySQL80's monitor.** | ✅ PASS |
| 6 | `my.ini` SHA256 | `42ed51cc…5bfd0` — **MATCH**; size 15545, mtime 2026-11-12 16:13:31; still 3 sections, still no per-service section | ✅ PASS |
| 7 | Data directory unmodified | `LastWriteTime 11/18/2026 11:58:40` and `CreationTime 11/12/2026 16:13:32` — both identical to baseline | ✅ PASS |
| 8 | No other service config changed | Only `MySQL80_New` `START_TYPE` changed (2→4). `MySQL80` fully unchanged. No third MySQL/MariaDB service exists. | ✅ PASS |

Check 5 required several sampling attempts because this session's socket table is intermittent
(see note 1 below); it was ultimately confirmed by three independent methods that agree.

### Two observations recorded for accuracy

1. **`netstat` output in this session is truncated.** The full `LISTENING` table shows only
   **6 rows** machine-wide — far fewer than any Windows 11 host really has. Port 33060 → PID 6432
   was captured directly, but port 3306 never appears. Its absence is a limitation of the sandboxed
   socket view, not evidence about the listener. Port 3306 is attributed to PID 6432 by
   corroboration: 6432 demonstrably owns 33060 (set in the same `[mysqld]` block as `port=3306`),
   the handshake version matches the installed binary exactly, PID 6592 owns no sockets at all, and
   there is no `netsh portproxy` rule, no Docker, no WSL, and no other running MySQL/MariaDB
   service. The XAMPP MariaDB tree at `C:\xamppa01\mysql` exists on disk but its service is not
   running.

2. **Correction to the earlier investigation report.** That report theorised the `MySQL80_New`
   monitor was holding off the Service Control Manager by advancing its start checkpoint. The
   service's actual control block disproves this:

   ```
   Name        State         AcceptStop AcceptPause CheckPoint WaitHint
   MySQL80_New Start Pending      False       False          0     5000
   ```

   `CheckPoint = 0` and `WaitHint = 5000` are a **stale, no-longer-updated** status: the service
   stopped talking to the SCM altogether and the SCM simply left it in `Start Pending`. The
   practical consequence strengthens the plan — `AcceptStop = False` means the service **cannot be
   stopped gracefully at all**, so a controlled reboot is not merely the safest route, it is the
   only one that clears the wedged processes without force-terminating them.

---

## 5. Reboot Verification (Phase 4) — ✅ COMPLETE, 7 / 7 PASS

Reboot performed manually by the operator. **Last boot 2026-11-18 13:01:30**; verification run at
**13:08:41** (uptime 7 min). All checks read-only.

| # | Check | Actual result | Verdict |
|---|---|---|---|
| 1 | `MySQL80` status | `Running`, `Status: OK`, `ExitCode: 0`, `AcceptStop: True`, svc PID **4716** | ✅ PASS |
| 2 | `MySQL80_New` status + StartMode | `Stopped`, **`StartMode: Disabled`**, `ProcessId: 0`, **`ExitCode: 1077`** | ✅ PASS |
| 3 | Processes + parent/child | `4716` (PPID 704 = `services.exe`, 4 thr) → `5996` (PPID 4716, **40 thr**) | ✅ PASS |
| 4 | Exactly two, both MySQL80 | **count = 2**; classified `SERVICE MONITOR` + `SERVER (child of monitor)`; **zero orphans** | ✅ PASS |
| 5 | Handshake on 3306 = 8.0.46 | `bytes=78  protocol=v10  server version=8.0.46` → **MATCH = True** | ✅ PASS |
| 6 | Port 33060 ownership | `OwningProcess 5996` (`mysqld`) — **and 3306 also captured directly: `OwningProcess 5996`** | ✅ PASS |
| 7 | No duplicate on the datadir | **server process count = 1** (expected 1); one named pipe `mysqld4716_pipe`; only two MySQL services exist, one Stopped/Disabled | ✅ PASS |

**`ExitCode 1077` is `ERROR_SERVICE_NEVER_STARTED`** — "no attempts to start the service have been
made since the last boot". This is the precise, expected value for a disabled service, and it is
positive proof that `MySQL80_New` **did not even attempt to start**. The two wedged processes from
before the reboot (5528 / 6592) are gone.

**Port 3306 ownership was captured directly for the first time this session** (`OwningProcess 5996`),
closing the attribution gap noted earlier — the sandbox socket table cooperated after the reboot.
Both 3306 and 33060 belong to the same process, PID 5996, the MySQL80 server.

### Supporting evidence

```
all mysqld processes post-reboot
  PID 4716   PPID 704    thr 4    -> SERVICE MONITOR
  PID 5996   PPID 4716   thr 40   -> SERVER (child of service monitor)
  server process count = 1   (expected: 1)

named pipes:  mysqld4716_pipe          (one monitor only; was two before)

datadir LastWriteTime = 11/18/2026 13:01:47   (17 s after boot — a single normal startup write)

System event log since boot: no MySQL-related events  ->  clean start, no failures
```

**Note on the datadir timestamp:** it moved from `11:58:40` to `13:01:47`. This is **expected and
correct**, not an anomaly — MySQL writes to its data directory every time it starts. The directory's
`CreationTime` is unchanged at `11/12/2026 16:13:32`, and `my.ini` is still byte-identical
(sha256 `42ed51cc…5bfd0`, 15545 bytes, mtime 2026-11-12 16:13:31, still 3 sections with no
per-service section).

---

## 6. Phase 5 — Error Log Investigation (ATTEMPTED post-reboot; still needs elevation)

Re-attempted read-only after the reboot. Still refused:

```
NOT readable from this session: Access is denied
```

The `Data` directory remains ACL-restricted to `NT AUTHORITY\NetworkService`, so this remains the
one open evidentiary item. It is **informational only** — it would confirm *which* resource the
duplicate failed to acquire (InnoDB datadir lock vs TCP bind), but the remediation is already
verified complete without it. To collect it, run in an **elevated** session:

```powershell
Get-Content "C:\ProgramData\MySQL\MySQL Server 8.0\Data\AYHM.err" -Tail 80
```

The `Data` directory is ACL-restricted to `NT AUTHORITY\NetworkService` and is unreadable from an
unelevated session (verified: `Access is denied`, including for the known-present `mysql` and `sys`
subdirectories used as controls). This file holds the definitive reason the duplicate failed around
**2026-11-18 11:58:36** — expected to be either an InnoDB datadir lock conflict
(`Unable to lock ./ibdata1`) or a TCP bind conflict (`Address already in use`).

Note that MySQL writes diagnostics to this file rather than the Windows Event Log
(`log-output=FILE`), which is why the System and Application logs contain **zero** MySQL events —
confirmed readable in this session by retrieving unrelated SCM failure events for other services.

This phase is informational. It does not change the diagnosis or the recommended action.

---

## 7. Duplicate Registration Removal (Phase 6) — NOT AUTHORIZED YET

```powershell
sc.exe delete MySQL80_New      # elevated; only after Phase 4 verification AND explicit approval
Get-Service MySQL80, MySQL80_New -ErrorAction SilentlyContinue
```

Safe because `MySQL80_New` has no dependencies in either direction (verified). This removes only the
Windows service registration — **no** MySQL file, binary, configuration file, or database directory.

---

## 8. Remaining MAURO Blockers (separate from this remediation)

These are independent of the duplicate service and are **not** resolved by it:

| ID | Blocker | Detail |
|---|---|---|
| **B1** | **Wrong port in `.env`** | `DB_PORT=3308` and `DB_TEST_PORT=3308`; the server listens on **3306**. Nothing listens on 3308 (`err 2002`). |
| **B2** | **Authentication failure (error 1045)** | Both the dev and test identities are rejected on 3306. 0 of 4 probe combinations connected. Users do not exist, or the stored passwords differ from `.env`. |
| **B3** | **Database existence undetermined** | The probe returns `1045` (access denied), not `1049` (unknown database), so authentication fails before database resolution. `mauro_library_dev` / `mauro_library_test` may or may not exist. The datadir check cannot answer this (ACL-denied). |
| **B4** | **MAURO runs on SQLite** | `DB_DRIVER=sqlite`, so no MySQL code path is exercised in development at all. |
| **B5** | **Documentation is stale** | `docs/MYSQL_VERIFICATION.md` states "MySQL 26.7", service `MySQL267`, port 3308, and "8 migrations". Reality: **8.0.46**, `MySQL80`, port **3306**, **11** migrations. The document also contradicts itself on the port. |

Good news already established: MySQL **8.0.46** is above the 8.0.16 threshold, so the `CHECK`
constraints in MAURO's migrations **will be enforced** rather than silently ignored. `mysqldump.exe`
is also present (though not on `PATH`), which unblocks the backup tooling.

---

## 9. Final Operational Recommendation

**Do not restart `MySQL80`, and do not terminate any `mysqld` process.** The wedged duplicate holds
no lock and owns no socket, so it is inert where it is. Force-terminating processes risks hitting
PID 6432 — the live server, with binary logging enabled — and `AcceptStop = False` means the
duplicate cannot be stopped gracefully in any case. A controlled reboot is the clean path.

Recommended sequence, lowest commitment first:

1. ✅ **DONE** — `sc.exe config MySQL80_New start= disabled`, executed elevated by the administrator,
   returned `[SC] ChangeServiceConfig SUCCESS`, verified 8/8. Config-only, fully reversible
   (`start= auto` undoes it); touched no process and no data. **The recurring boot-time race is
   already eliminated** — even if the machine is never rebooted, the duplicate will not start again.
2. ✅ **DONE** — controlled reboot at **2026-11-18 13:01:30**, performed manually by the operator.
   The two wedged processes (5528 / 6592) were cleared without anything being force-terminated.
3. ✅ **DONE** — Phase 4 read-only verification: **7 / 7 PASS** (§5).
4. ⏳ **`sc.exe delete MySQL80_New`** — **awaiting explicit approval.** Elevated, removes only the
   Windows service registration; no MySQL file, binary, config or database directory is touched.
   Safe because the service has no dependencies in either direction (verified twice).

**The remediation objective is already met.** The duplicate no longer starts, no longer runs, and
no longer contends for the datadir. Step 4 is housekeeping — it removes a dormant, disabled entry
so nobody re-enables it by accident later. Leaving it disabled indefinitely is also a defensible
choice.

Current data risk is **LOW**: nothing is corrupting anything, and the primary instance is healthy.
Forward risk is **HIGH** and recurring — the race repeats on every boot, and it is most dangerous
precisely when the service is restarted or MAURO's first migration runs. Resolving this before
Phase 2 migrations means the first schema write lands in an uncontested datadir.

**No backup was taken, and none is currently possible:** blocker B2 means `mysqldump` cannot
authenticate. Steps 1–4 are configuration-only and write nothing to the datadir, so they are safe
without one.
