#!/bin/bash
# Runs as root before a plugin upgrade

echo "<INFO> Stopping Samsung Frame TV service before upgrade..."
systemctl stop samsungframe.service 2>/dev/null || true

# Back up config and pairing token so they survive the upgrade
CFGDIR=/opt/loxberry/config/plugins/samsungframe
if [ -f "$CFGDIR/samsungframe.cfg" ]; then
    cp "$CFGDIR/samsungframe.cfg" /tmp/samsungframe_cfg.bak
    echo "<INFO> Config backed up."
fi
if [ -f "$CFGDIR/token.txt" ]; then
    cp "$CFGDIR/token.txt" /tmp/samsungframe_token.txt.bak
    echo "<INFO> Pairing token backed up."
fi
echo "<OK> Pre-upgrade complete."
exit 0
