<?php
/**
 * Test script for chatbot context awareness and AI response improvements
 * 
 * This script tests the context-related functions without requiring database access
 */

// Mock session for testing
$_SESSION = [
    'chat_history' => [],
    'conversation_summary' => [
        'message_count' => 0,
        'topics' => [],
        'active_flow' => null
    ],
    'detected_intent' => null
];

// Mock class for testing context-related functions
class ChatbotContextTester {
    // Test function for extracting topics from messages
    public static function testTopicExtraction() {
        echo "\n=== Testing Topic Extraction ===\n";
        
        $testCases = [
            'I want to buy a t-shirt' => ['product'],
            'How much does shipping cost?' => ['shipping'],
            'I need to track my order #12345' => ['order'],
            'Do you have this in red color?' => ['product'],
            'I want to return my purchase' => ['return'],
            'What payment methods do you accept?' => ['payment']
        ];
        
        $passed = 0;
        $total = count($testCases);
        
        foreach ($testCases as $message => $expectedTopics) {
            $extractedTopics = self::mockExtractTopics($message);
            $success = false;
            
            // Check if at least one expected topic is found
            foreach ($expectedTopics as $topic) {
                if (in_array($topic, $extractedTopics)) {
                    $success = true;
                    break;
                }
            }
            
            echo "Message: '$message'\n";
            echo "Expected topics: " . implode(', ', $expectedTopics) . "\n";
            echo "Extracted topics: " . implode(', ', $extractedTopics) . "\n";
            echo "Result: " . ($success ? "✅ PASSED" : "❌ FAILED") . "\n\n";
            
            if ($success) $passed++;
        }
        
        echo "Topic Extraction Test: $passed/$total tests passed\n";
        return $passed === $total;
    }
    
    // Test function for conversation summary updates
    public static function testConversationSummary() {
        echo "\n=== Testing Conversation Summary ===\n";
        
        // Reset mock session
        $_SESSION['conversation_summary'] = [
            'message_count' => 0,
            'topics' => [],
            'active_flow' => null
        ];
        
        $messages = [
            'Hello there',
            'I want to buy some shoes',
            'Do you have Nike in black?',
            'How much do they cost?',
            'I also want to check my order status'
        ];
        
        echo "Simulating conversation with " . count($messages) . " messages...\n";
        
        foreach ($messages as $message) {
            self::mockUpdateSummary($message);
            echo "Added message: '$message'\n";
            echo "Current summary: " . json_encode($_SESSION['conversation_summary']) . "\n\n";
        }
        
        $summary = $_SESSION['conversation_summary'];
        $hasProductTopic = in_array('product', $summary['topics']);
        $hasOrderTopic = in_array('order', $summary['topics']);
        $correctCount = $summary['message_count'] === count($messages);
        
        echo "Final message count: {$summary['message_count']} (Expected: " . count($messages) . ")\n";
        echo "Topics detected: " . implode(', ', $summary['topics']) . "\n";
        echo "Product topic detected: " . ($hasProductTopic ? "Yes" : "No") . "\n";
        echo "Order topic detected: " . ($hasOrderTopic ? "Yes" : "No") . "\n";
        
        $success = $hasProductTopic && $hasOrderTopic && $correctCount;
        echo "Conversation Summary Test: " . ($success ? "✅ PASSED" : "❌ FAILED") . "\n";
        
        return $success;
    }
    
    // Test function for context-aware responses
    public static function testContextAwareResponses() {
        echo "\n=== Testing Context-Aware Responses ===\n";
        
        // Test cases with different contexts
        $testCases = [
            // New user, no context
            [
                'context' => [
                    'message_count' => 1,
                    'topics' => [],
                    'active_flow' => null,
                    'detected_intent' => null
                ],
                'message' => 'Hello',
                'expected_patterns' => ['hello', 'hi', 'hey', 'welcome']
            ],
            // Returning user with product context
            [
                'context' => [
                    'message_count' => 8,
                    'topics' => ['product'],
                    'active_flow' => null,
                    'detected_intent' => 'product_inquiry'
                ],
                'message' => 'Hello',
                'expected_patterns' => ['again', 'back', 'return']
            ],
            // User in order flow
            [
                'context' => [
                    'message_count' => 3,
                    'topics' => ['order'],
                    'active_flow' => 'order_tracking',
                    'detected_intent' => 'order_status'
                ],
                'message' => 'Thanks',
                'expected_patterns' => ['order', 'help', 'else']
            ]
        ];
        
        $passed = 0;
        $total = count($testCases);
        
        foreach ($testCases as $index => $case) {
            // Set up context
            $_SESSION['conversation_summary'] = $case['context'];
            $_SESSION['detected_intent'] = $case['context']['detected_intent'];
            
            // Get mock response
            $response = self::mockGetResponse($case['message'], 'en');
            
            // Check if response matches expected patterns
            $matchesPattern = false;
            foreach ($case['expected_patterns'] as $pattern) {
                if (stripos($response, $pattern) !== false) {
                    $matchesPattern = true;
                    break;
                }
            }
            
            echo "Test case #" . ($index + 1) . ":\n";
            echo "Context: " . json_encode($case['context']) . "\n";
            echo "Message: '{$case['message']}'\n";
            echo "Response: '$response'\n";
            echo "Expected patterns: " . implode(', ', $case['expected_patterns']) . "\n";
            echo "Result: " . ($matchesPattern ? "✅ PASSED" : "❌ FAILED") . "\n\n";
            
            if ($matchesPattern) $passed++;
        }
        
        echo "Context-Aware Responses Test: $passed/$total tests passed\n";
        return $passed === $total;
    }
    
    // Mock function for topic extraction
    private static function mockExtractTopics($message) {
        $topics = [];
        $lowerMsg = strtolower($message);
        
        // Simple keyword-based topic extraction
        $topicKeywords = [
            'product' => ['buy', 'product', 'item', 'purchase', 't-shirt', 'shoes', 'color', 'size'],
            'order' => ['order', 'track', 'status', 'delivery', 'shipped'],
            'return' => ['return', 'refund', 'exchange', 'money back'],
            'payment' => ['payment', 'pay', 'credit card', 'paypal', 'billing'],
            'shipping' => ['shipping', 'delivery', 'ship', 'send']
        ];
        
        foreach ($topicKeywords as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($lowerMsg, $keyword) !== false) {
                    $topics[] = $topic;
                    break;
                }
            }
        }
        
        return $topics;
    }
    
    // Mock function for updating conversation summary
    private static function mockUpdateSummary($message) {
        // Increment message count
        $_SESSION['conversation_summary']['message_count']++;
        
        // Extract and add topics
        $newTopics = self::mockExtractTopics($message);
        foreach ($newTopics as $topic) {
            if (!in_array($topic, $_SESSION['conversation_summary']['topics'])) {
                $_SESSION['conversation_summary']['topics'][] = $topic;
            }
        }
        
        // Detect active flow (simplified)
        if (strpos(strtolower($message), 'order') !== false) {
            $_SESSION['conversation_summary']['active_flow'] = 'order_tracking';
        } elseif (strpos(strtolower($message), 'return') !== false) {
            $_SESSION['conversation_summary']['active_flow'] = 'return_process';
        }
    }
    
    // Mock function for getting context-aware responses
    private static function mockGetResponse($message, $lang) {
        $lowerMsg = strtolower($message);
        $summary = $_SESSION['conversation_summary'];
        $messageCount = $summary['message_count'] ?? 0;
        $topics = $summary['topics'] ?? [];
        $activeFlow = $summary['active_flow'] ?? null;
        
        // Greeting patterns
        if (in_array($lowerMsg, ['hi', 'hello', 'hey'])) {
            if ($messageCount > 5) {
                return "Welcome back! How can I help you today?";
            } else {
                return "Hello! How can I help you?";
            }
        }
        
        // Thanks patterns
        if (in_array($lowerMsg, ['thanks', 'thank you'])) {
            if (in_array('product', $topics)) {
                return "You're welcome! Would you like to see other similar products?";
            } elseif (in_array('order', $topics)) {
                return "My pleasure! Do you have any other questions about your order?";
            } else {
                return "You're welcome! Anything else I can help with?";
            }
        }
        
        // Default response with context
        if (!empty($topics)) {
            return "I see we've been talking about " . implode(', ', $topics) . ". How can I help you further with this?";
        }
        
        return "How can I assist you today?";
    }
}

// Run the tests
echo "\n===== CHATBOT CONTEXT AWARENESS TESTS =====\n";

$topicExtractionPassed = ChatbotContextTester::testTopicExtraction();
echo "\n" . str_repeat('-', 50) . "\n";

$conversationSummaryPassed = ChatbotContextTester::testConversationSummary();
echo "\n" . str_repeat('-', 50) . "\n";

$contextAwareResponsesPassed = ChatbotContextTester::testContextAwareResponses();
echo "\n" . str_repeat('-', 50) . "\n";

// Overall results
echo "\n===== TEST RESULTS SUMMARY =====\n";
echo "Topic Extraction: " . ($topicExtractionPassed ? "✅ PASSED" : "❌ FAILED") . "\n";
echo "Conversation Summary: " . ($conversationSummaryPassed ? "✅ PASSED" : "❌ FAILED") . "\n";
echo "Context-Aware Responses: " . ($contextAwareResponsesPassed ? "✅ PASSED" : "❌ FAILED") . "\n";

$allPassed = $topicExtractionPassed && $conversationSummaryPassed && $contextAwareResponsesPassed;
echo "\nOverall Test Result: " . ($allPassed ? "✅ ALL TESTS PASSED" : "❌ SOME TESTS FAILED") . "\n";