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
if [ -d /tmp/samsungframe_tokens.bak ]; then
    for token in /tmp/samsungframe_tokens.bak/token*.txt; do
        if [ -f "$token" ]; then
            mv "$token" "$CFGDIR/$(basename "$token")"
        fi
    done
    rmdir /tmp/samsungframe_tokens.bak 2>/dev/null || true
    echo "<INFO> Pairing tokens restored."
fi

echo "<INFO> Restarting Samsung Frame TV service..."
systemctl daemon-reload
systemctl restart samsungframe.service
echo "<OK> Post-upgrade complete."
exit 0
