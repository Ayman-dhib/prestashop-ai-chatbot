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

        parent::__construct();

        $this->displayName = $this->l('AI Chatbot');
        $this->description = $this->l('Smart chatbot for FAQ, products, and customer service powered by AI.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayFooter')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && Configuration::updateValue('AI_CHATBOT_API_KEY', '')
            && Configuration::updateValue('AI_CHATBOT_KB_FILE', _PS_MODULE_DIR_ . $this->name . '/uploads/faq.txt')
            && $this->installTables();
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
            && Configuration::deleteByName('AI_CHATBOT_VECTOR_STORE_ID');
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
}
