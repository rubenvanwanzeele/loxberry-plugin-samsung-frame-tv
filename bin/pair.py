#!/usr/bin/env python3
"""
pair.py — One-shot pairing helper for Samsung Frame TV plugin.

Supports pairing the legacy default TV and additional multi-TV device sections.
"""

import argparse
import configparser
import os
import sys
import time


def sanitize_device_id(value: str) -> str:
    cleaned = "".join(ch.lower() if ch.isalnum() else "_" for ch in value.strip())
    while "__" in cleaned:
        cleaned = cleaned.replace("__", "_")
    cleaned = cleaned.strip("_")
    cleaned = cleaned or "default"
    if cleaned == "all":
        cleaned = "tv_all"
    return cleaned


def token_file_for_device(config_dir: str, device_id: str) -> str:
    if device_id == "default":
        return os.path.join(config_dir, "token.txt")
    return os.path.join(config_dir, f"token_{device_id}.txt")


def load_device(cfg: configparser.ConfigParser, device_id: str):
    device_id = sanitize_device_id(device_id)
    section = f"DEVICE_{device_id}"

    if cfg.has_section(section):
        return {
            "device_id": device_id,
            "label": cfg.get(section, "LABEL", fallback=device_id) or device_id,
            "ip": cfg.get(section, "IP", fallback="").strip(),
            "port": cfg.getint(section, "PORT", fallback=8002),
            "name": cfg.get(section, "NAME", fallback="LoxBerry") or "LoxBerry",
        }

    if device_id == "default":
        return {
            "device_id": "default",
            "label": cfg.get("TV", "LABEL", fallback="Primary TV") or "Primary TV",
            "ip": cfg.get("TV", "IP", fallback="").strip(),
            "port": cfg.getint("TV", "PORT", fallback=8002),
            "name": cfg.get("TV", "NAME", fallback="LoxBerry") or "LoxBerry",
        }

    return None


def main():
    parser = argparse.ArgumentParser(description="Samsung Frame TV pairing helper")
    parser.add_argument("--config", required=True, help="Path to samsungframe.cfg")
    parser.add_argument("--device", default="default", help="Device ID to pair (default: primary legacy TV)")
    args = parser.parse_args()

    cfg = configparser.ConfigParser()
    cfg.read(args.config)

    requested_device_id = sanitize_device_id(args.device)
    device = load_device(cfg, requested_device_id)
    if not device:
        print(f"ERROR: Device {requested_device_id!r} not found in config.")
        sys.exit(2)

    if not device["ip"]:
        print(f"ERROR: [{device['label']}] No IP address configured for this TV.")
        print("Set the TV IP in the plugin configuration first, then run pairing again.")
        sys.exit(2)

    config_dir = os.path.dirname(args.config)
    token_file = token_file_for_device(config_dir, device["device_id"])

    prefix = f"[{device['label']}]"
    print(f"{prefix} Connecting to Samsung TV at {device['ip']}:{device['port']} ...")
    print(f"{prefix} Token will be saved to: {token_file}")
    print()

    try:
        from samsungtvws import SamsungTVWS
    except ImportError:
        print("ERROR: samsungtvws library not installed. Run: pip3 install 'samsungtvws[encrypted]'")
        sys.exit(1)

    try:
        tv = SamsungTVWS(
            host=device["ip"],
            port=device["port"],
            token_file=token_file,
            timeout=10,
            name=device["name"],
        )

        print(f"{prefix} A popup should appear on your TV — please accept the connection request.")
        print(f"{prefix} Waiting up to 30 seconds for acceptance...")

        tv.open()
        time.sleep(2)
        tv.close()

        if os.path.exists(token_file):
            with open(token_file) as handle:
                token = handle.read().strip()
            print(f"SUCCESS: {prefix} Pairing complete. Token saved: {token}")
            sys.exit(0)

        print(f"WARNING: {prefix} Connection succeeded but no token file was created.")
        print(f"{prefix} The TV may not require token auth, or pairing was not accepted.")
        sys.exit(0)

    except ConnectionRefusedError:
        print(f"ERROR: {prefix} Connection refused to {device['ip']}:{device['port']}.")
        print(f"{prefix} Check that the TV is on and the IP address is correct.")
        sys.exit(2)
    except TimeoutError:
        print(f"ERROR: {prefix} Connection timed out. Make sure the TV is powered on and reachable.")
        sys.exit(2)
    except Exception as exc:
        print(f"ERROR: {prefix} {type(exc).__name__}: {exc}")
        sys.exit(2)


if __name__ == "__main__":
    main()
