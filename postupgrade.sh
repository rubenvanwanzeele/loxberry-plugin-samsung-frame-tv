#!/bin/bash
# Runs as root after a plugin upgrade

echo "<INFO> Updating Python dependencies..."
pip3 install --quiet "samsungtvws[encrypted]" paho-mqtt wakeonlan

echo "<INFO> Restarting Samsung Frame TV service..."
systemctl daemon-reload
systemctl restart samsungframe.service
echo "<OK> Post-upgrade complete."
exit 0
