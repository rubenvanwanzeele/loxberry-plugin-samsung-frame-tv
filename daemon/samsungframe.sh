#!/bin/bash
# Daemon launcher for Samsung Frame TV plugin
# Must fork immediately — blocking here stalls all other LoxBerry plugin daemons

/usr/bin/python3 "$LBPBIN/samsungframe/monitor.py" \
  --config "$LBPCONFIG/samsungframe/samsungframe.cfg" \
  --logfile "$LBPLOG/samsungframe/monitor.log" &
