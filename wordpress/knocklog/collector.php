<?php
/**
 * Knocklog collector for the WordPress plugin: reports AI-agent visits, and people's page
 * views, to Knocklog after the page has been sent. WordPress APIs only (wp_remote_post,
 * wp_parse_url, wp_unslash). It never changes the page and never breaks the site.
 *
 * Standalone PHP sites use packages/core/collectors/knocklog.php instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Knocklog_Collector
{
    const ENDPOINT = 'https://api.knocklog.ai/v1/events';

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

    /** @var string */
    private static $key = '';

    public static function start($key)
    {
        if (strpos((string) $key, 'kl_ing_') !== 0) {
            return;
        }
        self::$key = (string) $key;
        // Last on shutdown, after the page and the site's own shutdown work.
        add_action('shutdown', array(__CLASS__, 'finish'), PHP_INT_MAX);
    }

    private static function endpoint()
    {
        return defined('KNOCKLOG_ENDPOINT') ? (string) KNOCKLOG_ENDPOINT : self::ENDPOINT;
    }

    private static function ip_header()
    {
        return defined('KNOCKLOG_TRUSTED_IP_HEADER') ? (string) KNOCKLOG_TRUSTED_IP_HEADER : '';
    }

    /**
     * A request value as the visitor sent it. WordPress adds slashes to $_SERVER, which
     * would break quoted signature headers, so they are removed; control characters are
     * dropped. The value is forwarded to Knocklog for verification, never printed or stored
     * by WordPress, so it is not run through text sanitizers that would rewrite it.
     */
    private static function server($name)
    {
        if (!isset($_SERVER[$name]) || !is_string($_SERVER[$name])) {
            return '';
        }
        $value = wp_unslash($_SERVER[$name]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- forwarded verbatim for signature verification; cleaned on the next line.
        return substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value), 0, 4096);
    }

    public static function finish()
    {
        try {
            $event = self::event();
            if ($event === null) {
                return;
            }
            // Close the connection first, so the visitor never waits for Knocklog.
            $closed = false;
            if (function_exists('fastcgi_finish_request')) {
                $closed = @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                $closed = @litespeed_finish_request();
            }
            $body = wp_json_encode(array('events' => array($event)), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($body)) {
                return;
            }
            wp_remote_post(self::endpoint(), array(
                'body' => $body,
                'headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . self::$key),
                'timeout' => $closed ? 4 : 1,
                // Where the page cannot be closed first, do not hold the visitor for the answer.
                'blocking' => (bool) $closed,
                'user-agent' => 'Knocklog-WordPress/' . (defined('KNOCKLOG_PLUGIN_VERSION') ? KNOCKLOG_PLUGIN_VERSION : '1'),
            ));
        } catch (\Throwable $e) {
            // Never let analytics break the site.
            return;
        }
    }

    private static function event()
    {
        $method = strtoupper(self::server('REQUEST_METHOD'));
        $host = self::server('HTTP_HOST');
        $uri = self::server('REQUEST_URI');
        if ($method === '' || $host === '' || $uri === '' || $uri[0] !== '/') {
            return null;
        }
        if (!preg_match('~^[a-z0-9.-]+(:[0-9]+)?$~i', $host)) {
            return null;
        }
        $https = self::server('HTTPS');
        $scheme = ($https !== '' && strtolower($https) !== 'off') || self::server('SERVER_PORT') === '443' ? 'https' : 'http';
        if (self::ip_header() !== '' && strtolower(self::server('HTTP_X_FORWARDED_PROTO')) === 'https') {
            $scheme = 'https';
        }

        $headers = array();
        foreach (self::HEADERS as $name => $key) {
            $value = self::server($key);
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }
        $ua = isset($headers['user-agent']) ? $headers['user-agent'] : '';
        $signed = isset($headers['signature-agent']) || isset($headers['signature-input']);
        $agent_like = $signed || !preg_match('~^mozilla/~i', $ua) || preg_match(self::AGENT_HINT, $ua);

        $path = strtok($uri, '?');
        if (!$agent_like) {
            // People: HTML page views only.
            $accept = isset($headers['accept']) ? $headers['accept'] : '';
            if ($method !== 'GET' || preg_match(self::ASSET, (string) $path) || strpos($accept, 'text/html') === false) {
                return null;
            }
        }

        // Agents: the exact URL and IP are needed to verify them.
        // People: only utm_* parameters are kept from the query string. Their IP is used
        // once, to look up which organization's network it belongs to, and is not stored.
        $url = $scheme . '://' . $host . $uri;
        if (!$agent_like) {
            $url = $scheme . '://' . $host . $path;
            $query = array();
            parse_str((string) wp_parse_url($uri, PHP_URL_QUERY), $params);
            foreach ($params as $k => $v) {
                if (is_string($v) && strpos((string) $k, 'utm_') === 0) {
                    $query[$k] = $v;
                }
            }
            if ($query) {
                $url .= '?' . http_build_query($query);
            }
        }
        $ip = self::server(self::ip_header() !== '' ? self::ip_header() : 'REMOTE_ADDR');
        $time = isset($_SERVER['REQUEST_TIME']) ? (int) $_SERVER['REQUEST_TIME'] : time();
        $status = http_response_code();
        $event = array(
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $time),
            'method' => $method,
            'url' => $url,
            'headers' => (object) $headers,
        );
        if ($ip !== '') {
            $event['ip'] = $ip;
        }
        if (is_int($status) && $status > 0) {
            $event['status'] = $status;
        }
        return $event;
    }
}
