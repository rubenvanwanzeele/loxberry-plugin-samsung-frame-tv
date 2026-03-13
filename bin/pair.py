#!/usr/bin/env python3
"""
pair.py — One-shot pairing helper for Samsung Frame TV plugin.

Called from the web UI to initiate WebSocket pairing with the TV.
The TV will show a popup asking the user to allow the connection.
On success, the token is saved to the token file and reused by monitor.py.

Usage:
    python3 pair.py --config /path/to/samsungframe.cfg
"""

import argparse
import configparser
import os
import sys
import time


def lb_path(var: str) -> str:
    return os.popen(
        f"perl -e 'use LoxBerry::System; print ${var}; exit;'"
    ).read().strip()


def main():
    parser = argparse.ArgumentParser(description="Samsung Frame TV pairing helper")
    parser.add_argument("--config", required=True, help="Path to samsungframe.cfg")
    args = parser.parse_args()

    cfg = configparser.ConfigParser()
    cfg.read(args.config)

    tv_ip = cfg.get("TV", "IP", fallback="192.168.1.43")
    tv_port = cfg.getint("TV", "PORT", fallback=8002)
    tv_name = cfg.get("TV", "NAME", fallback="LoxBerry")

    # Token file lives next to the config file
    config_dir = os.path.dirname(args.config)
    token_file = os.path.join(config_dir, "token.txt")

    print(f"Connecting to Samsung TV at {tv_ip}:{tv_port} ...")
    print(f"Token will be saved to: {token_file}")
    print()

    try:
        from samsungtvws import SamsungTVWS
    except ImportError:
        print("ERROR: samsungtvws library not installed. Run: pip3 install 'samsungtvws[encrypted]'")
        sys.exit(1)

    try:
        tv = SamsungTVWS(
            host=tv_ip,
            port=tv_port,
            token_file=token_file,
            timeout=10,
            name=tv_name,
        )

        # Opening the connection triggers pairing popup on the TV.
        # The user must accept it within ~30 seconds.
        print("A popup should appear on your TV — please accept the connection request.")
        print("Waiting up to 30 seconds for acceptance...")

        tv.open()
        time.sleep(2)
        tv.close()

        if os.path.exists(token_file):
            with open(token_file) as f:
                token = f.read().strip()
            print(f"SUCCESS: Pairing complete. Token saved: {token}")
            sys.exit(0)
        else:
            print("WARNING: Connection succeeded but no token file was created.")
            print("The TV may not require token auth, or pairing was not accepted.")
            sys.exit(0)

    except ConnectionRefusedError:
        print(f"ERROR: Connection refused to {tv_ip}:{tv_port}.")
        print("Check that the TV is on and the IP address is correct.")
        sys.exit(2)
    except TimeoutError:
        print("ERROR: Connection timed out. Make sure the TV is powered on and reachable.")
        sys.exit(2)
    except Exception as e:
        print(f"ERROR: {type(e).__name__}: {e}")
        sys.exit(2)


if __name__ == "__main__":
    main()
