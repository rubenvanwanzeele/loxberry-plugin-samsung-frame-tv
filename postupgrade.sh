#!/bin/bash
# Runs after a plugin upgrade.
# Re-install Python dependencies in case new ones were added.

echo "<INFO> Installing/updating Python dependencies..."
pip3 install --quiet "samsungtvws[encrypted]" paho-mqtt wakeonlan
echo "<OK> Post-upgrade complete."
exit 0
