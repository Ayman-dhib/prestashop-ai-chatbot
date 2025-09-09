<?php

require_once _PS_MODULE_DIR_ . 'ai_chatbot/includes/Path.php';

class AdminAiChatbotController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();
        $this->context->smarty->assign([
            'module_dir' => $this->module->getPathUri(),
            'message'    => '✅ AI Chatbot admin panel is working!',
        ]);
        $this->setTemplate('configure.tpl');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('save_ai_chatbot')) {
            $apiKey = (string) Tools::getValue('openai_api_key');

            Configuration::updateValue('AI_CHATBOT_API_KEY', $apiKey);

            // Optional upload through this controller (compat)
            if (isset($_FILES['kb_file']) && $_FILES['kb_file']['error'] === UPLOAD_ERR_OK) {
                $uploads = AiPaths::uploadsDir();
                if (!is_dir($uploads)) @mkdir($uploads, 0755, true);
                $fileName = basename($_FILES['kb_file']['name']);
                $dest = $uploads . '/' . $fileName;
                if (@move_uploaded_file($_FILES['kb_file']['tmp_name'], $dest)) {
                    @chmod($dest, 0664);
                    Configuration::updateValue('AI_CHATBOT_KB_FILE', $dest);
                    Configuration::updateValue('AI_CHATBOT_FAQ_BASENAME', $fileName);
                } else {
                    $this->errors[] = $this->l('❌ Failed to upload FAQ file.');
                }
            }

            // Rebuild embeddings if possible (use FULL PATH!)
            $kbAbs = (string) Configuration::get('AI_CHATBOT_KB_FILE');
            if ($apiKey && $kbAbs && is_file($kbAbs)) {
                require_once _PS_MODULE_DIR_ . 'ai_chatbot/faq_embed_admin.php';
                try {
                    build_faq_embeddings($kbAbs, $apiKey, 'text-embedding-3-small');
                    $this->confirmations[] = $this->l('✅ FAQ embeddings updated successfully!');
                } catch (Exception $e) {
                    $this->errors[] = $this->l('❌ FAQ embeddings failed: ' . $e->getMessage());
                }
            } else {
                $this->errors[] = $this->l('❌ Missing API key or FAQ file.');
            }

            $this->confirmations[] = $this->l('✅ Settings saved successfully!');
        }
    }
}
