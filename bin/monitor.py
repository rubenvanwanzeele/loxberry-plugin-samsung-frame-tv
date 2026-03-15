#!/usr/bin/env python3
"""
monitor.py — Main daemon for Samsung Frame TV LoxBerry plugin.

Supports one or more TVs while staying backward compatible with v1.0.0:
  - Primary TV still uses the legacy MQTT topics from [MQTT].
  - Every configured TV also gets its own device topic suffix:
      <STATE_TOPIC>/<device_id>
      <CMD_TOPIC>/<device_id>
  - Broadcast commands can be sent to:
      <CMD_TOPIC>/all
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
from dataclasses import dataclass, field
from logging.handlers import RotatingFileHandler
from typing import Dict, List, Optional, Tuple

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
except ImportError:
    print("ERROR: samsungtvws not installed. Run: pip3 install 'samsungtvws[encrypted]'")
    sys.exit(1)


LEGACY_STATE_TOPIC = "loxberry/plugin/samsungframe/state"
LEGACY_CMD_TOPIC = "loxberry/plugin/samsungframe/cmd"

log = logging.getLogger("samsungframe")

_mqtt_client = None
_availability_clients: Dict[str, mqtt.Client] = {}
_runtime_config = None
_devices: Dict[str, "DeviceRuntime"] = {}
_topic_targets: Dict[str, List[str]] = {}
_broadcast_cmd_topic = ""
_shutdown = threading.Event()


@dataclass
class DeviceConfig:
    device_id: str
    label: str
    ip: str
    mac: str
    port: int
    name: str
    enabled: bool = True


@dataclass
class RuntimeConfig:
    config_path: str
    config_dir: str
    legacy_state_topic: str
    legacy_cmd_topic: str
    primary_device_id: str
    poll_interval: int
    loglevel: int
    devices: List[DeviceConfig]


@dataclass
class DeviceRuntime:
    cfg: DeviceConfig
    token_file: str
    state_topic: str
    cmd_topic: str
    availability_topic: str
    is_primary: bool = False
    current_state: str = ""
    tv: Optional[SamsungTVWS] = None
    art = None
    lock: threading.Lock = field(default_factory=threading.Lock)

    def prefix(self, message: str) -> str:
        reference = self.cfg.label or self.cfg.device_id
        return f"[{reference}] {message}"

    def get_tv(self) -> SamsungTVWS:
        if self.tv is None:
            self.tv = SamsungTVWS(
                host=self.cfg.ip,
                port=self.cfg.port,
                token_file=self.token_file,
                timeout=5,
                name=self.cfg.name,
            )
            self.art = None
        return self.tv

    def get_art(self):
        if self.art is None:
            self.art = self.get_tv().art()
        return self.art

    def reset_tv(self) -> None:
        self.tv = None
        self.art = None

    def is_tv_on_rest(self) -> Optional[bool]:
        if not self.cfg.ip:
            log.debug(self.prefix("REST check skipped: no IP configured"))
            return None
        try:
            response = requests.get(f"http://{self.cfg.ip}:8001/api/v2/", timeout=3)
            power = response.json().get("device", {}).get("PowerState", "standby")
            return power != "standby"
        except Exception as exc:
            log.debug(self.prefix(f"REST check failed: {exc}"))
            return None

    def get_state(self) -> str:
        powered_on = self.is_tv_on_rest()
        if powered_on is None or not powered_on:
            return "off"

        with self.lock:
            try:
                artmode = self.get_art().get_artmode()
                log.debug(self.prefix(f"Art mode query result: {artmode!r}"))
                return "art" if artmode == "on" else "on"
            except Exception as exc:
                log.warning(self.prefix(f"Art mode check failed ({type(exc).__name__}: {exc}) — assuming 'on'"))
                self.reset_tv()
                return "on"

    def publish_state(self, state: str, force: bool = False) -> None:
        if state == self.current_state and not force:
            return

        publish_targets = [self.state_topic]
        if self.is_primary:
            publish_targets.append(_runtime_config.legacy_state_topic)

        for topic in publish_targets:
            try:
                _mqtt_client.publish(topic, state, qos=1, retain=True)
                mirror_note = " (legacy mirror)" if self.is_primary and topic == _runtime_config.legacy_state_topic else ""
                log.info(self.prefix(f"State published: {state!r} → {topic}{mirror_note}"))
            except Exception as exc:
                log.error(self.prefix(f"MQTT publish failed for {topic}: {exc}"))

        self.current_state = state

    def handle_command(self, cmd: str) -> None:
        with self.lock:
            try:
                self._handle_command_once(cmd)
            except Exception as exc:
                log.warning(self.prefix(
                    f"Command {cmd!r} failed on first attempt: {type(exc).__name__}: {exc} — retrying once"
                ))
                self.reset_tv()
                time.sleep(1)
                try:
                    self._handle_command_once(cmd, retry=True)
                except Exception as retry_exc:
                    log.error(self.prefix(
                        f"Command {cmd!r} failed after retry: {type(retry_exc).__name__}: {retry_exc}"
                    ))
                    self.reset_tv()

    def _handle_command_once(self, cmd: str, retry: bool = False) -> None:
        suffix = " (retry)" if retry else ""

        if cmd == "power_on":
            try:
                self.get_tv().send_key("KEY_POWER")
                log.info(self.prefix(f"Sent KEY_POWER via WebSocket (power on){suffix}"))
            except Exception:
                self.reset_tv()
                if self.cfg.mac:
                    wakeonlan.send_magic_packet(self.cfg.mac)
                    log.info(self.prefix(f"Sent Wake-on-LAN to {self.cfg.mac}{suffix}"))
                else:
                    log.warning(self.prefix(f"power_on: WebSocket failed and no MAC configured for WOL{suffix}"))

        elif cmd == "power_off":
            self.get_tv().hold_key("KEY_POWER", 3)
            log.info(self.prefix(f"Sent KEY_POWER hold 3s (power off){suffix}"))

        elif cmd == "art_on":
            self.get_art().set_artmode("on")
            log.info(self.prefix(f"Art mode enabled{suffix}"))
            self.publish_state("art", force=True)

        elif cmd == "art_off":
            self.get_art().set_artmode("off")
            log.info(self.prefix(f"Art mode disabled{suffix}"))
            self.publish_state("on", force=True)

        elif cmd.startswith("key_"):
            key = cmd[4:].upper()
            if not key.startswith("KEY_"):
                key = "KEY_" + key
            self.get_tv().send_key(key)
            log.info(self.prefix(f"Sent key: {key}{suffix}"))

        else:
            log.warning(self.prefix(f"Unknown command: {cmd!r}"))


def setup_logging(logfile: str, loglevel: int) -> None:
    level_map = {
        1: logging.CRITICAL,
        2: logging.ERROR,
        3: logging.WARNING,
        4: logging.INFO,
        5: logging.DEBUG,
        6: logging.DEBUG,
    }
    level = level_map.get(loglevel, logging.INFO)
    formatter = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")

    os.makedirs(os.path.dirname(logfile), exist_ok=True)

    file_handler = RotatingFileHandler(logfile, maxBytes=5 * 1024 * 1024, backupCount=3)
    file_handler.setFormatter(formatter)

    stream_handler = logging.StreamHandler(sys.stdout)
    stream_handler.setFormatter(formatter)

    log.handlers.clear()
    log.setLevel(level)
    log.addHandler(file_handler)
    log.addHandler(stream_handler)


def sanitize_device_id(value: str) -> str:
    cleaned = "".join(ch.lower() if ch.isalnum() else "_" for ch in value.strip())
    while "__" in cleaned:
        cleaned = cleaned.replace("__", "_")
    cleaned = cleaned.strip("_")
    cleaned = cleaned or "default"
    if cleaned == "all":
        cleaned = "tv_all"
    return cleaned


def topic_for_device(base_topic: str, device_id: str) -> str:
    return f"{base_topic.rstrip('/')}/{device_id}"


def availability_topic_for_device(base_state_topic: str, device_id: str) -> str:
    return f"{base_state_topic.rstrip('/')}/availability/{device_id}"


def token_file_for_device(config_dir: str, device_id: str) -> str:
    if device_id == "default":
        return os.path.join(config_dir, "token.txt")
    return os.path.join(config_dir, f"token_{device_id}.txt")


def load_runtime_config(config_path: str) -> RuntimeConfig:
    parser = configparser.ConfigParser()
    parser.read(config_path)
    config_dir = os.path.dirname(config_path)

    legacy_state_topic = parser.get("MQTT", "STATE_TOPIC", fallback=LEGACY_STATE_TOPIC).strip() or LEGACY_STATE_TOPIC
    legacy_cmd_topic = parser.get("MQTT", "CMD_TOPIC", fallback=LEGACY_CMD_TOPIC).strip() or LEGACY_CMD_TOPIC
    poll_interval = parser.getint("MONITOR", "POLL_INTERVAL", fallback=5)
    loglevel = parser.getint("MONITOR", "LOGLEVEL", fallback=3)

    devices: List[DeviceConfig] = []
    seen_ids = set()

    for section in parser.sections():
        if not section.upper().startswith("DEVICE_"):
            continue
        raw_device_id = section[7:]
        device_id = sanitize_device_id(raw_device_id)
        if device_id in seen_ids:
            log.warning(f"[system] Duplicate device id {device_id!r} in config, skipping section [{section}]")
            continue
        seen_ids.add(device_id)
        devices.append(DeviceConfig(
            device_id=device_id,
            label=parser.get(section, "LABEL", fallback=device_id) or device_id,
            ip=parser.get(section, "IP", fallback="").strip(),
            mac=parser.get(section, "MAC", fallback="").strip(),
            port=parser.getint(section, "PORT", fallback=8002),
            name=parser.get(section, "NAME", fallback="LoxBerry") or "LoxBerry",
            enabled=parser.getboolean(section, "ENABLED", fallback=True),
        ))

    if not devices:
        devices.append(DeviceConfig(
            device_id="default",
            label=parser.get("TV", "LABEL", fallback="Primary TV") or "Primary TV",
            ip=parser.get("TV", "IP", fallback="").strip(),
            mac=parser.get("TV", "MAC", fallback="").strip(),
            port=parser.getint("TV", "PORT", fallback=8002),
            name=parser.get("TV", "NAME", fallback="LoxBerry") or "LoxBerry",
            enabled=True,
        ))

    primary_device_id = sanitize_device_id(parser.get("GENERAL", "PRIMARY_DEVICE", fallback=devices[0].device_id))
    available_ids = {device.device_id for device in devices}
    enabled_ids = [device.device_id for device in devices if device.enabled]
    if primary_device_id not in available_ids:
        primary_device_id = enabled_ids[0] if enabled_ids else devices[0].device_id
    elif primary_device_id not in enabled_ids and enabled_ids:
        primary_device_id = enabled_ids[0]

    return RuntimeConfig(
        config_path=config_path,
        config_dir=config_dir,
        legacy_state_topic=legacy_state_topic,
        legacy_cmd_topic=legacy_cmd_topic,
        primary_device_id=primary_device_id,
        poll_interval=max(1, poll_interval),
        loglevel=loglevel,
        devices=devices,
    )


def build_device_runtimes(runtime_config: RuntimeConfig) -> Dict[str, DeviceRuntime]:
    devices = {}
    for device_cfg in runtime_config.devices:
        devices[device_cfg.device_id] = DeviceRuntime(
            cfg=device_cfg,
            token_file=token_file_for_device(runtime_config.config_dir, device_cfg.device_id),
            state_topic=topic_for_device(runtime_config.legacy_state_topic, device_cfg.device_id),
            cmd_topic=topic_for_device(runtime_config.legacy_cmd_topic, device_cfg.device_id),
            availability_topic=availability_topic_for_device(runtime_config.legacy_state_topic, device_cfg.device_id),
            is_primary=device_cfg.device_id == runtime_config.primary_device_id,
        )
    return devices


def build_topic_targets() -> Dict[str, List[str]]:
    topic_targets = {}
    primary_device = _devices.get(_runtime_config.primary_device_id)
    if primary_device:
        topic_targets[_runtime_config.legacy_cmd_topic] = [primary_device.cfg.device_id]

    for device in _devices.values():
        if device.cfg.enabled:
            topic_targets[device.cmd_topic] = [device.cfg.device_id]

    if _broadcast_cmd_topic:
        topic_targets[_broadcast_cmd_topic] = [device.cfg.device_id for device in _devices.values() if device.cfg.enabled]

    return topic_targets


def get_mqtt_connection() -> Tuple[str, int, str, str]:
    try:
        with open("/opt/loxberry/config/system/general.json") as handle:
            data = json.load(handle)
        mqtt_cfg = data.get("Mqtt", {})
        host = mqtt_cfg.get("Brokerhost", "localhost") or "localhost"
        port = int(mqtt_cfg.get("Brokerport", 1883) or 1883)
        user = mqtt_cfg.get("Brokeruser", "")
        password = mqtt_cfg.get("Brokerpass", "")
        return host, port, user, password
    except Exception as exc:
        log.debug(f"[system] Could not read general.json: {exc} — using defaults")
        return "localhost", 1883, "", ""


def on_mqtt_connect(client, userdata, flags, rc) -> None:
    if rc != 0:
        log.error(f"[system] MQTT connection failed, rc={rc}")
        return

    for topic in sorted(_topic_targets.keys()):
        client.subscribe(topic, qos=1)
        log.info(f"[system] MQTT subscribed to {topic}")

    for device in _devices.values():
        if device.current_state:
            device.publish_state(device.current_state, force=True)

    for device in _devices.values():
        if not device.cfg.enabled:
            try:
                client.publish(device.availability_topic, "offline", qos=1, retain=True)
                log.info(device.prefix(f"Availability published: 'offline' → {device.availability_topic} (disabled)"))
            except Exception as exc:
                log.warning(device.prefix(f"Failed to publish disabled availability state: {exc}"))


def on_mqtt_disconnect(client, userdata, rc) -> None:
    if rc != 0:
        log.warning(f"[system] MQTT disconnected unexpectedly (rc={rc}), will auto-reconnect")


def on_mqtt_message(client, userdata, msg) -> None:
    payload = msg.payload.decode("utf-8", errors="ignore").strip()
    targets = _topic_targets.get(msg.topic, [])

    if not targets:
        log.warning(f"[system] MQTT message received for unhandled topic {msg.topic}: {payload!r}")
        return

    if msg.topic == _broadcast_cmd_topic:
        log.info(f"[system] Broadcast MQTT command received on {msg.topic}: {payload!r}")

    for device_id in targets:
        device = _devices.get(device_id)
        if not device:
            continue
        log.info(device.prefix(f"MQTT command received on {msg.topic}: {payload!r}"))
        device.handle_command(payload)


def setup_mqtt(host: str, port: int, user: str, password: str):
    client = mqtt.Client(client_id="samsungframe-monitor", clean_session=True)
    client.on_connect = on_mqtt_connect
    client.on_disconnect = on_mqtt_disconnect
    client.on_message = on_mqtt_message

    if user:
        client.username_pw_set(user, password)
        log.info(f"[system] MQTT using credentials for user '{user}'")

    client.will_set(_runtime_config.legacy_state_topic, "off", qos=1, retain=True)
    client.connect_async(host, port, keepalive=60)
    client.loop_start()
    log.info(f"[system] MQTT connecting to {host}:{port}")
    return client


def make_availability_on_connect(device: DeviceRuntime):
    def _on_connect(client, userdata, flags, rc):
        if rc == 0:
            try:
                client.publish(device.availability_topic, "online", qos=1, retain=True)
                log.info(device.prefix(f"Availability published: 'online' → {device.availability_topic}"))
            except Exception as exc:
                log.warning(device.prefix(f"Failed to publish online availability state: {exc}"))
        else:
            log.warning(device.prefix(f"Availability MQTT connection failed, rc={rc}"))

    return _on_connect


def make_availability_on_disconnect(device: DeviceRuntime):
    def _on_disconnect(client, userdata, rc):
        if rc != 0:
            log.warning(device.prefix(f"Availability MQTT disconnected unexpectedly (rc={rc}), will auto-reconnect"))

    return _on_disconnect


def setup_availability_clients(host: str, port: int, user: str, password: str) -> Dict[str, mqtt.Client]:
    clients: Dict[str, mqtt.Client] = {}
    for device in _devices.values():
        if not device.cfg.enabled:
            continue

        client_id = f"sframe-avail-{device.cfg.device_id}"
        client = mqtt.Client(client_id=client_id, clean_session=True)
        if user:
            client.username_pw_set(user, password)

        client.will_set(device.availability_topic, "offline", qos=1, retain=True)
        client.on_connect = make_availability_on_connect(device)
        client.on_disconnect = make_availability_on_disconnect(device)
        client.connect_async(host, port, keepalive=60)
        client.loop_start()
        clients[device.cfg.device_id] = client

    return clients


def run_poll_loop() -> None:
    log.info(f"[system] Starting poll loop (interval={_runtime_config.poll_interval}s)")

    while not _shutdown.is_set():
        for device in _devices.values():
            if not device.cfg.enabled:
                continue
            state = device.get_state()
            device.publish_state(state)

        _shutdown.wait(timeout=_runtime_config.poll_interval)

    log.info("[system] Poll loop exiting")


def handle_signal(signum, frame) -> None:
    log.info(f"[system] Signal {signum} received, shutting down")
    _shutdown.set()


def main() -> None:
    global _mqtt_client, _availability_clients, _runtime_config, _devices, _topic_targets, _broadcast_cmd_topic

    parser = argparse.ArgumentParser(description="Samsung Frame TV monitor daemon")
    parser.add_argument("--config", required=True, help="Path to samsungframe.cfg")
    parser.add_argument("--logfile", required=True, help="Path to log file")
    args = parser.parse_args()

    _runtime_config = load_runtime_config(args.config)
    setup_logging(args.logfile, _runtime_config.loglevel)

    _devices = build_device_runtimes(_runtime_config)
    _broadcast_cmd_topic = topic_for_device(_runtime_config.legacy_cmd_topic, "all")
    _topic_targets = build_topic_targets()

    log.info("[system] Samsung Frame TV monitor starting")
    log.info(f"[system] Config: {_runtime_config.config_path}")
    log.info(f"[system] Primary device: {_runtime_config.primary_device_id}")
    log.info(f"[system] Configured devices: {', '.join(_devices.keys())}")

    for device in _devices.values():
        primary_note = " (primary)" if device.is_primary else ""
        enabled_note = "enabled" if device.cfg.enabled else "disabled"
        log.info(device.prefix(
            f"Configured {enabled_note}: {device.cfg.ip or 'no-ip'}:{device.cfg.port}, state topic {device.state_topic}, command topic {device.cmd_topic}{primary_note}"
        ))

    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)

    host, port, user, password = get_mqtt_connection()
    _mqtt_client = setup_mqtt(host, port, user, password)
    _availability_clients = setup_availability_clients(host, port, user, password)

    for _ in range(20):
        if _mqtt_client.is_connected():
            break
        time.sleep(0.5)
    else:
        log.warning("[system] MQTT did not connect within 10s — continuing anyway")

    try:
        run_poll_loop()
    finally:
        for device_id, client in _availability_clients.items():
            device = _devices.get(device_id)
            if device:
                try:
                    client.publish(device.availability_topic, "offline", qos=1, retain=True)
                except Exception:
                    pass

        log.info("[system] Disconnecting MQTT")
        _mqtt_client.loop_stop()
        _mqtt_client.disconnect()

        for client in _availability_clients.values():
            client.loop_stop()
            client.disconnect()

        log.info("[system] Samsung Frame TV monitor stopped")


if __name__ == "__main__":
    main()
