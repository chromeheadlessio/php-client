<?php
// Pure unit test for Exporter::resolveUrl() — no network required.

require __DIR__ . '/../src/Exporter.php';

function check($cond, $msg)
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "ok: $msg\n";
}

$scheme = 'https';
$host   = 'https://ex.com';
$base   = 'https://ex.com/reports/r1'; // baseUrl carries no trailing slash (as saveTempContent produces)

$cases = array(
    array('style.css',           'https://ex.com/reports/r1/style.css'),   // plain relative
    array('./style.css',         'https://ex.com/reports/r1/style.css'),   // ./ stripped
    array('../shared/a.css',     'https://ex.com/reports/shared/a.css'),   // one ..
    array('../../top.css',       'https://ex.com/top.css'),                // two ..
    array('/abs/x.js',           'https://ex.com/abs/x.js'),               // root-relative
    array('//cdn.com/lib.js',    'https://cdn.com/lib.js'),                // protocol-relative
    array('https://o.com/a.css', 'https://o.com/a.css'),                   // already absolute
    array('style.css?v=2#frag',  'https://ex.com/reports/r1/style.css?v=2'), // keep query, drop fragment
    array('a/b/../c.png',        'https://ex.com/reports/r1/a/c.png'),     // .. in the middle
);

foreach ($cases as $c) {
    $got = \chromeheadlessio\Exporter::resolveUrl($c[0], $scheme, $host, $base);
    check($got === $c[1], "resolveUrl('{$c[0]}') => '$got' (want '{$c[1]}')");
}

echo "resolveUrl_test PASSED\n";
