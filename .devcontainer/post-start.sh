#!/bin/bash
set -e

echo "🎮 Starting Asmrandle development server..."

# Start Python HTTP server in background
cd /workspaces/Asmrandle
python -m http.server 8000 > /tmp/http-server.log 2>&1 &
SERVER_PID=$!

echo "✅ Web server started (PID: $SERVER_PID)"
echo "📍 Server available at: http://localhost:8000"
echo ""
echo "🧪 Ready for E2E testing with:"
echo "   npm test"
echo ""
