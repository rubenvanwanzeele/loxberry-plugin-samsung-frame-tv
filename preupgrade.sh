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
rm -rf /tmp/samsungframe_tokens.bak
mkdir -p /tmp/samsungframe_tokens.bak
found_token=0
for token in "$CFGDIR"/token*.txt; do
    if [ -f "$token" ]; then
        cp "$token" /tmp/samsungframe_tokens.bak/
        found_token=1
    fi
done
if [ "$found_token" -eq 1 ]; then
    echo "<INFO> Pairing tokens backed up."
fi
echo "<OK> Pre-upgrade complete."
exit 0
