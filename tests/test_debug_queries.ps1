# PowerShell script for testing AI Chatbot SQL query debugging
# This script helps isolate chatbot query issues on Windows systems

# Configuration - UPDATE THESE TO MATCH YOUR SETUP
$PrestashopBaseUrl = "http://localhost/prestashop"  # UPDATE THIS
$ChatbotApiUrl = "$PrestashopBaseUrl/modules/ai_chatbot/controllers/front/api.php"

# Your test data from the working SQL query  
$TestData = @{
    CategoryId = 10        # pants category
    BrandId = 3            # HA brand
    AttributeId = 14       # Blue color attribute
    LanguageId = 1         # Default language
}

Write-Host "=== AI CHATBOT SQL QUERY DEBUGGING TESTS ===" -ForegroundColor Cyan
Write-Host ""

# Test 1: Debug Query Endpoint
function Test-DebugQueryEndpoint {
    param($ApiUrl, $TestData)
    
    Write-Host "TEST 1: Debug Query Endpoint" -ForegroundColor Yellow
    Write-Host ("-" * 40)
    
    $QueryParams = @{
        debug_query = '1'
        categories = $TestData.CategoryId
        brands = $TestData.BrandId
        attributes = $TestData.AttributeId
        message = 'blue ha'
        lang = 'en'
    }
    
    $QueryString = ($QueryParams.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }) -join "&"
    $DebugUrl = "$ApiUrl?$QueryString"
    
    Write-Host "Debug URL: $DebugUrl"
    
    try {
        $Response = Invoke-RestMethod -Uri $DebugUrl -Method Get -TimeoutSec 30 -Headers @{
            'Content-Type' = 'application/json'
            'User-Agent' = 'ChatbotDebugger/1.0'
        }
        
        Write-Host "✅ Response received successfully" -ForegroundColor Green
        
        if ($Response.debug_info) {
            $Debug = $Response.debug_info
            
            Write-Host ""
            Write-Host "📊 Debug Information:" -ForegroundColor Blue
            Write-Host "Filter Extraction:"
            Write-Host "  - Extracted Brands: $($Debug.filter_extraction.extracted_brands -join ', ')"
            Write-Host "  - Extracted Attributes: $($Debug.filter_extraction.extracted_attributes -join ', ')"
            
            Write-Host ""
            Write-Host "Query Parameters:"
            Write-Host "  - Category IDs: $($Debug.final_query_params.category_ids -join ', ')"
            Write-Host "  - Brand IDs: $($Debug.final_query_params.brand_ids -join ', ')"
            Write-Host "  - Attribute IDs: $($Debug.final_query_params.attribute_ids -join ', ')"
            
            Write-Host ""
            Write-Host "Query Results:"
            Write-Host "  - Products Found: $($Debug.query_results.found_products)"
            
            if ($Debug.query_results.products -and $Debug.query_results.products.Count -gt 0) {
                Write-Host "  - Sample Products:"
                foreach ($Product in $Debug.query_results.products) {
                    Write-Host "    * $($Product.name) (ID: $($Product.id_product))"
                }
            }
            
            Write-Host ""
            Write-Host "Expected SQL Query:"
            Write-Host $Debug.expected_working_sql
            Write-Host ""
        }
        
        return $true
    }
    catch {
        Write-Host "❌ Error: $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

# Test 2: Full Chatbot Flow
function Test-FullChatbotFlow {
    param($ApiUrl)
    
    Write-Host ""
    Write-Host "TEST 2: Full Chatbot Flow Simulation" -ForegroundColor Yellow
    Write-Host ("-" * 40)
    
    # Step 1: Ask for pants
    Write-Host "Step 1: User asks 'do you sell pants?'"
    
    try {
        $Payload1 = @{
            message = "do you sell pants?"
            context = @{}
        } | ConvertTo-Json
        
        $Response1 = Invoke-RestMethod -Uri $ApiUrl -Method Post -Body $Payload1 -ContentType "application/json" -TimeoutSec 30
        
        Write-Host "Bot Response: $($Response1.reply.Substring(0, [Math]::Min(100, $Response1.reply.Length)))..."
        
        # Step 2: Provide filters
        Write-Host ""
        Write-Host "Step 2: User specifies 'blue ha'"
        
        $Payload2 = @{
            message = "blue ha"
            context = @{}
        } | ConvertTo-Json
        
        $Response2 = Invoke-RestMethod -Uri $ApiUrl -Method Post -Body $Payload2 -ContentType "application/json" -TimeoutSec 30
        
        Write-Host "Bot Response: $($Response2.reply.Substring(0, [Math]::Min(100, $Response2.reply.Length)))..."
        
        # Check if products were found
        if ($Response2.reply -like "*found*" -or $Response2.reply -like "*results*") {
            Write-Host "✅ Products appear to be found in the response" -ForegroundColor Green
        } else {
            Write-Host "⚠️ No products seem to be found - this may be the issue" -ForegroundColor Yellow
        }
        
        return $true
    }
    catch {
        Write-Host "❌ Error in chatbot flow: $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

# Test 3: Database Query Verification
function Show-DatabaseQueryVerification {
    param($TestData)
    
    Write-Host ""
    Write-Host "TEST 3: Database Query Verification" -ForegroundColor Yellow
    Write-Host ("-" * 40)
    
    Write-Host "⚠️  Note: This test requires database access and PrestaShop environment." -ForegroundColor Yellow
    Write-Host "To run this test manually, execute this SQL in your database:"
    Write-Host ""
    
    $Sql = @"
SELECT DISTINCT p.id_product, pl.name, p.price 
FROM ps_product p 
INNER JOIN ps_product_lang pl ON (pl.id_product = p.id_product AND pl.id_lang = $($TestData.LanguageId)) 
INNER JOIN ps_category_product cp ON cp.id_product = p.id_product 
WHERE p.active = 1 
  AND cp.id_category IN ($($TestData.CategoryId)) -- pants category
  AND p.id_manufacturer IN ($($TestData.BrandId)) -- HA brand
  AND p.id_product IN ( 
    SELECT pa.id_product 
    FROM ps_product_attribute pa 
    INNER JOIN ps_product_attribute_combination pac 
        ON pac.id_product_attribute = pa.id_product_attribute 
    WHERE pac.id_attribute IN ($($TestData.AttributeId)) -- Blue color attribute
    GROUP BY pa.id_product 
    HAVING COUNT(DISTINCT pac.id_attribute) = 1 
  ) 
ORDER BY p.date_upd DESC 
LIMIT 24;
"@
    
    Write-Host $Sql -ForegroundColor Gray
    Write-Host ""
    Write-Host "If this query returns results, the data exists and the problem is in the PHP code."
    Write-Host "If this query returns no results, check your test data IDs."
}

# Generate curl commands
function Show-CurlCommands {
    param($ApiUrl, $TestData)
    
    Write-Host ""
    Write-Host "CURL COMMANDS FOR MANUAL TESTING:" -ForegroundColor Cyan
    Write-Host ("=" * 50)
    
    $QueryParams = @{
        debug_query = '1'
        categories = $TestData.CategoryId
        brands = $TestData.BrandId
        attributes = $TestData.AttributeId
        message = 'blue ha'
        lang = 'en'
    }
    
    $QueryString = ($QueryParams.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }) -join "&"
    $DebugUrl = "$ApiUrl?$QueryString"
    
    Write-Host "1. Debug Query Endpoint:"
    Write-Host "curl -X GET `"$DebugUrl`""
    Write-Host ""
    
    Write-Host "2. Full Chatbot Flow:"
    Write-Host "# Step 1 - Ask for pants:"
    Write-Host "curl -X POST `"$ApiUrl`" \"
    Write-Host "  -H `"Content-Type: application/json`" \"
    Write-Host "  -d '{`"message`":`"do you sell pants?`",`"context`":{}}'`"
    Write-Host ""
    
    Write-Host "# Step 2 - Specify filters:"
    Write-Host "curl -X POST `"$ApiUrl`" \"
    Write-Host "  -H `"Content-Type: application/json`" \"
    Write-Host "  -d '{`"message`":`"blue ha`",`"context`":{}}'`"
    Write-Host ""
    
    Write-Host "3. Log File Locations:"
    Write-Host "Main log: /var/logs/ai_chatbot_debug.log"
    Write-Host "Detailed log: /var/logs/ai_chatbot_detailed_debug.json"
}

# Run all tests
Write-Host "Testing chatbot API: $ChatbotApiUrl"
Write-Host "Using test data: $(($TestData | ConvertTo-Json -Compress))"
Write-Host ""

# Test 1: Debug endpoint
$Test1Result = Test-DebugQueryEndpoint -ApiUrl $ChatbotApiUrl -TestData $TestData

# Test 2: Full flow (only if Test 1 passed)
if ($Test1Result) {
    $Test2Result = Test-FullChatbotFlow -ApiUrl $ChatbotApiUrl
} else {
    Write-Host "⚠️ Skipping Test 2 due to Test 1 failure" -ForegroundColor Yellow
}

# Test 3: Database verification
Show-DatabaseQueryVerification -TestData $TestData

# Generate curl commands
Show-CurlCommands -ApiUrl $ChatbotApiUrl -TestData $TestData

Write-Host ""
Write-Host ("=" * 60) -ForegroundColor Cyan
Write-Host "DEBUGGING CHECKLIST:" -ForegroundColor Cyan
Write-Host "□ Check the log files for detailed query information"
Write-Host "□ Verify your test data IDs are correct in the database"  
Write-Host "□ Run the direct SQL query to confirm data exists"
Write-Host "□ Use the debug endpoint to see exact query being built"
Write-Host "□ Compare generated SQL with your working SQL"
Write-Host "□ Test the full chatbot flow to see where it breaks"
Write-Host ("=" * 60) -ForegroundColor Cyan
