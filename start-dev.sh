#!/usr/bin/env bash

# ==============================================================================
# Intelligent AI Camera Hub - Local Dev Launcher
# ==============================================================================

set -e

# ANSI Color codes
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${CYAN}=====================================================${NC}"
echo -e "${CYAN} Starting Intelligent AI Camera Hub Dev ${NC}"
echo -e "${CYAN}=====================================================${NC}"

# 1. Ensure required system services are running
echo -e "${YELLOW}[1/4] Checking core system services (PostgreSQL, Redis, Mosquitto)...${NC}"
if ! pg_isready -q; then
    echo -e "Starting PostgreSQL..."
    sudo systemctl start postgresql
fi

if ! redis-cli ping > /dev/null 2>&1; then
    echo -e "Starting Redis..."
    sudo systemctl start redis-server
fi

if ! systemctl is-active --quiet mosquitto; then
    echo -e "Starting Mosquitto MQTT Broker..."
    sudo systemctl start mosquitto
fi

echo -e "${GREEN}✓ All core system services are running.${NC}"

# 2. Run Database Migrations
echo -e "${YELLOW}[2/4] Running pending database migrations...${NC}"
php artisan migrate --force

# 3. Clean up any stale background processes on standard ports & daemons
echo -e "${YELLOW}[3/4] Preparing ports (8000, 8080, 5173) and daemons...${NC}"
pkill -f "./bore local" 2>/dev/null || true
pkill -f "cloudflared tunnel run" 2>/dev/null || true
PIDS=$(lsof -t -i:8000 -i:8080 -i:5173 2>/dev/null || true)
if [ -n "$PIDS" ]; then
    echo -e "Cleaning up previous processes (PIDs: $PIDS)..."
    kill -9 $PIDS 2>/dev/null || true
fi

# 4. Start Daemons and Development Services
echo -e "${YELLOW}[4/4] Launching background services...${NC}"

# Function to clean up background processes on Ctrl+C / exit
cleanup() {
    echo -e "\n${RED}Shutting down development processes...${NC}"
    kill 0 2>/dev/null || true
    exit 0
}
trap cleanup SIGINT SIGTERM EXIT

# A. Start Cloudflare Tunnel
echo -e "${CYAN}→ Starting Cloudflare Tunnel (camera-hub-tunnel)...${NC}"
cloudflared --protocol http2 --config /home/wsk-devops2/.cloudflared/config.yml tunnel run camera-hub-tunnel > /tmp/cloudflared.log 2>&1 &
TUNNEL_PID=$!

# B. Start MQTT Telemetry Daemon
echo -e "${CYAN}→ Starting MQTT Ingestion Daemon (php artisan mqtt:listen)...${NC}"
php artisan mqtt:listen > /dev/null 2>&1 &
MQTT_PID=$!

# C. Start bore TCP Tunnel for MQTT (using static remote port 35803)
echo -e "${CYAN}→ Starting bore TCP Tunnel for MQTT...${NC}"
./bore local 1883 --to bore.pub --port 35803 > /tmp/bore.log 2>&1 &
BORE_PID=$!
sleep 1.5
BORE_PORT=$(grep -oP 'listening at bore.pub:\K[0-9]+' /tmp/bore.log || echo "27964")

echo -e "${GREEN}=====================================================${NC}"
echo -e "${GREEN} Dev Environment Ready!${NC}"
echo -e "${GREEN} Local Web:        http://127.0.0.1:8000${NC}"
echo -e "${GREEN} Local Vite:       http://localhost:5173${NC}"
echo -e "${GREEN} Public Web:       https://camera-dev.8gategames.com${NC}"
echo -e "${GREEN} Public MQTT Host: bore.pub:${BORE_PORT}${NC}"
echo -e "${GREEN}=====================================================${NC}"
echo -e "${YELLOW}Press Ctrl+C to stop all development services.${NC}\n"

# C. Launch main Laravel dev process (Serve + Vite + Reverb + Horizon + Pail)
php artisan dev --inline
