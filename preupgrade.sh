#!/bin/bash
# Runs before a plugin upgrade — stop the service gracefully

echo "<INFO> Stopping Samsung Frame TV service before upgrade..."
systemctl stop samsungframe.service 2>/dev/null || true
echo "<OK> Pre-upgrade complete."
exit 0
