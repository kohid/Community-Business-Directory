/**
 * Emoji Picker (ported from the WorkSpace theme)
 *
 * Self-contained Fluent Emoji picker. Renders a Facebook-style tabbed UI,
 * fetches emojis from the kohid/icons jsDelivr CDN, and inserts the native
 * Unicode glyph into a target field on click.
 *
 * Markup contract — anywhere in the page:
 *   <button data-emoji-trigger data-emoji-target="#my-textarea">😀</button>
 *
 * Programmatic API:
 *   const picker = new WSEmojiPicker({ onSelect: ({ name, url, glyph }) => {} });
 *   picker.open({ trigger: btnEl, target: textareaEl });
 */
(function (window, document) {
    'use strict';

    const CDN = 'https://cdn.jsdelivr.net/gh/kohid/icons@main/fluent-emoji';
    const STORAGE_RECENT = 'cbd-emoji-recents';
    const STORAGE_TONE = 'cbd-emoji-tone';
    const RECENT_LIMIT = 24;

    const CATEGORIES = [
        { id: 'recent',     label: 'Recent',     icon: 'three-oclock' },
        { id: 'people',     label: 'People',     icon: 'grinning-face' },
        { id: 'nature',     label: 'Nature',     icon: 'dog-face' },
        { id: 'food',       label: 'Food',       icon: 'hamburger' },
        { id: 'activities', label: 'Activities', icon: 'soccer-ball' },
        { id: 'travel',     label: 'Travel',     icon: 'automobile' },
        { id: 'objects',    label: 'Objects',    icon: 'light-bulb' },
        { id: 'symbols',    label: 'Symbols',    icon: 'hundred-points' },
        { id: 'flags',      label: 'Flags',      icon: 'triangular-flag' },
    ];

    const TONES = ['default', 'light', 'medium-light', 'medium', 'medium-dark', 'dark'];
    const TONE_GLYPHS = {
        'default':       '👋',
        'light':         '👋🏻',
        'medium-light':  '👋🏼',
        'medium':        '👋🏽',
        'medium-dark':   '👋🏾',
        'dark':          '👋🏿',
    };

    let manifest = null;
    let manifestPromise = null;

    function loadManifest() {
        if (manifest) return Promise.resolve(manifest);
        if (manifestPromise) return manifestPromise;
        manifestPromise = fetch(CDN + '/manifest.json', { cache: 'no-cache' })
            .then(r => r.json())
            .then(m => (manifest = m));
        return manifestPromise;
    }

    function svgUrl(name) { return CDN + '/svg/' + name + '.svg'; }
    function nameToLabel(name) { return name.replace(/-/g, ' '); }

    function findGlyph(name) {
        if (!manifest || !manifest.categories) return '';
        for (const cat of Object.keys(manifest.categories)) {
            const list = manifest.categories[cat];
            for (const e of list) {
                if (e.name === name && e.unicode) return e.unicode;
                if (e.tones && e.tones.length) {
                    if (e.tones.indexOf(name) !== -1 && e.unicode) return e.unicode;
                }
            }
        }
        return '';
    }

    function getRecents() {
        try { return JSON.parse(localStorage.getItem(STORAGE_RECENT)) || []; }
        catch { return []; }
    }
    function addRecent(name) {
        const list = getRecents().filter(n => n !== name);
        list.unshift(name);
        localStorage.setItem(STORAGE_RECENT, JSON.stringify(list.slice(0, RECENT_LIMIT)));
    }

    function getTone() { return localStorage.getItem(STORAGE_TONE) || 'default'; }
    function setTone(t) { localStorage.setItem(STORAGE_TONE, t); }

    function applyTone(entry, tone) {
        if (tone === 'default' || !entry.tones || !entry.tones.length) return entry.name;
        return entry.tones.find(t => t.endsWith('-' + tone)) || entry.name;
    }

    class WSEmojiPicker {
        constructor(opts = {}) {
            this.opts = opts;
            this.el = null;
            this.trigger = null;
            this.target = null;
            this.activeTab = 'recent';
            this.searchQuery = '';
            this.tone = getTone();
            this._onDocClick = this._onDocClick.bind(this);
            this._onKey = this._onKey.bind(this);
            this._onScroll = this._onScroll.bind(this);
        }

        async open({ trigger, target } = {}) {
            this.trigger = trigger || null;
            this.target = target || null;
            try {
                await loadManifest();
            } catch (err) {
                console.error('EmojiPicker: failed to load manifest', err);
                return;
            }
            this._build();
            this._position();
            setTimeout(() => {
                document.addEventListener('mousedown', this._onDocClick, true);
                document.addEventListener('keydown', this._onKey);
                window.addEventListener('scroll', this._onScroll, true);
                window.addEventListener('resize', this._onScroll);
            }, 0);
        }

        close() {
            if (!this.el) return;
            this.el.remove();
            this.el = null;
            document.removeEventListener('mousedown', this._onDocClick, true);
            document.removeEventListener('keydown', this._onKey);
            window.removeEventListener('scroll', this._onScroll, true);
            window.removeEventListener('resize', this._onScroll);
            if (typeof this.opts.onClose === 'function') this.opts.onClose();
        }

        _onDocClick(e) {
            if (!this.el) return;
            if (this.el.contains(e.target)) return;
            if (this.trigger && this.trigger.contains(e.target)) return;
            this.close();
        }
        _onKey(e) {
            if (e.key === 'Escape') this.close();
        }
        _onScroll() {
            if (this.el && this.trigger) this._position();
        }

        _build() {
            const el = document.createElement('div');
            el.className = 'ws-emoji-picker';
            el.innerHTML = `
                <div class="ws-emoji-picker__head">
                    <label class="ws-emoji-search">
                        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
                            <path fill="currentColor" d="M11.7 10.3a6.5 6.5 0 1 0-1.4 1.4l3.9 3.9a1 1 0 0 0 1.4-1.4l-3.9-3.9zM6.5 12a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/>
                        </svg>
                        <input type="search" placeholder="Search" aria-label="Search emoji">
                    </label>
                    <button class="ws-emoji-tone-btn" type="button" aria-label="Skin tone">
                        <span class="ws-emoji-tone-glyph">${TONE_GLYPHS[this.tone]}</span>
                    </button>
                    <button class="ws-emoji-close" type="button" aria-label="Close">✕</button>
                </div>
                <div class="ws-emoji-tone-menu" hidden>
                    ${TONES.map(t => `
                        <button type="button" data-tone="${t}" class="ws-emoji-tone-opt${t === this.tone ? ' is-active' : ''}" aria-label="${t}">${TONE_GLYPHS[t]}</button>
                    `).join('')}
                </div>
                <div class="ws-emoji-tabs" role="tablist">
                    ${CATEGORIES.map(c => `
                        <button type="button" role="tab" class="ws-emoji-tab" data-cat="${c.id}" aria-label="${c.label}" title="${c.label}">
                            <img src="${svgUrl(c.icon)}" alt="" loading="lazy">
                        </button>
                    `).join('')}
                </div>
                <div class="ws-emoji-body" role="tabpanel"></div>
            `;
            this.el = el;
            document.body.appendChild(el);

            el.querySelector('.ws-emoji-close').addEventListener('click', () => this.close());

            el.querySelector('.ws-emoji-search input').addEventListener('input', (e) => {
                this.searchQuery = e.target.value.trim().toLowerCase();
                this._renderBody();
            });

            el.querySelectorAll('.ws-emoji-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    this.activeTab = tab.dataset.cat;
                    this.searchQuery = '';
                    el.querySelector('.ws-emoji-search input').value = '';
                    el.querySelectorAll('.ws-emoji-tab').forEach(t => t.classList.toggle('is-active', t === tab));
                    this._renderBody();
                });
            });

            const toneBtn = el.querySelector('.ws-emoji-tone-btn');
            const toneMenu = el.querySelector('.ws-emoji-tone-menu');
            toneBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toneMenu.hidden = !toneMenu.hidden;
            });
            toneMenu.querySelectorAll('[data-tone]').forEach(opt => {
                opt.addEventListener('click', () => {
                    this.tone = opt.dataset.tone;
                    setTone(this.tone);
                    el.querySelector('.ws-emoji-tone-glyph').textContent = TONE_GLYPHS[this.tone];
                    toneMenu.querySelectorAll('.ws-emoji-tone-opt').forEach(o => o.classList.toggle('is-active', o === opt));
                    toneMenu.hidden = true;
                    this._renderBody();
                });
            });

            const initial = el.querySelector('.ws-emoji-tab[data-cat="recent"]');
            if (initial) initial.classList.add('is-active');
            this._renderBody();
        }

        _renderBody() {
            const body = this.el.querySelector('.ws-emoji-body');

            if (this.searchQuery) {
                const q = this.searchQuery;
                const matches = [];
                for (const cat of CATEGORIES) {
                    if (cat.id === 'recent') continue;
                    const items = manifest.categories[cat.id] || [];
                    for (const e of items) {
                        if (e.name.includes(q)) matches.push(e);
                        if (matches.length >= 200) break;
                    }
                    if (matches.length >= 200) break;
                }
                body.innerHTML = matches.length
                    ? this._sectionHtml('Search results', matches)
                    : '<div class="ws-emoji-empty">No matches.</div>';
            } else if (this.activeTab === 'recent') {
                const recents = getRecents().map(n => ({ name: n }));
                let html = '';
                if (recents.length) html += this._sectionHtml('Frequently Used', recents, /*skipTone*/ true);
                else html += '<div class="ws-emoji-empty">Pick an emoji — it\'ll show up here.</div>';
                html += this._sectionHtml('People', manifest.categories.people || []);
                body.innerHTML = html;
            } else {
                const cat = CATEGORIES.find(c => c.id === this.activeTab);
                const items = manifest.categories[this.activeTab] || [];
                body.innerHTML = this._sectionHtml(cat.label, items);
            }

            body.querySelectorAll('.ws-emoji-item').forEach(btn => {
                btn.addEventListener('click', () => this._select(btn.dataset.name));
            });
        }

        _sectionHtml(label, entries, skipTone = false) {
            const isDefaultTone = skipTone || this.tone === 'default';
            const parts = entries.map(e => {
                const name = skipTone ? e.name : applyTone(e, this.tone);
                const lbl = nameToLabel(name);
                const inner = (isDefaultTone && e.unicode)
                    ? `<span class="ws-emoji-glyph">${e.unicode}</span>`
                    : `<img src="${svgUrl(name)}" alt="${lbl}" loading="lazy">`;
                return `<button type="button" class="ws-emoji-item" data-name="${name}" title="${lbl}">${inner}</button>`;
            });
            return `<h4 class="ws-emoji-section">${label}</h4>
                    <div class="ws-emoji-grid">${parts.join('')}</div>`;
        }

        _position() {
            if (!this.trigger) {
                this.el.style.left = '50%';
                this.el.style.top = '50%';
                this.el.style.transform = 'translate(-50%, -50%)';
                return;
            }
            const r = this.trigger.getBoundingClientRect();
            const pr = this.el.getBoundingClientRect();
            const vh = window.innerHeight, vw = window.innerWidth;
            let top = r.bottom + 8;
            let left = r.left;
            if (top + pr.height > vh) top = Math.max(8, r.top - pr.height - 8);
            if (left + pr.width > vw) left = vw - pr.width - 8;
            if (left < 8) left = 8;
            this.el.style.top = top + 'px';
            this.el.style.left = left + 'px';
        }

        _select(name) {
            addRecent(name);
            const url = svgUrl(name);
            const glyph = findGlyph(name);

            if (typeof this.opts.onSelect === 'function') {
                this.opts.onSelect({ name, url, glyph });
            } else {
                this._defaultInsert(name, url, glyph);
            }
            this.close();
        }

        _defaultInsert(name, url, glyph) {
            const t = this.target;
            if (!t) return;
            // Insert the native Unicode glyph (falls back to the SVG image only
            // for content-editable targets when no glyph exists).
            const insertText = glyph || ('');

            if (t.tagName === 'TEXTAREA' || t.tagName === 'INPUT') {
                if (!insertText) return;
                const start = t.selectionStart ?? t.value.length;
                const end = t.selectionEnd ?? t.value.length;
                t.value = t.value.slice(0, start) + insertText + t.value.slice(end);
                t.selectionStart = t.selectionEnd = start + insertText.length;
                t.focus();
                t.dispatchEvent(new Event('input', { bubbles: true }));
            } else if (t.isContentEditable) {
                t.focus();
                if (glyph) {
                    document.execCommand('insertText', false, glyph);
                } else {
                    const html = `<img class="ws-emoji" alt=":${name}:" data-emoji="${name}" src="${url}">`;
                    document.execCommand('insertHTML', false, html);
                }
            }
        }
    }

    // ---- Auto-init: clicks on [data-emoji-trigger] ----
    let active = null;
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-emoji-trigger]');
        if (!trigger) return;
        e.preventDefault();
        if (active) { active.close(); active = null; return; }

        const targetSel = trigger.dataset.emojiTarget;
        const target = targetSel ? document.querySelector(targetSel) : null;
        active = new WSEmojiPicker({ onClose: () => { active = null; } });
        active.open({ trigger, target });
    });

    window.WSEmojiPicker = WSEmojiPicker;
    window.WSEmojiPicker.findGlyph = findGlyph;
})(window, document);
