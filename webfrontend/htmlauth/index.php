<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

$pluginname  = "samsungframe";
$cfgfile     = "$lbpconfigdir/samsungframe.cfg";
$bindir      = $lbpbindir;
$logfile     = "$lbplogdir/monitor.log";

// -------------------------------------------------------------------------
// Load / save config
// -------------------------------------------------------------------------

function cfg_read($file) {
    $cfg = parse_ini_file($file, true);
    return $cfg ?: [];
}

function cfg_write($file, $cfg) {
    $out = "";
    foreach ($cfg as $section => $pairs) {
        $out .= "[$section]\n";
        foreach ($pairs as $k => $v) {
            $out .= "$k=$v\n";
        }
        $out .= "\n";
    }
    file_put_contents($file, $out);
}

function cfg_get($plugin_cfg, $section, $key, $default = "") {
    return isset($plugin_cfg[$section][$key]) ? $plugin_cfg[$section][$key] : $default;
}

$plugin_cfg = cfg_read($cfgfile);
$mqtt_cred  = mqtt_connectiondetails();

// -------------------------------------------------------------------------
// Handle form submissions
// -------------------------------------------------------------------------

$message = "";
$message_type = "info";  // "info" | "success" | "error"

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    // --- Restart daemon ---
    if ($action === "restart_daemon") {
        $out = shell_exec("sudo /bin/systemctl restart samsungframe.service 2>&1");
        $message = "Daemon restarted." . ($out ? " ($out)" : "");
        $message_type = "success";
    }

    // --- Save configuration ---
    if ($action === "save_config") {
        $tv_ip  = trim($_POST["tv_ip"]  ?? "");
        $tv_mac = trim($_POST["tv_mac"] ?? "");
        $tv_name = trim($_POST["tv_name"] ?? "LoxBerry");
        $tv_port = intval($_POST["tv_port"] ?? 8002);

        // Auto-discover MAC from ARP if IP changed and MAC is blank
        if ($tv_ip !== "" && $tv_mac === "") {
            $arp_out = shell_exec("arp -n " . escapeshellarg($tv_ip) . " 2>/dev/null");
            if (preg_match('/([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i', $arp_out, $m)) {
                $tv_mac = $m[1];
            }
        }

        $plugin_cfg["TV"]["IP"]   = $tv_ip;
        $plugin_cfg["TV"]["MAC"]  = $tv_mac;
        $plugin_cfg["TV"]["PORT"] = $tv_port;
        $plugin_cfg["TV"]["NAME"] = $tv_name;
        $plugin_cfg["MQTT"]["HOST"]        = trim($_POST["mqtt_host"] ?? "localhost");
        $plugin_cfg["MQTT"]["PORT"]        = intval($_POST["mqtt_port"] ?? 1883);
        $plugin_cfg["MQTT"]["STATE_TOPIC"] = trim($_POST["state_topic"] ?? "loxberry/plugin/samsungframe/state");
        $plugin_cfg["MQTT"]["CMD_TOPIC"]   = trim($_POST["cmd_topic"]   ?? "loxberry/plugin/samsungframe/cmd");
        $plugin_cfg["MONITOR"]["POLL_INTERVAL"] = intval($_POST["poll_interval"] ?? 30);
        $plugin_cfg["MONITOR"]["LOGLEVEL"]      = intval($_POST["loglevel"] ?? 3);

        cfg_write($cfgfile, $plugin_cfg);

        $mac_note = $tv_mac ? " (MAC: $tv_mac)" : "";
        $message = "Configuration saved.$mac_note";
        $message_type = "success";
    }

    // --- Trigger pairing ---
    if ($action === "pair") {
        $cmd = "/usr/bin/python3 " . escapeshellarg("$bindir/pair.py")
             . " --config " . escapeshellarg($cfgfile)
             . " 2>&1";
        $pair_output = shell_exec($cmd);
        if (strpos($pair_output, "SUCCESS") !== false) {
            $message = "Pairing successful! Token saved.";
            $message_type = "success";
        } elseif (strpos($pair_output, "ERROR") !== false) {
            $message = "Pairing failed. See details below.";
            $message_type = "error";
        } else {
            $message = "Pairing command ran. See details below.";
            $message_type = "info";
        }
    }

    // --- Send test command via MQTT publish ---
    if ($action === "test_cmd") {
        $cmd_payload = trim($_POST["cmd_payload"] ?? "");
        if ($cmd_payload !== "") {
            $topic = cfg_get($plugin_cfg, "MQTT", "CMD_TOPIC", "loxberry/plugin/samsungframe/cmd");
            $mqtt_host = cfg_get($plugin_cfg, "MQTT", "HOST", "localhost");
            $mqtt_port = cfg_get($plugin_cfg, "MQTT", "PORT", "1883");
            $auth = !empty($mqtt_cred['brokeruser']) ? " -u " . escapeshellarg($mqtt_cred['brokeruser']) . " -P " . escapeshellarg($mqtt_cred['brokerpass']) : "";
            $pub_cmd = "mosquitto_pub -h " . escapeshellarg($mqtt_host)
                     . " -p " . escapeshellarg($mqtt_port)
                     . $auth
                     . " -t " . escapeshellarg($topic)
                     . " -m " . escapeshellarg($cmd_payload)
                     . " 2>&1";
            $pub_out = shell_exec($pub_cmd);
            $message = "Command '$cmd_payload' sent." . ($pub_out ? " ($pub_out)" : "");
            $message_type = "success";
        }
    }

    // Reload config after any save
    $plugin_cfg = cfg_read($cfgfile);
}

// -------------------------------------------------------------------------
// Read current state from MQTT retain (via mosquitto_sub -C 1)
// -------------------------------------------------------------------------

$tv_state = "unknown";
$state_age = "–";

$state_topic = cfg_get($plugin_cfg, "MQTT", "STATE_TOPIC", "loxberry/plugin/samsungframe/state");
$mqtt_host   = cfg_get($plugin_cfg, "MQTT", "HOST", "localhost");
$mqtt_port   = cfg_get($plugin_cfg, "MQTT", "PORT", "1883");

$auth = !empty($mqtt_cred['brokeruser']) ? " -u " . escapeshellarg($mqtt_cred['brokeruser']) . " -P " . escapeshellarg($mqtt_cred['brokerpass']) : "";
$sub_cmd = "mosquitto_sub -h " . escapeshellarg($mqtt_host)
         . " -p " . escapeshellarg($mqtt_port)
         . $auth
         . " -t " . escapeshellarg($state_topic)
         . " -C 1 -W 2 2>/dev/null";
$sub_result = trim(shell_exec($sub_cmd) ?? "");
if ($sub_result !== "") {
    $tv_state = $sub_result;
}

// -------------------------------------------------------------------------
// Page output
// -------------------------------------------------------------------------

LBWeb::lbheader("Samsung Frame TV", $pluginname, "help.html");

$tv_ip       = htmlspecialchars(cfg_get($plugin_cfg, "TV", "IP", "192.168.1.43"));
$tv_mac      = htmlspecialchars(cfg_get($plugin_cfg, "TV", "MAC", ""));
$tv_port_val = htmlspecialchars(cfg_get($plugin_cfg, "TV", "PORT", "8002"));
$tv_name_val = htmlspecialchars(cfg_get($plugin_cfg, "TV", "NAME", "LoxBerry"));
$mqtt_host_v = htmlspecialchars(cfg_get($plugin_cfg, "MQTT", "HOST", "localhost"));
$mqtt_port_v = htmlspecialchars(cfg_get($plugin_cfg, "MQTT", "PORT", "1883"));
$state_topic_v = htmlspecialchars(cfg_get($plugin_cfg, "MQTT", "STATE_TOPIC", "loxberry/plugin/samsungframe/state"));
$cmd_topic_v   = htmlspecialchars(cfg_get($plugin_cfg, "MQTT", "CMD_TOPIC",   "loxberry/plugin/samsungframe/cmd"));
$poll_interval_v = htmlspecialchars(cfg_get($plugin_cfg, "MONITOR", "POLL_INTERVAL", "30"));
$loglevel_v    = intval(cfg_get($plugin_cfg, "MONITOR", "LOGLEVEL", "3"));

$state_color = ["off" => "#e74c3c", "art" => "#9b59b6", "on" => "#2ecc71", "unknown" => "#95a5a6"];
$state_label = ["off" => "Off", "art" => "Art Mode", "on" => "On (Active)", "unknown" => "Unknown"];
$color = $state_color[$tv_state] ?? "#95a5a6";
$label = $state_label[$tv_state] ?? ucfirst($tv_state);

?>

<style>
.sf-card {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 4px rgba(0,0,0,.12);
    padding: 20px 24px;
    margin-bottom: 24px;
}
.sf-card h3 {
    margin-top: 0;
    border-bottom: 1px solid #eee;
    padding-bottom: 8px;
    font-size: 1.1em;
}
.sf-msg {
    padding: 10px 14px;
    border-radius: 4px;
    margin-bottom: 16px;
    font-weight: 500;
}
.sf-msg.success { background: #d5f5e3; color: #1e8449; }
.sf-msg.error   { background: #fadbd8; color: #922b21; }
.sf-msg.info    { background: #d6eaf8; color: #1a5276; }
.sf-state-badge {
    display: inline-block;
    padding: 6px 18px;
    border-radius: 20px;
    color: #fff;
    font-size: 1.1em;
    font-weight: 600;
    letter-spacing: .5px;
}
.sf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 24px; }
.sf-grid label { font-weight: 500; }
.sf-grid input, .sf-grid select {
    width: 100%; padding: 6px 8px; border: 1px solid #ccc;
    border-radius: 4px; font-size: .95em; box-sizing: border-box;
}
.sf-btn-row { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px; }
.sf-btn {
    padding: 7px 16px; border: none; border-radius: 4px; cursor: pointer;
    font-size: .9em; font-weight: 500;
}
.sf-btn-primary  { background: #2980b9; color: #fff; }
.sf-btn-success  { background: #27ae60; color: #fff; }
.sf-btn-warning  { background: #e67e22; color: #fff; }
.sf-btn-danger   { background: #c0392b; color: #fff; }
.sf-btn-purple   { background: #8e44ad; color: #fff; }
.sf-btn-grey     { background: #7f8c8d; color: #fff; }
.sf-btn:hover    { opacity: .87; }
pre.sf-pre {
    background: #f4f4f4; border-radius: 4px; padding: 10px 12px;
    font-size: .82em; max-height: 200px; overflow: auto; white-space: pre-wrap;
}
@media (max-width: 600px) { .sf-grid { grid-template-columns: 1fr; } }
</style>

<?php if ($message): ?>
<div class="sf-msg <?= htmlspecialchars($message_type) ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<!-- =====================================================================
     SECTION 1 — LIVE STATUS
     ===================================================================== -->
<div class="sf-card">
    <h3>Live TV Status</h3>
    <p>
        <span id="sf-state-badge" class="sf-state-badge" style="background:<?= $color ?>">
            <?= htmlspecialchars($label) ?>
        </span>
        &nbsp;
        <small style="color:#888">
            Topic: <code><?= $state_topic_v ?></code>
            &nbsp;|&nbsp;
            <a href="index.php" style="font-size:.9em">Refresh</a>
            &nbsp;|&nbsp;
            <span id="sf-autorefresh-label" style="font-size:.9em;color:#aaa">auto-refresh in <span id="sf-countdown">10</span>s</span>
        </small>
    </p>
    <form method="post" style="display:inline">
        <input type="hidden" name="action" value="restart_daemon">
        <button type="submit" class="sf-btn sf-btn-warning">Restart Daemon</button>
    </form>
</div>
<script>
(function() {
    var countdown = 10;
    var el = document.getElementById('sf-countdown');
    var interval = setInterval(function() {
        countdown--;
        if (el) el.textContent = countdown;
        if (countdown <= 0) {
            clearInterval(interval);
            window.location.href = 'index.php';
        }
    }, 1000);
})();
</script>

<!-- =====================================================================
     SECTION 2 — CONFIGURATION
     ===================================================================== -->
<div class="sf-card">
    <h3>Configuration</h3>
    <form method="post">
        <input type="hidden" name="action" value="save_config">
        <div class="sf-grid">
            <div>
                <label>TV IP Address</label>
                <input type="text" name="tv_ip" value="<?= $tv_ip ?>" placeholder="192.168.1.43" required>
            </div>
            <div>
                <label>TV MAC Address <small>(for Wake-on-LAN; auto-filled on save)</small></label>
                <input type="text" name="tv_mac" value="<?= $tv_mac ?>" placeholder="Auto-discovered via ARP">
            </div>
            <div>
                <label>WebSocket Port</label>
                <input type="number" name="tv_port" value="<?= $tv_port_val ?>" min="1" max="65535">
            </div>
            <div>
                <label>Connection Name <small>(shown on TV pairing popup)</small></label>
                <input type="text" name="tv_name" value="<?= $tv_name_val ?>">
            </div>
            <div>
                <label>MQTT Host</label>
                <input type="text" name="mqtt_host" value="<?= $mqtt_host_v ?>">
            </div>
            <div>
                <label>MQTT Port</label>
                <input type="number" name="mqtt_port" value="<?= $mqtt_port_v ?>" min="1" max="65535">
            </div>
            <div>
                <label>State Topic <small>(plugin → Loxone)</small></label>
                <input type="text" name="state_topic" value="<?= $state_topic_v ?>">
            </div>
            <div>
                <label>Command Topic <small>(Loxone → plugin)</small></label>
                <input type="text" name="cmd_topic" value="<?= $cmd_topic_v ?>">
            </div>
            <div>
                <label>Poll Interval (seconds) <small>(fallback when WebSocket drops)</small></label>
                <input type="number" name="poll_interval" value="<?= $poll_interval_v ?>" min="5" max="300">
            </div>
            <div>
                <label>Log Level</label>
                <select name="loglevel">
                    <?php foreach ([1=>"1 – Critical",2=>"2 – Error",3=>"3 – Warning",4=>"4 – Info",5=>"5 – Debug",6=>"6 – Verbose"] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $loglevel_v == $v ? "selected" : "" ?>><?= htmlspecialchars($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="sf-btn-row">
            <button type="submit" class="sf-btn sf-btn-primary">Save Configuration</button>
        </div>
    </form>
</div>

<!-- =====================================================================
     SECTION 3 — PAIRING
     ===================================================================== -->
<div class="sf-card">
    <h3>TV Pairing</h3>
    <p>
        Click <strong>Start Pairing</strong> to connect to the TV for the first time.
        A popup will appear on the TV — accept it within 30 seconds.
        The token is then saved and reused automatically by the monitor daemon.
    </p>
    <?php if (isset($pair_output)): ?>
    <pre class="sf-pre"><?= htmlspecialchars($pair_output) ?></pre>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="action" value="pair">
        <div class="sf-btn-row">
            <button type="submit" class="sf-btn sf-btn-success"
                    onclick="return confirm('Make sure the TV is powered on and showing a picture. Accept the popup on the TV. Proceed?')">
                Start Pairing
            </button>
        </div>
    </form>
</div>

<!-- =====================================================================
     SECTION 4 — TEST CONTROLS
     ===================================================================== -->
<div class="sf-card">
    <h3>Test Controls</h3>
    <p style="color:#888;font-size:.9em">
        Sends commands via MQTT to the command topic
        (<code><?= $cmd_topic_v ?></code>).
        The monitor daemon must be running for these to take effect.
    </p>

    <strong>Power</strong>
    <div class="sf-btn-row" style="margin-bottom:14px">
        <?php foreach (["power_on" => ["sf-btn-success","Power On"], "power_off" => ["sf-btn-danger","Power Off"]] as $cmd => [$cls,$lbl]): ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="test_cmd">
            <input type="hidden" name="cmd_payload" value="<?= $cmd ?>">
            <button type="submit" class="sf-btn <?= $cls ?>"><?= $lbl ?></button>
        </form>
        <?php endforeach; ?>
    </div>

    <strong>Art Mode</strong>
    <div class="sf-btn-row" style="margin-bottom:14px">
        <?php foreach (["art_on" => ["sf-btn-purple","Art Mode On"], "art_off" => ["sf-btn-grey","Art Mode Off"]] as $cmd => [$cls,$lbl]): ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="test_cmd">
            <input type="hidden" name="cmd_payload" value="<?= $cmd ?>">
            <button type="submit" class="sf-btn <?= $cls ?>"><?= $lbl ?></button>
        </form>
        <?php endforeach; ?>
    </div>

    <strong>Common Keys</strong>
    <div class="sf-btn-row">
        <?php
        $keys = [
            "key_KEY_MUTE"    => "Mute",
            "key_KEY_VOLUP"   => "Vol +",
            "key_KEY_VOLDOWN" => "Vol -",
            "key_KEY_UP"      => "▲",
            "key_KEY_DOWN"    => "▼",
            "key_KEY_LEFT"    => "◀",
            "key_KEY_RIGHT"   => "▶",
            "key_KEY_ENTER"   => "OK",
            "key_KEY_RETURN"  => "Back",
            "key_KEY_HOME"    => "Home",
        ];
        foreach ($keys as $cmd => $lbl):
        ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="test_cmd">
            <input type="hidden" name="cmd_payload" value="<?= $cmd ?>">
            <button type="submit" class="sf-btn sf-btn-grey"><?= htmlspecialchars($lbl) ?></button>
        </form>
        <?php endforeach; ?>
    </div>

    <div style="margin-top:16px">
        <strong>Custom Command</strong>
        <form method="post" style="display:flex;gap:8px;margin-top:6px">
            <input type="hidden" name="action" value="test_cmd">
            <input type="text" name="cmd_payload" placeholder="e.g. key_KEY_HDMI1"
                   style="padding:6px 8px;border:1px solid #ccc;border-radius:4px;width:260px">
            <button type="submit" class="sf-btn sf-btn-primary">Send</button>
        </form>
    </div>
</div>

<?php LBWeb::lbfooter(); ?>
