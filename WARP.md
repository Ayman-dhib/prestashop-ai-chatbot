# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

This is an **AI Chatbot module for PrestaShop** that provides intelligent customer service capabilities including FAQ support, product catalog queries, and order status checking. The module integrates with OpenAI's API for advanced AI features but works intelligently without it using database-driven responses and Google Translate.

## Architecture

### Core Components

**Main Module Class** (`ai_chatbot.php`)
- Handles module installation/uninstall, configuration, and PrestaShop integration
- Manages database tables for customer ratings system
- Handles file uploads for FAQ knowledge base
- Integrates with OpenAI Vector Store for enhanced FAQ processing

**Front Controller** (`controllers/front/api.php`) - **OPTIMIZED**
- Lightweight chatbot API endpoint with smart response logic
- Multi-language support using Google Translate library
- Database-driven product/category searches without hardcoded intents
- Simple FAQ matching with token-based scoring
- Secure order status verification

**Admin Controller** (`controllers/admin/AdminAiChatbotController.php`)
- Administrative interface for module configuration
- API key management and FAQ file uploads

**FAQ Embeddings Generator** (`faq_embed_admin.php`)
- Standalone script for building OpenAI embeddings from FAQ files
- Supports both TXT and PDF formats
- Generates JSON files with embeddings and BM25 IDF weights

### Key Features - OPTIMIZED

- **Smart Responses**: Dynamic responses based on database content, not hardcoded rules
- **FAQ Search**: Simple token-matching for FAQ responses in user's language
- **Order Status**: Secure order lookups with email verification
- **Product Search**: Real-time price/stock queries from database
- **Auto Translation**: Google Translate integration for French/English
- **Database Driven**: All responses generated from actual store data
- **Minimal Code**: Removed complex intent systems and rating features

### Database Schema - SIMPLIFIED

No custom tables needed. Uses existing PrestaShop tables:
- `ps_product` + `ps_product_lang`: Product information and pricing
- `ps_category` + `ps_category_lang`: Category data for smart searches
- `ps_orders` + `ps_order_state_lang`: Order status lookups
- `ps_customer`: Customer email verification

## Development Commands

### Module Installation/Management
```bash
# Install the module (via PrestaShop admin interface)
# Navigate to Modules > Module Manager > Upload module

# Manual composer dependency installation
composer install --no-dev --optimize-autoloader

# Update dependencies  
composer update

# Generate FAQ embeddings (requires OpenAI API key)
php faq_embed_admin.php uploads/faq.txt YOUR_API_KEY
```

### Testing & Development
```bash
# Test API endpoint directly
curl -X POST http://your-prestashop.com/modules/ai_chatbot/controllers/front/api.php \
  -H "Content-Type: application/json" \
  -d '{"message":"hello","context":{}}'

# Debug with diagnostic endpoint
curl http://your-prestashop.com/modules/ai_chatbot/controllers/front/api.php?diag=1

# Check debug logs
tail -f /var/www/html/var/logs/ai_chatbot_debug.log
```

### File Structure Operations
```bash
# Upload new FAQ files (place in uploads/ directory)
cp new_faq.txt uploads/
# Then regenerate embeddings via admin panel or CLI

# Backup configuration and ratings
mysqldump -u user -p prestashop ps_ai_chatbot_ratings > ratings_backup.sql
```

## Configuration

### Environment Setup
- PHP 8.1+ required
- Composer dependencies: GuzzleHTTP, Google Translate, Nyholm PSR-7
- PrestaShop 1.7+ compatibility
- Optional: OpenAI API key for enhanced AI features

### Key Configuration Values
- `AI_CHATBOT_API_KEY`: OpenAI API key (encrypted storage)
- `AI_CHATBOT_KB_FILE`: Path to FAQ knowledge base file
- `AI_CHATBOT_VECTOR_STORE_ID`: OpenAI Vector Store ID for FAQ embeddings

### FAQ File Format
```
Q: How do I return an item?
A: You can return items within 30 days of purchase.
Contact our support team for a return authorization.

Q: What payment methods do you accept?
A: We accept credit cards, PayPal, and bank transfers.
Processing time varies by method.
```

## Important Implementation Details

### Session Management
The module uses multiple PHP session keys for conversation state:
- `ai_chat_hist`: Chat history storage
- `ai_order_flow`: Order status inquiry progress  
- `ai_catalog_flow`: Product search workflow state
- `ai_rating_flow`: Customer rating collection state

### Translation Strategy
- Detects user language from message content and special characters
- Processes internally in English, translates responses back to user's language
- Uses language-specific FAQ files when available (faq_fr.txt)

### Security Measures
- Email verification required for order status queries
- SQL injection protection via PrestaShop's pSQL() function
- Input sanitization for all user-provided content
- Session-based CSRF protection

### Performance Optimizations
- FAQ embeddings cached in JSON files beside source documents
- Token caching to reduce repeated translation API calls
- Efficient BM25 implementation with pre-computed IDF weights
- Database query optimization with proper indexing

### Error Handling
- Graceful degradation when OpenAI API is unavailable
- Comprehensive logging to `/var/logs/ai_chatbot_debug.log`
- Fallback responses for all conversation scenarios

## OpenAI Integration

When API key is configured:
- Generates embeddings for semantic FAQ search
- Creates and manages Vector Store for document retrieval
- Can enable GPT-4 chat completions (currently disabled by default)
- Automatic FAQ embeddings regeneration on file upload

Without API key, the module functions with:
- BM25 keyword-based FAQ search
- Rule-based conversation management
- Local typo correction using Levenshtein distance
