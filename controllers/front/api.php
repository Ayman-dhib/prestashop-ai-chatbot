<?php
/**
 * AI Chatbot – Generalized Front API
 * - Intelligent conversation management
 * - Secure order tracking with proper flow
 * - Dynamic product catalog search
 * - Smart FAQ matching
 * - Multi-language support
 * - Adaptive rating system
 */

use GuzzleHttp\Client;
use Stichoza\GoogleTranslate\GoogleTranslate;

require_once __DIR__ . '/../../../../vendor/autoload.php';

class Ai_ChatbotApiModuleFrontController extends ModuleFrontController
{
    /* ======================= constants ======================== */

    private const S_CHAT = 'ai_chat_hist';
    private const S_FLOW = 'ai_order_flow';
    private const S_CAT  = 'ai_catalog_flow'; // {stage: ask_filters|done, cat_ids:[], cat_names:[]}
    private const S_RATING = 'ai_rating_flow'; // {stage: pending|done, order_id: int}
    private const S_CONTINUE = 'ai_continue_flow'; // {stage: awaiting_response}

    // Adaptive FAQ thresholds - automatically adjust based on content quality
    private const KW_THRESHOLD_MIN  = 0.02;
    private const KW_THRESHOLD_MAX  = 0.15;
    private const KW_GOOD           = 0.08; // Good keyword match threshold
    private const SEM_THRESHOLD_MIN = 0.40;
    private const SEM_THRESHOLD_MAX = 0.70;
    private const BLEND_ACCEPT_MIN  = 0.35;
    
    // OpenAI configuration
    private const CHAT_MODEL  = 'gpt-4o-mini'; // More cost-effective
    private const EMBED_MODEL = 'text-embedding-3-small';
    
    // Rating system timing (in hours)
    private const RATING_MIN_ORDER_AGE = 2; // Wait 2 hours after order
    private const RATING_MAX_ORDER_AGE = 168; // 1 week maximum

    /* ========================= entry ========================== */

    public function initContent()
    {
        @ini_set('display_errors', '0'); @ini_set('html_errors', '0');
        while (ob_get_level()) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        if (session_status() === PHP_SESSION_NONE) session_start();

        try {
            // Clear opcache to ensure latest code is loaded
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
            
            if ($_SERVER['REQUEST_METHOD'] === 'GET' && Tools::getValue('diag')) { $this->diag(); return; }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->outErr('Invalid method', 405); return; }

            $payload = json_decode(file_get_contents('php://input'), true) ?: [];
            $userMsg = trim((string)($payload['message'] ?? ''));
            $ctx     = (array)($payload['context'] ?? []);
            if (!empty($ctx['reset_chat'])) {
                unset($_SESSION[self::S_CHAT], $_SESSION[self::S_FLOW], $_SESSION[self::S_CAT], $_SESSION[self::S_RATING], $_SESSION[self::S_CONTINUE]);
                $this->out(['reply'=>null,'source'=>'reset']); return;
            }
            if ($userMsg === '') { $this->out(['reply'=>'⚠️ Empty message.','source'=>'error']); return; }

            $apiKey = (string) Configuration::get('AI_CHATBOT_API_KEY');
            $lang = $this->detectLanguage($userMsg);
            $kbAbs = $this->getLanguageSpecificFaqFile($lang);
            $embAbs = $kbAbs ? $kbAbs.'.embeddings.json' : '';
            
            // Store original message for context preservation
            $_SESSION['original_user_msg'] = $userMsg;
            $_SESSION['detected_language'] = $lang;

            // Only check for ratings after processing other intents to avoid interference
            $shouldCheckRating = ($userMsg !== '[system]' && 
                                !$this->isInActiveFlow() && 
                                $this->hasEligibleCompletedOrders());
                                
            if ($shouldCheckRating) {
                $this->checkAndShowPendingRatings($lang, $userMsg, $userMsg);
            }

            $this->log('Processing message in language: '.$lang);
            
            // Detect user intent
            $intent = $this->detectIntent($userMsg, $lang);
            $this->log('Detected intent: '.$intent);
            
            /* 1) Small talk handling (only when NOT in an active flow and message is not an email) */
            if ($intent === 'smalltalk' && !$this->isInActiveFlow() && $this->extractEmail($userMsg) === null) {
                $reply = $this->handleSmallTalk($userMsg, $lang);
                $translatedReply = $this->autoTranslateResponse($reply, $lang, 'smalltalk');
                $this->remember('user', $userMsg);
                $this->remember('assistant', $translatedReply);
                $this->out(['reply' => $translatedReply, 'source' => 'smalltalk']);
                return;
            }

            /* 2) Order tracking flow */
            $flow = $_SESSION[self::S_FLOW] ?? null;
            if (is_array($flow) && in_array(($flow['stage'] ?? ''), ['need_email','need_id','start'], true)) {
                $reply = $this->handleOrderFlow($userMsg, $lang);
                $translatedReply = $this->autoTranslateResponse($reply, $lang, 'order');
                $this->remember('user', $userMsg);
                $this->remember('assistant', $translatedReply);
                $this->out(['reply' => $translatedReply, 'source' => 'order']); 
                return;
            }
            
            if ($intent === 'order') {
                $_SESSION[self::S_FLOW] = ['stage' => 'start', 'email' => null];
                $reply = $this->handleOrderFlow($userMsg, $lang);
                $translatedReply = $this->autoTranslateResponse($reply, $lang, 'order');
                $this->remember('user', $userMsg);
                $this->remember('assistant', $translatedReply);
                $this->out(['reply' => $translatedReply, 'source' => 'order']); 
                return;
            }

            /* 3) Product catalog flow */
            $catFlow = $_SESSION[self::S_CAT] ?? null;
            if (is_array($catFlow) && ($catFlow['stage'] ?? '') === 'ask_filters') {
                $reply = $this->handleCatalogFilters($userMsg, $lang, $catFlow);
                $translatedReply = $this->autoTranslateResponse($reply, $lang, 'catalog');
                unset($_SESSION[self::S_CAT]);
                $this->remember('user', $userMsg);
                $this->remember('assistant', $translatedReply);
                $this->out(['reply' => $translatedReply, 'source' => 'catalog']); 
                return;
            }

            if ($intent === 'catalog') {
                $reply = $this->handleCatalogQuery($userMsg, $lang);
                if ($reply !== null) {
                    $translatedReply = $this->autoTranslateResponse($reply, $lang, 'catalog');
                    $this->remember('user', $userMsg);
                    $this->remember('assistant', $translatedReply);
                    $this->out(['reply' => $translatedReply, 'source' => 'catalog']); 
                    return;
                }
            }

            /* 4) Continuation flow - handle yes/no after product search */
            $continueFlow = $_SESSION[self::S_CONTINUE] ?? null;
            if (is_array($continueFlow) && ($continueFlow['stage'] ?? '') === 'awaiting_response') {
                $reply = $this->handleContinuationResponse($userMsg, $lang);
                $translatedReply = $this->autoTranslateResponse($reply, $lang, 'continuation');
                $this->remember('user', $userMsg);
                $this->remember('assistant', $translatedReply);
                $this->out(['reply' => $translatedReply, 'source' => 'continuation']); 
                return;
            }

            /* 5) FAQ Search - with intelligent translation */
            $faqAnswer = $this->searchFAQ($userMsg, $lang, $apiKey, $kbAbs, $embAbs);
            if ($faqAnswer) {
                $translatedFaqAnswer = $this->autoTranslateResponse($faqAnswer, $lang, 'faq');
                $this->remember('user', $userMsg);
                $this->remember('assistant', $translatedFaqAnswer);
                $this->out(['reply' => $translatedFaqAnswer, 'source' => 'faq']); 
                return;
            }

            /* 6) Fallback - Contact support */
            $reply = $this->getFallbackMessage($lang);
            $translatedReply = $this->autoTranslateResponse($reply, $lang, 'fallback');
            $this->remember('user', $userMsg);
            $this->remember('assistant', $translatedReply);
            $this->out(['reply' => $translatedReply, 'source' => 'fallback']);

        } catch (\Throwable $e) {
            $this->log('FATAL: '.$e->getMessage());
            $this->out(['reply'=>'Please contact support.','source'=>'error']);
        }
    }

    /* ==================== FLOW MANAGEMENT ==================== */
    
    private function isInActiveFlow(): bool
    {
        return !empty($_SESSION[self::S_FLOW]) || 
               !empty($_SESSION[self::S_CAT]) ||
               !empty($_SESSION[self::S_RATING]) ||
               !empty($_SESSION[self::S_CONTINUE]);
    }
    
    private function hasEligibleCompletedOrders(): bool
    {
        $ctx = Context::getContext();
        $customer = $ctx->customer;
        
        if (!$customer || !$customer->id) {
            return false;
        }
        
        // Check if customer has completed orders eligible for rating
        $sql = 'SELECT COUNT(*) as count
                FROM '._DB_PREFIX_.'orders o
                WHERE o.id_customer = '.(int)$customer->id.'
                  AND o.valid = 1
                  AND o.current_state IN ('.implode(',', $this->getCompletedOrderStates()).')
                  AND TIMESTAMPDIFF(HOUR, o.date_add, NOW()) >= '.self::RATING_MIN_ORDER_AGE.'
                  AND TIMESTAMPDIFF(HOUR, o.date_add, NOW()) <= '.self::RATING_MAX_ORDER_AGE;
                  
        $count = (int)$this->dbValue($sql);
        return $count > 0;
    }
    
    private function getCompletedOrderStates(): array
    {
        // Get order states that indicate completion
        $states = [];
        
        if ($delivered = Configuration::get('PS_OS_DELIVERED')) {
            $states[] = (int)$delivered;
        }
        if ($shipped = Configuration::get('PS_OS_SHIPPING')) {
            $states[] = (int)$shipped;
        }
        if ($payment = Configuration::get('PS_OS_PAYMENT')) {
            $states[] = (int)$payment;
        }
        
        return $states ?: [2, 3, 4]; // Fallback to common state IDs
    }

    /* ====================== SMALL TALK ====================== */

    private function handleSmallTalk(string $message, string $lang): string
    {
        $lowerMsg = mb_strtolower($message, 'UTF-8');
        
        // Define responses directly
        if ($lang === 'fr') {
            $greetingPatterns = ['salut', 'bonjour', 'coucou', 'hello', 'hi'];
            $greetingReplies = [
                "Salut ! 👋 Comment puis-je vous aider (produits, prix, stock, commandes, retours) ?",
                "Bonjour ! En quoi puis-je vous être utile aujourd'hui ?",
                "Bonjour ! Je suis là pour vous aider. Que cherchez-vous ?"
            ];
            
            $thanksPatterns = ['merci', 'thank you', 'thanks'];
            $thanksReplies = [
                "De rien ! Autre chose ?",
                "Je vous en prie ! Y a-t-il autre chose ?",
                "Avec plaisir ! Puis-je vous aider davantage ?"
            ];
            
            $defaultReplies = [
                "D'accord ! Dites-moi ce dont vous avez besoin.",
                "Très bien ! Comment puis-je vous aider ?"
            ];
        } else {
            $greetingPatterns = ['hi', 'hello', 'hey', 'bonjour', 'salut'];
            $greetingReplies = [
                "Hi! 👋 How can I help (products, prices, stock, orders, returns)?",
                "Hello! What can I assist you with today?",
                "Hey there! I'm here to help. What are you looking for?"
            ];
            
            $thanksPatterns = ['thanks', 'thank you', 'merci'];
            $thanksReplies = [
                "You're welcome! Anything else I can help with?",
                "My pleasure! Is there anything else?",
                "Happy to help! What else can I do for you?"
            ];
            
            $defaultReplies = [
                "Okay! Tell me what you need.",
                "Alright! How can I help you?"
            ];
        }
        
        // Check greeting patterns
        if ($this->matchesPattern($lowerMsg, $greetingPatterns)) {
            return $this->randomChoice($greetingReplies);
        }
        
        // Check thanks patterns
        if ($this->matchesPattern($lowerMsg, $thanksPatterns)) {
            return $this->randomChoice($thanksReplies);
        }
        
        return $this->randomChoice($defaultReplies);
    }
    
    
    private function matchesPattern(string $text, array $patterns): bool
    {
        // Do not treat emails as smalltalk triggers
        if ($this->extractEmail($text) !== null) {
            return false;
        }

        foreach ($patterns as $pattern) {
            // Use regex with "word-ish" boundaries to avoid matching inside words/usernames/emails
            $quoted = preg_quote($pattern, '/');
            if (preg_match('/(?<![A-Za-z0-9_])' . $quoted . '(?![A-Za-z0-9_])/iu', $text)) {
                return true;
            }
        }
        return false;
    }
    
    private function randomChoice(array $options): string
    {
        return $options[array_rand($options)];
    }

    /* ====================== ORDER FLOW ====================== */
    
    private function handleOrderFlow(string $message, string $lang): string
    {
        $state = $_SESSION[self::S_FLOW] ?? ['stage' => 'start', 'email' => null];
        
        // Always use original message to preserve emails/order IDs
        $originalMessage = $_SESSION['original_user_msg'] ?? $message;
        $typedEmail = $this->extractEmail($originalMessage);
        $orderInfo = $this->extractOrderInfo($originalMessage);
        
        $ctx = Context::getContext();
        $customer = $ctx->customer;
        $isLoggedIn = ($customer && $customer->id);
        $loggedEmail = $isLoggedIn ? strtolower(trim($customer->email)) : null;
        
        switch ($state['stage']) {
            case 'start':
                $_SESSION[self::S_FLOW] = ['stage' => 'need_email', 'email' => null];
                return $this->getLocalizedMessage('order_need_email', $lang);
                
            case 'need_email':
                if (!$typedEmail) {
                    return $this->getLocalizedMessage('order_email_required', $lang);
                }
                
                $_SESSION[self::S_FLOW]['email'] = $typedEmail;
                
                // Security check - email must match logged in user
                if (!$isLoggedIn || $loggedEmail !== strtolower($typedEmail)) {
                    unset($_SESSION[self::S_FLOW]);
                    return $this->getLocalizedMessage('order_security_error', $lang);
                }
                
                // Capture email for newsletter
                $this->captureNewsletterEmail($typedEmail);
                
                $_SESSION[self::S_FLOW]['stage'] = 'need_id';
                return $this->getLocalizedMessage('order_need_id', $lang);
                
            case 'need_id':
                if (!$orderInfo['id'] && !$orderInfo['reference']) {
                    return $this->getLocalizedMessage('order_id_required', $lang);
                }
                
                unset($_SESSION[self::S_FLOW]);
                return $this->getOrderStatus($orderInfo, $lang);
                
            default:
                unset($_SESSION[self::S_FLOW]);
                return $this->getLocalizedMessage('order_restart', $lang);
        }
    }

    private function orderFlowHandle(string $text, string $lang): string
    {
        $state = $_SESSION[self::S_FLOW] ?? ['stage' => 'start', 'email' => null];

        // Always extract email and order info from the ORIGINAL user message to avoid translation issues
        // We'll get the original message from the session or use current text as fallback
        $originalText = $_SESSION['original_user_msg'] ?? $text;
        $typedEmail = $this->extractEmail($originalText);
        $oid        = $this->extractOrderRefOrId($originalText);

        $ctx = Context::getContext();
        $cust = $ctx->customer;
        $isLogged = ($cust && $cust->id);
        $loggedEmail = $isLogged ? strtolower(trim($cust->email)) : null;

        if ($state['stage'] === 'start') {
            $_SESSION[self::S_FLOW] = ['stage'=>'need_email','email'=>null];
            return "To check your order, please provide your **email address**.";
        }

        if ($state['stage'] === 'need_email') {
            if (!$typedEmail) {
                return "I'll need your **email** first to continue.";
            }
            $_SESSION[self::S_FLOW]['email'] = $typedEmail;

            if (!$isLogged || $loggedEmail !== strtolower($typedEmail)) {
                unset($_SESSION[self::S_FLOW]);
                return "Sorry — for security, I can only show status if you're signed in with **that same email**. Please sign in and try again.";
            }

            // Only capture email for newsletter AFTER successful verification
            $this->captureNewsletterEmail($typedEmail);
            
            $_SESSION[self::S_FLOW]['stage'] = 'need_id';
            return "Great, thanks! Now your **order number** (reference/ID).";
        }

        if ($state['stage'] === 'need_id') {
            if (!($oid['id'] || $oid['ref'])) {
                return "Please share your **order number** (reference/ID).";
            }
            unset($_SESSION[self::S_FLOW]);
            return $this->renderOrderStatus($oid['id'], $oid['ref'], $lang);
        }

        unset($_SESSION[self::S_FLOW]);
        return "Let's start again with your **email address**.";
    }

    private function renderOrderStatus(?int $id, ?string $ref, string $lang): string
    {
        try {
            $rowId = null;
            if ($ref) {
                $rowId = (int)$this->dbValue('SELECT id_order FROM '._DB_PREFIX_.'orders WHERE reference=\''.pSQL($ref).'\'');
            }
            if (!$rowId && $id) {
                $rowId = (int)$this->dbValue('SELECT id_order FROM '._DB_PREFIX_.'orders WHERE id_order='.(int)$id);
            }

            if ($rowId) {
                $o = new Order((int)$rowId);
                if (Validate::isLoadedObject($o)) {
                    $st = new OrderState($o->current_state);
                    $idLang = (int)Context::getContext()->language->id;
                    $name = $st->name[$idLang] ?? ($st->name[1] ?? 'Unknown');
                    
                    // Add order date and total for more info
                    $date = date('d/m/Y', strtotime($o->date_add));
                    $total = Tools::displayPrice($o->total_paid);
                    
                    return "Order **#{$o->reference}** from {$date} ({$total}) is **{$name}**.";
                }
            }
        } catch (\Throwable $e) {
            $this->log('ORDER STATUS ERR: '.$e->getMessage());
        }

        return "Sorry, I couldn't find an order with that number. Please double-check and resend.";
    }

    private function extractEmail(string $text): ?string
    {
        if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $text, $m)) {
            $em = strtolower(trim($m[1]));
            return Validate::isEmail($em) ? $em : null;
        }
        return null;
    }

    private function extractOrderRefOrId(string $text): array
    {
        $ref=null; $id=null;
        $words=preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
        $stop=['ORDER','COMMANDE','STATUS','STATUT','TRACKING','SUIVI','ETAT','ÉTAT'];

        foreach($words as $w){
            $w=trim($w);
            if(preg_match('/^\d{1,10}$/',$w)){ $id=(int)$w; break; }
            if(preg_match('/^[A-Za-z0-9-]{6,16}$/',$w)){
                $tok=strtoupper($w);
                if(in_array($tok,$stop,true)) continue;
                if(preg_match('/^[A-Z]{6,16}$/',$tok) || preg_match('/\d/',$tok)){
                    $ref=$tok; break;
                }
            }
        }
        return ['id'=>$id?:null,'ref'=>$ref?:null];
    }

    private function captureNewsletterEmail(string $email): void
    {
        $email = trim($email);
        if ($email === '' || !Validate::isEmail($email)) return;

        try {
            $tbl = _DB_PREFIX_.'emailsubscription';
            $exists = Db::getInstance()->executeS('SHOW TABLES LIKE \''.$tbl.'\'');
            if (!$exists) return;

            $already = (int)Db::getInstance()->getValue('SELECT id FROM '.$tbl.' WHERE email=\''.pSQL($email).'\'');
            if ($already) return;

            $ctx = Context::getContext();
            Db::getInstance()->insert('emailsubscription', [
                'id_shop'                   => (int)$ctx->shop->id,
                'id_shop_group'             => (int)$ctx->shop->id_shop_group,
                'email'                     => pSQL($email),
                'newsletter_date_add'       => date('Y-m-d H:i:s'),
                'ip_registration_newsletter'=> pSQL(Tools::getRemoteAddr()),
                'http_referer'              => pSQL($_SERVER['HTTP_REFERER'] ?? ''),
                'active'                    => 0,
                'id_lang'                   => (int)$ctx->language->id,
            ]);
        } catch (\Throwable $e) {
            $this->log('CAPTURE ERR: '.$e->getMessage());
        }
    }

    /* ====================== CATALOG FLOW ====================== */

    // STEP 1: find category, confirm, ask for filters (no listing)
    private function catalogConfirmCategory(string $text, string $lang): ?string
    {
        $idLang = (int)Context::getContext()->language->id;
        $tokens = $this->tok($text);
        if (!$tokens) return null;

        $this->log('CATEGORY SEARCH: Original tokens: '.json_encode($tokens));
        
        // Expand tokens to include plural/singular variations
        $expandedTokens = $this->expandWordVariations($tokens);
        $this->log('CATEGORY SEARCH: Expanded tokens: '.json_encode($expandedTokens));

        $likes = [];
        foreach ($expandedTokens as $t) {
            if (mb_strlen($t,'UTF-8') < 3) continue;
            $likes[] = "cl.name LIKE '".pSQL('%'.$t.'%')."'";
        }
        if (!$likes) {
            $this->log('CATEGORY SEARCH: No valid tokens for LIKE clauses');
            return null;
        }
        $this->log('CATEGORY SEARCH: LIKE clauses count: '.count($likes));

        // categories that match tokens and actually contain at least 1 active product
        $sql = 'SELECT DISTINCT c.id_category, cl.name
                FROM '._DB_PREFIX_.'category c
                INNER JOIN '._DB_PREFIX_.'category_lang cl
                  ON (cl.id_category=c.id_category AND cl.id_lang='.$idLang.')
                INNER JOIN '._DB_PREFIX_.'category_product cp ON cp.id_category=c.id_category
                INNER JOIN '._DB_PREFIX_.'product p ON p.id_product=cp.id_product AND p.active=1
                WHERE c.active=1 AND c.id_category > 2 AND ('.implode(' OR ',$likes).')
                ORDER BY c.level_depth ASC, c.id_category ASC
                LIMIT 6';
        
        $this->log('CATEGORY SEARCH: '.substr($sql, 0, 200).'...');
        $rows = Db::getInstance()->executeS($sql) ?: [];
        $this->log('CATEGORY RESULTS: '.count($rows).' found');
        if (!$rows) {
            return "We don't have this category in the store. Please try another **category name** like shoes, clothing, electronics, etc.";
        }

        $catIds = []; $catNames = [];
        foreach ($rows as $r) { $catIds[] = (int)$r['id_category']; $catNames[] = $r['name']; }

        // save flow state for step 2
        $_SESSION[self::S_CAT] = [
            'stage'     => 'ask_filters',
            'cat_ids'   => $catIds,
            'cat_names' => $catNames,
        ];

        $list = implode(', ', $catNames);
        return "Yes — we carry **{$list}**. Now tell me your **filters**: *brand*, *color*, *size*.";
    }

    // STEP 2: apply filters and list exact matches
    private function catalogListWithFilters(string $text, string $lang, array $flow): string
    {
        $idLang = (int)Context::getContext()->language->id;
        $tokens = $this->tok($text);
        $filter = $this->extractFiltersFromMessage($tokens, $idLang);

        $ids = array_map('intval', (array)($flow['cat_ids'] ?? []));
        if (!$ids) {
            return "I couldn't determine the category. Let's start again.";
        }

        $prods = $this->findProductsInCategories($ids, $idLang, $filter, 24);
        if (!$prods) {
            // see if category has any items at all
            $plainCnt = (int)$this->dbValue('SELECT COUNT(DISTINCT p.id_product)
                     FROM '._DB_PREFIX_.'product p
                     INNER JOIN '._DB_PREFIX_.'category_product cp ON cp.id_product=p.id_product
                     WHERE p.active=1 AND cp.id_category IN ('.implode(',', $ids).')');
            if ($plainCnt === 0) {
                return "That category is empty right now. Please check back later.";
            }
            return "No items match those filters. Try a different **brand/color/size**.";
        }

        // present matches with clickable URLs
        $lines = [];
        foreach ($prods as $p) {
            // Format: Clean clickable HTML link
            $lines[] = '• <a href="'.$p['url'].'" target="_blank">'.$p['name'].'</a>';
        }
        $msg = "Matching results:\n";
        return $msg . implode("\n", $lines);
    }

    private function extractFiltersFromMessage(array $tokens, int $idLang): array
    {
        $db = Db::getInstance();

        // BRANDS
        $brandIds = [];
        if ($tokens) {
            $like = implode(' OR ', array_map(function($t){ return "m.name LIKE '".pSQL('%'.$t.'%')."'"; }, $tokens));
            $rows = $db->executeS('SELECT id_manufacturer FROM '._DB_PREFIX_.'manufacturer m WHERE ('.$like.') LIMIT 16') ?: [];
            foreach ($rows as $r) $brandIds[] = (int)$r['id_manufacturer'];
        }

        // ATTRIBUTES (match across all languages to handle FR/EN tokens)
        $attrIds = [];
        if ($tokens) {
            $like = implode(' OR ', array_map(function($t){ return "al.name LIKE '".pSQL('%'.$t.'%')."'"; }, $tokens));
            $rows = $db->executeS('SELECT DISTINCT a.id_attribute
                      FROM '._DB_PREFIX_.'attribute a
                      INNER JOIN '._DB_PREFIX_.'attribute_lang al ON al.id_attribute=a.id_attribute
                      WHERE ('.$like.')
                      LIMIT 64') ?: [];
            foreach ($rows as $r) $attrIds[] = (int)$r['id_attribute'];
        }

        return [
            'brands' => array_values(array_unique($brandIds)),
            'attrs'  => array_values(array_unique($attrIds)),
        ];
    }

    private function findProductsInCategories(array $catIds, int $idLang, array $filter, int $limit = 24, array $nameTokens = []): array
    {
        if (!$catIds) return [];
        $catIds = $this->expandCategoryIds($catIds);

        $brands = array_values(array_unique(array_map('intval', (array)($filter['brands'] ?? []))));
        $attrs  = array_values(array_unique(array_map('intval', (array)($filter['attrs'] ?? []))));

        // Stages: brand+ALL attrs, brand+ANY attr, brand only, ALL attrs, ANY attr, plain category
        $stages = [
            [$brands, $attrs, 'all'],
            [$brands, $attrs, 'any'],
            [$brands, [],     'any'],
            [[],      $attrs, 'all'],
            [[],      $attrs, 'any'],
            [[],      [],     'any'],
        ];
        
        $this->log('CAT SEARCH: Starting with brands='.count($brands).', attrs='.count($attrs).', stages='.count($stages));

        foreach ($stages as $idx => $st) {
            [$b, $a, $mode] = $st;
            $this->log('CAT SEARCH stage='.$idx.' mode='.$mode.' catIds='.json_encode($catIds).' brands='.json_encode($b).' attrs='.json_encode($a).' tokens='.json_encode($nameTokens));
            $rows = $this->runProductQuery($catIds, $idLang, $b, $a, $limit, $nameTokens, $mode);
            if ($rows) return $rows;
        }

        if ($nameTokens) {
            $this->log('CAT SEARCH final fallback across catalog by name/brand');
            $rows = $this->finalNameBrandSearch($idLang, $nameTokens, $limit);
            if ($rows) return $rows;
        }

        return [];
    }

    private function runProductQuery(array $catIds, int $idLang, array $brandIds, array $attrIds, int $limit, array $nameTokens = [], string $attrMode = 'all'): array
    {
        $db = Db::getInstance();
        $w = [];
        $w[] = 'p.active=1';
        $w[] = 'cp.id_category IN ('.implode(',', array_map('intval',$catIds)).')';

        if (!empty($brandIds)) {
            $w[] = 'p.id_manufacturer IN ('.implode(',', $brandIds).')';
        }

        if (!empty($attrIds)) {
            if ($attrMode === 'all') {
                // product must have all requested attributes (in some combination set)
                $w[] = 'p.id_product IN (
                    SELECT pa2.id_product
                    FROM '._DB_PREFIX_.'product_attribute pa2
                    INNER JOIN '._DB_PREFIX_.'product_attribute_combination pac2
                        ON pac2.id_product_attribute = pa2.id_product_attribute
                    WHERE pac2.id_attribute IN ('.implode(',', $attrIds).')
                    GROUP BY pa2.id_product
                    HAVING COUNT(DISTINCT pac2.id_attribute) >= '.(int)count($attrIds).'
                )';
            } else {
                // any of the attributes
                $w[] = 'p.id_product IN (
                    SELECT DISTINCT pa2.id_product
                    FROM '._DB_PREFIX_.'product_attribute pa2
                    INNER JOIN '._DB_PREFIX_.'product_attribute_combination pac2
                        ON pac2.id_product_attribute = pa2.id_product_attribute
                    WHERE pac2.id_attribute IN ('.implode(',', $attrIds).')
                )';
            }
        }

        $sql = 'SELECT DISTINCT p.id_product, pl.name, p.price
                FROM '._DB_PREFIX_.'product p
                INNER JOIN '._DB_PREFIX_.'product_lang pl ON (pl.id_product=p.id_product AND pl.id_lang='.(int)$idLang.')
                INNER JOIN '._DB_PREFIX_.'category_product cp ON cp.id_product=p.id_product
                WHERE '.implode(' AND ', $w).'
                ORDER BY p.date_upd DESC
                LIMIT '.(int)$limit;

        $rows = $db->executeS($sql) ?: [];
        $this->log('CAT SEARCH rows='.count($rows).' mode='.$attrMode.' sql='.substr($sql,0,200).'...');

        // Name/manufacturer fallback inside the categories when nothing matched
        if (!$rows && $nameTokens) {
            $nameLikes = [];
            $brandLikes = [];
            foreach ($nameTokens as $t) {
                if (mb_strlen($t, 'UTF-8') >= 2) {
                    $nameLikes[]  = "plx.name LIKE '".pSQL('%'.$t.'%')."'";
                    $brandLikes[] = "m.name LIKE '".pSQL('%'.$t.'%')."'";
                }
            }
            if ($nameLikes || $brandLikes) {
                $cond = [];
                if ($nameLikes)  $cond[] = 'EXISTS (SELECT 1 FROM '._DB_PREFIX_.'product_lang plx WHERE plx.id_product=p.id_product AND ('.implode(' OR ', $nameLikes).'))';
                if ($brandLikes) $cond[] = '('.implode(' OR ', $brandLikes).')';
                $whereLike = implode(' OR ', $cond);

                // 1) Try inside selected categories by name/brand
                $sql1 = '
                    SELECT DISTINCT p.id_product,
                           (SELECT plx2.name FROM '._DB_PREFIX_.'product_lang plx2 WHERE plx2.id_product=p.id_product ORDER BY plx2.id_lang ASC LIMIT 1) AS name,
                           p.price
                    FROM '._DB_PREFIX_.'product p
                    INNER JOIN '._DB_PREFIX_.'category_product cp ON cp.id_product=p.id_product
                    LEFT JOIN '._DB_PREFIX_.'manufacturer m ON m.id_manufacturer=p.id_manufacturer
                    WHERE p.active=1
                      AND cp.id_category IN ('.implode(',', array_map('intval',$catIds)).')
                      AND ('.$whereLike.')
                    ORDER BY p.date_upd DESC
                    LIMIT '.(int)$limit;
                $rows = $db->executeS($sql1) ?: [];
                $this->log('CAT SEARCH fallback-in-cat rows='.count($rows).' sql='.substr($sql1,0,200).'...');

                // 2) If still empty, search the whole catalog by name/brand (ignore category)
                if (!$rows) {
                    $sql2 = '
                        SELECT DISTINCT p.id_product,
                               (SELECT plx2.name FROM '._DB_PREFIX_.'product_lang plx2 WHERE plx2.id_product=p.id_product ORDER BY plx2.id_lang ASC LIMIT 1) AS name,
                               p.price
                        FROM '._DB_PREFIX_.'product p
                        LEFT JOIN '._DB_PREFIX_.'manufacturer m ON m.id_manufacturer=p.id_manufacturer
                        WHERE p.active=1
                          AND ('.$whereLike.')
                        ORDER BY p.date_upd DESC
                        LIMIT '.(int)$limit;
                    $rows = $db->executeS($sql2) ?: [];
                    $this->log('CAT SEARCH fallback-global rows='.count($rows).' sql='.substr($sql2,0,200).'...');
                }
            }
        }

        // 3) Last rescue: if brand+attrs were requested and no rows still, ignore brand but keep attrs across catalog
        if (!$rows && !empty($attrIds)) {
            $this->log('CAT SEARCH rescue: global by attributes only');
            $attrCond = 'p.id_product IN (
                SELECT pa2.id_product
                FROM '._DB_PREFIX_.'product_attribute pa2
                INNER JOIN '._DB_PREFIX_.'product_attribute_combination pac2
                    ON pac2.id_product_attribute = pa2.id_product_attribute
                WHERE pac2.id_attribute IN ('.implode(',', array_map('intval',$attrIds)).')
                GROUP BY pa2.id_product
                HAVING COUNT(DISTINCT pac2.id_attribute) >= '.(int)count($attrIds).'
            )';
            $sql3 = '
                SELECT DISTINCT p.id_product,
                       (SELECT plx2.name FROM '._DB_PREFIX_.'product_lang plx2 WHERE plx2.id_product=p.id_product ORDER BY plx2.id_lang ASC LIMIT 1) AS name,
                       p.price
                FROM '._DB_PREFIX_.'product p
                WHERE p.active=1 AND '.$attrCond.'
                ORDER BY p.date_upd DESC
                LIMIT '.(int)$limit;
            $rows = $db->executeS($sql3) ?: [];
            $this->log('CAT SEARCH rescue-attrs rows='.count($rows).' sql='.substr($sql3,0,200).'...');
        }

        if (!$rows) return [];

        $currency = new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
        $out = [];
        foreach ($rows as $r) {
            try {
                $productPrice = Product::getPriceStatic((int)$r['id_product'], true, null, 6, null, false, true, 1);
                $price = Tools::displayPrice($productPrice, $currency);
            } catch (\Throwable $e) {
                $this->log('PRODUCT PRICE ERR: '.$e->getMessage());
                $price = Tools::displayPrice((float)$r['price'], $currency);
            }
            try {
                $stock = StockAvailable::getQuantityAvailableByProduct((int)$r['id_product']);
            } catch (\Throwable $e) {
                $stock = 0;
            }
            $stockInfo = $stock > 0 ? " (Stock: {$stock})" : " (Out of stock)";
            $productUrl = Context::getContext()->link->getProductLink((int)$r['id_product'], null, null, null, $idLang);

            $out[] = [
                'id'    => (int)$r['id_product'],
                'name'  => $r['name'],
                'price' => $price . $stockInfo,
                'url'   => $productUrl,
            ];
        }
        return $out;
    }

    private function finalNameBrandSearch(int $idLang, array $nameTokens, int $limit = 24): array
    {
        $db = Db::getInstance();
        $likes = [];
        foreach ($nameTokens as $t) {
            if (mb_strlen($t, 'UTF-8') >= 2) {
                $likes[] = "pl.name LIKE '".pSQL('%'.$t.'%')."'";
                $likes[] = "m.name LIKE '".pSQL('%'.$t.'%')."'";
            }
        }
        if (!$likes) return [];
        $nameLikes = [];
        $brandLikes = [];
        foreach ($nameTokens as $t) {
            if (mb_strlen($t, 'UTF-8') >= 2) {
                $nameLikes[]  = "plx.name LIKE '".pSQL('%'.$t.'%')."'";
                $brandLikes[] = "m.name LIKE '".pSQL('%'.$t.'%')."'";
            }
        }
        if (!$nameLikes && !$brandLikes) return [];
        $cond = [];
        if ($nameLikes)  $cond[] = 'EXISTS (SELECT 1 FROM '._DB_PREFIX_.'product_lang plx WHERE plx.id_product=p.id_product AND ('.implode(' OR ', $nameLikes).'))';
        if ($brandLikes) $cond[] = '('.implode(' OR ', $brandLikes).')';
        $whereLike = implode(' OR ', $cond);

        $sql = '
            SELECT DISTINCT p.id_product,
                   (SELECT plx2.name FROM '._DB_PREFIX_.'product_lang plx2 WHERE plx2.id_product=p.id_product ORDER BY plx2.id_lang ASC LIMIT 1) AS name,
                   p.price
            FROM '._DB_PREFIX_.'product p
            LEFT JOIN '._DB_PREFIX_.'manufacturer m ON m.id_manufacturer=p.id_manufacturer
            WHERE p.active=1
              AND ('.$whereLike.')
            ORDER BY p.date_upd DESC
            LIMIT '.(int)$limit;
        $rows = $db->executeS($sql) ?: [];
        $this->log('CAT SEARCH finalNameBrand rows='.count($rows).' sql='.substr($sql,0,200).'...');
        if (!$rows) return [];

        $currency = new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
        $out = [];
        foreach ($rows as $r) {
            try {
                $productPrice = Product::getPriceStatic((int)$r['id_product'], true, null, 6, null, false, true, 1);
                $price = Tools::displayPrice($productPrice, $currency);
            } catch (\Throwable $e) {
                $price = Tools::displayPrice((float)$r['price'], $currency);
            }
            $productUrl = Context::getContext()->link->getProductLink((int)$r['id_product'], null, null, null, $idLang);
            $out[] = [
                'id'    => (int)$r['id_product'],
                'name'  => $r['name'],
                'price' => $price,
                'url'   => $productUrl,
            ];
        }
        return $out;
    }

    private function expandCategoryIds(array $catIds): array
    {
        $catIds = array_values(array_unique(array_map('intval', $catIds)));
        if (!$catIds) return [];
        try {
            $db = Db::getInstance();
            $idsSql = implode(',', $catIds);
            // fetch nleft/nright for selected categories
            $parents = $db->executeS('SELECT id_category, nleft, nright FROM '._DB_PREFIX_.'category WHERE id_category IN ('.$idsSql.')') ?: [];
            if (!$parents) return $catIds;
            $ranges = [];
            foreach ($parents as $p) {
                $ranges[] = '(nleft BETWEEN '.(int)$p['nleft'].' AND '.(int)$p['nright'].')';
            }
            $where = implode(' OR ', $ranges);
            $children = $db->executeS('SELECT id_category FROM '._DB_PREFIX_.'category WHERE '.$where) ?: [];
            $out = $catIds;
            foreach ($children as $c) { $out[] = (int)$c['id_category']; }
            return array_values(array_unique($out));
        } catch (\Throwable $e) {
            $this->log('EXPAND CAT ERR: '.$e->getMessage());
            return $catIds;
        }
    }

    /* ========================= FAQ ========================= */

    private function faqFromEmbeddings(string $q,string $apiKey,string $embPath): ?string
    {
        $kb = $this->loadKB($embPath); if(!$kb) return null;

        $kwBest  = $this->bm25Best($q,$kb);
        $kwScore = $kwBest['score'] ?? 0.0;
        $kwAns   = $kwBest['a'] ?? null;

        $semScore = 0.0; $semAns = null;
        if ($apiKey) {
            $sem = $this->semanticBest($q,$apiKey,$kb);
            if ($sem){ $semScore=$sem['score']; $semAns=$sem['a']; }
        }

        $blend = (0.70*$semScore) + (0.50*min(1,$kwScore));
        if ($semScore>=self::SEM_THRESHOLD || $kwScore>=self::KW_GOOD || $blend>=self::BLEND_ACCEPT) {
            if ($semScore>=$kwScore && $semAns) return $semAns;
            if ($kwAns) return $kwAns;
        }
        return null;
    }

    private function faqFromRawTxt(string $q, string $rawTxt): ?string
    {
        $lines = preg_split('/\R/u', $rawTxt);
        $pairs = []; $curQ=null; $curA=[];
        $flush=function()use(&$pairs,&$curQ,&$curA){
            if($curQ!==null && $curA){ $pairs[]=['q'=>trim($curQ),'a'=>trim(implode("\n",$curA))]; }
            $curQ=null; $curA=[];
        };
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line==='') continue;
            if (preg_match('/^Q\s*[:\-]\s*(.+)$/iu',$line,$m)){ $flush(); $curQ=$m[1]; continue; }
            if ($curQ!==null && preg_match('/^A\s*[:\-]\s*(.+)$/iu',$line,$m)){ $curA[]=$m[1]; continue; }
            if ($curQ!==null && $curA){ $curA[]=$line; }
        }
        $flush();
        if (!$pairs) return null;

        $kb=['pairs'=>[],'idf'=>[]];
        foreach($pairs as $p){
            $kb['pairs'][]=['q'=>$p['q'],'a'=>$p['a'],'qt'=>$this->tok($p['q']),'at'=>$this->tok($p['a'])];
        }
        $df=[]; foreach($kb['pairs'] as $r){ foreach(array_unique(array_merge($r['qt'],$r['at'])) as $t){ $df[$t]=($df[$t]??0)+1; } }
        $N=max(1,count($kb['pairs'])); foreach($df as $t=>$d){ $kb['idf'][$t] = log(($N-$d+0.5)/($d+0.5)+1.0); }

        $best = $this->bm25Best($q,$kb);
        return $best ? ($best['a'] ?? null) : null;
    }

    private function loadKB(string $embPath): ?array
    {
        if(!$embPath || !is_file($embPath)) return null;
        $raw = json_decode(@file_get_contents($embPath),true); if(!$raw) return null;
        $pairsIn = $raw['pairs'] ?? $raw; $idf = $raw['idf'] ?? [];
        $pairs=[];
        foreach ($pairsIn as $p){
            $q=$p['q'] ?? ($p['question'] ?? null);
            $a=$p['a'] ?? ($p['answer'] ?? null);
            if(!$q || !$a) continue;
            $pairs[] = [
                'q'=>$q,'a'=>$a,
                'qt'=>$p['qt'] ?? $this->tok($q),
                'at'=>$p['at'] ?? $this->tok($a),
                'embedding'=>$p['embedding'] ?? [],
            ];
        }
        return ['pairs'=>$pairs,'idf'=>$idf];
    }

    private function bm25Best(string $q,array $kb): ?array
    {
        $qt=$this->tok($q); if(!$qt) return null;
        $this->log('BM25: Query tokens: '.json_encode($qt));

        $idf = $kb['idf'] ?? [];
        $k1=1.4; $b=0.75;

        $lens=[]; foreach($kb['pairs'] as $p) $lens[] = count($p['qt'])+count($p['at']);
        $avgLen = $lens? array_sum($lens)/count($lens) : 1.0;

        $best=null; $bestScore=0.0;
        foreach($kb['pairs'] as $p){
            $doc=array_merge($p['qt'],$p['at']); $len=max(1,count($doc)); $score=0.0;
            foreach($qt as $t){
                $tf=0; foreach($doc as $d) if($d===$t) $tf++;
                if(!$tf) continue;
                $idfw = $idf[$t] ?? 0.5;
                $score += $idfw * ($tf*($k1+1)) / ($tf + $k1*(1-$b+$b*$len/$avgLen));
            }
            if($score>$bestScore){ $bestScore=$score; $best=$p; }
        }
        
        $this->log('BM25: Best score='.$bestScore.', threshold='.self::KW_THRESHOLD_MIN);
        if ($best && $bestScore < self::KW_THRESHOLD_MIN) {
            $this->log('BM25: Score too low, rejecting');
            return null;
        }
        return $best? ['answer'=>$best['a'],'score'=>$bestScore] : null;
    }

    private function semanticBest(string $q,string $apiKey,array $kb): ?array
    {
        try {
            if (!$apiKey) return null;
            $client=$this->http($apiKey);
            $resp = $client->post('embeddings',['json'=>['model'=>self::EMBED_MODEL,'input'=>$q]]);
            $data = json_decode((string)$resp->getBody(), true);
            $vec  = $data['data'][0]['embedding'] ?? null;
            if(!$vec) return null;
        } catch (\Throwable $e) {
            $this->log('EMBED ERR: '.$e->getMessage());
            return null;
        }
        $best=null; $bestScore=0.0;
        foreach($kb['pairs'] as $p){
            if (empty($p['embedding'])) continue;
            $s = $this->cos($vec,$p['embedding']);
            if($s>$bestScore){ $bestScore=$s; $best=$p; }
        }
        return $best? ['a'=>$best['a'],'score'=>$bestScore] : null;
    }

    /* =================== DIRECT PRODUCT QUERIES =================== */
    
    private function isPriceStockQuery(string $text): bool
    {
        $s = mb_strtolower($text, 'UTF-8');
        return preg_match('/\b(price|prix|cost|combien|coute|coûte|stock|available|disponible)\b/u', $s);
    }
    
    private function handleDirectProductQuery(string $text, string $lang): ?string
    {
        $idLang = (int)Context::getContext()->language->id;
        $tokens = $this->tok($text);
        if (!$tokens) return null;
        
        // Detect what type of query this is
        $s = mb_strtolower($text, 'UTF-8');
        $isPriceQuery = preg_match('/\b(price|prix|cost|combien|coute|coûte|how much|quel prix)\b/u', $s);
        $isStockQuery = preg_match('/\b(stock|available|disponible|inventory|inventaire)\b/u', $s);
        
        // Remove price/stock keywords to get product terms
        $productTokens = array_filter($tokens, function($token) {
            return !preg_match('/^(price|prix|cost|combien|coute|coûte|stock|available|disponible|inventory|inventaire|how|much|quel)$/u', $token);
        });
        
        if (!$productTokens) return null;
        
        // Expand tokens for better matching
        $expandedTokens = $this->expandWordVariations($productTokens);
        
        // Search directly in products by name
        $likes = [];
        foreach ($expandedTokens as $t) {
            if (mb_strlen($t,'UTF-8') >= 3) {
                $likes[] = "pl.name LIKE '".pSQL('%'.$t.'%')."'";
            }
        }
        
        if (!$likes) return null;
        
        $sql = 'SELECT DISTINCT p.id_product, pl.name, p.price
                FROM '._DB_PREFIX_.'product p
                INNER JOIN '._DB_PREFIX_.'product_lang pl ON (pl.id_product=p.id_product AND pl.id_lang='.$idLang.')
                WHERE p.active=1 AND ('.implode(' OR ', $likes).')
                ORDER BY pl.name ASC
                LIMIT 10';
        
        $rows = Db::getInstance()->executeS($sql) ?: [];
        if (!$rows) return null;
        
        // Format results based on query type
        $currency = new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
        $results = [];
        
        foreach ($rows as $row) {
            try {
                $productPrice = Product::getPriceStatic(
                    (int)$row['id_product'],
                    true, // include tax
                    null, // no specific combination
                    6, // price precision
                    null, // specific price rule
                    false, // not only reduction
                    true, // use reduction
                    1 // quantity
                );
                $price = Tools::displayPrice($productPrice, $currency);
                
                // Stock
                $stockQty = 0;
                try { $stockQty = StockAvailable::getQuantityAvailableByProduct((int)$row['id_product']); } catch (\Throwable $e2) { $stockQty = 0; }
                $stockText = $stockQty > 0 ? 'Stock: '.$stockQty : 'Out of stock';

                if ($isPriceQuery && !$isStockQuery) {
                    $results[] = '• '.$row['name'].' — '.$price;
                } elseif ($isStockQuery && !$isPriceQuery) {
                    $results[] = '• '.$row['name'].' — '.$stockText;
                } else {
                    $results[] = '• '.$row['name'].' — '.$price.' — '.$stockText;
                }
            } catch (\Throwable $e) {
                $this->log('DIRECT PRODUCT ERR: '.$e->getMessage());
                $price = Tools::displayPrice((float)$row['price'], $currency);
                $stockQty = 0;
                try { $stockQty = StockAvailable::getQuantityAvailableByProduct((int)$row['id_product']); } catch (\Throwable $e3) { $stockQty = 0; }
                $stockText = $stockQty > 0 ? 'Stock: '.$stockQty : 'Out of stock';
                if ($isPriceQuery && !$isStockQuery) {
                    $results[] = '• '.$row['name'].' — '.$price;
                } elseif ($isStockQuery && !$isPriceQuery) {
                    $results[] = '• '.$row['name'].' — '.$stockText;
                } else {
                    $results[] = '• '.$row['name'].' — '.$price.' — '.$stockText;
                }
            }
        }
        
        if (!$results) return null;
        
        // More specific headers based on query type
        if ($isPriceQuery && !$isStockQuery) {
            $header = "Prices:\n";
        } elseif ($isStockQuery && !$isPriceQuery) {
            $header = "Stock levels:\n";
        } else {
            $header = "Products found:\n";
        }
        
        return $header . implode("\n", $results);
    }

    /* ==================== UNIFIED TRANSLATION SERVICE ==================== */
    
    private static $translationCache = [];
    private static $translationStats = ['hits' => 0, 'misses' => 0, 'errors' => 0];
    
    /**
     * Master translation method - handles all translation needs intelligently
     * @param string $text Text to translate
     * @param string $targetLang Target language (en/fr) 
     * @param string $sourceLang Source language (en/fr) - auto-detected if null
     * @param string $context Context hint for better translation (faq/product/order/etc)
     * @return string Translated text or original if translation not needed
     */
    private function translate(string $text, string $targetLang, ?string $sourceLang = null, string $context = 'general'): string
    {
        $text = trim($text);
        
        // Skip translation if not needed
        if (!$this->shouldTranslate($text) || mb_strlen($text, 'UTF-8') < 2) {
            return $text;
        }
        
        // Auto-detect source language if not provided
        if ($sourceLang === null) {
            $sourceLang = $this->detectLanguage($text);
        }
        
        // No translation needed if source and target are the same
        if ($sourceLang === $targetLang) {
            return $text;
        }
        
        // Log translation request with more detail
        $this->log("TRANSLATION: {$sourceLang}->{$targetLang} [ctx:{$context}]: \"" . substr($text, 0, 100) . '..."');
        $this->log("TRANSLATION: Using Google Translate library for translation");
        
        // Check cache first
        $cacheKey = $this->getTranslationCacheKey($text, $sourceLang, $targetLang, $context);
        if (isset(self::$translationCache[$cacheKey])) {
            self::$translationStats['hits']++;
            $cached = self::$translationCache[$cacheKey];
            $this->log('TRANSLATION: Cache hit - "' . substr($cached, 0, 50) . '..."');
            return $cached;
        }
        
        // Perform translation using Google Translate
        try {
            $translator = new GoogleTranslate($targetLang, $sourceLang);
            
            // Add context-specific optimizations
            $optimizedText = $this->optimizeTextForTranslation($text, $context);
            $translated = $translator->translate($optimizedText);
            
            // Post-process translation
            $finalTranslation = $this->postProcessTranslation($translated, $context, $targetLang);
            
            // Cache the result
            self::$translationCache[$cacheKey] = $finalTranslation;
            self::$translationStats['misses']++;
            
            $this->log("TRANSLATION: Success - \"" . substr($finalTranslation, 0, 50) . '..."');
            return $finalTranslation;
            
        } catch (\Throwable $e) {
            self::$translationStats['errors']++;
            $this->log('TRANSLATION ERROR: ' . $e->getMessage());
            $this->log('TRANSLATION ERROR: Exception class: ' . get_class($e));
            $this->log('TRANSLATION ERROR: Stack trace: ' . $e->getTraceAsString());
            $this->log('TRANSLATION ERROR: Returning original text due to failure');
            return $text; // Return original text if translation fails
        }
    }
    
    /**
     * Smart auto-response translation based on user's detected language
     * This is the main method called throughout the bot
     */
    private function autoTranslateResponse(string $response, string $userLang, string $context = 'general'): string
    {
        $this->log("AUTO TRANSLATE: Input response language detected as: " . $this->detectLanguage($response) . ", user language: " . $userLang);
        
        // Skip translation if response shouldn't be translated
        if (!$this->shouldTranslate($response)) {
            $this->log('AUTO TRANSLATE: Skipping translation (short/numeric/email content)');
            return $response;
        }
        
        // If user is French and response is in English, translate to French
        if ($userLang === 'fr') {
            $detectedResponseLang = $this->detectLanguage($response);
            if ($detectedResponseLang === 'en') {
                $this->log('AUTO TRANSLATE: Translating English response to French');
                return $this->translate($response, 'fr', 'en', $context);
            } else {
                $this->log('AUTO TRANSLATE: Response already in French, no translation needed');
                return $response;
            }
        }
        
        // For English users, return as-is
        $this->log('AUTO TRANSLATE: English user, returning response as-is');
        return $response;
    }
    
    /**
     * Translate user input to English for processing (if needed)
     * Used for FAQ search, intent detection, etc.
     */
    private function translateForProcessing(string $userInput, string $userLang): string
    {
        $this->log("TRANSLATE FOR PROCESSING: Input language: $userLang, input: \"" . substr($userInput, 0, 50) . '..."');
        
        // Only translate French input to English for processing
        if ($userLang === 'fr') {
            $this->log('TRANSLATE FOR PROCESSING: Translating French to English for processing');
            return $this->translate($userInput, 'en', 'fr', 'query');
        }
        
        $this->log('TRANSLATE FOR PROCESSING: No translation needed, returning original');
        return $userInput;
    }
    
    /**
     * Enhanced language detection with better accuracy
     */
    private function detectLanguageAdvanced(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        
        // Strong French indicators (high confidence)
        $strongFrench = [
            // Definite articles and pronouns
            '/\b(je|tu|vous|nous|ils|elles|on|mon|ma|mes|ton|ta|tes|son|sa|ses)\b/u',
            // Common French words
            '/\b(avec|dans|pour|sans|comme|depuis|pendant|avant|après|chez)\b/u',
            // French-specific conjugations
            '/\b(suis|êtes|sont|avons|avez|ont|vais|vas|allons|allez|vont)\b/u',
            // French question words
            '/\b(comment|pourquoi|combien|quand|où|quel|quelle|quels|quelles)\b/u',
            // French connectors
            '/\b(mais|donc|puis|alors|ensuite|cependant|néanmoins)\b/u'
        ];
        
        $frenchScore = 0;
        foreach ($strongFrench as $pattern) {
            if (preg_match($pattern, $text)) {
                $frenchScore += 2; // Higher weight
            }
        }
        
        // Check for French accented characters
        if (preg_match('/[àâäéèêëîïôöùûüç]/u', $text)) {
            $frenchScore += 3; // Very strong indicator
        }
        
        // French greetings and polite words
        if (preg_match('/\b(bonjour|salut|bonsoir|merci|pardon|excusez|désolé)\b/u', $text)) {
            $frenchScore += 2;
        }
        
        // French-specific terms
        if (preg_match('/\b(commande|livraison|retour|prix|produit|article|achat)\b/u', $text)) {
            $frenchScore += 1;
        }
        
        // English indicators (for comparison)
        $englishIndicators = [
            '/\b(the|and|or|but|with|from|have|has|will|would|could|should)\b/u',
            '/\b(order|delivery|return|price|product|item|purchase|tracking)\b/u'
        ];
        
        $englishScore = 0;
        foreach ($englishIndicators as $pattern) {
            if (preg_match($pattern, $text)) {
                $englishScore += 1;
            }
        }
        
        $this->log("LANG DETECT: FR={$frenchScore}, EN={$englishScore} for: \"" . substr($text, 0, 50) . '..."');
        
        return ($frenchScore >= $englishScore && $frenchScore > 0) ? 'fr' : 'en';
    }
    
    /**
     * Generate cache key for translations
     */
    private function getTranslationCacheKey(string $text, string $from, string $to, string $context): string
    {
        return $from . '_to_' . $to . '_' . $context . '_' . md5($text);
    }
    
    /**
     * Optimize text before translation for better results
     */
    private function optimizeTextForTranslation(string $text, string $context): string
    {
        // Keep URLs, emails, and special markers intact
        $text = preg_replace('/\*\*(.*?)\*\*/', '[BOLD]$1[/BOLD]', $text);
        $text = preg_replace('/__(.*?)__/', '[ITALIC]$1[/ITALIC]', $text);
        
        return $text;
    }
    
    /**
     * Post-process translation results
     */
    private function postProcessTranslation(string $translated, string $context, string $targetLang): string
    {
        // Restore formatting
        $translated = preg_replace('/\[BOLD\](.*?)\[\/BOLD\]/', '**$1**', $translated);
        $translated = preg_replace('/\[ITALIC\](.*?)\[\/ITALIC\]/', '__$1__', $translated);
        
        // Fix common translation issues for French
        if ($targetLang === 'fr') {
            // Fix spacing before punctuation (French typography)
            $translated = preg_replace('/\s*([?!:;])/', ' $1', $translated);
        }
        
        return trim($translated);
    }
    
    /**
     * Check if we should translate this text
     */
    private function shouldTranslate(string $text): bool
    {
        $text = trim($text);
        
        // Skip very short texts
        if (mb_strlen($text, 'UTF-8') < 3) {
            return false;
        }
        
        // Skip if it's mostly numbers, symbols, or URLs
        if (preg_match('/^[0-9@\.,\-\s\#\/]+$/', $text)) {
            return false;
        }
        
        // Skip URLs
        if (preg_match('/^https?:\/\//i', $text)) {
            return false;
        }
        
        // Skip pure emails
        if (preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $text)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get translation statistics for debugging
     */
    private function getTranslationStats(): array
    {
        return self::$translationStats;
    }
    

    /* ====================== RATING SYSTEM ====================== */

    /**
     * Check for pending ratings and show rating request when appropriate
     */
    private function checkAndShowPendingRatings(string $lang, string $originalMessage, string $processedMessage)
    {
        try {
            $ctx = Context::getContext();
            $customer = $ctx->customer;

            // Only show for logged-in customers
            if (!$customer || !$customer->id) {
                return;
            }

            // Ensure tables exist, otherwise skip rating flow entirely
            if (!$this->tableExists(_DB_PREFIX_.'ai_chatbot_rating_pending') || !$this->tableExists(_DB_PREFIX_.'ai_chatbot_ratings')) {
                return;
            }

            $customerId = (int)$customer->id;

            // Only show rating request if this is an actual user message (not system/empty)
            if ($originalMessage === '[system]' || trim($originalMessage) === '' || trim($processedMessage) === '') {
                return; // Don't show rating for system messages or empty messages
            }

            // Check if we have any pending ratings for this customer
            $pendingRating = $this->getPendingRatingForCustomer($customerId);

            // Only show if rating hasn't been requested AND order is at least 1 hour old (to avoid cart interference)
            if ($pendingRating && empty($pendingRating['rating_requested']) && $this->isOrderOldEnough((int)$pendingRating['id_order'])) {
                // Mark as requested to avoid showing repeatedly
                $this->markRatingAsRequested((int)$pendingRating['id_order']);

                // Show rating request
                $ratingMessage = $this->generateRatingRequest((int)$pendingRating['id_order'], $lang);
                $translatedMessage = $this->shouldTranslate($ratingMessage) ? $this->translateToFrench($ratingMessage, $lang) : $ratingMessage;

                // Set rating session
                $_SESSION[self::S_RATING] = [
                    'stage' => 'pending',
                    'order_id' => (int)$pendingRating['id_order']
                ];

                $this->remember('assistant', $translatedMessage);
                $this->out([
                    'reply' => $translatedMessage,
                    'source' => 'rating_request',
                    'show_rating' => true,
                    'order_id' => (int)$pendingRating['id_order']
                ]);
            }

            // Handle rating submission
            if ($this->isRatingSubmission($originalMessage, $processedMessage)) {
                $this->handleRatingSubmission($originalMessage, $processedMessage, $lang);
            }
        } catch (\Throwable $e) {
            // Never let rating flow break the chat
            $this->log('RATING FLOW SKIPPED: '.$e->getMessage());
            return;
        }
    }
    
    /**
     * Get pending rating for customer
     */
    private function getPendingRatingForCustomer(int $customerId): ?array
    {
        $sql = 'SELECT p.id, p.id_order, p.id_customer, p.rating_requested, p.date_add
                FROM `' . _DB_PREFIX_ . 'ai_chatbot_rating_pending` p
                LEFT JOIN `' . _DB_PREFIX_ . 'ai_chatbot_ratings` r ON r.id_order = p.id_order
                INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_order = p.id_order
                WHERE p.id_customer = ' . $customerId . ' 
                  AND r.id_rating IS NULL
                  AND o.valid = 1
                  AND o.current_state IN (' . (int)Configuration::get('PS_OS_DELIVERED') . ', ' . (int)Configuration::get('PS_OS_SHIPPING') . ', ' . (int)Configuration::get('PS_OS_PAYMENT') . ')
                ORDER BY p.date_add ASC
                LIMIT 1';
        
        $result = Db::getInstance()->getRow($sql);
        return $result ?: null;
    }
    
    /**
     * Check if order is old enough to show rating (avoid showing during checkout)
     */
    private function isOrderOldEnough(int $orderId): bool
    {
        $sql = 'SELECT TIMESTAMPDIFF(MINUTE, date_add, NOW()) as minutes_old 
                FROM `' . _DB_PREFIX_ . 'orders` 
                WHERE id_order = ' . $orderId;
        
        $minutesOld = (int)Db::getInstance()->getValue($sql);
        return $minutesOld >= 60; // Order must be at least 1 hour old
    }
    
    /**
     * Mark rating as requested
     */
    private function markRatingAsRequested(int $orderId): bool
    {
        return Db::getInstance()->update(
            'ai_chatbot_rating_pending',
            ['rating_requested' => 1],
            'id_order = ' . $orderId
        );
    }
    
    /**
     * Generate rating request message
     */
    private function generateRatingRequest(int $orderId, string $lang): string
    {
        try {
            $order = new Order($orderId);
            if (Validate::isLoadedObject($order)) {
                return "Thanks for your recent order #" . $order->reference . "! 🎉\n\nHow was your shopping experience with us? Please rate our service:";
            }
        } catch (\Throwable $e) {
            $this->log('RATING REQUEST ERR: ' . $e->getMessage());
        }
        
        return "Thanks for your recent purchase! 🎉\n\nHow was your shopping experience with us? Please rate our service:";
    }
    
    /**
     * Check if the message is a rating submission
     */
    private function isRatingSubmission(string $originalMessage, string $processedMessage): bool
    {
        $ratingFlow = $_SESSION[self::S_RATING] ?? null;
        if (!$ratingFlow || ($ratingFlow['stage'] ?? '') !== 'pending') {
            return false;
        }
        
        // Check for star ratings (1-5)
        if (preg_match('/\b([1-5])\s*(star|étoile|/5)s?\b/ui', $originalMessage) ||
            preg_match('/\b([1-5])\s*(star|étoile|/5)s?\b/ui', $processedMessage)) {
            return true;
        }
        
        // Check for numeric ratings
        if (preg_match('/^\s*([1-5])\s*$/', trim($originalMessage)) ||
            preg_match('/^\s*([1-5])\s*$/', trim($processedMessage))) {
            return true;
        }
        
        // Check for rating keywords
        $ratingKeywords = [
            'excellent', 'parfait', 'très bien', 'super', 'génial', 'fantastique',
            'good', 'bien', 'ok', 'correct', 
            'bad', 'mauvais', 'nul', 'terrible',
            'poor', 'pauvre', 'décevant'
        ];
        
        $lowerMessage = mb_strtolower($originalMessage . ' ' . $processedMessage, 'UTF-8');
        foreach ($ratingKeywords as $keyword) {
            if (strpos($lowerMessage, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Handle rating submission
     */
    private function handleRatingSubmission(string $originalMessage, string $processedMessage, string $lang)
    {
        $ratingFlow = $_SESSION[self::S_RATING] ?? null;
        if (!$ratingFlow || ($ratingFlow['stage'] ?? '') !== 'pending') {
            return;
        }
        
        $orderId = $ratingFlow['order_id'] ?? 0;
        if (!$orderId) {
            return;
        }
        
        $ctx = Context::getContext();
        $customer = $ctx->customer;
        if (!$customer || !$customer->id) {
            return;
        }
        
        $customerId = (int)$customer->id;
        $rating = $this->extractRatingFromMessage($originalMessage, $processedMessage);
        $comment = $this->extractCommentFromMessage($originalMessage, $processedMessage);
        
        if ($rating > 0) {
            // Save the rating
            $success = $this->saveRating($orderId, $customerId, $rating, $comment);
            
            if ($success) {
                // Remove from pending
                $this->removePendingRating($orderId);
                
                // Clear rating session
                unset($_SESSION[self::S_RATING]);
                
                $thankYouMessage = $this->generateThankYouMessage($rating, $lang);
                $translatedMessage = $this->shouldTranslate($thankYouMessage) ? $this->translateToFrench($thankYouMessage, $lang) : $thankYouMessage;
                
                $this->remember('user', $originalMessage);
                $this->remember('assistant', $translatedMessage);
                $this->out(['reply' => $translatedMessage, 'source' => 'rating_thank_you']);
            } else {
                $errorMessage = "Thank you for your feedback! We appreciate your time.";
                $translatedMessage = $this->shouldTranslate($errorMessage) ? $this->translateToFrench($errorMessage, $lang) : $errorMessage;
                
                $this->remember('user', $originalMessage);
                $this->remember('assistant', $translatedMessage);
                $this->out(['reply' => $translatedMessage, 'source' => 'rating_error']);
            }
        }
    }
    
    /**
     * Extract numeric rating from message
     */
    private function extractRatingFromMessage(string $originalMessage, string $processedMessage): int
    {
        // Check for explicit star ratings
        if (preg_match('/\b([1-5])\s*(star|étoile|/5)s?\b/ui', $originalMessage, $matches) ||
            preg_match('/\b([1-5])\s*(star|étoile|/5)s?\b/ui', $processedMessage, $matches)) {
            return (int)$matches[1];
        }
        
        // Check for simple numeric ratings
        if (preg_match('/^\s*([1-5])\s*$/', trim($originalMessage), $matches) ||
            preg_match('/^\s*([1-5])\s*$/', trim($processedMessage), $matches)) {
            return (int)$matches[1];
        }
        
        // Map keywords to ratings
        $lowerMessage = mb_strtolower($originalMessage . ' ' . $processedMessage, 'UTF-8');
        
        if (preg_match('/\b(excellent|parfait|fantastique|génial)\b/u', $lowerMessage)) {
            return 5;
        }
        if (preg_match('/\b(très bien|super|great|amazing)\b/u', $lowerMessage)) {
            return 4;
        }
        if (preg_match('/\b(bien|good|ok|correct)\b/u', $lowerMessage)) {
            return 3;
        }
        if (preg_match('/\b(mauvais|bad|not great)\b/u', $lowerMessage)) {
            return 2;
        }
        if (preg_match('/\b(terrible|nul|awful|poor)\b/u', $lowerMessage)) {
            return 1;
        }
        
        return 0; // Could not extract rating
    }
    
    /**
     * Extract comment from rating message
     */
    private function extractCommentFromMessage(string $originalMessage, string $processedMessage): string
    {
        // Use original message as comment, but clean it up
        $comment = trim($originalMessage);
        
        // Remove simple ratings like "5" or "5 stars"
        $comment = preg_replace('/^\s*[1-5]\s*(stars?|étoiles?|/5)?\s*/ui', '', $comment);
        $comment = preg_replace('/\b[1-5]\s*(stars?|étoiles?|/5)\s*$/ui', '', $comment);
        
        return trim($comment);
    }
    
    /**
     * Save rating to database
     */
    private function saveRating(int $orderId, int $customerId, int $rating, string $comment): bool
    {
        try {
            return Db::getInstance()->insert('ai_chatbot_ratings', [
                'id_order' => $orderId,
                'id_customer' => $customerId,
                'rating' => $rating,
                'comment' => pSQL($comment),
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            $this->log('SAVE RATING ERR: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove pending rating
     */
    private function removePendingRating(int $orderId): bool
    {
        return Db::getInstance()->delete('ai_chatbot_rating_pending', 'id_order = ' . $orderId);
    }
    
    /**
     * Generate thank you message based on rating
     */
    private function generateThankYouMessage(int $rating, string $lang): string
    {
        if ($rating >= 4) {
            return "Thank you so much for the great rating! 🌟 We're delighted you had a positive experience. Feel free to reach out if you need anything else!";
        } elseif ($rating === 3) {
            return "Thanks for your feedback! 🙂 We're always working to improve. Is there anything specific we could do better?";
        } else {
            return "Thank you for your honest feedback. We're sorry your experience wasn't perfect. We'll use this to improve our service. Please don't hesitate to contact us if there's anything we can help with.";
        }
    }

    /* ==================== SMART INTENT DETECTION ==================== */
    
    /**
     * Detect if the message is asking a general question vs requesting a specific action
     * This helps distinguish FAQ questions from actionable requests
     */
    private function isGeneralQuestion(string $text): bool
    {
        // Remove extra spaces and normalize
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        $questionWords = [
            'how', 'what', 'why', 'when', 'where', 'which', 'who',
            'can i', 'could i', 'should i', 'would i', 'may i', 'might i',
            'is it', 'are there', 'do you', 'does it', 'will you',
            'am i able', 'is there a way', 'is it possible'
        ];
        
        // Check if starts with question words
        foreach ($questionWords as $qword) {
            $pattern = '/^' . preg_quote($qword, '/') . '\b/iu';
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        
        // Check for question marks
        if (strpos($text, '?') !== false) {
            return true;
        }
        
        // Check for question patterns in middle of sentence
        $midQuestionPatterns = [
            '/\b(how do i|how can i|how to)\b/iu',
            '/\b(what should|what can|what if)\b/iu',
            '/\b(is there|are there|will there)\b/iu',
        ];
        
        foreach ($midQuestionPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect if user is requesting a specific action (like tracking a specific order)
     * vs asking general questions about policies
     */
    private function isSpecificActionRequest(string $text): bool
    {
        // Action request patterns (now English-only)
        $actionPatterns = [
            '/\b(check|track|find|show|get|give me)\s+(my|the)\s+order\b/iu',
            '/\border\s*(status)\b/iu',
            '/\bmy\s+order\s*(#|number|reference)/iu',
            '/\btrack\s*(order|package)/iu',
            '/\bwhere is\s*my\s*order/iu'
        ];
        
        foreach ($actionPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        
        // Check for presence of specific identifiers (order numbers, references)
        if (preg_match('/\b(#\d+|[A-Z]{2,}\d{2,}|\d{4,})\b/', $text)) {
            return true;
        }
        
        return false;
    }
    
    /* ==================== utilities ==================== */
    
    private function getLanguageSpecificFaqFile(string $lang): string
    {
        $baseFaqFile = (string) Configuration::get('AI_CHATBOT_KB_FILE');
        
        // If French is detected, try to use French FAQ file
        if ($lang === 'fr') {
            $uploadsDir = _PS_MODULE_DIR_ . 'ai_chatbot/uploads/';
            $frenchFaqFile = $uploadsDir . 'faq_fr.txt';
            
            if (is_file($frenchFaqFile)) {
                $this->log('DEBUG: Using French FAQ file: ' . $frenchFaqFile);
                return $frenchFaqFile;
            }
        }
        
        $this->log('DEBUG: Using default FAQ file: ' . $baseFaqFile);
        return $baseFaqFile;
    }

    private function detectIntent(string $message, string $lang): string
    {

        $this->log('INTENT DETECTION: Analyzing original message: "' . $message . '" in lang: ' . $lang);

        // Translate to English first for consistent intent detection
        $messageForIntent = $message;
        if ($lang === 'fr') {
            $messageForIntent = $this->translateForProcessing($message, $lang);
            $this->log('INTENT DETECTION: Translated message for intent processing: "' . $messageForIntent . '"');
        }

        $lowerMsg = mb_strtolower($messageForIntent, 'UTF-8');


        return $this->detectIntentWithAI($message, $lang);
    }

    private function detectIntentWithAI(string $message, string $lang): string
    {
        $apiKey = (string) Configuration::get('AI_CHATBOT_API_KEY');
        if (!$apiKey) {
            $this->log('INTENT_AI: OpenAI API key is missing. Falling back to legacy method.');
            return $this->detectIntentLegacy($message, $lang);
        }

        $client = $this->http($apiKey);

        // Translate to English for more reliable classification
        $processedMessage = $message;
        if ($lang === 'fr') {
            $processedMessage = $this->translate($message, 'en', 'fr', 'intent-detection');
            $this->log('INTENT_AI: Translated French message for intent detection: "' . $processedMessage . '"');
        }

        $systemPrompt = <<<PROMPT
You are an intent detection assistant for an e-commerce chatbot.
Your task is to classify the user's message into one of the following categories:
- "order": For messages about tracking an existing order, checking order status, or finding an order.
- "catalog": For messages about finding products, searching for categories (e.g., "shoes", "laptops"), asking about brands, colors, sizes, price, or stock.
- "smalltalk": For greetings, thanks, goodbyes, or other conversational chat that is not a specific request.
- "faq": For general questions that might be in a FAQ, such as questions about return policies, shipping options, or payment methods.

The user's message is: "$processedMessage"
Respond with only the category name and nothing else.
PROMPT;

        try {
            $response = $client->post('chat/completions', [
                'json' => [
                    'model' => self::CHAT_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt]
                    ],
                    'max_tokens' => 10,
                    'temperature' => 0.0,
                ]
            ]);

            $data = json_decode((string)$response->getBody(), true);
            $intent = strtolower(trim($data['choices'][0]['message']['content'] ?? ''));

            $this->log('INTENT_AI: Raw response: ' . ($data['choices'][0]['message']['content'] ?? 'N/A'));

            $validIntents = ['order', 'catalog', 'smalltalk', 'faq'];
            if (in_array($intent, $validIntents, true)) {
                $this->log('INTENT_AI: Detected intent: ' . $intent);
                return $intent;
            }

            $this->log('INTENT_AI: Could not classify intent, falling back to legacy. Response was: ' . $intent);
            return $this->detectIntentLegacy($message, $lang); // Fallback

        } catch (\Throwable $e) {
            $this->log('INTENT_AI: Error calling OpenAI for intent detection: ' . $e->getMessage());
            return $this->detectIntentLegacy($message, $lang);
        }
    }

    private function detectIntentLegacy(string $message, string $lang): string
    {
        $lowerMsg = mb_strtolower($message, 'UTF-8');
        
        $this->log('INTENT DETECTION: Analyzing message: "' . $message . '"');
        

        // Check small talk first
        if ($this->isSmallTalk($lowerMsg)) {
            $this->log('INTENT DETECTION: Classified as smalltalk');
            return 'smalltalk';
        }
        
        // Smart order intent detection
        if ($this->isOrderQuery($lowerMsg)) {
            // Use the potentially translated message for these checks
            $isQuestion = $this->isGeneralQuestion($messageForIntent);
            $isActionRequest = $this->isSpecificActionRequest($messageForIntent);
            
            $this->log('INTENT DETECTION: Contains order keywords - isQuestion: ' . ($isQuestion ? 'yes' : 'no') . ', isActionRequest: ' . ($isActionRequest ? 'yes' : 'no'));
            
            // If it's a general question about orders, treat as FAQ
            if ($isQuestion && !$isActionRequest) {
                $this->log('INTENT DETECTION: Order-related question -> FAQ (general)');
                return 'general';
            }
            
            // If it's a specific action request, treat as order tracking
            if ($isActionRequest) {
                $this->log('INTENT DETECTION: Specific order action request -> order flow');
                return 'order';
            }
            
            // If contains order keywords but unclear intent, default to FAQ
            $this->log('INTENT DETECTION: Unclear order intent -> FAQ (general)');
            return 'general';
        }
        
        // Check catalog/product queries
        if ($this->isCatalogQuery($lowerMsg)) {
            $this->log('INTENT DETECTION: Classified as catalog');
            return 'catalog';
        }
        
        // Default to general/FAQ
        $this->log('INTENT DETECTION: Classified as general/FAQ');
        return 'general';
    }
    
    private function isSmallTalk(string $text): bool
    {
        $patterns = [
            'greeting' => ['patterns' => ['hi', 'hello', 'hey']],
            'thanks' => ['patterns' => ['thanks', 'thank you']],
            'how_are_you' => ['patterns' => ['how are you', 'how are']],
            'goodbye' => ['patterns' => ['bye', 'goodbye', 'see you']],
            'confirmation' => ['patterns' => ['ok', 'cool', 'great', 'awesome', 'perfect']]
        ];
        
        foreach ($patterns as $category) {
            if (isset($category['patterns'])) {
                if ($this->matchesPattern($text, $category['patterns'])) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    private function isOrderQuery(string $text): bool
    {
        $keywords = ['order', 'status', 'tracking', 'delivery', 'shipped', 'track'];
        
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                $contextKeywords = ['order', 'number', 'reference'];
                foreach ($contextKeywords as $context) {
                    if (strpos($text, $context) !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    private function isCatalogQuery(string $text): bool
    {
        // Price/stock queries
        $priceStockKeywords = ['price', 'cost', 'how much', 'stock', 'available', 'inventory'];
        foreach ($priceStockKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        // Product search queries
        $searchKeywords = ['looking for', 'need', 'want to buy', 'show me', 'find', 'search', 'shoes', 'clothing', 'electronics', 'nike', 'adidas'];
        foreach ($searchKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        // Check if message mentions product categories
        if ($this->mentionsProductCategory($text)) {
            return true;
        }
        
        return false;
    }
    
    private function mentionsProductCategory(string $text): bool
    {
        // Get categories from database dynamically instead of hardcoding
        $idLang = (int)Context::getContext()->language->id;
        $tokens = $this->tok($text);
        
        if (!$tokens) return false;
        
        $likes = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token, 'UTF-8') >= 3) {
                $likes[] = "cl.name LIKE '" . pSQL('%' . $token . '%') . "'";
            }
        }
        
        if (!$likes) return false;
        
        $sql = 'SELECT COUNT(*) as count FROM ' . _DB_PREFIX_ . 'category_lang cl
                INNER JOIN ' . _DB_PREFIX_ . 'category c ON c.id_category = cl.id_category
                WHERE cl.id_lang = ' . $idLang . ' AND c.active = 1 AND c.id_category > 2
                  AND (' . implode(' OR ', $likes) . ')
                LIMIT 1';
        
        $count = (int)$this->dbValue($sql);
        return $count > 0;
    }


    private function detectLanguage(string $text): string
    {
        // Use our advanced language detection
        return $this->detectLanguageAdvanced($text);
    }

    private function getFallbackMessage(string $lang): string
    {
        $email = Configuration::get('PS_SHOP_EMAIL');
        $phone = Configuration::get('PS_SHOP_PHONE') ?: Configuration::get('PS_SHOP_PHONE_NUMBER');
        
        if ($lang === 'fr') {
            if ($email || $phone) {
                return "Si vous avez encore besoin d'aide, contactez-nous à " . $email . 
                       ($phone ? " ou " . $phone . "." : ".");
            }
            return "Veuillez contacter notre support.";
        } else {
            if ($email || $phone) {
                return "If you still need help, contact us at " . $email . 
                       ($phone ? " or " . $phone . "." : ".");
            }
            return "Please contact support.";
        }
    }
    
    private function getLocalizedMessage(string $key, string $lang): string
    {
        $messages = [
            'fr' => [
                'order_need_email' => "Pour vérifier votre commande, veuillez fournir votre **adresse e-mail**.",
                'order_email_required' => "J'ai besoin de votre **e-mail** pour continuer.",
                'order_security_error' => "Désolé — pour des raisons de sécurité, je ne peux montrer le statut que si vous êtes connecté avec **cette même adresse e-mail**. Veuillez vous connecter et réessayer.",
                'order_need_id' => "Parfait, merci ! Maintenant votre **numéro de commande** (référence/ID).",
                'order_id_required' => "Veuillez partager votre **numéro de commande** (référence/ID).",
                'order_restart' => "Commençons à nouveau avec votre **adresse e-mail**."
            ],
            'en' => [
                'order_need_email' => "To check your order, please provide your **email address**.",
                'order_email_required' => "I'll need your **email** first to continue.",
                'order_security_error' => "Sorry — for security, I can only show status if you're signed in with **that same email**. Please sign in and try again.",
                'order_need_id' => "Great, thanks! Now your **order number** (reference/ID).",
                'order_id_required' => "Please share your **order number** (reference/ID).",
                'order_restart' => "Let's start again with your **email address**."
            ]
        ];
        
        $langMessages = $messages[$lang] ?? $messages['en'];
        return $langMessages[$key] ?? $langMessages['order_restart'];
    }

    private function catMsg(string $key, string $lang): string
    {
        $fr = [
            'category_not_found'   => "Nous ne vendons pas cette catégorie. Essayez un autre nom de catégorie.",
            'category_undetermined'=> "Je n'ai pas pu déterminer la catégorie. Recommençons.",
            'filters_no_match'     => "Aucun article ne correspond à ces filtres. Essayez une marque/couleur/taille différente.",
            'results_header'       => "Résultats trouvés:"
        ];
        $en = [
            'category_not_found'   => "We don't sell that category. Try another category name.",
            'category_undetermined'=> "I couldn't determine the category. Let's start again.",
            'filters_no_match'     => "No items match those filters. Try a different brand/color/size.",
            'results_header'       => "Results found:"
        ];
        $map = ($lang === 'fr') ? $fr : $en;
        return $map[$key] ?? $key;
    }
    
    private function extractOrderInfo(string $text): array
    {
        $id = null;
        $reference = null;
        
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $stopWords = ['ORDER', 'COMMANDE', 'STATUS', 'STATUT', 'TRACKING', 'SUIVI', 'ETAT', 'ÉTAT'];
        
        foreach ($words as $word) {
            $word = trim($word);
            
            // Look for numeric IDs
            if (preg_match('/^\d{1,10}$/', $word)) {
                $id = (int)$word;
                break;
            }
            
            // Look for alphanumeric references
            if (preg_match('/^[A-Za-z0-9-]{6,16}$/', $word)) {
                $upperWord = strtoupper($word);
                if (in_array($upperWord, $stopWords, true)) {
                    continue;
                }
                if (preg_match('/^[A-Z]{6,16}$/', $upperWord) || preg_match('/\d/', $upperWord)) {
                    $reference = $upperWord;
                    break;
                }
            }
        }
        
        return ['id' => $id, 'reference' => $reference];
    }
    
    private function getOrderStatus(array $orderInfo, string $lang): string
    {
        try {
            $orderId = null;
            
            if ($orderInfo['reference']) {
                $orderId = (int)$this->dbValue(
                    'SELECT id_order FROM ' . _DB_PREFIX_ . 'orders WHERE reference = "' . 
                    pSQL($orderInfo['reference']) . '"'
                );
            }
            
            if (!$orderId && $orderInfo['id']) {
                $orderId = (int)$this->dbValue(
                    'SELECT id_order FROM ' . _DB_PREFIX_ . 'orders WHERE id_order = ' . (int)$orderInfo['id']
                );
            }
            
            if ($orderId) {
                $order = new Order($orderId);
                if (Validate::isLoadedObject($order)) {
                    $orderState = new OrderState($order->current_state);
                    $idLang = (int)Context::getContext()->language->id;
                    $stateName = $orderState->name[$idLang] ?? ($orderState->name[1] ?? 'Unknown');
                    
                    $date = date('d/m/Y', strtotime($order->date_add));
                    $total = Tools::displayPrice($order->total_paid);
                    
                    if ($lang === 'fr') {
                        return "Commande **#{$order->reference}** du {$date} ({$total}) est **{$stateName}**.";
                    } else {
                        return "Order **#{$order->reference}** from {$date} ({$total}) is **{$stateName}**.";
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log('ORDER STATUS ERR: ' . $e->getMessage());
        }
        
        if ($lang === 'fr') {
            return "Désolé, je n'ai pas trouvé de commande avec ce numéro. Veuillez vérifier et renvoyer.";
        } else {
            return "Sorry, I couldn't find an order with that number. Please double-check and resend.";
        }
    }
    

    /* ====================== CONTINUATION FLOW ====================== */
    
    private function handleContinuationResponse(string $message, string $lang): string
    {
        $lowerMsg = mb_strtolower($message, 'UTF-8');
        
        // Check for positive responses
        $positivePatterns = ['yes', 'oui', 'y', 'ok', 'yeah', 'yep', 'sure'];
        $negativePatterns = ['no', 'non', 'n', 'nope', 'stop', 'end', 'quit'];
        
        $isPositive = false;
        $isNegative = false;
        
        foreach ($positivePatterns as $pattern) {
            if (strpos($lowerMsg, $pattern) !== false) {
                $isPositive = true;
                break;
            }
        }
        
        foreach ($negativePatterns as $pattern) {
            if (strpos($lowerMsg, $pattern) !== false) {
                $isNegative = true;
                break;
            }
        }
        
        if ($isPositive) {
            // User wants to continue - clear continuation flow and ask for new search
            unset($_SESSION[self::S_CONTINUE]);
            
            // Important: Don't set any flow state here - let the next message be processed normally
            if ($lang === 'fr') {
                return "Parfait ! Que cherchez-vous ? (par exemple: chaussures, vêtements, électronique)";
            } else {
                return "Great! What are you looking for? (e.g., shoes, clothing, electronics)";
            }
        } elseif ($isNegative) {
            // User doesn't want to continue - clear continuation flow
            unset($_SESSION[self::S_CONTINUE]);
            
            if ($lang === 'fr') {
                return "D'accord ! Si vous avez d'autres questions, n'hésitez pas à me les poser.";
            } else {
                return "Alright! If you have any other questions, feel free to ask me.";
            }
        } else {
            // Unclear response - ask again
            if ($lang === 'fr') {
                return "Je n'ai pas compris. Voulez-vous chercher autre chose ? Répondez par **oui** ou **non**.";
            } else {
                return "I didn't understand. Would you like to search for something else? Please answer **yes** or **no**.";
            }
        }
    }
    
    /* ====================== CATALOG HANDLERS ====================== */
    
    private function handleCatalogQuery(string $message, string $lang): ?string
    {
        // Check if this is a direct price/stock query
        if ($this->isPriceStockQuery($message)) {
            return $this->handleDirectProductQuery($message, $lang);
        }
        
        // Otherwise, start category confirmation flow
        return $this->confirmProductCategory($message, $lang);
    }
    
    private function handleCatalogFilters(string $message, string $lang, array $flow): string
    {
        try {
            $idLang = (int)Context::getContext()->language->id;
            $tokens = $this->tok($message);
            
            $this->log('CATALOG FILTERS: Processing message: "'.$message.'" with tokens: '.json_encode($tokens));
            
            $filters = $this->extractFiltersFromMessage($tokens, $idLang);
            $this->log('CATALOG FILTERS: Extracted filters: '.json_encode($filters));

            $categoryIds = array_map('intval', (array)($flow['cat_ids'] ?? []));
            if (!$categoryIds) {
                $this->log('CATALOG FILTERS: No category IDs found in flow');
                return $this->catMsg('category_undetermined', $lang);
            }
            
            $this->log('CATALOG FILTERS: Searching in categories: '.json_encode($categoryIds));

            $products = $this->findProductsInCategories($categoryIds, $idLang, $filters, 24, $tokens);
            $this->log('CATALOG FILTERS: Found '.count($products).' products');
            
            if (!$products) {
                // Check if category has any products at all for debugging
                $plainCnt = (int)$this->dbValue('SELECT COUNT(DISTINCT p.id_product)
                         FROM '._DB_PREFIX_.'product p
                         INNER JOIN '._DB_PREFIX_.'category_product cp ON cp.id_product=p.id_product
                         WHERE p.active=1 AND cp.id_category IN ('.implode(',', $categoryIds).')');
                
                $this->log('CATALOG FILTERS: Total products in categories: '.$plainCnt);
                
                if ($plainCnt === 0) {
                    return "That category is empty right now. Please check back later.";
                }
                
                return $this->catMsg('filters_no_match', $lang);
            }

            return $this->formatProductResults($products, $lang);
        } catch (\Throwable $e) {
            $this->log('CATALOG FILTERS ERR: '.$e->getMessage().' Stack: '.$e->getTraceAsString());
            return $this->catMsg('filters_no_match', $lang);
        }
    }

    private function formatProductResults(array $products, string $lang): string
    {
        if (!$products) return $this->catMsg('filters_no_match', $lang);

        $lines = [];
        foreach ($products as $p) {
            // Format: Clean clickable HTML link (URL only)
            $lines[] = '• <a href="'.$p['url'].'" target="_blank">'.$p['name'].'</a>';
        }

        $header = $this->catMsg('results_header', $lang);
        $results = $header . "\n" . implode("\n", $lines);
        
        // Set up continuation flow
        $_SESSION[self::S_CONTINUE] = ['stage' => 'awaiting_response'];
        
        // Add continuation question
        $continueQuestion = $lang === 'fr' 
            ? "\n\nVoulez-vous chercher autre chose ? (oui/non)"
            : "\n\nWould you like to search for something else? (yes/no)";
            
        return $results . $continueQuestion;
    }
    
    
    private function confirmProductCategory(string $message, string $lang): ?string
    {
        try {
            $idLang = (int)Context::getContext()->language->id;
            $tokens = $this->tok($message);

            if (!$tokens) return null;

            $categories = $this->findMatchingCategories($tokens, $idLang);

            if (!$categories) {
                return $this->catMsg('category_not_found', $lang);
            }

            $categoryIds = array_column($categories, 'id_category');
            $categoryNames = array_column($categories, 'name');

            $_SESSION[self::S_CAT] = [
                'stage'     => 'ask_filters',
                'cat_ids'   => $categoryIds,
                'cat_names' => $categoryNames,
            ];

            $list = implode(', ', $categoryNames);

            if ($lang === 'fr') {
                return "Oui — nous avons **{$list}**. Maintenant, dites-moi vos **filtres** : *marque*, *couleur*, *taille*.";
            }
            return "Yes — we carry **{$list}**. Now tell me your **filters**: *brand*, *color*, *size*.";
        } catch (\Throwable $e) {
            $this->log('CATALOG CONFIRM ERR: '.$e->getMessage());
            return $this->catMsg('category_not_found', $lang);
        }
    }

    private function findMatchingCategories(array $tokens, int $idLang): array
    {
        if (!$tokens) return [];

        $likes = [];
        foreach ($tokens as $t) {
            if (mb_strlen($t,'UTF-8') >= 3) {
                $likes[] = "cl.name LIKE '".pSQL('%'.$t.'%')."'";
            }
        }
        if (!$likes) return [];

        $sql = 'SELECT DISTINCT c.id_category, cl.name
                FROM '._DB_PREFIX_.'category c
                INNER JOIN '._DB_PREFIX_.'category_lang cl
                  ON (cl.id_category=c.id_category AND cl.id_lang='.$idLang.')
                INNER JOIN '._DB_PREFIX_.'category_product cp ON cp.id_category=c.id_category
                INNER JOIN '._DB_PREFIX_.'product p ON p.id_product=cp.id_product AND p.active=1
                WHERE c.active=1 AND c.id_category > 2 AND ('.implode(' OR ',$likes).')
                ORDER BY c.level_depth ASC, c.id_category ASC
                LIMIT 6';

        return Db::getInstance()->executeS($sql) ?: [];
    }
    
    /* ====================== FAQ HANDLER ====================== */
    
    private function searchFAQ(string $message, string $lang, string $apiKey, string $kbFile, string $embFile): ?string
    {
        $this->log('FAQ: Searching for answer to: "' . substr($message, 0, 100) . '" in language: ' . $lang);
        
        // Check if this is a question - boost confidence for questions
        $isQuestion = $this->isGeneralQuestion($message);
        $this->log('FAQ: Message is a question: ' . ($isQuestion ? 'yes' : 'no'));
        
        // For French queries, translate to English for better KB matching
        $searchQuery = $message;
        if ($lang === 'fr') {
            $searchQuery = $this->translateForProcessing($message, $lang);
            $this->log('FAQ: Translated FR query to: "' . substr($searchQuery, 0, 100) . '"');
        }
        
        $answer = null;
        
        // Try embeddings first if available
        if ($apiKey && $embFile && is_file($embFile)) {
            $this->log('FAQ: Trying embeddings search with query: "' . substr($searchQuery, 0, 50) . '..."');
            $answer = $this->searchFAQWithEmbeddings($searchQuery, $apiKey, $embFile, $isQuestion);
        }
        
        // Fallback to text search
        if (!$answer && $kbFile && is_file($kbFile)) {
            $this->log('FAQ: Trying raw text search with query: "' . substr($searchQuery, 0, 50) . '..."');
            $rawText = @file_get_contents($kbFile) ?: '';
            if ($rawText !== '') {
                $answer = $this->searchFAQInText($searchQuery, $rawText);
            }
        }
        
        return $answer;
    }
    
    private function searchFAQInText(string $query, string $text): ?string
    {
        $this->log('FAQ: Searching in raw text for: ' . $query);
        
        // Parse Q&A pairs from text
        $pairs = $this->parseQAPairs($text);
        if (empty($pairs)) {
            $this->log('FAQ: No Q&A pairs found in text');
            return null;
        }
        
        $this->log('FAQ: Found ' . count($pairs) . ' Q&A pairs');
        
        // Use BM25 scoring for better matching
        $bestScore = 0;
        $bestAnswer = null;
        $queryTokens = $this->tok($query);
        
        foreach ($pairs as $pair) {
            $question = mb_strtolower($pair['q'] ?? '', 'UTF-8');
            $answer = $pair['a'] ?? '';
            
            if (empty($question) || empty($answer)) continue;
            
            $score = $this->calculateBM25Score($queryTokens, $this->tok($question));
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAnswer = $answer;
            }
        }
        
        $threshold = 0.1; // Minimum score threshold
        $this->log("FAQ: Best score = $bestScore, threshold = $threshold");
        
        if ($bestScore >= $threshold) {
            $this->log('FAQ: Found answer with score ' . $bestScore);
            return $bestAnswer;
        }
        
        $this->log('FAQ: No suitable answer found');
        return null;
    }
    
    private function parseQAPairs(string $text): array
    {
        $pairs = [];
        $lines = explode("\n", $text);
        $currentQ = '';
        $currentA = '';
        $inAnswer = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Check if line starts with Q: or Question:
            if (preg_match('/^(Q:|Question:)\s*(.+)$/i', $line, $matches)) {
                // Save previous pair if exists
                if (!empty($currentQ) && !empty($currentA)) {
                    $pairs[] = ['q' => $currentQ, 'a' => trim($currentA)];
                }
                $currentQ = trim($matches[2]);
                $currentA = '';
                $inAnswer = false;
            }
            // Check if line starts with A: or Answer:
            elseif (preg_match('/^(A:|Answer:)\s*(.+)$/i', $line, $matches)) {
                $currentA = trim($matches[2]);
                $inAnswer = true;
            }
            // Continue answer on next lines
            elseif ($inAnswer && !empty($currentQ)) {
                $currentA .= ' ' . $line;
            }
        }
        
        // Save last pair
        if (!empty($currentQ) && !empty($currentA)) {
            $pairs[] = ['q' => $currentQ, 'a' => trim($currentA)];
        }
        
        return $pairs;
    }
    
    
    private function calculateBM25Score(array $queryTokens, array $docTokens): float
    {
        if (empty($queryTokens) || empty($docTokens)) return 0.0;
        
        $k1 = 1.5;
        $b = 0.75;
        $avgDocLength = 50; // Estimated average document length
        $docLength = count($docTokens);
        
        $score = 0.0;
        $docTokenCounts = array_count_values($docTokens);
        
        foreach ($queryTokens as $term) {
            $tf = $docTokenCounts[$term] ?? 0;
            if ($tf > 0) {
                $idf = log(1 + (1 / max(1, $tf))); // Simplified IDF
                $score += $idf * ($tf * ($k1 + 1)) / ($tf + $k1 * (1 - $b + $b * ($docLength / $avgDocLength)));
            }
        }
        
        return $score;
    }
    
    private function searchFAQWithEmbeddings(string $query, string $apiKey, string $embFile, bool $isQuestion = false): ?string
    {
        $kb = $this->loadKB($embFile);
        if (!$kb) return null;
        
        // Get keyword-based score
        $keywordResult = $this->bm25Best($query, $kb);
        $keywordScore = $keywordResult['score'] ?? 0.0;
        $keywordAnswer = $keywordResult['a'] ?? null;

        // Get semantic score if API key available
        $semanticScore = 0.0;
        $semanticAnswer = null;

        if ($apiKey) {
            $semanticResult = $this->semanticBest($query, $apiKey, $kb);
            if ($semanticResult) {
                $semanticScore = $semanticResult['score'];
                $semanticAnswer = $semanticResult['a'];
            }
        }
        
        $this->log('FAQ EMBEDDINGS: keywordScore=' . $keywordScore . ', semanticScore=' . $semanticScore . ', isQuestion=' . ($isQuestion ? 'yes' : 'no'));
        
        // Boost scores for questions to increase FAQ confidence
        if ($isQuestion) {
            $keywordScore *= 1.3; // 30% boost for questions
            $semanticScore *= 1.2; // 20% boost for questions
            $this->log('FAQ EMBEDDINGS: Boosted scores - keywordScore=' . $keywordScore . ', semanticScore=' . $semanticScore);
        }
        
        // Adaptive threshold based on content quality
        $kwThreshold = $this->getAdaptiveThreshold('keyword', $keywordScore, $isQuestion);
        $semThreshold = $this->getAdaptiveThreshold('semantic', $semanticScore, $isQuestion);
        
        $this->log('FAQ EMBEDDINGS: thresholds - keyword=' . $kwThreshold . ', semantic=' . $semThreshold);
        
        // Choose best answer
        if ($semanticScore >= $semThreshold && $semanticAnswer) {
            $this->log('FAQ EMBEDDINGS: Using semantic answer');
            return $semanticAnswer;
        }
        
        if ($keywordScore >= $kwThreshold && $keywordAnswer) {
            $this->log('FAQ EMBEDDINGS: Using keyword answer');
            return $keywordAnswer;
        }
        
        $this->log('FAQ EMBEDDINGS: No answer found above thresholds');
        return null;
    }
    
    private function getAdaptiveThreshold(string $type, float $score, bool $isQuestion = false): float
    {
        if ($type === 'keyword') {
            // Lower threshold for better keyword matches, even lower for questions
            $baseThreshold = $score > 0.08 ? self::KW_THRESHOLD_MIN : self::KW_THRESHOLD_MAX;
            return $isQuestion ? $baseThreshold * 0.7 : $baseThreshold; // 30% lower for questions
        } else {
            // Adjust semantic threshold based on score confidence, lower for questions
            $baseThreshold = $score > 0.6 ? self::SEM_THRESHOLD_MIN : self::SEM_THRESHOLD_MAX;
            return $isQuestion ? $baseThreshold * 0.8 : $baseThreshold; // 20% lower for questions
        }
    }
    
    /* ====================== UTILITIES ====================== */
    
    private function http(string $apiKey, array $extraHeaders = []): Client
    {
        return new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => 30,
            'headers' => array_merge([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json'
            ], $extraHeaders),
        ]);
    }

    private function tok(string $text): array
    {
        $text=mb_strtolower($text,'UTF-8');
        $noAcc=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$text); if($noAcc!==false)$text=$noAcc;
        $text=preg_replace('/[^0-9a-z\s]/i',' ',$text);
        $parts=preg_split('/\s+/', $text,-1,PREG_SPLIT_NO_EMPTY); if(!$parts) return [];
        static $stop=['the','a','an','and','or','of','for','to','is','are','on','in','with','do','you','any',
                      'la','le','les','de','des','du','et','ou','au','aux','un','une'];
        return array_values(array_unique(array_diff($parts,$stop)));
    }

    private function expandWordVariations(array $tokens): array
    {
        $expanded = $tokens;
        foreach ($tokens as $token) {
            // English plural/singular variations
            if (preg_match('/^(.+)s$/', $token, $m) && strlen($m[1]) > 2) {
                $expanded[] = $m[1]; // shirts -> shirt
            } elseif (!preg_match('/s$/', $token)) {
                $expanded[] = $token . 's'; // shirt -> shirts
            }
            
            // Handle common irregular plurals
            $irregulars = [
                'child' => 'children', 'children' => 'child',
                'man' => 'men', 'men' => 'man',
                'woman' => 'women', 'women' => 'woman',
                'foot' => 'feet', 'feet' => 'foot',
                'shoe' => 'shoes', 'shoes' => 'shoe',
            ];
            if (isset($irregulars[$token])) {
                $expanded[] = $irregulars[$token];
            }
            
            // French plural handling
            if (preg_match('/^(.+)x$/', $token, $m) && strlen($m[1]) > 2) {
                $expanded[] = $m[1]; // chapeaux -> chapeau
            }
            
            // Add cross-language translations for common category terms
            $translations = $this->getCategoryTranslations();
            if (isset($translations[$token])) {
                $expanded = array_merge($expanded, $translations[$token]);
                $this->log('TRANSLATION FOUND: '.$token.' -> '.implode(', ', $translations[$token]));
            } else {
                $this->log('TRANSLATION NOT FOUND for token: '.$token);
            }
        }
        return array_values(array_unique($expanded));
    }
    
    private function getCategoryTranslations(): array
    {
        static $translations = null;
        if ($translations !== null) return $translations;
        
        $translations = [];
        
        try {
            // Get all available languages in the shop
            $languages = Language::getLanguages(true);
            $this->log('TRANSLATION: Found '.count($languages).' active languages');
            
            if (count($languages) < 2) {
                $this->log('TRANSLATION: Not enough languages for translation');
                return $translations; // Need at least 2 languages for translation
            }
            
            // Get all active categories with their names in all languages
            $sql = 'SELECT cl.id_category, cl.id_lang, cl.name, l.iso_code
                    FROM '._DB_PREFIX_.'category_lang cl
                    INNER JOIN '._DB_PREFIX_.'category c ON c.id_category = cl.id_category
                    INNER JOIN '._DB_PREFIX_.'lang l ON l.id_lang = cl.id_lang
                    WHERE c.active = 1 AND c.id_category > 2 AND l.active = 1 AND TRIM(cl.name) != \'\'
                    ORDER BY cl.id_category, cl.id_lang';
            
            $this->log('TRANSLATION SQL: '.substr($sql, 0, 150).'...');
            $rows = Db::getInstance()->executeS($sql) ?: [];
            $this->log('TRANSLATION: Retrieved '.count($rows).' category name records');
            
            // Group category names by category ID
            $categoryNames = [];
            foreach ($rows as $row) {
                $catId = (int)$row['id_category'];
                $langCode = strtolower($row['iso_code']);
                $name = trim(strtolower($row['name']));
                
                if ($name !== '') {
                    $categoryNames[$catId][$langCode] = $name;
                }
            }
            
            // Create cross-language translation mappings
            $processedCategories = 0;
            foreach ($categoryNames as $catId => $names) {
                if (count($names) < 2) continue; // Need names in at least 2 languages
                $processedCategories++;
                
                $this->log('TRANSLATION: Processing category '.$catId.' with names: '.json_encode($names));
                
                // Create bidirectional mappings between all language pairs
                foreach ($names as $lang1 => $name1) {
                    foreach ($names as $lang2 => $name2) {
                        if ($lang1 !== $lang2 && $name1 !== $name2) {
                            // Add the translation mapping
                            if (!isset($translations[$name1])) {
                                $translations[$name1] = [];
                            }
                            $translations[$name1][] = $name2;
                            
                            // Also add tokenized versions for partial matching
                            $tokens1 = $this->tok($name1);
                            $tokens2 = $this->tok($name2);
                            
                            foreach ($tokens1 as $token1) {
                                if (mb_strlen($token1, 'UTF-8') >= 3) {
                                    if (!isset($translations[$token1])) {
                                        $translations[$token1] = [];
                                    }
                                    $translations[$token1][] = $name2;
                                    foreach ($tokens2 as $token2) {
                                        if (mb_strlen($token2, 'UTF-8') >= 3) {
                                            $translations[$token1][] = $token2;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            $this->log('TRANSLATION: Processed '.$processedCategories.' multilingual categories');
            
            // Remove duplicates from each translation array
            foreach ($translations as $key => $values) {
                $translations[$key] = array_values(array_unique($values));
            }
            
            $this->log('CATEGORY TRANSLATIONS: Generated '.count($translations).' translation mappings');
            
            // Log a sample of translations for debugging
            $sampleKeys = array_slice(array_keys($translations), 0, 5);
            foreach ($sampleKeys as $key) {
                $this->log('TRANSLATION SAMPLE: '.$key.' -> '.implode(', ', array_slice($translations[$key], 0, 3)));
            }
            
        } catch (\Throwable $e) {
            $this->log('TRANSLATION BUILD ERR: '.$e->getMessage());
            $translations = [];
        }
        
        return $translations;
    }

    private function kbDictTokens(string $embPath): array
    {
        if(!$embPath || !is_file($embPath)) return [];
        $raw=json_decode(@file_get_contents($embPath),true); if(!$raw) return [];
        $idf=is_array(($raw['idf'] ?? null)) ? array_keys($raw['idf']) : [];
        $extras=['returns','refund','shipping','delivery','payment','support','warranty','international','price','stock','return','commande','livraison','retours','remboursement'];
        return array_values(array_unique(array_merge($idf,$extras)));
    }

    private function typoCorrect(string $text,array $dict): string
    {
        if(!$dict) return $text;
        if(count($dict)>6000) $dict=array_slice($dict,0,6000);

        $parts=preg_split('/(\b)/u',$text,-1,PREG_SPLIT_DELIM_CAPTURE);
        for($i=0;$i<count($parts);$i++){
            $w=$parts[$i]; if(!preg_match('/^[a-zA-Z]{4,}$/',$w)) continue;
            $lw=strtolower($w); if(in_array($lw,$dict,true)) continue;
            $best=null; $bestD=99;
            foreach($dict as $cand){
                $d=levenshtein($lw,$cand);
                if($d<$bestD){ $bestD=$d; $best=$cand; }
                if($bestD===0) break;
            }
            $lim=(strlen($lw)>=8)?3:2;
            if($best!==null && $bestD<=$lim) $parts[$i]=ctype_upper($w[0])?ucfirst($best):$best;
        }
        return implode('',$parts);
    }

    private function cos(array $a,array $b): float
    {
        $n=min(count($a),count($b)); if(!$n) return 0.0;
        $dot=0.0; $na=0.0; $nb=0.0;
        for($i=0;$i<$n;$i++){ $dot+=$a[$i]*$b[$i]; $na+=$a[$i]*$a[$i]; $nb+=$b[$i]*$b[$i]; }
        if($na==0||$nb==0) return 0.0; return $dot/(sqrt($na)*sqrt($nb));
    }

    private function dbValue(string $sql)
    {
        try { return Db::getInstance()->getValue($sql); }
        catch (\Throwable $e) { $this->log("DB EXC (value): ".$e->getMessage()."\nSQL: ".trim($sql)); return null; }
    }

    private function tableExists(string $tableName): bool
    {
        try {
            $safe = pSQL($tableName);
            $row = Db::getInstance()->getRow("SHOW TABLES LIKE '".$safe."'");
            return !empty($row);
        } catch (\Throwable $e) {
            $this->log('TABLE EXISTS CHECK ERR: '.$e->getMessage());
            return false;
        }
    }

    private function remember(string $role,string $content): void
    {
        if(!isset($_SESSION[self::S_CHAT]) || !is_array($_SESSION[self::S_CHAT])) $_SESSION[self::S_CHAT]=[];
        $_SESSION[self::S_CHAT][]=['role'=>$role,'content'=>$content];
        if(count($_SESSION[self::S_CHAT])>24) $_SESSION[self::S_CHAT]=array_slice($_SESSION[self::S_CHAT],-24);
    }

    private function log(string $m){
        @file_put_contents(_PS_ROOT_DIR_.'/var/logs/ai_chatbot_debug.log','['.date('Y-m-d H:i:s')."] $m\n",FILE_APPEND);
    }
    private function out(array $arr){ while(ob_get_level()){@ob_end_clean();} header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); flush(); exit; }
    private function outErr(string $msg,int $code=200){ http_response_code($code); $this->out(['reply'=>$msg,'source'=>'error']); }

    /* ========================= diag ========================= */

    private function diag(): void
    {
        $apiKey=(string)Configuration::get('AI_CHATBOT_API_KEY');
        $vsId  =(string)Configuration::get('AI_CHATBOT_VECTOR_STORE_ID');
        $kbAbs =(string)Configuration::get('AI_CHATBOT_KB_FILE');
        $exists=is_file($kbAbs); $kbSize=$exists?filesize($kbAbs):0; $mtime=$exists?date('c',filemtime($kbAbs)):null; $sha1=$exists?sha1_file($kbAbs):null;

        $emb=$kbAbs?$kbAbs.'.embeddings.json':''; $pairsFound=0; $sample=[];
        if($emb && is_file($emb)){
            $raw=json_decode(@file_get_contents($emb),true);
            $pairs=$raw['pairs'] ?? $raw ?? [];
            if(is_array($pairs)){
                $pairsFound=count($pairs);
                foreach(array_slice($pairs,0,5) as $p) $sample[]=['q'=>$p['q']??'','a'=>$p['a']??''];
            }
        }

        echo json_encode([
            'api_key_present'=>(bool)$apiKey,
            'vector_store_id'=>$vsId,
            'kb_file_abs'=>$kbAbs,
            'kb_exists'=>$exists,
            'kb_size_bytes'=>$kbSize,
            'kb_mtime'=>$mtime,
            'kb_sha1'=>$sha1,
            'pairs_found'=>$pairsFound,
            'sample'=>$sample,
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
}
