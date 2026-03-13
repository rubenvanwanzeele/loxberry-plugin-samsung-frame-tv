#!/bin/bash
# Uninstall script for Samsung Frame TV plugin
# LoxBerry calls this before removing plugin files

echo "<INFO> Stopping Samsung Frame TV daemon..."
pkill -f "monitor.py" 2>/dev/null || true

echo "<OK> Samsung Frame TV plugin uninstalled."
exit 0
