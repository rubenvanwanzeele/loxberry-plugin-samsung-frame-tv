# CLAUDE.md — Developer Context for Samsung Frame TV Plugin

This file is for AI assistants and contributors. It documents the architecture,
key decisions, and gotchas for this LoxBerry plugin.

---

## What This Plugin Does

Two-way local integration between a Samsung The Frame TV and Loxone via MQTT.
No cloud. No SmartThings. Direct local WebSocket only.

- **State reading**: Detect TV state (off / art / on) → publish to MQTT → Loxone
- **Command sending**: Receive MQTT commands from Loxone → control the TV

---

## Plugin Identity (NEVER change these after first release)

| Field         | Value              |
|---------------|--------------------|
| PLUGIN NAME   | `samsungframe`     |
| PLUGIN FOLDER | `samsungframe`     |
| PLUGIN TITLE  | `Samsung Frame TV` |
| LB_MINIMUM    | `3.0.1.3`          |
| INTERFACE     | `2.0`              |

Changing NAME or FOLDER after first release permanently breaks update detection.

---

## Repository Structure

```
loxberry-plugin-samsung-frame-tv/
├── plugin.cfg                              # Plugin identity & metadata
├── apt12                                   # Debian 12 apt deps (one package per line)
├── postinstall.sh                          # pip3 install Python deps
├── preupgrade.sh                           # Stop daemon before upgrade
├── postupgrade.sh                          # Re-install pip deps after upgrade
├── uninstall/
│   └── uninstall.sh
│
├── bin/
│   └── samsungframe/
│       ├── monitor.py                      # Main daemon
│       └── pair.py                         # One-shot pairing helper
│
├── daemon/
│   └── samsungframe.sh                     # Forks monitor.py into background
│
├── config/
│   └── samsungframe/
│       └── samsungframe.cfg                # Default config (copied on install)
│
├── webfrontend/
│   └── htmlauth/
│       └── plugins/
│           └── samsungframe/
│               ├── index.php               # Web UI
│               └── help.html               # Help page (linked from UI header)
│
├── templates/
│   └── plugins/
│       └── samsungframe/
│           └── lang/
│               ├── en.json
│               └── nl.json
│
└── icons/
    ├── icon_64.png
    └── icon_128.png
```

---

## Hardware Under Test

- **TV**: Samsung The Frame QE65LS03BAUXXN (2022, 65")
- **TV IP**: `192.168.1.43` (wired, configured via plugin web UI)
- **TV WiFi MAC**: `04:b9:e3:9e:70:1e`
- **LoxBerry**: v3.0.1.3, Raspberry Pi
- **Loxone**: Miniserver Gen 2

---

## Technology Stack

| Layer          | Choice                   |
|----------------|--------------------------|
| Backend daemon | Python 3                 |
| TV comms       | `samsungtvws[encrypted]` |
| Wake-on-LAN    | `wakeonlan`              |
| MQTT client    | `paho-mqtt`              |
| Web UI         | PHP (LoxBerry SDK)       |
| Config format  | INI                      |

---

## Key Technical Facts

### TV Local API

- **REST endpoint** (no auth, always available including in standby):
  `GET http://<TV_IP>:8001/api/v2/`
  Returns `device.PowerState`: `"standby"` or `"on"` (both art mode and active use return `"on"`)

- **WebSocket** (token auth required, port 8002 TLS):
  Used for art mode detection, art mode toggle, remote key sending, event subscription.
  First connection triggers pairing popup on TV → token saved to `token.txt` → reused forever.

- **Three distinct TV states**:
  - `off` — REST returns `PowerState: standby`
  - `art` — REST returns `PowerState: on` AND `art().get_artmode()` returns `"on"`
  - `on`  — REST returns `PowerState: on` AND `art().get_artmode()` returns `"off"`

### MQTT

- LoxBerry 3 has Mosquitto built in — always available on `localhost:1883`
- State topic published with `retain=True` so Loxone always has the latest state on reconnect
- Last-will set to `"off"` so Loxone recovers correctly if the daemon crashes

---

## Daemon Architecture (`monitor.py`)

Single Python process, two concurrent concerns:

1. **MQTT command subscriber** — `paho-mqtt` with `loop_start()` (background thread).
   Subscribes to command topic, handles commands in `on_mqtt_message` callback.

2. **State monitor** — main thread runs a poll loop:
   - Tries `tv.start_listening(callback)` for event-driven art mode updates (preferred).
   - Always polls via REST every `POLL_INTERVAL` seconds as a safety net (catches power-off
     which doesn't generate a D2D event).
   - When TV powers off, WebSocket listener dies — flag is reset so it reconnects next time.

### State determination

```python
# Step 1: REST (fast, no auth, works in standby)
GET http://<TV_IP>:8001/api/v2/ → device.PowerState
# "standby" → state = "off", done

# Step 2: WebSocket art mode check (only if REST says "on")
art().get_artmode() → "on" → state = "art"
                    → "off" → state = "on"
# Exception → state = "on" (TV is on, art mode undetermined)
```

### Key samsungtvws calls

```python
tv.art().get_artmode()        # "on" or "off"
tv.art().set_artmode("on/off")
tv.hold_key("KEY_POWER", 3)   # power off
tv.send_key("KEY_MUTE")       # remote keys
tv.start_listening(callback)  # event subscription
```

**Important**: `tv.art()` creates a new instance each call — `monitor.py` caches it in `_art`.

---

## LoxBerry Python Path Pattern

Python has no native LoxBerry SDK. Paths are derived via Perl one-liners or
passed as CLI arguments from the daemon shell script:

```python
def lb_path(var: str) -> str:
    return os.popen(
        f"perl -e 'use LoxBerry::System; print ${var}; exit;'"
    ).read().strip()
```

`monitor.py` and `pair.py` receive `--config` and `--logfile` from `daemon/samsungframe.sh`,
which has `$LBPCONFIG`, `$LBPBIN`, `$LBPLOG` in its environment.

---

## Web UI (`index.php`)

Uses the LoxBerry PHP SDK:
```php
require_once "/opt/loxberry/libs/phplib/loxberry_system.php";
require_once "/opt/loxberry/libs/phplib/loxberry_web.php";
LBWeb::lbheader("Samsung Frame TV", "samsungframe", "help.html");
```

### Sections
1. **Live status** — reads retained MQTT state via `mosquitto_sub -C 1 -W 2`
2. **Configuration** — saves to `samsungframe.cfg`; auto-discovers MAC via `arp -n` on save
3. **Pairing** — calls `pair.py` via `shell_exec`, displays output
4. **Test controls** — publishes commands via `mosquitto_pub`

---

## MQTT Topic Design

### State (plugin → Loxone)
| Topic | Values | Notes |
|---|---|---|
| `loxberry/plugin/samsungframe/state` | `off` / `art` / `on` | retain=true |

### Commands (Loxone → plugin)
| Topic | Payload | Action |
|---|---|---|
| `loxberry/plugin/samsungframe/cmd` | `power_on` | WS key or Wake-on-LAN fallback |
| `loxberry/plugin/samsungframe/cmd` | `power_off` | Hold KEY_POWER 3s |
| `loxberry/plugin/samsungframe/cmd` | `art_on` | `art().set_artmode("on")` |
| `loxberry/plugin/samsungframe/cmd` | `art_off` | `art().set_artmode("off")` |
| `loxberry/plugin/samsungframe/cmd` | `key_XXXX` | Forward raw key, e.g. `key_KEY_MUTE` |

---

## Known Gotchas

- **Samsung keeps network alive in standby** — REST API responds even when TV appears off.
  Always check `PowerState` first before attempting WebSocket.
- **Art mode API history** — Removed in Tizen 6.5 (2022 TVs), re-added spring 2024 firmware.
  `art.supported()` checks at runtime. If unsupported, art mode detection won't work.
- **WebSocket drops after ~60s idle** — Handle `ConnectionResetError` /
  `WebSocketConnectionClosedException` and recreate the `SamsungTVWS` instance.
  `monitor.py` does this via `reset_tv()`.
- **Power-off has no D2D event** — The WebSocket listener won't fire when the TV powers off.
  The poll loop catches this via the REST check.
- **Token file** lives at `$LBPCONFIG/samsungframe/token.txt` — excluded from git in `.gitignore`.
- **`tv.art()` returns a new object each call** — always use the cached `_art` instance.
- **Daemon must self-background immediately** — `daemon/samsungframe.sh` ends with `&`.
  Blocking here stalls all other LoxBerry plugin daemons.

---

## Reference Links

- LoxBerry plugin basics: https://wiki.loxberry.de/en/entwickler/grundlagen_zur_erstellung_eines_plugins
- Python in LoxBerry: https://wiki.loxberry.de/entwickler/python_develop_plugins_with_python
- LoxBerry MQTT for devs: https://wiki.loxberry.de/entwickler/mqtt/start
- Sample plugin (PHP): https://github.com/christianTF/LoxBerry-Plugin-SamplePlugin-V2-PHP
- samsungtvws library: https://github.com/xchwarze/samsung-tv-ws-api
