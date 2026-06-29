# Change Log

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