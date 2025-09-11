# AI Chatbot Promotion Enhancement

## Overview
Enhanced the AI Chatbot module to better handle promotion-related queries in multiple languages, providing comprehensive product information with pricing, discounts, and stock details.

## Key Enhancements

### 1. Improved Promotion Query Detection
- **Enhanced `isPromotionQuery()` method** to detect more variations of promotion questions
- **Added pattern matching** for complex question structures
- **Expanded keyword lists** for both English and French

#### New Detection Patterns:
**English:**
- "is there any product on promotion"
- "are there any products on sale"
- "what items are on sale"
- "which products are discounted"
- "any promotions", "any deals"
- "products on promotion", "items on sale"

**French:**
- "y a-t-il des produits en promotion"
- "des produits en soldes"
- "quels produits sont en promo"
- "articles en promotion"
- "des promotions", "des offres"
- "produits soldés", "prix réduits"

#### Regex Pattern Matching:
- English: `/\b(is there|are there|do you have|got any).*(promotion|promo|sale|discount|deal)/i`
- French: `/\b(y a-t-il|avez-vous|il y a).*(promotion|promo|soldes|remise)/i`

### 2. Enhanced Promotion Response Handler
- **Complete rewrite of `handlePromotionQuery()` method**
- **Comprehensive product information** including:
  - Product name with clickable links
  - Original price (struck through) vs discounted price
  - Discount percentage/amount clearly displayed
  - Real-time stock information
  - Proper currency formatting

#### Response Format Example:
```
🎉 Here are the products currently on sale:

• [Product Name](url)
  Price: ~~$99.99~~ **$79.99** (-20%)
  Stock: 15 in stock

• [Another Product](url)
  Price: ~~€49.99~~ **€39.99** (-€10.00)
  Stock: Out of stock

💡 Click on any product to see more details and add it to your cart!
```

### 3. Comprehensive Language Support
- **Localized messages** for all promotion-related responses
- **Automatic translation** integration using the chatbot's `autoTranslateResponse()` method
- **Context-aware translations** with 'promotion' context for better accuracy

#### Localized Messages:
- **No promotions available**: Friendly messages in both languages
- **Error handling**: Graceful error messages when promotion data can't be retrieved
- **Stock indicators**: "in stock" / "out of stock" properly translated
- **Headers and footers**: Encouraging call-to-action messages

### 4. Database Integration & Performance
- **Enhanced SQL queries** to fetch:
  - Product names in correct language
  - Current promotion details (percentage/amount)
  - Manufacturing information
  - Stock levels
- **Error handling** for individual products to prevent complete failure
- **Limited results** (10 products max) for optimal performance and readability

### 5. Integration with Existing System
- **Seamless integration** with existing chatbot flow
- **Proper session management** and logging
- **Consistent with other handlers** (order, catalog, FAQ, etc.)
- **Translation system compatibility**

## Technical Implementation Details

### Enhanced Query Detection Logic:
1. **Pattern Matching First**: Check regex patterns for complex questions
2. **Keyword Fallback**: Check individual keywords if patterns don't match
3. **Multi-language Support**: Handles EN/FR simultaneously

### Promotion Data Retrieval:
1. **Active Promotions Only**: Filters by active status and date ranges
2. **Price Calculation**: Uses PrestaShop's `Product::getPriceStatic()` for accurate pricing
3. **Stock Information**: Real-time stock using `StockAvailable::getQuantityAvailableByProduct()`
4. **Proper Formatting**: Currency formatting using PrestaShop's `Tools::displayPrice()`

### Response Formatting:
1. **HTML Links**: Clickable product links for easy navigation
2. **Price Presentation**: Strikethrough original price, bold discounted price
3. **Visual Indicators**: Emojis and formatting for better UX
4. **Helpful Footer**: Encourages user action

## User Experience Improvements

### Multi-language Query Support:
- Users can ask in English or French naturally
- Questions are detected regardless of exact phrasing
- Responses are provided in the user's detected language

### Comprehensive Product Information:
- Users get complete pricing information
- Stock availability prevents disappointment
- Direct links enable quick purchasing

### Professional Presentation:
- Clean, structured responses
- Visual hierarchy with proper formatting
- Encouraging and helpful tone

## Testing Coverage

The enhancement handles various question formats:
- Direct questions: "are there any products on sale?"
- Indirect questions: "what items are discounted?"
- Simple queries: "promotions", "soldes"
- Complex patterns: "y a-t-il des produits en promotion?"

## Future Enhancements

Potential improvements could include:
- Category-specific promotion queries
- Price range filtering for promotions
- Time-limited promotion countdowns
- Personalized promotion recommendations
