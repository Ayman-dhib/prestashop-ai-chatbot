<?php
/**
 * Test script to simulate chatbot requests and debug SQL query issues
 * This script helps isolate whether the problem is in:
 * (a) Query building
 * (b) Query execution 
 * (c) Data return to frontend
 * (d) Widget/browser issues
 */

// Configuration - UPDATE THESE TO MATCH YOUR SETUP
$prestashopBaseUrl = 'http://localhost/prestashop';  // UPDATE THIS
$chatbotApiUrl = $prestashopBaseUrl . '/modules/ai_chatbot/controllers/front/api.php';

// Your test data from the working SQL query
$testData = [
    'category_id' => 10,        // pants category
    'brand_id' => 3,            // HA brand  
    'attribute_id' => 14,       // Blue color attribute
    'language_id' => 1          // Default language
];

echo "=== AI CHATBOT SQL QUERY DEBUGGING TESTS ===\n\n";

/**
 * Test 1: Debug Query Endpoint
 * Tests the query building directly without going through the full chatbot flow
 */
function testDebugQueryEndpoint($chatbotApiUrl, $testData) {
    echo "TEST 1: Debug Query Endpoint\n";
    echo str_repeat('-', 40) . "\n";
    
    $debugUrl = $chatbotApiUrl . '?' . http_build_query([
        'debug_query' => '1',
        'categories' => $testData['category_id'],
        'brands' => $testData['brand_id'], 
        'attributes' => $testData['attribute_id'],
        'message' => 'blue ha',
        'lang' => 'en'
    ]);
    
    echo "Debug URL: $debugUrl\n";
    
    // Use curl to make the request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $debugUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: ChatbotDebugger/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP Status: $httpCode\n";
    
    if ($error) {
        echo "❌ CURL Error: $error\n";
        return false;
    }
    
    if ($httpCode !== 200) {
        echo "❌ HTTP Error: $httpCode\n";
        echo "Response: $response\n";
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        echo "❌ Invalid JSON response\n";
        echo "Raw response: $response\n";
        return false;
    }
    
    echo "✅ Response received successfully\n";
    
    // Display key debug information
    if (isset($data['debug_info'])) {
        $debug = $data['debug_info'];
        
        echo "\n📊 Debug Information:\n";
        echo "Filter Extraction:\n";
        echo "  - Extracted Brands: " . json_encode($debug['filter_extraction']['extracted_brands'] ?? []) . "\n";
        echo "  - Extracted Attributes: " . json_encode($debug['filter_extraction']['extracted_attributes'] ?? []) . "\n";
        
        echo "\nQuery Parameters:\n";
        echo "  - Category IDs: " . json_encode($debug['final_query_params']['category_ids'] ?? []) . "\n";
        echo "  - Brand IDs: " . json_encode($debug['final_query_params']['brand_ids'] ?? []) . "\n";
        echo "  - Attribute IDs: " . json_encode($debug['final_query_params']['attribute_ids'] ?? []) . "\n";
        
        echo "\nQuery Results:\n";
        echo "  - Products Found: " . ($debug['query_results']['found_products'] ?? 0) . "\n";
        
        if (!empty($debug['query_results']['products'])) {
            echo "  - Sample Products:\n";
            foreach ($debug['query_results']['products'] as $product) {
                echo "    * {$product['name']} (ID: {$product['id_product']})\n";
            }
        }
        
        echo "\nExpected SQL Query:\n";
        echo $debug['expected_working_sql'] ?? 'Not available';
        echo "\n";
    }
    
    return true;
}

/**
 * Test 2: Full Chatbot Flow Simulation
 * Tests the complete chatbot conversation flow
 */
function testFullChatbotFlow($chatbotApiUrl) {
    echo "\n\nTEST 2: Full Chatbot Flow Simulation\n";
    echo str_repeat('-', 40) . "\n";
    
    // Step 1: Ask for pants
    echo "Step 1: User asks 'do you sell pants?'\n";
    $response1 = makeChatbotRequest($chatbotApiUrl, 'do you sell pants?');
    
    if (!$response1) {
        echo "❌ Step 1 failed\n";
        return false;
    }
    
    echo "Bot Response: " . substr($response1['reply'], 0, 100) . "...\n";
    
    // Step 2: Provide filters
    echo "\nStep 2: User specifies 'blue ha'\n";
    $response2 = makeChatbotRequest($chatbotApiUrl, 'blue ha');
    
    if (!$response2) {
        echo "❌ Step 2 failed\n";
        return false;
    }
    
    echo "Bot Response: " . substr($response2['reply'], 0, 100) . "...\n";
    
    // Check if products were found
    if (strpos($response2['reply'], 'found') !== false || strpos($response2['reply'], 'results') !== false) {
        echo "✅ Products appear to be found in the response\n";
    } else {
        echo "⚠️ No products seem to be found - this may be the issue\n";
    }
    
    return true;
}

/**
 * Make a chatbot API request
 */
function makeChatbotRequest($apiUrl, $message, $context = []) {
    $payload = [
        'message' => $message,
        'context' => $context
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: ChatbotDebugger/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "CURL Error: $error\n";
        return false;
    }
    
    if ($httpCode !== 200) {
        echo "HTTP Error: $httpCode\n";
        echo "Response: $response\n";
        return false;
    }
    
    return json_decode($response, true);
}

/**
 * Test 3: Direct Database Query Verification
 * Runs the exact SQL query you provided to verify data exists
 */
function testDirectDatabaseQuery($testData) {
    echo "\n\nTEST 3: Database Query Verification\n";
    echo str_repeat('-', 40) . "\n";
    
    echo "⚠️  Note: This test requires database access and PrestaShop environment.\n";
    echo "To run this test manually, execute this SQL in your database:\n\n";
    
    $sql = "SELECT DISTINCT p.id_product, pl.name, p.price 
FROM ps_product p 
INNER JOIN ps_product_lang pl ON (pl.id_product = p.id_product AND pl.id_lang = {$testData['language_id']}) 
INNER JOIN ps_category_product cp ON cp.id_product = p.id_product 
WHERE p.active = 1 
  AND cp.id_category IN ({$testData['category_id']}) -- pants category
  AND p.id_manufacturer IN ({$testData['brand_id']}) -- HA brand
  AND p.id_product IN ( 
    SELECT pa.id_product 
    FROM ps_product_attribute pa 
    INNER JOIN ps_product_attribute_combination pac 
        ON pac.id_product_attribute = pa.id_product_attribute 
    WHERE pac.id_attribute IN ({$testData['attribute_id']}) -- Blue color attribute
    GROUP BY pa.id_product 
    HAVING COUNT(DISTINCT pac.id_attribute) = 1 
  ) 
ORDER BY p.date_upd DESC 
LIMIT 24;";
    
    echo $sql . "\n";
    echo "\nIf this query returns results, the data exists and the problem is in the PHP code.\n";
    echo "If this query returns no results, check your test data IDs.\n";
}

/**
 * Generate curl commands for manual testing
 */
function generateCurlCommands($chatbotApiUrl, $testData) {
    echo "\n\nCURL COMMANDS FOR MANUAL TESTING:\n";
    echo str_repeat('=', 50) . "\n";
    
    // Debug endpoint curl
    $debugUrl = $chatbotApiUrl . '?' . http_build_query([
        'debug_query' => '1',
        'categories' => $testData['category_id'],
        'brands' => $testData['brand_id'], 
        'attributes' => $testData['attribute_id'],
        'message' => 'blue ha',
        'lang' => 'en'
    ]);
    
    echo "1. Debug Query Endpoint:\n";
    echo "curl -X GET \"$debugUrl\"\n\n";
    
    // Full chatbot flow curls
    echo "2. Full Chatbot Flow:\n";
    echo "# Step 1 - Ask for pants:\n";
    echo "curl -X POST \"$chatbotApiUrl\" \\\n";
    echo "  -H \"Content-Type: application/json\" \\\n";
    echo "  -d '{\"message\":\"do you sell pants?\",\"context\":{}}'\n\n";
    
    echo "# Step 2 - Specify filters:\n";
    echo "curl -X POST \"$chatbotApiUrl\" \\\n";
    echo "  -H \"Content-Type: application/json\" \\\n";
    echo "  -d '{\"message\":\"blue ha\",\"context\":{}}'\n\n";
    
    echo "3. Log File Locations:\n";
    echo "Main log: /var/logs/ai_chatbot_debug.log\n";
    echo "Detailed log: /var/logs/ai_chatbot_detailed_debug.json\n";
}

// Run all tests
echo "Testing chatbot API: $chatbotApiUrl\n";
echo "Using test data: " . json_encode($testData) . "\n\n";

// Test 1: Debug endpoint
$test1Result = testDebugQueryEndpoint($chatbotApiUrl, $testData);

// Test 2: Full flow (only if Test 1 passed)
if ($test1Result) {
    $test2Result = testFullChatbotFlow($chatbotApiUrl);
} else {
    echo "⚠️ Skipping Test 2 due to Test 1 failure\n";
}

// Test 3: Database verification
testDirectDatabaseQuery($testData);

// Generate curl commands
generateCurlCommands($chatbotApiUrl, $testData);

echo "\n" . str_repeat('=', 60) . "\n";
echo "DEBUGGING CHECKLIST:\n";
echo "□ Check the log files for detailed query information\n";
echo "□ Verify your test data IDs are correct in the database\n";
echo "□ Run the direct SQL query to confirm data exists\n";
echo "□ Use the debug endpoint to see exact query being built\n";
echo "□ Compare generated SQL with your working SQL\n";
echo "□ Test the full chatbot flow to see where it breaks\n";
echo str_repeat('=', 60) . "\n";
?>
