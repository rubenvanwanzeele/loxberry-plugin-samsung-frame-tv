#!/usr/bin/env python3
"""
monitor.py — Main daemon for Samsung Frame TV LoxBerry plugin.

Responsibilities:
  1. Subscribe to MQTT command topic and forward commands to the TV.
  2. Monitor TV state (off / art / on) and publish to MQTT state topic.

State detection:
  - REST endpoint (port 8001) tells us if the TV is on or in standby.
  - WebSocket art mode API (port 8002) tells us if art mode is active.
  - Event-driven via start_listening() preferred; polling as fallback.

Usage:
    python3 monitor.py --config /path/to/samsungframe.cfg --logfile /path/to/monitor.log
"""

import argparse
import configparser
import json
import logging
import os
import signal
import sys
import threading
import time
from logging.handlers import RotatingFileHandler

import requests

try:
    import paho.mqtt.client as mqtt
except ImportError:
    print("ERROR: paho-mqtt not installed. Run: pip3 install paho-mqtt")
    sys.exit(1)

try:
    import wakeonlan
except ImportError:
    print("ERROR: wakeonlan not installed. Run: pip3 install wakeonlan")
    sys.exit(1)

try:
    from samsungtvws import SamsungTVWS
    from samsungtvws.event import D2D_SERVICE_MESSAGE_EVENT
except ImportError:
    print("ERROR: samsungtvws not installed. Run: pip3 install 'samsungtvws[encrypted]'")
    sys.exit(1)


# ---------------------------------------------------------------------------
# Globals (set up after config is loaded)
# ---------------------------------------------------------------------------

log = logging.getLogger("samsungframe")

_config: configparser.ConfigParser = None
_mqtt_client: mqtt.Client = None
_tv: SamsungTVWS = None
_art = None                   # cached art() instance
_tv_lock = threading.Lock()   # serialize TV access from MQTT thread + main loop

_current_state: str = ""      # last published state: "off" / "art" / "on"
_shutdown = threading.Event()


# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------

def setup_logging(logfile: str, loglevel: int) -> None:
    level_map = {1: logging.CRITICAL, 2: logging.ERROR, 3: logging.WARNING,
                 4: logging.INFO, 5: logging.DEBUG, 6: logging.DEBUG}
    level = level_map.get(loglevel, logging.INFO)

    fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s",
                             datefmt="%Y-%m-%d %H:%M:%S")

    # Rotating file handler (5 MB, keep 3 backups)
    os.makedirs(os.path.dirname(logfile), exist_ok=True)
    fh = RotatingFileHandler(logfile, maxBytes=5 * 1024 * 1024, backupCount=3)
    fh.setFormatter(fmt)

    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(fmt)

    log.setLevel(level)
    log.addHandler(fh)
    log.addHandler(sh)


# ---------------------------------------------------------------------------
# TV helpers
# ---------------------------------------------------------------------------

def get_tv() -> SamsungTVWS:
    """Return (creating if needed) the shared SamsungTVWS instance."""
    global _tv, _art
    if _tv is None:
        tv_ip = _config.get("TV", "IP")
        tv_port = _config.getint("TV", "PORT", fallback=8002)
        tv_name = _config.get("TV", "NAME", fallback="LoxBerry")
        config_dir = os.path.dirname(
            _config.get("_meta", "config_path", fallback="/tmp/samsungframe.cfg")
        )
        token_file = os.path.join(config_dir, "token.txt")
        _tv = SamsungTVWS(
            host=tv_ip,
            port=tv_port,
            token_file=token_file,
            timeout=5,
            name=tv_name,
        )
        _art = None  # reset cached art instance when TV is recreated
    return _tv


def get_art():
    """Return (creating if needed) the cached art() helper."""
    global _art
    tv = get_tv()
    if _art is None:
        _art = tv.art()
    return _art


def reset_tv() -> None:
    """Discard TV/art instances so they are recreated on next use."""
    global _tv, _art
    _tv = None
    _art = None


def is_tv_on_rest() -> bool | None:
    """
    Check TV power state via REST (no auth, works in standby).
    Returns True if PowerState == "on", False if "standby", None on error.
    """
    tv_ip = _config.get("TV", "IP")
    try:
        r = requests.get(f"http://{tv_ip}:8001/api/v2/", timeout=3)
        power = r.json().get("device", {}).get("PowerState", "standby")
        return power != "standby"
    except Exception as e:
        log.debug(f"REST check failed: {e}")
        return None


def get_tv_state() -> str:
    """
    Determine full TV state: "off" / "art" / "on".
    Uses REST first, then WebSocket for art mode.
    """
    powered_on = is_tv_on_rest()

    if powered_on is None or not powered_on:
        return "off"

    # TV is on — check art mode via WebSocket
    with _tv_lock:
        try:
            art = get_art()
            artmode = art.get_artmode()
            log.debug(f"Art mode query result: {artmode!r}")
            return "art" if artmode == "on" else "on"
        except Exception as e:
            log.warning(f"Art mode check failed (WebSocket): {e} — assuming 'on'")
            reset_tv()
            return "on"


# ---------------------------------------------------------------------------
# State publishing
# ---------------------------------------------------------------------------

def publish_state(state: str, force: bool = False) -> None:
    """Publish state to MQTT if it changed (or force=True)."""
    global _current_state
    if state == _current_state and not force:
        return
    topic = _config.get("MQTT", "STATE_TOPIC",
                         fallback="loxberry/plugin/samsungframe/state")
    try:
        _mqtt_client.publish(topic, state, qos=1, retain=True)
        log.info(f"State published: {state!r} → {topic}")
        _current_state = state
    except Exception as e:
        log.error(f"MQTT publish failed: {e}")


# ---------------------------------------------------------------------------
# Event listener (preferred over polling)
# ---------------------------------------------------------------------------

def on_tv_event(event) -> None:
    """
    Callback fired by samsungtvws background listener thread.
    We only care about D2D_SERVICE_MESSAGE_EVENT which carries art mode changes.
    """
    try:
        if event.get("event") != D2D_SERVICE_MESSAGE_EVENT:
            return
        data = json.loads(event.get("data", "{}"))
        event_type = data.get("event", "")
        log.debug(f"D2D event: {event_type} — {data}")

        if event_type in ("art_mode_changed", "artmode_status"):
            value = data.get("value", "")
            if value == "on":
                publish_state("art")
            elif value == "off":
                # TV is on but art mode just turned off
                publish_state("on")
    except Exception as e:
        log.warning(f"Error processing TV event: {e}")


def start_event_listener() -> bool:
    """
    Attempt to start the WebSocket event listener on the TV.
    Returns True on success, False if not supported or failed.
    """
    with _tv_lock:
        try:
            tv = get_tv()
            tv.start_listening(callback=on_tv_event)
            log.info("WebSocket event listener started.")
            return True
        except Exception as e:
            log.warning(f"Could not start event listener: {e} — will use polling only")
            reset_tv()
            return False


# ---------------------------------------------------------------------------
# MQTT command handler
# ---------------------------------------------------------------------------

def on_mqtt_connect(client, userdata, flags, rc) -> None:
    if rc == 0:
        cmd_topic = _config.get("MQTT", "CMD_TOPIC",
                                 fallback="loxberry/plugin/samsungframe/cmd")
        client.subscribe(cmd_topic, qos=1)
        log.info(f"MQTT connected, subscribed to {cmd_topic}")
        # Re-publish current state with retain so Loxone sees it on reconnect
        if _current_state:
            publish_state(_current_state, force=True)
    else:
        log.error(f"MQTT connection failed, rc={rc}")


def on_mqtt_disconnect(client, userdata, rc) -> None:
    if rc != 0:
        log.warning(f"MQTT disconnected unexpectedly (rc={rc}), will auto-reconnect")


def on_mqtt_message(client, userdata, msg) -> None:
    payload = msg.payload.decode("utf-8", errors="ignore").strip()
    log.info(f"MQTT command received: {payload!r}")
    handle_command(payload)


def handle_command(cmd: str) -> None:
    tv_ip = _config.get("TV", "IP")
    tv_mac = _config.get("TV", "MAC", fallback="")

    with _tv_lock:
        try:
            if cmd == "power_on":
                # Try WebSocket first; fall back to WOL
                try:
                    tv = get_tv()
                    tv.send_key("KEY_POWER")
                    log.info("Sent KEY_POWER via WebSocket (power on)")
                except Exception:
                    reset_tv()
                    if tv_mac:
                        wakeonlan.send_magic_packet(tv_mac)
                        log.info(f"Sent Wake-on-LAN to {tv_mac}")
                    else:
                        log.warning("power_on: WebSocket failed and no MAC configured for WOL")

            elif cmd == "power_off":
                tv = get_tv()
                tv.hold_key("KEY_POWER", 3)
                log.info("Sent KEY_POWER hold 3s (power off)")

            elif cmd == "art_on":
                art = get_art()
                art.set_artmode("on")
                log.info("Art mode enabled")
                publish_state("art")

            elif cmd == "art_off":
                art = get_art()
                art.set_artmode("off")
                log.info("Art mode disabled")
                publish_state("on")

            elif cmd.startswith("key_"):
                key = cmd[4:].upper()  # "key_KEY_MUTE" → "KEY_MUTE"
                tv = get_tv()
                tv.send_key(key)
                log.info(f"Sent key: {key}")

            else:
                log.warning(f"Unknown command: {cmd!r}")

        except Exception as e:
            log.warning(f"Command '{cmd}' failed on first attempt: {type(e).__name__}: {e} — retrying once")
            reset_tv()
            time.sleep(3)
            try:
                if cmd == "art_on":
                    get_art().set_artmode("on")
                    log.info("Art mode enabled (retry)")
                    publish_state("art")
                elif cmd == "art_off":
                    get_art().set_artmode("off")
                    log.info("Art mode disabled (retry)")
                    publish_state("on")
                elif cmd == "power_off":
                    get_tv().hold_key("KEY_POWER", 3)
                    log.info("Sent KEY_POWER hold 3s (power off, retry)")
                elif cmd.startswith("key_"):
                    key = cmd[4:].upper()
                    get_tv().send_key(key)
                    log.info(f"Sent key: {key} (retry)")
                else:
                    log.error(f"Command '{cmd}' failed: {type(e).__name__}: {e}")
            except Exception as e2:
                log.error(f"Command '{cmd}' failed after retry: {type(e2).__name__}: {e2}")
                reset_tv()


# ---------------------------------------------------------------------------
# Main loop
# ---------------------------------------------------------------------------

def get_mqtt_credentials() -> tuple[str, str]:
    """Read MQTT credentials from LoxBerry's general.json (Mqtt section)."""
    try:
        with open("/opt/loxberry/config/system/general.json") as f:
            data = json.load(f)
        mqtt = data.get("Mqtt", {})
        return mqtt.get("Brokeruser", ""), mqtt.get("Brokerpass", "")
    except Exception as e:
        log.debug(f"Could not read general.json: {e} — connecting without credentials")
        return "", ""


def setup_mqtt() -> mqtt.Client:
    client = mqtt.Client(client_id="samsungframe-monitor", clean_session=True)
    client.on_connect = on_mqtt_connect
    client.on_disconnect = on_mqtt_disconnect
    client.on_message = on_mqtt_message

    user, password = get_mqtt_credentials()
    if user:
        client.username_pw_set(user, password)
        log.info(f"MQTT using credentials for user '{user}'")

    host = _config.get("MQTT", "HOST", fallback="localhost")
    port = _config.getint("MQTT", "PORT", fallback=1883)

    # Set last-will so Loxone sees "off" if the daemon crashes
    state_topic = _config.get("MQTT", "STATE_TOPIC",
                               fallback="loxberry/plugin/samsungframe/state")
    client.will_set(state_topic, "off", qos=1, retain=True)

    client.connect_async(host, port, keepalive=60)
    client.loop_start()
    log.info(f"MQTT connecting to {host}:{port}")
    return client


def run_poll_loop() -> None:
    """
    Main polling loop.  Runs state checks on a configurable interval.
    Even when event listening is active, we poll periodically as a safety net
    (e.g. to catch power-off which doesn't generate a D2D event).
    """
    poll_interval = _config.getint("MONITOR", "POLL_INTERVAL", fallback=30)
    event_listening = False
    reconnect_delay = 5

    log.info(f"Starting poll loop (interval={poll_interval}s)")

    # Try to start the WebSocket event listener once the TV is reachable
    while not _shutdown.is_set():
        # Reload config on every cycle so web UI changes are picked up without restart
        config_path = _config.get("_meta", "config_path")
        _config.read(config_path)
        poll_interval = _config.getint("MONITOR", "POLL_INTERVAL", fallback=30)

        if not event_listening and is_tv_on_rest():
            event_listening = start_event_listener()

        state = get_tv_state()
        publish_state(state)

        # If TV went off, the WebSocket listener is now dead — reset for next time
        if state == "off" and event_listening:
            log.debug("TV is off — resetting event listener flag")
            reset_tv()
            event_listening = False

        _shutdown.wait(timeout=poll_interval)

    log.info("Poll loop exiting.")


def handle_signal(signum, frame) -> None:
    log.info(f"Signal {signum} received, shutting down...")
    _shutdown.set()


def main() -> None:
    global _config, _mqtt_client

    parser = argparse.ArgumentParser(description="Samsung Frame TV monitor daemon")
    parser.add_argument("--config", required=True, help="Path to samsungframe.cfg")
    parser.add_argument("--logfile", required=True, help="Path to log file")
    args = parser.parse_args()

    _config = configparser.ConfigParser()
    _config.read(args.config)
    # Stash the config path so get_tv() can locate the token file
    if not _config.has_section("_meta"):
        _config.add_section("_meta")
    _config.set("_meta", "config_path", args.config)

    loglevel = _config.getint("MONITOR", "LOGLEVEL", fallback=3)
    setup_logging(args.logfile, loglevel)

    log.info("Samsung Frame TV monitor starting")
    log.info(f"Config: {args.config}")
    log.info(f"TV: {_config.get('TV', 'IP')}:{_config.get('TV', 'PORT', fallback='8002')}")

    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)

    _mqtt_client = setup_mqtt()

    # Give MQTT a moment to connect before first state publish
    time.sleep(2)

    try:
        run_poll_loop()
    finally:
        log.info("Disconnecting MQTT...")
        _mqtt_client.loop_stop()
        _mqtt_client.disconnect()
        log.info("Samsung Frame TV monitor stopped.")


if __name__ == "__main__":
    main()
