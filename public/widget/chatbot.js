/**
 * FleetQ Chatbot Widget — v1.0.0
 * Embeddable Web Component with Shadow DOM.
 *
 * Usage:
 *   <script src="/widget/chatbot.js"
 *           data-token="{fq_cb_...}"
 *           data-api-base="https://app.fleetq.net"
 *           data-theme-color="#6366f1"
 *           data-position="bottom-right">
 *   </script>
 */
(function () {
  'use strict';

  const STORAGE_PREFIX = 'fq_cb_';

  // Only allow valid hex color values to prevent style injection
  function safeHex(val) {
    return /^#[0-9A-Fa-f]{3,8}$/.test(val) ? val : '#6366f1';
  }

  class FleetqChatbot extends HTMLElement {
    constructor() {
      super();
      this._shadow = this.attachShadow({ mode: 'closed' });
      this._open = false;
      this._sessionId = null;
      this._pending = false;
      this._eventSource = null;
    }

    get token() { return this.dataset.token || ''; }
    get apiBase() { return (this.dataset.apiBase || '').replace(/\/$/, ''); }
    get themeColor() { return safeHex(this.dataset.themeColor || '#6366f1'); }
    get position() { return this.dataset.position === 'bottom-left' ? 'bottom-left' : 'bottom-right'; }

    connectedCallback() {
      this._sessionId = sessionStorage.getItem(STORAGE_PREFIX + 'sid') || null;
      this._render();
    }

    disconnectedCallback() {
      this._closeEventSource();
    }

    _render() {
      const tc = this.themeColor;
      const posLeft = this.position === 'bottom-left';

      // Build styles as a text node inside a <style> element — no untrusted data interpolated
      const style = document.createElement('style');
      style.textContent = [
        '*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }',
        ':host { font-family: system-ui, -apple-system, sans-serif; font-size: 14px; }',
        ':host { --tc: ' + tc + '; }',
        '.launcher { position: fixed; bottom: 20px; ' + (posLeft ? 'left: 20px;' : 'right: 20px;') + ' width: 56px; height: 56px; border-radius: 50%; background: var(--tc); border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,.25); display: flex; align-items: center; justify-content: center; z-index: 9999; transition: transform .2s; }',
        '.launcher:hover { transform: scale(1.08); }',
        '.launcher svg { width: 26px; height: 26px; fill: white; }',
        '.window { position: fixed; bottom: 90px; ' + (posLeft ? 'left: 20px;' : 'right: 20px;') + ' width: 360px; max-height: 520px; border-radius: 12px; background: #fff; box-shadow: 0 8px 32px rgba(0,0,0,.18); display: flex; flex-direction: column; z-index: 9998; overflow: hidden; }',
        '.window.hidden { display: none; }',
        '.header { padding: 14px 16px; background: var(--tc); color: #fff; font-weight: 600; font-size: 15px; display: flex; align-items: center; justify-content: space-between; }',
        '.header button { background: none; border: none; color: #fff; cursor: pointer; font-size: 18px; line-height: 1; }',
        '.messages { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 8px; }',
        '.msg { max-width: 80%; padding: 8px 12px; border-radius: 12px; line-height: 1.45; word-break: break-word; }',
        '.msg.user { background: var(--tc); color: #fff; align-self: flex-end; border-bottom-right-radius: 4px; }',
        '.msg.bot { background: #f3f4f6; color: #1f2937; align-self: flex-start; border-bottom-left-radius: 4px; }',
        '.msg.bot.pending { opacity: .6; font-style: italic; }',
        '.footer { padding: 10px; border-top: 1px solid #e5e7eb; display: flex; gap: 8px; }',
        '.footer input { flex: 1; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 12px; font-size: 14px; outline: none; }',
        '.footer input:focus { border-color: var(--tc); }',
        '.footer button { background: var(--tc); color: #fff; border: none; border-radius: 8px; padding: 8px 14px; cursor: pointer; font-size: 14px; }',
        '.footer button:disabled { opacity: .5; cursor: not-allowed; }',
      ].join('\n');

      // Launcher button
      const launcher = document.createElement('button');
      launcher.className = 'launcher';
      launcher.setAttribute('aria-label', 'Open chat');
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 24 24');
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', 'M20 2H4a2 2 0 00-2 2v18l4-4h14a2 2 0 002-2V4a2 2 0 00-2-2z');
      svg.appendChild(path);
      launcher.appendChild(svg);

      // Chat window
      const win = document.createElement('div');
      win.className = 'window hidden';
      win.id = 'fq-window';

      // Header
      const header = document.createElement('div');
      header.className = 'header';
      const title = document.createElement('span');
      title.textContent = 'Chat';
      const closeBtn = document.createElement('button');
      closeBtn.setAttribute('aria-label', 'Close');
      closeBtn.textContent = '\u00d7';
      header.appendChild(title);
      header.appendChild(closeBtn);

      // Messages
      const messages = document.createElement('div');
      messages.className = 'messages';
      messages.id = 'fq-messages';

      // Footer
      const footer = document.createElement('div');
      footer.className = 'footer';
      const input = document.createElement('input');
      input.type = 'text';
      input.placeholder = 'Type a message\u2026';
      input.maxLength = 4000;
      input.id = 'fq-input';
      const sendBtn = document.createElement('button');
      sendBtn.textContent = 'Send';

      footer.appendChild(input);
      footer.appendChild(sendBtn);
      win.appendChild(header);
      win.appendChild(messages);
      win.appendChild(footer);

      this._shadow.appendChild(style);
      this._shadow.appendChild(launcher);
      this._shadow.appendChild(win);

      // Store refs
      this._win = win;
      this._messages = messages;
      this._input = input;
      this._sendBtn = sendBtn;

      // Events
      launcher.addEventListener('click', () => this._toggleOpen());
      closeBtn.addEventListener('click', () => this._setOpen(false));
      sendBtn.addEventListener('click', () => this._sendMessage());
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this._sendMessage(); }
      });
    }

    _toggleOpen() { this._setOpen(!this._open); }

    _setOpen(open) {
      this._open = open;
      this._win.classList.toggle('hidden', !open);
      if (open && !this._sessionId) { this._initSession(); }
      if (open && this._sessionId) { this._openEventSource(); }
      if (!open) { this._closeEventSource(); }
    }

    async _initSession() {
      try {
        const res = await this._apiFetch('POST', '/api/chatbot/sessions', {});
        const data = await res.json();
        this._sessionId = data.session_id;
        sessionStorage.setItem(STORAGE_PREFIX + 'sid', this._sessionId);
        if (data.welcome_message) { this._appendMessage('bot', data.welcome_message); }
        this._openEventSource();
      } catch (_) {
        this._appendMessage('bot', 'Unable to connect. Please try again later.');
      }
    }

    async _sendMessage() {
      const text = (this._input.value || '').trim();
      if (!text || this._pending) { return; }
      this._input.value = '';
      this._appendMessage('user', text);
      this._pending = true;
      this._sendBtn.disabled = true;
      const thinkingEl = this._appendMessage('bot', '\u2026', true);
      try {
        const res = await this._apiFetch('POST', '/api/chatbot/sessions/' + this._sessionId + '/messages', { message: text });
        const data = await res.json();
        thinkingEl.remove();
        if (data.escalated) {
          this._appendMessage('bot', data.escalation_message || 'Your message is under review. You will be notified when a response is ready.');
        } else {
          this._appendMessage('bot', data.content || '');
        }
      } catch (_) {
        thinkingEl.remove();
        this._appendMessage('bot', 'Sorry, something went wrong. Please try again.');
      } finally {
        this._pending = false;
        this._sendBtn.disabled = false;
        this._input.focus();
      }
    }

    _openEventSource() {
      if (!this._sessionId || this._eventSource) { return; }
      const url = this.apiBase + '/api/chatbot/sessions/' + this._sessionId + '/events?token=' + encodeURIComponent(this.token);
      const es = new EventSource(url);
      es.addEventListener('approved_response', (e) => {
        try {
          const data = JSON.parse(e.data);
          this._appendMessage('bot', data.content || '');
        } catch (_) {}
      });
      es.onerror = () => { this._closeEventSource(); };
      this._eventSource = es;
    }

    _closeEventSource() {
      if (this._eventSource) { this._eventSource.close(); this._eventSource = null; }
    }

    _appendMessage(role, text, isPending = false) {
      const div = document.createElement('div');
      div.className = 'msg ' + role + (isPending ? ' pending' : '');
      div.textContent = text; // safe: textContent, not innerHTML
      this._messages.appendChild(div);
      this._messages.scrollTop = this._messages.scrollHeight;
      return div;
    }

    async _apiFetch(method, path, body) {
      return fetch(this.apiBase + path, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + this.token,
        },
        body: JSON.stringify(body),
      });
    }
  }

  if (!customElements.get('fleetq-chatbot')) {
    customElements.define('fleetq-chatbot', FleetqChatbot);
  }

  // Auto-init: mount element using attributes from the <script> tag
  const script = document.currentScript;
  if (script) {
    const el = document.createElement('fleetq-chatbot');
    for (const [k, v] of Object.entries(script.dataset)) {
      el.dataset[k] = v;
    }
    document.body.appendChild(el);
  }
})();
