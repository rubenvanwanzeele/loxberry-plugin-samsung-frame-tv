<?php
// Temporary diagnostics for blank-page troubleshooting on LoxBerry.
// Remove these lines again after the root cause is fixed.
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * Samsung Frame TV — LoxBerry Plugin Web UI
 *
 * Sections:
 *   1. Configuration (TV IP, MAC, MQTT topics, poll interval, log level)
 *   2. Pairing (invoke pair.py, display result)
 *   3. Live status (current TV state + last updated)
 *   4. Test controls (power, art mode, common keys)
 */

require_once "/opt/loxberry/libs/phplib/loxberry_system.php";
require_once "/opt/loxberry/libs/phplib/loxberry_web.php";
require_once "/opt/loxberry/libs/phplib/loxberry_io.php";

$pluginname = "samsungframe";
$cfgfile = "$lbpconfigdir/samsungframe.cfg";
$bindir = $lbpbindir;
$logfile = "$lbplogdir/monitor.log";

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sanitize_device_id($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim($value, '_');
    if ($value === '' || $value === null) {
        $value = 'default';
    }
    if ($value === 'all') {
        $value = 'tv_all';
    }
    return $value;
}

function cfg_get($cfg, $section, $key, $default = '') {
    return isset($cfg[$section][$key]) ? $cfg[$section][$key] : $default;
}

function normalize_topic($topic, $default) {
    $topic = trim((string)$topic);
    return $topic !== '' ? rtrim($topic, '/') : $default;
}

function token_file_for_device($cfgdir, $device_id) {
    return $device_id === 'default'
        ? "$cfgdir/token.txt"
        : "$cfgdir/token_{$device_id}.txt";
}

function device_state_topic($base_topic, $device_id) {
    return rtrim($base_topic, '/') . '/' . $device_id;
}

function device_cmd_topic($base_topic, $device_id) {
    return rtrim($base_topic, '/') . '/' . $device_id;
}

function broadcast_cmd_topic($base_topic) {
    return rtrim($base_topic, '/') . '/all';
}

function cfg_load_model($file) {
    $raw = parse_ini_file($file, true, INI_SCANNER_RAW);
    if (!is_array($raw)) {
        $raw = [];
    }

    $general = [
        'legacy_state_topic' => normalize_topic(cfg_get($raw, 'MQTT', 'STATE_TOPIC', 'loxberry/plugin/samsungframe/state'), 'loxberry/plugin/samsungframe/state'),
        'legacy_cmd_topic' => normalize_topic(cfg_get($raw, 'MQTT', 'CMD_TOPIC', 'loxberry/plugin/samsungframe/cmd'), 'loxberry/plugin/samsungframe/cmd'),
        'poll_interval' => max(5, intval(cfg_get($raw, 'MONITOR', 'POLL_INTERVAL', 5))),
        'loglevel' => max(1, min(6, intval(cfg_get($raw, 'MONITOR', 'LOGLEVEL', 3)))),
        'primary_device' => sanitize_device_id(cfg_get($raw, 'GENERAL', 'PRIMARY_DEVICE', 'default')),
        'mqtt_host' => cfg_get($raw, 'MQTT', 'HOST', 'localhost'),
        'mqtt_port' => cfg_get($raw, 'MQTT', 'PORT', 1883),
    ];

    $devices = [];
    foreach ($raw as $section => $pairs) {
        if (strpos($section, 'DEVICE_') !== 0) {
            continue;
        }
        $device_id = sanitize_device_id(substr($section, 7));
        $devices[] = [
            'device_id' => $device_id,
            'label' => trim(cfg_get($raw, $section, 'LABEL', $device_id)) ?: $device_id,
            'ip' => trim(cfg_get($raw, $section, 'IP', '')),
            'mac' => trim(cfg_get($raw, $section, 'MAC', '')),
            'port' => max(1, min(65535, intval(cfg_get($raw, $section, 'PORT', 8002) ?: 8002))),
            'name' => trim(cfg_get($raw, $section, 'NAME', 'LoxBerry')) ?: 'LoxBerry',
            'enabled' => in_array(strtolower((string)cfg_get($raw, $section, 'ENABLED', '1')), ['1', 'true', 'yes', 'on'], true),
        ];
    }

    if (empty($devices)) {
        $devices[] = [
            'device_id' => 'default',
            'label' => trim(cfg_get($raw, 'TV', 'LABEL', 'Primary TV')) ?: 'Primary TV',
            'ip' => trim(cfg_get($raw, 'TV', 'IP', '')),
            'mac' => trim(cfg_get($raw, 'TV', 'MAC', '')),
            'port' => max(1, min(65535, intval(cfg_get($raw, 'TV', 'PORT', 8002) ?: 8002))),
            'name' => trim(cfg_get($raw, 'TV', 'NAME', 'LoxBerry')) ?: 'LoxBerry',
            'enabled' => true,
        ];
    }

    $device_ids = array_map(function ($device) {
        return $device['device_id'];
    }, $devices);
    if (!in_array($general['primary_device'], $device_ids, true)) {
        $general['primary_device'] = $devices[0]['device_id'];
    }

    foreach ($devices as &$device) {
        $device['is_primary'] = $device['device_id'] === $general['primary_device'];
        $device['state_topic'] = device_state_topic($general['legacy_state_topic'], $device['device_id']);
        $device['cmd_topic'] = device_cmd_topic($general['legacy_cmd_topic'], $device['device_id']);
        $device['ui_state_topic'] = $device['is_primary'] ? $general['legacy_state_topic'] : $device['state_topic'];
        $device['ui_cmd_topic'] = $device['is_primary'] ? $general['legacy_cmd_topic'] : $device['cmd_topic'];
    }
    unset($device);

    return ['general' => $general, 'devices' => $devices, 'raw' => $raw];
}

function write_ini_file($file, $sections) {
    $out = "";
    foreach ($sections as $section => $pairs) {
        $out .= "[$section]\n";
        foreach ($pairs as $key => $value) {
            $value = str_replace(["\r", "\n"], [' ', ' '], (string)$value);
            $out .= "$key=$value\n";
        }
        $out .= "\n";
    }
    file_put_contents($file, $out);
}

function cfg_write_model($file, $general, $devices, $mqtt_host, $mqtt_port) {
    if (empty($devices)) {
        $devices[] = [
            'device_id' => 'default',
            'label' => 'Primary TV',
            'ip' => '',
            'mac' => '',
            'port' => 8002,
            'name' => 'LoxBerry',
            'enabled' => true,
        ];
    }

    $primary_id = sanitize_device_id(isset($general['primary_device']) ? $general['primary_device'] : $devices[0]['device_id']);
    $primary_device = null;
    foreach ($devices as $device) {
        if ($device['device_id'] === $primary_id) {
            $primary_device = $device;
            break;
        }
    }
    if ($primary_device === null) {
        $primary_device = $devices[0];
        $primary_id = $primary_device['device_id'];
    }

    $sections = [
        'TV' => [
            'IP' => $primary_device['ip'],
            'MAC' => $primary_device['mac'],
            'PORT' => $primary_device['port'],
            'NAME' => $primary_device['name'],
            'LABEL' => $primary_device['label'],
        ],
        'MQTT' => [
            'HOST' => $mqtt_host,
            'PORT' => $mqtt_port,
            'STATE_TOPIC' => $general['legacy_state_topic'],
            'CMD_TOPIC' => $general['legacy_cmd_topic'],
        ],
        'MONITOR' => [
            'POLL_INTERVAL' => $general['poll_interval'],
            'LOGLEVEL' => $general['loglevel'],
        ],
        'GENERAL' => [
            'PRIMARY_DEVICE' => $primary_id,
        ],
    ];

    foreach ($devices as $device) {
        $sections['DEVICE_' . $device['device_id']] = [
            'LABEL' => $device['label'],
            'IP' => $device['ip'],
            'MAC' => $device['mac'],
            'PORT' => $device['port'],
            'NAME' => $device['name'],
            'ENABLED' => $device['enabled'] ? 1 : 0,
        ];
    }

    write_ini_file($file, $sections);
}

function unique_device_id($requested, &$used_ids, $fallback_base = 'tv') {
    $base = sanitize_device_id($requested);
    if ($base === 'default' && in_array('default', $used_ids, true)) {
        $base = $fallback_base;
    }

    $candidate = $base;
    $counter = 2;
    while (in_array($candidate, $used_ids, true)) {
        $candidate = $base . $counter;
        $counter++;
    }
    $used_ids[] = $candidate;
    return $candidate;
}

function discover_mac($ip) {
    $ip = trim((string)$ip);
    if ($ip === '') {
        return '';
    }
    $arp_out = shell_exec('arp -n ' . escapeshellarg($ip) . ' 2>/dev/null');
    if (preg_match('/([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i', (string)$arp_out, $matches)) {
        return strtolower($matches[1]);
    }
    return '';
}

function mqtt_read_state($topic, $mqtt_host, $mqtt_port, $mqtt_auth) {
    $cmd = 'mosquitto_sub -h ' . escapeshellarg($mqtt_host)
         . ' -p ' . escapeshellarg($mqtt_port)
         . $mqtt_auth
         . ' -t ' . escapeshellarg($topic)
         . ' -C 1 -W 2 2>/dev/null';
    $result = trim((string)shell_exec($cmd));
    return $result !== '' ? $result : 'unknown';
}

function sf_mqtt_publish($topic, $payload, $mqtt_host, $mqtt_port, $mqtt_auth) {
    $cmd = 'mosquitto_pub -h ' . escapeshellarg($mqtt_host)
         . ' -p ' . escapeshellarg($mqtt_port)
         . $mqtt_auth
         . ' -t ' . escapeshellarg($topic)
         . ' -m ' . escapeshellarg($payload)
         . ' 2>&1';
    return trim((string)shell_exec($cmd));
}

function find_device($devices, $device_id) {
    foreach ($devices as $device) {
        if ($device['device_id'] === $device_id) {
            return $device;
        }
    }
    return null;
}

$model = cfg_load_model($cfgfile);
$plugin_cfg = $model['raw'];
$general = $model['general'];
$devices = $model['devices'];

$mqtt_cred = mqtt_connectiondetails();
$mqtt_host = !empty($mqtt_cred['brokerhost']) ? $mqtt_cred['brokerhost'] : 'localhost';
$mqtt_port = !empty($mqtt_cred['brokerport']) ? $mqtt_cred['brokerport'] : 1883;
$mqtt_auth = !empty($mqtt_cred['brokeruser'])
    ? ' -u ' . escapeshellarg($mqtt_cred['brokeruser']) . ' -P ' . escapeshellarg($mqtt_cred['brokerpass'])
    : '';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    $device_id = sanitize_device_id(isset($_GET['device']) ? $_GET['device'] : 'default');
    $model = cfg_load_model($cfgfile);
    $device = find_device($model['devices'], $device_id);
    $topic = $device ? $device['ui_state_topic'] : normalize_topic(cfg_get($plugin_cfg, 'MQTT', 'STATE_TOPIC', 'loxberry/plugin/samsungframe/state'), 'loxberry/plugin/samsungframe/state');

    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode([
        'state' => mqtt_read_state($topic, $mqtt_host, $mqtt_port, $mqtt_auth),
        'updated' => date('H:i:s'),
        'topic' => $topic,
        'device' => $device_id,
    ]);
    exit;
}

$message = '';
$message_type = 'info';
$refresh_after_cmd = false;
$pair_output = '';
$pair_output_device_id = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'restart_daemon') {
        $out = shell_exec('sudo /bin/systemctl restart samsungframe.service 2>&1');
        $message = 'Daemon restarted.' . ($out ? ' (' . trim($out) . ')' : '');
        $message_type = 'success';
    }

    if ($action === 'save_config') {
        $notes = [];
        $general_post = [
            'legacy_state_topic' => normalize_topic(isset($_POST['state_topic']) ? $_POST['state_topic'] : $general['legacy_state_topic'], 'loxberry/plugin/samsungframe/state'),
            'legacy_cmd_topic' => normalize_topic(isset($_POST['cmd_topic']) ? $_POST['cmd_topic'] : $general['legacy_cmd_topic'], 'loxberry/plugin/samsungframe/cmd'),
            'poll_interval' => max(5, min(300, intval(isset($_POST['poll_interval']) ? $_POST['poll_interval'] : $general['poll_interval']))),
            'loglevel' => max(1, min(6, intval(isset($_POST['loglevel']) ? $_POST['loglevel'] : $general['loglevel']))),
            'primary_device' => 'default',
        ];

        $posted_devices = isset($_POST['devices']) && is_array($_POST['devices']) ? $_POST['devices'] : [];
        $primary_row = isset($_POST['primary_device_row']) ? (string)$_POST['primary_device_row'] : '0';
        $saved_devices = [];
        $used_ids = [];
        $primary_device_id = null;

        foreach ($posted_devices as $row_key => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['remove'])) {
                continue;
            }

            $requested_id = trim((string)(isset($row['device_id']) ? $row['device_id'] : ''));
            $label = trim((string)(isset($row['label']) ? $row['label'] : ''));
            $ip = trim((string)(isset($row['ip']) ? $row['ip'] : ''));
            $mac = trim((string)(isset($row['mac']) ? $row['mac'] : ''));
            $name = trim((string)(isset($row['name']) ? $row['name'] : '')) ?: 'LoxBerry';
            $port = max(1, min(65535, intval(isset($row['port']) ? $row['port'] : 8002) ?: 8002));
            $enabled = !empty($row['enabled']);

            if ($requested_id === '') {
                $requested_id = $label !== '' ? $label : ('tv' . (count($saved_devices) + 1));
            }
            $device_id = unique_device_id($requested_id, $used_ids, 'tv');
            if ($device_id !== sanitize_device_id($requested_id)) {
                $notes[] = "Adjusted duplicate device ID to '{$device_id}'.";
            }
            if ($label === '') {
                $label = ucfirst(str_replace('_', ' ', $device_id));
            }
            if ($mac === '' && $ip !== '') {
                $discovered_mac = discover_mac($ip);
                if ($discovered_mac !== '') {
                    $mac = $discovered_mac;
                    $notes[] = "Auto-discovered MAC for {$label}: {$mac}.";
                }
            }

            $saved_devices[] = [
                'device_id' => $device_id,
                'label' => $label,
                'ip' => $ip,
                'mac' => $mac,
                'port' => $port,
                'name' => $name,
                'enabled' => $enabled,
            ];

            if ((string)$row_key === $primary_row) {
                $primary_device_id = $device_id;
            }
        }

        if (empty($saved_devices)) {
            $saved_devices[] = [
                'device_id' => 'default',
                'label' => 'Primary TV',
                'ip' => '',
                'mac' => '',
                'port' => 8002,
                'name' => 'LoxBerry',
                'enabled' => true,
            ];
            $primary_device_id = 'default';
            $notes[] = 'At least one TV is required. A blank primary TV was kept.';
        }

        if ($primary_device_id === null || !find_device($saved_devices, $primary_device_id)) {
            $primary_device_id = $saved_devices[0]['device_id'];
        }
        $general_post['primary_device'] = $primary_device_id;

        cfg_write_model($cfgfile, $general_post, $saved_devices, $mqtt_host, $mqtt_port);
        shell_exec('sudo /bin/systemctl restart samsungframe.service 2>&1');

        $message = 'Configuration saved and daemon restarted.';
        if (!empty($notes)) {
            $message .= ' ' . implode(' ', $notes);
        }
        $message_type = 'success';
    }

    if ($action === 'pair') {
        $device_id = sanitize_device_id(isset($_POST['device_id']) ? $_POST['device_id'] : 'default');
        $cmd = '/usr/bin/python3 ' . escapeshellarg("$bindir/pair.py")
             . ' --config ' . escapeshellarg($cfgfile)
             . ' --device ' . escapeshellarg($device_id)
             . ' 2>&1';
        $pair_output = trim((string)shell_exec($cmd));
        $pair_output_device_id = $device_id;
        if (strpos($pair_output, 'SUCCESS') !== false) {
            $message = 'Pairing successful! Token saved.';
            $message_type = 'success';
        } elseif (strpos($pair_output, 'ERROR') !== false) {
            $message = 'Pairing failed. See details below.';
            $message_type = 'error';
        } else {
            $message = 'Pairing command ran. See details below.';
            $message_type = 'info';
        }
    }

    if ($action === 'test_cmd') {
        $cmd_payload = trim((string)(isset($_POST['cmd_payload']) ? $_POST['cmd_payload'] : ''));
        $target_device = sanitize_device_id(isset($_POST['target_device']) ? $_POST['target_device'] : 'default');
        $scope = isset($_POST['scope']) ? $_POST['scope'] : 'device';

        if ($cmd_payload !== '') {
            if ($scope === 'all') {
                $topic = broadcast_cmd_topic($general['legacy_cmd_topic']);
            } else {
                $device = find_device($devices, $target_device);
                $topic = $device ? $device['ui_cmd_topic'] : $general['legacy_cmd_topic'];
            }
            $pub_out = sf_mqtt_publish($topic, $cmd_payload, $mqtt_host, $mqtt_port, $mqtt_auth);
            $message = "Command '{$cmd_payload}' sent to {$topic}." . ($pub_out ? ' (' . $pub_out . ')' : '');
            $message_type = 'success';
            $refresh_after_cmd = true;
        }
    }

    $model = cfg_load_model($cfgfile);
    $plugin_cfg = $model['raw'];
    $general = $model['general'];
    $devices = $model['devices'];
}

$state_color = ['off' => '#e74c3c', 'art' => '#9b59b6', 'on' => '#2ecc71', 'unknown' => '#95a5a6'];
$state_label = ['off' => 'Off', 'art' => 'Art Mode', 'on' => 'On (Active)', 'unknown' => 'Unknown'];

$device_statuses = [];
foreach ($devices as $device) {
    $device_statuses[$device['device_id']] = [
        'state' => mqtt_read_state($device['ui_state_topic'], $mqtt_host, $mqtt_port, $mqtt_auth),
        'paired' => file_exists(token_file_for_device($lbpconfigdir, $device['device_id'])),
    ];
}

LBWeb::lbheader('Samsung Frame TV', $pluginname, 'help.html');
?>
<style>
.sf-card { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.12); padding:20px 24px; margin-bottom:24px; }
.sf-card h3 { margin-top:0; border-bottom:1px solid #eee; padding-bottom:8px; font-size:1.1em; }
.sf-msg { padding:10px 14px; border-radius:4px; margin-bottom:16px; font-weight:500; }
.sf-msg.success { background:#d5f5e3; color:#1e8449; }
.sf-msg.error { background:#fadbd8; color:#922b21; }
.sf-msg.info { background:#d6eaf8; color:#1a5276; }
.sf-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 24px; }
.sf-grid-3 { display:grid; grid-template-columns:1.2fr .8fr .8fr; gap:12px 16px; }
.sf-grid label { font-weight:500; }
.sf-grid input, .sf-grid select, .sf-grid textarea, .sf-grid-3 input { width:100%; padding:6px 8px; border:1px solid #ccc; border-radius:4px; font-size:.95em; box-sizing:border-box; }
.sf-btn-row { margin-top:10px; display:flex; flex-wrap:wrap; gap:8px; }
.sf-btn { padding:7px 16px; border:none; border-radius:4px; cursor:pointer; font-size:.9em; font-weight:500; }
.sf-btn-primary { background:#2980b9; color:#fff; }
.sf-btn-success { background:#27ae60; color:#fff; }
.sf-btn-warning { background:#e67e22; color:#fff; }
.sf-btn-danger { background:#c0392b; color:#fff; }
.sf-btn-purple { background:#8e44ad; color:#fff; }
.sf-btn-grey { background:#7f8c8d; color:#fff; }
.sf-btn-help { background:#34495e; color:#fff; text-decoration:none; display:inline-block; }
.sf-btn:hover { opacity:.87; }
.sf-state-badge, .sf-small-badge { display:inline-block; color:#fff; font-weight:600; letter-spacing:.2px; }
.sf-state-badge { padding:6px 18px; border-radius:20px; font-size:1em; }
.sf-small-badge { padding:3px 10px; border-radius:12px; font-size:.8em; }
.sf-tag { display:inline-block; padding:3px 8px; border-radius:12px; background:#eef3f7; color:#47606d; font-size:.8em; margin-left:6px; }
.sf-pair-status { display:inline-block; padding:4px 12px; border-radius:12px; font-weight:600; font-size:.95em; }
.sf-pair-status.paired { background:#d5f5e3; color:#1e8449; }
.sf-pair-status.unpaired { background:#fdebd0; color:#a04000; }
.sf-device-config { border:1px solid #e5e7eb; border-radius:6px; padding:16px; margin-bottom:16px; background:#fbfcfd; }
.sf-device-config.disabled { opacity:.72; }
.sf-device-header { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
.sf-device-title { font-weight:700; font-size:1em; }
.sf-muted { color:#7f8c8d; font-size:.9em; }
.sf-mini-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px 16px; }
.sf-summary-table { width:100%; border-collapse:collapse; }
.sf-summary-table th, .sf-summary-table td { padding:10px 8px; border-bottom:1px solid #eee; text-align:left; vertical-align:top; }
.sf-pre { background:#f4f4f4; border-radius:4px; padding:10px 12px; font-size:.82em; max-height:220px; overflow:auto; white-space:pre-wrap; }
.sf-hidden { display:none; }
@media (max-width: 900px) { .sf-grid, .sf-grid-3, .sf-mini-grid { grid-template-columns:1fr; } }
</style>

<?php if ($message): ?>
<div class="sf-msg <?= h($message_type) ?>"><?= h($message) ?></div>
<?php endif; ?>

<div class="sf-card">
    <h3>Live Status Overview</h3>
    <p class="sf-muted">
        The primary TV keeps the legacy topics for backward compatibility. Every TV also has its own device topic suffix.
    </p>
    <table class="sf-summary-table">
        <thead>
            <tr>
                <th>Device</th>
                <th>Status</th>
                <th>Pairing</th>
                <th>Topics</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($devices as $device):
                $status = $device_statuses[$device['device_id']];
                $state = $status['state'];
                $color = isset($state_color[$state]) ? $state_color[$state] : $state_color['unknown'];
                $label = isset($state_label[$state]) ? $state_label[$state] : ucfirst($state);
            ?>
            <tr>
                <td>
                    <strong><?= h($device['label']) ?></strong>
                    <?php if ($device['is_primary']): ?><span class="sf-tag">Primary</span><?php endif; ?>
                    <?php if (!$device['enabled']): ?><span class="sf-tag">Disabled</span><?php endif; ?>
                    <div class="sf-muted">ID: <code><?= h($device['device_id']) ?></code><?php if ($device['ip']): ?> · <?= h($device['ip']) ?><?php endif; ?></div>
                </td>
                <td>
                    <span id="sf-state-<?= h($device['device_id']) ?>" class="sf-state-badge" style="background:<?= h($color) ?>"><?= h($label) ?></span>
                    <div id="sf-updated-<?= h($device['device_id']) ?>" class="sf-muted">updated at <?= h(date('H:i:s')) ?></div>
                </td>
                <td>
                    <?php if ($status['paired']): ?>
                        <span class="sf-pair-status paired">Paired ✓</span>
                    <?php else: ?>
                        <span class="sf-pair-status unpaired">Not paired</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div><strong>State:</strong> <code><?= h($device['ui_state_topic']) ?></code></div>
                    <div><strong>Command:</strong> <code><?= h($device['ui_cmd_topic']) ?></code></div>
                    <?php if ($device['is_primary']): ?>
                        <div class="sf-muted">Extra device topics: <code><?= h($device['state_topic']) ?></code> and <code><?= h($device['cmd_topic']) ?></code></div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" style="margin-top:14px; display:inline">
        <input type="hidden" name="action" value="restart_daemon">
        <button type="submit" class="sf-btn sf-btn-warning">Restart Daemon</button>
    </form>
    <a class="sf-btn sf-btn-help" style="margin-top:14px;" href="help.html" title="Open plugin help and MQTT reference">
        Open Help
    </a>
</div>

<form method="post" id="sf-config-form">
    <input type="hidden" name="action" value="save_config">

    <div class="sf-card">
        <h3>General Configuration</h3>
        <div class="sf-grid">
            <div>
                <label>Legacy Primary State Topic <small>(primary TV → Loxone)</small></label>
                <input type="text" name="state_topic" value="<?= h($general['legacy_state_topic']) ?>">
                <div class="sf-muted">Each TV also publishes to <code><?= h($general['legacy_state_topic']) ?>/&lt;device_id&gt;</code>.</div>
            </div>
            <div>
                <label>Legacy Primary Command Topic <small>(Loxone → primary TV)</small></label>
                <input type="text" name="cmd_topic" value="<?= h($general['legacy_cmd_topic']) ?>">
                <div class="sf-muted">Each TV also listens on <code><?= h($general['legacy_cmd_topic']) ?>/&lt;device_id&gt;</code>. Broadcast: <code><?= h(broadcast_cmd_topic($general['legacy_cmd_topic'])) ?></code></div>
            </div>
            <div>
                <label>Poll Interval (seconds)</label>
                <input type="number" name="poll_interval" value="<?= h($general['poll_interval']) ?>" min="5" max="300">
            </div>
            <div>
                <label>Log Level</label>
                <select name="loglevel">
                    <?php foreach ([1=>'1 – Critical', 2=>'2 – Error', 3=>'3 – Warning', 4=>'4 – Info', 5=>'5 – Debug', 6=>'6 – Verbose'] as $v => $l): ?>
                    <option value="<?= h($v) ?>" <?= intval($general['loglevel']) === $v ? 'selected' : '' ?>><?= h($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p class="sf-muted" style="margin-top:10px">MQTT broker host and port are still read automatically from LoxBerry system settings.</p>
    </div>

    <div class="sf-card">
        <h3>TV Configuration</h3>
        <p class="sf-muted">For most users, one TV is enough. Add extra TVs only if you need them.</p>
        <div id="sf-device-list">
            <?php foreach ($devices as $index => $device): ?>
            <div class="sf-device-config <?= !$device['enabled'] ? 'disabled' : '' ?>" data-device-card>
                <div class="sf-device-header">
                    <div>
                        <div class="sf-device-title"><?= h($device['label']) ?></div>
                        <div class="sf-muted">Topic suffix / ID: <code><?= h($device['device_id']) ?></code></div>
                    </div>
                    <button type="button" class="sf-btn sf-btn-danger" data-remove-device>Remove</button>
                </div>
                <input type="hidden" name="devices[<?= h($index) ?>][remove]" value="0" data-remove-flag>
                <div class="sf-mini-grid">
                    <div>
                        <label>Label <small>(used in UI and logs)</small></label>
                        <input type="text" name="devices[<?= h($index) ?>][label]" value="<?= h($device['label']) ?>" placeholder="Living Room TV">
                    </div>
                    <div>
                        <label>Device ID <small>(topic suffix)</small></label>
                        <input type="text" name="devices[<?= h($index) ?>][device_id]" value="<?= h($device['device_id']) ?>" placeholder="living_room">
                    </div>
                    <div>
                        <label>TV IP Address</label>
                        <input type="text" name="devices[<?= h($index) ?>][ip]" value="<?= h($device['ip']) ?>" placeholder="192.168.1.43">
                    </div>
                    <div>
                        <label>TV MAC Address <small>(for Wake-on-LAN; auto-filled on save)</small></label>
                        <input type="text" name="devices[<?= h($index) ?>][mac]" value="<?= h($device['mac']) ?>" placeholder="Auto-discovered via ARP">
                    </div>
                    <div>
                        <label>WebSocket Port</label>
                        <input type="number" name="devices[<?= h($index) ?>][port]" value="<?= h($device['port']) ?>" min="1" max="65535">
                    </div>
                    <div>
                        <label>Connection Name <small>(shown on TV pairing popup)</small></label>
                        <input type="text" name="devices[<?= h($index) ?>][name]" value="<?= h($device['name']) ?>" placeholder="LoxBerry">
                    </div>
                </div>
                <div class="sf-btn-row">
                    <label><input type="checkbox" name="devices[<?= h($index) ?>][enabled]" value="1" <?= $device['enabled'] ? 'checked' : '' ?>> Enabled</label>
                    <label><input type="radio" name="primary_device_row" value="<?= h($index) ?>" <?= $device['is_primary'] ? 'checked' : '' ?>> Use as primary TV (legacy topics)</label>
                </div>
                <div class="sf-muted" style="margin-top:8px">
                    Device topics: <code><?= h($device['cmd_topic']) ?></code> and <code><?= h($device['state_topic']) ?></code>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="sf-btn-row">
            <button type="button" class="sf-btn sf-btn-grey" id="sf-add-device">Add another TV</button>
            <button type="submit" class="sf-btn sf-btn-primary">Save Configuration</button>
        </div>
    </div>
</form>

<?php foreach ($devices as $device):
    $status = $device_statuses[$device['device_id']];
    $paired = $status['paired'];
?>
<div class="sf-card">
    <h3><?= h($device['label']) ?><?php if ($device['is_primary']): ?> <span class="sf-tag">Primary</span><?php endif; ?></h3>
    <p>
        Pairing status:
        <?php if ($paired): ?>
            <span class="sf-pair-status paired">Paired ✓</span>
            <small class="sf-muted">Token file exists and will be reused automatically.</small>
        <?php else: ?>
            <span class="sf-pair-status unpaired">Not paired</span>
            <small class="sf-muted">No token saved yet for this TV.</small>
        <?php endif; ?>
    </p>
    <p class="sf-muted">
        State topic: <code><?= h($device['ui_state_topic']) ?></code><br>
        Command topic: <code><?= h($device['ui_cmd_topic']) ?></code><br>
        Token file: <code><?= h(basename(token_file_for_device($lbpconfigdir, $device['device_id']))) ?></code>
    </p>
    <?php if ($pair_output && $pair_output_device_id === $device['device_id']): ?>
    <pre class="sf-pre"><?= h($pair_output) ?></pre>
    <?php endif; ?>
    <form method="post" style="display:inline">
        <input type="hidden" name="action" value="pair">
        <input type="hidden" name="device_id" value="<?= h($device['device_id']) ?>">
        <button type="submit" class="sf-btn sf-btn-success" onclick="return confirm('Make sure this TV is powered on and showing a picture. Accept the popup on the TV. Proceed?')">Start Pairing</button>
    </form>

    <div style="margin-top:18px">
        <strong>Power</strong>
        <div class="sf-btn-row" style="margin-bottom:14px">
            <?php foreach (['power_on' => ['sf-btn-success', 'Power On'], 'power_off' => ['sf-btn-danger', 'Power Off']] as $cmd => $meta): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="test_cmd">
                <input type="hidden" name="target_device" value="<?= h($device['device_id']) ?>">
                <input type="hidden" name="scope" value="device">
                <input type="hidden" name="cmd_payload" value="<?= h($cmd) ?>">
                <button type="submit" class="sf-btn <?= h($meta[0]) ?>"><?= h($meta[1]) ?></button>
            </form>
            <?php endforeach; ?>
        </div>

        <strong>Art Mode</strong>
        <div class="sf-btn-row" style="margin-bottom:14px">
            <?php foreach (['art_on' => ['sf-btn-purple', 'Art Mode On'], 'art_off' => ['sf-btn-grey', 'Art Mode Off']] as $cmd => $meta): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="test_cmd">
                <input type="hidden" name="target_device" value="<?= h($device['device_id']) ?>">
                <input type="hidden" name="scope" value="device">
                <input type="hidden" name="cmd_payload" value="<?= h($cmd) ?>">
                <button type="submit" class="sf-btn <?= h($meta[0]) ?>"><?= h($meta[1]) ?></button>
            </form>
            <?php endforeach; ?>
        </div>

        <strong>Common Keys</strong>
        <div class="sf-btn-row">
            <?php foreach (['key_KEY_MUTE' => 'Mute', 'key_KEY_VOLUP' => 'Vol +', 'key_KEY_VOLDOWN' => 'Vol -', 'key_KEY_UP' => '▲', 'key_KEY_DOWN' => '▼', 'key_KEY_LEFT' => '◀', 'key_KEY_RIGHT' => '▶', 'key_KEY_ENTER' => 'OK', 'key_KEY_RETURN' => 'Back', 'key_KEY_HOME' => 'Home'] as $cmd => $lbl): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="test_cmd">
                <input type="hidden" name="target_device" value="<?= h($device['device_id']) ?>">
                <input type="hidden" name="scope" value="device">
                <input type="hidden" name="cmd_payload" value="<?= h($cmd) ?>">
                <button type="submit" class="sf-btn sf-btn-grey"><?= h($lbl) ?></button>
            </form>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:16px">
            <strong>Custom Command</strong>
            <form method="post" style="display:flex; gap:8px; margin-top:6px; flex-wrap:wrap">
                <input type="hidden" name="action" value="test_cmd">
                <input type="hidden" name="target_device" value="<?= h($device['device_id']) ?>">
                <input type="hidden" name="scope" value="device">
                <input type="text" name="cmd_payload" placeholder="e.g. key_KEY_HDMI1" style="padding:6px 8px; border:1px solid #ccc; border-radius:4px; width:260px">
                <button type="submit" class="sf-btn sf-btn-primary">Send</button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (count($devices) > 1): ?>
<div class="sf-card">
    <h3>All TVs Test Controls</h3>
    <p class="sf-muted">Send the same command to every enabled TV via <code><?= h(broadcast_cmd_topic($general['legacy_cmd_topic'])) ?></code>.</p>
    <div class="sf-btn-row">
        <?php foreach (['power_on' => ['sf-btn-success', 'Power On All'], 'power_off' => ['sf-btn-danger', 'Power Off All'], 'art_on' => ['sf-btn-purple', 'Art Mode On All'], 'art_off' => ['sf-btn-grey', 'Art Mode Off All']] as $cmd => $meta): ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="test_cmd">
            <input type="hidden" name="scope" value="all">
            <input type="hidden" name="cmd_payload" value="<?= h($cmd) ?>">
            <button type="submit" class="sf-btn <?= h($meta[0]) ?>"><?= h($meta[1]) ?></button>
        </form>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
(function() {
    var stateColors = {off:'#e74c3c', art:'#9b59b6', on:'#2ecc71', unknown:'#95a5a6'};
    var stateLabels = {off:'Off', art:'Art Mode', on:'On (Active)', unknown:'Unknown'};
    var deviceIds = <?= json_encode(array_map(function($device) { return $device['device_id']; }, $devices)) ?>;
    var nextIndex = <?= json_encode(count($devices)) ?>;

    function refreshState(deviceId) {
        fetch('index.php?ajax=status&device=' + encodeURIComponent(deviceId) + '&_=' + Date.now())
            .then(function(response) { return response.json(); })
            .then(function(data) {
                var state = data.state || 'unknown';
                var badge = document.getElementById('sf-state-' + deviceId);
                if (badge) {
                    badge.style.background = stateColors[state] || stateColors.unknown;
                    badge.textContent = stateLabels[state] || state;
                }
                var updated = document.getElementById('sf-updated-' + deviceId);
                if (updated && data.updated) {
                    updated.textContent = 'updated at ' + data.updated;
                }
            })
            .catch(function() {});
    }

    function refreshAllStates() {
        deviceIds.forEach(refreshState);
    }

    document.querySelectorAll('[data-remove-device]').forEach(function(button) {
        button.addEventListener('click', function() {
            var card = button.closest('[data-device-card]');
            if (!card) { return; }
            var removeFlag = card.querySelector('[data-remove-flag]');
            if (removeFlag) {
                removeFlag.value = '1';
            }
            card.classList.add('sf-hidden');
        });
    });

    var addButton = document.getElementById('sf-add-device');
    if (addButton) {
        addButton.addEventListener('click', function() {
            var container = document.getElementById('sf-device-list');
            var idx = nextIndex++;
            var deviceNumber = idx + 1;
            var wrapper = document.createElement('div');
            wrapper.className = 'sf-device-config';
            wrapper.setAttribute('data-device-card', '1');
            wrapper.innerHTML = ''
                + '<div class="sf-device-header">'
                + '  <div><div class="sf-device-title">New TV</div><div class="sf-muted">Choose a unique topic suffix / ID.</div></div>'
                + '  <button type="button" class="sf-btn sf-btn-danger" data-remove-device>Remove</button>'
                + '</div>'
                + '<input type="hidden" name="devices[' + idx + '][remove]" value="0" data-remove-flag>'
                + '<div class="sf-mini-grid">'
                + '  <div><label>Label <small>(used in UI and logs)</small></label><input type="text" name="devices[' + idx + '][label]" value="TV ' + deviceNumber + '"></div>'
                + '  <div><label>Device ID <small>(topic suffix)</small></label><input type="text" name="devices[' + idx + '][device_id]" value="tv' + deviceNumber + '"></div>'
                + '  <div><label>TV IP Address</label><input type="text" name="devices[' + idx + '][ip]" placeholder="192.168.1.43"></div>'
                + '  <div><label>TV MAC Address <small>(for Wake-on-LAN; auto-filled on save)</small></label><input type="text" name="devices[' + idx + '][mac]" placeholder="Auto-discovered via ARP"></div>'
                + '  <div><label>WebSocket Port</label><input type="number" name="devices[' + idx + '][port]" value="8002" min="1" max="65535"></div>'
                + '  <div><label>Connection Name <small>(shown on TV pairing popup)</small></label><input type="text" name="devices[' + idx + '][name]" value="LoxBerry"></div>'
                + '</div>'
                + '<div class="sf-btn-row">'
                + '  <label><input type="checkbox" name="devices[' + idx + '][enabled]" value="1" checked> Enabled</label>'
                + '  <label><input type="radio" name="primary_device_row" value="' + idx + '"> Use as primary TV (legacy topics)</label>'
                + '</div>';
            container.appendChild(wrapper);
            var removeButton = wrapper.querySelector('[data-remove-device]');
            removeButton.addEventListener('click', function() {
                var removeFlag = wrapper.querySelector('[data-remove-flag]');
                if (removeFlag) {
                    removeFlag.value = '1';
                }
                wrapper.classList.add('sf-hidden');
            });
        });
    }

    refreshAllStates();
    setInterval(refreshAllStates, 5000);

    <?php if ($refresh_after_cmd): ?>
    setTimeout(refreshAllStates, 3000);
    setTimeout(refreshAllStates, 6000);
    <?php endif; ?>
})();
</script>

<?php LBWeb::lbfooter(); ?>
