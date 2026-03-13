#!/bin/bash
# Runs as root before a plugin upgrade

echo "<INFO> Stopping Samsung Frame TV service before upgrade..."
systemctl stop samsungframe.service 2>/dev/null || true

# Back up pairing token so it survives the upgrade
TOKEN=/opt/loxberry/config/plugins/samsungframe/token.txt
if [ -f "$TOKEN" ]; then
    cp "$TOKEN" /tmp/samsungframe_token.txt.bak
    echo "<INFO> Pairing token backed up."
fi
echo "<OK> Pre-upgrade complete."
exit 0
