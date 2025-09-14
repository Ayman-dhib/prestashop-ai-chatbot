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
    
    // Dynamic threshold storage (for runtime modification)
    private static $_KW_THRESHOLD_MIN;
    private static $_KW_THRESHOLD_MAX;
    private static $_SEM_THRESHOLD_MIN;
    private static $_SEM_THRESHOLD_MAX;
    
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
        
        // Handle coupon-specific requests
        if (Tools::getValue('coupon') === '1') {
            $this->handleCouponRequest();
            return;
        }
        
        // Handle debugging requests
        if (Tools::getValue('debug_query') === '1') {
            $this->handleDebugQueryRequest();
            return;
        }

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
                $this->out([
                    'reply'=>null,
                    'source'=>'reset',
                    'conversation'=>[]
                ]); return;
            }
            if ($userMsg === '') { 
                $this->out([
                    'reply'=>'⚠️ Empty message.',
                    'source'=>'error',
                    'conversation' => $_SESSION[self::S_CHAT] ?? []
                ]); 
                return; 
            }

            $apiKey = (string) Configuration::get('AI_CHATBOT_API_KEY');
            
            // Enhanced language flow tracking
            $lang = $this->getOrSetSessionLanguage($userMsg);
            $kbAbs = $this->getLanguageSpecificFaqFile($lang);
            $embAbs = $kbAbs ? $kbAbs.'.embeddings.json' : '';
            
            // Store original message for context preservation
            $_SESSION['original_user_msg'] = $userMsg;
            $_SESSION['detected_language'] = $lang;
            $_SESSION['conversation_language'] = $lang; // Lock conversation language

            // Only check for ratings after processing other intents to avoid interference
            $shouldCheckRating = ($userMsg !== '[system]' && 
                                !$this->isInActiveFlow() && 
                                $this->hasEligibleCompletedOrders());
                                
            if ($shouldCheckRating) {
                $this->checkAndShowPendingRatings($lang, $userMsg, $userMsg);
            }

            $this->log('Processing message in language: '.$lang);
            
            // Detect user intent using AI-powered detection with fallback
            $intent = $this->detectIntentWithAI($userMsg, $lang, $apiKey);
            $this->log('Detected intent: '.$intent);
            
            /* 0) FAQ Search - only for general questions, not specific intents */
            if ($intent === 'general' || $this->isGeneralQuestion($userMsg)) {
                $faqAnswer = $this->searchFAQUniversal($userMsg, $lang, $apiKey, $kbAbs);
                if ($faqAnswer) {
                    // Ensure the answer is in the user's language
                    $finalAnswer = $this->ensureAnswerInUserLanguage($faqAnswer, $lang, $apiKey);
                    $this->remember('user', $userMsg);
                    $this->remember('assistant', $finalAnswer);
                    $this->out([
                        'reply' => $finalAnswer, 
                        'source' => 'faq',
                        'conversation' => $_SESSION[self::S_CHAT] ?? []
                    ]); 
                    return;
                }
            }
            
            /* 1) Small talk handling (only when NOT in an active flow and message is not an email) */
            if ($intent === 'smalltalk' && !$this->isInActiveFlow() && $this->extractEmail($userMsg) === null) {
                $reply = $this->handleSmallTalk($userMsg, $lang);
                $translatedReply = $this->autoTranslateResponse($reply, $lang, 'smalltalk');
                $this->remember('user', $userMsg);
                $this->remember('assistant', $translatedReply);
                $this->out([
                    'reply' => $translatedReply, 
                    'source' => 'smalltalk',
                    'conversation' => $_SESSION[self::S_CHAT] ?? []
                ]);
                return;
            }

            /* 2) Order tracking flow */
            $flow = $_SESSION[self::S_FLOW] ?? null;
            if (is_array($flow) && in_array(($flow['stage'] ?? ''), ['need_email','need_id','start'], true)) {
                $reply = $this->handleOrderFlow($userMsg, $lang);
                $translatedReply = $this->autoTranslateResponse($reply, $lang, 'order');
                $this->remember('user', $userMsg);
                $this->remember('assistant', $translatedReply);
                $this->out([
                    'reply' => $translatedReply, 
                    'source' => 'order',
                    'conversation' => $_SESSION[self::S_CHAT] ?? []
                ]);
                return;
            }
            
            if ($intent === 'order') {
                $_SESSION[self::S_FLOW] = ['stage' => 'start', 'email' => null];
                $reply = $this->handleOrderFlow($userMsg, $lang);
                $translatedReply = $this->autoTranslateResponse($reply, $lang, 'order');
                $this->remember('user', $userMsg);
                $this->remember('assistant', $translatedReply);
                $this->out([
                    'reply' => $translatedReply, 
                    'source' => 'order',
                    'conversation' => $_SESSION[self::S_CHAT] ?? []
                ]); 
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
                $this->out([
                    'reply' => $translatedReply, 
                    'source' => 'catalog',
                    'conversation' => $_SESSION[self::S_CHAT] ?? []
                ]); 
                return;
            }

            if ($intent === 'catalog') {
                $reply = $this->handleSmartCatalogQuery($userMsg, $lang, $apiKey);
                if ($reply !== null) {
                    $this->remember('user', $userMsg);
                    $this->remember('assistant', $reply);
                    $this->out([
                        'reply' => $reply, 
                        'source' => 'catalog',
                        'conversation' => $_SESSION[self::S_CHAT] ?? []
                    ]); 
                    return;
                }
            }
            
            /* 6) Coupon-related queries - handle these FIRST to avoid confusion with promotions */
            $couponReply = $this->handleCouponQuery($userMsg, $lang);
            if ($couponReply) {
                $translatedCouponReply = $this->autoTranslateResponse($couponReply, $lang, 'coupon');
                $this->remember('user', $userMsg);
                $this->remember('assistant', $translatedCouponReply);
                $this->out([
                    'reply' => $translatedCouponReply, 
                    'source' => 'coupon',
                    'conversation' => $_SESSION[self::S_CHAT] ?? []
                ]);
                return;
            }

            /* Handle AI-detected promotion intents */
            if ($intent === 'promotion') {
                $promotionReply = $this->handlePromotionQuery($userMsg, $lang);
                if ($promotionReply) {
                    $translatedPromotionReply = $this->autoTranslateResponse($promotionReply, $lang, 'promotion');
                    $this->remember('user', $userMsg);
                    $this->remember('assistant', $translatedPromotionReply);
                    $this->out([
                        'reply' => $translatedPromotionReply, 
                        'source' => 'promotion',
                        'conversation' => $_SESSION[self::S_CHAT] ?? []
                    ]);
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
                $this->out([
                    'reply' => $translatedReply, 
                    'source' => 'continuation',
                    'conversation' => $_SESSION[self::S_CHAT] ?? []
                ]); 
                return;
            }

            /* 5) Enhanced FAQ Search with AI assistance - MOVED TO TOP PRIORITY */
            // This section has been moved to the beginning of the processing flow
            // to prioritize knowledge base responses

            /* 7) Promotion/Sale product queries */
            // Skip promotion handling if this was already handled by coupon handler above
            if (!preg_match('/\b(coupon|voucher|code\s+promo|code\s+remise|discount\s+code|coupon\s+code)\b/i', $userMsg)) {
                $promotionReply = $this->handlePromotionQuery($userMsg, $lang);
                if ($promotionReply) {
                    $translatedPromotionReply = $this->autoTranslateResponse($promotionReply, $lang, 'promotion');
                    $this->remember('user', $userMsg);
                    $this->remember('assistant', $translatedPromotionReply);
                    $this->out([
                        'reply' => $translatedPromotionReply, 
                        'source' => 'promotion',
                        'conversation' => $_SESSION[self::S_CHAT] ?? []
                    ]);
                    return;
                }
            }

            /* 8) Fallback - Contact support */
            $reply = $this->getFallbackMessage($lang);
            $translatedReply = $this->autoTranslateResponse($reply, $lang, 'fallback');
            $this->remember('user', $userMsg);
            $this->remember('assistant', $translatedReply);
            $this->out([
                'reply' => $translatedReply, 
                'source' => 'fallback',
                'conversation' => $_SESSION[self::S_CHAT] ?? []
            ]);

        } catch (\Throwable $e) {
            $this->log('FATAL: '.$e->getMessage());
            $this->out([
                'reply'=>'Please contact support.',
                'source'=>'error',
                'conversation' => $_SESSION[self::S_CHAT] ?? []
            ]);
        }
    }

    /* ==================== FLOW MANAGEMENT ==================== */
    
    private function getOrSetSessionLanguage(string $userMsg): string
    {
        // Detect language from current message
        $currentLang = $this->detectLanguage($userMsg);
        
        // Check if this is a new conversation or reset
        $isNewConversation = !isset($_SESSION['conversation_language']) || empty($_SESSION[self::S_CHAT]);
        
        // For new conversations, set the language based on detection
        if ($isNewConversation) {
            $_SESSION['conversation_language'] = $currentLang;
            $this->log("LANG INIT: Setting conversation language to '$currentLang' from first message");
        } else {
            // For existing conversations, check if we need to update the language
            // If the message is clearly in a different language (like French greetings or questions)
            if ($currentLang === 'fr' && preg_match('/\b(bonjour|salut|avez-vous|pourquoi|comment)\b/ui', $userMsg)) {
                $_SESSION['conversation_language'] = 'fr';
                $this->log("LANG UPDATE: Switching conversation language to 'fr' based on clear French indicators");
            } elseif ($currentLang === 'en' && preg_match('/\b(hello|hi|how|why|what|where)\b/ui', $userMsg)) {
                $_SESSION['conversation_language'] = 'en';
                $this->log("LANG UPDATE: Switching conversation language to 'en' based on clear English indicators");
            } else {
                $this->log("LANG MAINTAIN: Keeping conversation language as '{$_SESSION['conversation_language']}' (detected: $currentLang)");
            }
        }
        
        $this->log("LANG DETECT: Detected '$currentLang' from message: \"" . substr($userMsg, 0, 50) . '..."');
        
        return $_SESSION['conversation_language'];
    }
    
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

    /**
     * Enhanced small talk handler with context awareness
     * Uses conversation history and previous intents for more natural responses
     */
    private function handleSmallTalk(string $message, string $lang): string
    {
        // Get conversation context for better responses
        $conversationSummary = $_SESSION['conversation_summary'] ?? null;
        $messageCount = $conversationSummary['message_count'] ?? 0;
        $previousIntent = $_SESSION['detected_intent'] ?? null;
        $topics = $conversationSummary['topics'] ?? [];
        
        $this->log('SMALLTALK: Context - Message count: ' . $messageCount . ', Previous intent: ' . ($previousIntent ?? 'none') . ', Topics: ' . implode(',', $topics));
        
        // Try to use AI for more natural conversation if API key is available
        $apiKey = Configuration::get('AI_CHATBOT_API_KEY');
        if ($apiKey) {
            $aiResponse = $this->getAISmallTalkResponse($message, $lang, $apiKey, $conversationSummary);
            if ($aiResponse) {
                $this->log('SMALLTALK: Using AI-generated response');
                return $aiResponse;
            }
        }
        
        // Fallback to enhanced rule-based responses if AI is not available
        $this->log('SMALLTALK: Using rule-based fallback response');
        $lowerMsg = mb_strtolower($message, 'UTF-8');
        
        // Define responses directly with context awareness
        if ($lang === 'fr') {
            $greetingPatterns = ['salut', 'bonjour', 'coucou', 'hello', 'hi'];
            $greetingReplies = [
                "Salut ! 👋 Comment puis-je vous aider (produits, prix, stock, commandes, retours) ?",
                "Bonjour ! En quoi puis-je vous être utile aujourd'hui ?",
                "Hello ! Je suis là pour vous aider. Que cherchez-vous ?"
            ];
            
            // Context-aware greeting for returning users
            if ($messageCount > 5) {
                $greetingReplies[] = "Ravi de vous revoir ! Comment puis-je vous aider aujourd'hui ?";
                $greetingReplies[] = "Bon retour ! Que puis-je faire pour vous cette fois-ci ?";
            }
            
            $thanksPatterns = ['merci', 'thank you', 'thanks'];
            $thanksReplies = [
                "De rien ! Autre chose ?",
                "Je vous en prie ! Y a-t-il autre chose ?",
                "Avec plaisir ! Puis-je vous aider davantage ?"
            ];
            
            // Context-aware thanks responses
            if (in_array('product', $topics)) {
                $thanksReplies[] = "De rien ! Souhaitez-vous voir d'autres produits similaires ?";
            }
            if (in_array('order', $topics)) {
                $thanksReplies[] = "Avec plaisir ! Avez-vous d'autres questions concernant votre commande ?";
            }
            
            $goodbyePatterns = ['au revoir', 'adieu', 'à bientôt', 'bye', 'goodbye', 'a plus'];
            $goodbyeReplies = [
                "Au revoir ! Merci d'avoir discuté avec moi. N'hésitez pas à revenir si vous avez d'autres questions.",
                "À bientôt ! J'espère avoir pu vous aider.",
                "Au revoir et bonne journée !"
            ];
            
            $defaultReplies = [
                "D'accord ! Dites-moi ce dont vous avez besoin.",
                "Très bien ! Comment puis-je vous aider ?"
            ];
            
            // Context-aware default responses
            if (!empty($topics)) {
                $defaultReplies[] = "Je vois que nous avons parlé de " . implode(', ', $topics) . ". Puis-je vous aider davantage avec l'un de ces sujets ?";
            }
        } else {
            $greetingPatterns = ['hi', 'hello', 'hey', 'bonjour', 'salut', 'hola'];
            $greetingReplies = [
                "Hi! 👋 How can I help (products, prices, stock, orders, returns)?",
                "Hello! What can I assist you with today?",
                "Hey there! I'm here to help. What are you looking for?"
            ];
            
            // Context-aware greeting for returning users
            if ($messageCount > 5) {
                $greetingReplies[] = "Great to see you again! How can I help you today?";
                $greetingReplies[] = "Welcome back! What can I do for you this time?";
            }
            
            $thanksPatterns = ['thanks', 'thank you', 'merci', 'thx'];
            $thanksReplies = [
                "You're welcome! Anything else I can help with?",
                "My pleasure! Is there anything else?",
                "Happy to help! What else can I do for you?"
            ];
            
            // Context-aware thanks responses
            if (in_array('product', $topics)) {
                $thanksReplies[] = "You're welcome! Would you like to see other similar products?";
            }
            if (in_array('order', $topics)) {
                $thanksReplies[] = "My pleasure! Do you have any other questions about your order?";
            }
            
            $goodbyePatterns = ['goodbye', 'bye', 'see you', 'cya', 'farewell', 'see ya', 'later'];
            $goodbyeReplies = [
                "Goodbye! Thanks for chatting with me. Feel free to come back if you have more questions.",
                "See you soon! I hope I was able to help you.",
                "Goodbye and have a great day!"
            ];
            
            $defaultReplies = [
                "Okay! Tell me what you need.",
                "Alright! How can I help you?"
            ];
            
            // Context-aware default responses
            if (!empty($topics)) {
                $defaultReplies[] = "I see we've been talking about " . implode(', ', $topics) . ". Can I help you further with any of these topics?";
            }
        }
        
        // Check greeting patterns
        if ($this->matchesPattern($lowerMsg, $greetingPatterns)) {
            return $this->randomChoice($greetingReplies);
        }
        
        // Check thanks patterns
        if ($this->matchesPattern($lowerMsg, $thanksPatterns)) {
            return $this->randomChoice($thanksReplies);
        }
        
        // Check goodbye patterns
        if ($this->matchesPattern($lowerMsg, $goodbyePatterns)) {
            return $this->randomChoice($goodbyeReplies);
        }
        
        return $this->randomChoice($defaultReplies);
    }
    
    /**
     * Get AI-powered small talk response using OpenAI
     * Enhanced with conversation context awareness and personalization
     */
    private function getAISmallTalkResponse(string $message, string $lang, string $apiKey, ?array $conversationSummary = null): ?string
    {
        try {
            // Get conversation history for context
            $history = $_SESSION[self::S_CHAT] ?? [];
            $contextMessages = [];
            
            // Add up to 10 previous messages for context
            $recentHistory = array_slice($history, -10, 10);
            foreach ($recentHistory as $entry) {
                $contextMessages[] = [
                    'role' => $entry['role'],
                    'content' => $entry['content']
                ];
            }
            
            // Add the current user message
            $contextMessages[] = ['role' => 'user', 'content' => $message];
            
            // Create an enhanced system message with context awareness
            $basePrompt = $lang === 'fr' ? 
                "Tu es un assistant de service client amical pour une boutique en ligne. Réponds de manière concise et utile en français. Garde tes réponses courtes (maximum 2-3 phrases). N'invente pas d'informations sur les produits ou les commandes." :
                "You are a friendly customer service assistant for an online store. Respond in a concise and helpful way in English. Keep your responses short (maximum 2-3 sentences). Don't make up information about products or orders.";
            
            // Add context information to the system prompt
            $systemPrompt = $basePrompt;
            
            if ($conversationSummary) {
                $messageCount = $conversationSummary['message_count'] ?? 0;
                $topics = $conversationSummary['topics'] ?? [];
                $activeFlow = $conversationSummary['active_flow'] ?? null;
                
                // Add context-specific instructions based on conversation state
                if ($lang === 'fr') {
                    if (!empty($topics)) {
                        $systemPrompt .= " La conversation a couvert ces sujets: " . implode(', ', $topics) . ".";
                    }
                    
                    if ($activeFlow) {
                        $systemPrompt .= " L'utilisateur est actuellement dans le flux '" . $activeFlow . "'.";
                    }
                    
                    // Add personalization based on conversation length
                    if ($messageCount > 5) {
                        $systemPrompt .= " C'est un utilisateur régulier, sois plus personnalisé dans ta réponse.";
                    }
                } else {
                    if (!empty($topics)) {
                        $systemPrompt .= " The conversation has covered these topics: " . implode(', ', $topics) . ".";
                    }
                    
                    if ($activeFlow) {
                        $systemPrompt .= " The user is currently in the '" . $activeFlow . "' flow.";
                    }
                    
                    // Add personalization based on conversation length
                    if ($messageCount > 5) {
                        $systemPrompt .= " This is a returning user, be more personalized in your response.";
                    }
                }
                
                // Add previous intent if available for better context
                $previousIntent = $_SESSION['detected_intent'] ?? null;
                if ($previousIntent) {
                    if ($lang === 'fr') {
                        $systemPrompt .= " L'intention précédente était: " . $previousIntent . ".";
                    } else {
                        $systemPrompt .= " The previous intent was: " . $previousIntent . ".";
                    }
                }
            }
            
            $client = new Client();
            $response = $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'model' => self::CHAT_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ...$contextMessages
                    ],
                    'max_tokens' => 150,
                    'temperature' => 0.8  // Slightly higher temperature for more varied responses
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            $aiResponse = trim($data['choices'][0]['message']['content'] ?? '');
            
            if ($aiResponse && strlen($aiResponse) > 0) {
                $this->log('AI SMALLTALK SUCCESS: ' . substr($aiResponse, 0, 50) . ' (with enhanced context)');
                return $aiResponse;
            }
            
        } catch (\Throwable $e) {
            $this->log('AI SMALLTALK ERROR: ' . $e->getMessage());
        }
        
        return null;  // Explicit return null on failure
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
                // Check if email was provided in the initial message
                if ($typedEmail) {
                    // Security check: Verify if user is logged in and the email matches
                    if ($isLoggedIn) {
                        if (strtolower($typedEmail) !== $loggedEmail) {
                            $_SESSION[self::S_FLOW] = ['stage' => 'need_email', 'email' => null];
                            return $this->getLocalizedMessage('order_email_mismatch', $lang);
                        }
                    } else {
                        // User is not logged in
                        $_SESSION[self::S_FLOW] = ['stage' => 'need_email', 'email' => null];
                        return $this->getLocalizedMessage('order_login_required', $lang);
                    }
                    
                    // Email is valid and user is logged in with matching email
                    $_SESSION[self::S_FLOW] = ['stage' => 'need_id', 'email' => $typedEmail];
                    $this->captureEmailForCollection($typedEmail);
                    return $this->getLocalizedMessage('order_need_id', $lang);
                } else {
                    // No email provided, ask for it
                    $_SESSION[self::S_FLOW] = ['stage' => 'need_email', 'email' => null];
                    return $this->getLocalizedMessage('order_need_email', $lang);
                }
                
            case 'need_email':
                if (!$typedEmail) {
                    return $this->getLocalizedMessage('order_email_required', $lang);
                }
                
                $_SESSION[self::S_FLOW]['email'] = $typedEmail;
                
                // Always capture email (subscriber or not)
                $this->captureEmailForCollection($typedEmail);
                
                // Security check: Verify if user is logged in and the email matches
                if ($isLoggedIn) {
                    if (strtolower($typedEmail) !== $loggedEmail) {
                        unset($_SESSION[self::S_FLOW]);
                        return $this->getLocalizedMessage('order_email_mismatch', $lang);
                    }
                } else {
                    // User is not logged in
                    unset($_SESSION[self::S_FLOW]);
                    return $this->getLocalizedMessage('order_login_required', $lang);
                }
                
                $_SESSION[self::S_FLOW]['stage'] = 'need_id';
                return $this->getLocalizedMessage('order_need_id', $lang);
                
            case 'need_id':
                if (!$orderInfo['id'] && !$orderInfo['reference']) {
                    return $this->getLocalizedMessage('order_id_required', $lang);
                }
                
                $email = $_SESSION[self::S_FLOW]['email'];
                unset($_SESSION[self::S_FLOW]);
                return $this->getOrderStatus($orderInfo, $email, $lang);
                
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
            return $this->getLocalizedMessage('order_need_email', $lang);
        }

        if ($state['stage'] === 'need_email') {
            if (!$typedEmail) {
                return $this->getLocalizedMessage('order_email_required', $lang);
            }
            $_SESSION[self::S_FLOW]['email'] = $typedEmail;

            if (!$isLogged || $loggedEmail !== strtolower($typedEmail)) {
                unset($_SESSION[self::S_FLOW]);
                return $this->getLocalizedMessage('order_security_error', $lang);
            }

            // Only capture email for newsletter AFTER successful verification
            $this->captureNewsletterEmail($typedEmail);
            
            $_SESSION[self::S_FLOW]['stage'] = 'need_id';
            return $this->getLocalizedMessage('order_need_id', $lang);
        }

        if ($state['stage'] === 'need_id') {
            if (!($oid['id'] || $oid['ref'])) {
                return $this->getLocalizedMessage('order_id_required', $lang);
            }
            unset($_SESSION[self::S_FLOW]);
            return $this->renderOrderStatus($oid['id'], $oid['ref'], $lang);
        }

        unset($_SESSION[self::S_FLOW]);
        return $this->getLocalizedMessage('order_restart', $lang);
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
                    
                    if ($lang === 'fr') {
                        return "Commande **#{$o->reference}** du {$date} ({$total}) est **{$name}**."; 
                    } else {
                        return "Order **#{$o->reference}** from {$date} ({$total}) is **{$name}**."; 
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log('ORDER STATUS ERR: '.$e->getMessage());
        }

        if ($lang === 'fr') {
            return "Désolé, je n'ai pas trouvé de commande avec ce numéro. Veuillez vérifier et renvoyer.";
        } else {
            return "Sorry, I couldn't find an order with that number. Please double-check and resend.";
        }
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

    /**
     * Capture email for collection (subscribers or not)
     */
    private function captureEmailForCollection(string $email): void
    {
        $email = trim($email);
        if ($email === '' || !Validate::isEmail($email)) return;

        try {
            // First try newsletter subscription table
            $this->captureNewsletterEmail($email);
            
            // Also log to a general collection table if it exists
            $this->logEmailCollection($email);
            
        } catch (\Throwable $e) {
            $this->log('EMAIL COLLECTION ERR: '.$e->getMessage());
        }
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
            $this->log('NEWSLETTER CAPTURE ERR: '.$e->getMessage());
        }
    }
    
    /**
     * Log email collection for analytics/marketing purposes
     */
    private function logEmailCollection(string $email): void
    {
        try {
            // Create/use a custom email collection table
            $sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'ai_chatbot_email_collection` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `email` varchar(255) NOT NULL,
                `collected_via` varchar(50) DEFAULT "order_inquiry",
                `ip_address` varchar(45),
                `date_add` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;';
            
            Db::getInstance()->execute($sql);
            
            // Insert or update the email record
            $data = [
                'email' => pSQL($email),
                'collected_via' => 'order_inquiry',
                'ip_address' => pSQL(Tools::getRemoteAddr()),
                'date_add' => date('Y-m-d H:i:s')
            ];
            
            Db::getInstance()->insert('ai_chatbot_email_collection', $data, false, true, Db::ON_DUPLICATE_KEY);
            
        } catch (\Throwable $e) {
            $this->log('EMAIL COLLECTION LOG ERR: '.$e->getMessage());
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
        $this->log('=== SELECTIVE FILTER EXTRACTION START ===');
        $this->log('FILTER EXTRACTION: Processing tokens: ' . json_encode($tokens));
        $this->log('FILTER EXTRACTION: Input message: "' . implode(' ', $tokens) . '"');
        
        // Get enabled filter configurations to focus only on enabled filters
        $enabledFilters = $this->getEnabledFilterConfigurations();
        $this->log('FILTER EXTRACTION: Enabled filters: ' . json_encode($enabledFilters));
        $this->debugLog('SELECTIVE FILTER CONFIG', $enabledFilters);
        
        // Parse comma-separated values to handle multi-criteria searches like "black,nike"
        $expandedTokens = [];
        foreach ($tokens as $token) {
            if (strpos($token, ',') !== false) {
                // Split by comma and add individual terms
                $parts = array_map('trim', explode(',', $token));
                $expandedTokens = array_merge($expandedTokens, $parts);
                $this->log('FILTER EXTRACTION: Split comma-separated token: ' . $token . ' into: ' . json_encode($parts));
            } else {
                $expandedTokens[] = $token;
            }
        }
        
        // Remove duplicates and empty values
        $expandedTokens = array_values(array_filter(array_unique($expandedTokens)));
        $this->log('FILTER EXTRACTION: Expanded tokens: ' . json_encode($expandedTokens));

        // SELECTIVE EXTRACTION: Only extract what's enabled, detect but ignore what's disabled
        $detectedBrands = [];
        $detectedAttributes = [];
        $ignoredBrands = [];
        $ignoredAttributes = [];
        
        // STEP 1: Detect ALL possible brands and attributes (regardless of enabled state)
        if ($expandedTokens) {
            // Detect brands
            $exactMatches = [];
            $likeMatches = [];
            foreach ($expandedTokens as $token) {
                $exactMatches[] = "m.name = '".pSQL($token)."'";
                $likeMatches[] = "m.name LIKE '".pSQL('%'.$token.'%')."'";
            }
            
            // Try exact matches first
            $exactQuery = 'SELECT id_manufacturer, name FROM '._DB_PREFIX_.'manufacturer m WHERE ('.implode(' OR ', $exactMatches).') LIMIT 16';
            $brandRows = $db->executeS($exactQuery) ?: [];
            
            // If no exact matches, try LIKE matches
            if (empty($brandRows)) {
                $likeQuery = 'SELECT id_manufacturer, name FROM '._DB_PREFIX_.'manufacturer m WHERE ('.implode(' OR ', $likeMatches).') LIMIT 16';
                $brandRows = $db->executeS($likeQuery) ?: [];
            }
            
            foreach ($brandRows as $r) {
                $detectedBrands[] = [
                    'id' => (int)$r['id_manufacturer'],
                    'name' => $r['name']
                ];
            }
            
            // Detect attributes (colors and sizes)
            $exactMatches = [];
            $likeMatches = [];
            foreach ($expandedTokens as $token) {
                $exactMatches[] = "al.name = '".pSQL($token)."'";
                $likeMatches[] = "al.name LIKE '".pSQL('%'.$token.'%')."'";
            }
            
            $exactQuery = 'SELECT DISTINCT a.id_attribute, al.name, ag.id_attribute_group, agl.name as group_name
                          FROM '._DB_PREFIX_.'attribute a
                          INNER JOIN '._DB_PREFIX_.'attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang = '.(int)$idLang.'
                          INNER JOIN '._DB_PREFIX_.'attribute_group ag ON a.id_attribute_group=ag.id_attribute_group
                          INNER JOIN '._DB_PREFIX_.'attribute_group_lang agl ON ag.id_attribute_group=agl.id_attribute_group AND agl.id_lang = '.(int)$idLang.'
                          WHERE ('.implode(' OR ', $exactMatches).')
                          LIMIT 64';
            $attrRows = $db->executeS($exactQuery) ?: [];
            
            // If no exact matches, try LIKE matches
            if (empty($attrRows)) {
                $likeQuery = 'SELECT DISTINCT a.id_attribute, al.name, ag.id_attribute_group, agl.name as group_name
                              FROM '._DB_PREFIX_.'attribute a
                              INNER JOIN '._DB_PREFIX_.'attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang = '.(int)$idLang.'
                              INNER JOIN '._DB_PREFIX_.'attribute_group ag ON a.id_attribute_group=ag.id_attribute_group
                              INNER JOIN '._DB_PREFIX_.'attribute_group_lang agl ON ag.id_attribute_group=agl.id_attribute_group AND agl.id_lang = '.(int)$idLang.'
                              WHERE ('.implode(' OR ', $likeMatches).')
                              LIMIT 64';
                $attrRows = $db->executeS($likeQuery) ?: [];
            }
            
            // Classify attributes by type
            foreach ($attrRows as $r) {
                $groupName = strtolower($r['group_name'] ?? '');
                $attrName = $r['name'];
                $attrType = null;
                
                // Determine attribute type
                if (strpos($groupName, 'color') !== false || 
                    strpos($groupName, 'colour') !== false || 
                    strpos($groupName, 'couleur') !== false) {
                    $attrType = 'color';
                } elseif (strpos($groupName, 'size') !== false || 
                          strpos($groupName, 'taille') !== false) {
                    $attrType = 'size';
                } else {
                    // Try to detect by common values
                    $commonColors = ['red', 'blue', 'green', 'black', 'white', 'yellow', 'orange', 'purple', 'pink', 'brown', 'gray', 'grey',
                                   'rouge', 'bleu', 'vert', 'noir', 'blanc', 'jaune', 'violet', 'rose', 'marron', 'gris'];
                    $commonSizes = ['xs', 's', 'm', 'l', 'xl', 'xxl', 'small', 'medium', 'large', 'petit', 'moyen', 'grand'];
                    
                    $attrLower = strtolower($attrName);
                    if (in_array($attrLower, $commonColors)) {
                        $attrType = 'color';
                    } elseif (in_array($attrLower, $commonSizes) || preg_match('/^\d{2,3}$/', $attrName)) {
                        $attrType = 'size';
                    }
                }
                
                if ($attrType) {
                    $detectedAttributes[] = [
                        'id' => (int)$r['id_attribute'],
                        'name' => $attrName,
                        'type' => $attrType,
                        'group' => $r['group_name'] ?? 'Unknown'
                    ];
                }
            }
        }
        
        $this->log('DETECTED BRANDS: ' . count($detectedBrands) . ' found');
        $this->log('DETECTED ATTRIBUTES: ' . count($detectedAttributes) . ' found');
        
        // STEP 2: Apply selective filtering based on enabled configurations
        $finalBrandIds = [];
        $finalAttrIds = [];
        
        // Process brands
        foreach ($detectedBrands as $brand) {
            if ($enabledFilters['brand']) {
                $finalBrandIds[] = $brand['id'];
                $this->log('SELECTIVE FILTER: USING brand: ' . $brand['name'] . ' (ID: ' . $brand['id'] . ') - ENABLED');
            } else {
                $ignoredBrands[] = $brand;
                $this->log('SELECTIVE FILTER: IGNORING brand: ' . $brand['name'] . ' (ID: ' . $brand['id'] . ') - DISABLED');
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
                $this->log('SELECTIVE FILTER: USING ' . $attr['type'] . ': ' . $attr['name'] . ' (ID: ' . $attr['id'] . ') - ENABLED');
            } else {
                $ignoredAttributes[] = $attr;
                $this->log('SELECTIVE FILTER: IGNORING ' . $attr['type'] . ': ' . $attr['name'] . ' (ID: ' . $attr['id'] . ') - DISABLED');
            }
        }

        
        // STEP 3: Log summary and return final result
        $this->log('=== SELECTIVE FILTER SUMMARY ===');
        $this->log('FINAL BRANDS USED: ' . count($finalBrandIds) . ' (' . implode(', ', $finalBrandIds) . ')');
        $this->log('FINAL ATTRIBUTES USED: ' . count($finalAttrIds) . ' (' . implode(', ', $finalAttrIds) . ')');
        $this->log('IGNORED BRANDS: ' . count($ignoredBrands));
        $this->log('IGNORED ATTRIBUTES: ' . count($ignoredAttributes));
        
        // Enhanced debug logging
        $this->debugLog('SELECTIVE FILTER RESULTS', [
            'enabled_filters' => $enabledFilters,
            'input_tokens' => $expandedTokens,
            'detected_brands' => $detectedBrands,
            'detected_attributes' => $detectedAttributes,
            'final_brand_ids' => $finalBrandIds,
            'final_attribute_ids' => $finalAttrIds,
            'ignored_brands' => $ignoredBrands,
            'ignored_attributes' => $ignoredAttributes
        ]);
        
        // Show what was ignored (for user feedback)
        if (!empty($ignoredBrands)) {
            $ignoredBrandNames = array_map(function($b) { return $b['name']; }, $ignoredBrands);
            $this->log('SELECTIVE FILTER: Ignored brands (filter disabled): ' . implode(', ', $ignoredBrandNames));
        }
        
        if (!empty($ignoredAttributes)) {
            $ignoredAttrNames = array_map(function($a) { return $a['name'] . '(' . $a['type'] . ')'; }, $ignoredAttributes);
            $this->log('SELECTIVE FILTER: Ignored attributes (filter disabled): ' . implode(', ', $ignoredAttrNames));
        }
        
        $result = [
            'brands' => array_values(array_unique($finalBrandIds)),
            'attrs'  => array_values(array_unique($finalAttrIds)),
            'ignored_brands' => $ignoredBrands,
            'ignored_attributes' => $ignoredAttributes,
            'has_disabled_criteria' => (!empty($ignoredBrands) || !empty($ignoredAttributes))
        ];
        
        $this->log('=== SELECTIVE FILTER EXTRACTION END ===');
        $this->log('FINAL RESULT: ' . json_encode($result));
        return $result;
    }

    private function findProductsInCategories(array $catIds, int $idLang, array $filter, int $limit = 24, array $nameTokens = []): array
    {
        if (!$catIds) return [];
        $catIds = $this->expandCategoryIds($catIds);

        $brands = array_values(array_unique(array_map('intval', (array)($filter['brands'] ?? []))));
        $attrs  = array_values(array_unique(array_map('intval', (array)($filter['attrs'] ?? []))));

        // Prioritize exact matches with both brand and attribute filters when both are present
        if (!empty($brands) && !empty($attrs)) {
            $this->log('CAT SEARCH: Prioritizing exact match with both brands and attributes');
            $rows = $this->runProductQuery($catIds, $idLang, $brands, $attrs, $limit, $nameTokens, 'all');
            if ($rows) {
                $this->log('CAT SEARCH: Found exact matches with both brands and attributes');
                return $rows;
            } else {
                $this->log('CAT SEARCH: No exact matches found for both brands and attributes - returning empty');
                return []; // Don't fall back to partial matches when both are specified
            }
        }
        
        // Modified stages to prioritize more specific searches first
        // The 'all' mode is now handled by our improved runProductQuery method
        $stages = [
            // Try with both brand and attributes in 'all' mode (most specific)
            [$brands, $attrs, 'all'],
            // Try with both brand and attributes in 'any' mode
            [$brands, $attrs, 'any'],
            // Try with just brands
            [$brands, [],     'any'],
            // Try with just attributes in 'all' mode
            [[],      $attrs, 'all'],
            // Try with just attributes in 'any' mode
            [[],      $attrs, 'any'],
            // Fallback to just category
            [[],      [],     'any'],
        ];
        
        // If we have both brands and attributes, we MUST find products that match BOTH
        // Don't fall back to partial matches
        if (!empty($brands) && !empty($attrs)) {
            $this->log('CAT SEARCH: Both brands and attributes specified - using strict matching only');
            $stages = [
                [$brands, $attrs, 'all'],
                [$brands, $attrs, 'any'],
            ];
        }
        
        $this->log('CAT SEARCH: Starting with brands='.count($brands).', attrs='.count($attrs).', stages='.count($stages));

        foreach ($stages as $idx => $st) {
            [$b, $a, $mode] = $st;
            $this->log('CAT SEARCH stage='.$idx.' mode='.$mode.' catIds='.json_encode($catIds).' brands='.json_encode($b).' attrs='.json_encode($a).' tokens='.json_encode($nameTokens));
            $rows = $this->runProductQuery($catIds, $idLang, $b, $a, $limit, $nameTokens, $mode);
            if ($rows) {
                $this->log('CAT SEARCH: Found matches at stage '.$idx.' with mode='.$mode);
                return $rows;
            }
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

        // DEBUGGING: Log all input parameters
        $this->log('=== PRODUCT QUERY DEBUG START ===');
        $this->log('INPUT PARAMS: catIds=' . json_encode($catIds) . ', idLang=' . $idLang . ', brandIds=' . json_encode($brandIds) . ', attrIds=' . json_encode($attrIds) . ', limit=' . $limit . ', attrMode=' . $attrMode);
        $this->debugLog('PRODUCT QUERY DEBUG', [
            'category_ids' => $catIds,
            'language_id' => $idLang,
            'brand_ids' => $brandIds,
            'attribute_ids' => $attrIds,
            'limit' => $limit,
            'attribute_mode' => $attrMode
        ]);

        // Always use 'all' mode for brand+attribute combinations to ensure proper multi-criteria matching
        $effectiveAttrMode = (!empty($brandIds) && !empty($attrIds)) ? 'all' : $attrMode;
        $this->log('PRODUCT QUERY: Using effective attribute mode: ' . $effectiveAttrMode . ' (original: ' . $attrMode . ')');
        $this->log('PRODUCT QUERY: Brand IDs: ' . json_encode($brandIds) . ', Attribute IDs: ' . json_encode($attrIds));
        
        if (!empty($brandIds)) {
            $w[] = 'p.id_manufacturer IN ('.implode(',', $brandIds).')';
            $this->log('PRODUCT QUERY: Filtering by brands: ' . implode(', ', $brandIds));
        }

        if (!empty($attrIds)) {
            // FIXED: Use the exact same query structure as your working SQL
            // This matches your provided working SQL query exactly
            
            $this->log('PRODUCT QUERY: Building attribute filter for IDs: ' . implode(',', $attrIds));
            
            if ($effectiveAttrMode === 'all') {
                // Use the EXACT same structure as your working SQL query
                $attrCondition = "p.id_product IN (
                    SELECT pa.id_product
                    FROM " . _DB_PREFIX_ . "product_attribute pa
                    INNER JOIN " . _DB_PREFIX_ . "product_attribute_combination pac
                        ON pac.id_product_attribute = pa.id_product_attribute
                    WHERE pac.id_attribute IN (" . implode(',', array_map('intval', $attrIds)) . ")
                    GROUP BY pa.id_product
                    HAVING COUNT(DISTINCT pac.id_attribute) = " . count($attrIds) . "
                )";
                $w[] = $attrCondition;
                $this->log('PRODUCT QUERY: Using EXACT working SQL structure for ALL attributes: ' . implode(',', $attrIds));
                
            } else {
                // For 'any' mode, use simpler structure
                $attrCondition = "p.id_product IN (
                    SELECT DISTINCT pa.id_product
                    FROM " . _DB_PREFIX_ . "product_attribute pa
                    INNER JOIN " . _DB_PREFIX_ . "product_attribute_combination pac
                        ON pac.id_product_attribute = pa.id_product_attribute
                    WHERE pac.id_attribute IN (" . implode(',', array_map('intval', $attrIds)) . ")
                )";
                $w[] = $attrCondition;
                $this->log('PRODUCT QUERY: Using ANY mode with attributes: ' . implode(', ', $attrIds));
            }
        }

        $sql = 'SELECT DISTINCT p.id_product, pl.name, p.price
                FROM '._DB_PREFIX_.'product p
                INNER JOIN '._DB_PREFIX_.'product_lang pl ON (pl.id_product=p.id_product AND pl.id_lang='.(int)$idLang.')
                INNER JOIN '._DB_PREFIX_.'category_product cp ON cp.id_product=p.id_product
                WHERE '.implode(' AND ', $w).'
                ORDER BY p.date_upd DESC
                LIMIT '.(int)$limit;

        // DEBUGGING: Log the complete SQL query
        $this->log('=== FINAL SQL QUERY ===');
        $this->log('COMPLETE SQL: ' . $sql);
        $this->debugLog('FINAL SQL QUERY', ['sql' => $sql, 'where_conditions' => $w]);
        
        // Execute query and log results
        $startTime = microtime(true);
        $rows = $db->executeS($sql) ?: [];
        $executionTime = microtime(true) - $startTime;
        
        $this->log('QUERY EXECUTION: Found ' . count($rows) . ' rows in ' . round($executionTime * 1000, 2) . 'ms');
        $this->debugLog('QUERY RESULTS', [
            'row_count' => count($rows),
            'execution_time_ms' => round($executionTime * 1000, 2),
            'first_3_results' => array_slice($rows, 0, 3)
        ]);
        $this->log('=== PRODUCT QUERY DEBUG END ===');

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
    
    /**
     * Universal FAQ search with AI enhancement
     */
    private function searchFAQUniversal(string $userMsg, string $lang, string $apiKey, string $kbAbs): ?string
    {
        $this->log('PRIORITIZED FAQ SEARCH: Starting with query "' . substr($userMsg, 0, 50) . '..."');
        
        // First try original search methods with lower thresholds for higher match rate
        $isQuestion = preg_match('/[?\?]$/', trim($userMsg)) || 
                     preg_match('/^(how|what|where|when|why|who|can|do|does|is|are|will|should)/i', trim($userMsg));
        
        // Apply a 20% lower threshold for all FAQ searches to increase match probability
        $originalKwThresholdMin = self::KW_THRESHOLD_MIN;
        $originalKwThresholdMax = self::KW_THRESHOLD_MAX;
        $originalSemThresholdMin = self::SEM_THRESHOLD_MIN;
        $originalSemThresholdMax = self::SEM_THRESHOLD_MAX;
        
        // Use higher thresholds to be more selective and avoid false matches
        $this->setConstant('_KW_THRESHOLD_MIN', $originalKwThresholdMin * 1.2);
        $this->setConstant('_KW_THRESHOLD_MAX', $originalKwThresholdMax * 1.2);
        $this->setConstant('_SEM_THRESHOLD_MIN', $originalSemThresholdMin * 1.2);
        $this->setConstant('_SEM_THRESHOLD_MAX', $originalSemThresholdMax * 1.2);
        
        // Try standard search with higher thresholds
        $answer = $this->searchFAQ($userMsg, $lang, $apiKey, $kbAbs, $kbAbs . '.embeddings.json');
        
        // Restore original thresholds
        $this->setConstant('_KW_THRESHOLD_MIN', $originalKwThresholdMin);
        $this->setConstant('_KW_THRESHOLD_MAX', $originalKwThresholdMax);
        $this->setConstant('_SEM_THRESHOLD_MIN', $originalSemThresholdMin);
        $this->setConstant('_SEM_THRESHOLD_MAX', $originalSemThresholdMax);
        
        if ($answer) {
            $this->log('PRIORITIZED FAQ SEARCH: Found answer with lower thresholds');
            return $answer;
        }
        
        // If no match with standard search, try AI-enhanced FAQ search
        if ($apiKey && $kbAbs) {
            $aiAnswer = $this->getAIEnhancedFAQAnswer($userMsg, $lang, $apiKey, $kbAbs);
            if ($aiAnswer) {
                $this->log('PRIORITIZED FAQ SEARCH: AI enhanced answer found');
                return $aiAnswer;
            }
        }
        
        $this->log('PRIORITIZED FAQ SEARCH: No answer found');
        return null;
    }
    
    private function setConstant(string $name, float $value): void
    {
        // This is a workaround to modify class constants at runtime
        $reflection = new ReflectionClass($this);
        $constants = $reflection->getConstants();
        if (array_key_exists($name, $constants)) {
            $reflectionConstant = $reflection->getReflectionConstant($name);
            if ($reflectionConstant->isPublic()) {
                // For PHP 8.1+ we could use ReflectionClassConstant::setValue
                // But for compatibility we use this approach
                $this->log("Setting constant $name to $value");
                // Store in a static property as we can't modify constants at runtime
                self::${'_' . $name} = $value;
            }
        }
    }
    
    private function getAIEnhancedFAQAnswer(string $question, string $lang, string $apiKey, string $kbPath): ?string
    {
        try {
            if (!$apiKey) return null;
            
            // Load FAQ content
            $faqContent = $this->loadFAQContent($kbPath);
            if (!$faqContent) return null;
            
            $client = new Client();
            $prompt = "You are a helpful customer service assistant. Answer the customer's question using ONLY the information provided in the FAQ below. If the answer is not in the FAQ, return 'NO_ANSWER_FOUND'.

FAQ Content:
" . substr($faqContent, 0, 2000) . "

Customer Question: $question

Answer:";
            
            $response = $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'model' => self::CHAT_MODEL,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'max_tokens' => 300,
                    'temperature' => 0.1
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            $answer = trim($data['choices'][0]['message']['content'] ?? '');
            
            if ($answer && $answer !== 'NO_ANSWER_FOUND' && strlen($answer) > 10) {
                $this->log('AI ENHANCED FAQ: Found answer: ' . substr($answer, 0, 100));
                return $answer;
            }
            
        } catch (\Throwable $e) {
            $this->log('AI ENHANCED FAQ ERROR: ' . $e->getMessage());
        }
        
        return null;
    }
    
    private function loadFAQContent(string $kbPath): ?string
    {
        // Try to load from embeddings file first
        $embPath = $kbPath . '.embeddings.json';
        if (is_file($embPath)) {
            $data = json_decode(file_get_contents($embPath), true);
            if ($data && isset($data['pairs'])) {
                $content = [];
                foreach ($data['pairs'] as $pair) {
                    $q = $pair['q'] ?? $pair['question'] ?? '';
                    $a = $pair['a'] ?? $pair['answer'] ?? '';
                    if ($q && $a) {
                        $content[] = "Q: $q\nA: $a\n";
                    }
                }
                return implode("\n", $content);
            }
        }
        
        // Try to load from text file
        if (is_file($kbPath)) {
            return file_get_contents($kbPath);
        }
        
        return null;
    }
    
    private function ensureAnswerInUserLanguage(string $answer, string $userLang, string $apiKey): string
    {
        // If user is French and answer seems to be in English, translate it
        if ($userLang === 'fr') {
            $answerLang = $this->detectLanguage($answer);
            if ($answerLang === 'en') {
                return $this->translate($answer, 'fr', 'en', 'faq');
            }
        }
        
        return $answer;
    }

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
        return preg_match('/\b(price|prix|cost|combien|coute|coûte|stock|available|disponible|inventaire|quantité)\b/u', $s);
    }
    
    private function handleDirectProductQuery(string $text, string $lang): ?string
    {
        $idLang = (int)Context::getContext()->language->id;
        $tokens = $this->tok($text);
        if (!$tokens) return null;
        
        // Detect what type of query this is
        $s = mb_strtolower($text, 'UTF-8');
        $isPriceQuery = preg_match('/\b(price|prix|cost|combien|coute|coûte|how much|quel prix|tarif|montant|cout|coût)\b/u', $s);
        $isStockQuery = preg_match('/\b(stock|available|disponible|inventory|inventaire|quantité|reste|restant|en stock|avez-vous)\b/u', $s);
        
        // Remove price/stock keywords to get product terms
        $productTokens = array_filter($tokens, function($token) {
            return !preg_match('/^(price|prix|cost|combien|coute|coûte|stock|available|disponible|inventory|inventaire|how|much|quel|tarif|montant|cout|coût|quantité|reste|restant|en|avez-vous)$/u', $token);
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
                if ($lang === 'fr') {
                    $stockText = $stockQty > 0 ? 'Stock : '.$stockQty : 'Rupture de stock';
                } else {
                    $stockText = $stockQty > 0 ? 'Stock: '.$stockQty : 'Out of stock';
                }

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
                if ($lang === 'fr') {
                    $stockText = $stockQty > 0 ? 'Stock : '.$stockQty : 'Rupture de stock';
                } else {
                    $stockText = $stockQty > 0 ? 'Stock: '.$stockQty : 'Out of stock';
                }
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
        
        // More specific headers based on query type with language support
        if ($isPriceQuery && !$isStockQuery) {
            $header = $lang === 'fr' ? "Prix :\n" : "Prices:\n";
        } elseif ($isStockQuery && !$isPriceQuery) {
            $header = $lang === 'fr' ? "Quantité disponible :\n" : "Stock levels:\n";
        } else {
            $header = $lang === 'fr' ? "Produits trouvés :\n" : "Products found:\n";
        }
        
        return $header . implode("\n", $results);
    }

    /* ==================== UNIFIED TRANSLATION SERVICE ==================== */
    
    private static $translationCache = [];
    private static $translationStats = ['hits' => 0, 'misses' => 0, 'errors' => 0];
    
    /**
     * Unified AI translation system - intelligent and context-aware
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
        
        // Check cache first
        $cacheKey = $this->getTranslationCacheKey($text, $sourceLang, $targetLang, $context);
        if (isset(self::$translationCache[$cacheKey])) {
            self::$translationStats['hits']++;
            return self::$translationCache[$cacheKey];
        }
        
        // Try AI translation first, fallback to Google Translate
        $translated = $this->tryAITranslation($text, $sourceLang, $targetLang, $context);
        if (!$translated) {
            $translated = $this->tryGoogleTranslation($text, $sourceLang, $targetLang, $context);
        }
        
        // Cache and return
        self::$translationCache[$cacheKey] = $translated;
        self::$translationStats['misses']++;
        
        return $translated;
    }
    
    private function tryAITranslation(string $text, string $from, string $to, string $context): ?string
    {
        try {
            $apiKey = Configuration::get('AI_CHATBOT_API_KEY');
            if (!$apiKey || strlen($text) > 500) return null; // Use AI only for shorter texts
            
            $client = new Client();
            $prompt = "Translate this $context text from $from to $to. Return ONLY the translation, no explanations:\n\n$text";
            
            $response = $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'model' => self::CHAT_MODEL,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'max_tokens' => 200,
                    'temperature' => 0.1
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            $translated = trim($data['choices'][0]['message']['content'] ?? '');
            
            if ($translated && strlen($translated) > 0) {
                $this->log('AI TRANSLATION SUCCESS: ' . substr($translated, 0, 50));
                return $translated;
            }
            
        } catch (\Throwable $e) {
            $this->log('AI TRANSLATION ERROR: ' . $e->getMessage());
        }
        
        return null;
    }
    
    private function tryGoogleTranslation(string $text, string $from, string $to, string $context): string
    {
        try {
            $translator = new GoogleTranslate($to, $from);
            $optimizedText = $this->optimizeTextForTranslation($text, $context);
            $translated = $translator->translate($optimizedText);
            return $this->postProcessTranslation($translated, $context, $to);
        } catch (\Throwable $e) {
            $this->log('GOOGLE TRANSLATION ERROR: ' . $e->getMessage());
            return $text;
        }
    }
    
    /**
     * Smart auto-response translation based on user's detected language
     * This is the main method called throughout the bot
     * Ensures consistent language throughout the conversation
     */
    private function autoTranslateResponse(string $response, string $userLang, string $context = 'general'): string
    {
        // Get the conversation language from session or initialize it if not set
        // This is critical for maintaining language consistency throughout the conversation
        if (!isset($_SESSION['conversation_language'])) {
            // For new conversations, set the language based on the first user message
            $_SESSION['conversation_language'] = $userLang;
            $this->log("AUTO TRANSLATE: Setting initial conversation language to {$userLang}");
        }
        
        // Always use the established conversation language to maintain consistency
        $targetLang = $_SESSION['conversation_language'];
        
        // Use advanced language detection for more accuracy
        $responseLanguage = $this->detectLanguageAdvanced($response);
        $this->log("AUTO TRANSLATE: Input response language detected as: {$responseLanguage}, target language: {$targetLang}");
        
        // Skip translation if response shouldn't be translated
        if (!$this->shouldTranslate($response)) {
            $this->log('AUTO TRANSLATE: Skipping translation (short/numeric/email content)');
            return $response;
        }
        
        // If languages don't match, translate to maintain consistency
        if ($responseLanguage !== $targetLang) {
            $this->log("AUTO TRANSLATE: Translating from {$responseLanguage} to {$targetLang}");
            return $this->translate($response, $targetLang, $responseLanguage, $context);
        }
        
        $this->log("AUTO TRANSLATE: Response already in {$targetLang}, no translation needed");
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
        // If text is too short, default to English to avoid misdetection
        if (mb_strlen(trim($text), 'UTF-8') < 3) {
            $this->log("LANG DETECT: Text too short, defaulting to English");
            return 'en';
        }
        
        $originalText = $text;
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
            '/\b(mais|donc|puis|alors|ensuite|cependant|néanmoins)\b/u',
            // Additional French indicators
            '/\b(est|sont|c\'est|ce|cette|ces|cet|de|du|des|la|le|les|un|une)\b/u'
        ];
        
        $frenchScore = 0;
        foreach ($strongFrench as $pattern) {
            if (preg_match($pattern, $text)) {
                $frenchScore += 2; // Higher weight
            }
        }
        
        // Check for French accented characters (very strong indicator)
        if (preg_match('/[àâäéèêëîïôöùûüç]/u', $originalText)) {
            $frenchScore += 4; // Very strong indicator
        }
        
        // French greetings and polite words
        if (preg_match('/\b(bonjour|salut|bonsoir|merci|pardon|excusez|désolé|s\'il|sil)\b/u', $text)) {
            $frenchScore += 3;
        }
        
        // French-specific terms and common words
        if (preg_match('/\b(commande|livraison|retour|prix|produit|article|achat|couleur|taille|marque)\b/u', $text)) {
            $frenchScore += 2;
        }
        
        // French short phrases that are very specific
        if (preg_match('/\b(quoi|quoi\?|oui|non|peut-être|peut être|d\'accord|ok)\b/u', $text)) {
            $frenchScore += 1;
        }
        
        // French question about products (specific to the error in the image)
        if (preg_match('/\b(avez-vous des|avez vous des)\b/u', $text)) {
            $frenchScore += 5; // Very strong indicator for French
        }
        
        // English indicators (for comparison)
        $strongEnglish = [
            '/\b(the|and|or|but|with|from|have|has|will|would|could|should|this|that|these|those)\b/u',
            '/\b(order|delivery|return|price|product|item|purchase|tracking|hello|hi|yes|no|thanks|thank)\b/u',
            '/\b(what|where|when|how|why|who|which|can|do|does|is|are|was|were)\b/u'
        ];
        
        $englishScore = 0;
        foreach ($strongEnglish as $pattern) {
            if (preg_match($pattern, $text)) {
                $englishScore += 2;
            }
        }
        
        $this->log("LANG DETECT: FR={$frenchScore}, EN={$englishScore} for: \"" . substr($text, 0, 50) . '..."');
        
        // Increase threshold for French detection to avoid false positives
        // Only detect as French if the score is significantly higher than English
        return ($frenchScore > $englishScore && $frenchScore > 0) ? 'fr' : 'en';
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
     * Enhanced to better handle special content and improve translation decisions
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
        
        // Skip product codes, reference numbers, and order IDs
        if (preg_match('/^[A-Z0-9\-\_]{5,15}$/', $text)) {
            return false;
        }
        
        // Skip markdown code blocks
        if (preg_match('/^```[\s\S]*```$/', $text)) {
            return false;
        }
        
        // Skip content with HTML tags (likely formatted content)
        if (preg_match('/<[^>]+>/', $text)) {
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
    
    /* ====================== COUPON HANDLING ====================== */
    
    /**
     * Handle coupon-specific API requests
     */
    private function handleCouponRequest()
    {
        try {
            $payload = json_decode(file_get_contents('php://input'), true) ?: [];
            $action = $payload['action'] ?? '';
            
            switch ($action) {
                case 'check_popup':
                    $this->handleCheckPopup();
                    break;
                    
                case 'record_popup_shown':
                    $this->handleRecordPopupShown();
                    break;
                    
                case 'generate_coupon':
                    $this->handleGenerateCoupon();
                    break;
                    
                default:
                    $this->out(['error' => 'Invalid coupon action'], 400);
                    break;
            }
        } catch (\Throwable $e) {
            $this->log('COUPON API ERROR: ' . $e->getMessage());
            $this->out(['error' => 'Coupon service unavailable'], 500);
        }
    }
    
    /**
     * Check if popup should be shown and return coupon data
     */
    private function handleCheckPopup()
    {
        try {
            $module = Module::getInstanceByName('ai_chatbot');
            if (!$module || !$module->shouldShowPopup()) {
                $this->out(['show_popup' => false]);
                return;
            }
            
            // Generate coupon
            $couponData = $module->generateCoupon();
            if (!$couponData) {
                $this->out(['show_popup' => false]);
                return;
            }
            
            $this->out([
                'show_popup' => true,
                'coupon' => $couponData,
                'delay' => (int)Configuration::get('AI_CHATBOT_POPUP_DELAY')
            ]);
            
        } catch (\Throwable $e) {
            $this->log('POPUP CHECK ERROR: ' . $e->getMessage());
            $this->out(['show_popup' => false]);
        }
    }
    
    /**
     * Record that popup was shown to user
     */
    private function handleRecordPopupShown()
    {
        try {
            $module = Module::getInstanceByName('ai_chatbot');
            if ($module) {
                $module->recordPopupShown();
            }
            $this->out(['success' => true]);
        } catch (\Throwable $e) {
            $this->log('RECORD POPUP ERROR: ' . $e->getMessage());
            $this->out(['success' => false]);
        }
    }
    
    /**
     * Generate a new coupon (for manual requests)
     */
    private function handleGenerateCoupon()
    {
        try {
            $module = Module::getInstanceByName('ai_chatbot');
            if (!$module) {
                $this->out(['error' => 'Module not found'], 404);
                return;
            }
            
            $couponData = $module->generateCoupon();
            if (!$couponData) {
                $this->out(['error' => 'Could not generate coupon'], 500);
                return;
            }
            
            $this->out([
                'success' => true,
                'coupon' => $couponData
            ]);
            
        } catch (\Throwable $e) {
            $this->log('COUPON GENERATION ERROR: ' . $e->getMessage());
            $this->out(['error' => 'Coupon generation failed'], 500);
        }
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
        
        // First, check if this is NOT a specific intent query
        $specificIntentPatterns = [
            // Order status patterns
            '/\b(order|commande|track|suivi|status|statut|where is|où est)\b/iu',
            // Product search patterns
            '/\b(show me|find|search|cherche|trouve|looking for|looking|need|want|buy|acheter)\b/iu',
            // Category patterns
            '/\b(shoes|clothing|electronics|chaussures|vêtements|électronique)\b/iu',
            // Brand patterns
            '/\b(nike|adidas|brand|marque)\b/iu',
            // Color/size patterns
            '/\b(red|blue|green|black|white|rouge|bleu|vert|noir|blanc|size|taille)\b/iu'
        ];
        
        foreach ($specificIntentPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return false; // This is a specific intent, not a general question
            }
        }
        
        // Question word patterns (start of sentence or after punctuation)
        $questionWords = [
            'en' => [
                'how', 'what', 'why', 'when', 'where', 'which', 'who',
                'can i', 'could i', 'should i', 'would i', 'may i', 'might i',
                'is it', 'are there', 'do you', 'does it', 'will you',
                'am i able', 'is there a way', 'is it possible'
            ],
            'fr' => [
                'comment', 'que', 'quoi', 'pourquoi', 'quand', 'où', 'quel', 'quelle', 'qui',
                'puis-je', 'peux-je', 'est-ce que je peux', 'est-ce que je dois',
                'y a-t-il', 'est-il possible', 'peut-on', 'doit-on',
                'suis-je capable', 'existe-t-il un moyen'
            ]
        ];
        
        $allQuestionWords = array_merge($questionWords['en'], $questionWords['fr']);
        
        // Check if starts with question words
        foreach ($allQuestionWords as $qword) {
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
            '/\b(comment faire|comment puis|que faire si)\b/iu'
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
        // Action request patterns
        $actionPatterns = [
            '/\b(check|track|find|show|get|give me)\s+(my|the)\s+(order|commande)\b/iu',
            '/\b(order|commande)\s*(status|statut|état)\b/iu',
            '/\b(my\s+order|ma\s+commande)\s*(#|number|numéro|reference|référence)/iu',
            '/\b(track|suivre)\s*(order|commande|package|colis)/iu',
            '/\b(where is|où est)\s*(my|ma)\s*(order|commande)/iu'
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
    
    /* ==================== COUPON CONVERSATION HANDLING ==================== */
    
    /* ==================== PROMOTION / SALE HANDLING ==================== */
    
    private function handlePromotionQuery(string $message, string $lang): ?string
    {
        $this->log('PROMOTION QUERY: Processing message in language: ' . $lang . ' - "' . substr($message, 0, 100) . '..."');
        
        // Fetch products on promotion (reduction) using PrestaShop classes
        try {
            $ctx = Context::getContext();
            $idLang = (int)$ctx->language->id;
            $idShop = (int)$ctx->shop->id;
            
            // Enhanced query to get more information about promoted products
            $sql = 'SELECT DISTINCT p.id_product, pl.name, sp.reduction, sp.reduction_type, p.price, p.id_manufacturer
                    FROM '._DB_PREFIX_.'product p
                    INNER JOIN '._DB_PREFIX_.'product_lang pl ON (pl.id_product = p.id_product AND pl.id_lang = '.(int)$idLang.')
                    INNER JOIN '._DB_PREFIX_.'specific_price sp ON sp.id_product = p.id_product
                    WHERE p.active = 1
                      AND (sp.reduction > 0)
                      AND (sp.from = "0000-00-00 00:00:00" OR sp.from <= NOW())
                      AND (sp.to = "0000-00-00 00:00:00" OR sp.to >= NOW())
                    ORDER BY sp.reduction DESC, p.date_upd DESC
                    LIMIT 15';
            
            $this->log('PROMOTION QUERY: Executing SQL query');
            $rows = Db::getInstance()->executeS($sql) ?: [];
            $this->log('PROMOTION QUERY: Found ' . count($rows) . ' promoted products');
            
            if (!$rows) {
                // Return localized "no promotions" message
                return $this->getPromotionMessage('no_promotions', $lang);
            }
            
            $currency = new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
            $productList = [];
            $processedCount = 0;
            
            foreach ($rows as $r) {
                try {
                    $productId = (int)$r['id_product'];
                    $name = trim($r['name']);
                    if (!$name) continue;
                    
                    // Get actual price with discount
                    $originalPrice = Product::getPriceStatic($productId, true, null, 6, null, false, false, 1);
                    $discountedPrice = Product::getPriceStatic($productId, true, null, 6, null, false, true, 1);
                    
                    // Get stock information
                    $stock = StockAvailable::getQuantityAvailableByProduct($productId);
                    $stockText = $stock > 0 ? ($stock . ' in stock') : 'Out of stock';
                    if ($lang === 'fr') {
                        $stockText = $stock > 0 ? ($stock . ' en stock') : 'Rupture de stock';
                    }
                    
                    // Format discount info
                    $discountText = '';
                    $savingsAmount = $originalPrice - $discountedPrice;
                    
                    if ($r['reduction_type'] == 'percentage' && $r['reduction'] > 0) {
                        $discountPercent = (int)($r['reduction'] * 100);
                        $discountText = $lang === 'fr' ? 
                            "(-{$discountPercent}%)" : 
                            "(-{$discountPercent}%)";
                    } elseif ($r['reduction_type'] == 'amount' && $r['reduction'] > 0) {
                        $discountAmount = Tools::displayPrice($r['reduction'], $currency);
                        $discountText = $lang === 'fr' ? 
                            "(-{$discountAmount})" : 
                            "(-{$discountAmount})";
                    }
                    
                    // Create product URL
                    $productUrl = $ctx->link->getProductLink($productId, null, null, null, $idLang);
                    
                    // Format original and discounted prices
                    $originalPriceFormatted = Tools::displayPrice($originalPrice, $currency);
                    $discountedPriceFormatted = Tools::displayPrice($discountedPrice, $currency);
                    
                    // Create product entry with comprehensive info
                    if ($savingsAmount > 0.01) { // Only show if there's a meaningful discount
                        $productList[] = [
                            'name' => $name,
                            'original_price' => $originalPriceFormatted,
                            'discounted_price' => $discountedPriceFormatted,
                            'discount_text' => $discountText,
                            'stock_text' => $stockText,
                            'url' => $productUrl,
                            'in_stock' => $stock > 0
                        ];
                        $processedCount++;
                        
                        if ($processedCount >= 10) break; // Limit to 10 products for readability
                    }
                    
                } catch (\Throwable $e) {
                    $this->log('PROMOTION PRODUCT ERR: ' . $e->getMessage());
                    continue; // Skip this product and continue
                }
            }
            
            if (empty($productList)) {
                return $this->getPromotionMessage('no_valid_promotions', $lang);
            }
            
            // Format the response
            return $this->formatPromotionResponse($productList, $lang);
            
        } catch (\Throwable $e) {
            $this->log('PROMOTION QUERY ERR: ' . $e->getMessage());
            return $this->getPromotionMessage('error', $lang);
        }
    }

    /**
     * Get localized promotion messages
     */
    private function getPromotionMessage(string $type, string $lang): string
    {
        $messages = [
            'fr' => [
                'no_promotions' => "Pour le moment, il n'y a pas de produits en promotion. 😊 Revenez bientôt pour découvrir nos nouvelles offres !",
                'no_valid_promotions' => "Des promotions sont en cours, mais je n'ai pas pu lister les produits. Veuillez consulter la page des promotions.",
                'error' => "Désolé, je n'ai pas pu récupérer les promotions pour le moment. Veuillez réessayer dans quelques instants.",
                'header' => "🎉 Voici les produits actuellement en promotion :"
            ],
            'en' => [
                'no_promotions' => "There are no products on promotion right now. 😊 Check back soon for new deals!",
                'no_valid_promotions' => "There are active promotions, but I couldn't list the products. Please check the promotions page.",
                'error' => "Sorry, I couldn't retrieve promotions right now. Please try again in a few moments.",
                'header' => "🎉 Here are the products currently on sale:"
            ]
        ];
        
        $langMessages = $messages[$lang] ?? $messages['en'];
        return $langMessages[$type] ?? $langMessages['error'];
    }
    
    /**
     * Format the promotion response with product list
     */
    private function formatPromotionResponse(array $productList, string $lang): string
    {
        if (empty($productList)) {
            return $this->getPromotionMessage('no_valid_promotions', $lang);
        }
        
        $header = $this->getPromotionMessage('header', $lang);
        $response = $header . "\n\n";
        
        foreach ($productList as $product) {
            // Format each product entry
            if ($lang === 'fr') {
                $line = "• <a href=\"{$product['url']}\" target=\"_blank\">{$product['name']}</a>\n";
                $line .= "  Prix: ~~{$product['original_price']}~~ **{$product['discounted_price']}** {$product['discount_text']}\n";
                $line .= "  Stock: {$product['stock_text']}\n";
            } else {
                $line = "• <a href=\"{$product['url']}\" target=\"_blank\">{$product['name']}</a>\n";
                $line .= "  Price: ~~{$product['original_price']}~~ **{$product['discounted_price']}** {$product['discount_text']}\n";
                $line .= "  Stock: {$product['stock_text']}\n";
            }
            $response .= $line . "\n";
        }
        
        // Add helpful footer
        if ($lang === 'fr') {
            $response .= "\n💡 Cliquez sur un produit pour voir plus de détails et l'ajouter à votre panier !";
        } else {
            $response .= "\n💡 Click on any product to see more details and add it to your cart!";
        }
        
        return trim($response);
    }

    /* ==================== COUPON CONVERSATION HANDLING ==================== */
    
    /**
     * Handle coupon-related queries in conversation
     */
    private function handleCouponQuery(string $message, string $lang): ?string
    {
        $lowerMsg = mb_strtolower($message, 'UTF-8');
        
        // First, check if this is a product discount/promotion query (should NOT be handled by coupon handler)
        $productDiscountPatterns = [
            '/\b(products?|items?|articles?)\s+(on\s+)?(sale|discount|promotion|promo)/i',
            '/\b(any|some|which|what)\s+(products?|items?)\s.*(discount|sale|promo)/i',
            '/\b(is there|are there|do you have)\s.*(products?|items?).*on\s+(sale|discount)/i',
            '/\b(produits?|articles?)\s+(en\s+)?(promotion|promo|soldes)/i',
            '/\b(y a-t-il|avez-vous)\s.*(produits?|articles?).*en\s+(promotion|soldes)/i',
            '/\b(des|quelques|quels?)\s+(produits?|articles?)\s.*(promotion|soldes|promo)/i'
        ];
        
        // If this looks like a product discount query, let the promotion handler deal with it
        foreach ($productDiscountPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                $this->log('COUPON HANDLER: Detected product discount query, skipping coupon handler');
                return null; // Let promotion handler take care of it
            }
        }
        
        // Now check for actual coupon/code requests
        $couponSpecificPatterns = [
            '/\b(is\s+there\s+any\s+coupon|any\s+coupon|coupon\s+available)\b/i',
            '/\b(do\s+you\s+have\s+.*coupon|have\s+.*coupon|got\s+.*coupon)\b/i',
            '/\b(avez-vous\s+.*coupon|y\s*a-t-il\s+.*coupon|il\s+y\s+a\s+.*coupon)\b/i',
            '/\b(promo\s+code|discount\s+code|coupon\s+code)\b/i',
            '/\b(give\s+me\s+.*coupon|show\s+me\s+.*coupon)\b/i',
            '/\b(voucher|bon\s+de\s+réduction)\b/i',
            '/\b(any\s+coupon\s*\?|coupon\s*\?|des\s+coupon|un\s+coupon)\b/i'
        ];
        
        $isCouponQuery = false;
        
        // Check for coupon-specific patterns first (more specific)
        foreach ($couponSpecificPatterns as $pattern) {
            if (preg_match($pattern, $lowerMsg)) {
                $this->log('COUPON DETECTION: Matched pattern: ' . $pattern);
                $isCouponQuery = true;
                break;
            }
        }
        
        // Check for direct coupon keywords
        if (!$isCouponQuery) {
            $couponKeywords = ['coupon', 'voucher', 'bon de réduction', 'code promo', 'code remise'];
            foreach ($couponKeywords as $keyword) {
                if (strpos($lowerMsg, $keyword) !== false) {
                    $this->log('COUPON DETECTION: Matched keyword: ' . $keyword);
                    $isCouponQuery = true;
                    break;
                }
            }
        }
        
        if (!$isCouponQuery) {
            return null;
        }
        
        $this->log('COUPON HANDLER: Detected coupon request: "' . substr($message, 0, 100) . '..."');
        
        try {
            // First, check for existing available coupons
            $availableCoupons = $this->getAvailableCartRuleCoupons($lang);
            if ($availableCoupons) {
                return $availableCoupons;
            }
            
            // Check if coupons generation is enabled
            if (!Configuration::get('AI_CHATBOT_COUPON_ENABLED')) {
                return $lang === 'fr' ? 
                    "Désolé, nous n'avons pas d'offres promotionnelles disponibles pour le moment." :
                    "Sorry, we don't have any promotional offers available at the moment.";
            }
            
            $module = Module::getInstanceByName('ai_chatbot');
            if (!$module) {
                return null;
            }
            
            // Check if user is asking about a specific coupon code they already have
            if (preg_match('/\b(AICHAT[A-Z0-9]{8})\b/', strtoupper($message), $matches)) {
                return $this->handleSpecificCouponQuery($matches[1], $lang);
            }
            
            // Generate a new coupon for the user
            $couponData = $module->generateCoupon();
            if (!$couponData) {
                return $lang === 'fr' ?
                    "Désolé, je ne peux pas générer de code promo pour le moment." :
                    "Sorry, I can't generate a promo code right now.";
            }
            
            // Format the response
            $discountText = $couponData['type'] === 'percentage' ? 
                $couponData['amount'] . '%' : 
                $couponData['amount'] . '€';
                
            $expiresText = date('M j, Y', strtotime($couponData['expires']));
            
            if ($lang === 'fr') {
                $response = "🎉 Voici votre code promo exclusif:\n\n";
                $response .= "**{$couponData['code']}**\n\n";
                $response .= "• Remise: {$discountText}\n";
                $response .= "• Expire le: {$expiresText}\n";
                
                if ($couponData['min_cart'] > 0) {
                    $response .= "• Montant minimum: {$couponData['min_cart']}€\n";
                }
                
                $response .= "\nUtilisez ce code lors de votre commande pour bénéficier de la réduction!";
            } else {
                $response = "🎉 Here's your exclusive promo code:\n\n";
                $response .= "**{$couponData['code']}**\n\n";
                $response .= "• Discount: {$discountText}\n";
                $response .= "• Expires: {$expiresText}\n";
                
                if ($couponData['min_cart'] > 0) {
                    $response .= "• Minimum order: €{$couponData['min_cart']}\n";
                }
                
                $response .= "\nUse this code at checkout to get your discount!";
            }
            
            return $response;
            
        } catch (\Throwable $e) {
            $this->log('COUPON CONVERSATION ERROR: ' . $e->getMessage());
            return $lang === 'fr' ?
                "Désolé, une erreur s'est produite lors de la génération du code promo." :
                "Sorry, there was an error generating your promo code.";
        }
    }
    
    /**
     * Handle queries about specific coupon codes
     */
    private function handleSpecificCouponQuery(string $couponCode, string $lang): string
    {
        try {
            // Check if coupon exists and get its details
            $sql = 'SELECT cr.*, crl.name 
                    FROM ' . _DB_PREFIX_ . 'cart_rule cr 
                    LEFT JOIN ' . _DB_PREFIX_ . 'cart_rule_lang crl ON (cr.id_cart_rule = crl.id_cart_rule AND crl.id_lang = ' . (int)Context::getContext()->language->id . ')
                    WHERE cr.code = "' . pSQL($couponCode) . '" AND cr.active = 1';
            
            $coupon = Db::getInstance()->getRow($sql);
            
            if (!$coupon) {
                return $lang === 'fr' ?
                    "Ce code promo n'existe pas ou a expiré." :
                    "This promo code doesn't exist or has expired.";
            }
            
            // Check if still valid
            $now = date('Y-m-d H:i:s');
            if ($coupon['date_to'] < $now) {
                return $lang === 'fr' ?
                    "Ce code promo a expiré le " . date('d/m/Y', strtotime($coupon['date_to'])) . "." :
                    "This promo code expired on " . date('M j, Y', strtotime($coupon['date_to'])) . ".";
            }
            
            // Format discount info
            $discountText = '';
            if ($coupon['reduction_percent'] > 0) {
                $discountText = $coupon['reduction_percent'] . '% off';
            } elseif ($coupon['reduction_amount'] > 0) {
                $discountText = '€' . $coupon['reduction_amount'] . ' off';
            }
            
            $expiresText = date('M j, Y', strtotime($coupon['date_to']));
            
            if ($lang === 'fr') {
                $response = "✅ Code promo valide: **{$couponCode}**\n\n";
                $response .= "• Remise: {$discountText}\n";
                $response .= "• Expire le: " . date('d/m/Y', strtotime($coupon['date_to'])) . "\n";
                
                if ($coupon['minimum_amount'] > 0) {
                    $response .= "• Montant minimum: €{$coupon['minimum_amount']}\n";
                }
                
                $response .= "\nVous pouvez utiliser ce code lors de votre commande!";
            } else {
                $response = "✅ Valid promo code: **{$couponCode}**\n\n";
                $response .= "• Discount: {$discountText}\n";
                $response .= "• Expires: {$expiresText}\n";
                
                if ($coupon['minimum_amount'] > 0) {
                    $response .= "• Minimum order: €{$coupon['minimum_amount']}\n";
                }
                
                $response .= "\nYou can use this code at checkout!";
            }
            
            return $response;
            
        } catch (\Throwable $e) {
            $this->log('SPECIFIC COUPON QUERY ERROR: ' . $e->getMessage());
            return $lang === 'fr' ?
                "Désolé, je ne peux pas vérifier ce code promo pour le moment." :
                "Sorry, I can't verify this promo code right now.";
        }
    }
    
    /* ==================== utilities ==================== */
    
    private function getLanguageSpecificFaqFile(string $lang): string
    {
        $baseFaqFile = (string) Configuration::get('AI_CHATBOT_KB_FILE');
        $uploadsDir = _PS_MODULE_DIR_ . 'ai_chatbot/uploads/';
        
        // Try to use language-specific FAQ file
        if ($lang === 'fr') {
            // Try multiple possible French FAQ file names
            $possibleFrenchFiles = [
                $uploadsDir . 'faq_fr.txt',
                $uploadsDir . 'kb_fr.txt',
                $uploadsDir . 'knowledge_base_fr.txt',
                dirname($baseFaqFile) . '/faq_fr.txt',
                dirname($baseFaqFile) . '/kb_fr.txt'
            ];
            
            foreach ($possibleFrenchFiles as $frenchFile) {
                if (is_file($frenchFile)) {
                    $this->log('DEBUG: Using French FAQ file: ' . $frenchFile);
                    return $frenchFile;
                }
            }
            
            $this->log('DEBUG: No French FAQ file found, will translate responses from default file');
        }
        
        // Check if base file exists
        if (!is_file($baseFaqFile)) {
            $this->log('WARNING: Default FAQ file not found: ' . $baseFaqFile);
        } else {
            $this->log('DEBUG: Using default FAQ file: ' . $baseFaqFile);
        }
        
        return $baseFaqFile;
    }

    /**
     * Enhanced AI-powered intent detection with context awareness
     * Now with improved context tracking and session storage
     */
    private function detectIntentWithAI(string $message, string $lang, string $apiKey): string
    {
        $this->log('AI INTENT DETECTION: Analyzing message: "' . $message . '" in ' . $lang);
        
        // Check for conversation context to improve intent detection
        $previousIntent = $_SESSION['detected_intent'] ?? null;
        $conversationSummary = $_SESSION['conversation_summary'] ?? null;
        $this->log('AI INTENT DETECTION: Previous intent: ' . ($previousIntent ?? 'none'));
        
        // Ensure we have a valid language code before proceeding
        if (empty($lang) || !in_array($lang, ['en', 'fr'])) {
            $this->log('AI INTENT DETECTION: Invalid language code "' . $lang . '", defaulting to "en"');
            $lang = 'en';
        }
        
        // Use AI for intelligent intent classification with context
        if ($apiKey) {
            $aiIntent = $this->getSmartAIIntent($message, $lang, $apiKey, $previousIntent, $conversationSummary);
            if ($aiIntent) {
                $this->log('AI INTENT DETECTION: Classified as ' . $aiIntent);
                // Store the detected intent in session for context tracking
                $_SESSION['detected_intent'] = $aiIntent;
                return $aiIntent;
            }
        }
        
        // Fallback to existing detection if AI fails
        $this->log('AI INTENT DETECTION: AI failed, using fallback');
        $fallbackIntent = $this->detectIntent($message, $lang);
        
        // Store the fallback intent in session for context tracking
        $_SESSION['detected_intent'] = $fallbackIntent;
        return $fallbackIntent;
    }
    
    /**
     * Enhanced AI-powered intent detection with context awareness
     * Now considers conversation history and previous intents
     */
    private function getSmartAIIntent(string $message, string $lang, string $apiKey, ?string $previousIntent = null, ?array $conversationSummary = null): ?string
    {
        try {
            // Build context-aware prompt
            $contextInfo = '';
            
            // Add previous intent if available
            if ($previousIntent) {
                $contextInfo .= "Previous intent: {$previousIntent}\n";
            }
            
            // Add conversation topics if available
            if ($conversationSummary && !empty($conversationSummary['topics'])) {
                $topics = implode(', ', $conversationSummary['topics']);
                $contextInfo .= "Conversation topics: {$topics}\n";
            }
            
            // Add message count if available
            if ($conversationSummary && isset($conversationSummary['message_count'])) {
                $contextInfo .= "Message count: {$conversationSummary['message_count']}\n";
            }
            
            // Enhanced prompt that understands promotion queries specifically and uses context
            // Include both French and English examples for better language handling
            $prompt = "You are an e-commerce chatbot intent classifier. Analyze this customer message and classify it into ONE of these intents:\n\n" .
                     "- smalltalk: greetings, thanks, casual conversation, goodbyes (e.g., 'hello', 'thank you', 'bonjour', 'merci')\n" .
                     "- order: SPECIFIC order status or tracking requests for a customer's own order (e.g., 'where is my order #12345', 'track my package', 'status of my recent order')\n" .
                     "- catalog: product search, prices, availability, categories, shopping (e.g., 'do you have shoes', 'price of shirt', 'avez-vous des chaussures', 'prix de chemise')\n" .
                     "- promotion: questions about products on sale, discounts, promotions (e.g., 'any discounts', 'sale items', 'des réductions', 'articles en promotion')\n" .
                     "- general: FAQ, policies, support questions, returns, shipping info, GENERAL questions about orders or shipping (e.g., 'return policy', 'shipping cost', 'how long does shipping take', 'do you ship internationally')\n\n" .
                     "IMPORTANT: Classify general questions about shipping, delivery times, or order policies as 'general', NOT as 'order'. The 'order' intent should ONLY be used when a customer is asking about a specific order they have placed.\n\n";
            
            // Add language-specific guidance
            if ($lang === 'fr') {
                $prompt .= "IMPORTANT: The message is in French. Pay special attention to French words and phrases.\n\n";
            }
            
            // Add context information if available
            if (!empty($contextInfo)) {
                $prompt .= "Context information:\n{$contextInfo}\n";
            }
            
            $prompt .= "Message: \"{$message}\"\n" .
                      "Language: {$lang}\n\n" .
                      "Respond with ONLY the intent name (smalltalk/order/catalog/promotion/general):";
            
            $this->log('AI INTENT PROMPT: ' . substr($prompt, 0, 200) . '...');
            
            $response = $this->http($apiKey)->post('https://api.openai.com/v1/chat/completions', [
                'json' => [
                    'model' => self::CHAT_MODEL,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'max_tokens' => 10,
                    'temperature' => 0.1
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            $intent = trim(strtolower($data['choices'][0]['message']['content'] ?? ''));
            
            return in_array($intent, ['smalltalk', 'order', 'catalog', 'promotion', 'general']) ? $intent : null;
            
        } catch (\Throwable $e) {
            $this->log('AI INTENT ERROR: ' . $e->getMessage());
            return null;
        }
    }

    private function detectIntent(string $message, string $lang): string
    {
        $lowerMsg = mb_strtolower($message, 'UTF-8');
        
        $this->log('INTENT DETECTION: Analyzing message: "' . $message . '"');
        
        // Check small talk first
        if ($this->isSmallTalk($lowerMsg, $lang)) {
            $this->log('INTENT DETECTION: Classified as smalltalk');
            return 'smalltalk';
        }
        
        // Check for specific order action requests first (highest priority for order intent)
        if ($this->isSpecificActionRequest($message)) {
            $this->log('INTENT DETECTION: Specific order action request detected -> order flow');
            return 'order';
        }
        
        // Check if message contains order-related keywords
        $containsOrderKeywords = $this->containsOrderKeywords($lowerMsg, $lang);
        
        // If message is a general question about orders without specific identifiers,
        // route to FAQ/general intent
        if ($containsOrderKeywords && $this->isGeneralQuestion($message) && !preg_match('/\b(#\d+|[A-Z]{2,}\d{2,}|\d{4,})\b/', $message)) {
            $this->log('INTENT DETECTION: General question about orders -> FAQ (general)');
            return 'general';
        }
        
        // Smart order intent detection (after specific checks)
        if ($this->isOrderQuery($lowerMsg, $lang)) {
            $this->log('INTENT DETECTION: Order query detected -> order flow');
            return 'order';
        }
        
        // Check promotion/sale queries
        if ($this->isPromotionQuery($lowerMsg, $lang)) {
            $this->log('INTENT DETECTION: Classified as promotion');
            return 'promotion';
        }
        
        // Check catalog/product queries
        if ($this->isCatalogQuery($lowerMsg, $lang)) {
            $this->log('INTENT DETECTION: Classified as catalog');
            return 'catalog';
        }
        
        // Default to general/FAQ
        $this->log('INTENT DETECTION: Classified as general/FAQ');
        return 'general';
    }
    
    private function isSmallTalk(string $text, string $lang): bool
    {
        // Define patterns directly to avoid method call issues
        $patterns = [
            'greeting' => [
                'patterns' => $lang === 'fr' 
                    ? ['salut', 'bonjour', 'coucou', 'hello', 'hi']
                    : ['hi', 'hello', 'hey', 'bonjour', 'salut']
            ],
            'thanks' => [
                'patterns' => $lang === 'fr'
                    ? ['merci', 'thank you', 'thanks']
                    : ['thanks', 'thank you', 'merci']
            ],
            'how_are_you' => [
                'patterns' => $lang === 'fr'
                    ? ['ça va', 'ca va', 'comment allez', 'how are']
                    : ['how are you', 'how are', 'ça va']
            ],
            'goodbye' => [
                'patterns' => $lang === 'fr'
                    ? ['au revoir', 'à bientôt', 'bye', 'goodbye', 'tchao']
                    : ['bye', 'goodbye', 'au revoir', 'see you']
            ],
            'confirmation' => [
                'patterns' => $lang === 'fr'
                    ? ['ok', 'cool', 'super', 'parfait', 'daccord', "d'accord"]
                    : ['ok', 'cool', 'great', 'awesome', 'perfect']
            ]
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
    
    /**
     * Helper function to check if text contains order-related keywords
     * This is used for basic keyword detection without full intent classification
     */
    private function containsOrderKeywords(string $text, string $lang): bool
    {
        $orderKeywords = [
            'en' => ['order', 'status', 'tracking', 'delivery', 'shipped', 'track'],
            'fr' => ['commande', 'statut', 'état', 'etat', 'suivi', 'livraison', 'expédié', 'suivre']
        ];
        
        $keywords = array_merge($orderKeywords['en'], $orderKeywords['fr']);
        
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function isOrderQuery(string $text, string $lang): bool
    {
        $orderKeywords = [
            'en' => ['order', 'status', 'tracking', 'delivery', 'shipped', 'track'],
            'fr' => ['commande', 'statut', 'état', 'etat', 'suivi', 'livraison', 'expédié', 'suivre']
        ];
        
        $keywords = array_merge($orderKeywords['en'], $orderKeywords['fr']);
        
        // First check if this is a specific order action request
        // This is a stronger signal than just keyword matching
        if ($this->isSpecificActionRequest($text)) {
            $this->log('ORDER QUERY: Detected specific order action request');
            return true;
        }
        
        // Check if this is a general FAQ about orders
        // If it's a general question about orders without specific identifiers,
        // it should be handled by the FAQ system, not the order flow
        if ($this->isGeneralQuestion($text)) {
            // Check if it contains order-related terms but no specific identifiers
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    // If it's a general question about orders without specific identifiers,
                    // don't classify as an order query - let it go to FAQ
                    if (!preg_match('/\b(#\d+|[A-Z]{2,}\d{2,}|\d{4,})\b/', $text)) {
                        $this->log('ORDER QUERY: Detected general question about orders - routing to FAQ');
                        return false;
                    }
                }
            }
        }
        
        // Standard keyword matching for other cases
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                // Additional context check - must mention order-related terms
                $contextKeywords = ['order', 'commande', 'number', 'numéro', 'reference', 'référence'];
                foreach ($contextKeywords as $context) {
                    if (strpos($text, $context) !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    private function isPromotionQuery(string $text, string $lang): bool
    {
        $promotionKeywords = [
            'en' => [
                'promotion', 'promo', 'sale', 'discount', 'on sale', 'deals', 
                'special offer', 'offers', 'reduced', 'cheap', 'bargain', 
                'clearance', 'markdown', 'any sales', 'discounted',
                // Question patterns
                'is there any product on promotion', 'are there any products on sale',
                'what items are on sale', 'which products are discounted',
                'any promotions', 'any deals', 'products on promotion',
                'items on sale', 'special pricing', 'price reduction'
            ],
            'fr' => [
                'promotion', 'promo', 'soldes', 'remise', 'réduction', 
                'en promo', 'offres', 'offre spéciale', 'bon marché', 
                'pas cher', 'démarquage', 'liquidation', 'des soldes', 'réduits',
                // Question patterns
                'y a-t-il des produits en promotion', 'des produits en soldes',
                'quels produits sont en promo', 'articles en promotion',
                'des promotions', 'des offres', 'produits soldés',
                'articles soldés', 'prix réduits', 'tarifs préférentiels'
            ]
        ];
        
        // Check for question patterns that indicate promotion queries
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
        
        // Check pattern matches first for better detection
        $patterns = array_merge($questionPatterns['en'], $questionPatterns['fr']);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        
        // Check individual keywords
        $allKeywords = array_merge($promotionKeywords['en'], $promotionKeywords['fr']);
        foreach ($allKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function isCatalogQuery(string $text, string $lang): bool
    {
        // Price/stock queries
        $priceStockKeywords = [
            'en' => ['price', 'cost', 'how much', 'stock', 'available', 'inventory'],
            'fr' => ['prix', 'coûte', 'coute', 'combien', 'stock', 'disponible', 'inventaire']
        ];
        
        $allPriceStock = array_merge($priceStockKeywords['en'], $priceStockKeywords['fr']);
        foreach ($allPriceStock as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        // Product search queries - dynamic detection based on common patterns
        $searchKeywords = [
            'en' => ['looking for', 'need', 'want to buy', 'show me', 'find', 'search', 'buy', 'purchase', 'price', 'cost', 'available', 'stock'],
            'fr' => ['cherche', 'recherche', 'veux', 'besoin', 'trouve', 'acheter', 'prix', 'coût', 'disponible', 'stock', 'achat']
        ];
        
        $allSearch = array_merge($searchKeywords['en'], $searchKeywords['fr']);
        foreach ($allSearch as $keyword) {
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
                'order_email_mismatch' => "Désolé — l'adresse e-mail que vous avez fournie ne correspond pas à votre compte. Veuillez utiliser l'adresse e-mail associée à votre compte.",
                'order_login_required' => "Pour vérifier le statut de votre commande, vous devez vous connecter à votre compte. Veuillez vous connecter et réessayer.",
                'order_need_id' => "Parfait, merci ! Maintenant votre **numéro de commande** (référence/ID).",
                'order_id_required' => "Veuillez partager votre **numéro de commande** (référence/ID).",
                'order_restart' => "Commençons à nouveau avec votre **adresse e-mail**."
            ],
            'en' => [
                'order_need_email' => "To check your order, please provide your **email address**.",
                'order_email_required' => "I'll need your **email** first to continue.",
                'order_security_error' => "Sorry — for security, I can only show status if you're signed in with **that same email**. Please sign in and try again.",
                'order_email_mismatch' => "Sorry — the email address you provided doesn't match your account. Please use the email address associated with your account.",
                'order_login_required' => "To check your order status, you need to be logged into your account. Please sign in and try again.",
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
            'filters_no_match'     => "Aucun produit ne correspond à vos spécifications. Veuillez essayer des critères différents pour la marque, la couleur ou la taille.",
            'results_header'       => "Résultats trouvés:"
        ];
        $en = [
            'category_not_found'   => "We don't sell that category. Try another category name.",
            'category_undetermined'=> "I couldn't determine the category. Let's start again.",
            'filters_no_match'     => "No products match your specifications. Please try different criteria for brand, color, or size.",
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
    
    private function getOrderStatus(array $orderInfo, string $email, string $lang): string
    {
        try {
            $orderId = null;
            
            // Find the order by reference or ID and validate email matches
            if ($orderInfo['reference']) {
                // First try customer table, then try address table as fallback
                $orderId = (int)$this->dbValue(
                    'SELECT o.id_order FROM ' . _DB_PREFIX_ . 'orders o 
                     INNER JOIN ' . _DB_PREFIX_ . 'customer c ON o.id_customer = c.id_customer
                     WHERE o.reference = "' . pSQL($orderInfo['reference']) . '" 
                     AND LOWER(c.email) = "' . pSQL(strtolower($email)) . '"'
                );
                
                // If not found, try with guest orders or address email
                if (!$orderId) {
                    $orderId = (int)$this->dbValue(
                        'SELECT DISTINCT o.id_order FROM ' . _DB_PREFIX_ . 'orders o 
                         LEFT JOIN ' . _DB_PREFIX_ . 'customer c ON o.id_customer = c.id_customer
                         LEFT JOIN ' . _DB_PREFIX_ . 'address a ON (o.id_address_invoice = a.id_address OR o.id_address_delivery = a.id_address)
                         WHERE o.reference = "' . pSQL($orderInfo['reference']) . '" 
                         AND (c.email IS NULL OR LOWER(c.email) = "' . pSQL(strtolower($email)) . '" OR LOWER(a.email) = "' . pSQL(strtolower($email)) . '")
                         LIMIT 1'
                    );
                }
            }
            
            if (!$orderId && $orderInfo['id']) {
                // First try customer table
                $orderId = (int)$this->dbValue(
                    'SELECT o.id_order FROM ' . _DB_PREFIX_ . 'orders o 
                     INNER JOIN ' . _DB_PREFIX_ . 'customer c ON o.id_customer = c.id_customer
                     WHERE o.id_order = ' . (int)$orderInfo['id'] . '
                     AND LOWER(c.email) = "' . pSQL(strtolower($email)) . '"'
                );
                
                // If not found, try with guest orders or address email
                if (!$orderId) {
                    $orderId = (int)$this->dbValue(
                        'SELECT DISTINCT o.id_order FROM ' . _DB_PREFIX_ . 'orders o 
                         LEFT JOIN ' . _DB_PREFIX_ . 'customer c ON o.id_customer = c.id_customer
                         LEFT JOIN ' . _DB_PREFIX_ . 'address a ON (o.id_address_invoice = a.id_address OR o.id_address_delivery = a.id_address)
                         WHERE o.id_order = ' . (int)$orderInfo['id'] . '
                         AND (c.email IS NULL OR LOWER(c.email) = "' . pSQL(strtolower($email)) . '" OR LOWER(a.email) = "' . pSQL(strtolower($email)) . '")
                         LIMIT 1'
                    );
                }
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
            return "Désolé, je n'ai pas trouvé de commande avec ce numéro pour cet email. Veuillez vérifier et renvoyer.";
        } else {
            return "Sorry, I couldn't find an order with that number for this email. Please double-check and resend.";
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
        
        // Try AI-enhanced smart catalog search first
        $apiKey = Configuration::get('AI_CHATBOT_API_KEY');
        if ($apiKey) {
            $smartResult = $this->handleSmartCatalogQuery($message, $lang, $apiKey);
            if ($smartResult) {
                return $smartResult;
            }
        }
        
        // Fallback to original category confirmation flow
        return $this->confirmProductCategory($message, $lang);
    }
    
    private function handleSmartCatalogQuery(string $message, string $lang, string $apiKey): ?string
    {
        $this->log('SMART CATALOG: Processing "'.$message.'" in '.$lang);
        
        // Check if this is a direct price/stock query first
        if ($this->isPriceStockQuery($message)) {
            $result = $this->handleDirectProductQuery($message, $lang);
            if ($result) return $result;
        }
        
        // Use AI to extract search terms and find categories
        if ($apiKey) {
            try {
                // AI extracts what the user wants in English
                $searchTerms = $this->extractCategoryWithAI($message, $lang, $apiKey);
                if ($searchTerms) {
                    $this->log('SMART CATALOG: AI extracted: '.json_encode($searchTerms));
                    
                    // Search for categories using AI terms
                    $categories = $this->searchCategoriesWithTerms($searchTerms, $lang);
                    if ($categories) {
                        return $this->handleFoundCategories($categories, $searchTerms, $lang);
                    }
                }
            } catch (\Throwable $e) {
                $this->log('SMART CATALOG AI ERR: '.$e->getMessage());
            }
        }
        
        // Fallback: Try token-based search
        return $this->handleCatalogFallback($message, $lang);
    }
    
    private function extractCategoryWithAI(string $message, string $lang, string $apiKey): ?array
    {
        try {
            // Create language-specific examples and instructions
            $frenchExamples = "";
            $englishExamples = "";
            
            if ($lang === 'fr') {
                $frenchExamples = "Exemples en français:\n" .
                    "- 'chaussures' -> {\"category\": \"shoes\", \"filters\": []}\n" .
                    "- 'vêtements' -> {\"category\": \"clothing\", \"filters\": []}\n" .
                    "- 'robe rouge taille 40' -> {\"category\": \"dresses\", \"filters\": [\"red\", \"40\"]}\n" .
                    "- 'avez-vous des chaussures' -> {\"category\": \"shoes\", \"filters\": []}\n" .
                    "- 'je cherche des vêtements' -> {\"category\": \"clothing\", \"filters\": []}\n" .
                    "- 't-shirt noir' -> {\"category\": \"t-shirts\", \"filters\": [\"black\"]}\n";
            } else {
                $englishExamples = "English examples:\n" .
                    "- 'shoes' -> {\"category\": \"shoes\", \"filters\": []}\n" .
                    "- 'clothing' -> {\"category\": \"clothing\", \"filters\": []}\n" .
                    "- 'red dress size 40' -> {\"category\": \"dresses\", \"filters\": [\"red\", \"40\"]}\n" .
                    "- 'do you have shoes' -> {\"category\": \"shoes\", \"filters\": []}\n" .
                    "- 'I'm looking for clothing' -> {\"category\": \"clothing\", \"filters\": []}\n" .
                    "- 'black t-shirt' -> {\"category\": \"t-shirts\", \"filters\": [\"black\"]}\n";
            }
            
            // Create language-specific instructions
            $langInstructions = ($lang === 'fr') ?
                "IMPORTANT: Le message est en français. Traduisez les catégories en anglais pour la recherche, mais gardez les filtres dans leur langue d'origine.\n" :
                "IMPORTANT: Extract the category name in English for database search, but keep filters in their original language.\n";
            
            $prompt = "Extract product search intent from this message. Return JSON with 'category' and 'filters'.\n" .
                     $langInstructions .
                     $frenchExamples .
                     $englishExamples .
                     "Message: {$message}\n" .
                     "JSON:";
            
            $this->log('EXTRACT CATEGORY AI PROMPT: ' . $prompt);
            
            $response = $this->http($apiKey)->post('https://api.openai.com/v1/chat/completions', [
                'json' => [
                    'model' => self::CHAT_MODEL,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'max_tokens' => 150,
                    'temperature' => 0.1
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            $result = trim($data['choices'][0]['message']['content'] ?? '');
            
            $this->log('EXTRACT CATEGORY AI RESPONSE: ' . $result);
            
            $parsed = json_decode($result, true);
            return is_array($parsed) && !empty($parsed['category']) ? $parsed : null;
            
        } catch (\Throwable $e) {
            $this->log('EXTRACT CATEGORY AI ERR: '.$e->getMessage());
            return null;
        }
    }
    
    private function searchCategoriesWithTerms(array $searchTerms, string $lang): ?array
    {
        $category = $searchTerms['category'] ?? '';
        if (!$category) return null;
        
        $idLang = (int)Context::getContext()->language->id;
        
        // Search for categories matching the AI-extracted category name
        $sql = 'SELECT DISTINCT c.id_category, cl.name
                FROM '._DB_PREFIX_.'category c
                INNER JOIN '._DB_PREFIX_.'category_lang cl ON (cl.id_category=c.id_category AND cl.id_lang='.$idLang.')
                INNER JOIN '._DB_PREFIX_.'category_product cp ON cp.id_category=c.id_category
                INNER JOIN '._DB_PREFIX_.'product p ON p.id_product=cp.id_product AND p.active=1
                WHERE c.active=1 AND c.id_category > 2 AND cl.name LIKE "%'.pSQL($category).'%"
                ORDER BY c.level_depth ASC, c.id_category ASC
                LIMIT 6';
        
        $categories = Db::getInstance()->executeS($sql) ?: [];
        $this->log('SEARCH CATEGORIES: Found '.count($categories).' for "'.$category.'"');
        return $categories ?: null;
    }
    
    private function handleFoundCategories(array $categories, array $searchTerms, string $lang): string
    {
        $categoryIds = array_column($categories, 'id_category');
        $categoryNames = array_column($categories, 'name');
        $filters = $searchTerms['filters'] ?? [];
        
        $this->log('FOUND CATEGORIES: '.json_encode($categoryNames).' with filters: '.json_encode($filters));
        
        // Check if any filters are enabled in configuration
        $enabledFilterConfigs = $this->getEnabledFilterConfigurations();
        $hasEnabledFilters = array_filter($enabledFilterConfigs); // Remove false values
        
        // STRICT FILTER MODE: If filters are enabled in config, ALWAYS require them
        if (!empty($hasEnabledFilters)) {
            // If user provided filters, search for products
            if (!empty($filters)) {
                $products = $this->searchProductsWithFilters($categoryIds, $filters, $lang);
                if ($products) {
                    return $this->formatProductResults($products, $lang);
                } else {
                    // No products found with the specified filters
                    $filtersList = implode(', ', $filters);
                    $list = implode(', ', $categoryNames);
                    
                    if ($lang === 'fr') {
                        return "Désolé, nous n'avons pas trouvé de produits dans **{$list}** correspondant à vos critères : **{$filtersList}**. Essayez d'autres critères comme d'autres couleurs, marques ou tailles.";
                    } else {
                        return "Sorry, we couldn't find any products in **{$list}** matching your criteria: **{$filtersList}**. Try other criteria like different colors, brands, or sizes.";
                    }
                }
            }
            
            // No filters provided - ALWAYS ask for them when enabled
            $_SESSION[self::S_CAT] = [
                'stage' => 'ask_filters',
                'cat_ids' => $categoryIds,
                'cat_names' => $categoryNames,
            ];
            
            $list = implode(', ', $categoryNames);
            $enabledFilterText = $this->getEnabledFilterOptions($lang);
            
            if ($lang === 'fr') {
                return "Parfait ! Nous avons **{$list}**. Pour afficher les produits, veuillez spécifier vos critères : {$enabledFilterText}.";
            } else {
                return "Great! We have **{$list}**. To display products, please specify your criteria: {$enabledFilterText}.";
            }
        } else {
            // No filters enabled - show products directly (fallback for when no filters are configured)
            $products = $this->searchProductsWithFilters($categoryIds, [], $lang);
            if ($products) {
                return $this->formatProductResults($products, $lang);
            }
            
            $list = implode(', ', $categoryNames);
            if ($lang === 'fr') {
                return "Parfait ! Nous avons **{$list}**, mais aucun produit n'est disponible pour le moment.";
            } else {
                return "Great! We have **{$list}**, but no products are available at the moment.";
            }
        }
    }
    
    private function handleCatalogFallback(string $message, string $lang): ?string
    {
        // Try basic token matching as last resort
        $tokens = $this->tok($message);
        if (!$tokens) return null;
        
        $categories = $this->findMatchingCategories($tokens, (int)Context::getContext()->language->id);
        if ($categories) {
            return $this->handleFoundCategories($categories, ['category' => $message, 'filters' => []], $lang);
        }
        
        // Get top categories to suggest
        $topCategories = $this->getTopCategories((int)Context::getContext()->language->id, 3);
        $categoryNames = [];
        
        foreach ($topCategories as $category) {
            $categoryNames[] = "'{$category['name']}'";
        }
        
        $suggestions = !empty($categoryNames) ? implode(', ', $categoryNames) : ($lang === 'fr' ? "une catégorie de produits" : "a product category");
        
        return $lang === 'fr' ? 
            "Nous ne trouvons pas cette catégorie. Essayez {$suggestions}." :
            "We can't find that category. Try {$suggestions}.";
    }
    
    /**
     * Get top categories with products to suggest to users
     */
    private function getTopCategories(int $idLang, int $limit = 3): array
    {
        $sql = 'SELECT DISTINCT c.id_category, cl.name
                FROM '._DB_PREFIX_.'category c
                INNER JOIN '._DB_PREFIX_.'category_lang cl
                  ON (cl.id_category=c.id_category AND cl.id_lang='.$idLang.')
                INNER JOIN '._DB_PREFIX_.'category_product cp ON cp.id_category=c.id_category
                INNER JOIN '._DB_PREFIX_.'product p ON p.id_product=cp.id_product AND p.active=1
                WHERE c.active=1 AND c.id_category > 2
                GROUP BY c.id_category
                ORDER BY COUNT(cp.id_product) DESC
                LIMIT '.(int)$limit;
        
        return Db::getInstance()->executeS($sql) ?: [];
    }
    
    private function searchProductsWithFilters(array $categoryIds, array $filters, string $lang): array
    {
        if (empty($categoryIds)) return [];
        
        $idLang = (int)Context::getContext()->language->id;
        
        // Extract tokens from filters to use for extracting brand and attribute IDs
        $tokens = [];
        foreach ($filters as $filter) {
            $filter = trim($filter);
            if (strlen($filter) >= 2) {
                $tokens[] = $filter;
            }
        }
        
        // Extract brand and attribute IDs from the filter tokens
        $extractedFilters = $this->extractFiltersFromMessage($tokens, $idLang);
        $brandIds = $extractedFilters['brands'] ?? [];
        $attrIds = $extractedFilters['attrs'] ?? [];
        
        $this->log('EXTRACTED FILTERS: Brands=' . json_encode($brandIds) . ', Attributes=' . json_encode($attrIds));
        
        // Use findProductsInCategories which properly handles brand and attribute filters
        if (!empty($brandIds) || !empty($attrIds)) {
            $products = $this->findProductsInCategories($categoryIds, $idLang, $extractedFilters, 20, $tokens);
            if (!empty($products)) {
                return $products;
            }
        }
        
        // Fallback to text-based search if no structured filters were found or no results
        $filterConditions = [];
        foreach ($filters as $filter) {
            $filter = trim($filter);
            if (strlen($filter) >= 3) {
                $filterConditions[] = "(pl.name LIKE '%".pSQL($filter)."%' OR pl.description LIKE '%".pSQL($filter)."%' OR 
                                      pl.description_short LIKE '%".pSQL($filter)."%' OR m.name LIKE '%".pSQL($filter)."%')";
            }
        }
        
        // Use AND between filter conditions to ensure all criteria are matched
        $filterClause = $filterConditions ? ' AND ('.implode(' AND ', $filterConditions).')' : '';
        
        $this->log('FILTER CONDITIONS: ' . json_encode($filterConditions));
        $this->log('FILTER CLAUSE: ' . $filterClause);
        
        $sql = 'SELECT DISTINCT p.id_product, pl.name, pl.link_rewrite
                FROM '._DB_PREFIX_.'product p
                INNER JOIN '._DB_PREFIX_.'product_lang pl ON (pl.id_product=p.id_product AND pl.id_lang='.$idLang.')
                INNER JOIN '._DB_PREFIX_.'category_product cp ON cp.id_product=p.id_product
                LEFT JOIN '._DB_PREFIX_.'manufacturer m ON m.id_manufacturer = p.id_manufacturer
                WHERE p.active=1 AND cp.id_category IN ('.implode(',', array_map('intval', $categoryIds)).')'.$filterClause.'
                ORDER BY pl.name ASC
                LIMIT 20';
        
        $this->log('PRODUCT SEARCH SQL: ' . $sql);
        
        $products = Db::getInstance()->executeS($sql) ?: [];
        $this->log('PRODUCTS FOUND: ' . count($products));
        
        // Add URLs to products
        foreach ($products as &$product) {
            $product['url'] = Context::getContext()->link->getProductLink($product['id_product']);
        }
        
        return $products;
    }
    
    /**
     * Check if user message is relevant to product filtering (not random conversation)
     */
    private function isMessageRelevantToFilters(string $message): bool
    {
        $message = mb_strtolower(trim($message), 'UTF-8');
        
        // Very short messages are usually not relevant
        if (mb_strlen($message, 'UTF-8') < 2) {
            return false;
        }
        
        // Clear signs this is NOT about product filtering
        $unrelatedPatterns = [
            '/^(hi|hello|salut|bonjour|hey)$/i',           // Just greetings
            '/^(thanks?|merci|bye|au revoir)$/i',          // Thanks/goodbye
            '/^(how are you|comment allez.vous)/',         // Personal questions
            '/^(what.*time|quelle heure)/',                // Time questions
            '/^(weather|temps|climate)/',                  // Weather talk
            '/^(help|aide)$/i'                             // Just "help"
        ];
        
        foreach ($unrelatedPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return false;
            }
        }
        
        // Check for product/shopping related terms
        $productTerms = [
            // General product terms
            'color', 'colour', 'couleur', 'brand', 'marque', 'size', 'taille',
            'price', 'prix', 'cheap', 'expensive', 'cher', 'bon marché',
            // Colors (basic ones) - dynamic detection
            'red', 'blue', 'green', 'black', 'white', 'yellow', 'orange', 'purple', 'pink', 'brown', 'gray', 'grey',
            'rouge', 'bleu', 'vert', 'noir', 'blanc', 'jaune', 'orange', 'violet', 'rose', 'marron', 'gris',
            // Sizes
            'small', 'medium', 'large', 'petit', 'moyen', 'grand', 'xs', 'xl', 's', 'm', 'l',
            // Common materials/styles
            'cotton', 'leather', 'coton', 'cuir', 'casual', 'sport'
        ];
        
        foreach ($productTerms as $term) {
            if (strpos($message, $term) !== false) {
                return true;
            }
        }
        
        // Check for numbers (sizes, prices)
        if (preg_match('/\b\d+\b/', $message)) {
            return true;
        }
        
        // If message is longer and specific, might be relevant product description
        if (mb_strlen($message, 'UTF-8') > 10 && !preg_match('/\?$/', $message)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get available CartRule coupons with their corresponding products
     */
    private function getAvailableCartRuleCoupons(string $lang): ?string
    {
        try {
            $idLang = (int)Context::getContext()->language->id;
            $idShop = (int)Context::getContext()->shop->id;
            $now = date('Y-m-d H:i:s');
            
            // Try CartRule table first (PrestaShop 1.7+)
            $sql = 'SELECT cr.id_cart_rule, cr.code, cr.name, cr.description, cr.reduction_percent, 
                           cr.reduction_amount, cr.reduction_currency, cr.minimum_amount, cr.minimum_amount_currency,
                           cr.quantity, cr.quantity_per_user, cr.date_to, cr.active
                    FROM '._DB_PREFIX_.'cart_rule cr
                    LEFT JOIN '._DB_PREFIX_.'cart_rule_lang crl ON (cr.id_cart_rule = crl.id_cart_rule AND crl.id_lang = '.$idLang.')
                    LEFT JOIN '._DB_PREFIX_.'cart_rule_shop crs ON (cr.id_cart_rule = crs.id_cart_rule AND crs.id_shop = '.$idShop.')
                    WHERE cr.active = 1 
                      AND cr.code != "" 
                      AND cr.code IS NOT NULL
                      AND (cr.date_from IS NULL OR cr.date_from <= "'.$now.'")
                      AND (cr.date_to IS NULL OR cr.date_to >= "'.$now.'")
                      AND (cr.quantity IS NULL OR cr.quantity > 0)
                    ORDER BY cr.reduction_percent DESC, cr.reduction_amount DESC
                    LIMIT 5';
                    
            // Check if CartRule table exists and has data
            $tableExists = Db::getInstance()->getValue('SHOW TABLES LIKE "'._DB_PREFIX_.'cart_rule"');
            if (!$tableExists) {
                // Fallback to discount table for older PrestaShop versions
                $sql = 'SELECT d.id_discount as id_cart_rule, d.name as code, d.name, d.name as description, 
                               d.value as reduction_percent, 0 as reduction_amount, 1 as reduction_currency,
                               0 as minimum_amount, 1 as minimum_amount_currency,
                               d.quantity, 1 as quantity_per_user, d.date_to, d.active
                        FROM '._DB_PREFIX_.'discount d
                        WHERE d.active = 1 
                          AND d.name != "" 
                          AND d.name IS NOT NULL
                          AND (d.date_from IS NULL OR d.date_from <= "'.$now.'")
                          AND (d.date_to IS NULL OR d.date_to >= "'.$now.'")
                          AND (d.quantity IS NULL OR d.quantity > 0)
                        ORDER BY d.value DESC
                        LIMIT 5';
            }
            
            $this->log('CART RULES SQL: ' . $sql);
            $cartRules = Db::getInstance()->executeS($sql);
            
            if (!$cartRules) {
                return null; // No available coupons
            }
            
            $this->log('AVAILABLE COUPONS: Found '.count($cartRules).' active cart rules');
            
            // Format the response
            $coupons = [];
            foreach ($cartRules as $rule) {
                $couponInfo = [
                    'code' => $rule['code'],
                    'name' => $rule['name'] ?: 'Discount Coupon',
                    'description' => $rule['description'] ?: '',
                ];
                
                // Format discount
                if ($rule['reduction_percent'] > 0) {
                    $couponInfo['discount'] = round($rule['reduction_percent'], 0) . '%';
                } else if ($rule['reduction_amount'] > 0) {
                    $currency = new Currency($rule['reduction_currency'] ?: Configuration::get('PS_CURRENCY_DEFAULT'));
                    $couponInfo['discount'] = Tools::displayPrice($rule['reduction_amount'], $currency);
                } else {
                    continue; // Skip if no discount
                }
                
                // Add minimum amount if exists
                if ($rule['minimum_amount'] > 0) {
                    $minCurrency = new Currency($rule['minimum_amount_currency'] ?: Configuration::get('PS_CURRENCY_DEFAULT'));
                    $couponInfo['min_amount'] = Tools::displayPrice($rule['minimum_amount'], $minCurrency);
                }
                
                // Get products this coupon applies to
                $products = $this->getCouponApplicableProducts($rule['id_cart_rule'], $idLang);
                if ($products) {
                    $couponInfo['products'] = $products;
                }
                
                $coupons[] = $couponInfo;
            }
            
            if (empty($coupons)) {
                return null;
            }
            
            return $this->formatAvailableCouponsResponse($coupons, $lang);
            
        } catch (\Throwable $e) {
            $this->log('GET AVAILABLE COUPONS ERR: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get products that a specific coupon can be applied to
     */
    private function getCouponApplicableProducts(int $idCartRule, int $idLang): array
    {
        try {
            // Check if coupon has product restrictions
            $hasProductRestrictions = (bool)Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM '._DB_PREFIX_.'cart_rule_product_rule 
                 WHERE id_cart_rule = '.$idCartRule
            );
            
            if (!$hasProductRestrictions) {
                // No restrictions = applies to all products, show some popular ones
                $sql = 'SELECT p.id_product, pl.name, p.price
                        FROM '._DB_PREFIX_.'product p
                        INNER JOIN '._DB_PREFIX_.'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = '.$idLang.')
                        WHERE p.active = 1
                        ORDER BY p.id_product DESC
                        LIMIT 3';
            } else {
                // Get specific products this coupon applies to
                $sql = 'SELECT DISTINCT p.id_product, pl.name, p.price
                        FROM '._DB_PREFIX_.'product p
                        INNER JOIN '._DB_PREFIX_.'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = '.$idLang.')
                        INNER JOIN '._DB_PREFIX_.'cart_rule_product_rule_value crprv ON crprv.id_item = p.id_product
                        INNER JOIN '._DB_PREFIX_.'cart_rule_product_rule crpr ON (crpr.id_product_rule = crprv.id_product_rule AND crpr.type = "products")
                        WHERE crpr.id_cart_rule = '.$idCartRule.' AND p.active = 1
                        LIMIT 5';
            }
            
            $products = Db::getInstance()->executeS($sql) ?: [];
            
            // Add URLs to products
            foreach ($products as &$product) {
                $product['url'] = Context::getContext()->link->getProductLink($product['id_product']);
                $product['price_formatted'] = Tools::displayPrice($product['price']);
            }
            
            return $products;
            
        } catch (\Throwable $e) {
            $this->log('GET COUPON PRODUCTS ERR: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Format available coupons response
     */
    private function formatAvailableCouponsResponse(array $coupons, string $lang): string
    {
        if (empty($coupons)) {
            return $lang === 'fr' ? 
                "Désolé, nous n'avons pas de coupons disponibles pour le moment." :
                "Sorry, we don't have any coupons available at the moment.";
        }
        
        $header = $lang === 'fr' ? 
            "🎫 Voici les coupons de réduction disponibles :" :
            "🎫 Here are the available discount coupons:";
        
        $response = $header . "\n\n";
        
        foreach ($coupons as $coupon) {
            $response .= "**" . $coupon['code'] . "** - " . $coupon['discount'] . " off\n";
            
            if (!empty($coupon['description'])) {
                $response .= "  " . $coupon['description'] . "\n";
            }
            
            if (!empty($coupon['min_amount'])) {
                $minText = $lang === 'fr' ? 
                    "  Minimum d'achat: " . $coupon['min_amount'] : 
                    "  Minimum purchase: " . $coupon['min_amount'];
                $response .= $minText . "\n";
            }
            
            if (!empty($coupon['products'])) {
                $productsText = $lang === 'fr' ? "  Produits applicables:" : "  Applicable products:";
                $response .= $productsText . "\n";
                
                foreach (array_slice($coupon['products'], 0, 3) as $product) {
                    $response .= "    • <a href=\"" . $product['url'] . "\" target=\"_blank\">" . $product['name'] . "</a> (" . $product['price_formatted'] . ")\n";
                }
            }
            
            $response .= "\n";
        }
        
        $footer = $lang === 'fr' ? 
            "💡 Copiez le code et utilisez-le lors de votre commande pour bénéficier de la réduction !" :
            "💡 Copy the code and use it during checkout to get the discount!";
            
        $response .= $footer;
        
        return $response;
    }
    
    /**
     * Get enabled filter options based on admin configuration
     */
    private function getEnabledFilterOptions(string $lang): string
    {
        $enabledFilters = [];
        
        // Check which filters are enabled in configuration
        $colorEnabled = Configuration::get('AI_CHATBOT_FILTER_COLOR');
        $brandEnabled = Configuration::get('AI_CHATBOT_FILTER_BRAND');
        $sizeEnabled = Configuration::get('AI_CHATBOT_FILTER_SIZE');
        
        $this->log('FILTER CONFIG: Color=' . ($colorEnabled ? '1' : '0') . ', Brand=' . ($brandEnabled ? '1' : '0') . ', Size=' . ($sizeEnabled ? '1' : '0'));
        
        if ($colorEnabled) {
            $enabledFilters[] = $lang === 'fr' ? '*couleur*' : '*color*';
        }
        
        if ($brandEnabled) {
            $enabledFilters[] = $lang === 'fr' ? '*marque*' : '*brand*';
        }
        
        if ($sizeEnabled) {
            $enabledFilters[] = $lang === 'fr' ? '*taille*' : '*size*';
        }
        
        $result = implode(', ', $enabledFilters);
        $this->log('ENABLED FILTERS: "' . $result . '"');
        
        return $result;
    }
    
    /**
     * Get enabled filter configurations as boolean array
     */
    private function getEnabledFilterConfigurations(): array
    {
        return [
            'color' => (bool)Configuration::get('AI_CHATBOT_FILTER_COLOR'),
            'brand' => (bool)Configuration::get('AI_CHATBOT_FILTER_BRAND'),
            'size' => (bool)Configuration::get('AI_CHATBOT_FILTER_SIZE'),
        ];
    }
    
    /**
     * Use AI to intelligently analyze user criteria and detect disabled filter types
     * This method leverages ChatGPT to identify filter criteria regardless of database content
     */
    private function analyzeUserCriteriaWithAI(string $message, string $lang, array $enabledFilters): array
    {
        $apiKey = Configuration::get('AI_CHATBOT_API_KEY');
        if (!$apiKey) {
            $this->log('AI CRITERIA ANALYSIS: No API key available, using fallback pattern matching');
            return $this->detectCriteriaPatternsFallback($message, $lang, $enabledFilters);
        }
        
        try {
            // Build enabled filters list for AI context
            $enabledList = [];
            if ($enabledFilters['color']) $enabledList[] = 'color';
            if ($enabledFilters['brand']) $enabledList[] = 'brand';
            if ($enabledFilters['size']) $enabledList[] = 'size';
            
            $enabledText = empty($enabledList) ? 'none' : implode(', ', $enabledList);
            
            // Create comprehensive AI prompt for criteria analysis
            $prompt = $lang === 'fr' ? 
                "Analysez CHAQUE MOT dans ce message d'utilisateur: \"{$message}\"\n\n" .
                "Critères de filtrage ACTIVÉS: {$enabledText}\n" .
                "Critères de filtrage DÉSACTIVÉS: " . implode(', ', array_keys(array_filter($enabledFilters, function($v) { return !$v; }))) . "\n\n" .
                "INSTRUCTIONS IMPORTANTES:\n" .
                "1. Analysez TOUS les mots, même ceux que vous ne connaissez pas\n" .
                "2. Déterminez le TYPE de critère pour chaque mot:\n" .
                "   - COULEUR: Toute couleur (bleu, rouge, noir, etc.)\n" .
                "   - MARQUE: Tout nom qui pourrait être une marque (Nike, ha, Adidas, et tous les marques des produits de tous les types même des mots inconnus)\n" .
                "   - TAILLE: Toute taille (S, M, L, XL, 36, 38, petit, grand, etc.)\n" .
                "3. Si un type de critère est DÉSACTIVÉ mais mentionné, incluez-le dans disabled_criteria\n" .
                "4. Soyez exhaustif - ne manquez aucun mot qui pourrait être un critère\n\n" .
                "Répondez UNIQUEMENT avec du JSON valide:\n" .
                "{\n" .
                "  \"has_disabled_criteria\": true/false,\n" .
                "  \"disabled_criteria\": [\"mot1 (type)\", \"mot2 (type)\"]\n" .
                "}\n\n" .
                "Exemples:\n" .
                "- Message: 'blue, ha' avec couleur activée: {\"has_disabled_criteria\": true, \"disabled_criteria\": [\"ha (marque)\"]}\n" .
                "- Message: 'pants, nike' avec couleur activée: {\"has_disabled_criteria\": true, \"disabled_criteria\": [\"nike (marque)\"]}\n" .
                "- Message: 'blue, adidas' avec couleur activée: {\"has_disabled_criteria\": true, \"disabled_criteria\": [\"adidas (marque)\"]}" :
                
                "Analyze EVERY WORD in this user message: \"{$message}\"\n\n" .
                "ENABLED filtering criteria: {$enabledText}\n" .
                "DISABLED filtering criteria: " . implode(', ', array_keys(array_filter($enabledFilters, function($v) { return !$v; }))) . "\n\n" .
                "CRITICAL INSTRUCTIONS:\n" .
                "1. Analyze ALL words, even unfamiliar ones\n" .
                "2. Determine the CRITERIA TYPE for each word:\n" .
                "   - COLOR: Any color word (blue, red, black, etc.)\n" .
                "   - BRAND: Any name that could be a brand (Nike, ha, Adidas,and all marks of all types of products even unknown words)\n" .
                "   - SIZE: Any size word (S, M, L, XL, 36, 38, small, large, etc.)\n" .
                "3. If a criteria type is DISABLED but mentioned, include it in disabled_criteria\n" .
                "4. Be exhaustive - don't miss any word that could be a criteria\n\n" .
                "Respond ONLY with valid JSON:\n" .
                "{\n" .
                "  \"has_disabled_criteria\": true/false,\n" .
                "  \"disabled_criteria\": [\"word1 (type)\", \"word2 (type)\"]\n" .
                "}\n\n" .
                "Examples:\n" .
                "- Message: 'blue, ha' with only color enabled: {\"has_disabled_criteria\": true, \"disabled_criteria\": [\"ha (brand)\"]}\n" .
                "- Message: 'pants, nike' with only color enabled: {\"has_disabled_criteria\": true, \"disabled_criteria\": [\"nike (brand)\"]}\n" .
                "- Message: 'blue, adidas' with only color enabled: {\"has_disabled_criteria\": true, \"disabled_criteria\": [\"adidas (brand)\"]}";
            
            $this->log('AI CRITERIA ANALYSIS PROMPT: ' . substr($prompt, 0, 200) . '...');
            
            $client = new \GuzzleHttp\Client();
            $response = $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'model' => self::CHAT_MODEL,
                    'messages' => [[
                        'role' => 'user', 
                        'content' => $prompt
                    ]],
                    'max_tokens' => 200,
                    'temperature' => 0.1  // Low temperature for consistent, accurate analysis
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            $aiResponse = trim($data['choices'][0]['message']['content'] ?? '');
            
            $this->log('AI CRITERIA ANALYSIS RESPONSE: ' . $aiResponse);
            
            // Parse AI JSON response
            $analysis = json_decode($aiResponse, true);
            if (!is_array($analysis)) {
                $this->log('AI CRITERIA ANALYSIS: Invalid JSON response, falling back to no disabled criteria');
                return ['has_disabled_criteria' => false, 'disabled_criteria' => []];
            }
            
            // Ensure proper structure
            $result = [
                'has_disabled_criteria' => !empty($analysis['has_disabled_criteria']),
                'disabled_criteria' => $analysis['disabled_criteria'] ?? []
            ];
            
            $this->log('AI CRITERIA ANALYSIS SUCCESS: ' . json_encode($result));
            return $result;
            
        } catch (\Throwable $e) {
            $this->log('AI CRITERIA ANALYSIS ERROR: ' . $e->getMessage() . ' - Using fallback pattern matching');
            // Fallback to pattern matching when AI fails
            return $this->detectCriteriaPatternsFallback($message, $lang, $enabledFilters);
        }
    }
    
    /**
     * Fallback method to detect filter criteria using pattern matching when AI is not available
     */
    private function detectCriteriaPatternsFallback(string $message, string $lang, array $enabledFilters): array
    {
        $this->log('FALLBACK CRITERIA DETECTION: Analyzing message using patterns');
        
        $messageLower = mb_strtolower($message, 'UTF-8');
        $disabledCriteria = [];
        
        // Define comprehensive pattern lists for each criteria type
        $colorPatterns = [
            // English colors
            'red', 'blue', 'green', 'yellow', 'black', 'white', 'gray', 'grey', 'brown', 'orange', 'purple', 'pink', 'violet',
            'navy', 'maroon', 'turquoise', 'cyan', 'magenta', 'lime', 'olive', 'silver', 'gold', 'beige', 'tan', 'khaki',
            // French colors  
            'rouge', 'bleu', 'vert', 'jaune', 'noir', 'blanc', 'gris', 'marron', 'orange', 'violet', 'rose',
            'marine', 'bordeaux', 'turquoise', 'cyan', 'magenta', 'citron', 'olive', 'argent', 'or', 'beige'
        ];
        
        $brandPatterns = [
            // Popular clothing/fashion brands
            'nike', 'adidas', 'puma', 'reebok', 'converse', 'vans', 'jordan', 'under armour', 'champion', 'fila',
            'lacoste', 'polo', 'tommy', 'calvin klein', 'hugo boss', 'armani', 'versace', 'gucci', 'prada',
            // Tech brands
            'apple', 'samsung', 'sony', 'lg', 'microsoft', 'google', 'huawei', 'xiaomi', 'oneplus',
            // Generic brand indicators
            'brand', 'marque', 'make', 'manufacturer', 'fabricant'
        ];
        
        $sizePatterns = [
            // Clothing sizes
            'xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl', 'small', 'medium', 'large', 'extra large',
            'petit', 'moyen', 'grand', 'très grand',
            // Shoe sizes (common ranges)
            '35', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47', '48',
            // US sizes
            '6', '7', '8', '9', '10', '11', '12', '13', '14', '15',
            // Size indicators
            'size', 'taille', 'pointure'
        ];
        
        // Check for color criteria when color filter is disabled
        if (!$enabledFilters['color']) {
            foreach ($colorPatterns as $color) {
                if (preg_match('/\\b' . preg_quote($color, '/') . '\\b/i', $messageLower)) {
                    $colorName = ucfirst($color);
                    $colorType = $lang === 'fr' ? 'couleur' : 'color';
                    $disabledCriteria[] = "{$colorName} ({$colorType})";
                    $this->log("FALLBACK: Detected disabled color criteria: {$color}");
                    break; // Only report one color to avoid spam
                }
            }
        }
        
        // Check for brand criteria when brand filter is disabled
        if (!$enabledFilters['brand']) {
            // First check known brand patterns
            foreach ($brandPatterns as $brand) {
                if (preg_match('/\\b' . preg_quote($brand, '/') . '\\b/i', $messageLower)) {
                    $brandName = ucfirst($brand);
                    $brandType = $lang === 'fr' ? 'marque' : 'brand';
                    $disabledCriteria[] = "{$brandName} ({$brandType})";
                    $this->log("FALLBACK: Detected disabled brand criteria: {$brand}");
                    break; // Only report one brand to avoid spam
                }
            }
            
            // If no known brands found, check database for potential brand matches
            if (empty($disabledCriteria)) {
                $tokens = preg_split('/[\s,]+/', $messageLower, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($tokens as $token) {
                    $token = trim($token);
                    if (strlen($token) >= 2) {
                        // Check if this token could be a brand in database
                        $brandCheck = Db::getInstance()->getValue(
                            'SELECT name FROM '._DB_PREFIX_.'manufacturer 
                             WHERE name LIKE "'.pSQL('%'.$token.'%').'" 
                             LIMIT 1'
                        );
                        if ($brandCheck) {
                            $brandName = ucfirst($token);
                            $brandType = $lang === 'fr' ? 'marque' : 'brand';
                            $disabledCriteria[] = "{$brandName} ({$brandType})";
                            $this->log("FALLBACK: Detected disabled brand from database: {$token}");
                            break; // Only report one brand to avoid spam
                        }
                    }
                }
            }
            
            // If still no brands found, use heuristics for potential brand names
            if (empty($disabledCriteria)) {
                $tokens = preg_split('/[\s,]+/', $messageLower, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($tokens as $token) {
                    $token = trim($token);
                    if (strlen($token) >= 2 && 
                        !in_array($token, $colorPatterns) && 
                        !in_array($token, $sizePatterns) &&
                        !in_array($token, ['pants', 'shoes', 'shirt', 'dress', 'jean', 'jeans', 'pantalon', 'chaussures', 'chemise', 'robe']) &&
                        preg_match('/^[a-zA-Z]+$/', $token)) { // Only alphabetic words
                        
                        // This could be a brand name
                        $brandName = ucfirst($token);
                        $brandType = $lang === 'fr' ? 'marque' : 'brand';
                        $disabledCriteria[] = "{$brandName} ({$brandType})";
                        $this->log("FALLBACK: Detected potential brand heuristically: {$token}");
                        break; // Only report one potential brand to avoid spam
                    }
                }
            }
        }
        
        // Check for size criteria when size filter is disabled
        if (!$enabledFilters['size']) {
            foreach ($sizePatterns as $size) {
                if (preg_match('/\\b' . preg_quote($size, '/') . '\\b/i', $messageLower)) {
                    $sizeName = strtoupper($size);
                    $sizeType = $lang === 'fr' ? 'taille' : 'size';
                    $disabledCriteria[] = "{$sizeName} ({$sizeType})";
                    $this->log("FALLBACK: Detected disabled size criteria: {$size}");
                    break; // Only report one size to avoid spam
                }
            }
        }
        
        $result = [
            'has_disabled_criteria' => !empty($disabledCriteria),
            'disabled_criteria' => $disabledCriteria
        ];
        
        $this->log('FALLBACK CRITERIA DETECTION RESULT: ' . json_encode($result));
        return $result;
    }
    
    private function findCategoriesByAIName(string $categoryName): array
    {
        $idLang = (int)Context::getContext()->language->id;
        $sql = 'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
                INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl ON c.id_category = cl.id_category
                WHERE cl.id_lang = ' . $idLang . ' AND c.active = 1 AND c.id_category > 2
                  AND cl.name LIKE "%' . pSQL($categoryName) . '%"
                LIMIT 5';
        
        $results = Db::getInstance()->executeS($sql) ?: [];
        return array_column($results, 'id_category');
    }
    
    private function searchProductsDirectly(string $category, array $filters, string $lang): string
    {
        $catIds = $this->findCategoriesByAIName($category);
        if (!$catIds) {
            return "Category '$category' not found. Please try another category.";
        }
        
        $products = $this->findProductsInCategories($catIds, (int)Context::getContext()->language->id, ['brands' => [], 'attrs' => []], 10);
        if (!$products) {
            return "No products found in $category. Please try different criteria.";
        }
        
        $lines = [];
        foreach ($products as $p) {
            $lines[] = '• <a href="' . $p['url'] . '" target="_blank">' . $p['name'] . '</a> - ' . $p['price'];
        }
        
        return "Found " . count($products) . " products in $category:\n\n" . implode("\n", $lines);
    }
    
    private function handleCatalogFilters(string $message, string $lang, array $flow): string
    {
        try {
            $idLang = (int)Context::getContext()->language->id;
            $tokens = $this->tok($message);
            
            $this->log('=== CATALOG FILTERS DEBUG START ===');
            $this->log('CATALOG FILTERS: Processing message: "'.$message.'" with tokens: '.json_encode($tokens));
            $this->debugLog('CATALOG FILTERS INPUT', [
                'message' => $message,
                'language' => $lang,
                'language_id' => $idLang,
                'tokens' => $tokens,
                'flow_state' => $flow
            ]);
            
            // Check if user input is relevant to product filtering
            if (!$this->isMessageRelevantToFilters($message)) {
                $this->log('CATALOG FILTERS: User input not relevant, resetting flow');
                // Reset the catalog flow - user is talking about something else
                unset($_SESSION[self::S_CAT]);
                
                if ($lang === 'fr') {
                    return "Je vois que vous avez changé de sujet. Si vous cherchez des produits, dites-moi simplement ce que vous voulez (ex: 'chaussures', 't-shirts').";
                } else {
                    return "I see you've changed topics. If you're looking for products, just tell me what you want (e.g., 'shoes', 't-shirts').";
                }
            }
            
            // First, use AI to intelligently detect and categorize user criteria
            $aiCriteriaAnalysis = $this->analyzeUserCriteriaWithAI($message, $lang, $this->getEnabledFilterConfigurations());
            $this->log('AI CRITERIA ANALYSIS: '.json_encode($aiCriteriaAnalysis));
            $this->debugLog('AI CRITERIA ANALYSIS', $aiCriteriaAnalysis);
            
            // Check if AI detected disabled criteria that user tried to use
            if (!empty($aiCriteriaAnalysis['has_disabled_criteria'])) {
                $this->log('CATALOG FILTERS: AI detected disabled criteria, re-asking for valid criteria');
                $enabledFilterText = $this->getEnabledFilterOptions($lang);
                $disabledItems = $aiCriteriaAnalysis['disabled_criteria'];
                
                // Build user-friendly message about disabled criteria
                $disabledText = implode(', ', $disabledItems);
                
                if ($lang === 'fr') {
                    return "Je remarque que vous avez mentionné: **{$disabledText}**, mais seuls ces critères sont disponibles : {$enabledFilterText}. Veuillez utiliser uniquement les critères disponibles pour votre recherche.";
                } else {
                    return "I notice you mentioned: **{$disabledText}**, but only these criteria are available: {$enabledFilterText}. Please use only the available criteria for your search.";
                }
            }
            
            // If AI analysis passed, proceed with database extraction
            $filters = $this->extractFiltersFromMessage($tokens, $idLang);
            $this->log('CATALOG FILTERS: Database extracted filters: '.json_encode($filters));
            $this->debugLog('FILTER EXTRACTION RESULT', $filters);

            // STRICT FILTER ENFORCEMENT: Check if filters are provided based on enabled configurations
            $enabledFilters = $this->getEnabledFilterConfigurations();
            $this->log('CATALOG FILTERS: Enabled filter configurations: '.json_encode($enabledFilters));
            
            // Check if user provided any valid filters when filters are enabled
            $hasValidFilters = false;
            if (!empty($enabledFilters)) {
                if (($enabledFilters['color'] || $enabledFilters['size']) && !empty($filters['attrs'])) {
                    $hasValidFilters = true;
                }
                if ($enabledFilters['brand'] && !empty($filters['brands'])) {
                    $hasValidFilters = true;
                }
            }
            
            // If filters are enabled but user didn't provide any valid filters, ask for them
            if (!empty($enabledFilters) && !$hasValidFilters) {
                $this->log('CATALOG FILTERS: No valid filters provided, requesting specific criteria');
                $enabledFilterText = $this->getEnabledFilterOptions($lang);
                
                if ($lang === 'fr') {
                    return "Pour rechercher des produits, veuillez spécifier des critères précis : {$enabledFilterText}.";
                } else {
                    return "To search for products, please specify precise criteria: {$enabledFilterText}.";
                }
            }

            $categoryIds = array_map('intval', (array)($flow['cat_ids'] ?? []));
            
            // PARAMETER VALIDATION: Validate category IDs
            $this->debugLog('CATEGORY ID VALIDATION', [
                'raw_cat_ids' => $flow['cat_ids'] ?? null,
                'processed_cat_ids' => $categoryIds,
                'category_count' => count($categoryIds)
            ]);
            
            if (!$categoryIds) {
                $this->log('CATALOG FILTERS ERROR: No category IDs found in flow');
                $this->debugLog('CATEGORY ID ERROR', ['flow_state' => $flow]);
                return $this->catMsg('category_undetermined', $lang);
            }
            
            // Validate that category IDs are positive integers
            $validCategoryIds = array_filter($categoryIds, function($id) { return $id > 0; });
            if (count($validCategoryIds) !== count($categoryIds)) {
                $this->log('CATALOG FILTERS WARNING: Some invalid category IDs found');
                $this->debugLog('INVALID CATEGORY IDS', [
                    'original' => $categoryIds,
                    'valid' => $validCategoryIds
                ]);
                $categoryIds = $validCategoryIds;
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
                    if ($lang === 'fr') {
                        return "Cette catégorie est vide pour le moment. Veuillez vérifier ultérieurement.";
                    } else {
                        return "That category is empty right now. Please check back later.";
                    }
                }
                
                // Check if the filters are valid but no products match
                if (!empty($filters['brands']) || !empty($filters['attrs'])) {
                    $this->log('CATALOG FILTERS: Filters specified but no matching products');
                    return $this->catMsg('filters_no_match', $lang);
                } else {
                    // No specific filters were provided
                    if ($lang === 'fr') {
                        return "Veuillez spécifier des critères comme la couleur, la marque ou la taille pour que je puisse trouver des produits correspondant à vos besoins.";
                    } else {
                        return "Please specify criteria like color, brand, or size so I can find products that match your needs.";
                    }
                }
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

        // Important short terms that should be included even if less than 3 characters
        $importantShortTerms = ['tv', 'pc', 'cd', 'dvd', 'vr', 'ps', 'ps4', 'ps5', 'mac', 'led', 'usb', 'ssd', 'hdd'];
        
        // Common category translations (French -> English)
        $categoryTranslations = [
            'chaussures' => 'shoes',
            'vetements' => 'clothing',
            'vêtements' => 'clothing',
            'electronique' => 'electronics',
            'électronique' => 'electronics'
        ];
        
        $likes = [];
        foreach ($tokens as $t) {
            $t = mb_strtolower(trim($t), 'UTF-8');
            
            // Check if we have a translation for this term
            if (array_key_exists($t, $categoryTranslations)) {
                $translatedTerm = $categoryTranslations[$t];
                $likes[] = "cl.name LIKE '".pSQL('%'.$translatedTerm.'%')."'";
                $this->log("CATEGORY SEARCH: Translated '{$t}' to '{$translatedTerm}' for search");
            }
            
            // Include tokens that are at least 3 characters OR are in the important short terms list
            if (mb_strlen($t,'UTF-8') >= 3 || in_array($t, $importantShortTerms)) {
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
        
        $answer = null;
        
        // For French queries, search directly in French FAQ without translation
        if ($lang === 'fr') {
            $this->log('FAQ: Searching directly in French FAQ');
            
            // Try embeddings first if available
            if ($apiKey && $embFile && is_file($embFile)) {
                $this->log('FAQ: Trying French embeddings search with query: "' . substr($message, 0, 50) . '..."');
                $answer = $this->searchFAQWithEmbeddings($message, $apiKey, $embFile, $isQuestion, $lang);
            }
            
            // Fallback to text search
            if (!$answer && $kbFile && is_file($kbFile)) {
                $this->log('FAQ: Trying French raw text search with query: "' . substr($message, 0, 50) . '..."');
                $rawText = @file_get_contents($kbFile) ?: '';
                if ($rawText !== '') {
                    $answer = $this->searchFAQInText($message, $rawText);
                }
            }
        } else {
            // For English queries, use the original logic
            $searchQuery = $message;
            
            // Try embeddings first if available
            if ($apiKey && $embFile && is_file($embFile)) {
                $this->log('FAQ: Trying English embeddings search with query: "' . substr($searchQuery, 0, 50) . '..."');
                $answer = $this->searchFAQWithEmbeddings($searchQuery, $apiKey, $embFile, $isQuestion, $lang);
            }
            
            // Fallback to text search
            if (!$answer && $kbFile && is_file($kbFile)) {
                $this->log('FAQ: Trying English raw text search with query: "' . substr($searchQuery, 0, 50) . '..."');
                $rawText = @file_get_contents($kbFile) ?: '';
                if ($rawText !== '') {
                    $answer = $this->searchFAQInText($searchQuery, $rawText);
                }
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
    
    private function searchFAQWithEmbeddings(string $query, string $apiKey, string $embFile, bool $isQuestion = false, ?string $lang = null): ?string
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
        $kwThreshold = $this->getAdaptiveThreshold('keyword', $keywordScore, $isQuestion, $lang);
        $semThreshold = $this->getAdaptiveThreshold('semantic', $semanticScore, $isQuestion, $lang);
        
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
    
    /**
     * Get adaptive threshold based on query type and language
     * 
     * @param string $type Type of threshold (keyword or semantic)
     * @param float $score Score value
     * @param bool $isQuestion Whether the query is a question
     * @param string $lang Language code (optional, will be detected from context if not provided)
     * @return float Calculated threshold
     */
    private function getAdaptiveThreshold(string $type, float $score, bool $isQuestion = false, ?string $lang = null): float
    {
        // Detect if we're processing a French query
        $isFrenchQuery = false;
        
        // If language is explicitly provided, use it
        if ($lang !== null) {
            $isFrenchQuery = $lang === 'fr';
        } else {
            // Try to detect from backtrace for backward compatibility
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            foreach ($backtrace as $trace) {
                if (isset($trace['function']) && $trace['function'] === 'searchFAQ') {
                    $isFrenchQuery = isset($trace['args'][1]) && $trace['args'][1] === 'fr';
                    break;
                }
            }
            
            // If still not detected, try to get from session
            if (!$isFrenchQuery && isset($_SESSION['ai_chat_lang']) && $_SESSION['ai_chat_lang'] === 'fr') {
                $isFrenchQuery = true;
            }
        }
        
        if ($type === 'keyword') {
            // Use dynamic thresholds if set, otherwise use constants
            $minThreshold = isset(self::$_KW_THRESHOLD_MIN) ? self::$_KW_THRESHOLD_MIN : self::KW_THRESHOLD_MIN;
            $maxThreshold = isset(self::$_KW_THRESHOLD_MAX) ? self::$_KW_THRESHOLD_MAX : self::KW_THRESHOLD_MAX;
            
            // Lower threshold for better keyword matches, even lower for questions
            $baseThreshold = $score > 0.08 ? $minThreshold : $maxThreshold;
            
            // Apply additional reduction for French queries (40% lower instead of 30%)
            if ($isFrenchQuery) {
                return $isQuestion ? $baseThreshold * 0.6 : $baseThreshold * 0.8; // 40% lower for French questions, 20% for other French
            }
            return $isQuestion ? $baseThreshold * 0.7 : $baseThreshold; // 30% lower for questions
        } else {
            // Use dynamic thresholds if set, otherwise use constants
            $minThreshold = isset(self::$_SEM_THRESHOLD_MIN) ? self::$_SEM_THRESHOLD_MIN : self::SEM_THRESHOLD_MIN;
            $maxThreshold = isset(self::$_SEM_THRESHOLD_MAX) ? self::$_SEM_THRESHOLD_MAX : self::SEM_THRESHOLD_MAX;
            
            // Adjust semantic threshold based on score confidence, lower for questions
            $baseThreshold = $score > 0.6 ? $minThreshold : $maxThreshold;
            
            // Apply additional reduction for French queries (30% lower instead of 20%)
            if ($isFrenchQuery) {
                return $isQuestion ? $baseThreshold * 0.7 : $baseThreshold * 0.85; // 30% lower for French questions, 15% for other French
            }
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
                'chaussure' => 'chaussures', 'chaussures' => 'chaussure',
                'vetement' => 'vetements', 'vetements' => 'vetement',
                'vêtement' => 'vêtements', 'vêtements' => 'vêtement',
                'electronique' => 'electroniques', 'electroniques' => 'electronique',
                'électronique' => 'électroniques', 'électroniques' => 'électronique',
            ];
            if (isset($irregulars[$token])) {
                $expanded[] = $irregulars[$token];
            }
            
            // French plural handling
            if (preg_match('/^(.+)x$/', $token, $m) && strlen($m[1]) > 2) {
                $expanded[] = $m[1]; // chapeaux -> chapeau
            }
            if (preg_match('/^(.+)s$/', $token, $m) && strlen($m[1]) > 2) {
                $expanded[] = $m[1]; // chaussures -> chaussure
            }
            if (preg_match('/^(.+)aux$/', $token, $m) && strlen($m[1]) > 2) {
                $expanded[] = $m[1] . 'al'; // travaux -> travail
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
        
        // Start with common hardcoded translations that might not be in the database
        $translations = [
            // French to English
            'chaussures' => ['shoes'],
            'vetements' => ['clothing', 'clothes'],
            'vêtements' => ['clothing', 'clothes'],
            'electronique' => ['electronics'],
            'électronique' => ['electronics'],
            
            // English to French
            'shoes' => ['chaussures'],
            'clothing' => ['vêtements', 'vetements'],
            'clothes' => ['vêtements', 'vetements'],
            'electronics' => ['électronique', 'electronique']
        ];
        
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

    /**
     * Enhanced conversation memory with context tracking
     * Stores messages with additional context information for better conversation flow
     */
    private function remember(string $role, string $content, array $contextData = []): void
    {
        if(!isset($_SESSION[self::S_CHAT]) || !is_array($_SESSION[self::S_CHAT])) $_SESSION[self::S_CHAT]=[];
        
        // Store message with enhanced context
        $entry = [
            'role' => $role,
            'content' => $content,
            'timestamp' => time(),
            'context' => []
        ];
        
        // Add context data if provided
        if (!empty($contextData)) {
            $entry['context'] = $contextData;
        } else {
            // Capture current conversation state
            $entry['context'] = [
                'intent' => $_SESSION['detected_intent'] ?? null,
                'language' => $_SESSION['detected_language'] ?? null,
                'in_flow' => $this->isInActiveFlow(),
                'flow_type' => $this->getActiveFlowType()
            ];
        }
        
        $_SESSION[self::S_CHAT][] = $entry;
        
        // Keep conversation history limited to last 24 messages
        if(count($_SESSION[self::S_CHAT]) > 24) {
            $_SESSION[self::S_CHAT] = array_slice($_SESSION[self::S_CHAT], -24);
        }
        
        // Store conversation summary for context awareness
        $this->updateConversationSummary($role, $content);
    }
    
    /**
     * Updates the conversation summary for better context awareness
     */
    private function updateConversationSummary(string $role, string $content): void
    {
        if (!isset($_SESSION['conversation_summary'])) {
            $_SESSION['conversation_summary'] = [
                'topics' => [],
                'last_intent' => null,
                'message_count' => 0,
                'last_update' => time()
            ];
        }
        
        // Update message count
        $_SESSION['conversation_summary']['message_count']++;
        $_SESSION['conversation_summary']['last_update'] = time();
        
        // Store last intent if available
        if (isset($_SESSION['detected_intent'])) {
            $_SESSION['conversation_summary']['last_intent'] = $_SESSION['detected_intent'];
        }
        
        // Extract potential topics from user messages
        if ($role === 'user') {
            $this->extractTopicsFromMessage($content);
        }
    }
    
    /**
     * Extracts potential topics from user messages for context tracking
     */
    private function extractTopicsFromMessage(string $message): void
    {
        // Simple keyword-based topic extraction
        $topicKeywords = [
            'order' => ['order', 'purchase', 'bought', 'delivery', 'shipping', 'track', 'commande', 'livraison'],
            'product' => ['product', 'item', 'produit', 'article'],
            'return' => ['return', 'refund', 'exchange', 'retour', 'remboursement'],
            'account' => ['account', 'login', 'password', 'compte', 'connexion', 'mot de passe'],
            'payment' => ['payment', 'pay', 'card', 'paiement', 'carte']
        ];
        
        foreach ($topicKeywords as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($message, $keyword) !== false) {
                    // Add topic if not already present
                    if (!in_array($topic, $_SESSION['conversation_summary']['topics'])) {
                        $_SESSION['conversation_summary']['topics'][] = $topic;
                    }
                    break;
                }
            }
        }
    }
    
    /**
     * Gets the current active flow type
     */
    private function getActiveFlowType(): ?string
    {
        if (isset($_SESSION[self::S_FLOW])) return 'order';
        if (isset($_SESSION[self::S_CAT])) return 'catalog';
        if (isset($_SESSION[self::S_RATING])) return 'rating';
        return null;
    }

    private function log(string $m){
        @file_put_contents(_PS_ROOT_DIR_.'/var/logs/ai_chatbot_debug.log','['.date('Y-m-d H:i:s')."] $m\n",FILE_APPEND);
    }
    
    /**
     * Enhanced debug logging with structured data
     */
    private function debugLog(string $context, array $data = []): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'context' => $context,
            'data' => $data,
            'session_id' => session_id(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        // Write to detailed debug file
        $debugFile = _PS_ROOT_DIR_ . '/var/logs/ai_chatbot_detailed_debug.json';
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($debugFile, $logLine, FILE_APPEND | LOCK_EX);
        
        // Also write readable version to main debug log
        $readableLog = "[$context] " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->log($readableLog);
    }
    
    /**
     * Debug endpoint to test query building without widget interference
     */
    private function handleDebugQueryRequest(): void
    {
        // Get parameters from GET request
        $categoryIds = Tools::getValue('categories'); // e.g., "10,11"
        $brandIds = Tools::getValue('brands'); // e.g., "3"
        $attributeIds = Tools::getValue('attributes'); // e.g., "14"
        $lang = Tools::getValue('lang', 'en');
        $message = Tools::getValue('message', 'blue ha'); // Test message
        
        $idLang = (int)Context::getContext()->language->id;
        
        // Parse IDs
        $catIds = $categoryIds ? array_map('intval', explode(',', $categoryIds)) : [];
        $brandIdArray = $brandIds ? array_map('intval', explode(',', $brandIds)) : [];
        $attrIdArray = $attributeIds ? array_map('intval', explode(',', $attributeIds)) : [];
        
        $this->debugLog('DEBUG ENDPOINT REQUEST', [
            'raw_categories' => $categoryIds,
            'raw_brands' => $brandIds,
            'raw_attributes' => $attributeIds,
            'parsed_categories' => $catIds,
            'parsed_brands' => $brandIdArray,
            'parsed_attributes' => $attrIdArray,
            'language' => $lang,
            'message' => $message
        ]);
        
        // Test 1: Filter extraction with selective filtering
        $tokens = $this->tok($message);
        $extractedFilters = $this->extractFiltersFromMessage($tokens, $idLang);
        
        // Show selective filtering in action
        $enabledFilterConfigs = $this->getEnabledFilterConfigurations();
        
        // Test 2: Query building if we have category IDs
        $queryResult = null;
        if (!empty($catIds)) {
            $queryResult = $this->runProductQuery($catIds, $idLang, $brandIdArray ?: $extractedFilters['brands'], $attrIdArray ?: $extractedFilters['attrs'], 24, $tokens, 'all');
        }
        
        // Test 3: Build the exact SQL we would run
        $debugInfo = [
            'input_parameters' => [
                'categories' => $catIds,
                'brands' => $brandIdArray,
                'attributes' => $attrIdArray,
                'language_id' => $idLang,
                'message' => $message,
                'tokens' => $tokens
            ],
            'selective_filtering' => [
                'enabled_filter_configs' => $enabledFilterConfigs,
                'example_scenarios' => [
                    'If only COLOR enabled: "' . $message . '" → would use only color filters, ignore brand/size',
                    'If only BRAND enabled: "' . $message . '" → would use only brand filters, ignore color/size',
                    'If all enabled: "' . $message . '" → would use all detected filters'
                ]
            ],
            'filter_extraction' => [
                'extracted_brands' => $extractedFilters['brands'] ?? [],
                'extracted_attributes' => $extractedFilters['attrs'] ?? [],
                'note' => 'These are the final filters after selective filtering - disabled filter types were ignored'
            ],
            'final_query_params' => [
                'category_ids' => $catIds,
                'brand_ids' => $brandIdArray ?: ($extractedFilters['brands'] ?? []),
                'attribute_ids' => $attrIdArray ?: ($extractedFilters['attrs'] ?? [])
            ],
            'query_results' => [
                'found_products' => $queryResult ? count($queryResult) : 0,
                'products' => $queryResult ? array_slice($queryResult, 0, 3) : []
            ],
            'expected_working_sql' => $this->buildExpectedSQL($catIds, $brandIdArray ?: ($extractedFilters['brands'] ?? []), $attrIdArray ?: ($extractedFilters['attrs'] ?? []), $idLang)
        ];
        
        $this->out([
            'debug_info' => $debugInfo,
            'status' => 'success',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Build the expected SQL based on your working example
     */
    private function buildExpectedSQL(array $catIds, array $brandIds, array $attrIds, int $idLang): string
    {
        $sql = "SELECT DISTINCT p.id_product, pl.name, p.price\n";
        $sql .= "FROM " . _DB_PREFIX_ . "product p\n";
        $sql .= "INNER JOIN " . _DB_PREFIX_ . "product_lang pl ON (pl.id_product = p.id_product AND pl.id_lang = {$idLang})\n";
        $sql .= "INNER JOIN " . _DB_PREFIX_ . "category_product cp ON cp.id_product = p.id_product\n";
        
        $conditions = [];
        $conditions[] = "p.active = 1";
        
        if (!empty($catIds)) {
            $conditions[] = "cp.id_category IN (" . implode(',', $catIds) . ")";
        }
        
        if (!empty($brandIds)) {
            $conditions[] = "p.id_manufacturer IN (" . implode(',', $brandIds) . ")";
        }
        
        if (!empty($attrIds)) {
            $conditions[] = "p.id_product IN (\n    SELECT pa.id_product\n    FROM " . _DB_PREFIX_ . "product_attribute pa\n    INNER JOIN " . _DB_PREFIX_ . "product_attribute_combination pac\n        ON pac.id_product_attribute = pa.id_product_attribute\n    WHERE pac.id_attribute IN (" . implode(',', $attrIds) . ")\n    GROUP BY pa.id_product\n    HAVING COUNT(DISTINCT pac.id_attribute) = " . count($attrIds) . "\n)";
        }
        
        $sql .= "WHERE " . implode("\n  AND ", $conditions) . "\n";
        $sql .= "ORDER BY p.date_upd DESC\n";
        $sql .= "LIMIT 24;";
        
        return $sql;
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
