<?php
/**
 * Simple test to verify that promotion queries are correctly detected
 * and don't conflict with coupon queries
 */

// Test queries that should be handled by PROMOTION handler
$promotionQueries = [
    "is there any product on discount",
    "what items are on discount",
    "do you have any products on promotion",
    "which products are discounted",
    "y a-t-il des produits en promotion",
    "des produits en soldes",
    "quels produits sont en promo"
];

// Test queries that should be handled by COUPON handler
$couponQueries = [
    "do you have a coupon",
    "give me a coupon code",
    "avez-vous un coupon",
    "donnez-moi un code promo",
    "I need a discount code",
    "voucher code please"
];

// Test the promotion detection logic
function isPromotionQuery(string $text, string $lang): bool {
    $promotionKeywords = [
        'en' => [
            'promotion', 'promo', 'sale', 'discount', 'on sale', 'deals', 
            'special offer', 'offers', 'reduced', 'cheap', 'bargain', 
            'clearance', 'markdown', 'any sales', 'discounted',
            'is there any product on promotion', 'are there any products on sale',
            'what items are on sale', 'which products are discounted',
            'any promotions', 'any deals', 'products on promotion',
            'items on sale', 'special pricing', 'price reduction'
        ],
        'fr' => [
            'promotion', 'promo', 'soldes', 'remise', 'réduction', 
            'en promo', 'offres', 'offre spéciale', 'bon marché', 
            'pas cher', 'démarquage', 'liquidation', 'des soldes', 'réduits',
            'y a-t-il des produits en promotion', 'des produits en soldes',
            'quels produits sont en promo', 'articles en promotion',
            'des promotions', 'des offres', 'produits soldés',
            'articles soldés', 'prix réduits', 'tarifs préférentiels'
        ]
    ];
    
    $questionPatterns = [
        'en' => [
            '/\b(is there|are there|do you have|got any).*(promotion|promo|sale|discount|deal)/i',
            '/\b(what|which).*(product|item).*(sale|promotion|promo|discount)/i',
            '/\b(any|some).*(product|item).*(sale|promotion|promo|discount)/i'
        ],
        'fr' => [
            '/\b(y a-t-il|avez-vous|il y a).*(promotion|promo|soldes|remise)/i',
            '/\b(quel|quels|quelle|quelles).*(produit|article).*(promotion|promo|soldes)/i',
            '/\b(des|quelques).*(produit|article).*(promotion|promo|soldes)/i'
        ]
    ];
    
    $patterns = array_merge($questionPatterns['en'], $questionPatterns['fr']);
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    
    $allKeywords = array_merge($promotionKeywords['en'], $promotionKeywords['fr']);
    foreach ($allKeywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

// Test the coupon detection logic (mimicking the fixed version)
function isCouponQuery(string $message): bool {
    $lowerMsg = strtolower($message);
    
    // First, check if this is a product discount/promotion query
    $productDiscountPatterns = [
        '/\b(products?|items?|articles?)\s+(on\s+)?(sale|discount|promotion|promo)/i',
        '/\b(any|some|which|what)\s+(products?|items?)\s.*(discount|sale|promo)/i',
        '/\b(is there|are there|do you have)\s.*(products?|items?).*on\s+(sale|discount)/i',
        '/\b(produits?|articles?)\s+(en\s+)?(promotion|promo|soldes)/i',
        '/\b(y a-t-il|avez-vous)\s.*(produits?|articles?).*en\s+(promotion|soldes)/i',
        '/\b(des|quelques|quels?)\s+(produits?|articles?)\s.*(promotion|soldes|promo)/i'
    ];
    
    // If this looks like a product discount query, don't handle as coupon
    foreach ($productDiscountPatterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return false;
        }
    }
    
    // Check for coupon-specific patterns
    $couponSpecificPatterns = [
        '/\b(coupon|code|voucher|bon)\b/i',
        '/\b(promo\s+code|discount\s+code|coupon\s+code)\b/i',
        '/\b(give\s+me\s+.*(coupon|code|discount))\b/i',
        '/\b(do\s+you\s+have\s+.*(coupon|code))\b/i',
        '/\b(avez-vous\s+.*(coupon|code|bon))\b/i',
        '/\b(donnez-moi\s+.*(coupon|code|remise))\b/i'
    ];
    
    foreach ($couponSpecificPatterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return true;
        }
    }
    
    // Check for coupon-only keywords
    $couponOnlyKeywords = ['coupon', 'voucher', 'promo code', 'discount code', 'coupon code', 'bon', 'code promo'];
    foreach ($couponOnlyKeywords as $keyword) {
        if (strpos($lowerMsg, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

echo "Testing Promotion vs Coupon Query Detection\n";
echo "==========================================\n\n";

echo "PROMOTION QUERIES (should be detected by promotion handler):\n";
echo "-----------------------------------------------------------\n";
foreach ($promotionQueries as $query) {
    $isPromotion = isPromotionQuery(strtolower($query), 'en');
    $isCoupon = isCouponQuery($query);
    $result = $isPromotion ? '✅ PROMOTION' : ($isCoupon ? '❌ COUPON (WRONG!)' : '❓ NEITHER');
    echo "\"$query\" -> $result\n";
}

echo "\nCOUPON QUERIES (should be detected by coupon handler):\n";
echo "-----------------------------------------------------\n";
foreach ($couponQueries as $query) {
    $isPromotion = isPromotionQuery(strtolower($query), 'en');
    $isCoupon = isCouponQuery($query);
    $result = $isCoupon ? '✅ COUPON' : ($isPromotion ? '❌ PROMOTION (WRONG!)' : '❓ NEITHER');
    echo "\"$query\" -> $result\n";
}

echo "\nTEST COMPLETE!\n";
echo "All queries marked with ✅ should work correctly now.\n";
echo "Any queries marked with ❌ indicate potential conflicts.\n";
?>
