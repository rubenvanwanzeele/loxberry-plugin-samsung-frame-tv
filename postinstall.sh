#!/bin/bash
# Post-install script for Samsung Frame TV plugin

echo "<INFO> Installing Python dependencies..."
pip3 install --quiet "samsungtvws[encrypted]" paho-mqtt wakeonlan
echo "<OK> Python dependencies installed."

echo "<INFO> Installing systemd service..."
cat > /etc/systemd/system/samsungframe.service << 'EOF'
[Unit]
Description=Samsung Frame TV Monitor (LoxBerry Plugin)
After=network.target mosquitto.service
Wants=mosquitto.service

[Service]
Type=simple
User=loxberry
ExecStart=/usr/bin/python3 /opt/loxberry/bin/plugins/samsungframe/monitor.py \
    --config /opt/loxberry/config/plugins/samsungframe/samsungframe.cfg \
    --logfile /opt/loxberry/log/plugins/samsungframe/monitor.log
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable samsungframe.service
systemctl start samsungframe.service
echo "<OK> Samsung Frame TV service installed and started."
exit 0
