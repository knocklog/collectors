<?php
/**
 * Plugin Name: Knocklog badge
 * Description: Shows the "Pronto para IA" badge in the footer. Must-use plugin: WordPress loads it on every page, whatever the theme. For the full plugin (collector, leads from AI, settings), see https://knocklog.ai/downloads/knocklog-wordpress.zip
 * Author: Knocklog
 * Author URI: https://knocklog.ai
 *
 * Install: set the project id below and copy this file to wp-content/mu-plugins/.
 * Optional: KNOCKLOG_BADGE_TARGET puts the badge inside an element of the theme's footer
 * (a CSS selector, e.g. '#text-4 .textwidget'); without it the badge gets its own centred
 * row at the end of the page. KNOCKLOG_BADGE_THEME is 'dark' on a dark footer.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KNOCKLOG_BADGE_PROJECT = '__KNOCKLOG_PROJECT__';
const KNOCKLOG_BADGE_LANG = 'pt';
const KNOCKLOG_BADGE_TARGET = '';
const KNOCKLOG_BADGE_THEME = 'light';

add_action('wp_footer', function () {
    if (!preg_match('/^p[0-9a-f]{20}$/', KNOCKLOG_BADGE_PROJECT)) {
        return;
    }
    $label = KNOCKLOG_BADGE_LANG === 'pt' ? 'Otimizado e protegido para agentes de IA' : 'Optimized and protected for AI agents';
    $theme = KNOCKLOG_BADGE_THEME === 'dark' ? ' data-theme="dark"' : '';
    echo '<div class="knocklog-badge-row" style="text-align:center;margin:16px 0">'
        . '<a href="https://knocklog.ai/?ref=badge" data-knocklog-badge="' . esc_attr(KNOCKLOG_BADGE_PROJECT) . '" data-lang="' . esc_attr(KNOCKLOG_BADGE_LANG) . '"' . $theme . '>'
        . esc_html($label . ' · Knocklog') . '</a></div>';
    if (KNOCKLOG_BADGE_TARGET !== '') {
        // Move the badge into the theme's footer element, if the page has it.
        echo '<script>(function(){var t=document.querySelector(' . wp_json_encode(KNOCKLOG_BADGE_TARGET) . ');var r=document.querySelector(".knocklog-badge-row");'
            . 'if(t&&r){r.style.cssText="margin-top:14px";t.appendChild(r);}})();</script>';
    }
    echo '<script src="https://api.knocklog.ai/badge.js" async></script>';
}, 100);
