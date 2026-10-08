<?php
// Playground test: Amelia web bookings (info set) count, staff bookings (info NULL) do not; created is GMT.
require dirname(__DIR__) . '/wp-load.php';
global $wpdb;
header('Content-Type: text/plain');
update_option('timezone_string', 'Europe/Bratislava');
$p = $wpdb->prefix . 'amelia_';
$wpdb->query("CREATE TABLE IF NOT EXISTS {$p}services (id int AUTO_INCREMENT PRIMARY KEY, name varchar(255))");
$wpdb->query("CREATE TABLE IF NOT EXISTS {$p}appointments (id int AUTO_INCREMENT PRIMARY KEY, status varchar(20), bookingStart datetime, serviceId int)");
$wpdb->query("CREATE TABLE IF NOT EXISTS {$p}customer_bookings (id int AUTO_INCREMENT PRIMARY KEY, appointmentId int, status varchar(20), info text NULL, created datetime NULL)");
foreach (['services', 'appointments', 'customer_bookings'] as $t) $wpdb->query("TRUNCATE {$p}$t");
$wpdb->insert("{$p}services", ['id' => 1, 'name' => 'Ortopedické vyšetrenie']);
$wpdb->insert("{$p}services", ['id' => 10, 'name' => 'Kontrola']);

$info = '{"firstName":"A","lastName":"B","phone":"1","locale":"sk_SK","timeZone":"Europe/Bratislava","urlParams":null}';
$rows = [
    // [serviceId, info, created GMT, status]
    [1, $info, '2026-10-05 08:00:00', 'approved'],   // local 2026-10-05 10:00
    [1, $info, '2026-10-05 22:30:00', 'canceled'],   // local 2026-10-06 00:30 → next day, canceled still counts
    [10, $info, '2026-10-06 12:00:00', 'rejected'],  // counts
    [1, null, '2026-10-06 09:00:00', 'approved'],    // staff → ignored
    [10, '', '2026-10-06 09:00:00', 'approved'],     // empty info → ignored
    [1, $info, null, 'approved'],                    // no created (old row) → ignored
    [1, $info, '2026-09-01 08:00:00', 'approved'],   // earliest created → complete_since
];
foreach ($rows as $r) {
    $wpdb->insert("{$p}appointments", ['status' => 'approved', 'bookingStart' => '2026-10-20 10:00:00', 'serviceId' => $r[0]]);
    $wpdb->insert("{$p}customer_bookings", ['appointmentId' => $wpdb->insert_id, 'status' => $r[3], 'info' => $r[1], 'created' => $r[2]]);
}
update_option('nwr_leads_since', '2026-10-07 12:00:00', false);
$wpdb->query("TRUNCATE " . NWR_Lead_Stats::table());
// other sources' native history (e.g. left by seed.php) must not leak into these checks
foreach (['bricksforge_submissions', 'frmt_form_entry', 'wpforms_entries', 'e_submissions'] as $t) $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}$t");

function q($params) {
    $req = new WP_REST_Request('GET', '/nwr-leads/v1/counts');
    foreach ($params as $k => $v) $req->set_param($k, $v);
    return NWR_Lead_Stats::rest_counts($req)->get_data();
}
$ok = 0; $fail = 0;
function check($name, $got, $want) {
    global $ok, $fail;
    if ($got === $want) { $ok++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name: got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; }
}

$d = q(['since' => '2026-10-05', 'until' => '2026-10-07']);
check('total', $d['total'], 3);
check('days', $d['days'], ['2026-10-05' => 1, '2026-10-06' => 2, '2026-10-07' => 0]);
$f = [];
foreach ($d['forms'] as $x) $f[$x['key']] = $x;
check('keys', array_keys($f), ['amelia:1', 'amelia:10']);
check('title', $f['amelia:1']['title'], 'Ortopedické vyšetrenie');
check('svc1 days', $f['amelia:1']['days'], ['2026-10-05' => 1, '2026-10-06' => 1]);
check('complete_since', $f['amelia:10']['complete_since'], '2026-09-01');

// local day boundary: until 2026-10-05 must not include the 22:30 GMT booking (local 10-06)
check('boundary', q(['since' => '2026-10-05', 'until' => '2026-10-05'])['total'], 1);
// forms filter
check('filter', q(['since' => '2026-10-05', 'until' => '2026-10-07', 'forms' => 'amelia:10'])['total'], 1);
// counted regardless of live-logging start (no double count, no history cut-off)
update_option('nwr_leads_since', '2026-01-01 00:00:00', false);
check('after since', q(['since' => '2026-10-05', 'until' => '2026-10-07'])['total'], 3);
$f = q(['since' => '2026-10-05', 'until' => '2026-10-07'])['forms'];
check('complete_since not live day', $f[0]['complete_since'], '2026-09-01');
check('month group', q(['since' => '2026-09-01', 'until' => '2026-10-31', 'group' => 'month'])['days'], ['2026-09' => 1, '2026-10' => 3]);

echo "\n$ok passed, $fail failed\n";
