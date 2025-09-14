{* modules/ai_chatbot/views/templates/admin/configure.tpl *}

<div class="ai-chatbot-config">
  <form method="post" enctype="multipart/form-data" action="{$form_action|escape:'html':'UTF-8'}">
    
    <!-- AI Chatbot Settings Section -->
    <div class="config-section">
      <div class="section-header">
        <i class="material-icons">smart_toy</i>
        <span>AI Chatbot Settings</span>
      </div>
      
      <div class="form-fields">
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label required">OpenAI API Key</label>
            <input type="password" name="AI_CHATBOT_API_KEY" value="{$AI_CHATBOT_API_KEY|escape:'html':'UTF-8'}" class="field-input" autocomplete="off" />
          </div>
          <div class="field-description">Stored securely in PrestaShop configuration.</div>
        </div>
        
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label">FAQ KB (.txt or .pdf)</label>
            <input type="file" name="AI_CHATBOT_KB_FILE" class="field-input file-input" />
          </div>
          <div class="field-description">
            Current file: <code>{$AI_CHATBOT_KB_PATH|escape:'html':'UTF-8'}</code><br>
            First save will auto-create a Vector Store and attach your file.
            {if $AI_CHATBOT_VECTOR_STORE_ID}<br/>Vector Store: <code>{$AI_CHATBOT_VECTOR_STORE_ID|escape:'html':'UTF-8'}</code>{/if}
          </div>
        </div>
      </div>
    </div>
    
    <!-- Catalog Filters Section -->
    <div class="config-section">
      <div class="section-header">
        <i class="material-icons">filter_list</i>
        <span>Catalog Filters</span>
      </div>
      <div class="form-fields">
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label">Filter Options</label>
            <div class="checkbox-group">
              <label class="checkbox-option">
                <input type="checkbox" name="AI_CHATBOT_FILTER_COLOR" value="1" {if $AI_CHATBOT_FILTER_COLOR}checked{/if} />
                <span>Color Filter</span>
              </label>
              <label class="checkbox-option">
                <input type="checkbox" name="AI_CHATBOT_FILTER_BRAND" value="1" {if $AI_CHATBOT_FILTER_BRAND}checked{/if} />
                <span>Brand Filter</span>
              </label>
              <label class="checkbox-option">
                <input type="checkbox" name="AI_CHATBOT_FILTER_SIZE" value="1" {if $AI_CHATBOT_FILTER_SIZE}checked{/if} />
                <span>Size Filter</span>
              </label>
            </div>
          </div>
          <div class="field-description">
            Choose which filters the chatbot will ask customers about when searching for products.<br/>
            <strong>Color:</strong> Ask about preferred colors (red, blue, black, etc.)<br/>
            <strong>Brand:</strong> Ask about preferred brands (Nike, Apple, etc.)<br/>
            <strong>Size:</strong> Ask about preferred sizes (S, M, L, XL, etc.)<br/>
            <em>If no filters are selected, the bot shows all products directly without asking for specifications.</em>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Promotional Coupon Settings Section -->
    <div class="config-section">
      <div class="section-header">
        <i class="material-icons">local_offer</i>
        <span>Promotional Coupon Settings</span>
      </div>
      <div class="form-fields">
        
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label">Enable Coupons</label>
            <div class="radio-group">
              <label class="radio-option">
                <input type="radio" name="AI_CHATBOT_COUPON_ENABLED" value="1" {if $AI_CHATBOT_COUPON_ENABLED}checked{/if} />
                <span>Yes</span>
              </label>
              <label class="radio-option">
                <input type="radio" name="AI_CHATBOT_COUPON_ENABLED" value="0" {if !$AI_CHATBOT_COUPON_ENABLED}checked{/if} />
                <span>No</span>
              </label>
            </div>
          </div>
          <div class="field-description">Enable automatic coupon generation and popup display for visitors</div>
        </div>
        
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label">Coupon Discount Amount</label>
            <div class="field-row">
              <input type="number" name="AI_CHATBOT_COUPON_AMOUNT" value="{$AI_CHATBOT_COUPON_AMOUNT|escape:'html':'UTF-8'}" class="field-input short" min="1" max="100" placeholder="10" />
              <select name="AI_CHATBOT_COUPON_TYPE" class="field-input short">
                <option value="percentage" {if $AI_CHATBOT_COUPON_TYPE == 'percentage'}selected{/if}>Percentage (%)</option>
                <option value="amount" {if $AI_CHATBOT_COUPON_TYPE == 'amount'}selected{/if}>Fixed Amount (€)</option>
              </select>
            </div>
          </div>
          <div class="field-description">Discount amount for generated coupons (percentage or fixed amount)</div>
        </div>
        
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label">Coupon Expiration (Days)</label>
            <input type="number" name="AI_CHATBOT_COUPON_EXPIRY_DAYS" value="{$AI_CHATBOT_COUPON_EXPIRY_DAYS|escape:'html':'UTF-8'}" class="field-input short" min="1" max="365" placeholder="7" />
          </div>
          <div class="field-description">Number of days until the coupon expires</div>
        </div>
        
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label">Popup Frequency</label>
            <select name="AI_CHATBOT_POPUP_FREQUENCY" class="field-input">
              <option value="always" {if $AI_CHATBOT_POPUP_FREQUENCY == 'always'}selected{/if}>Every Visit</option>
              <option value="once_per_session" {if $AI_CHATBOT_POPUP_FREQUENCY == 'once_per_session'}selected{/if}>Once Per Session</option>
              <option value="once_per_day" {if $AI_CHATBOT_POPUP_FREQUENCY == 'once_per_day'}selected{/if}>Once Per Day</option>
              <option value="once_per_week" {if $AI_CHATBOT_POPUP_FREQUENCY == 'once_per_week'}selected{/if}>Once Per Week</option>
              <option value="once_per_visitor" {if $AI_CHATBOT_POPUP_FREQUENCY == 'once_per_visitor'}selected{/if}>Once Per Visitor (Permanent)</option>
            </select>
          </div>
          <div class="field-description">How often to show the coupon popup to visitors</div>
        </div>
        
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label">Minimum Cart Amount</label>
            <div class="field-input-group">
              <input type="number" name="AI_CHATBOT_COUPON_MIN_CART" value="{$AI_CHATBOT_COUPON_MIN_CART|escape:'html':'UTF-8'}" class="field-input short" min="0" step="0.01" placeholder="0" />
              <span class="field-addon">€</span>
            </div>
          </div>
          <div class="field-description">Minimum cart amount required to use the coupon (0 for no minimum)</div>
        </div>
        
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label">Popup Delay (Seconds)</label>
            <input type="number" name="AI_CHATBOT_POPUP_DELAY" value="{$AI_CHATBOT_POPUP_DELAY|escape:'html':'UTF-8'}" class="field-input short" min="0" max="60" placeholder="3" />
          </div>
          <div class="field-description">Delay in seconds before showing the popup (0 for immediate)</div>
        </div>
        
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label">Coupon Title</label>
            <input type="text" name="AI_CHATBOT_COUPON_TITLE" value="{$AI_CHATBOT_COUPON_TITLE|escape:'html':'UTF-8'}" class="field-input" maxlength="100" placeholder="Special Offer Just For You!" />
          </div>
          <div class="field-description">Title displayed on the coupon popup</div>
        </div>
        
        <div class="field-group">
          <div class="field-row-main">
            <label class="field-label">Coupon Message</label>
            <textarea name="AI_CHATBOT_COUPON_MESSAGE" class="field-input textarea" rows="3" maxlength="500" placeholder="Get your exclusive discount code and save on your purchase!">{$AI_CHATBOT_COUPON_MESSAGE|escape:'html':'UTF-8'}</textarea>
          </div>
          <div class="field-description">Message displayed on the coupon popup explaining the offer</div>
        </div>
      </div>
    </div>
    
    <!-- Save Button -->
    <div class="form-actions">
      <button type="submit" name="submitAiChatbot" class="btn btn-primary btn-save">
        <i class="material-icons">save</i>
        Save settings & upload FAQ
      </button>
    </div>
    
  </form>
</div>

<style>
/* Modern PrestaShop Admin Styling */
.ai-chatbot-config {
  max-width: 100%;
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.config-section {
  background: #fff;
  border: 1px solid #dfdfdf;
  border-radius: 4px;
  margin-bottom: 20px;
  overflow: hidden;
}

.section-header {
  background: #f8f9fa;
  border-bottom: 1px solid #dfdfdf;
  padding: 15px 20px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  color: #555;
  font-size: 14px;
}

.section-header .material-icons {
  font-size: 18px;
  color: #25b9d7;
}

.form-fields {
  padding: 20px;
}

.field-group {
  margin-bottom: 28px;
  display: block;
}

.field-group:last-child {
  margin-bottom: 0;
}

.field-row-main {
  display: flex;
  align-items: center;
  gap: 15px;
  margin-bottom: 6px;
}

.field-label {
  display: inline-block;
  margin: 0;
  color: #666;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;
  min-width: 180px;
  text-align: right;
}

.field-label.required::after {
  content: " *";
  color: #e74c3c;
}

.field-input {
  width: 100%;
  max-width: 400px;
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 3px;
  font-size: 13px;
  line-height: 1.4;
  background: #fff;
  transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
  text-align: center;
}

.field-input:focus {
  text-align: left;
}

.field-input:focus {
  outline: none;
  border-color: #25b9d7;
  box-shadow: 0 0 0 2px rgba(37, 185, 215, 0.1);
}

.field-input.short {
  max-width: 120px;
}

.field-input.textarea {
  min-height: 80px;
  resize: vertical;
  max-width: 500px;
}

.field-input.file-input {
  padding: 6px;
  max-width: 300px;
}

.field-row {
  display: flex;
  gap: 12px;
  align-items: center;
}

.field-input-group {
  display: flex;
  max-width: 160px;
}

.field-input-group .field-input {
  border-radius: 3px 0 0 3px;
  border-right: 0;
}

.field-addon {
  padding: 8px 12px;
  background: #f8f9fa;
  border: 1px solid #ddd;
  border-left: 0;
  border-radius: 0 3px 3px 0;
  font-size: 13px;
  color: #666;
  display: flex;
  align-items: center;
}

.field-description {
  margin-top: 0;
  margin-left: 195px;
  font-size: 12px;
  color: #888;
  line-height: 1.4;
  max-width: 400px;
}

.field-description code {
  background: #f8f9fa;
  padding: 2px 6px;
  border-radius: 3px;
  border: 1px solid #e9ecef;
  font-family: 'Monaco', 'Consolas', 'Courier New', monospace;
  font-size: 11px;
}

/* Radio Button Group */
.radio-group {
  display: flex;
  gap: 20px;
}

.radio-option {
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  font-size: 13px;
  color: #666;
}

.radio-option input[type="radio"] {
  margin: 0;
  cursor: pointer;
}

.radio-option span {
  user-select: none;
}

/* Checkbox Group */
.checkbox-group {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.checkbox-option {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  font-size: 13px;
  color: #666;
  padding: 4px 0;
}

.checkbox-option input[type="checkbox"] {
  margin: 0;
  cursor: pointer;
  width: 16px;
  height: 16px;
}

.checkbox-option span {
  user-select: none;
  font-weight: 500;
}

.checkbox-option:hover {
  color: #25b9d7;
}

/* Form Actions */
.form-actions {
  background: #f8f9fa;
  border-top: 1px solid #dfdfdf;
  padding: 20px;
  margin-top: 20px;
  border-radius: 0 0 4px 4px;
}

.btn-save {
  background: #25b9d7;
  border: 1px solid #25b9d7;
  color: white;
  padding: 10px 20px;
  border-radius: 3px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all 0.15s ease-in-out;
  text-decoration: none;
}

.btn-save:hover {
  background: #1e9bb0;
  border-color: #1e9bb0;
  color: white;
  text-decoration: none;
}

.btn-save:focus {
  outline: none;
  box-shadow: 0 0 0 2px rgba(37, 185, 215, 0.2);
}

.btn-save .material-icons {
  font-size: 16px;
}

/* Select styling */
select.field-input {
  background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 4 5'%3E%3Cpath fill='%23666' d='m2 0-2 2h4zm0 5 2-2h-4z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 8px center;
  background-size: 8px;
  padding-right: 32px;
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
}

/* Responsive */
@media (max-width: 768px) {
  .field-row {
    flex-direction: column;
    align-items: flex-start;
  }
  
  .field-input {
    max-width: 100%;
  }
  
  .field-input.short {
    max-width: 200px;
  }
  
  .section-header {
    padding: 12px 15px;
    font-size: 13px;
  }
  
  .form-fields {
    padding: 15px;
  }
}
</style>
