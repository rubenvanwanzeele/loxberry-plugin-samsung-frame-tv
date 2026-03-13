#!/bin/bash
# Runs as root after a plugin upgrade

echo "<INFO> Updating Python dependencies..."
pip3 install --quiet "samsungtvws[encrypted]" paho-mqtt wakeonlan

# Restore token file if it was backed up before upgrade
TOKEN=/opt/loxberry/config/plugins/samsungframe/token.txt
TOKEN_BACKUP=/tmp/samsungframe_token.txt.bak
if [ -f "$TOKEN_BACKUP" ]; then
    mv "$TOKEN_BACKUP" "$TOKEN"
    echo "<INFO> Pairing token restored."
fi

echo "<INFO> Restarting Samsung Frame TV service..."
systemctl daemon-reload
systemctl restart samsungframe.service
echo "<OK> Post-upgrade complete."
exit 0
