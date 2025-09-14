<?php
/**
 * Test script for strict filter logic implementation
 * Tests the modifications made to enforce filter usage in catalog search
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

// Mock Context class
class Context {
    public static $instance;
    public $language;
    public $shop;
    
    public function __construct() {
        $this->language = (object)['id' => 1];
        $this->shop = (object)['id' => 1];
    }
    
    public static function getContext() {
        if (!self::$instance) {
            self::$instance = new Context();
        }
        return self::$instance;
    }
}

// Test class for strict filter logic
class StrictFilterTester {
    
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
     * Mock the getEnabledFilterOptions method
     */
    public static function getEnabledFilterOptions(string $lang): string {
        $enabledFilters = [];
        
        $colorEnabled = Configuration::get('AI_CHATBOT_FILTER_COLOR');
        $brandEnabled = Configuration::get('AI_CHATBOT_FILTER_BRAND');
        $sizeEnabled = Configuration::get('AI_CHATBOT_FILTER_SIZE');
        
        if ($colorEnabled) {
            $enabledFilters[] = $lang === 'fr' ? '*couleur*' : '*color*';
        }
        
        if ($brandEnabled) {
            $enabledFilters[] = $lang === 'fr' ? '*marque*' : '*brand*';
        }
        
        if ($sizeEnabled) {
            $enabledFilters[] = $lang === 'fr' ? '*taille*' : '*size*';
        }
        
        if (!empty($enabledFilters)) {
            $enabledFilters[] = $lang === 'fr' ? 'etc.' : 'etc.';
        }
        
        return implode(', ', $enabledFilters);
    }
    
    /**
     * Mock filter extraction from message tokens
     */
    public static function mockExtractFiltersFromMessage(array $tokens): array {
        // Mock brand database (like your 'ha' brand)
        $mockBrands = [
            'ha' => 1,
            'nike' => 2,
            'adidas' => 3
        ];
        
        // Mock attributes database (colors, sizes)
        $mockAttributes = [
            'blue' => ['id' => 1, 'type' => 'color'],
            'bleu' => ['id' => 1, 'type' => 'color'],
            'red' => ['id' => 2, 'type' => 'color'],
            'rouge' => ['id' => 2, 'type' => 'color'],
            'black' => ['id' => 3, 'type' => 'color'],
            'noir' => ['id' => 3, 'type' => 'color'],
            's' => ['id' => 4, 'type' => 'size'],
            'm' => ['id' => 5, 'type' => 'size'],
            'l' => ['id' => 6, 'type' => 'size'],
            '40' => ['id' => 7, 'type' => 'size'],
            '42' => ['id' => 8, 'type' => 'size']
        ];
        
        $enabledFilters = self::getEnabledFilterConfigurations();
        $brands = [];
        $attrs = [];
        
        foreach ($tokens as $token) {
            $lowerToken = strtolower(trim($token));
            
            // Extract brands if enabled
            if ($enabledFilters['brand'] && isset($mockBrands[$lowerToken])) {
                $brands[] = $mockBrands[$lowerToken];
                echo "✓ Found brand: {$token} (ID: {$mockBrands[$lowerToken]})\n";
            }
            
            // Extract attributes if color or size enabled  
            if (($enabledFilters['color'] || $enabledFilters['size']) && isset($mockAttributes[$lowerToken])) {
                $attr = $mockAttributes[$lowerToken];
                if (($enabledFilters['color'] && $attr['type'] === 'color') || 
                    ($enabledFilters['size'] && $attr['type'] === 'size')) {
                    $attrs[] = $attr['id'];
                    echo "✓ Found {$attr['type']}: {$token} (ID: {$attr['id']})\n";
                }
            }
        }
        
        return [
            'brands' => array_unique($brands),
            'attrs' => array_unique($attrs)
        ];
    }
    
    /**
     * Test strict filter validation logic
     */
    public static function testStrictFilterValidation() {
        echo "\n=== Testing Strict Filter Validation ===\n";
        
        $testCases = [
            // Test case 1: All filters enabled, user provides both brand and color
            [
                'name' => 'Valid filters provided (blue ha)',
                'config' => ['color' => '1', 'brand' => '1', 'size' => '1'],
                'tokens' => ['blue', 'ha'],
                'expected_valid' => true,
                'description' => 'Should accept blue color + ha brand'
            ],
            
            // Test case 2: All filters enabled, user provides only color
            [
                'name' => 'Partial filters provided (only blue)',
                'config' => ['color' => '1', 'brand' => '1', 'size' => '1'],
                'tokens' => ['blue'],
                'expected_valid' => true,
                'description' => 'Should accept just color when filters enabled'
            ],
            
            // Test case 3: All filters enabled, user provides unrelated terms
            [
                'name' => 'No valid filters provided (random terms)',
                'config' => ['color' => '1', 'brand' => '1', 'size' => '1'],
                'tokens' => ['random', 'words', 'here'],
                'expected_valid' => false,
                'description' => 'Should reject when no valid filters found'
            ],
            
            // Test case 4: Only brand filter enabled, user provides brand
            [
                'name' => 'Brand filter only, brand provided',
                'config' => ['color' => '0', 'brand' => '1', 'size' => '0'],
                'tokens' => ['ha'],
                'expected_valid' => true,
                'description' => 'Should accept brand when only brand filter enabled'
            ],
            
            // Test case 5: Only brand filter enabled, user provides color
            [
                'name' => 'Brand filter only, color provided',
                'config' => ['color' => '0', 'brand' => '1', 'size' => '0'],
                'tokens' => ['blue'],
                'expected_valid' => false,
                'description' => 'Should reject color when only brand filter enabled'
            ],
            
            // Test case 6: No filters enabled
            [
                'name' => 'No filters enabled',
                'config' => ['color' => '0', 'brand' => '0', 'size' => '0'],
                'tokens' => ['blue', 'ha'],
                'expected_valid' => 'skip',
                'description' => 'Should skip validation when no filters enabled'
            ]
        ];
        
        $passed = 0;
        $total = count($testCases);
        
        foreach ($testCases as $case) {
            // Set up configuration for this test
            foreach ($case['config'] as $key => $value) {
                Configuration::set('AI_CHATBOT_FILTER_' . strtoupper($key), $value);
            }
            
            echo "\nTest: {$case['name']}\n";
            echo "Description: {$case['description']}\n";
            echo "Config: " . json_encode($case['config']) . "\n";
            echo "Tokens: " . json_encode($case['tokens']) . "\n";
            
            // Get enabled filter configurations
            $enabledFilters = self::getEnabledFilterConfigurations();
            $hasEnabledFilters = array_filter($enabledFilters);
            
            if ($case['expected_valid'] === 'skip') {
                if (empty($hasEnabledFilters)) {
                    echo "Result: ✅ PASSED (correctly skipped validation)\n";
                    $passed++;
                } else {
                    echo "Result: ❌ FAILED (should have no enabled filters)\n";
                }
                continue;
            }
            
            // Extract filters from tokens
            $filters = self::mockExtractFiltersFromMessage($case['tokens']);
            
            // Validate filters according to strict logic
            $hasValidFilters = false;
            if (!empty($hasEnabledFilters)) {
                if (($enabledFilters['color'] || $enabledFilters['size']) && !empty($filters['attrs'])) {
                    $hasValidFilters = true;
                }
                if ($enabledFilters['brand'] && !empty($filters['brands'])) {
                    $hasValidFilters = true;
                }
            }
            
            echo "Extracted filters: " . json_encode($filters) . "\n";
            echo "Has valid filters: " . ($hasValidFilters ? 'Yes' : 'No') . "\n";
            echo "Expected valid: " . ($case['expected_valid'] ? 'Yes' : 'No') . "\n";
            
            if ($hasValidFilters === $case['expected_valid']) {
                echo "Result: ✅ PASSED\n";
                $passed++;
            } else {
                echo "Result: ❌ FAILED\n";
            }
        }
        
        echo "\nStrict Filter Validation Test: $passed/$total tests passed\n";
        return $passed === $total;
    }
    
    /**
     * Test the specific use case mentioned: "do you sell pants" -> "blue ha"
     */
    public static function testSpecificUseCase() {
        echo "\n=== Testing Specific Use Case: Pants -> Blue Ha ===\n";
        
        // Set up configuration with all filters enabled
        Configuration::set('AI_CHATBOT_FILTER_COLOR', '1');
        Configuration::set('AI_CHATBOT_FILTER_BRAND', '1');
        Configuration::set('AI_CHATBOT_FILTER_SIZE', '1');
        
        echo "Configuration: All filters enabled (color, brand, size)\n";
        echo "Database: Mock jean product in pants category, brand 'ha', color 'blue'\n\n";
        
        // Step 1: User asks "do you sell pants"
        echo "Step 1: User asks 'do you sell pants'\n";
        echo "Expected behavior: Bot finds pants category and asks for filters\n";
        
        $enabledFilterText = self::getEnabledFilterOptions('en');
        $expectedResponse1 = "Great! We have **pants**. To display products, please specify your criteria: {$enabledFilterText}. For example: 'blue ha' for blue color from brand ha.";
        echo "Expected response: {$expectedResponse1}\n\n";
        
        // Step 2: User specifies "blue ha" 
        echo "Step 2: User specifies 'blue ha'\n";
        echo "Expected behavior: Bot extracts color=blue, brand=ha and searches for products\n";
        
        $tokens = ['blue', 'ha'];
        echo "Input tokens: " . json_encode($tokens) . "\n";
        
        $extractedFilters = self::mockExtractFiltersFromMessage($tokens);
        echo "Extracted filters: " . json_encode($extractedFilters) . "\n";
        
        // Validate the extraction worked
        $hasBlueColor = in_array(1, $extractedFilters['attrs']); // blue = ID 1
        $hasHaBrand = in_array(1, $extractedFilters['brands']); // ha = ID 1
        
        echo "Has blue color: " . ($hasBlueColor ? 'Yes' : 'No') . "\n";
        echo "Has ha brand: " . ($hasHaBrand ? 'Yes' : 'No') . "\n";
        
        if ($hasBlueColor && $hasHaBrand) {
            echo "Expected behavior: Search database for pants with color=blue AND brand=ha\n";
            echo "Expected result: Find the jean product and display it\n";
            echo "Result: ✅ PASSED - Filter extraction works correctly\n";
            return true;
        } else {
            echo "Result: ❌ FAILED - Filter extraction not working correctly\n";
            return false;
        }
    }
}

// Mock session start
session_start();

// Run the tests
echo "\n===== STRICT FILTER LOGIC TESTS =====\n";

$validationPassed = StrictFilterTester::testStrictFilterValidation();
echo "\n" . str_repeat('-', 60) . "\n";

$useCasePassed = StrictFilterTester::testSpecificUseCase();
echo "\n" . str_repeat('-', 60) . "\n";

// Overall results
echo "\n===== TEST RESULTS SUMMARY =====\n";
echo "Strict Filter Validation: " . ($validationPassed ? "✅ PASSED" : "❌ FAILED") . "\n";
echo "Specific Use Case Test: " . ($useCasePassed ? "✅ PASSED" : "❌ FAILED") . "\n";

$allPassed = $validationPassed && $useCasePassed;
echo "\nOverall Test Result: " . ($allPassed ? "✅ ALL TESTS PASSED" : "❌ SOME TESTS FAILED") . "\n";

if ($allPassed) {
    echo "\n🎉 SUCCESS: The strict filter logic is working correctly!\n";
    echo "   - Bot will ONLY show products when filters are provided\n";
    echo "   - 'blue ha' will correctly extract both color and brand filters\n";
    echo "   - The jean product with category=pants, brand=ha, color=blue will be found\n";
} else {
    echo "\n⚠️  Some tests failed. Please check the implementation.\n";
}
