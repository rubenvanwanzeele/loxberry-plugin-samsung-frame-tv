# Samsung Frame TV – LoxBerry Plugin

A [LoxBerry 3](https://www.loxberry.de) plugin for two-way **local** integration between a
Samsung The Frame TV and [Loxone](https://www.loxone.com) via MQTT.

No cloud. No SmartThings. Direct local control only.

---

## Features

- Detects TV state — **off / art mode / on** — and publishes to MQTT every 5 seconds
- Sends commands from Loxone to the TV: power, art mode, any remote key
- Wake-on-LAN support for powering on from standby
- Web UI for configuration, pairing, live status and test controls
- Configuration and pairing token are preserved across plugin upgrades

---

## Requirements

| Requirement | Details |
|---|---|
| LoxBerry | 3.0.1.3 or newer |
| Samsung The Frame TV | Any model — developer mode must be enabled |
| Loxone Miniserver | Any model with MQTT support |

**Enable developer mode on the TV before pairing:**
`Settings → Support → Device Care → Developer Mode`

---

## Installation

In LoxBerry: **System → Plugin Manager → Install from URL**

```
https://github.com/rubenvanwanzeele/loxberry-plugin-samsung-frame-tv/archive/refs/tags/v1.0.0.zip
```

Python dependencies (`samsungtvws`, `paho-mqtt`, `wakeonlan`) are installed automatically.

---

## Setup

### 1. Configure

Open the plugin page in LoxBerry. Enter the **TV IP address** and click **Save Configuration**.
The MAC address for Wake-on-LAN is auto-discovered via ARP on save.

### 2. Pair

With the TV **powered on and showing a picture** (not in standby or art mode), click **Start Pairing**.
Accept the popup that appears on the TV within 30 seconds.
The token is saved and reused automatically — you only need to pair once.

> If you see "Not paired" after an upgrade, run pairing again. This is only needed if the token file was lost.

### 3. Enable Wake-on-LAN (optional)

Required for the `power_on` command to work when the TV is fully off:

On the TV: **Settings → General → Network → Expert Settings → Power On with Mobile**
(may also be labelled "Remote Device Management" depending on firmware)

---

## MQTT Topics

### State (plugin → Loxone)

| Topic | Values | Notes |
|---|---|---|
| `loxberry/plugin/samsungframe/state` | `off` / `art` / `on` | retained, updated every 5s |

| Value | Meaning |
|---|---|
| `off` | TV is in standby |
| `art` | TV is on, Art Mode active (showing artwork) |
| `on` | TV is on, showing normal content |

### Commands (Loxone → plugin)

Publish to topic: `loxberry/plugin/samsungframe/cmd`

| Payload | Action |
|---|---|
| `power_on` | Wake TV from standby (WebSocket or Wake-on-LAN) |
| `power_off` | Power off TV |
| `art_on` | Enable Art Mode |
| `art_off` | Disable Art Mode |
| `key_KEY_MUTE` | Mute / unmute |
| `key_KEY_VOLUP` | Volume up |
| `key_KEY_VOLDOWN` | Volume down |
| `key_KEY_HOME` | Home screen |
| `key_KEY_RETURN` | Back |
| `key_XXXX` | Any Samsung remote key, e.g. `key_KEY_HDMI1`, `key_KEY_NETFLIX` |

Topics are configurable in the plugin web UI.

---

## Loxone Integration

### Receive TV state

1. In Loxone Config add a **Virtual Input** → type **Text** → **MQTT**
2. Topic: `loxberry/plugin/samsungframe/state`, enable **Retain**
3. Add a **Formula** block to convert to a number:
   - `IF(AQ == "on", 1, 0)` — 1 when TV is actively playing
   - `IF(AQ == "art", 1, 0)` — 1 when in art mode
   - `IF(AQ == "off", 1, 0)` — 1 when TV is off

### Send commands

1. Add a **Virtual Output** → type **MQTT**
2. Topic: `loxberry/plugin/samsungframe/cmd`
3. Payload on: the command to send, e.g. `power_off`

### Example automations

- TV turns on → pause music (trigger on state = `on`)
- Leaving the house → publish `power_off`
- No presence in TV area for 1 hour → publish `power_off` (Staircase block on presence sensor)

---

## Upgrades

Plugin upgrades preserve your configuration (IP, topics, poll interval) and pairing token automatically. You do not need to re-enter settings or re-pair after an upgrade.

---

## Troubleshooting

**State stuck at "unknown" after install**
The daemon may not be running or the TV is unreachable. Check:
```bash
systemctl status samsungframe.service
tail -f /opt/loxberry/log/plugins/samsungframe/monitor.log
```

**Pairing fails / no popup on TV**
Developer mode must be enabled: `Settings → Support → Device Care → Developer Mode`.
The TV must be powered on and showing a picture (not in standby).

**Art mode not detected**
The art mode API was removed in some 2022–2023 firmware versions and re-added in early 2024.
Make sure the TV firmware is up to date.

**`power_on` doesn't work**
Enable Wake-on-LAN in TV settings and confirm the MAC address is shown in the plugin config.

**Commands fail with "socket closed" in logs**
This is handled automatically — the daemon resets the connection and retries. If it persists, check that no other app is holding a WebSocket connection to the TV.

---

## Tested on

- **TV**: Samsung The Frame QE65LS03BAUXXN (2022, 65")
- **LoxBerry**: v3.0.1.3, Raspberry Pi
- **Loxone**: Miniserver Gen 2

---

## License

MIT
