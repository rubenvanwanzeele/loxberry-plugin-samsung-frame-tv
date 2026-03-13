#!/bin/bash
# Post-install script — runs as loxberry user

echo "<INFO> Installing Python dependencies..."
pip3 install --quiet "samsungtvws[encrypted]" paho-mqtt wakeonlan
echo "<OK> Python dependencies installed."
exit 0
