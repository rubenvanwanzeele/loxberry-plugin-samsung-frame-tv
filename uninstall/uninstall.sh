#!/bin/bash
# Uninstall script — runs as root

echo "<INFO> Stopping and removing Samsung Frame TV service..."
systemctl stop samsungframe.service 2>/dev/null || true
systemctl disable samsungframe.service 2>/dev/null || true
rm -f /etc/systemd/system/samsungframe.service
systemctl daemon-reload

echo "<OK> Samsung Frame TV plugin uninstalled."
exit 0
