#!/bin/bash
set -e

echo "🚀 Setting up Asmrandle development environment..."

# Install Python dependencies
echo "📦 Installing Python dependencies..."
pip install --upgrade pip
pip install -r requirements.txt 2>/dev/null || echo "No requirements.txt found"

# Install Node dependencies for testing
echo "📦 Installing Node dependencies..."
npm install --save-dev @playwright/test

# Install Playwright browsers
echo "🎭 Installing Playwright browsers..."
npx playwright install

echo "✅ Setup complete! Ready for development and testing."
echo ""
echo "Available commands:"
echo "  - npm test                 # Run E2E tests"
echo "  - npm run server           # Start the web server (port 8000)"
echo "  - python pullCards.py      # Update card database"
