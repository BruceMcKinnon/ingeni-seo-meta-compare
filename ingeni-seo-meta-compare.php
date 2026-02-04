<?php
/**
 * Plugin Name: Ingeni SEO Meta Compare
 * Description: Compare SEO meta tags between this site and another domain for all published pages.
 * Version: 2026.01
 * Author: Bruce McKinnon - ingeni.net
 */


if (!defined('ABSPATH')) exit;

// Include support for PluginUpdateChecker
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class SEO_Meta_Compare {


    private $report_key = 'seo_meta_compare_report';
    private $option_key = 'seo_meta_compare_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_seo_meta_compare_run', [$this, 'ajax_run']);
        add_action('wp_ajax_seo_meta_compare_export', [$this, 'export_csv']);
        add_action('wp_ajax_seo_meta_compare_get_ids', [$this, 'get_ids']);
    }

    public function menu() {
        add_management_page(
            'SEO Meta Compare',
            'SEO Meta Compare',
            'manage_options',
            'seo-meta-compare',
            [$this, 'page']
        );
    }

    public function assets($hook) {
        if ($hook !== 'tools_page_seo-meta-compare') return;

        wp_enqueue_script(
            'seo-meta-compare',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            ['jquery'],
            '1.0',
            true
        );

        wp_localize_script('seo-meta-compare', 'SEOCompare', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('seo_meta_compare')
        ]);
    }


    public function get_ids() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        $types = isset($_GET['types']) ? (array) $_GET['types'] : ['page'];

        $posts = get_posts([
            'post_type'      => $types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        wp_send_json($posts);
    }


    public function page() {

        $settings = get_option($this->option_key, [
            'original' => home_url(),
            'target'   => '',
            'types'    => ['page'],
            'normalize'=> 1,
        ]);
        ?>
        <div class="wrap">
            <h1>SEO Meta Compare</h1>

            <table class="form-table">
                <tr>
                    <th>Original domain</th>
                    <td><input type="text" id="original" value="<?php echo esc_attr($settings['original']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Target domain</th>
                    <td><input type="text" id="target" value="<?php echo esc_attr($settings['target']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Content types</th>
                    <td>
                        <label><input type="checkbox" value="page" checked> Pages</label><br>
                        <label><input type="checkbox" value="post"> Posts</label>
                    </td>
                </tr>
                <tr>
                    <th>Normalize URLs</th>
                    <td>
                        <label>
                            <input type="checkbox" id="normalize" checked>
                            Ignore protocol (http/https) & trailing slashes
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Comparison mode</th>
                    <td>
                        <label>
                            <input type="checkbox" id="canonical_only">
                            Only detect canonical mismatches
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Export</th>
                    <td>
                        <button class="button" id="export-csv" disabled>Export CSV</button>
                    </td>
                </tr>
            </table>

            <button class="button button-primary" id="start-compare">Run Comparison</button>

            <div id="progress-wrap" style="margin-top:20px; display:none;">
                <progress id="progress" value="0" max="100" style="width:300px;"></progress>
                <span id="progress-text"></span>
            </div>

            <table class="widefat striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Path</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="results"></tbody>
            </table>
        </div>
        <?php
    }

    public function ajax_run() {
        check_ajax_referer('seo_meta_compare', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        $post_id = intval($_POST['post_id']);
        $original = esc_url_raw($_POST['original']);
        $target   = esc_url_raw($_POST['target']);
        $normalize = !empty($_POST['normalize']);
        $canonical_only = !empty($_POST['canonical_only']);

        update_option($this->option_key, [
            'original' => home_url(),
            'target'   => $target,
            'types'    => ['page'],
            'normalize'=> 1,
        ]);

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error();
        }

        $path = wp_parse_url(get_permalink($post), PHP_URL_PATH);

        $src = rtrim($settings['original'], '/') . $path;
        $tgt = rtrim($target, '/') . $path;

        $source = $this->get_meta($src);
        $target = $this->get_meta($tgt);

        if (!$target) {
            wp_send_json([
                'url' => $path,
                'status' => 'Missing: '.$tgt,
                'details'=> 'Target URL not found'
            ]);
        }

        $diffs = $this->compare($source, $target_meta, $normalize, $canonical_only);


        $report = get_transient($this->report_key);
        if (!$report) {
            $report = [];
        }

        $report[] = [
            'url'              => $path,
            'status'           => empty($diffs) ? 'OK' : 'Diff',
            'title_diff'       => $source['title'] !== $target['title'],
            'description_diff' => $source['description'] !== $target['description'],
            'robots_diff'      => $source['robots'] !== $target['robots'],
            'canonical_diff'   => $source['canonical'] !== $target['canonical'],
            'source_canonical' => $source['canonical'],
            'target_canonical' => $target['canonical'],
        ];

        set_transient($this->report_key, $report, HOUR_IN_SECONDS);

        wp_send_json([
            'url' => $path,
            'status' => empty($diffs) ? 'OK' : 'Diff',
            'details'=> empty($diffs) ? 'No differences' : implode('<br>', $diffs)
        ]);
    }

    private function get_meta($url) {
        $r = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
            return false;
        }

        $html = wp_remote_retrieve_body($r);
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        $meta = [
            'title'       => '',
            'description' => '',
            'robots'      => '',
            'canonical'   => '',
        ];

        if ($dom->getElementsByTagName('title')->length) {
            $meta['title'] = trim($dom->getElementsByTagName('title')->item(0)->textContent);
        }

        foreach ($dom->getElementsByTagName('meta') as $m) {
            if ($m->getAttribute('name') === 'description') {
                $meta['description'] = $m->getAttribute('content');
            }
            if ($m->getAttribute('name') === 'robots') {
                $meta['robots'] = $m->getAttribute('content');
            }
        }

        foreach ($dom->getElementsByTagName('link') as $l) {
            if ($l->getAttribute('rel') === 'canonical') {
                $meta['canonical'] = $l->getAttribute('href');
            }
        }

        return $meta;
    }

    private function normalize($url) {
        return rtrim(preg_replace('#^https?://#', '', $url), '/');
    }

    private function compare($src, $tgt, $normalize, $canonical_only = false) {
        $diffs = [];

        if ($canonical_only) {
            $a = $normalize ? $this->normalize($src['canonical']) : $src['canonical'];
            $b = $normalize ? $this->normalize($tgt['canonical']) : $tgt['canonical'];

            if (empty($src['canonical']) || empty($tgt['canonical'])) {
                $diffs[] = 'Canonical missing on one or both URLs';
            } elseif ($a !== $b) {
                $diffs[] =
                    "CANONICAL MISMATCH:<br>
                    Source: {$src['canonical']}<br>
                    Target: {$tgt['canonical']}";
            }

            return $diffs;
        }

        foreach ($src as $k => $v) {
            $a = $normalize ? $this->normalize($v) : $v;
            $b = $normalize ? $this->normalize($tgt[$k]) : $tgt[$k];
            if ($a !== $b) {
                $diffs[] =
                    strtoupper($k) . ":<br>
                    Source: {$v}<br>
                    Target: {$tgt[$k]}";
            }
        }

        return $diffs;
    }


    public function export_csv() {
        if (!current_user_can('manage_options')) {
            exit;
        }

        $report = get_transient($this->report_key);
        if (empty($report)) {
            wp_die('No report data available.');
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=seo-meta-compare.csv');

        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'URL',
            'Status',
            'Title Diff',
            'Description Diff',
            'Robots Diff',
            'Canonical Diff',
            'Source Canonical',
            'Target Canonical'
        ]);

        foreach ($report as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }


    public function init() {
        // Init auto-update from GitHub repo
        require 'plugin-update-checker/plugin-update-checker.php';
        $myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/BruceMcKinnon/ingeni-seo-meta-compare',
            __FILE__,
            'ingeni-seo-meta-compare'
        );
    }

}

new SEO_Meta_Compare();




