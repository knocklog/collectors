<?php
/**
 * Plugin Name:       Knocklog – AI agent analytics
 * Description:       See which AI agents (ChatGPT, Claude, Perplexity, Gemini) read your site, which ones are real, and which visitors AI assistants send you. Optional "Optimized and protected for AI agents" badge.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Knocklog
 * Author URI:        https://knocklog.ai
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       knocklog
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KNOCKLOG_PLUGIN_VERSION', '1.0.0');

/** Saved settings with defaults. */
function knocklog_options()
{
    $saved = get_option('knocklog', array());
    return wp_parse_args(is_array($saved) ? $saved : array(), array(
        'key' => '',
        'project' => '',
        'leads' => 1,
        'badge' => 0,
        'cloudflare' => 0,
    ));
}

function knocklog_lang()
{
    return strpos(get_locale(), 'pt') === 0 ? 'pt' : 'en';
}

// ── Collector: reports AI-agent visits (and a sample of pageviews) after each page is sent.
$knocklog_settings = knocklog_options();
if (preg_match('/^kl_ing_[A-Za-z0-9_]{8,200}$/', (string) $knocklog_settings['key'])) {
    if (!empty($knocklog_settings['cloudflare']) && !defined('KNOCKLOG_TRUSTED_IP_HEADER')) {
        define('KNOCKLOG_TRUSTED_IP_HEADER', 'HTTP_CF_CONNECTING_IP');
    }
    require_once __DIR__ . '/collector.php';
    Knocklog_Collector::start($knocklog_settings['key']);
}
unset($knocklog_settings);

// ── Leads from AI: marks visitors sent by AI assistants and counts the leads they become.
add_action('wp_enqueue_scripts', function () {
    $o = knocklog_options();
    if (empty($o['leads']) || !preg_match('/^p[0-9a-f]{20}$/', (string) $o['project'])) {
        return;
    }
    wp_enqueue_script('knocklog-leads', 'https://api.knocklog.ai/leads.js', array(), KNOCKLOG_PLUGIN_VERSION, array('strategy' => 'async', 'in_footer' => false));
});

add_filter('script_loader_tag', function ($tag, $handle) {
    if ($handle !== 'knocklog-leads') {
        return $tag;
    }
    $o = knocklog_options();
    return str_replace(' src=', ' data-project="' . esc_attr($o['project']) . '" src=', $tag);
}, 10, 2);

// ── Badge: a small "AI-ready" pill; its hover card reads "Optimized and protected for AI
// agents" while Knocklog receives the site's traffic, and its logo links to knocklog.ai.
function knocklog_badge_html()
{
    $o = knocklog_options();
    if (!preg_match('/^p[0-9a-f]{20}$/', (string) $o['project'])) {
        return '';
    }
    wp_enqueue_script('knocklog-badge', 'https://api.knocklog.ai/badge.js', array(), KNOCKLOG_PLUGIN_VERSION, array('strategy' => 'async', 'in_footer' => true));
    $lang = knocklog_lang();
    $label = $lang === 'pt' ? 'Otimizado e protegido para agentes de IA' : 'Optimized and protected for AI agents';
    return '<a href="' . esc_url('https://knocklog.ai/?ref=badge') . '" data-knocklog-badge="' . esc_attr($o['project']) . '" data-lang="' . esc_attr($lang) . '">'
        . esc_html($label . ' · Knocklog') . '</a>';
}

add_shortcode('knocklog_badge', 'knocklog_badge_html');

add_action('wp_footer', function () {
    $o = knocklog_options();
    if (empty($o['badge'])) {
        return;
    }
    $html = knocklog_badge_html();
    if ($html !== '') {
        echo '<div class="knocklog-badge" style="text-align:center;margin:16px 0">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }
});

// ── Settings page: Settings → Knocklog.
add_action('admin_init', function () {
    register_setting('knocklog', 'knocklog', array(
        'type' => 'array',
        'sanitize_callback' => function ($input) {
            $input = is_array($input) ? $input : array();
            $key = isset($input['key']) ? trim((string) $input['key']) : '';
            $project = isset($input['project']) ? strtolower(trim((string) $input['project'])) : '';
            $old = knocklog_options();
            return array(
                // An empty key field keeps the saved key: it is never printed back.
                'key' => $key === '' ? $old['key'] : (preg_match('/^kl_ing_[A-Za-z0-9_]{8,200}$/', $key) ? $key : ''),
                'project' => preg_match('/^p[0-9a-f]{20}$/', $project) ? $project : '',
                'leads' => empty($input['leads']) ? 0 : 1,
                'badge' => empty($input['badge']) ? 0 : 1,
                'cloudflare' => empty($input['cloudflare']) ? 0 : 1,
            );
        },
        'default' => array(),
    ));
});

add_action('admin_menu', function () {
    add_options_page('Knocklog', 'Knocklog', 'manage_options', 'knocklog', 'knocklog_settings_page');
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=knocklog')) . '">' . esc_html(knocklog_lang() === 'pt' ? 'Configurações' : 'Settings') . '</a>');
    return $links;
});

function knocklog_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $o = knocklog_options();
    $pt = knocklog_lang() === 'pt';
    $t = function ($en, $ptText) use ($pt) {
        return $pt ? $ptText : $en;
    };
    $connected = $o['key'] !== '';
    ?>
    <div class="wrap">
        <h1>Knocklog</h1>
        <p><?php echo esc_html($t(
            'See which AI agents read your site, which ones are real, and which visitors AI assistants send you.',
            'Veja quais agentes de IA leem o seu site, quais são verdadeiros e quantos visitantes os assistentes de IA mandam para você.'
        )); ?></p>
        <p><a class="button button-secondary" href="<?php echo esc_url('https://knocklog.ai/?site=' . rawurlencode((string) wp_parse_url(home_url(), PHP_URL_HOST)) . ($pt ? '&lang=pt' : '')); ?>" target="_blank" rel="noopener"><?php echo esc_html($t('1. Create your free project on knocklog.ai', '1. Crie o seu projeto grátis no knocklog.ai')); ?></a>
        <a class="button button-secondary" href="<?php echo esc_url('https://knocklog.ai/report' . ($pt ? '?lang=pt' : '')); ?>" target="_blank" rel="noopener"><?php echo esc_html($t('Open my report', 'Abrir meu relatório')); ?></a></p>
        <form method="post" action="options.php">
            <?php settings_fields('knocklog'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="knocklog-key"><?php echo esc_html($t('Send key', 'Chave de envio')); ?></label></th>
                    <td>
                        <input id="knocklog-key" name="knocklog[key]" type="password" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr($connected ? $t('Saved (leave empty to keep it)', 'Salva (deixe vazio para manter)') : 'kl_ing_…'); ?>">
                        <p class="description"><?php echo esc_html($t(
                            'Starts with kl_ing_. It can only send visits, never read your data. Shown when you create the project, or under Your plan → Install code.',
                            'Começa com kl_ing_. Ela só envia visitas, nunca lê os seus dados. Aparece ao criar o projeto, ou em Seu plano → Código de instalação.'
                        )); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="knocklog-project"><?php echo esc_html($t('Project id', 'Id do projeto')); ?></label></th>
                    <td>
                        <input id="knocklog-project" name="knocklog[project]" type="text" class="regular-text" value="<?php echo esc_attr($o['project']); ?>" placeholder="p…">
                        <p class="description"><?php echo esc_html($t('Starts with p, 21 characters. Needed for leads from AI and the badge.', 'Começa com p, 21 caracteres. Necessário para os leads vindos de IA e para o selo.')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html($t('Options', 'Opções')); ?></th>
                    <td>
                        <label><input name="knocklog[leads]" type="checkbox" value="1" <?php checked(!empty($o['leads'])); ?>> <?php echo esc_html($t('Leads from AI: tell which assistant sent each form or WhatsApp contact', 'Leads vindos de IA: saber qual assistente gerou cada formulário ou contato no WhatsApp')); ?></label><br>
                        <label><input name="knocklog[badge]" type="checkbox" value="1" <?php checked(!empty($o['badge'])); ?>> <?php echo esc_html($t('Show the "Optimized and protected for AI agents" badge in the footer (or use the [knocklog_badge] shortcode)', 'Mostrar o selo "Otimizado e protegido para agentes de IA" no rodapé (ou use o shortcode [knocklog_badge])')); ?></label><br>
                        <label><input name="knocklog[cloudflare]" type="checkbox" value="1" <?php checked(!empty($o['cloudflare'])); ?>> <?php echo esc_html($t('The site is behind Cloudflare (read the visitor IP from CF-Connecting-IP). Turn on only if your server accepts traffic from Cloudflare alone.', 'O site está atrás da Cloudflare (ler o IP do visitante em CF-Connecting-IP). Ligue só se o servidor aceita tráfego apenas da Cloudflare.')); ?></label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <p class="description"><?php echo esc_html($t(
            'If your host caches whole pages (LiteSpeed, Nginx cache on HostGator or Bluehost), WordPress does not run for most visits. Knocklog then recommends sending the host\'s access logs instead; see Install code in your report.',
            'Se a hospedagem guarda páginas inteiras em cache (LiteSpeed, cache Nginx da HostGator ou da Bluehost), o WordPress não roda na maioria das visitas. Nesse caso o Knocklog recomenda enviar os logs de acesso da hospedagem; veja Código de instalação no relatório.'
        )); ?></p>
    </div>
    <?php
}
