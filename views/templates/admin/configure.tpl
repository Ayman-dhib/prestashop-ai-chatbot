{* modules/ai_chatbot/views/templates/admin/configure.tpl *}

<div class="panel">
  <div class="panel-heading">
    <i class="icon-comments"></i> AI Chatbot Settings
  </div>

  <form method="post" enctype="multipart/form-data" action="{$form_action|escape:'html':'UTF-8'}" class="form-horizontal">
    <div class="form-wrapper">

      <div class="form-group">
        <label class="control-label col-lg-3">{l s='OpenAI API Key' mod='ai_chatbot'}</label>
        <div class="col-lg-6">
          <input type="password" name="AI_CHATBOT_API_KEY"
                 value="{$AI_CHATBOT_API_KEY|escape:'html':'UTF-8'}"
                 class="form-control" autocomplete="off" />
          <p class="help-block">
            {l s='Stored securely in PrestaShop configuration.' mod='ai_chatbot'}
          </p>
        </div>
      </div>

      <div class="form-group">
        <label class="control-label col-lg-3">{l s='FAQ KB (.txt or .pdf)' mod='ai_chatbot'}</label>
        <div class="col-lg-6">
          <input type="file" name="AI_CHATBOT_KB_FILE" class="form-control" />
          <p class="help-block">
            {l s='Current file:' mod='ai_chatbot'} <code>{$AI_CHATBOT_KB_PATH|escape:'html':'UTF-8'}</code><br>
            {l s='First save will auto-create a Vector Store and attach your file.' mod='ai_chatbot'}
            {if $AI_CHATBOT_VECTOR_STORE_ID}
              <br/>{l s='Vector Store:' mod='ai_chatbot'} <code>{$AI_CHATBOT_VECTOR_STORE_ID|escape:'html':'UTF-8'}</code>
            {/if}
          </p>
        </div>
      </div>

      <div class="panel-footer">
        <button type="submit" name="submitAiChatbot" class="btn btn-primary">
          <i class="icon-save"></i> {l s='Save settings & upload FAQ' mod='ai_chatbot'}
        </button>
      </div>

    </div>
  </form>
</div>
