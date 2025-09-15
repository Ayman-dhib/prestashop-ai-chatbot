<?php

class RegexTester {
    private function isCouponSpecificQuery(string $message): bool
    {
        $lowerMsg = mb_strtolower($message, 'UTF-8');
        
        // Specific patterns that indicate a coupon query (not a general promotion query)
        $couponSpecificPatterns = [
            '/\b(coupon|voucher|promo)\s+(code|number)s?\b/i',
            '/\b(code|codes)\s+(promo|promotionnel|reduction|réduction)s?\b/i',
            '/\b(discount|promo|promotional|coupon)\s+(code|codes)\b/i',
            '/\b(do you have|got|have)\s+(any)?\s*(coupon|promo|discount)\s+(code|codes)\b/i',
            '/\b(is there|are there)\s+(any)?\s*(coupon|promo|discount)\s+(code|codes)\b/i',
            '/\b(avez.vous|avez vous)\s+(des)?\s*(code|codes)\s+(promo|promotionnel|reduction|réduction)s?\b/i',
            '/\b(code promo)\b/i',
            '/\b(y a.t.il|y a t il)\s+(des)?\s*(code|codes)\s+(promo|promotionnel|reduction|réduction)s?\b/i',
            '/\b(do you have any promo)\b/i',
            '/\b(avez.vous des coupons)\b/i'
        ];
        
        // Check for direct coupon keywords
        $couponKeywords = ['coupon', 'voucher', 'code promo', 'promo code', 'discount code', 'promotional code'];
        foreach ($couponKeywords as $keyword) {
            if (strpos($lowerMsg, $keyword) !== false) {
                echo "Matched coupon keyword: $keyword\n";
                return true;
            }
        }
        
        // Check for coupon-specific patterns
        foreach ($couponSpecificPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                echo "Matched coupon pattern: $pattern\n";
                return true;
            }
        }
        
        return false;
    }
    
    private function isPromotionOrCouponQuery(string $message): bool
    {
        $lowerMsg = mb_strtolower($message, 'UTF-8');
        
        // Check for promotion keywords in English and French
        $promotionKeywords = [
            'promotion', 'promo', 'discount', 'sale', 'deal', 'offer', 'special', 
            'reduction', 'réduction', 'solde', 'offre', 'spécial', 'rabais'
        ];
        
        foreach ($promotionKeywords as $keyword) {
            if (strpos($lowerMsg, $keyword) !== false) {
                echo "Matched promotion keyword: $keyword\n";
                return true;
            }
        }
        
        // Check for common question patterns
        $promotionPatterns = [
            '/\b(any|some|current)\s+(promotion|promo|discount|sale|deal|offer|special)s?\b/i',
            '/\b(is there|are there)\s+(any|some)?\s+(promotion|promo|discount|sale|deal|offer|special)s?\b/i',
            '/\b(do you have)\s+(any|some)?\s+(promotion|promo|discount|sale|deal|offer|special)s?\b/i',
            '/\b(what|which)\s+(promotion|promo|discount|sale|deal|offer|special)s?\b/i',
            '/\b(des|quelques?)\s+(promotion|promo|réduction|solde|offre|spécial|rabais)s?\b/i',
            '/\b(y a-t-il|avez-vous)\s+(des|quelques?)?\s+(promotion|promo|réduction|solde|offre|spécial|rabais)s?\b/i',
            '/\b(quelle|quelles)\s+(promotion|promo|réduction|solde|offre|spécial|rabais)s?\b/i',
            '/\b(do you have any promo)\b/i',
            '/\b(is there a discount)\b/i',
            '/\b(y a-t-il des coupons)\b/i'
        ];
        
        foreach ($promotionPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                echo "Matched promotion pattern: $pattern\n";
                return true;
            }
        }
        
        return false;
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

$test = new RegexTester();
$test->testQueries();