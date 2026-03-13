#!/bin/bash
# Daemon launcher for Samsung Frame TV plugin
# Must fork immediately — blocking here stalls all other LoxBerry plugin daemons

/usr/bin/python3 "$LBPBIN/monitor.py" \
  --config "$LBPCONFIG/samsungframe.cfg" \
  --logfile "$LBPLOG/monitor.log" &
