<?php
// Playground test: simulates submissions from every supported plugin + native history rows.
require file_exists(__DIR__ . '/wp-load.php') ? __DIR__ . '/wp-load.php' : dirname(__DIR__) . '/wp-load.php';
global $wpdb;
header('Content-Type: text/plain');
$wpdb->query("TRUNCATE " . NWR_Lead_Stats::table());
$now = current_time('timestamp');
$since = date('Y-m-d H:i:s', $now - 86400);           // live logging "started" yesterday
update_option('nwr_leads_since', $since, false);

// native Bricksforge history: 2 rows before $since (count), 1 after (ignored, live log covers it)
$bt = $wpdb->prefix . 'bricksforge_submissions';
$wpdb->query("CREATE TABLE IF NOT EXISTS $bt (id int AUTO_INCREMENT PRIMARY KEY, post_id int NOT NULL, form_id text, timestamp datetime NOT NULL, fields text)");
$wpdb->query("TRUNCATE $bt");
foreach ([5, 3, 0] as $d) $wpdb->insert($bt, ['post_id' => 1, 'form_id' => 'abc123', 'timestamp' => date('Y-m-d H:i:s', $now - $d * 86400 - 60), 'fields' => '{}']);
// native Forminator history: 1 active, 1 spam, 1 draft
$ft = $wpdb->prefix . 'frmt_form_entry';
$wpdb->query("CREATE TABLE IF NOT EXISTS $ft (entry_id bigint AUTO_INCREMENT PRIMARY KEY, entry_type varchar(191), draft_id varchar(12), form_id bigint, is_spam tinyint(1), date_created datetime, status varchar(20))");
$wpdb->query("TRUNCATE $ft");
foreach ([[0,'active'],[1,'spam'],[0,'draft']] as $r) $wpdb->insert($ft, ['entry_type'=>'custom-forms','form_id'=>77,'is_spam'=>$r[0],'date_created'=>date('Y-m-d H:i:s', $now - 4*86400),'status'=>$r[1]]);
// native WPForms Pro history (GMT): 1 normal, 1 spam
$wt = $wpdb->prefix . 'wpforms_entries';
$wpdb->query("CREATE TABLE IF NOT EXISTS $wt (entry_id bigint AUTO_INCREMENT PRIMARY KEY, form_id bigint, status varchar(30), date datetime)");
$wpdb->query("TRUNCATE $wt");
$wpdb->insert($wt, ['form_id'=>12,'status'=>'','date'=>gmdate('Y-m-d H:i:s', time() - 2*86400)]);
$wpdb->insert($wt, ['form_id'=>12,'status'=>'spam','date'=>gmdate('Y-m-d H:i:s', time() - 2*86400)]);

wp_set_current_user(0); // visitor
// live: Bricksforge via REST capture
$req = new WP_REST_Request('POST', '/bricksforge/v1/form_submit');
$req->set_param('formId', 'abc123');
apply_filters('rest_request_before_callbacks', null, [], $req);
do_action('bricksforge/pro_forms/before_submit', ['postId' => 302]);
do_action('bricksforge/pro_forms/before_submit', ['postId' => 302]); // second call in same request must not count
// live: WPForms
do_action('wpforms_process_complete', [], [], ['id' => 12], 0);
// live: Forminator active / spam / preview
do_action('forminator_custom_form_submit_before_set_fields', (object) ['status' => 'active'], 77, []);
do_action('forminator_custom_form_submit_before_set_fields', (object) ['status' => 'spam'], 77, []);
do_action('forminator_custom_form_submit_before_set_fields', (object) [], 77, []);
// live: Elementor + CF7
class FakeRecord { function get_form_settings($k) { return $k === 'id' ? 'abc123' : 9; } }
do_action('elementor_pro/forms/new_record', new FakeRecord());
class FakeCF7 { function id() { return 55; } }
do_action('wpcf7_submit', new FakeCF7(), ['status' => 'mail_failed']);
do_action('wpcf7_submit', new FakeCF7(), ['status' => 'spam']);
// Bricksforge: failed request must not leak its form id into the next one
$bad = new WP_REST_Request('POST', '/bricksforge/v1/form_submit'); $bad->set_param('formId', 'leak');
apply_filters('rest_request_before_callbacks', null, [], $bad);
apply_filters('rest_request_after_callbacks', null, [], $bad);
do_action('bricksforge/pro_forms/before_submit', ['postId' => 1]);
// live: admin test submission
wp_set_current_user(1);
do_action('wpforms_process_complete', [], [], ['id' => 12], 0);
wp_set_current_user(0);

echo "log rows: "; print_r($wpdb->get_results("SELECT source, form_id, post_id, is_test FROM " . NWR_Lead_Stats::table(), ARRAY_A));
echo "key=" . get_option('nwr_leads_key') . "\n";
