#!/bin/bash
# Runs as root after a plugin upgrade

echo "<INFO> Updating Python dependencies..."
pip3 install --quiet "samsungtvws[encrypted]" paho-mqtt wakeonlan

# Restore config and pairing token from pre-upgrade backup
CFGDIR=/opt/loxberry/config/plugins/samsungframe
if [ -f /tmp/samsungframe_cfg.bak ]; then
    mv /tmp/samsungframe_cfg.bak "$CFGDIR/samsungframe.cfg"
    echo "<INFO> Config restored."
fi
if [ -f /tmp/samsungframe_token.txt.bak ]; then
    mv /tmp/samsungframe_token.txt.bak "$CFGDIR/token.txt"
    echo "<INFO> Pairing token restored."
fi

echo "<INFO> Restarting Samsung Frame TV service..."
systemctl daemon-reload
systemctl restart samsungframe.service
echo "<OK> Post-upgrade complete."
exit 0
