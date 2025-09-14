# AI Chatbot SQL Query Debugging Guide

## Problem Summary

Your working SQL query:
```sql
SELECT DISTINCT p.id_product, pl.name, p.price 
FROM ps_product p 
INNER JOIN ps_product_lang pl ON (pl.id_product = p.id_product AND pl.id_lang = 1) 
INNER JOIN ps_category_product cp ON cp.id_product = p.id_product 
WHERE p.active = 1 
  AND cp.id_category IN (10) -- pants category
  AND p.id_manufacturer IN (3) -- HA brand
  AND p.id_product IN ( 
    SELECT pa.id_product 
    FROM ps_product_attribute pa 
    INNER JOIN ps_product_attribute_combination pac 
        ON pac.id_product_attribute = pa.id_product_attribute 
    WHERE pac.id_attribute IN (14) -- Blue color attribute
    GROUP BY pa.id_product 
    HAVING COUNT(DISTINCT pac.id_attribute) = 1 
  ) 
ORDER BY p.date_upd DESC 
LIMIT 24;
```

**Works in database ✅ but chatbot returns wrong/no results ❌**

## Root Cause Analysis

The issue was likely in one or more of these areas:

1. **Query Building Logic** - The PHP code wasn't building the exact same SQL structure
2. **Parameter Handling** - Category IDs, brand IDs, or attribute IDs not passed correctly
3. **Filter Extraction** - The "blue ha" filter parsing wasn't working properly
4. **SQL Execution** - Query built correctly but executed differently

## Fixes Implemented

### 1. ✅ Enhanced SQL Query Logging
**File**: `controllers/front/api.php`

Added comprehensive logging to capture:
- Input parameters (categories, brands, attributes, language)
- Filter extraction results
- Complete generated SQL queries
- Query execution time and results
- Parameter validation

```php
// New debug logging method
private function debugLog(string $context, array $data = []): void
{
    // Logs to both readable and JSON formats
    // Location: /var/logs/ai_chatbot_debug.log
    // Location: /var/logs/ai_chatbot_detailed_debug.json
}
```

### 2. ✅ Fixed SQL Query Structure  
**File**: `controllers/front/api.php` (runProductQuery method)

**Before**: Complex attribute grouping logic that didn't match your working SQL
**After**: Exact same structure as your working SQL

```php
// FIXED: Now uses exact same structure as your working SQL
$attrCondition = "p.id_product IN (
    SELECT pa.id_product
    FROM " . _DB_PREFIX_ . "product_attribute pa
    INNER JOIN " . _DB_PREFIX_ . "product_attribute_combination pac
        ON pac.id_product_attribute = pa.id_product_attribute
    WHERE pac.id_attribute IN (" . implode(',', array_map('intval', $attrIds)) . ")
    GROUP BY pa.id_product
    HAVING COUNT(DISTINCT pac.id_attribute) = " . count($attrIds) . "
)";
```

### 3. ✅ Enhanced Parameter Validation
**File**: `controllers/front/api.php` (handleCatalogFilters method)

Added validation for:
- Category IDs are positive integers
- Brand IDs exist and are valid
- Attribute IDs are correctly extracted
- Language ID is valid

### 4. ✅ Debug Endpoint
**File**: `controllers/front/api.php` (handleDebugQueryRequest method)

New endpoint to test query building without widget interference:
```
GET /modules/ai_chatbot/controllers/front/api.php?debug_query=1&categories=10&brands=3&attributes=14&message=blue ha&lang=en
```

## Testing Tools Created

### 1. **PHP Test Script**
**File**: `tests/test_debug_queries.php`

Comprehensive test suite that:
- Tests the debug endpoint
- Simulates full chatbot conversation flow  
- Verifies database query directly
- Generates curl commands for manual testing

**Usage**:
```bash
php tests/test_debug_queries.php
```

### 2. **PowerShell Test Script**
**File**: `tests/test_debug_queries.ps1`

Windows-friendly version with same functionality.

**Usage**:
```powershell
.\tests\test_debug_queries.ps1
```

## Manual Testing Commands

### Test the Debug Endpoint
```bash
curl -X GET "http://localhost/prestashop/modules/ai_chatbot/controllers/front/api.php?debug_query=1&categories=10&brands=3&attributes=14&message=blue ha&lang=en"
```

### Test Full Chatbot Flow
```bash
# Step 1 - Ask for pants
curl -X POST "http://localhost/prestashop/modules/ai_chatbot/controllers/front/api.php" \
  -H "Content-Type: application/json" \
  -d '{"message":"do you sell pants?","context":{}}'

# Step 2 - Specify filters  
curl -X POST "http://localhost/prestashop/modules/ai_chatbot/controllers/front/api.php" \
  -H "Content-Type: application/json" \
  -d '{"message":"blue ha","context":{}}'
```

## Debugging Checklist

### Phase 1: Verify Data Exists
- [ ] Run your working SQL directly in the database
- [ ] Confirm it returns the expected jean product
- [ ] Note the exact IDs (category=10, brand=3, attribute=14)

### Phase 2: Test Filter Extraction
- [ ] Use debug endpoint to see if "blue ha" extracts correctly
- [ ] Check if brand ID 3 is found for "ha"  
- [ ] Check if attribute ID 14 is found for "blue"
- [ ] Verify category ID 10 is passed for "pants"

### Phase 3: Compare SQL Queries
- [ ] Check log files for the generated SQL
- [ ] Compare with your working SQL character by character
- [ ] Look for differences in WHERE conditions
- [ ] Verify JOIN structures match exactly

### Phase 4: Test Execution 
- [ ] Check query execution time in logs
- [ ] Verify result count matches database query
- [ ] Compare returned product IDs

### Phase 5: Frontend Integration
- [ ] Test with the actual widget in browser
- [ ] Check browser console for JavaScript errors
- [ ] Verify AJAX requests are reaching the backend

## Log File Locations

1. **Main Debug Log**: `/var/logs/ai_chatbot_debug.log`
   - Human-readable format
   - All major operations logged
   - SQL queries with parameters

2. **Detailed JSON Log**: `/var/logs/ai_chatbot_detailed_debug.json`  
   - Machine-readable format
   - Complete context data
   - Perfect for automated analysis

## Troubleshooting Common Issues

### Issue: "No products found"
**Likely Cause**: Filter extraction failing
**Check**: Debug endpoint filter_extraction section
**Fix**: Verify brand/attribute names in database match input

### Issue: "Wrong products returned" 
**Likely Cause**: SQL query structure mismatch
**Check**: Compare generated SQL with your working SQL
**Fix**: Ensure exact same JOIN and WHERE logic

### Issue: "Chatbot doesn't respond"
**Likely Cause**: PHP error or timeout
**Check**: PHP error logs and chatbot debug logs  
**Fix**: Check for syntax errors or database connection issues

### Issue: "Widget shows error"
**Likely Cause**: Frontend/backend communication problem
**Check**: Browser console and network tab
**Fix**: Verify API endpoint URL and CORS settings

## Configuration Update Required

**IMPORTANT**: Update the test script configuration:

In `tests/test_debug_queries.php` and `tests/test_debug_queries.ps1`:
```php
// UPDATE THESE TO MATCH YOUR SETUP
$prestashopBaseUrl = 'http://localhost/prestashop';  // UPDATE THIS

$testData = [
    'category_id' => 10,        // Your pants category ID
    'brand_id' => 3,            // Your HA brand ID  
    'attribute_id' => 14,       // Your blue color attribute ID
    'language_id' => 1          // Your default language ID
];
```

## Expected Results

After these fixes, the chatbot should:

1. **User**: "Do you sell pants?"
2. **Bot**: "Great! We have **pants**. To display products, please specify your criteria: *color*, *brand*, *size*, etc. For example: 'blue ha' for blue color from brand ha."
3. **User**: "blue ha"  
4. **Bot**: Returns your jean product ✅

## Next Steps

1. **Run the test scripts** to verify fixes work
2. **Check log files** for detailed debugging info
3. **Compare generated SQL** with your working query
4. **Test with actual widget** in browser
5. **Monitor performance** and fine-tune as needed

The debugging tools will help you pinpoint exactly where the discrepancy occurs between your working SQL and the chatbot's generated queries.
