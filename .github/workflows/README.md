# GitHub Actions Workflow for Asmrandle# GitHub Actions Workflows for Asmrandle



This repository contains a single, unified "Build and Deploy" workflow that handles card updates, comprehensive E2E testing, and deployment to GitHub Pages as one cohesive process.This repository contains multiple automated workflows to maintain and deploy the Asmrandle Magic: the Gathering game.



## 🔄 Single Unified Workflow## 🔄 Workflows Overview



### **Build and Deploy** (`build-and-deploy.yml`)### 1. **Card List Updates** (`update-card-list.yml`)

A comprehensive workflow with three distinct jobs that run in sequence:Automatically updates the Magic: the Gathering card list using the latest data from Scryfall.

1. **Update Cards** - Monthly card list updates from Scryfall

2. **Test** - Comprehensive E2E testing with Playwright  ### 2. **E2E Testing & Deployment** (`deploy-with-tests.yml`)

3. **Deploy** - Deployment to GitHub Pages (only after tests pass)Comprehensive end-to-end testing with Playwright before deploying to GitHub Pages.



---### 3. **Simple Validation & Deployment** (`simple-deploy.yml`)

Lightweight validation and deployment workflow for faster CI/CD.

## 🏗️ Workflow Structure

---

### When it runs:

1. **On Push to Main**: Direct pushes to main branch trigger testing and deployment## 📋 Card List Updates Workflow

2. **On Pull Request Merge**: When pull requests are merged into main

3. **Manual Trigger**: Can be run manually from the GitHub Actions tab### When it runs:

4. **Monthly Schedule**: Runs on the 1st of every month at 2:00 AM UTC for automated card updates1. **On Push to Main**: When commits are pushed directly to the main branch

2. **On Pull Request Merge**: When pull requests are merged into main

### Job Sequence:3. **Manual Trigger**: Can be run manually from the GitHub Actions tab

4. **Smart Timing**: Only runs if the card list hasn't been updated in the past 48 hours (push/PR) or 25 days (scheduled)

#### 🃏 **Job 1: Update Cards** (Schedule & Manual Only)5. **Monthly Schedule**: Automatically runs on the 1st of every month at 2:00 AM UTC

- **Condition**: Only runs for scheduled or manual triggers (not for pushes)

- **Smart Timing**: 25-day cooldown - only updates if cards haven't been updated in 25+ days### What it does:

- **Process**: 1. **Environment Setup**: Sets up Python 3.11 and installs dependencies

  1. Downloads latest oracle cards from Scryfall API2. **Smart Timing Check**: Checks last modification time with different thresholds

  2. Runs `pullCards.py` to process and validate new cards3. **Card List Update**: Downloads latest oracle cards from Scryfall and runs `pullCards.py`

  3. Commits changes with descriptive messages if new cards found4. **Automatic Commit**: Commits changes with descriptive messages

  4. Pushes updates to trigger the next job

---

#### 🧪 **Job 2: Test** (Always Runs)

- **Condition**: Runs after card updates complete (or immediately for push/PR events)## 🧪 E2E Testing & Deployment Workflow

- **Setup**: Node.js 18, Playwright with Chromium browser, local HTTP server on port 3000

- **E2E Test Coverage**:### When it runs:

  - ✅ **Homepage Loading**: Title, buttons, footer elements visibility- **On Push to Main**: Runs tests and deploys if tests pass

  - ✅ **Game Functionality**: Daily and Practice game button workflows- **On Pull Requests**: Runs tests only (no deployment)

  - ✅ **User Interactions**: Card clicking, game state transitions- **Manual Trigger**: Can be run manually

  - ✅ **Responsive Design**: Mobile viewport testing (375x667)

  - ✅ **Game Flow**: Complete interaction cycles and state management### Test Coverage:

- **Artifacts**: Uploads test reports and screenshots on failure- ✅ **Page Loading**: Homepage loads correctly with all elements

- **Cleanup**: Properly terminates local server after testing- ✅ **Game Functionality**: Daily and Practice game buttons work

- ✅ **User Interactions**: Card clicking, hard mode toggle

#### 🚀 **Job 3: Deploy** (Only After Tests Pass)- ✅ **Game Progression**: Scoreboard updates, overlays display

- **Condition**: Only runs on main branch after successful tests- ✅ **Responsive Design**: Mobile and desktop viewport testing

- **Safety**: Deployment blocked if any E2E tests fail- ✅ **Performance**: Page load times and network behavior

- **Process**: - ✅ **External Links**: EDHRec and LinkedIn links validation

  1. Checks out latest code (including any card updates)

  2. Configures GitHub Pages### Technology Stack:

  3. Uploads repository contents as deployment artifact- **Playwright**: Browser automation for real user interaction testing

  4. Deploys to GitHub Pages with custom domain support- **Chromium**: Headless browser for consistent testing environment

- **GitHub Pages**: Automated deployment on successful tests

---

### Deployment Process:

## 🛡️ Safety and Quality Features```

Code Push → Run E2E Tests → Tests Pass? → Deploy to GitHub Pages

### **Quality Gates**:                     ↓

- **No Untested Deployments**: Every deployment requires passing E2E tests               Tests Fail? → Block Deployment

- **Smart Updates**: Card list only updates when actually needed (25+ days)```

- **Change Detection**: Only commits when new cards are actually found

- **Rollback Safe**: Failed deployments don't affect live site---



### **Concurrency Control**:## ⚡ Simple Validation & Deployment Workflow

- **Single Deployment**: Only one deployment can run at a time

- **No Cancellation**: In-progress deployments complete without interruption### When it runs:

- **Queue Management**: Later workflow runs wait for current deployment- **On Push to Main**: Runs validation and deploys if validation passes

- **On Pull Requests**: Runs validation only

### **Error Handling**:- **Manual Trigger**: Available for quick testing

- **Graceful Failures**: Detailed logging and error reporting at each step

- **Test Artifacts**: Screenshots and reports available for debugging### Validation Checks:

- **Environment Cleanup**: Local servers and processes properly terminated- ✅ **File Existence**: Required files (`index.html`, `formatted_card_list.js`)

- **Rollback Protection**: Failed tests prevent broken deployments- ✅ **HTML Syntax**: Valid HTML structure and required elements

- ✅ **JavaScript Syntax**: Valid JavaScript in card list file

---- ✅ **SEO Elements**: Meta tags, Open Graph, viewport settings

- ✅ **CSS Structure**: Responsive design and essential classes

## 📊 Monitoring & Usage- ✅ **Server Startup**: Local server functionality test

- ✅ **Card List Format**: Correct structure and sufficient content

### **GitHub Actions Tab Experience**:

- **Single Workflow View**: All operations appear under one "Build and Deploy" workflow### Benefits:

- **Job Visibility**: See card updates → testing → deployment as distinct steps- **Fast Execution**: Lightweight validation without external dependencies

- **Progress Tracking**: Monitor each phase independently- **Quick Feedback**: Rapid identification of basic issues

- **Artifact Access**: Download test reports and failure screenshots- **Broad Coverage**: Checks multiple aspects of site functionality



### **Workflow Status Indicators**:---

- 🟢 **Green**: All jobs completed successfully, site deployed

- 🔴 **Red**: Tests failed, deployment blocked, site unchanged## 🛡️ Safety Features

- 🟡 **Yellow**: Workflow running (check individual job progress)

- ⚪ **Gray**: Workflow skipped (card update timing conditions not met)### All Workflows Include:

- **Permissions Control**: Minimal required permissions for security

### **Manual Execution Options**:- **Concurrency Management**: Prevents conflicting deployments

- **Full Pipeline**: Run complete card update → test → deploy sequence- **Error Handling**: Graceful failure with detailed logging

- **Test & Deploy**: Skip card updates, run tests and deploy current code- **Artifact Upload**: Test reports and screenshots on failure

- **Development Testing**: Test changes before committing- **Environment Separation**: Different behavior for different trigger types



---### Deployment Protection:

- **Tests Required**: Deployment only occurs after successful testing/validation

## 🔧 Configuration Details- **Main Branch Only**: Production deployments limited to main branch

- **Manual Override**: Workflows can be manually triggered when needed

### **Card Update Settings**:

- **Timing**: 25-day cooldown for scheduled runs---

- **Schedule**: 1st day of every month at 2:00 AM UTC

- **Source**: Scryfall oracle card database (latest Magic: The Gathering data)## 📊 Monitoring & Maintenance

- **Validation**: Cards checked against EDHRec for popularity metrics

### GitHub Actions Tab:

### **Testing Configuration**:- View workflow execution history

- **Browser**: Chromium headless for consistent results- Check test results and deployment status

- **Timeouts**: Generous timeouts (10-15 seconds) for reliable testing- Download test artifacts (screenshots, reports)

- **Viewports**: Desktop and mobile (375x667) responsive testing- Monitor performance and timing

- **Server**: Python HTTP server on localhost:3000 for realistic testing

### Workflow Status:

### **Deployment Settings**:- � **Green**: All tests passed, deployment successful

- **Target**: GitHub Pages with custom domain support- 🔴 **Red**: Tests failed, deployment blocked

- **Source**: Full repository contents (no Jekyll processing)- 🟡 **Yellow**: Workflow running or queued

- **Security**: Minimal required permissions for each job- ⚪ **Gray**: Workflow skipped (timing conditions not met)

- **Artifacts**: 30-day retention for test results and reports

### Manual Execution:

---All workflows can be manually triggered from the GitHub Actions tab for:

- Testing new features before push

## 🎯 Benefits of Unified Workflow- Emergency deployments

- Debugging workflow issues

### **Simplified Management**:- Updating card list outside normal schedule

- **Single Point of Control**: All automation visible in one workflow

- **Consistent Process**: Same quality standards for all deployments---

- **Clear Dependencies**: Visual job sequence shows update → test → deploy flow

## 🔧 Configuration

### **Quality Assurance**:

- **Comprehensive Testing**: Every deployment validated before going live### Card List Updates:

- **Automated Maintenance**: Monthly card updates without manual intervention- **48-hour cooldown** for push/PR triggers

- **Failure Protection**: Broken code never reaches production- **25-day cooldown** for scheduled runs

- **Monthly schedule**: 1st day at 2:00 AM UTC

### **Developer Experience**:

- **Fast Feedback**: Quick identification of issues through E2E testing### Testing & Deployment:

- **Manual Override**: Can trigger any part of the process when needed- **E2E Tests**: Comprehensive browser automation

- **Transparent Process**: Clear visibility into what's happening at each step- **Simple Validation**: Fast syntax and structure checks

- **GitHub Pages**: Automatic deployment on test success

### **Operational Benefits**:

- **Hands-off Maintenance**: Fully automated monthly updates and deploymentChoose the appropriate workflow based on your needs:

- **Quality Gates**: Multiple validation points prevent issues- Use **E2E testing** for thorough validation before important releases

- **Monitoring**: Complete visibility into deployment pipeline health- Use **Simple validation** for quick iteration and development

- **Card list updates** run automatically to keep content current
---

## 🚀 Usage Scenarios

### **Normal Development**:
1. Push code to main → Tests run → Deploys if tests pass
2. Monthly: Cards auto-update → Tests run → Deploys with latest cards

### **Manual Maintenance**:
1. **Force Card Update**: Manually trigger workflow to update cards immediately
2. **Test Validation**: Run workflow to test current site functionality
3. **Emergency Deploy**: Quick deployment after manual verification

### **Troubleshooting**:
1. **Test Failures**: Check uploaded artifacts for screenshots and logs
2. **Card Update Issues**: Review Python script output in workflow logs
3. **Deployment Problems**: Verify GitHub Pages settings and permissions

This unified workflow ensures your Asmrandle site stays current with the latest Magic cards while maintaining high quality through comprehensive testing before every deployment.