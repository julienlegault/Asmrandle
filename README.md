# Asmrandle

A daily Magic: The Gathering card popularity prediction game that leverages real-time EDH/Commander deck statistics. Built as a full-stack web application demonstrating modern web development practices, API integration, and data processing techniques.

**Production URL**: [asmrandle.com](https://asmrandle.com)

## Project Overview

Asmrandle is a data-driven game that challenges users to predict card popularity in the Magic: The Gathering EDH/Commander format. Players compare two cards and select the one with higher inclusion rates based on [EDHRec](https://edhrec.com) deck statistics.

The application implements deterministic daily challenges using seeded random number generation, ensuring all users worldwide receive identical puzzles while maintaining fairness and consistency across different time zones.

## Technical Implementation

### Architecture

- **Frontend**: Vanilla JavaScript ES6+ with modern CSS3 features
- **Backend Logic**: Client-side game engine with deterministic seeding
- **Data Pipeline**: Python-based ETL process for card data aggregation
- **API Integration**: RESTful consumption of multiple third-party APIs
- **Testing**: Comprehensive end-to-end testing with Playwright

### Core Features

**Multi-Mode Gameplay**
- Daily Mode: Seeded deterministic card pairs
- Practice Mode: Random generation for unlimited play
- Hard Mode: Image-only identification challenge

### Data Processing Pipeline

The application features a sophisticated ETL pipeline implemented in Python that:

**Data Acquisition**
- Consumes Scryfall's bulk data API (150MB+ JSON datasets)
- Implements streaming JSON processing for memory efficiency
- Handles rate limiting and API error recovery

**Data Transformation**
- Filters 25,000+ Magic cards for EDH/Commander legality
- Cross-references card data with EDHRec popularity metrics
- Calculates statistical significance thresholds for meaningful comparisons
- Implements precision-based popularity rounding for balanced gameplay

**Quality Assurance**
- Validates card image availability and accessibility

## Development Workflow

### Build and Deployment

**Local Development**
```bash
# Serve application locally
python -m http.server 8000

# Update card database
python pullCards.py

# Run test suite
npx playwright test
```
