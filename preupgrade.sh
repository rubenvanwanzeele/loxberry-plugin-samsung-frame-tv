#!/bin/bash
# Runs as root before a plugin upgrade

echo "<INFO> Stopping Samsung Frame TV service before upgrade..."
systemctl stop samsungframe.service 2>/dev/null || true
echo "<OK> Pre-upgrade complete."
exit 0
