# Handoff — current state of all agents

Updated: 2026-07-09 (architect)

## Architect (Claude Code, pdf-export-service terminal)

- **task-038 (T2 resource cache client, 1.10.0) BUILT + SELF-VERIFIED + REVIEWED.**
  Architect-built directly (Opus 4.8) — production wire contract, Antigravity out,
  DeepSeek Flash too weak. New `src/ResourceCache.php` (belief set BUNDLED∪SYNCED∪SELF,
  atomic per-(endpoint,token) store with graceful no-writable-dir degradation, cached
  capability probe, gated failure-safe delta sync) + `Exporter.php` wiring (hash-and-omit
  into `resourceManifest`, 409 `{missing}` single re-send, X-Resource-Cached→SELF, HARD
  fallback to full-zip on any cache-path error). OPT-IN, default OFF, wire byte-identical
  when off/unsupported. VERIFIED from clean: `tests/run-tests.sh` EXIT 0, **107 assertions,
  9/9 files** (new unit 22 + service 22 via `tests/service-mock.php`) — no regression.
- Nothing committed — on branch `t2-resource-cache`, awaiting user commit gate.
- Server side (pdf-export-service) T2 is complete + pushed (034/035/036/037/040 reviewed);
  038 here is the client half. task-039 (e2e/perf) can run once both are green-lit.

## OPEN release item (user gate)
- composer bumped 1.8.0 → **1.10.0** (the resource-cache floor, per task spec). The **1.9.0
  resilience batch (task-001..004, committed as f02473a) was never formally released** —
  its CHANGELOG entry + tag are still pending. Decide: cut 1.9.0 first, or fold it into the
  1.10.0 notes. `data/koolreport-hashset.json` ships EMPTY — the release step copies the
  server's `dist/koolreport-hashset.json` (from task-037 pre-seed) into it.

## builder-1 (OpenCode + DeepSeek, php-client terminal)

- Status: idle. Queue empty. No builder action on task-038 (architect-built).
