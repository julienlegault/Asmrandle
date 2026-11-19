# Dev Container Setup

This directory contains the configuration for the GitHub Dev Container setup for Asmrandle.

## What's Included

### Configuration Files

- **devcontainer.json**: Main dev container configuration
  - Python 3.11 with Node.js 20
  - Port forwarding for the web server (8000)
  - VS Code extensions for development
  - Automatic setup and startup scripts

- **post-create.sh**: Setup script (runs once)
  - Installs Python and Node dependencies
  - Installs Playwright browsers for E2E testing

- **post-start.sh**: Startup script (runs every container start)
  - Automatically starts the Python HTTP server on port 8000
  - Displays helpful commands

## Getting Started

1. **Open in Dev Container**
   - Open the repository in GitHub Codespaces or VS Code with Dev Containers extension
   - VS Code will automatically detect and use the dev container configuration

2. **Automatic Setup**
   - The dev container will automatically:
     - Install all dependencies
     - Start the HTTP server
     - Display available commands

3. **Access the Application**
   - The web server will be available at: `http://localhost:8000`
   - VS Code will show a notification when the port is forwarded

## Available Commands

### Web Server
```bash
npm run server    # Start the web server (port 8000)
```

### Testing
```bash
npm test          # Run E2E tests with Playwright
npm run test:ui   # Run tests with interactive UI
npm run test:headed  # Run tests in headed (visible) browser
npm run test:debug   # Run tests with debugger
```

### Data Management
```bash
npm run update-cards   # Update card database from Scryfall/EDHRec
```

## Project Structure

```
.devcontainer/
├── devcontainer.json    # Main configuration
├── post-create.sh       # Setup script
├── post-start.sh        # Startup script
└── README.md           # This file
```

## Features

✅ **Instant Testing**: Server starts automatically, ready for tests  
✅ **Port Forwarding**: Port 8000 automatically forwarded  
✅ **Playwright Integration**: E2E testing pre-configured  
✅ **Development Tools**: ESLint, Prettier, Python linting  
✅ **Reproducible Environment**: Consistent setup across machines  

## Customization

### Add More Extensions
Edit `devcontainer.json` and add to the `extensions` array:
```json
"extensions": [
  "ms-playwright.playwright",
  "other-extension-id"
]
```

### Modify Port
To use a different port, update:
1. `forwardPorts` in devcontainer.json
2. `post-start.sh` server command
3. Playwright test configuration if needed

### Add Python Dependencies
Create a `requirements.txt` file in the repository root with your dependencies.

## Troubleshooting

### Server not starting?
Check the logs:
```bash
cat /tmp/http-server.log
```

### Playwright tests failing?
Ensure browsers are installed:
```bash
npx playwright install
```

### Port conflicts?
If port 8000 is in use, modify the port in:
1. `.devcontainer/devcontainer.json` → `forwardPorts`
2. `.devcontainer/post-start.sh` → python command
3. `package.json` → server script

## References

- [Dev Containers Documentation](https://containers.dev/)
- [GitHub Codespaces Documentation](https://docs.github.com/en/codespaces)
- [Playwright Documentation](https://playwright.dev/)
