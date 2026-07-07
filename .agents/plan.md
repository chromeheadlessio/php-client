# Plan: php-client resilience + speed for KoolReport customers (v1.9.0 line)

Date: 2026-07-07
Architect: Claude Code (pdf-export-service Terminal 1)
Status: ACTIVE — approved by user 2026-07-07

## Goal

Improve the php-client's resilience and export speed for KoolReport customers
**without any change to the export service** (production runs the ver4.0.0
service; it must keep working unchanged) and **without any wire-format change**.

## Hard constraints (apply to every task)

1. Wire contract frozen: endpoint path, multipart fields (`exportFormat`,
   `waitUntil`, `engine`, `fileToExport`, `options`), defaults, and the
   `Authorization: Bearer` header must not change.
2. New behavior is opt-in via `settings`, or has a backward-safe default
   explicitly stated in the task spec.
3. PHP 7.0-compatible syntax, no Composer deps, match existing code style.
4. `bash tests/run-tests.sh` must pass from a clean state after every task;
   every task adds/extends tests for its own behavior.

## Why these four tasks (customer impact)

- A single dead/slow resource URL in a report stalls export for PHP's default
  socket timeout (60s+) per resource, before the request even reaches the
  service → task-001 (bounded timeouts).
- Transient 5xx/connect failures surface as hard exceptions; the code comment
  at Exporter.php even promises callers can "back off on 503 + Retry-After"
  but nothing implements it → task-002 (opt-in retry).
- Failed resource downloads are silent (URL left as-is in the HTML) — "my PDF
  has no styling" support tickets → task-003 (warnings API).
- Resources download sequentially; 15 resources = 15 serial round-trips before
  upload, often dwarfing the ~2s service render time → task-004 (opt-in
  parallel downloads via curl_multi).

Tasks are chained (deps) because they all touch `src/Exporter.php`; one
builder, sequential execution, no merge conflicts.

## Out of scope (explicitly)

- Any change under the pdf-export-service repo (all branches).
- Changing default `CURLOPT_TIMEOUT` (140s), default engine, default
  `waitUntil`, or the default service host.
- Version bump + CHANGELOG + README/manual updates — architect does these at
  release time after reviewing the batch.

## Review & release

Architect reviews each task (run tests from clean state, read the diff), then
`bash scripts/archive-task.sh <id>`. After all four are reviewed: release
1.9.0 (CHANGELOG, manual update, tag) — architect-owned.
