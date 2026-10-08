<?php
/**
 * Plugin Name: Nowera Lead Stats
 * Description: Counts form submissions (Bricksforge Pro Forms, WPForms, Forminator, Elementor Pro Forms, Contact Form 7) and Amelia web bookings, and exposes daily counts — never the submitted data — over REST for the Nowera daily report.
 * Version: 1.1.0
 * Author: Nowera
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

defined('ABSPATH') || exit;

final class NWR_Lead_Stats
{
    const VERSION    = '1.1.0';
    const DB_VERSION = '1';
    const NS         = 'nwr-leads/v1';
    const OPT_KEY    = 'nwr_leads_key';
    const OPT_SINCE  = 'nwr_leads_since';   // local datetime when live logging started
    const OPT_DB     = 'nwr_leads_db';
    const MAX_DAYS   = 3700; // ~10 years, enough for the whole history of a form plugin

    /** Bricksforge passes no form id to its hooks, so it is taken from the REST request. */
    private static $bf_request = null;

    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'nwr_leads';
    }

    public static function init()
    {
        add_action('plugins_loaded', [__CLASS__, 'maybe_install']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);

        // Bricksforge Pro Forms: before_submit runs after validation, honeypot and Turnstile,
        // before the actions, so a lead still counts when e.g. the e-mail action fails.
        add_filter('rest_request_before_callbacks', [__CLASS__, 'bf_capture'], 10, 3);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'bf_release'], 10, 3);
        add_action('bricksforge/pro_forms/before_submit', [__CLASS__, 'bf_submit'], 10, 1);

        // WPForms (Lite and Pro): only successful, non-spam submissions reach this hook.
        add_action('wpforms_process_complete', [__CLASS__, 'wpforms_submit'], 10, 4);

        // Forminator: runs for every processed submission, also when storing entries is disabled.
        add_action('forminator_custom_form_submit_before_set_fields', [__CLASS__, 'forminator_submit'], 10, 2);

        // Elementor Pro Forms.
        add_action('elementor_pro/forms/new_record', [__CLASS__, 'elementor_submit'], 10, 1);

        // Contact Form 7: every accepted submission, also when sending the mail failed.
        add_action('wpcf7_submit', [__CLASS__, 'cf7_submit'], 10, 2);
    }

    /* ------------------------------------------------------------------ install */

    public static function activate()
    {
        self::install();
    }

    public static function maybe_install()
    {
        if (get_option(self::OPT_DB) !== self::DB_VERSION) {
            self::install();
        }
    }

    private static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $t = self::table();
        dbDelta("CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(20) NOT NULL,
  form_id varchar(64) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY source_form (source, form_id)
) $charset;");
        update_option(self::OPT_DB, self::DB_VERSION, false);
        if (!get_option(self::OPT_SINCE)) {
            update_option(self::OPT_SINCE, current_time('mysql'), false);
        }
        if (!get_option(self::OPT_KEY)) {
            update_option(self::OPT_KEY, wp_generate_password(40, false, false), false);
        }
    }

    /* ------------------------------------------------------------------ logging */

    private static function log($source, $form_id, $post_id = 0)
    {
        global $wpdb;
        $form_id = substr(sanitize_text_field((string) $form_id), 0, 64);
        if ($form_id === '') {
            return;
        }
        // Submissions sent by logged-in editors/admins are almost always tests.
        $is_test = (is_user_logged_in() && current_user_can('edit_posts')) ? 1 : 0;
        $wpdb->insert(self::table(), [
            'source'     => $source,
            'form_id'    => $form_id,
            'post_id'    => absint($post_id),
            'created_at' => current_time('mysql'),
            'is_test'    => $is_test,
        ], ['%s', '%s', '%d', '%s', '%d']);
    }

    public static function bf_capture($response, $handler, $request)
    {
        self::$bf_request = ($request instanceof WP_REST_Request && untrailingslashit($request->get_route()) === '/bricksforge/v1/form_submit') ? $request : null;
        return $response;
    }

    public static function bf_release($response, $handler, $request)
    {
        self::$bf_request = null;
        return $response;
    }

    public static function bf_submit($form_data)
    {
        $req = self::$bf_request;
        if (!$req) {
            return;
        }
        $form_id = $req->get_param('formId') ?: $req->get_param('formIdFallback');
        $post_id = is_array($form_data) && isset($form_data['postId']) ? $form_data['postId'] : $req->get_param('postId');
        self::log('bricksforge', $form_id, $post_id);
        self::$bf_request = null; // one submission per request
    }

    public static function wpforms_submit($fields, $entry, $form_data, $entry_id = 0)
    {
        $form_id = is_array($form_data) && isset($form_data['id']) ? $form_data['id'] : '';
        $post_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        self::log('wpforms', $form_id, $post_id);
    }

    public static function forminator_submit($entry, $form_id)
    {
        // Spam, drafts, abandoned forms and previews (status not set) are not leads.
        if (!is_object($entry) || !isset($entry->status) || $entry->status !== 'active') {
            return;
        }
        $post_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        self::log('forminator', $form_id, $post_id);
    }

    public static function elementor_submit($record)
    {
        if (!is_object($record) || !method_exists($record, 'get_form_settings')) {
            return;
        }
        self::log('elementor', $record->get_form_settings('id'), $record->get_form_settings('form_post_id'));
    }

    public static function cf7_submit($contact_form, $result = [])
    {
        if (!is_object($contact_form) || !method_exists($contact_form, 'id')) {
            return;
        }
        $status = is_array($result) && isset($result['status']) ? $result['status'] : '';
        if (!in_array($status, ['mail_sent', 'mail_failed'], true)) {
            return; // spam, validation_failed, aborted, ...
        }
        $post_id = 0;
        if (class_exists('WPCF7_Submission') && ($s = WPCF7_Submission::get_instance())) {
            $post_id = (int) $s->get_meta('container_post_id');
        }
        self::log('cf7', $contact_form->id(), $post_id);
    }

    /* ------------------------------------------------------------------ counting */

    /**
     * Daily counts per form. Live log rows are always counted; rows that the form plugins
     * stored themselves are used only for the time before live logging started (history).
     *
     * @return array [ ['source','form_id','post_id','day','c'], ... ]
     */
    private static function rows($from, $to, $include_tests)
    {
        global $wpdb;
        $since = get_option(self::OPT_SINCE) ?: current_time('mysql');
        $out = [];

        $t = self::table();
        $sql = "SELECT source, form_id, DATE(created_at) day, COUNT(*) c FROM $t WHERE created_at >= %s AND created_at < %s"
            . ($include_tests ? '' : ' AND is_test = 0')
            . ' GROUP BY source, form_id, day';
        foreach ((array) $wpdb->get_results($wpdb->prepare($sql, $from, $to), ARRAY_A) as $r) {
            $out[] = $r;
        }

        // Amelia: always counted from its own tables (no live log), only bookings made on the website.
        // The booking form stores the customer's info JSON; bookings entered by staff in the admin have info NULL.
        $ab = $wpdb->prefix . 'amelia_customer_bookings';
        $aa = $wpdb->prefix . 'amelia_appointments';
        if (self::table_exists($ab) && self::table_exists($aa)) {
            $res = $wpdb->get_results($wpdb->prepare(
                "SELECT a.serviceId form_id, DATE_FORMAT(b.created, '%%Y-%%m-%%d %%H:00:00') h, COUNT(*) c FROM $ab b JOIN $aa a ON a.id = b.appointmentId
                 WHERE b.info IS NOT NULL AND b.info <> '' AND b.created >= %s AND b.created < %s GROUP BY form_id, h",
                get_gmt_from_date($from), get_gmt_from_date($to)
            ), ARRAY_A);
            $out = array_merge($out, self::gmt_hours_to_days('amelia', $res));
        }

        $hist_to = min($to, $since);
        if ($from >= $hist_to) {
            return $out;
        }

        // Bricksforge (local time).
        $bt = $wpdb->prefix . 'bricksforge_submissions';
        if (self::table_exists($bt)) {
            $res = $wpdb->get_results($wpdb->prepare(
                "SELECT 'bricksforge' source, form_id, DATE(`timestamp`) day, COUNT(*) c FROM $bt WHERE `timestamp` >= %s AND `timestamp` < %s GROUP BY form_id, day",
                $from, $hist_to
            ), ARRAY_A);
            $out = array_merge($out, (array) $res);
        }

        // Forminator (local time).
        $ft = $wpdb->prefix . 'frmt_form_entry';
        if (self::table_exists($ft)) {
            $has_status = (bool) $wpdb->get_var("SHOW COLUMNS FROM $ft LIKE 'status'");
            $res = $wpdb->get_results($wpdb->prepare(
                "SELECT 'forminator' source, form_id, DATE(date_created) day, COUNT(*) c FROM $ft
                 WHERE entry_type = 'custom-forms' AND is_spam = 0" . ($has_status ? " AND (status IS NULL OR status = '' OR status = 'active')" : '') . "
                 AND date_created >= %s AND date_created < %s GROUP BY form_id, day",
                $from, $hist_to
            ), ARRAY_A);
            $out = array_merge($out, (array) $res);
        }

        // WPForms Pro (stores GMT; Lite keeps no entries locally).
        $wt = $wpdb->prefix . 'wpforms_entries';
        if (self::table_exists($wt)) {
            $res = $wpdb->get_results($wpdb->prepare(
                "SELECT form_id, DATE_FORMAT(`date`, '%%Y-%%m-%%d %%H:00:00') h, COUNT(*) c FROM $wt
                 WHERE status NOT IN ('spam','trash','partial','abandoned') AND `date` >= %s AND `date` < %s GROUP BY form_id, h",
                get_gmt_from_date($from), get_gmt_from_date($hist_to)
            ), ARRAY_A);
            $out = array_merge($out, self::gmt_hours_to_days('wpforms', $res));
        }

        // Elementor Pro (local time column created_at).
        $et = $wpdb->prefix . 'e_submissions';
        if (self::table_exists($et)) {
            $res = $wpdb->get_results($wpdb->prepare(
                "SELECT 'elementor' source, element_id form_id, DATE(created_at) day, COUNT(*) c FROM $et
                 WHERE status <> 'trash' AND created_at >= %s AND created_at < %s GROUP BY element_id, day",
                $from, $hist_to
            ), ARRAY_A);
            $out = array_merge($out, (array) $res);
        }

        return $out;
    }

    /** Hourly GMT counts (form_id, h, c) → local-day rows. */
    private static function gmt_hours_to_days($source, $res)
    {
        $agg = [];
        foreach ((array) $res as $r) {
            $k = $r['form_id'] . '|' . substr(get_date_from_gmt($r['h']), 0, 10);
            $agg[$k] = ($agg[$k] ?? 0) + (int) $r['c'];
        }
        $out = [];
        foreach ($agg as $k => $c) {
            list($fid, $day) = explode('|', $k);
            $out[] = ['source' => $source, 'form_id' => $fid, 'day' => $day, 'c' => $c];
        }
        return $out;
    }

    private static function table_exists($t)
    {
        global $wpdb;
        static $cache = [];
        if (!isset($cache[$t])) {
            $cache[$t] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) === $t;
        }
        return $cache[$t];
    }

    /** Earliest submission the form plugin stored itself (history before live logging), local date or null. */
    private static function native_first($source, $form_id)
    {
        global $wpdb;
        if ($source === 'amelia') {
            // Amelia fills `created` (GMT) since some version, older rows have NULL: counts are complete
            // from the first booking with a creation time, for every service.
            $t = $wpdb->prefix . 'amelia_customer_bookings';
            $v = self::table_exists($t) ? $wpdb->get_var("SELECT MIN(created) FROM $t") : null;
            return $v ? substr(get_date_from_gmt($v), 0, 10) : null;
        }
        $map = [
            'bricksforge' => ['bricksforge_submissions', '`timestamp`', 'form_id = %s', false],
            'forminator'  => ['frmt_form_entry', 'date_created', "entry_type = 'custom-forms' AND is_spam = 0 AND form_id = %s", false],
            'wpforms'     => ['wpforms_entries', '`date`', "status NOT IN ('spam','trash','partial','abandoned') AND form_id = %s", true],
            'elementor'   => ['e_submissions', 'created_at', "status <> 'trash' AND element_id = %s", false],
        ];
        if (!isset($map[$source])) {
            return null;
        }
        list($t, $col, $where, $gmt) = $map[$source];
        $t = $wpdb->prefix . $t;
        if (!self::table_exists($t)) {
            return null;
        }
        $v = $wpdb->get_var($wpdb->prepare("SELECT MIN($col) FROM $t WHERE $where", $form_id));
        if (!$v) {
            return null;
        }
        return substr($gmt ? get_date_from_gmt($v) : $v, 0, 10);
    }

    private static function form_title($source, $form_id)
    {
        global $wpdb;
        if (in_array($source, ['wpforms', 'forminator', 'cf7'], true) && ctype_digit((string) $form_id)) {
            return get_the_title((int) $form_id);
        }
        if ($source === 'bricksforge') {
            // Name of the page the form was sent from (Bricksforge forms have no own title).
            $t = $wpdb->prefix . 'bricksforge_submissions';
            $pid = self::table_exists($t) ? $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $t WHERE form_id = %s ORDER BY id DESC LIMIT 1", $form_id)) : null;
            if (!$pid) {
                $pid = $wpdb->get_var($wpdb->prepare('SELECT post_id FROM ' . self::table() . ' WHERE source = %s AND form_id = %s ORDER BY id DESC LIMIT 1', $source, $form_id));
            }
            return $pid ? get_the_title((int) $pid) : '';
        }
        if ($source === 'amelia') {
            $t = $wpdb->prefix . 'amelia_services';
            return self::table_exists($t) ? (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM $t WHERE id = %d", (int) $form_id)) : '';
        }
        if ($source === 'elementor') {
            $t = $wpdb->prefix . 'e_submissions';
            return self::table_exists($t) ? (string) $wpdb->get_var($wpdb->prepare("SELECT form_name FROM $t WHERE element_id = %s ORDER BY id DESC LIMIT 1", $form_id)) : '';
        }
        return '';
    }

    /* ------------------------------------------------------------------ REST */

    public static function routes()
    {
        register_rest_route(self::NS, '/counts', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_counts'],
            'permission_callback' => [__CLASS__, 'can_read'],
            'args'                => [
                'since'         => ['type' => 'string', 'required' => false],
                'until'         => ['type' => 'string', 'required' => false],
                'forms'         => ['type' => 'string', 'required' => false],
                'include_tests' => ['type' => 'boolean', 'required' => false, 'default' => false],
                'group'         => ['type' => 'string', 'required' => false, 'default' => 'day', 'enum' => ['day', 'week', 'month']],
            ],
        ]);
    }

    public static function can_read(WP_REST_Request $request)
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        $key = (string) get_option(self::OPT_KEY);
        $sent = (string) $request->get_header('x_nwr_leads_key');
        return $key !== '' && $sent !== '' && hash_equals($key, $sent);
    }

    private static function valid_day($s)
    {
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4));
    }

    public static function rest_counts(WP_REST_Request $request)
    {
        $tz    = wp_timezone();
        $today = new DateTimeImmutable('today', $tz);
        $since = $request->get_param('since');
        $until = $request->get_param('until');
        if ($since !== null && !self::valid_day($since)) {
            return new WP_Error('nwr_leads_bad_since', 'since must be YYYY-MM-DD', ['status' => 400]);
        }
        if ($until !== null && !self::valid_day($until)) {
            return new WP_Error('nwr_leads_bad_until', 'until must be YYYY-MM-DD', ['status' => 400]);
        }
        $from = $since ? new DateTimeImmutable($since, $tz) : $today->modify('-14 days');
        $to   = $until ? new DateTimeImmutable($until, $tz) : $today;
        if ($to < $from) {
            return new WP_Error('nwr_leads_bad_range', 'until is before since', ['status' => 400]);
        }
        if ($from->diff($to)->days > self::MAX_DAYS) {
            return new WP_Error('nwr_leads_range', 'range is limited to ' . self::MAX_DAYS . ' days', ['status' => 400]);
        }

        // optional allow-list: "bricksforge:abc123,wpforms:12"
        $only = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $request->get_param('forms')))) as $k) {
            $only[$k] = true;
        }

        $rows = self::rows(
            $from->format('Y-m-d 00:00:00'),
            $to->modify('+1 day')->format('Y-m-d 00:00:00'),
            (bool) $request->get_param('include_tests')
        );

        $days  = [];
        $forms = [];
        $total = 0;
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = 0;
        }
        foreach ($rows as $r) {
            $key = $r['source'] . ':' . $r['form_id'];
            if ($only && !isset($only[$key])) {
                continue;
            }
            $c = (int) $r['c'];
            if (!isset($days[$r['day']])) {
                continue;
            }
            $days[$r['day']] += $c;
            if (!isset($forms[$key])) {
                $forms[$key] = ['key' => $key, 'source' => $r['source'], 'form_id' => (string) $r['form_id'], 'title' => '', 'total' => 0, 'days' => []];
            }
            $forms[$key]['total'] += $c;
            $forms[$key]['days'][$r['day']] = ($forms[$key]['days'][$r['day']] ?? 0) + $c;
            $total += $c;
        }
        $live_day = substr((string) get_option(self::OPT_SINCE), 0, 10);
        foreach ($forms as $k => $f) {
            $forms[$k]['title'] = html_entity_decode(wp_strip_all_tags(self::form_title($f['source'], $f['form_id'])), ENT_QUOTES);
            ksort($forms[$k]['days']);
            // counts are complete from this day: live logging start, or earlier if the form plugin kept history
            $first = self::native_first($f['source'], $f['form_id']);
            if ($f['source'] === 'amelia') {
                $forms[$k]['complete_since'] = $first ?: $live_day; // never from the live log
            } else {
                $forms[$k]['complete_since'] = ($first && $first < $live_day) ? $first : $live_day;
            }
        }
        // optional week (ISO, "2026-W41") or month ("2026-10") buckets for long ranges
        $group = $request->get_param('group') ?: 'day';
        if ($group !== 'day') {
            $bucket = function ($day) use ($group) {
                $ts = strtotime($day . ' 12:00:00 UTC');
                return $group === 'month' ? gmdate('Y-m', $ts) : gmdate('o-\\WW', $ts);
            };
            $regroup = function ($map) use ($bucket) {
                $o = [];
                foreach ($map as $d => $c) {
                    $b = $bucket($d);
                    $o[$b] = ($o[$b] ?? 0) + $c;
                }
                return $o;
            };
            $days = $regroup($days);
            foreach ($forms as $k => $f) {
                $forms[$k]['days'] = $regroup($f['days']);
            }
        }

        uasort($forms, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        $res = rest_ensure_response([
            'site'           => home_url('/'),
            'plugin'         => self::VERSION,
            'timezone'       => wp_timezone_string(),
            'since'          => $from->format('Y-m-d'),
            'until'          => $to->format('Y-m-d'),
            'group'          => $group,
            'live_since'     => get_option(self::OPT_SINCE),
            'generated_at'   => current_time('mysql'),
            'total'          => $total,
            'days'           => $days,
            'forms'          => array_values($forms),
        ]);
        $res->header('Cache-Control', 'no-store, private');
        return $res;
    }

    /* ------------------------------------------------------------------ admin */

    public static function admin_menu()
    {
        add_management_page('Nowera Lead Stats', 'Nowera Lead Stats', 'manage_options', 'nwr-lead-stats', [__CLASS__, 'admin_page']);
    }

    public static function admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_POST['nwr_leads_regen']) && check_admin_referer('nwr_leads_regen')) {
            update_option(self::OPT_KEY, wp_generate_password(40, false, false), false);
            echo '<div class="notice notice-success"><p>Kľúč bol vygenerovaný nanovo. Aktualizuj ho v n8n.</p></div>';
        }
        $req = new WP_REST_Request('GET', '/' . self::NS . '/counts');
        $req->set_param('since', (new DateTimeImmutable('today', wp_timezone()))->modify('-30 days')->format('Y-m-d'));
        $data = self::rest_counts($req)->get_data();
        $url = rest_url(self::NS . '/counts');
        echo '<div class="wrap"><h1>Nowera Lead Stats</h1>';
        echo '<p>Endpoint: <code>' . esc_html($url) . '</code><br>Hlavička: <code>X-NWR-Leads-Key</code> = <code>' . esc_html(get_option(self::OPT_KEY)) . '</code></p>';
        echo '<form method="post">';
        wp_nonce_field('nwr_leads_regen');
        echo '<p><button class="button" name="nwr_leads_regen" value="1" onclick="return confirm(\'Starý kľúč prestane fungovať. Pokračovať?\')">Vygenerovať nový kľúč</button></p></form>';
        echo '<p>Živé počítanie od: <strong>' . esc_html($data['live_since']) . '</strong>. Staršie údaje sa berú z tabuliek formulárových pluginov, ak ich ukladajú.</p>';
        echo '<h2>Posledných 30 dní: ' . (int) $data['total'] . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Kľúč (pre parameter forms)</th><th>Zdroj</th><th>Formulár / stránka</th><th>Spolu</th></tr></thead><tbody>';
        foreach ($data['forms'] as $f) {
            echo '<tr><td><code>' . esc_html($f['key']) . '</code></td><td>' . esc_html($f['source']) . '</td><td>' . esc_html($f['title']) . '</td><td>' . (int) $f['total'] . '</td></tr>';
        }
        if (!$data['forms']) {
            echo '<tr><td colspan="4">Zatiaľ žiadne odoslané formuláre.</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

register_activation_hook(__FILE__, ['NWR_Lead_Stats', 'activate']);
NWR_Lead_Stats::init();
