<?php

namespace chromeheadlessio;

/**
 * Internal signal: something on the resource-cache request path failed or the
 * server behaved unexpectedly. The caller catches this and retries the export
 * once as a plain full-zip request (no manifest). Never surfaces to callers.
 */
class ResourceCacheFallback extends \Exception
{
}

/**
 * Client-side resource-cache belief store for the export service's T2 resource
 * cache (server >= v2, php-client >= 1.10.0). OPT-IN and fully additive: when
 * disabled, or when anything goes wrong, the caller falls back to a plain
 * full-zip export — a cache problem must NEVER break an export.
 *
 * Belief set (a hash is "believed cached" if it is in ANY of):
 *   - BUNDLED : the read-only koolreport-hashset.json shipped in the package
 *               (the curated KoolReport library assets pre-seeded server-side).
 *   - SYNCED  : global shareable hashes pulled from GET /api/cache/manifest
 *               (assets other tenants uploaded that got promoted server-side).
 *   - SELF    : hashes this install has itself uploaded and had confirmed
 *               (the server's X-Resource-Cached response header).
 *
 * Membership is always tested against the sha256 of the ACTUAL local bytes
 * (never a path/version -> hash mapping), so a locally patched asset simply
 * misses the set and is uploaded normally — a cache hit can never swap in the
 * wrong bytes.
 *
 * SYNCED + SELF are persisted in a WRITABLE cache dir (default
 * sys_get_temp_dir()), NEVER inside the package/vendor dir, keyed by
 * (endpoint, token) so different servers/tenants stay isolated. Writes are
 * atomic (temp + rename) for concurrent PHP workers. If no writable dir is
 * available the store silently degrades to BUNDLED-only (+ 409 warm-up), which
 * is still correct.
 */
class ResourceCache
{
    /** @var array config sub-array (settings['resourceCache']) */
    private $config;
    /** @var string export endpoint URL (store key component) */
    private $endpoint;
    /** @var string secret token (store key component) */
    private $token;

    /** @var array<string,int> bundled hashes (hash => 1) */
    private $bundled = array();
    /** @var array<string,int> synced hashes (hash => 1) */
    private $synced = array();
    /** @var array<string,int> self-confirmed hashes (hash => 1) */
    private $self = array();
    /** @var int delta-sync cursor */
    private $cursor = 0;
    /** @var int last successful/attempted sync (unix ts) */
    private $syncedAt = 0;
    /** @var array|null capability cache: ['supported'=>bool,'ts'=>int] */
    private $capability = null;

    /** @var string|null resolved store file path, or null when not persistable */
    private $storePath = null;
    /** @var bool whether the persistent store has been loaded */
    private $loaded = false;

    /** process-static bundled-set cache, keyed by absolute artifact path */
    private static $bundledMemo = array();
    /** process-static capability memo, keyed by endpoint */
    private static $capMemo = array();

    public function __construct($config, $endpoint, $token)
    {
        $this->config = is_array($config) ? $config : array();
        $this->endpoint = (string) $endpoint;
        $this->token = (string) $token;
    }

    // ---- config helpers ---------------------------------------------------

    private function cfg($key, $default = null)
    {
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }

    /** Caching is opt-in: OFF unless settings.resourceCache.enabled === true. */
    public function isEnabled()
    {
        return $this->cfg('enabled', false) === true;
    }

    /** Delta-sync is on by default (but gated by syncInterval) when caching is on. */
    public function syncEnabled()
    {
        return $this->cfg('sync', true) !== false;
    }

    // ---- belief set -------------------------------------------------------

    /** True if $hash (sha256 hex of the actual bytes) is believed server-cached. */
    public function beliefHas($hash)
    {
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        $this->ensureBundled();
        $this->ensureLoaded();
        return isset($this->bundled[$hash])
            || isset($this->synced[$hash])
            || isset($this->self[$hash]);
    }

    private function ensureBundled()
    {
        if (!empty($this->bundled)) {
            return;
        }
        $path = $this->cfg('bundledHashSetPath', __DIR__ . '/../data/koolreport-hashset.json');
        if (!is_string($path) || $path === '') {
            return;
        }
        $real = realpath($path);
        $key = $real !== false ? $real : $path;
        if (isset(self::$bundledMemo[$key])) {
            $this->bundled = self::$bundledMemo[$key];
            return;
        }
        $map = array();
        if ($real !== false && is_readable($real)) {
            $raw = @file_get_contents($real);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                $hashes = null;
                if (is_array($data)) {
                    if (isset($data['hashes']) && is_array($data['hashes'])) {
                        $hashes = $data['hashes'];
                    } elseif (array_values($data) === $data) {
                        $hashes = $data; // bare sorted array form
                    }
                }
                if (is_array($hashes)) {
                    foreach ($hashes as $h) {
                        if (is_string($h) && $h !== '') {
                            $map[$h] = 1;
                        }
                    }
                }
            }
        }
        self::$bundledMemo[$key] = $map;
        $this->bundled = $map;
    }

    // ---- persistent learned store (SYNCED + SELF) -------------------------

    private function storeFilePath()
    {
        $dir = $this->cfg('cacheDir', sys_get_temp_dir());
        if (!is_string($dir) || $dir === '') {
            return null;
        }
        if (!is_dir($dir)) {
            // Best-effort create; if it fails we degrade to bundled-only.
            @mkdir($dir, 0700, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        $key = sha1($this->endpoint . '|' . $this->token);
        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'koolreport-cache-' . $key . '.json';
    }

    private function ensureLoaded()
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $this->storePath = $this->storeFilePath();
        if ($this->storePath === null || !is_file($this->storePath)) {
            return;
        }
        $raw = @file_get_contents($this->storePath);
        if ($raw === false || $raw === '') {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        foreach (array('synced', 'self') as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                foreach ($data[$k] as $h => $_v) {
                    if (is_string($h) && $h !== '') {
                        $this->{$k}[$h] = 1;
                    }
                }
            }
        }
        if (isset($data['cursor'])) {
            $this->cursor = (int) $data['cursor'];
        }
        if (isset($data['syncedAt'])) {
            $this->syncedAt = (int) $data['syncedAt'];
        }
        if (isset($data['capability']) && is_array($data['capability'])) {
            $this->capability = array(
                'supported' => !empty($data['capability']['supported']),
                'ts' => isset($data['capability']['ts']) ? (int) $data['capability']['ts'] : 0,
            );
        }
    }

    /** Atomically persist the learned store. No-op (safe) when not persistable. */
    private function persist()
    {
        if ($this->storePath === null) {
            return;
        }
        $payload = array(
            'v' => 1,
            'synced' => $this->synced,
            'self' => $this->self,
            'cursor' => $this->cursor,
            'syncedAt' => $this->syncedAt,
            'capability' => $this->capability,
        );
        $json = json_encode($payload);
        if ($json === false) {
            return;
        }
        $tmp = $this->storePath . '.tmp.' . getmypid() . '.' . uniqid('', true);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        // rename is atomic on POSIX; on failure clean up the temp file.
        if (!@rename($tmp, $this->storePath)) {
            @unlink($tmp);
        }
    }

    /** Merge server-confirmed upload hashes into SELF and persist. */
    public function noteConfirmed($hashes)
    {
        if (!is_array($hashes) || empty($hashes)) {
            return;
        }
        $this->ensureLoaded();
        $changed = false;
        foreach ($hashes as $h) {
            if (is_string($h) && $h !== '' && !isset($this->self[$h])) {
                $this->self[$h] = 1;
                $changed = true;
            }
        }
        if ($changed) {
            $this->persist();
        }
    }

    // ---- capability probe (cached) ----------------------------------------

    /**
     * Whether the server advertises resource-cache support. Cached in-process
     * and in the persistent store (TTL capabilityTtl, default 300s) so it is at
     * most one cheap GET per TTL window. Any failure => false => full-zip export.
     */
    public function capabilitySupported($serviceHost, $verifySsl)
    {
        $memoKey = (string) $serviceHost;
        if (isset(self::$capMemo[$memoKey])) {
            return self::$capMemo[$memoKey];
        }
        $this->ensureLoaded();
        $ttl = (int) $this->cfg('capabilityTtl', 300);
        if ($this->capability !== null && (time() - $this->capability['ts']) < $ttl) {
            return self::$capMemo[$memoKey] = (bool) $this->capability['supported'];
        }
        $url = rtrim((string) $serviceHost, '/') . '/api/capabilities';
        $json = $this->httpGetJson($url, $verifySsl, 5);
        $supported = is_array($json) && !empty($json['resourceCache']);
        $this->capability = array('supported' => $supported, 'ts' => time());
        $this->persist();
        return self::$capMemo[$memoKey] = $supported;
    }

    // ---- delta sync (off the export hot path) -----------------------------

    /**
     * Pull newly-promoted global hashes from GET /api/cache/manifest?since=cursor
     * and merge them into SYNCED. Gated by syncInterval (default daily) so it is
     * effectively never on the hot path. Never throws; a sync failure must not
     * block or break an export.
     */
    public function maybeSync($serviceHost, $verifySsl)
    {
        if (!$this->syncEnabled()) {
            return;
        }
        $this->ensureLoaded();
        $interval = (int) $this->cfg('syncInterval', 86400);
        if ($this->syncedAt > 0 && (time() - $this->syncedAt) < $interval) {
            return; // not due yet
        }
        $url = rtrim((string) $serviceHost, '/') . '/api/cache/manifest?since=' . rawurlencode((string) $this->cursor);
        $json = $this->httpGetJson($url, $verifySsl, 10);
        // Throttle regardless of outcome so a persistently-failing server is not
        // hammered once per export.
        $this->syncedAt = time();
        if (is_array($json) && isset($json['added']) && is_array($json['added'])) {
            foreach ($json['added'] as $h) {
                if (is_string($h) && $h !== '') {
                    $this->synced[$h] = 1;
                }
            }
            if (isset($json['cursor'])) {
                $this->cursor = (int) $json['cursor'];
            }
        }
        $this->persist();
    }

    // ---- low-level HTTP GET (JSON) ----------------------------------------

    /** GET a URL and json_decode it. Returns array on success, null on any failure. */
    private function httpGetJson($url, $verifySsl, $timeout)
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int) $timeout,
                CURLOPT_CONNECTTIMEOUT => min((int) $timeout, 10),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
                CURLOPT_SSL_VERIFYPEER => $verifySsl ? true : false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $this->token),
            ));
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno !== 0 || $code < 200 || $code >= 300 || !is_string($body) || $body === '') {
                return null;
            }
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // ---- test / diagnostic accessors --------------------------------------

    /** @return array{bundled:int,synced:int,self:int,cursor:int,storePath:?string} */
    public function stats()
    {
        $this->ensureBundled();
        $this->ensureLoaded();
        return array(
            'bundled' => count($this->bundled),
            'synced' => count($this->synced),
            'self' => count($this->self),
            'cursor' => $this->cursor,
            'storePath' => $this->storePath,
        );
    }

    /** Reset process-static memos (tests only). */
    public static function resetStaticCaches()
    {
        self::$bundledMemo = array();
        self::$capMemo = array();
    }
}
