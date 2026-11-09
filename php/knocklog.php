<?php
/**
 * Knocklog collector for PHP sites: WordPress, Laravel, any PHP app (https://knocklog.ai)
 *
 * Reports AI-agent visits, and a sample of human pageviews, after the page has been
 * sent. It never changes the page and never breaks the site: every error is ignored.
 *
 * Needs PHP 7.2 or newer. Install (pick one), with this file saved as knocklog.php in your site's folder:
 *   - .user.ini (most hosts, cPanel, PHP-FPM):  auto_prepend_file = "/full/path/to/knocklog.php"
 *   - .htaccess (Apache with mod_php):          php_value auto_prepend_file "/full/path/to/knocklog.php"
 *   - WordPress, top of wp-config.php:          require_once __DIR__ . '/knocklog.php';
 */

if (PHP_SAPI !== 'cli' && !class_exists('KnocklogCollector', false)) {

    final class KnocklogCollector
    {
        const ENDPOINT = 'https://api.knocklog.ai/v1/events';
        // Ingest-only key: it can send visits, it cannot read your data.
        const KEY = 'kl_ing_YOUR_INGEST_KEY';
        // Share of ordinary browser pageviews to report (0 to 1). Agents are always reported.
        const HUMAN_SAMPLE_RATE = 1;
        // Leave empty unless the site sits behind a proxy or CDN that sets the visitor's IP in
        // a header, e.g. 'HTTP_CF_CONNECTING_IP' for Cloudflare. Never trust a header the
        // visitor can set: a forged IP would make a fake agent look real.
        const TRUSTED_IP_HEADER = '';

        const HEADERS = array(
            'user-agent' => 'HTTP_USER_AGENT',
            'accept' => 'HTTP_ACCEPT',
            'accept-language' => 'HTTP_ACCEPT_LANGUAGE',
            'referer' => 'HTTP_REFERER',
            'signature' => 'HTTP_SIGNATURE',
            'signature-input' => 'HTTP_SIGNATURE_INPUT',
            'signature-agent' => 'HTTP_SIGNATURE_AGENT',
            'from' => 'HTTP_FROM',
        );
        const AGENT_HINT = '~bot|crawl|spider|slurp|agent|gpt|claude|anthropic|perplexity|python|curl|wget|headless|scrapy|go-http|axios|node-fetch|okhttp|java/~i';
        const ASSET = '~\.(css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|pdf|zip|gz)$~i';
        // Visits wait in a small file and go out together: at most once every 30 seconds or
        // every 32 KB, so most page loads do no network call at all.
        const FLUSH_AFTER_SECONDS = 30;
        const FLUSH_AFTER_BYTES = 32768;
        const MAX_EVENTS_PER_POST = 100;

        // The WordPress plugin sets these constants from its settings page; a standalone
        // install uses the values written in this file.
        private static function key()
        {
            return defined('KNOCKLOG_KEY') ? (string) KNOCKLOG_KEY : self::KEY;
        }

        private static function endpoint()
        {
            return defined('KNOCKLOG_ENDPOINT') ? (string) KNOCKLOG_ENDPOINT : self::ENDPOINT;
        }

        private static function ipHeader()
        {
            return defined('KNOCKLOG_TRUSTED_IP_HEADER') ? (string) KNOCKLOG_TRUSTED_IP_HEADER : self::TRUSTED_IP_HEADER;
        }

        public static function start()
        {
            if (strpos(self::key(), 'kl_ing_') !== 0) return;
            // Runs last, after the site's own shutdown work, so nothing it prints is lost.
            register_shutdown_function(function () {
                register_shutdown_function(array('KnocklogCollector', 'finish'));
            });
        }

        public static function finish()
        {
            try {
                $event = self::event();
                if ($event === null) return;
                // Close the connection first: the visitor never waits for Knocklog.
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    @litespeed_finish_request();
                }
                self::queue($event);
            } catch (\Throwable $e) {
                // Never let analytics break the site.
            }
        }

        private static function server($name)
        {
            return isset($_SERVER[$name]) && is_string($_SERVER[$name]) ? $_SERVER[$name] : '';
        }

        private static function event()
        {
            $method = strtoupper(self::server('REQUEST_METHOD'));
            $host = self::server('HTTP_HOST');
            $uri = self::server('REQUEST_URI');
            if ($method === '' || $host === '' || $uri === '' || $uri[0] !== '/') return null;
            if (!preg_match('~^[a-z0-9.-]+(:[0-9]+)?$~i', $host)) return null;
            $https = self::server('HTTPS');
            $scheme = ($https !== '' && strtolower($https) !== 'off') || self::server('SERVER_PORT') === '443' ? 'https' : 'http';
            if (self::ipHeader() !== '' && strtolower(self::server('HTTP_X_FORWARDED_PROTO')) === 'https') $scheme = 'https';

            $headers = array();
            foreach (self::HEADERS as $name => $key) {
                $value = self::server($key);
                if ($value !== '') $headers[$name] = substr($value, 0, 4096);
            }
            $ua = isset($headers['user-agent']) ? $headers['user-agent'] : '';
            $signed = isset($headers['signature-agent']) || isset($headers['signature-input']);
            $agentLike = $signed || !preg_match('~^mozilla/~i', $ua) || preg_match(self::AGENT_HINT, $ua);

            $path = strtok($uri, '?');
            $sampleRate = null;
            if (!$agentLike) {
                // People: HTML pageviews only, sampled.
                $accept = isset($headers['accept']) ? $headers['accept'] : '';
                if ($method !== 'GET' || preg_match(self::ASSET, $path) || strpos($accept, 'text/html') === false) return null;
                if (self::HUMAN_SAMPLE_RATE <= 0 || mt_rand() / mt_getrandmax() >= self::HUMAN_SAMPLE_RATE) return null;
                if (self::HUMAN_SAMPLE_RATE < 1) $sampleRate = self::HUMAN_SAMPLE_RATE;
            }

            // Agents: the exact URL and IP are needed to verify them.
            // People: only utm_* parameters are kept from the query string. Their IP is used
            // once, to look up which organization's network it belongs to, and is not stored.
            $url = $scheme . '://' . $host . $uri;
            if (!$agentLike) {
                $url = $scheme . '://' . $host . $path;
                $query = array();
                parse_str((string) parse_url($uri, PHP_URL_QUERY), $params);
                foreach ($params as $k => $v) {
                    if (is_string($v) && strpos($k, 'utm_') === 0) $query[$k] = $v;
                }
                if ($query) $url .= '?' . http_build_query($query);
            }
            $ip = self::server(self::ipHeader() !== '' ? self::ipHeader() : 'REMOTE_ADDR');
            if ($ip === '') $ip = null;
            $time = isset($_SERVER['REQUEST_TIME']) ? (int) $_SERVER['REQUEST_TIME'] : time();
            $status = http_response_code();
            $event = array(
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $time),
                'method' => $method,
                'url' => $url,
                'headers' => (object) $headers,
            );
            if ($ip !== null) $event['ip'] = $ip;
            if (is_int($status) && $status > 0) $event['status'] = $status;
            if ($sampleRate !== null) $event['sampleRate'] = $sampleRate;
            return $event;
        }

        private static function queue(array $event)
        {
            $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line === false) return;
            $file = rtrim(sys_get_temp_dir(), '/\\') . '/knocklog-' . substr(sha1(self::key() . '|' . __FILE__), 0, 16) . '.jsonl';
            $handle = @fopen($file, 'c+');
            if ($handle === false) {
                self::post(array($line));
                return;
            }
            $lines = null;
            if (@flock($handle, LOCK_EX)) {
                $stat = fstat($handle);
                $size = $stat ? $stat['size'] : 0;
                if ($size === 0) {
                    // First visit in this batch: remember when the batch started.
                    $header = '#' . time() . "\n";
                    fwrite($handle, $header);
                    $size = strlen($header);
                }
                fseek($handle, 0, SEEK_END);
                fwrite($handle, $line . "\n");
                $size += strlen($line) + 1;
                rewind($handle);
                $first = fgets($handle);
                $started = $first !== false && $first[0] === '#' ? (int) substr($first, 1) : 0;
                if ($size >= self::FLUSH_AFTER_BYTES || time() - $started >= self::FLUSH_AFTER_SECONDS) {
                    $content = stream_get_contents($handle);
                    ftruncate($handle, 0);
                    $lines = array_values(array_filter(explode("\n", (string) $content), 'strlen'));
                }
                fflush($handle);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            if ($lines) {
                foreach (array_chunk($lines, self::MAX_EVENTS_PER_POST) as $chunk) self::post($chunk);
            }
        }

        private static function post(array $lines)
        {
            $body = '{"events":[' . implode(',', $lines) . ']}';
            $headers = array('Content-Type: application/json', 'Authorization: Bearer ' . self::key());
            if (function_exists('curl_init')) {
                $curl = curl_init(self::endpoint());
                curl_setopt_array($curl, array(
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_TIMEOUT => 4,
                ));
                curl_exec($curl);
                curl_close($curl);
                return;
            }
            $context = stream_context_create(array('http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 4,
                'ignore_errors' => true,
            )));
            @file_get_contents(self::endpoint(), false, $context);
        }
    }

    KnocklogCollector::start();
}
