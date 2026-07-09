# Change Log

## Version 1.10.0
1. **Resource cache (opt-in, additive).** When enabled and the target export
   service advertises support, the client omits assets it believes are already
   cached server-side from the upload zip and lists them in a `resourceManifest`
   instead — so KoolReport library resources no longer have to be zipped into
   every request. Enable with a `resourceCache` settings block; it is **OFF by
   default** and, when off or unsupported, the wire is byte-identical to before.
   ```php
   'resourceCache' => [
       'enabled'   => true,          // opt in (default false)
       'cacheDir'  => sys_get_temp_dir(), // writable; NEVER the package dir
       // optional: 'sync' => true, 'syncInterval' => 86400, 'capabilityTtl' => 300,
   ]
   ```
2. Fidelity-safe by construction: membership is tested against the sha256 of the
   **actual local bytes**, so a locally patched asset simply misses the set and
   is uploaded normally — a cache hit can never swap in the wrong bytes.
3. Belief set = BUNDLED (the shipped `data/koolreport-hashset.json`) ∪ SYNCED
   (global shareable hashes pulled from `GET /api/cache/manifest`, off the hot
   path) ∪ SELF (this install's own server-confirmed uploads). SYNCED+SELF are
   persisted atomically in `cacheDir`, keyed by (endpoint, token).
4. Resilient: on a `409 { missing }` the client re-sends once with just the
   missing assets added back; on ANY cache-path problem it falls back to a plain
   full-zip export. A resource-cache issue never breaks or blocks an export.

This release also folds in the **resilience + speed batch** (previously staged
as 1.9.0, not separately released):

5. Bounded timeouts on every resource/page fetch (`resourceTimeout` = 10s,
   `pageTimeout` = 60s) — a dead resource URL no longer stalls the export.
6. Opt-in retry with exponential backoff honoring `Retry-After` (`retries` = 0
   by default = exact prior behavior; only curl errno 6/7/28/35/52/56 or HTTP
   502/503/504 are retried).
7. `getWarnings()` on `Exporter`/`Service`: failed resource downloads are now
   inspectable (url + reason) instead of silent.
8. Opt-in parallel resource downloads via `curl_multi` (`parallelDownloads` =
   false by default, `parallelConcurrency` = 8), chunked so every URL is
   prefetched; byte-identical output vs sequential mode, enforced by tests.

No wire-format change from any of the above: endpoint, multipart fields,
defaults and auth header are untouched.

## Version 1.8.0
1. Allow the diagnostic flags `logTiming` and `returnTiming` to be set via `settings` (they are forwarded into the export options), in addition to the pdf/image options. An explicit option value still takes precedence.

## Version 1.7.0
1. Support the string form of `@import` (`@import "x.css"`) in addition to `@import url(...)`, both in inline `<style>`/`style=""` and recursively inside downloaded stylesheets (previously the in-CSS recursion only resolved `url()`).

## Version 1.6.0
1. Fix resource collision: store downloaded resources keyed by their absolute URL (`md5(absoluteUrl)`) instead of `md5(basename)`, so two resources sharing a filename in different folders no longer overwrite each other.
2. Harden URL resolution: resolve `./` and `../` segments, drop `#fragments`, and ignore query strings when detecting a resource's extension.
3. Add a zero-dependency test suite (`tests/run-tests.sh`): `resolveUrl` unit cases + a collision integration test served by `php -S`.

## Version 1.5.0
1. Verify the export service's TLS certificate by default; add a `verifySsl` setting (`false` to opt out for self-signed / private servers).
2. Throw an exception instead of calling `exit()` on a failed (non-200) response, so callers can catch and handle/retry it instead of having the host script terminated.
3. Add a request timeout (`CURLOPT_TIMEOUT`, default 140s, overridable via the `timeout` setting) and a connect timeout to avoid hanging on a stalled service.
4. Always clean up the request's own temporary files (extracted folder + zip) on success or failure — fixes temp files accumulating in the system temp folder.
5. Fix `save()` falsely reporting failure when the exported content is empty.
6. Load `Exporter.php` via `include_once` with an absolute path.
7. Require PHP >= 5.5.

## Version 1.4.0
1. Fix dynamic property warning in PHP 8.2.
2. Fix margin issue: auto convert string to array. 

## Version 1.3.1
1. Fix Content-Type in sendToBrowser.

## Version 1.3.0
1. Add "serviceHost", "serviceUrl" setting to use a local cloud server.

## Version 1.2.0
1. Add exception catcher for url_get_content.
2. Fix handler's save's $filePath case sensitivity typo.

## Version 1.1.0

1. Replace url in every css file with downloaded files using relative path from the css file.

## Version 1.0.0

1. Replace file_get_contents with general url_get_contents using file_get_contents, fopen, or curl.

## Version 0.7.0

## Version 0.6.0