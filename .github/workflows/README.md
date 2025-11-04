# GitHub Actions Workflows for Asmrandle

This repository contains multiple automated workflows to maintain and deploy the Asmrandle Magic: the Gathering game.

## 🔄 Workflows Overview

### 1. **Card List Updates** (`update-card-list.yml`)
Automatically updates the Magic: the Gathering card list using the latest data from Scryfall.

### 2. **E2E Testing & Deployment** (`deploy-with-tests.yml`)
Comprehensive end-to-end testing with Playwright before deploying to GitHub Pages.

### 3. **Simple Validation & Deployment** (`simple-deploy.yml`)
Lightweight validation and deployment workflow for faster CI/CD.

---

## 📋 Card List Updates Workflow

### When it runs:
1. **On Push to Main**: When commits are pushed directly to the main branch
2. **On Pull Request Merge**: When pull requests are merged into main
3. **Manual Trigger**: Can be run manually from the GitHub Actions tab
4. **Smart Timing**: Only runs if the card list hasn't been updated in the past 48 hours (push/PR) or 25 days (scheduled)
5. **Monthly Schedule**: Automatically runs on the 1st of every month at 2:00 AM UTC

### What it does:
1. **Environment Setup**: Sets up Python 3.11 and installs dependencies
2. **Smart Timing Check**: Checks last modification time with different thresholds
3. **Card List Update**: Downloads latest oracle cards from Scryfall and runs `pullCards.py`
4. **Automatic Commit**: Commits changes with descriptive messages

---

## 🧪 E2E Testing & Deployment Workflow

### When it runs:
- **On Push to Main**: Runs tests and deploys if tests pass
- **On Pull Requests**: Runs tests only (no deployment)
- **Manual Trigger**: Can be run manually

### Test Coverage:
- ✅ **Page Loading**: Homepage loads correctly with all elements
- ✅ **Game Functionality**: Daily and Practice game buttons work
- ✅ **User Interactions**: Card clicking, hard mode toggle
- ✅ **Game Progression**: Scoreboard updates, overlays display
- ✅ **Responsive Design**: Mobile and desktop viewport testing
- ✅ **Performance**: Page load times and network behavior
- ✅ **External Links**: EDHRec and LinkedIn links validation

### Technology Stack:
- **Playwright**: Browser automation for real user interaction testing
- **Chromium**: Headless browser for consistent testing environment
- **GitHub Pages**: Automated deployment on successful tests

### Deployment Process:
```
Code Push → Run E2E Tests → Tests Pass? → Deploy to GitHub Pages
                     ↓
               Tests Fail? → Block Deployment
```

---

## ⚡ Simple Validation & Deployment Workflow

### When it runs:
- **On Push to Main**: Runs validation and deploys if validation passes
- **On Pull Requests**: Runs validation only
- **Manual Trigger**: Available for quick testing

### Validation Checks:
- ✅ **File Existence**: Required files (`index.html`, `formatted_card_list.js`)
- ✅ **HTML Syntax**: Valid HTML structure and required elements
- ✅ **JavaScript Syntax**: Valid JavaScript in card list file
- ✅ **SEO Elements**: Meta tags, Open Graph, viewport settings
- ✅ **CSS Structure**: Responsive design and essential classes
- ✅ **Server Startup**: Local server functionality test
- ✅ **Card List Format**: Correct structure and sufficient content

### Benefits:
- **Fast Execution**: Lightweight validation without external dependencies
- **Quick Feedback**: Rapid identification of basic issues
- **Broad Coverage**: Checks multiple aspects of site functionality

---

## 🛡️ Safety Features

### All Workflows Include:
- **Permissions Control**: Minimal required permissions for security
- **Concurrency Management**: Prevents conflicting deployments
- **Error Handling**: Graceful failure with detailed logging
- **Artifact Upload**: Test reports and screenshots on failure
- **Environment Separation**: Different behavior for different trigger types

### Deployment Protection:
- **Tests Required**: Deployment only occurs after successful testing/validation
- **Main Branch Only**: Production deployments limited to main branch
- **Manual Override**: Workflows can be manually triggered when needed

---

## 📊 Monitoring & Maintenance

### GitHub Actions Tab:
- View workflow execution history
- Check test results and deployment status
- Download test artifacts (screenshots, reports)
- Monitor performance and timing

### Workflow Status:
- � **Green**: All tests passed, deployment successful
- 🔴 **Red**: Tests failed, deployment blocked
- 🟡 **Yellow**: Workflow running or queued
- ⚪ **Gray**: Workflow skipped (timing conditions not met)

### Manual Execution:
All workflows can be manually triggered from the GitHub Actions tab for:
- Testing new features before push
- Emergency deployments
- Debugging workflow issues
- Updating card list outside normal schedule

---

## 🔧 Configuration

### Card List Updates:
- **48-hour cooldown** for push/PR triggers
- **25-day cooldown** for scheduled runs
- **Monthly schedule**: 1st day at 2:00 AM UTC

### Testing & Deployment:
- **E2E Tests**: Comprehensive browser automation
- **Simple Validation**: Fast syntax and structure checks
- **GitHub Pages**: Automatic deployment on test success

Choose the appropriate workflow based on your needs:
- Use **E2E testing** for thorough validation before important releases
- Use **Simple validation** for quick iteration and development
- **Card list updates** run automatically to keep content current