# GitHub Actions Workflow for Asmrandle Card List Updates

This workflow automatically updates the Magic: the Gathering card list using the latest data from Scryfall.

## When it runs:

1. **On Push to Main**: When commits are pushed directly to the main branch
2. **On Pull Request Merge**: When pull requests are merged into main
3. **Manual Trigger**: Can be run manually from the GitHub Actions tab
4. **48-Hour Check**: Only runs if the card list hasn't been updated in the past 48 hours

## What it does:

1. **Environment Setup**: 
   - Sets up Python 3.11
   - Installs required dependencies (requests)

2. **Smart Timing Check**:
   - Checks the last modification time of `formatted_card_list.js`
   - Skips execution if updated within the last 48 hours
   - Always runs if the file doesn't exist

3. **Card List Update**:
   - Downloads latest oracle cards from Scryfall
   - Runs the `pullCards.py` script
   - Only checks new cards (skips existing ones)

4. **Automatic Commit**:
   - Commits any changes to `formatted_card_list.js`
   - Uses descriptive commit messages
   - Only commits if there are actual changes

## Benefits:

- ⚡ **Efficient**: Only runs when needed (48-hour cooldown)
- 🔄 **Automatic**: No manual intervention required
- 🎯 **Smart**: Only commits when new cards are found
- 📋 **Comprehensive**: Handles all edge cases and errors
- 🛡️ **Safe**: Won't spam commits or waste resources

## Manual Execution:

You can manually trigger the workflow from the GitHub Actions tab if needed, even within the 48-hour window.

## Monitoring:

Check the Actions tab in your GitHub repository to see the workflow results and any potential issues.