{* modules/ai_chatbot/views/templates/hook/chatbot.tpl *}

<div id="ai-chat-root"
     class="ai-chat"
     data-v="tpl-2025-09-03-a"
     data-api="{$link->getModuleLink('ai_chatbot','api')|escape:'htmlall'}"
     data-coupon-api="{$link->getModuleLink('ai_chatbot','api')|escape:'htmlall'}?coupon=1">
  <button id="ai-chat-bubble" class="ai-bubble" aria-label="Chat" aria-expanded="false">
    <span class="ai-bubble-dot"></span>
    <span class="ai-bubble-dot"></span>
    <span class="ai-bubble-dot"></span>
    <span class="ai-bubble-label">Chat</span>
  </button>

  <section id="ai-chat-panel" class="ai-panel" hidden>
    <header class="ai-head">
      <div class="ai-title">Assistant</div>
      <div class="ai-head-actions">
        <button type="button" class="ai-icon ai-clear" title="New chat" aria-label="New chat">⟳</button>
        <button type="button" class="ai-icon ai-close" title="Close" aria-label="Close">×</button>
      </div>
    </header>

    <main id="ai-chat-log" class="ai-log" aria-live="polite"></main>

    <form id="ai-chat-form" class="ai-form" autocomplete="off">
      <textarea id="ai-chat-input" class="ai-input" rows="1"
        placeholder="{l s='Write your message… (Enter = send, Shift+Enter = newline)' mod='ai_chatbot'}"></textarea>
      <button class="ai-send" type="submit" title="{l s='Send' mod='ai_chatbot'}">➤</button>
    </form>
  </section>
</div>

<!-- Coupon Popup -->
<div id="ai-coupon-popup" class="ai-coupon-popup" style="display: none;">
  <div class="ai-coupon-overlay"></div>
  <div class="ai-coupon-modal">
    <div class="ai-coupon-close" onclick="closeCouponPopup()">&times;</div>
    <div class="ai-coupon-content">
      <div class="ai-coupon-icon">🎉</div>
      <h3 id="ai-coupon-title">Special Offer Just For You!</h3>
      <p id="ai-coupon-message">Get your exclusive discount code and save on your purchase!</p>
      <div class="ai-coupon-code-container">
        <div class="ai-coupon-code" id="ai-coupon-code">LOADING...</div>
        <button class="ai-coupon-copy" onclick="copyCouponCode()" title="Copy to clipboard">📋</button>
      </div>
      <div class="ai-coupon-details" id="ai-coupon-details">
        <p>Expires: <span id="ai-coupon-expires">-</span></p>
        <p class="ai-coupon-min-cart" id="ai-coupon-min-cart" style="display: none;">Minimum order: <span></span></p>
      </div>
      <div class="ai-coupon-actions">
        <button class="ai-coupon-shop-now" onclick="startShopping()">Shop Now</button>
        <button class="ai-coupon-chat" onclick="openChatWithCoupon()">Ask Assistant</button>
      </div>
    </div>
  </div>
</div>

<style>
  .ai-chat{ldelim}position:fixed;right:18px;bottom:18px;z-index:2147483000;font-family:Inter,system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif{rdelim}
  .ai-bubble{ldelim}display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,#86b6ff,#8a7bff);color:#0a0e1a;border:none;border-radius:999px;padding:10px 14px;box-shadow:0 10px 25px rgba(0,0,0,.25);cursor:pointer;transition:transform .15s ease,box-shadow .15s ease{rdelim}
  .ai-bubble:active{ldelim}transform:translateY(1px);box-shadow:0 6px 16px rgba(0,0,0,.25){rdelim}
  .ai-bubble-label{ldelim}font-weight:600{rdelim}
  .ai-bubble-dot{ldelim}width:7px;height:7px;background:#0a0e1a;border-radius:50%;display:inline-block;animation:ai-b 1.3s infinite ease-in-out{rdelim}
  .ai-bubble-dot:nth-child(2){ldelim}animation-delay:.15s{rdelim}
  .ai-bubble-dot:nth-child(3){ldelim}animation-delay:.3s{rdelim}
  @keyframes ai-b{ldelim}0%,80%,100%{ldelim}opacity:.4;transform:translateY(0){rdelim}40%{ldelim}opacity:1;transform:translateY(-3px){rdelim}{rdelim}
  .ai-panel{ldelim}width:min(420px,calc(100vw - 36px));height:min(560px,calc(100vh - 36px));background:#0e1525;color:#d9e1ff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.45);overflow:hidden;display:flex;flex-direction:column{rdelim}
  .ai-head{ldelim}display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:#0a0e1a;border-bottom:1px solid rgba(255,255,255,.06){rdelim}
  .ai-title{ldelim}font-weight:700{rdelim}
  .ai-icon{ldelim}background:transparent;color:#93a4ff;border:none;font-size:18px;line-height:1;cursor:pointer;margin-left:6px{rdelim}
  .ai-log{ldelim}flex:1;overflow:auto;padding:14px;scrollbar-width:none;-ms-overflow-style:none{rdelim}
  .ai-log::-webkit-scrollbar{ldelim}display:none{rdelim}
  .ai-msg{ldelim}max-width:80%;margin:8px 0;padding:10px 12px;border-radius:12px;font-size:14px;line-height:1.35;white-space:pre-wrap{rdelim}
  .ai-msg.user{ldelim}margin-left:auto;background:#2a3350;color:#e9eeff{rdelim}
  .ai-msg.bot{ldelim}background:#121a31;color:#dce4ff;border:1px solid rgba(255,255,255,.05){rdelim}
  .ai-msg.small{ldelim}opacity:.8;font-size:12px{rdelim}
  .ai-msg.typing{ldelim}opacity:.7;font-style:italic{rdelim}
  .ai-rating{ldelim}margin:10px 0;padding:15px;background:#1a2035;border-radius:12px;border:1px solid rgba(255,255,255,.08){rdelim}
  .ai-rating-stars{ldelim}display:flex;gap:6px;margin:8px 0 12px 0;justify-content:center{rdelim}
  .ai-rating-star{ldelim}font-size:28px;cursor:pointer;color:#4a5568;transition:color 0.2s ease;user-select:none{rdelim}
  .ai-rating-star:hover,.ai-rating-star.active{ldelim}color:#ffd700{rdelim}
  .ai-rating-star:hover ~ .ai-rating-star{ldelim}color:#4a5568{rdelim}
  .ai-rating-submit{ldelim}background:#7b88ff;color:#0a0e1a;border:none;border-radius:8px;padding:6px 12px;font-size:12px;cursor:pointer;margin-top:8px{rdelim}
  .ai-form{ldelim}display:flex;gap:8px;padding:10px;background:#0a0f1f;border-top:1px solid rgba(255,255,255,.06){rdelim}
  .ai-input{ldelim}flex:1;resize:none;background:#0e1525;color:#e3e9ff;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px 12px;outline:none;max-height:160px;overflow:auto{rdelim}
  .ai-input:focus{ldelim}border-color:#7d8dff{rdelim}
  .ai-send{ldelim}background:#7b88ff;color:#0a0e1a;border:none;border-radius:12px;padding:0 14px;font-weight:700;cursor:pointer{rdelim}
  .ai-send:active{ldelim}transform:translateY(1px){rdelim}
  
  /* Coupon Popup Styles */
  .ai-coupon-popup{ldelim}position:fixed;top:0;left:0;width:100%;height:100%;z-index:2147483001;display:flex;justify-content:center;align-items:center;font-family:Inter,system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;transition:opacity 0.2s ease{rdelim}
  .ai-coupon-overlay{ldelim}position:absolute;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);backdrop-filter:blur(5px){rdelim}
  .ai-coupon-modal{ldelim}position:relative;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:20px;box-shadow:0 25px 50px rgba(0,0,0,0.25);max-width:400px;width:calc(100% - 40px);margin:20px;animation:ai-popup-slide 0.3s ease-out{rdelim}
  .ai-coupon-close{ldelim}position:absolute;top:10px;right:15px;color:white;font-size:24px;cursor:pointer;line-height:1;z-index:10;opacity:0.9;transition:all 0.2s;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:rgba(0,0,0,0.2){rdelim}
  .ai-coupon-close:hover{ldelim}opacity:1;transform:scale(1.1);background:rgba(0,0,0,0.4){rdelim}
  .ai-coupon-content{ldelim}padding:40px 30px 30px;text-align:center;color:white{rdelim}
  .ai-coupon-icon{ldelim}font-size:48px;margin-bottom:15px;animation:ai-bounce 2s infinite{rdelim}
  .ai-coupon-content h3{ldelim}margin:0 0 15px;font-size:24px;font-weight:700;text-shadow:0 2px 4px rgba(0,0,0,0.3){rdelim}
  .ai-coupon-content p{ldelim}margin:0 0 25px;font-size:16px;color:white;opacity:1;line-height:1.4{rdelim}
  .ai-coupon-code-container{ldelim}display:flex;align-items:center;justify-content:center;gap:10px;margin:25px 0;padding:15px;background:rgba(255,255,255,0.15);border-radius:12px;backdrop-filter:blur(10px){rdelim}
  .ai-coupon-code{ldelim}font-family:Monaco,Consolas,monospace;font-size:18px;font-weight:bold;color:white;letter-spacing:1px;user-select:all{rdelim}
  .ai-coupon-copy{ldelim}background:rgba(255,255,255,0.2);border:none;color:white;padding:8px 12px;border-radius:6px;cursor:pointer;font-size:16px;transition:all 0.2s{rdelim}
  .ai-coupon-copy:hover{ldelim}background:rgba(255,255,255,0.3);transform:scale(1.05){rdelim}
  .ai-coupon-details{ldelim}margin:20px 0;font-size:14px;opacity:0.8{rdelim}
  .ai-coupon-details p{ldelim}margin:5px 0;font-size:14px{rdelim}
  .ai-coupon-actions{ldelim}display:flex;gap:12px;margin-top:25px{rdelim}
  .ai-coupon-actions button{ldelim}flex:1;padding:12px 20px;border:none;border-radius:25px;font-weight:600;cursor:pointer;font-size:14px;transition:all 0.2s{rdelim}
  .ai-coupon-shop-now{ldelim}background:white;color:#667eea;box-shadow:0 4px 15px rgba(255,255,255,0.2){rdelim}
  .ai-coupon-shop-now:hover{ldelim}transform:translateY(-2px);box-shadow:0 6px 20px rgba(255,255,255,0.3){rdelim}
  .ai-coupon-chat{ldelim}background:rgba(255,255,255,0.2);color:white;border:2px solid rgba(255,255,255,0.3){rdelim}
  .ai-coupon-chat:hover{ldelim}background:rgba(255,255,255,0.3);transform:translateY(-2px){rdelim}
  
  @keyframes ai-popup-slide{ldelim}from{ldelim}opacity:0;transform:scale(0.8) translateY(20px){rdelim}to{ldelim}opacity:1;transform:scale(1) translateY(0){rdelim}{rdelim}
  @keyframes ai-bounce{ldelim}0%,20%,50%,80%,100%{ldelim}transform:translateY(0){rdelim}40%{ldelim}transform:translateY(-10px){rdelim}60%{ldelim}transform:translateY(-5px){rdelim}{rdelim}
  
  /* Mobile responsive */
  @media (max-width: 480px) {
    .ai-coupon-modal{ldelim}margin:10px;border-radius:15px{rdelim}
    .ai-coupon-content{ldelim}padding:30px 20px 20px{rdelim}
    .ai-coupon-content h3{ldelim}font-size:20px{rdelim}
    .ai-coupon-code{ldelim}font-size:16px{rdelim}
    .ai-coupon-actions{ldelim}flex-direction:column{rdelim}
  }
</style>

{literal}
<script>
(function(){
  const root   = document.getElementById('ai-chat-root');
  const panel  = document.getElementById('ai-chat-panel');
  const bubble = document.getElementById('ai-chat-bubble');
  const log    = document.getElementById('ai-chat-log');
  const form   = document.getElementById('ai-chat-form');
  const input  = document.getElementById('ai-chat-input');
  const close  = root.querySelector('.ai-close');
  const clear  = root.querySelector('.ai-clear');

  const AI_CHAT_API = root.getAttribute('data-api');

  const clearLog = () => { log.innerHTML = ''; };

  // Force-close & clear immediately (prevents bfcache DOM reuse)
  panel.hidden = true;
  clearLog();

  const open = () => { panel.hidden = false; bubble.setAttribute('aria-expanded','true'); clearLog(); input.focus({preventScroll:true}); };
  const hide = () => { panel.hidden = true;  bubble.setAttribute('aria-expanded','false'); };
  const toggle = () => panel.hidden ? open() : hide();
  bubble.addEventListener('click', toggle);
  close.addEventListener('click', () => { hide(); resetSession(true); });
  clear.addEventListener('click', () => { clearLog(); resetSession(false); });

  /* ---------- auto-grow ---------- */
  const autoGrow = () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
  };
  input.addEventListener('input', autoGrow);

  /* ---------- context helpers ---------- */
  function metaContent(name){
    try{
      const sel = 'meta[name="'+name+'"],meta[property="'+name+'"]';
      const m   = document.querySelector(sel);
      return m ? (m.content || '') : '';
    }catch(e){ return ''; }
  }

  function textSample(){
    try{
      const sel = ['main','h1','h2','.product-title','.page-content','#content','.container'];
      let txt = '';
      for(const s of sel){
        const el = document.querySelector(s);
        if(el){ txt += ' ' + (el.innerText||''); }
      }
      const mt = (metaContent('description') || metaContent('og:description') || '');
      txt = (mt + ' ' + txt + ' ' + (document.body.innerText || '')).replace(/\s+/g,' ').trim();
      return txt.slice(0,1000);
    }catch(e){ return ''; }
  }

  function getProductId(){
    try{
      const inp = document.querySelector('input[name="id_product"]');
      if (inp && /^\d+$/.test(inp.value)) return parseInt(inp.value,10);
      const dataEl = document.querySelector('[data-id-product]');
      if (dataEl){
        const v = dataEl.getAttribute('data-id-product') || dataEl.dataset.idProduct;
        if (v && /^\d+$/.test(v)) return parseInt(v,10);
      }
      if (window.prestashop && window.prestashop.product && window.prestashop.product.id_product){
        const v = window.prestashop.product.id_product; if (v) return parseInt(v,10);
      }
      const scripts = Array.from(document.querySelectorAll('script[type="application/ld+json"]'));
      for (const s of scripts){
        try{
          const j = JSON.parse(s.textContent); const arr = Array.isArray(j) ? j : [j];
          for (const it of arr){
            const t = it['@type'];
            if (t==='Product' || (Array.isArray(t) && t.indexOf('Product')>=0)){
              const cand = it.productID || it.sku || it['@id'] || it.id;
              if (cand){ const num = parseInt(String(cand).replace(/\D+/g,''),10); if(!isNaN(num)) return num; }
            }
          }
        }catch(_){}
      }
    }catch(_){}
    return null;
  }

  function getCategoryId(){
    try{
      const b = document.body;
      let v = b.getAttribute('data-id-category') || (b.dataset ? b.dataset.idCategory : null);
      if (v && /^\d+$/.test(v)) return parseInt(v,10);
      const el = document.querySelector('[data-id-category]');
      if (el){
        v = el.getAttribute('data-id-category') || el.dataset.idCategory;
        if (v && /^\d+$/.test(v)) return parseInt(v,10);
      }
    }catch(_){}
    return null;
  }

  function pageType(){
    try{
      if (window.prestashop && window.prestashop.page && window.prestashop.page.page_name) return window.prestashop.page.page_name;
      return document.body.id || '';
    }catch(_){return '';}
  }

  function ctx(extra){
    return Object.assign({
      url: window.location.href,
      title: document.title,
      lang: (document.documentElement.lang || navigator.language || 'en').slice(0,2),
      text_sample: textSample(),
      product_id: getProductId(),
      category_id: getCategoryId(),
      page_type: pageType()
    }, extra||{});
  }

  /* ---------- chat UI ---------- */
  function addMsg(role, text, opts={}, data={}){
    const div = document.createElement('div');
    div.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot') + (opts.small ? ' small' : '') + (opts.typing ? ' typing' : '');
    div.innerHTML = text;
    
    // Add rating interface if this is a rating request
    if (data.show_rating && data.order_id) {
      const ratingDiv = document.createElement('div');
      ratingDiv.className = 'ai-rating';
      ratingDiv.innerHTML = `
        <div class="ai-rating-stars" data-order-id="${data.order_id}">
          <span class="ai-rating-star" data-rating="1">★</span>
          <span class="ai-rating-star" data-rating="2">★</span>
          <span class="ai-rating-star" data-rating="3">★</span>
          <span class="ai-rating-star" data-rating="4">★</span>
          <span class="ai-rating-star" data-rating="5">★</span>
        </div>
        <button class="ai-rating-submit" style="display:none;">Submit Rating</button>
      `;
      div.appendChild(ratingDiv);
      setupRatingInteraction(ratingDiv);
    }
    
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
    return div;
  }
  
  /* ---------- rating system ---------- */
  function setupRatingInteraction(ratingDiv) {
    const stars = ratingDiv.querySelectorAll('.ai-rating-star');
    const submitBtn = ratingDiv.querySelector('.ai-rating-submit');
    const orderId = ratingDiv.querySelector('.ai-rating-stars').dataset.orderId;
    let selectedRating = 0;
    
    stars.forEach((star, index) => {
      star.addEventListener('mouseenter', () => {
        highlightStars(stars, index + 1);
      });
      
      star.addEventListener('mouseleave', () => {
        highlightStars(stars, selectedRating);
      });
      
      star.addEventListener('click', () => {
        selectedRating = index + 1;
        highlightStars(stars, selectedRating);
        submitBtn.style.display = 'inline-block';
      });
    });
    
    submitBtn.addEventListener('click', () => {
      if (selectedRating > 0) {
        // Send rating as a regular message
        send(`${selectedRating} stars`);
        // Hide the rating interface
        ratingDiv.style.display = 'none';
      }
    });
  }
  
  function highlightStars(stars, rating) {
    stars.forEach((star, index) => {
      if (index < rating) {
        star.classList.add('active');
      } else {
        star.classList.remove('active');
      }
    });
  }

  async function send(text){
    addMsg('user', text);
    input.value = ''; autoGrow();
    const typing = addMsg('bot','typing…',{typing:true,small:true});
    try{
      const res  = await fetch(AI_CHAT_API, {
        method:'POST',
        credentials:'same-origin',
        cache:'no-store',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ message: text, context: ctx() })
      });
      const data = await res.json();
      typing.remove();
      if (data && data.reply) {
        addMsg('bot', data.reply, {}, data);
      } else {
        addMsg('bot', 'Please contact support.');
      }
    }catch(e){
      typing.remove();
      addMsg('bot', 'Please contact support.');
    }
  }

  // system call (optionally silent)
  async function sendSystem(extraCtx, silent){
    const typing = silent ? null : addMsg('bot','typing…',{typing:true,small:true});
    try{
      const res  = await fetch(AI_CHAT_API, {
        method:'POST',
        credentials:'same-origin',
        cache:'no-store',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ message: '[system]', context: ctx(extraCtx) })
      });
      const data = await res.json();
      if (!silent) {
        if (typing) typing.remove();
        if (data && data.reply){ addMsg('bot', data.reply, {}, data); }
      }
    }catch(_){ if (typing) typing.remove(); }
  }

  function resetSession(silent){
    return sendSystem({reset_chat:true}, !!silent);
  }

  form.addEventListener('submit', (ev)=>{
    ev.preventDefault();
    const t = (input.value || '').trim();
    if(!t) return;
    send(t);
  });
  input.addEventListener('keydown', (e)=>{
    if(e.key === 'Enter' && !e.shiftKey){
      e.preventDefault();
      form.dispatchEvent(new Event('submit'));
    }
  });

  /* ---------- Exit intent promo on cart ---------- */
  let exitFired = false;
  function isCart(){
    const p = pageType(); const u = window.location.href;
    return (p && p.toLowerCase().indexOf('cart')>=0) || /controller=cart|\/cart\b/i.test(u);
  }
  function triggerExit(){
    if(exitFired || !isCart()) return;
    exitFired = true;
    open();
    sendSystem({exit_intent:true}, false);
  }
  if (isCart()){
    document.addEventListener('mouseleave', (e)=>{ if (e.clientY<=0) triggerExit(); });
    document.addEventListener('visibilitychange', ()=>{ if (document.visibilityState==='hidden') triggerExit(); });
    window.addEventListener('beforeunload', ()=>{ triggerExit(); });
  }

  // ---- reset chat history on every page load silently
  resetSession(true);
  
  // ---- initialize coupon popup functionality
  initCouponPopup();

  // ---- handle browser bfcache (user navigates back)
  window.addEventListener('pageshow', (ev)=>{
    if (ev.persisted) {
      panel.hidden = true;
      clearLog();
      resetSession(true);
    }
  });
  
  /* ============ COUPON POPUP FUNCTIONALITY ============ */
  
  function initCouponPopup() {
    // Check if coupon should be shown on page load
    checkAndShowCoupon();
    
    // Close popup when clicking overlay or modal background
    const popup = document.getElementById('ai-coupon-popup');
    if (popup) {
      popup.addEventListener('click', function(event) {
        // Close if clicking the popup background (not the modal content)
        if (event.target === popup || event.target.classList.contains('ai-coupon-overlay')) {
          closeCouponPopup();
        }
      });
    }
  }
  
  async function checkAndShowCoupon() {
    try {
      const couponApiUrl = root.getAttribute('data-coupon-api');
      if (!couponApiUrl) return;
      
      // Check if popup should be shown and get coupon data
      const response = await fetch(couponApiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'check_popup' })
      });
      
      const data = await response.json();
      
      if (data.show_popup && data.coupon) {
        // Show popup with delay
        const delay = (data.delay || 3) * 1000;
        setTimeout(() => {
          showCouponPopup(data.coupon);
        }, delay);
      }
    } catch (error) {
      console.warn('Coupon popup check failed:', error);
    }
  }
  
  function showCouponPopup(couponData) {
    const popup = document.getElementById('ai-coupon-popup');
    if (!popup) return;
    
    // Update popup content
    const title = document.getElementById('ai-coupon-title');
    const message = document.getElementById('ai-coupon-message');
    const code = document.getElementById('ai-coupon-code');
    const expires = document.getElementById('ai-coupon-expires');
    const minCart = document.getElementById('ai-coupon-min-cart');
    
    if (title) title.textContent = couponData.title || 'Special Offer!';
    if (message) message.textContent = couponData.message || 'Get your discount!';
    if (code) code.textContent = couponData.code || '';
    if (expires) expires.textContent = couponData.expires || '';
    
    // Show minimum cart amount if set
    if (minCart && couponData.min_cart > 0) {
      minCart.style.display = 'block';
      minCart.querySelector('span').textContent = couponData.min_cart;
    }
    
    // Store coupon data globally
    window.aiChatbotCoupon = couponData;
    
    // Show popup with animation
    popup.style.display = 'flex';
    
    // Record that popup was shown
    recordPopupShown();
  }
  
  window.closeCouponPopup = function() {
    const popup = document.getElementById('ai-coupon-popup');
    if (popup) {
      // Add fade out animation
      popup.style.opacity = '0';
      setTimeout(() => {
        popup.style.display = 'none';
        popup.style.opacity = '1'; // Reset for next time
      }, 200);
    }
  };
  
  window.copyCouponCode = function() {
    const codeElement = document.getElementById('ai-coupon-code');
    if (!codeElement) return;
    
    const code = codeElement.textContent;
    
    // Try to copy to clipboard
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(code).then(() => {
        showCopySuccess();
      }).catch(() => {
        fallbackCopyToClipboard(code);
      });
    } else {
      fallbackCopyToClipboard(code);
    }
  };
  
  function fallbackCopyToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
      document.execCommand('copy');
      showCopySuccess();
    } catch (err) {
      console.warn('Copy failed:', err);
      // Show manual copy instruction
      alert('Copy this code: ' + text);
    } finally {
      document.body.removeChild(textArea);
    }
  }
  
  function showCopySuccess() {
    const copyBtn = document.querySelector('.ai-coupon-copy');
    if (copyBtn) {
      const originalText = copyBtn.textContent;
      copyBtn.textContent = '✓';
      copyBtn.style.background = 'rgba(76, 175, 80, 0.8)';
      
      setTimeout(() => {
        copyBtn.textContent = originalText;
        copyBtn.style.background = '';
      }, 2000);
    }
  }
  
  window.startShopping = function() {
    closeCouponPopup();
    
    // Try to find shop/category links, fallback to common shop URLs
    const possibleShopUrls = [
      document.querySelector('a[href*="category"]'),
      document.querySelector('a[href*="shop"]'),
      document.querySelector('a[href*="products"]'),
      document.querySelector('.category a'),
      document.querySelector('[data-depth="0"] a'), // PrestaShop category menu
      document.querySelector('#category a')
    ];
    
    let shopUrl = null;
    for (const link of possibleShopUrls) {
      if (link && link.href) {
        shopUrl = link.href;
        break;
      }
    }
    
    if (shopUrl) {
      window.location.href = shopUrl;
    } else {
      // Try common PrestaShop shop URLs
      const baseUrl = window.location.origin;
      const shopPaths = ['/2-home', '/shop', '/products', '/categories'];
      
      // Test if any of these paths exist
      window.location.href = baseUrl + shopPaths[0]; // Default to home category
    }
  };
  
  window.openChatWithCoupon = function() {
    closeCouponPopup();
    
    // Open chat panel
    if (panel && panel.hidden) {
      open();
    }
    
    // Send coupon info to chat after a short delay to ensure panel is open
    if (window.aiChatbotCoupon && input) {
      const couponMsg = `I have a coupon code: ${window.aiChatbotCoupon.code}. How can I use it?`;
      setTimeout(() => {
        input.value = couponMsg;
        input.focus();
        // Auto-submit the message
        if (form && typeof send === 'function') {
          send(couponMsg);
        }
      }, 700);
    }
  };
  
  // Add keyboard escape functionality
  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
      const popup = document.getElementById('ai-coupon-popup');
      if (popup && popup.style.display === 'flex') {
        closeCouponPopup();
      }
    }
  });
  
  async function recordPopupShown() {
    try {
      const couponApiUrl = root.getAttribute('data-coupon-api');
      if (!couponApiUrl) return;
      
      await fetch(couponApiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'record_popup_shown' })
      });
    } catch (error) {
      console.warn('Failed to record popup shown:', error);
    }
  }
  
})();
</script>
{/literal}
