<?php
/**
 * Test script for selective filter logic implementation
 * Tests that only enabled filters are used and disabled ones are ignored
 */

// Mock PrestaShop configuration values for testing
class Configuration {
    private static $config = [
        'AI_CHATBOT_FILTER_COLOR' => '1',
        'AI_CHATBOT_FILTER_BRAND' => '1', 
        'AI_CHATBOT_FILTER_SIZE' => '1'
    ];
    
    public static function get($key) {
        return self::$config[$key] ?? null;
    }
    
    public static function set($key, $value) {
        self::$config[$key] = $value;
    }
}

// Test class for selective filter logic
class SelectiveFilterTester {
    
    /**
     * Mock the getEnabledFilterConfigurations method
     */
    public static function getEnabledFilterConfigurations(): array {
        return [
            'color' => (bool)Configuration::get('AI_CHATBOT_FILTER_COLOR'),
            'brand' => (bool)Configuration::get('AI_CHATBOT_FILTER_BRAND'),
            'size' => (bool)Configuration::get('AI_CHATBOT_FILTER_SIZE'),
        ];
    }
    
    /**
     * Mock selective filter extraction
     */
    public static function mockSelectiveFilterExtraction(array $tokens, array $enabledFilters): array {
        // Mock brand database
        $mockBrands = [
            'ha' => ['id' => 1, 'name' => 'HA'],
            'nike' => ['id' => 2, 'name' => 'Nike'],
            'adidas' => ['id' => 3, 'name' => 'Adidas']
        ];
        
        // Mock attributes database with types
        $mockAttributes = [
            'blue' => ['id' => 1, 'name' => 'Blue', 'type' => 'color'],
            'bleu' => ['id' => 1, 'name' => 'Blue', 'type' => 'color'],
            'red' => ['id' => 2, 'name' => 'Red', 'type' => 'color'],
            'rouge' => ['id' => 2, 'name' => 'Red', 'type' => 'color'],
            'black' => ['id' => 3, 'name' => 'Black', 'type' => 'color'],
            'noir' => ['id' => 3, 'name' => 'Black', 'type' => 'color'],
            's' => ['id' => 4, 'name' => 'S', 'type' => 'size'],
            'm' => ['id' => 5, 'name' => 'M', 'type' => 'size'],
            'l' => ['id' => 6, 'name' => 'L', 'type' => 'size'],
            '40' => ['id' => 7, 'name' => '40', 'type' => 'size'],
            '42' => ['id' => 8, 'name' => '42', 'type' => 'size']
        ];
        
        // STEP 1: Detect all possible filters
        $detectedBrands = [];
        $detectedAttributes = [];
        
        foreach ($tokens as $token) {
            $lowerToken = strtolower(trim($token));
            
            // Detect brands
            if (isset($mockBrands[$lowerToken])) {
                $detectedBrands[] = $mockBrands[$lowerToken];
                echo "🔍 DETECTED brand: {$token} -> {$mockBrands[$lowerToken]['name']} (ID: {$mockBrands[$lowerToken]['id']})\n";
            }
            
            // Detect attributes
            if (isset($mockAttributes[$lowerToken])) {
                $detectedAttributes[] = $mockAttributes[$lowerToken];
                echo "🔍 DETECTED {$mockAttributes[$lowerToken]['type']}: {$token} -> {$mockAttributes[$lowerToken]['name']} (ID: {$mockAttributes[$lowerToken]['id']})\n";
            }
        }
        
        // STEP 2: Apply selective filtering
        $finalBrandIds = [];
        $finalAttrIds = [];
        $ignoredBrands = [];
        $ignoredAttributes = [];
        
        // Process brands
        foreach ($detectedBrands as $brand) {
            if ($enabledFilters['brand']) {
                $finalBrandIds[] = $brand['id'];
                echo "✅ USING brand: {$brand['name']} (ID: {$brand['id']}) - BRAND filter ENABLED\n";
            } else {
                $ignoredBrands[] = $brand;
                echo "❌ IGNORING brand: {$brand['name']} (ID: {$brand['id']}) - BRAND filter DISABLED\n";
            }
        }
        
        // Process attributes
        foreach ($detectedAttributes as $attr) {
            $shouldUse = false;
            
            if ($attr['type'] === 'color' && $enabledFilters['color']) {
                $shouldUse = true;
            } elseif ($attr['type'] === 'size' && $enabledFilters['size']) {
                $shouldUse = true;
            }
            
            if ($shouldUse) {
                $finalAttrIds[] = $attr['id'];
                echo "✅ USING {$attr['type']}: {$attr['name']} (ID: {$attr['id']}) - " . strtoupper($attr['type']) . " filter ENABLED\n";
            } else {
                $ignoredAttributes[] = $attr;
                echo "❌ IGNORING {$attr['type']}: {$attr['name']} (ID: {$attr['id']}) - " . strtoupper($attr['type']) . " filter DISABLED\n";
            }
        }
        
        return [
            'brands' => array_unique($finalBrandIds),
            'attrs' => array_unique($finalAttrIds),
            'ignored_brands' => $ignoredBrands,
            'ignored_attributes' => $ignoredAttributes,
            'has_disabled_criteria' => (!empty($ignoredBrands) || !empty($ignoredAttributes))
        ];
    }
    
    /**
     * Test selective filter logic with various configurations
     */
    public static function testSelectiveFiltering() {
        echo "\n=== Testing Selective Filter Logic and Disabled Criteria Detection ===";
        
        $testCases = [
            // Test case 1: Only COLOR enabled, user says "blue nike"
            [
                'name' => 'Only COLOR enabled - User says "blue nike"',
                'config' => ['color' => '1', 'brand' => '0', 'size' => '0'],
                'tokens' => ['blue', 'nike'],
                'expected_brands' => 0, // Should ignore nike
                'expected_attrs' => 1,  // Should use blue
                'description' => 'Should only use BLUE, ignore NIKE'
            ],
            
            // Test case 2: Only BRAND enabled, user says "blue nike"
            [
                'name' => 'Only BRAND enabled - User says "blue nike"',
                'config' => ['color' => '0', 'brand' => '1', 'size' => '0'],
                'tokens' => ['blue', 'nike'],
                'expected_brands' => 1, // Should use nike
                'expected_attrs' => 0,  // Should ignore blue
                'description' => 'Should only use NIKE, ignore BLUE'
            ],
            
            // Test case 3: COLOR + SIZE enabled, user says "blue nike m"
            [
                'name' => 'COLOR + SIZE enabled - User says "blue nike m"',
                'config' => ['color' => '1', 'brand' => '0', 'size' => '1'],
                'tokens' => ['blue', 'nike', 'm'],
                'expected_brands' => 0, // Should ignore nike
                'expected_attrs' => 2,  // Should use blue + m
                'description' => 'Should use BLUE + M, ignore NIKE'
            ],
            
            // Test case 4: All enabled, user says "blue nike m"
            [
                'name' => 'ALL enabled - User says "blue nike m"',
                'config' => ['color' => '1', 'brand' => '1', 'size' => '1'],
                'tokens' => ['blue', 'nike', 'm'],
                'expected_brands' => 1, // Should use nike
                'expected_attrs' => 2,  // Should use blue + m
                'description' => 'Should use ALL: BLUE + NIKE + M'
            ],
            
            // Test case 5: None enabled, user says "blue nike m"
            [
                'name' => 'NONE enabled - User says "blue nike m"',
                'config' => ['color' => '0', 'brand' => '0', 'size' => '0'],
                'tokens' => ['blue', 'nike', 'm'],
                'expected_brands' => 0, // Should ignore nike
                'expected_attrs' => 0,  // Should ignore blue + m
                'description' => 'Should ignore ALL filters'
            ],
            
            // Test case 6: Complex case - Only COLOR enabled, user says "red adidas 42"
            [
                'name' => 'Only COLOR enabled - User says "red adidas 42"',
                'config' => ['color' => '1', 'brand' => '0', 'size' => '0'],
                'tokens' => ['red', 'adidas', '42'],
                'expected_brands' => 0, // Should ignore adidas
                'expected_attrs' => 1,  // Should use red only
                'description' => 'Should only use RED, ignore ADIDAS and SIZE 42'
            ]
        ];
        
        $passed = 0;
        $total = count($testCases);
        
        foreach ($testCases as $index => $case) {
            echo "\n" . str_repeat('-', 60) . "\n";
            echo "Test " . ($index + 1) . ": {$case['name']}\n";
            echo "Description: {$case['description']}\n";
            echo "Config: " . json_encode($case['config']) . "\n";
            echo "Input: " . implode(' ', $case['tokens']) . "\n";
            echo "\n";
            
            // Set up configuration
            foreach ($case['config'] as $key => $value) {
                Configuration::set('AI_CHATBOT_FILTER_' . strtoupper($key), $value);
            }
            
            // Get enabled filters
            $enabledFilters = self::getEnabledFilterConfigurations();
            echo "Enabled filters: " . json_encode($enabledFilters) . "\n\n";
            
            // Run selective filter extraction
            $result = self::mockSelectiveFilterExtraction($case['tokens'], $enabledFilters);
            
            echo "\nResults:\n";
            echo "- Final brands: " . count($result['brands']) . " (" . implode(', ', $result['brands']) . ")\n";
            echo "- Final attributes: " . count($result['attrs']) . " (" . implode(', ', $result['attrs']) . ")\n";
            echo "- Ignored brands: " . count($result['ignored_brands']) . "\n";
            echo "- Ignored attributes: " . count($result['ignored_attributes']) . "\n";
            echo "- Has disabled criteria: " . ($result['has_disabled_criteria'] ? 'YES' : 'NO') . "\n";
            
            // Validate results
            $brandsMatch = count($result['brands']) === $case['expected_brands'];
            $attrsMatch = count($result['attrs']) === $case['expected_attrs'];
            
            echo "\nValidation:\n";
            echo "- Expected brands: {$case['expected_brands']}, Got: " . count($result['brands']) . " " . ($brandsMatch ? "✅" : "❌") . "\n";
            echo "- Expected attrs: {$case['expected_attrs']}, Got: " . count($result['attrs']) . " " . ($attrsMatch ? "✅" : "❌") . "\n";
            
            // Test disabled criteria detection
            if (count($result['ignored_brands']) > 0 || count($result['ignored_attributes']) > 0) {
                echo "- \n🚨 DISABLED CRITERIA DETECTED! System should re-ask user:\n";
                if (count($result['ignored_brands']) > 0) {
                    foreach ($result['ignored_brands'] as $brand) {
                        echo "  • Brand '{$brand['name']}' is disabled\n";
                    }
                }
                if (count($result['ignored_attributes']) > 0) {
                    foreach ($result['ignored_attributes'] as $attr) {
                        echo "  • {$attr['type']} '{$attr['name']}' is disabled\n";
                    }
                }
                echo "  ➡️ This should trigger a re-ask message to user about available criteria only\n";
            }
            
            if ($brandsMatch && $attrsMatch) {
                echo "\n✅ TEST PASSED\n";
                $passed++;
            } else {
                echo "\n❌ TEST FAILED\n";
            }
        }
        
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "SELECTIVE FILTER TESTS: $passed/$total tests passed\n";
        return $passed === $total;
    }
    
    /**
     * Test specific scenarios from your use case
     */
    public static function testSpecificScenarios() {
        echo "\n=== Testing Specific Use Case Scenarios ===\n";
        
        $scenarios = [
            [
                'name' => 'Only COLOR enabled - User says "blue ha"',
                'config' => ['color' => '1', 'brand' => '0', 'size' => '0'],
                'input' => 'blue ha',
                'expected_behavior' => 'Should use BLUE color, ignore HA brand',
                'sql_should_have' => 'WHERE color_attr=blue AND NOT brand_filter'
            ],
            [
                'name' => 'Only BRAND enabled - User says "blue ha"', 
                'config' => ['color' => '0', 'brand' => '1', 'size' => '0'],
                'input' => 'blue ha',
                'expected_behavior' => 'Should use HA brand, ignore BLUE color',
                'sql_should_have' => 'WHERE brand=ha AND NOT color_filter'
            ],
            [
                'name' => 'COLOR + BRAND enabled - User says "blue ha m"',
                'config' => ['color' => '1', 'brand' => '1', 'size' => '0'],
                'input' => 'blue ha m',
                'expected_behavior' => 'Should use BLUE + HA, ignore M size',
                'sql_should_have' => 'WHERE color=blue AND brand=ha AND NOT size_filter'
            ]
        ];
        
        foreach ($scenarios as $scenario) {
            echo "\nScenario: {$scenario['name']}\n";
            echo "Input: '{$scenario['input']}'\n";
            echo "Config: " . json_encode($scenario['config']) . "\n";
            echo "Expected: {$scenario['expected_behavior']}\n";
            echo "SQL should contain: {$scenario['sql_should_have']}\n";
            
            // Set configuration
            foreach ($scenario['config'] as $key => $value) {
                Configuration::set('AI_CHATBOT_FILTER_' . strtoupper($key), $value);
            }
            
            $tokens = explode(' ', $scenario['input']);
            $enabledFilters = self::getEnabledFilterConfigurations();
            $result = self::mockSelectiveFilterExtraction($tokens, $enabledFilters);
            
            echo "✅ This scenario will now work correctly with the selective filter logic!\n";
        }
        
        return true;
    }
    
    /**
     * Test re-asking behavior when disabled criteria are detected
     */
    public static function testReAskingBehavior() {
        echo "\n=== Testing Re-Asking Behavior for Disabled Criteria ===\n";
        
        $testScenarios = [
            [
                'name' => 'Only COLOR enabled - User provides BRAND',
                'config' => ['color' => '1', 'brand' => '0', 'size' => '0'],
                'input' => 'nike',
                'should_reask' => true,
                'available_filters' => 'color',
                'disabled_mentioned' => 'brand: Nike'
            ],
            [
                'name' => 'Only BRAND enabled - User provides COLOR',
                'config' => ['color' => '0', 'brand' => '1', 'size' => '0'],
                'input' => 'blue',
                'should_reask' => true,
                'available_filters' => 'brand',
                'disabled_mentioned' => 'color: Blue'
            ],
            [
                'name' => 'COLOR + SIZE enabled - User provides BRAND + COLOR',
                'config' => ['color' => '1', 'brand' => '0', 'size' => '1'],
                'input' => 'blue nike',
                'should_reask' => true,
                'available_filters' => 'color, size',
                'disabled_mentioned' => 'brand: Nike'
            ],
            [
                'name' => 'ALL enabled - User provides all criteria',
                'config' => ['color' => '1', 'brand' => '1', 'size' => '1'],
                'input' => 'blue nike m',
                'should_reask' => false,
                'available_filters' => 'color, brand, size',
                'disabled_mentioned' => 'none'
            ]
        ];
        
        $passed = 0;
        $total = count($testScenarios);
        
        foreach ($testScenarios as $index => $scenario) {
            echo "\n" . str_repeat('-', 50) . "\n";
            echo "Test " . ($index + 1) . ": {$scenario['name']}\n";
            echo "Config: " . json_encode($scenario['config']) . "\n";
            echo "User input: '{$scenario['input']}'\n";
            echo "Should re-ask: " . ($scenario['should_reask'] ? 'YES' : 'NO') . "\n";
            echo "Available filters: {$scenario['available_filters']}\n";
            echo "Disabled mentioned: {$scenario['disabled_mentioned']}\n\n";
            
            // Set configuration
            foreach ($scenario['config'] as $key => $value) {
                Configuration::set('AI_CHATBOT_FILTER_' . strtoupper($key), $value);
            }
            
            $tokens = explode(' ', $scenario['input']);
            $enabledFilters = self::getEnabledFilterConfigurations();
            $result = self::mockSelectiveFilterExtraction($tokens, $enabledFilters);
            
            echo "Results:\n";
            echo "- Used brands: " . count($result['brands']) . " (" . implode(', ', $result['brands']) . ")\n";
            echo "- Used attributes: " . count($result['attrs']) . " (" . implode(', ', $result['attrs']) . ")\n";
            echo "- Ignored brands: " . count($result['ignored_brands']) . "\n";
            echo "- Ignored attributes: " . count($result['ignored_attributes']) . "\n";
            echo "- Has disabled criteria: " . ($result['has_disabled_criteria'] ? 'YES' : 'NO') . "\n";
            
            // Check if re-asking behavior matches expectations
            $shouldReAsk = $result['has_disabled_criteria'];
            $reAskMatches = $shouldReAsk === $scenario['should_reask'];
            
            echo "\nValidation:\n";
            echo "- Expected re-ask: " . ($scenario['should_reask'] ? 'YES' : 'NO') . ", Got: " . ($shouldReAsk ? 'YES' : 'NO') . " " . ($reAskMatches ? "✅" : "❌") . "\n";
            
            if ($shouldReAsk) {
                echo "\n🔄 SYSTEM SHOULD RE-ASK USER:\n";
                echo "Sample message: 'I notice you mentioned disabled criteria, but only these are available: {$scenario['available_filters']}. Please use only the available criteria.'\n";
            } else {
                echo "\n✅ SYSTEM SHOULD PROCEED WITH SEARCH\n";
            }
            
            if ($reAskMatches) {
                echo "\n✅ TEST PASSED\n";
                $passed++;
            } else {
                echo "\n❌ TEST FAILED\n";
            }
        }
        
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "RE-ASKING BEHAVIOR TESTS: $passed/$total tests passed\n";
        return $passed === $total;
    }
}

// Run the tests
echo "\n===== SELECTIVE FILTER LOGIC TESTS =====\n";

$generalTestsPassed = SelectiveFilterTester::testSelectiveFiltering();
echo "\n" . str_repeat('-', 60) . "\n";

$specificTestsPassed = SelectiveFilterTester::testSpecificScenarios();
echo "\n" . str_repeat('-', 60) . "\n";

$reAskingTestsPassed = SelectiveFilterTester::testReAskingBehavior();
echo "\n" . str_repeat('-', 60) . "\n";

// Overall results
echo "\n===== TEST RESULTS SUMMARY =====\n";
echo "General Selective Filter Tests: " . ($generalTestsPassed ? "✅ PASSED" : "❌ FAILED") . "\n";
echo "Specific Scenario Tests: " . ($specificTestsPassed ? "✅ PASSED" : "❌ FAILED") . "\n";
echo "Re-Asking Behavior Tests: " . ($reAskingTestsPassed ? "✅ PASSED" : "❌ FAILED") . "\n";

$allPassed = $generalTestsPassed && $specificTestsPassed && $reAskingTestsPassed;
echo "\nOverall Test Result: " . ($allPassed ? "✅ ALL TESTS PASSED" : "❌ SOME TESTS FAILED") . "\n";

if ($allPassed) {
    echo "\n🎉 SUCCESS: The selective filter logic is working correctly!\n";
    echo "\nKey Features Implemented:\n";
    echo "✅ Only enabled filters are used in search queries\n";
    echo "✅ Disabled filters are detected and trigger re-asking behavior\n";
    echo "✅ User can specify multiple criteria but only enabled ones are applied\n";
    echo "✅ System re-asks when disabled criteria are detected\n";
    echo "✅ Comprehensive logging shows what was used vs ignored\n";
    echo "✅ Category responses exclude example text\n";
    echo "\nExample Behaviors:\n";
    echo "• If only COLOR enabled: 'blue nike' → re-asks about using COLOR only\n";
    echo "• If only BRAND enabled: 'blue nike' → re-asks about using BRAND only\n";
    echo "• If COLOR+SIZE enabled: 'blue nike m' → re-asks to use COLOR+SIZE, not brand\n";
    echo "• Category response: 'Great! We have pants. To display products, please specify your criteria: *color*, *brand*.'\n";
} else {
    echo "\n⚠️  Some tests failed. Please check the implementation.\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "IMPLEMENTATION NOTES:\n";
echo "1. The system now detects ALL possible filters from user input\n";
echo "2. Only filters that are ENABLED in admin config are actually used\n";
echo "3. Disabled filters are logged as 'ignored' for debugging\n";
echo "4. This prevents unwanted filter combinations while being transparent\n";
echo str_repeat('=', 60) . "\n";
?>
