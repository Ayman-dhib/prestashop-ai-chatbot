<?php

if (!defined('_PS_VERSION_')) { exit; }

use GuzzleHttp\Client;

class Ai_Chatbot extends Module
{
    public function __construct()
    {
        $this->name = 'ai_chatbot';
        $this->version = '2.0.0';
        $this->author = 'AI Chatbot';
        $this->tab = 'front_office_features';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('AI Chatbot');
        $this->description = $this->l('Smart chatbot for FAQ, products, and customer service powered by AI.');
    }
    public function install()
    {
        return parent::install()
            && $this->registerHook('displayFooter')
            && Configuration::updateValue('AI_CHATBOT_API_KEY', '')
            && Configuration::updateValue('AI_CHATBOT_KB_FILE', _PS_MODULE_DIR_ . $this->name . '/uploads/faq.txt')
            && Configuration::updateValue('AI_CHATBOT_VECTOR_STORE_ID', '')
            && Configuration::updateValue('AI_CHATBOT_FILTER_COLOR', '1')
            && Configuration::updateValue('AI_CHATBOT_FILTER_BRAND', '1')
            && Configuration::updateValue('AI_CHATBOT_FILTER_SIZE', '1')
            && Configuration::updateValue('AI_CHATBOT_COUPON_ENABLED', '0')
            && Configuration::updateValue('AI_CHATBOT_POPUP_DELAY', '3')
            && $this->installCouponDefaults()
            && $this->installTables();
    }

    private function installCouponDefaults()
    {
        Configuration::updateValue('AI_CHATBOT_COUPON_ENABLED', false);
        Configuration::updateValue('AI_CHATBOT_COUPON_AMOUNT', 10);
        Configuration::updateValue('AI_CHATBOT_COUPON_TYPE', 'percentage');
        Configuration::updateValue('AI_CHATBOT_COUPON_EXPIRY_DAYS', 7);
        Configuration::updateValue('AI_CHATBOT_POPUP_FREQUENCY', 'once_per_day');
        Configuration::updateValue('AI_CHATBOT_COUPON_MIN_CART', 0);
        Configuration::updateValue('AI_CHATBOT_POPUP_DELAY', 3);
        Configuration::updateValue('AI_CHATBOT_COUPON_TITLE', 'Special Offer Just For You!');
        Configuration::updateValue('AI_CHATBOT_COUPON_MESSAGE', 'Get your exclusive discount code and save on your purchase!');
        return true;
    }

    private function installTables()
    {
        $sql = file_get_contents(_PS_MODULE_DIR_ . $this->name . '/install.sql');
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        $queries = preg_split('/;\s*$/m', $sql, -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                if (!Db::getInstance()->execute($query)) {
                    return false;
                }
            }
        }
        return true;
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        // Add order to rating queue when it's completed
        $order = $params['newOrderStatus'];
        $orderId = (int)$params['id_order'];
        
        // Check if this is a completed order status
        $completedStates = [
            Configuration::get('PS_OS_DELIVERED'),
            Configuration::get('PS_OS_SHIPPING'),
            Configuration::get('PS_OS_PAYMENT')
        ];
        
        if (in_array($order->id, $completedStates)) {
            $orderObj = new Order($orderId);
            if (Validate::isLoadedObject($orderObj) && $orderObj->id_customer) {
                // Add to pending ratings table
                try {
                    Db::getInstance()->insert('ai_chatbot_rating_pending', [
                        'id_order' => $orderId,
                        'id_customer' => (int)$orderObj->id_customer,
                        'rating_requested' => 0,
                        'date_add' => date('Y-m-d H:i:s')
                    ], false, true, Db::INSERT_IGNORE);
                } catch (Exception $e) {
                    // Log error but don't break the order process
                    error_log('AI Chatbot: Could not add order to rating queue: ' . $e->getMessage());
                }
            }
        }
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('AI_CHATBOT_API_KEY')
            && Configuration::deleteByName('AI_CHATBOT_KB_FILE')
            && Configuration::deleteByName('AI_CHATBOT_VECTOR_STORE_ID')
            && Configuration::deleteByName('AI_CHATBOT_FILTER_COLOR')
            && Configuration::deleteByName('AI_CHATBOT_FILTER_BRAND')
            && Configuration::deleteByName('AI_CHATBOT_FILTER_SIZE')
            && $this->uninstallCouponSettings();
    }

    private function uninstallCouponSettings()
    {
        Configuration::deleteByName('AI_CHATBOT_COUPON_ENABLED');
        Configuration::deleteByName('AI_CHATBOT_COUPON_AMOUNT');
        Configuration::deleteByName('AI_CHATBOT_COUPON_TYPE');
        Configuration::deleteByName('AI_CHATBOT_COUPON_EXPIRY_DAYS');
        Configuration::deleteByName('AI_CHATBOT_POPUP_FREQUENCY');
        Configuration::deleteByName('AI_CHATBOT_COUPON_MIN_CART');
        Configuration::deleteByName('AI_CHATBOT_POPUP_DELAY');
        Configuration::deleteByName('AI_CHATBOT_COUPON_TITLE');
        Configuration::deleteByName('AI_CHATBOT_COUPON_MESSAGE');
        return true;
    }

    public function hookDisplayFooter($params)
    {
        return $this->fetch('module:' . $this->name . '/views/templates/hook/chatbot.tpl');
    }

    public function getContent()
    {
        $apiKey = (string) Configuration::get('AI_CHATBOT_API_KEY');
        $kbFile = (string) Configuration::get('AI_CHATBOT_KB_FILE');

        if (Tools::isSubmit('submitAiChatbot')) {
            // Update API key
            $newKey = trim((string) Tools::getValue('AI_CHATBOT_API_KEY', ''));
            if ($newKey !== '') {
                Configuration::updateValue('AI_CHATBOT_API_KEY', $newKey);
                $apiKey = $newKey;
            }

            // Handle coupon settings
            $this->updateCouponSettings();
            
            // Update filter settings
            Configuration::updateValue('AI_CHATBOT_FILTER_COLOR', Tools::getValue('AI_CHATBOT_FILTER_COLOR') ? '1' : '0');
            Configuration::updateValue('AI_CHATBOT_FILTER_BRAND', Tools::getValue('AI_CHATBOT_FILTER_BRAND') ? '1' : '0');
            Configuration::updateValue('AI_CHATBOT_FILTER_SIZE', Tools::getValue('AI_CHATBOT_FILTER_SIZE') ? '1' : '0');

            // Handle file upload
            if (isset($_FILES['AI_CHATBOT_KB_FILE']) && is_uploaded_file($_FILES['AI_CHATBOT_KB_FILE']['tmp_name'])) {
                $uploadDir = _PS_MODULE_DIR_ . 'ai_chatbot/uploads/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                
                $ext = strtolower(pathinfo($_FILES['AI_CHATBOT_KB_FILE']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['txt', 'pdf'])) {
                    $ext = 'txt';
                }
                
                $dest = $uploadDir . 'faq.' . $ext;
                
                if (@move_uploaded_file($_FILES['AI_CHATBOT_KB_FILE']['tmp_name'], $dest)) {
                    @chmod($dest, 0644);
                    Configuration::updateValue('AI_CHATBOT_KB_FILE', $dest);
                    $kbFile = $dest;
                    
                    // Auto-generate embeddings if API key is available
                    if ($apiKey) {
                        $this->generateEmbeddings($dest, $apiKey);
                    }
                }
            }

            // Setup Vector Store if API key is provided
            if ($apiKey && $kbFile && is_file($kbFile)) {
                $this->setupVectorStore($apiKey, $kbFile);
            }

            if (isset($this->context->controller)) {
                $this->context->controller->confirmations[] = $this->l('Settings saved successfully.');
            }
        }

        $this->context->smarty->assign([
            'form_action' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'AI_CHATBOT_API_KEY' => $apiKey,
            'AI_CHATBOT_KB_PATH' => $kbFile,
            'AI_CHATBOT_VECTOR_STORE_ID' => Configuration::get('AI_CHATBOT_VECTOR_STORE_ID'),
            // Filter settings
            'AI_CHATBOT_FILTER_COLOR' => Configuration::get('AI_CHATBOT_FILTER_COLOR'),
            'AI_CHATBOT_FILTER_BRAND' => Configuration::get('AI_CHATBOT_FILTER_BRAND'),
            'AI_CHATBOT_FILTER_SIZE' => Configuration::get('AI_CHATBOT_FILTER_SIZE'),
            // Coupon settings
            'AI_CHATBOT_COUPON_ENABLED' => Configuration::get('AI_CHATBOT_COUPON_ENABLED'),
            'AI_CHATBOT_COUPON_AMOUNT' => Configuration::get('AI_CHATBOT_COUPON_AMOUNT'),
            'AI_CHATBOT_COUPON_TYPE' => Configuration::get('AI_CHATBOT_COUPON_TYPE'),
            'AI_CHATBOT_COUPON_EXPIRY_DAYS' => Configuration::get('AI_CHATBOT_COUPON_EXPIRY_DAYS'),
            'AI_CHATBOT_POPUP_FREQUENCY' => Configuration::get('AI_CHATBOT_POPUP_FREQUENCY'),
            'AI_CHATBOT_COUPON_MIN_CART' => Configuration::get('AI_CHATBOT_COUPON_MIN_CART'),
            'AI_CHATBOT_POPUP_DELAY' => Configuration::get('AI_CHATBOT_POPUP_DELAY'),
            'AI_CHATBOT_COUPON_TITLE' => Configuration::get('AI_CHATBOT_COUPON_TITLE'),
            'AI_CHATBOT_COUPON_MESSAGE' => Configuration::get('AI_CHATBOT_COUPON_MESSAGE'),
        ]);
        
        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    private function generateEmbeddings(string $faqPath, string $apiKey): void
    {
        try {
            require_once _PS_MODULE_DIR_ . 'ai_chatbot/faq_embed_admin.php';
            build_faq_embeddings($faqPath, $apiKey, 'text-embedding-3-small');
        } catch (\Throwable $e) {
            // Log error silently, don't interrupt the flow
            @file_put_contents(_PS_ROOT_DIR_ . '/var/logs/ai_chatbot.log', 
                '[' . date('Y-m-d H:i:s') . '] Embedding generation failed: ' . $e->getMessage() . "\n", 
                FILE_APPEND);
        }
    }

    private function setupVectorStore(string $apiKey, string $kbFile): void
    {
        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'OpenAI-Beta' => 'assistants=v2'
                ],
            ]);

            $vsId = Configuration::get('AI_CHATBOT_VECTOR_STORE_ID');
            
            if (!$vsId) {
                $resp = $client->post('vector_stores', ['json' => ['name' => 'chatbot-faq']]);
                $data = json_decode((string)$resp->getBody(), true);
                $vsId = $data['id'] ?? '';
                if ($vsId) {
                    Configuration::updateValue('AI_CHATBOT_VECTOR_STORE_ID', $vsId);
                }
            }

            if ($vsId && is_file($kbFile)) {
                $f = fopen($kbFile, 'r');
                $resp = $client->request('POST', 'files', [
                    'multipart' => [
                        ['name' => 'purpose', 'contents' => 'assistants'],
                        ['name' => 'file', 'contents' => $f, 'filename' => basename($kbFile)],
                    ],
                ]);
                
                $file = json_decode((string)$resp->getBody(), true);
                if (!empty($file['id'])) {
                    $client->post('vector_stores/' . $vsId . '/files', [
                        'json' => ['file_id' => $file['id']]
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Log error silently
            @file_put_contents(_PS_ROOT_DIR_ . '/var/logs/ai_chatbot.log', 
                '[' . date('Y-m-d H:i:s') . '] Vector store setup failed: ' . $e->getMessage() . "\n", 
                FILE_APPEND);
        }
    }

    private function updateCouponSettings()
    {
        Configuration::updateValue('AI_CHATBOT_COUPON_ENABLED', (bool)Tools::getValue('AI_CHATBOT_COUPON_ENABLED', false));
        Configuration::updateValue('AI_CHATBOT_COUPON_AMOUNT', (int)Tools::getValue('AI_CHATBOT_COUPON_AMOUNT', 10));
        Configuration::updateValue('AI_CHATBOT_COUPON_TYPE', Tools::getValue('AI_CHATBOT_COUPON_TYPE', 'percentage'));
        Configuration::updateValue('AI_CHATBOT_COUPON_EXPIRY_DAYS', (int)Tools::getValue('AI_CHATBOT_COUPON_EXPIRY_DAYS', 7));
        Configuration::updateValue('AI_CHATBOT_POPUP_FREQUENCY', Tools::getValue('AI_CHATBOT_POPUP_FREQUENCY', 'once_per_day'));
        Configuration::updateValue('AI_CHATBOT_COUPON_MIN_CART', (float)Tools::getValue('AI_CHATBOT_COUPON_MIN_CART', 0));
        Configuration::updateValue('AI_CHATBOT_POPUP_DELAY', (int)Tools::getValue('AI_CHATBOT_POPUP_DELAY', 3));
        Configuration::updateValue('AI_CHATBOT_COUPON_TITLE', Tools::getValue('AI_CHATBOT_COUPON_TITLE', 'Special Offer Just For You!'));
        Configuration::updateValue('AI_CHATBOT_COUPON_MESSAGE', Tools::getValue('AI_CHATBOT_COUPON_MESSAGE', 'Get your exclusive discount code and save on your purchase!'));
    }

    /**
     * Generate a unique coupon code for a visitor
     * @return array|null Array with coupon info or null on failure
     */
    public function generateCoupon()
    {
        if (!Configuration::get('AI_CHATBOT_COUPON_ENABLED')) {
            return null;
        }

        try {
            $couponCode = $this->generateUniqueCouponCode();
            $amount = (float)Configuration::get('AI_CHATBOT_COUPON_AMOUNT');
            $type = Configuration::get('AI_CHATBOT_COUPON_TYPE');
            $expiryDays = (int)Configuration::get('AI_CHATBOT_COUPON_EXPIRY_DAYS');
            $minCart = (float)Configuration::get('AI_CHATBOT_COUPON_MIN_CART');
            $title = Configuration::get('AI_CHATBOT_COUPON_TITLE');
            $message = Configuration::get('AI_CHATBOT_COUPON_MESSAGE');
            
            // Create CartRule (PrestaShop's coupon system)
            $cartRule = new CartRule();
            $cartRule->code = $couponCode;
            $cartRule->name = [
                (int)Configuration::get('PS_LANG_DEFAULT') => 'AI Chatbot Coupon: ' . $couponCode
            ];
            $cartRule->description = 'Generated by AI Chatbot module';
            
            // Set reduction
            if ($type === 'percentage') {
                $cartRule->reduction_percent = $amount;
                $cartRule->reduction_amount = 0;
            } else {
                $cartRule->reduction_amount = $amount;
                $cartRule->reduction_percent = 0;
                $cartRule->reduction_tax = 1; // Include tax
            }
            
            // Set minimum amount
            if ($minCart > 0) {
                $cartRule->minimum_amount = $minCart;
                $cartRule->minimum_amount_tax = 1;
                $cartRule->minimum_amount_currency = (int)Configuration::get('PS_CURRENCY_DEFAULT');
            }
            
            // Set dates
            $cartRule->date_from = date('Y-m-d H:i:s');
            $cartRule->date_to = date('Y-m-d H:i:s', strtotime('+' . $expiryDays . ' days'));
            
            // Set usage limits
            $cartRule->quantity = 1; // Single use
            $cartRule->quantity_per_user = 1;
            
            // Enable the rule
            $cartRule->active = 1;
            
            if ($cartRule->add()) {
                // Track this coupon
                $this->trackCouponGeneration($couponCode, $cartRule->id);
                
                return [
                    'code' => $couponCode,
                    'amount' => $amount,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'min_cart' => $minCart,
                    'expires' => date('Y-m-d', strtotime('+' . $expiryDays . ' days'))
                ];
            }
            
        } catch (Exception $e) {
            error_log('AI Chatbot Coupon Generation Error: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Generate a unique coupon code
     * @return string
     */
    private function generateUniqueCouponCode()
    {
        do {
            $code = 'AICHAT' . strtoupper(Tools::substr(md5(uniqid()), 0, 8));
            $exists = Db::getInstance()->getValue('SELECT id_cart_rule FROM ' . _DB_PREFIX_ . 'cart_rule WHERE code = "' . pSQL($code) . '"');
        } while ($exists);
        
        return $code;
    }

    /**
     * Track coupon generation for analytics
     * @param string $couponCode
     * @param int $cartRuleId
     */
    private function trackCouponGeneration($couponCode, $cartRuleId)
    {
        $customerId = null;
        $sessionId = null;
        
        if (Context::getContext()->customer && Context::getContext()->customer->id) {
            $customerId = (int)Context::getContext()->customer->id;
        }
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionId = session_id();
        }
        
        try {
            Db::getInstance()->insert('ai_chatbot_coupon_tracking', [
                'id_customer' => $customerId,
                'ip_address' => Tools::getRemoteAddr(),
                'session_id' => pSQL($sessionId),
                'coupon_code' => pSQL($couponCode),
                'id_cart_rule' => (int)$cartRuleId,
                'popup_shown' => 0,
                'popup_closed' => 0,
                'coupon_used' => 0,
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log('AI Chatbot Coupon Tracking Error: ' . $e->getMessage());
        }
    }

    /**
     * Check if visitor should see popup based on frequency settings
     * @return bool
     */
    public function shouldShowPopup()
    {
        if (!Configuration::get('AI_CHATBOT_COUPON_ENABLED')) {
            return false;
        }
        
        $frequency = Configuration::get('AI_CHATBOT_POPUP_FREQUENCY');
        $customerId = null;
        $ipAddress = Tools::getRemoteAddr();
        $sessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : null;
        
        if (Context::getContext()->customer && Context::getContext()->customer->id) {
            $customerId = (int)Context::getContext()->customer->id;
        }
        
        try {
            switch ($frequency) {
                case 'always':
                    return true;
                    
                case 'once_per_session':
                    if (!$sessionId) return false;
                    $count = (int)Db::getInstance()->getValue(
                        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'ai_chatbot_popup_frequency 
                         WHERE session_id = "' . pSQL($sessionId) . '"'
                    );
                    return $count == 0;
                    
                case 'once_per_day':
                    $condition = $customerId ? 
                        'id_customer = ' . $customerId : 
                        'ip_address = "' . pSQL($ipAddress) . '" AND id_customer IS NULL';
                    
                    $lastShown = Db::getInstance()->getValue(
                        'SELECT last_popup_shown FROM ' . _DB_PREFIX_ . 'ai_chatbot_popup_frequency 
                         WHERE ' . $condition . ' ORDER BY date_upd DESC LIMIT 1'
                    );
                    
                    if (!$lastShown) return true;
                    return strtotime($lastShown) < strtotime('-24 hours');
                    
                case 'once_per_week':
                    $condition = $customerId ? 
                        'id_customer = ' . $customerId : 
                        'ip_address = "' . pSQL($ipAddress) . '" AND id_customer IS NULL';
                    
                    $lastShown = Db::getInstance()->getValue(
                        'SELECT last_popup_shown FROM ' . _DB_PREFIX_ . 'ai_chatbot_popup_frequency 
                         WHERE ' . $condition . ' ORDER BY date_upd DESC LIMIT 1'
                    );
                    
                    if (!$lastShown) return true;
                    return strtotime($lastShown) < strtotime('-7 days');
                    
                case 'once_per_visitor':
                    $condition = $customerId ? 
                        'id_customer = ' . $customerId : 
                        'ip_address = "' . pSQL($ipAddress) . '" AND id_customer IS NULL';
                    
                    $count = (int)Db::getInstance()->getValue(
                        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'ai_chatbot_popup_frequency 
                         WHERE ' . $condition
                    );
                    return $count == 0;
                    
                default:
                    return false;
            }
        } catch (Exception $e) {
            error_log('AI Chatbot Popup Frequency Check Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Record that popup was shown to visitor
     */
    public function recordPopupShown()
    {
        $customerId = null;
        $ipAddress = Tools::getRemoteAddr();
        $sessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : null;
        
        if (Context::getContext()->customer && Context::getContext()->customer->id) {
            $customerId = (int)Context::getContext()->customer->id;
        }
        
        try {
            $data = [
                'id_customer' => $customerId,
                'ip_address' => pSQL($ipAddress),
                'session_id' => pSQL($sessionId),
                'last_popup_shown' => date('Y-m-d H:i:s'),
                'popup_count' => 1,
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s')
            ];
            
            // Check if record exists
            $condition = $customerId ? 
                'id_customer = ' . $customerId : 
                'ip_address = "' . pSQL($ipAddress) . '" AND id_customer IS NULL';
            
            $existingId = Db::getInstance()->getValue(
                'SELECT id FROM ' . _DB_PREFIX_ . 'ai_chatbot_popup_frequency 
                 WHERE ' . $condition . ' ORDER BY date_upd DESC LIMIT 1'
            );
            
            if ($existingId) {
                // Update existing record
                Db::getInstance()->update(
                    'ai_chatbot_popup_frequency',
                    [
                        'last_popup_shown' => date('Y-m-d H:i:s'),
                        'popup_count' => 'popup_count + 1',
                        'date_upd' => date('Y-m-d H:i:s')
                    ],
                    'id = ' . (int)$existingId
                );
            } else {
                // Insert new record
                Db::getInstance()->insert('ai_chatbot_popup_frequency', $data);
            }
        } catch (Exception $e) {
            error_log('AI Chatbot Popup Recording Error: ' . $e->getMessage());
        }
    }
}
