<?php
require_once('../../config/config.inc.php');
require_once('./controllers/front/api.php');

class TestCouponDetection extends Ai_ChatbotApiModuleFrontController {
    public function __construct() {
        parent::__construct();
    }
    
    public function testQueries() {
        $queries = [
            'do you have any coupons?',
            'is there any product in promotion',
            'any discount codes available?',
            'avez-vous des codes promo?',
            'are there any sales going on?',
            'do you offer any discounts?'
        ];
        
        echo "\nTesting coupon and promotion detection:\n";
        echo "----------------------------------------\n";
        
        foreach ($queries as $query) {
            echo "\nQuery: \"$query\"\n";
            echo "isCouponSpecificQuery: " . ($this->isCouponSpecificQuery($query) ? 'true' : 'false') . "\n";
            echo "isPromotionOrCouponQuery: " . ($this->isPromotionOrCouponQuery($query) ? 'true' : 'false') . "\n";
        }
    }
}

$test = new TestCouponDetection();
$test->init();
$test->testQueries();