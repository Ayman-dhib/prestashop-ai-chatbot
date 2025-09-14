# Selective Filter Feature - AI Chatbot

## Overview

The **Selective Filter Feature** ensures that only the filter types enabled in the admin configuration are used in product searches, even if the user provides multiple types of criteria in their input.

## How It Works

### Before (Without Selective Filtering)
- User says: "blue nike medium"
- System extracts: color=blue, brand=nike, size=medium
- **Problem**: Uses ALL detected filters regardless of admin settings

### After (With Selective Filtering)
- User says: "blue nike medium"
- System detects: color=blue, brand=nike, size=medium
- **But only uses filters that are ENABLED in admin configuration**

## Configuration Control

The feature respects these admin settings:
- `AI_CHATBOT_FILTER_COLOR` - Enable/disable color filtering
- `AI_CHATBOT_FILTER_BRAND` - Enable/disable brand filtering  
- `AI_CHATBOT_FILTER_SIZE` - Enable/disable size filtering

## Real-World Examples

### Scenario 1: Only COLOR Filter Enabled
**Configuration**: COLOR=enabled, BRAND=disabled, SIZE=disabled
- **User**: "blue nike medium"
- **System detects**: color=blue, brand=nike, size=medium
- **System uses**: ✅ color=blue only
- **System ignores**: ❌ brand=nike, ❌ size=medium
- **SQL result**: `WHERE color_attribute = 'blue'` (no brand or size filters)

### Scenario 2: Only BRAND Filter Enabled  
**Configuration**: COLOR=disabled, BRAND=enabled, SIZE=disabled
- **User**: "blue nike medium"
- **System detects**: color=blue, brand=nike, size=medium
- **System uses**: ✅ brand=nike only
- **System ignores**: ❌ color=blue, ❌ size=medium
- **SQL result**: `WHERE brand = 'nike'` (no color or size filters)

### Scenario 3: COLOR + BRAND Enabled, SIZE Disabled
**Configuration**: COLOR=enabled, BRAND=enabled, SIZE=disabled
- **User**: "blue nike medium"
- **System detects**: color=blue, brand=nike, size=medium
- **System uses**: ✅ color=blue, ✅ brand=nike
- **System ignores**: ❌ size=medium
- **SQL result**: `WHERE color='blue' AND brand='nike'` (no size filter)

## Benefits

### 1. **Precise Control**
Admin can control exactly which filter types are available to customers

### 2. **Prevents Unwanted Combinations**
Stops users from accidentally filtering by criteria you don't want to support

### 3. **Maintains Clean Search Results**
Only the filter types you've configured are applied to searches

### 4. **Transparent Operation**
System logs show what was used vs. what was ignored

## Technical Implementation

### Filter Detection Process
1. **Parse user input**: Extract all possible filter terms
2. **Detect filter types**: Identify colors, brands, sizes from database
3. **Apply selective filtering**: Only keep filters that are enabled
4. **Build SQL query**: Generate query using only enabled filter types

### Code Structure
```php
// Step 1: Detect ALL possible filters
$detectedBrands = [...];      // All brands found in input
$detectedAttributes = [...];  // All colors/sizes found in input

// Step 2: Apply selective filtering
$finalBrandIds = [];
$finalAttrIds = [];

foreach ($detectedBrands as $brand) {
    if ($enabledFilters['brand']) {
        $finalBrandIds[] = $brand['id'];  // ✅ USE
    } else {
        // ❌ IGNORE (but log for debugging)
    }
}

foreach ($detectedAttributes as $attr) {
    if ($attr['type'] === 'color' && $enabledFilters['color']) {
        $finalAttrIds[] = $attr['id'];    // ✅ USE
    } else {
        // ❌ IGNORE (but log for debugging)
    }
}
```

## Logging & Debugging

The system provides comprehensive logging:

### Main Log (`/var/logs/ai_chatbot_debug.log`)
```
SELECTIVE FILTER: USING brand: Nike (ID: 2) - ENABLED
SELECTIVE FILTER: IGNORING color: Blue (ID: 1) - DISABLED
```

### Detailed Log (`/var/logs/ai_chatbot_detailed_debug.json`)
```json
{
  "context": "SELECTIVE FILTER RESULTS",
  "data": {
    "enabled_filters": {"color": false, "brand": true, "size": false},
    "detected_brands": [{"id": 2, "name": "Nike"}],
    "detected_attributes": [{"id": 1, "name": "Blue", "type": "color"}],
    "final_brand_ids": [2],
    "final_attribute_ids": [],
    "ignored_brands": [],
    "ignored_attributes": [{"id": 1, "name": "Blue", "type": "color"}]
  }
}
```

## Testing

### Test Script
Run the comprehensive test: `tests/test_selective_filters.php`

### Debug Endpoint
Test selective filtering directly:
```bash
curl "http://localhost/prestashop/modules/ai_chatbot/controllers/front/api.php?debug_query=1&message=blue nike medium&lang=en"
```

### Manual Testing
1. Configure filter settings in admin (enable only specific filter types)
2. Test with user input containing multiple filter types
3. Verify only enabled filters are used in search results
4. Check logs to see what was ignored

## Use Cases

### Fashion Store
- **Enable**: COLOR, SIZE
- **Disable**: BRAND
- **Result**: Customers can filter by color and size, but not by brand
- **User says**: "red nike large" → Uses RED + LARGE, ignores NIKE

### Electronics Store  
- **Enable**: BRAND
- **Disable**: COLOR, SIZE
- **Result**: Customers can only filter by brand/manufacturer
- **User says**: "blue apple 64gb" → Uses APPLE, ignores BLUE and 64GB

### Jewelry Store
- **Enable**: COLOR (for metal type)
- **Disable**: BRAND, SIZE  
- **Result**: Customers can filter by metal color only
- **User says**: "gold tiffany small" → Uses GOLD, ignores TIFFANY and SMALL

## Configuration Examples

### Enable All Filters (Default)
```php
Configuration::updateValue('AI_CHATBOT_FILTER_COLOR', '1');
Configuration::updateValue('AI_CHATBOT_FILTER_BRAND', '1'); 
Configuration::updateValue('AI_CHATBOT_FILTER_SIZE', '1');
```

### Color-Only Filtering
```php
Configuration::updateValue('AI_CHATBOT_FILTER_COLOR', '1');
Configuration::updateValue('AI_CHATBOT_FILTER_BRAND', '0'); 
Configuration::updateValue('AI_CHATBOT_FILTER_SIZE', '0');
```

### Brand-Only Filtering  
```php
Configuration::updateValue('AI_CHATBOT_FILTER_COLOR', '0');
Configuration::updateValue('AI_CHATBOT_FILTER_BRAND', '1'); 
Configuration::updateValue('AI_CHATBOT_FILTER_SIZE', '0');
```

## Migration Notes

### Existing Installations
- Feature is **backward compatible**
- If all filter settings are enabled (default), behavior remains unchanged
- No database changes required
- Existing chatbot conversations continue to work

### New Installations
- All filters enabled by default
- Admin can customize which filter types to enable
- Selective filtering applies immediately after configuration change

## Troubleshooting

### "No products found" after enabling selective filtering
**Cause**: User is providing filter types that are now disabled
**Solution**: Check which filters are enabled and guide users accordingly

### Filters seem to be ignored
**Check**: Admin configuration settings
**Verify**: Log files show which filters were detected vs. used

### Unexpected search results
**Debug**: Use the debug endpoint to see exactly which filters are being applied
**Log check**: Review `/var/logs/ai_chatbot_debug.log` for selective filtering decisions

## Advanced Configuration

### Custom Filter Behavior
You can extend the selective filtering logic by modifying the `extractFiltersFromMessage` method to add:
- Custom filter types
- Filter priority rules
- Business-specific filter combinations

### API Integration
The debug endpoint provides full transparency into the selective filtering process for API integrations and automated testing.

This feature gives you complete control over how customers can filter products while maintaining a seamless user experience.
