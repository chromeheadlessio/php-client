# Decision log (architect-owned)

- 2026-07-07: v1.9.0 batch scoped to php-client ONLY — user directive: keep the
  ver4.0.0 production service intact; nothing in pdf-export-service changes.
- 2026-07-07: All new behavior opt-in via settings (retries=0,
  parallelDownloads=false by default). Exceptions: resourceTimeout=10s and
  pageTimeout=60s apply by default — bounded I/O is the bug fix itself;
  pageTimeout=60 matches PHP's default socket timeout so slow report pages do
  not regress.
- 2026-07-07: parallel downloads = pre-fetch cache feeding the EXISTING
  sequential rewrite flow (alternative considered: parallelizing inside the
  regex callback — rejected, would fork the rewrite logic and risk output
  drift). Correctness bar: byte-identical output vs sequential mode.
- 2026-07-07: retry policy is conservative: only curl errno 6/7/28/35/52/56 or
  HTTP 502/503/504; everything else throws unchanged so existing customer
  catch blocks keep working.
- 2026-07-07: tasks chained 001→002→003→004 (single builder, all tasks touch
  src/Exporter.php — no parallel-claim conflicts possible).
- 2026-07-07 (batch review): builder tests can't catch "output identical but
  slower" bugs — task-004's truncated prefetch window passed all tests while
  silently skipping every resource past the first 8. Lesson: for performance
  features, specs must include a case where the limit is SMALLER than the
  workload (now added: concurrency=2 vs 5-resource chain).
- 2026-07-07: architect direct fixes during review (fast-task rule): retries
  clamp (negative → PHP fatal via throw null), array_chunk prefetch
  restructure, select(-1) busy-spin guard, dead $parsedUrl removal.
