# Samsung Frame TV – LoxBerry Plugin

A [LoxBerry 3](https://www.loxberry.de) plugin for two-way **local** integration between a
Samsung The Frame TV and [Loxone](https://www.loxone.com) via MQTT.

No cloud. No SmartThings. Direct local WebSocket only.

---

## Features

- Detects TV state — **off / art mode / on** — and publishes to MQTT
- Sends commands from Loxone to the TV: power, art mode, any remote key
- Wake-on-LAN support for powering on from standby
- Event-driven state updates via WebSocket (polling fallback when WebSocket drops)
- Web UI for configuration, pairing, live status and test controls

---

## Requirements

| Requirement | Version |
|---|---|
| LoxBerry | 3.0.1.3 or newer |
| Samsung The Frame TV | Any model with developer mode enabled |
| Loxone Miniserver | Gen 2 (native MQTT) or any with MQTT support |

The TV must have **developer mode** enabled:
`Settings → Support → Device Care → Developer Mode`

---

## Installation

### From GitHub (recommended)

1. In LoxBerry: **System → Plugin Manager → Install from URL**
2. Enter the URL of this repository's ZIP:
   ```
   https://github.com/rubenvanwanzeele/loxberry-plugin-samsung-frame-tv/archive/refs/heads/main.zip
   ```
3. Follow the on-screen instructions.

### From a local ZIP

Download or build the ZIP and upload via **System → Plugin Manager → Install from file**.

---

## Setup

1. Open the plugin's web UI in LoxBerry.
2. Enter the **TV IP address** and click **Save Configuration**.
   The MAC address for Wake-on-LAN is auto-discovered via ARP.
3. With the TV powered on and showing a picture, click **Start Pairing**.
   Accept the popup that appears on the TV within 30 seconds.
4. The daemon starts automatically at boot and begins publishing state to MQTT.

---

## MQTT Topics

### State (plugin → Loxone)

| Topic | Values | Notes |
|---|---|---|
| `loxberry/plugin/samsungframe/state` | `off` / `art` / `on` | retained |

### Commands (Loxone → plugin)

| Topic | Payload | Action |
|---|---|---|
| `loxberry/plugin/samsungframe/cmd` | `power_on` | Wake TV from standby |
| `loxberry/plugin/samsungframe/cmd` | `power_off` | Power off TV |
| `loxberry/plugin/samsungframe/cmd` | `art_on` | Enable Art Mode |
| `loxberry/plugin/samsungframe/cmd` | `art_off` | Disable Art Mode |
| `loxberry/plugin/samsungframe/cmd` | `key_KEY_MUTE` | Send MUTE key |
| `loxberry/plugin/samsungframe/cmd` | `key_XXXX` | Send any Samsung remote key |

Topics are configurable in the web UI.

---

## Loxone Config

- **Virtual Input** (text) subscribed to the state topic → use in your programme to react to TV state.
- **Virtual Output** publishing to the command topic → trigger commands from Loxone blocks.

---

## TV States Explained

| State | Meaning |
|---|---|
| `off` | TV is in standby (REST API returns `PowerState: standby`) |
| `art` | TV is on and Art Mode is active |
| `on` | TV is on and showing normal content |

---

## Hardware Tested On

- **TV**: Samsung The Frame QE65LS03BAUXXN (2022, 65")
- **LoxBerry**: v3.0.1.3 on Raspberry Pi
- **Loxone**: Miniserver Gen 2

---

## Troubleshooting

**Pairing fails / no popup on TV**
Developer mode must be enabled on the TV. Go to `Settings → Support → Device Care → Developer Mode`.

**Art mode not detected**
The art mode WebSocket API was removed in Tizen 6.5 (2022 TVs) and re-added in a spring 2024 firmware update. Make sure the TV firmware is up to date.

**State stuck at "unknown" after install**
The daemon may not be running. Check the log:
`/opt/loxberry/log/plugins/samsungframe/monitor.log`

**Wake-on-LAN not working**
Verify the MAC address in the configuration is correct and that the TV is connected via wired Ethernet.

---

## License

MIT — see [LICENSE](LICENSE).
