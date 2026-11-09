<?php
/**
 * Knocklog log shipper for cPanel and other shared hosting (https://knocklog.ai)
 *
 * Every few minutes (cPanel → Cron Jobs) it reads the new lines of your site's access
 * log and sends them to Knocklog. Works for any site on the host (HTML, PHP, WordPress)
 * without touching it. Needs PHP 7.2 or newer on the command line.
 *
 * It reads the live log in ~/access-logs when the host allows it (new visits every few
 * minutes). Some hosts, HostGator among them, only keep the compressed copies cPanel
 * writes to ~/logs when it processes statistics, usually once a day: then it sends
 * each new part as soon as it appears.
 *
 * Install:
 *   1. Save this file OUTSIDE public_html, e.g. /home/USER/knocklog/knocklog-shipper.php
 *   2. Check it once (cPanel → Terminal, or a one-off cron):  php ~/knocklog/knocklog-shipper.php --check
 *   3. cPanel → Cron Jobs → every 5 minutes:  php ~/knocklog/knocklog-shipper.php >/dev/null 2>&1
 *
 * It remembers where it stopped (.knocklog-state.json next to this file), so lines are
 * never sent twice; if they ever are, Knocklog ignores the duplicates.
 */

const KNOCKLOG_ENDPOINT = 'https://api.knocklog.ai';
// Ingest-only key: it can send visits, it cannot read your data.
const KNOCKLOG_KEY = 'kl_ing_YOUR_INGEST_KEY';
const KNOCKLOG_SITE = 'example.com';
// Access logs to read (full paths; * matches anything, e.g. ~/logs/example.com-ssl_log-*.gz).
// Leave empty to find this site's logs in ~/access-logs or ~/logs (cPanel).
$KNOCKLOG_LOGS = array();

const MAX_BYTES_PER_POST = 1500000;
const MAX_LINES_PER_POST = 5000;
const MAX_POSTS_PER_RUN = 40;

if (isset($_SERVER['REQUEST_METHOD'])) {
    // Never runs from the web, even if the file ends up in public_html. (Some hosts run
    // cron jobs with the CGI build of PHP, so the check is "not a web request", not "CLI".)
    http_response_code(404);
    exit;
}
if (!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));

function knocklog_home()
{
    $home = getenv('HOME');
    if ($home) return rtrim($home, '/');
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $user = posix_getpwuid(posix_geteuid());
        if ($user && !empty($user['dir'])) return rtrim($user['dir'], '/');
    }
    return dirname(__DIR__);
}

/**
 * cPanel keeps each domain's live logs in ~/access-logs/<domain> and <domain>-ssl_log.
 * When those cannot be read, it uses the monthly archives in ~/logs, named
 * <domain>[-ssl_log]-Mon-YYYY.gz, or <addon domain>.<main domain>[-ssl_log]-Mon-YYYY.gz.
 */
function knocklog_find_logs($now = null)
{
    $home = knocklog_home();
    $site = preg_replace('~^www\.~', '', strtolower(KNOCKLOG_SITE));
    $found = array();
    foreach (array($site, 'www.' . $site) as $name) {
        foreach (array($home . '/access-logs/' . $name . '-ssl_log', $home . '/access-logs/' . $name) as $path) {
            if (is_file($path) && is_readable($path)) $found[] = $path;
        }
    }
    if ($found) return $found;
    return knocklog_find_archives($home . '/logs', $site, $now === null ? time() : $now);
}

function knocklog_find_archives($dir, $site, $now)
{
    $files = @scandir($dir);
    if (!$files) return array();
    // This month's archive and last month's (its last days arrive after the month ends).
    $months = array(gmdate('M-Y', strtotime('first day of last month', $now)), gmdate('M-Y', $now));
    $names = array_flip($files);
    $found = array();
    foreach ($months as $month) {
        foreach ($files as $file) {
            if (!preg_match('~^(?:www\.)?' . preg_quote($site, '~') . '(?:\.([a-z0-9.-]+))?(?:-ssl_log)?-' . preg_quote($month, '~') . '\.gz$~i', $file, $m)) continue;
            // <site>.<suffix> must be an addon domain's log: the suffix is the account's main
            // domain, which has its own archive (this skips e.g. <site>.blog, another domain).
            if (!empty($m[1]) && !isset($names[$m[1] . '-' . $month . '.gz']) && !isset($names[$m[1] . '-ssl_log-' . $month . '.gz'])) continue;
            if (is_readable($dir . '/' . $file)) $found[] = $dir . '/' . $file;
        }
    }
    return $found;
}

function knocklog_expand(array $configured)
{
    $paths = array();
    foreach ($configured as $entry) {
        $entry = preg_replace('~^\~/~', knocklog_home() . '/', $entry);
        if (strpos($entry, '*') === false) {
            $paths[] = $entry;
            continue;
        }
        $matches = glob($entry);
        // Archives: only those changed in the last 40 days.
        foreach ($matches ? $matches : array() as $path) {
            if (@filemtime($path) >= time() - 40 * 86400) $paths[] = $path;
        }
    }
    return $paths;
}

function knocklog_is_gz($path)
{
    return substr($path, -3) === '.gz';
}

function knocklog_request($method, $url, $body = null, $contentType = 'text/plain')
{
    $headers = array('Authorization: Bearer ' . KNOCKLOG_KEY, 'User-Agent: KnocklogShipper/1.0 (+https://knocklog.ai)');
    if ($body !== null) $headers[] = 'Content-Type: ' . $contentType;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        $options = array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        );
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        return array($status, $response === false ? $error : (string) $response);
    }
    $context = stream_context_create(array('http' => array(
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : $body,
        'timeout' => 60,
        'ignore_errors' => true,
    )));
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('~^HTTP/\S+\s+(\d{3})~', $http_response_header[0], $m)) $status = (int) $m[1];
    return array($status, $response === false ? 'request failed' : (string) $response);
}

/**
 * The next block of whole lines from $offset: at most MAX_LINES_PER_POST lines and
 * MAX_BYTES_PER_POST bytes. Returns array(text, bytes consumed); bytes is 0 when only a
 * partial last line is available (it is still being written).
 */
function knocklog_read_block($handle, $offset, $gz = false)
{
    if ($gz) {
        gzseek($handle, $offset);
        $data = gzread($handle, MAX_BYTES_PER_POST);
    } else {
        fseek($handle, $offset);
        $data = fread($handle, MAX_BYTES_PER_POST);
    }
    if ($data === false || $data === '') return array('', 0);
    $end = strrpos($data, "\n");
    if ($end === false) {
        // A single line longer than the whole block cannot be a log line: skip it.
        return strlen($data) >= MAX_BYTES_PER_POST ? array('', strlen($data)) : array('', 0);
    }
    $data = substr($data, 0, $end + 1);
    $pos = -1;
    for ($i = 0; $i < MAX_LINES_PER_POST; $i++) {
        $pos = strpos($data, "\n", $pos + 1);
        if ($pos === false) break;
    }
    if ($pos !== false && $pos + 1 < strlen($data)) $data = substr($data, 0, $pos + 1);
    return array($data, strlen($data));
}

function knocklog_main(array $argv, array $configured)
{
    $check = in_array('--check', $argv, true);
    $logs = $configured ? knocklog_expand($configured) : knocklog_find_logs();
    if (!$logs) {
        fwrite(STDERR, "Knocklog: no access log found for " . KNOCKLOG_SITE . " in " . knocklog_home() . "/access-logs or /logs.\n" .
            "Set \$KNOCKLOG_LOGS in this file to the log's full path (cPanel → Metrics → Raw Access shows the domain).\n");
        return 1;
    }

    if ($check) {
        echo "Knocklog: logs found:\n";
        foreach ($logs as $log) echo "  " . $log . " (" . filesize($log) . " bytes)\n";
        list($status, $body) = knocklog_request('GET', KNOCKLOG_ENDPOINT . '/v1/plan');
        if ($status === 200) {
            echo "Knocklog: the key works. Add the cron job and the data will show in your report within minutes.\n";
            return 0;
        }
        fwrite(STDERR, "Knocklog: the API answered $status: " . substr($body, 0, 300) . "\n");
        return 2;
    }

    $stateFile = __DIR__ . '/.knocklog-state.json';
    $lock = @fopen($stateFile . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return 0; // another run is still going
    $state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : array();
    if (!is_array($state)) $state = array();
    $files = isset($state['files']) && is_array($state['files']) ? $state['files'] : array();

    $posts = 0;
    $sent = 0;
    $error = null;
    foreach ($logs as $log) {
        clearstatcache(true, $log);
        $stat = @stat($log);
        $gz = knocklog_is_gz($log);
        if ($stat === false) {
            $error = "cannot read $log";
            continue;
        }
        $known = isset($files[$log]) ? $files[$log] : array('inode' => $stat['ino'], 'offset' => 0, 'size' => 0);
        $offset = (int) $known['offset'];
        if ($gz) {
            // cPanel appends to the month's archive; offsets count uncompressed bytes.
            // Unchanged: nothing to do. Smaller: replaced, start over.
            $knownSize = isset($known['size']) ? (int) $known['size'] : 0;
            if ($stat['size'] === $knownSize && $offset > 0) continue;
            if ($stat['size'] < $knownSize) $offset = 0;
        } elseif ((int) $known['inode'] !== (int) $stat['ino'] || $stat['size'] < $offset) {
            // Rotated or emptied (cPanel empties the live log after processing stats): start over.
            $offset = 0;
        }
        $handle = $gz ? @gzopen($log, 'rb') : @fopen($log, 'rb');
        if ($handle === false) {
            $error = "cannot read $log";
            continue;
        }
        $eof = false;
        while ($posts < MAX_POSTS_PER_RUN && ($gz || $offset < $stat['size'])) {
            list($text, $bytes) = knocklog_read_block($handle, $offset, $gz);
            if ($bytes === 0) {
                $eof = true;
                break;
            }
            if (trim($text) !== '') {
                list($status, $body) = knocklog_request('POST', KNOCKLOG_ENDPOINT . '/v1/logs?live=1', $text);
                $posts++;
                if ($status !== 202) {
                    $error = "API answered $status: " . substr($body, 0, 300);
                    break;
                }
                $sent += substr_count($text, "\n");
            }
            $offset += $bytes;
        }
        $gz ? gzclose($handle) : fclose($handle);
        // An archive not read to the end (API error, or the per-run limit) is read again next
        // run even if its size has not changed.
        $files[$log] = array('inode' => $stat['ino'], 'offset' => $offset, 'size' => $gz && !$eof ? 0 : $stat['size']);
        if ($error !== null && strpos($error, 'API answered') === 0) break;
    }

    $state = array('files' => $files, 'lastRun' => gmdate('c'), 'lastSentLines' => $sent, 'lastError' => $error);
    file_put_contents($stateFile . '.tmp', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($stateFile . '.tmp', $stateFile);
    flock($lock, LOCK_UN);
    fclose($lock);
    if ($error !== null) {
        fwrite(STDERR, "Knocklog: $error\n");
        return 2;
    }
    return 0;
}

exit(knocklog_main(isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : array(), $KNOCKLOG_LOGS));
