#!/bin/bash
# Runs before a plugin upgrade.
# Stop the daemon gracefully so files can be replaced safely.

echo "<INFO> Stopping Samsung Frame TV daemon before upgrade..."
pkill -f "monitor.py" 2>/dev/null || true
sleep 1
echo "<OK> Pre-upgrade complete."
exit 0
