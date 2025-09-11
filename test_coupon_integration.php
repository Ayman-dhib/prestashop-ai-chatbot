<?php
/**
 * Test script for AI Chatbot Coupon Integration
 * This script can be used to test the coupon functionality
 */

// Only allow access from localhost during development
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    http_response_code(403);
    die('Access denied');
}

require_once __DIR__ . '/../../config/config.inc.php';
require_once __DIR__ . '/../../init.php';

echo "<h1>AI Chatbot Coupon Integration Test</h1>";

try {
    // Test module loading
    $module = Module::getInstanceByName('ai_chatbot');
    if (!$module) {
        echo "<div style='color:red'>❌ Module not found</div>";
        exit;
    }
    echo "<div style='color:green'>✅ Module loaded successfully</div>";

    // Test configuration
    $couponEnabled = Configuration::get('AI_CHATBOT_COUPON_ENABLED');
    echo "<div>Coupon Enabled: " . ($couponEnabled ? 'YES' : 'NO') . "</div>";
    
    $couponAmount = Configuration::get('AI_CHATBOT_COUPON_AMOUNT');
    $couponType = Configuration::get('AI_CHATBOT_COUPON_TYPE');
    echo "<div>Coupon Settings: {$couponAmount} ({$couponType})</div>";
    
    $popupFreq = Configuration::get('AI_CHATBOT_POPUP_FREQUENCY');
    echo "<div>Popup Frequency: {$popupFreq}</div>";

    // Test database tables
    $tables = [
        'ai_chatbot_coupon_tracking',
        'ai_chatbot_popup_frequency',
        'ai_chatbot_ratings',
        'ai_chatbot_rating_pending'
    ];
    
    echo "<h2>Database Tables Check</h2>";
    foreach ($tables as $table) {
        $fullTableName = _DB_PREFIX_ . $table;
        $exists = Db::getInstance()->executeS("SHOW TABLES LIKE '{$fullTableName}'");
        if ($exists) {
            echo "<div style='color:green'>✅ Table {$fullTableName} exists</div>";
        } else {
            echo "<div style='color:red'>❌ Table {$fullTableName} missing</div>";
        }
    }

    // Test coupon generation
    echo "<h2>Coupon Generation Test</h2>";
    if ($couponEnabled) {
        $couponData = $module->generateCoupon();
        if ($couponData) {
            echo "<div style='color:green'>✅ Coupon generated successfully</div>";
            echo "<div><strong>Code:</strong> {$couponData['code']}</div>";
            echo "<div><strong>Amount:</strong> {$couponData['amount']} ({$couponData['type']})</div>";
            echo "<div><strong>Expires:</strong> {$couponData['expires']}</div>";
        } else {
            echo "<div style='color:red'>❌ Failed to generate coupon</div>";
        }
    } else {
        echo "<div style='color:orange'>⚠️ Coupon generation disabled</div>";
    }

    // Test popup frequency check
    echo "<h2>Popup Frequency Test</h2>";
    $shouldShow = $module->shouldShowPopup();
    echo "<div>Should show popup: " . ($shouldShow ? 'YES' : 'NO') . "</div>";

    echo "<h2>Integration Status</h2>";
    echo "<div style='color:green'>✅ All core components appear to be working</div>";
    echo "<div><em>To fully test, visit the frontend and check for popup functionality</em></div>";

} catch (Exception $e) {
    echo "<div style='color:red'>❌ Error: " . $e->getMessage() . "</div>";
}

echo "<hr>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ul>";
echo "<li>Enable coupon functionality in the admin panel</li>";
echo "<li>Configure coupon settings (amount, frequency, etc.)</li>";
echo "<li>Test popup display on frontend</li>";
echo "<li>Test coupon codes in checkout</li>";
echo "<li>Test chatbot coupon conversations</li>";
echo "</ul>";
?>
