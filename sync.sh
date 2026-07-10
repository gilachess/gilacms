#!/bin/bash

# Configuration Variables
LOCAL_DIR="./"
REMOTE_USER="root"
REMOTE_HOST="your-production-server"
REMOTE_DIR="/var/www/your-site-domain/"

echo "🚀 Starting sync to $REMOTE_HOST..."

# 1. Sync the files (excluding git, local environment configs, and SQLite databases)
rsync -avz --no-owner --no-group \
  --exclude='.git/' \
  --exclude='node_modules/' \
  --exclude='.env' \
  --exclude='.DS_Store' \
  --exclude='._*' \
  --exclude='cache/' \
  --exclude='*.db' \
  --exclude='*.sqlite' \
  --exclude='images/uploads/' \
  "$LOCAL_DIR" "$REMOTE_USER@$REMOTE_HOST:$REMOTE_DIR"

# 2. Automatically fix ownership on the live server right after sync finishes
echo "🔧 Fixing file permissions on live server..."
ssh "$REMOTE_USER@$REMOTE_HOST" "chown -R www-data:www-data $REMOTE_DIR"

echo "✅ Sync complete! Production is up to date and permissions are secure."
