/**
 * Solih SEO Engine v4.0 — Classic Editor
 * =====================================================
 * Features:
 * - Language switcher (AR/EN/FR) with RTL/LTR
 * - Tab filtering: All / Products / Posts / Pages
 * - Refresh throttle (15s cooldown with countdown)
 * - Brand color integration
 * - Relevance score bar
 * - Safe DOM rendering (no innerHTML for data)
 * - Clipboard with fallback
 */

(function ($) {
    'use strict';

    if (typeof solihSeoClassic === 'undefined') { return; }

    var ajaxUrl = solihSeoClassic.ajaxUrl;
    var nonce = solihSeoClassic.nonce;
    var postId = solihSeoClassic.postId;
    var currentLang = solihSeoClassic.lang || 'en';
    var allTranslations = solihSeoClassic.translations || {};
    var supportedLangs = solihSeoClassic.supportedLangs || {};
    var brandColor = solihSeoClassic.brandColor || '#4F46E5';
    var throttleSeconds = parseInt(solihSeoClassic.throttleSeconds, 10) || 15;
    var wrapper = document.getElementById('solih-seo-classic-wrapper');

    if (!wrapper) { return; }

    // State
    var allSuggestions = [];
    var activeTab = 'all';
    var throttleRemaining = 0;
    var throttleTimer = null;

    /* ============================================================
       الدوال المساعدة
       ============================================================ */
    function t(key) {
        var dict = allTranslations[currentLang] || allTranslations['en'] || {};
        return dict[key] || key;
    }

    function clearWrapper() {
        while (wrapper.firstChild) { wrapper.removeChild(wrapper.firstChild); }
    }

    function copyToClipboard(text, onSuccess) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onSuccess);
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); onSuccess(); } catch (e) { }
            document.body.removeChild(ta);
        }
    }

    function getScoreColor(s) {
        if (s >= 90) return '#10B981';
        if (s >= 60) return brandColor;
        return '#f59e0b';
    }

    function translateMatchType(mt) {
        if (mt === 'both') return t('bothMatch');
        if (mt === 'tax') return t('taxMatch');
        return t('kwMatch');
    }

    function applyDirection() {
        var dir = (allTranslations[currentLang] || {}).dir || 'ltr';
        wrapper.setAttribute('dir', dir);
    }

    function applyBrandColor() {
        wrapper.style.setProperty('--solih-brand', brandColor);
    }

    /* ============================================================
       فلترة النتائج حسب التبويب النشط
       ============================================================ */
    function getFilteredSuggestions() {
        if (activeTab === 'all') return allSuggestions;
        return allSuggestions.filter(function (item) {
            var rawType = (item._rawType || item.postType || '').toLowerCase();
            return rawType === activeTab;
        });
    }

    /* ============================================================
       مُبدّل اللغة
       ============================================================ */
    function renderLangSwitcher() {
        var bar = document.createElement('div');
        bar.className = 'solih-seo-lang-bar';

        var label = document.createElement('span');
        label.className = 'solih-seo-lang-label';
        label.textContent = t('langLabel') + ':';

        var select = document.createElement('select');
        select.className = 'solih-seo-lang-select';

        Object.keys(supportedLangs).forEach(function (code) {
            var opt = document.createElement('option');
            opt.value = code;
            opt.textContent = supportedLangs[code];
            if (code === currentLang) { opt.selected = true; }
            select.appendChild(opt);
        });

        select.addEventListener('change', function () {
            currentLang = this.value;
            applyDirection();

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'solih_seo_set_language',
                    nonce: nonce,
                    lang: currentLang,
                },
            });

            // Re-render with new language
            renderUI();
        });

        bar.appendChild(label);
        bar.appendChild(select);
        return bar;
    }

    /* ============================================================
       التبويبات (Tabs)
       ============================================================ */
    function renderTabs() {
        var tabsContainer = document.createElement('div');
        tabsContainer.className = 'solih-seo-tabs';

        var tabs = [
            { key: 'all', label: t('tabAll') || 'All' },
            { key: 'product', label: t('tabProducts') || 'Products' },
            { key: 'post', label: t('tabPosts') || 'Posts' },
            { key: 'page', label: t('tabPages') || 'Pages' }
        ];

        tabs.forEach(function (tab) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'solih-seo-tab' + (activeTab === tab.key ? ' active' : '');
            btn.textContent = tab.label;

            if (activeTab === tab.key) {
                btn.style.borderBottomColor = brandColor;
                btn.style.color = brandColor;
            }

            btn.addEventListener('click', function () {
                activeTab = tab.key;
                renderUI();
            });

            tabsContainer.appendChild(btn);
        });

        return tabsContainer;
    }

    /* ============================================================
       جلب الاقتراحات
       ============================================================ */
    function fetchSuggestions() {

        clearWrapper();
        applyDirection();
        applyBrandColor();

        // Language switcher
        wrapper.appendChild(renderLangSwitcher());

        // Tabs
        wrapper.appendChild(renderTabs());

        var loadingDiv = document.createElement('div');
        loadingDiv.className = 'solih-seo-loading-container';
        var loadingP = document.createElement('p');
        loadingP.textContent = t('loading');
        loadingDiv.appendChild(loadingP);
        wrapper.appendChild(loadingDiv);

        $.ajax({
            url: ajaxUrl,
            type: 'GET',
            dataType: 'json',
            data: {
                action: 'solih_seo_suggestions',
                nonce: nonce,
                post_id: parseInt(postId, 10),
            },
            success: function (response) {
                if (response.success && response.data && Array.isArray(response.data.data)) {
                    allSuggestions = response.data.data;
                    renderUI();
                } else {
                    allSuggestions = [];
                    renderUI();
                }
            },
            error: function (xhr) {
                var msg = t('errorGeneric');
                try {
                    var p = JSON.parse(xhr.responseText);
                    if (p && p.data && p.data.message) { msg = p.data.message; }
                } catch (e) { }
                renderError(msg);
            },
        });
    }

    /* ============================================================
       إعادة رسم الواجهة بالكامل (بعد تغيير اللغة أو التبويب)
       ============================================================ */
    function renderUI() {
        clearWrapper();
        applyDirection();
        applyBrandColor();

        wrapper.appendChild(renderLangSwitcher());
        wrapper.appendChild(renderTabs());

        var filtered = getFilteredSuggestions();

        if (filtered.length === 0) {
            var emptyDiv = document.createElement('div');
            emptyDiv.className = 'solih-seo-empty-state';
            var emptyP = document.createElement('p');
            emptyP.textContent = t('empty');
            emptyDiv.appendChild(emptyP);
            wrapper.appendChild(emptyDiv);
        } else {
            renderSuggestionCards(filtered);
        }

        renderRefreshButton();
    }

    /* ============================================================
       عرض بطاقات الاقتراحات
       ============================================================ */
    function renderSuggestionCards(items) {
        var list = document.createElement('div');
        list.className = 'solih-seo-suggestions-list';

        items.forEach(function (item) {
            var card = document.createElement('div');
            card.className = 'solih-seo-suggestion-card';

            // Score bar
            var scoreBar = document.createElement('div');
            scoreBar.className = 'solih-seo-score-bar';
            var scoreFill = document.createElement('div');
            scoreFill.className = 'solih-seo-score-fill';
            var sv = Math.min(item.score || 0, 100);
            scoreFill.style.width = sv + '%';
            scoreFill.style.background = getScoreColor(sv);
            var scoreText = document.createElement('span');
            scoreText.className = 'solih-seo-score-text';
            scoreText.textContent = sv + '%';
            scoreBar.appendChild(scoreFill);
            scoreBar.appendChild(scoreText);

            // Header
            var header = document.createElement('div');
            header.className = 'solih-seo-card-header';

            var title = document.createElement('h4');
            title.className = 'solih-seo-title';
            title.textContent = item.title;

            var badges = document.createElement('div');
            badges.className = 'solih-seo-badges';

            if (item.postType) {
                var tb = document.createElement('span');
                tb.className = 'solih-seo-badge solih-seo-badge-type';
                tb.textContent = item.postType;
                badges.appendChild(tb);
            }

            var mb = document.createElement('span');
            mb.className = 'solih-seo-badge';
            mb.textContent = translateMatchType(item.matchType);
            badges.appendChild(mb);

            header.appendChild(title);
            header.appendChild(badges);

            // Copy button
            var actions = document.createElement('div');
            actions.className = 'solih-seo-card-actions';

            var copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'button solih-seo-btn';
            copyBtn.textContent = t('copyLink');

            copyBtn.addEventListener('click', (function (url, btn) {
                return function () {
                    copyToClipboard(url, function () {
                        btn.textContent = t('copied');
                        btn.classList.add('is-copied');
                        setTimeout(function () {
                            btn.textContent = t('copyLink');
                            btn.classList.remove('is-copied');
                        }, 2000);
                    });
                };
            })(item.url, copyBtn));

            actions.appendChild(copyBtn);

            card.appendChild(scoreBar);
            card.appendChild(header);
            card.appendChild(actions);
            list.appendChild(card);
        });

        wrapper.appendChild(list);
    }

    /* ============================================================
       عرض حالة الخطأ
       ============================================================ */
    function renderError(msg) {
        clearWrapper();
        applyDirection();
        applyBrandColor();

        wrapper.appendChild(renderLangSwitcher());
        wrapper.appendChild(renderTabs());

        var div = document.createElement('div');
        div.className = 'solih-seo-error-state';
        var p = document.createElement('p');
        p.textContent = '⚠ ' + msg;
        div.appendChild(p);
        wrapper.appendChild(div);
        renderRefreshButton();
    }

    /* ============================================================
       زر التحديث مع Throttle (15 ثانية)
       ============================================================ */
    function renderRefreshButton() {
        var footer = document.createElement('div');
        footer.className = 'solih-seo-footer-actions';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button button-primary solih-seo-refresh-btn';

        var isThrottled = throttleRemaining > 0;

        if (isThrottled) {
            var throttleMsg = (t('throttleMsg') || 'Wait %ss...').replace('%s', throttleRemaining);
            btn.textContent = throttleMsg;
            btn.disabled = true;
            btn.classList.add('solih-seo-btn-throttled');
        } else {
            btn.textContent = t('refresh');
            btn.style.background = 'linear-gradient(135deg, ' + brandColor + ', ' + brandColor + 'dd)';
        }

        btn.addEventListener('click', function () {
            if (throttleRemaining > 0) return;

            fetchSuggestions();

            // Start throttle
            throttleRemaining = throttleSeconds;
            startThrottleCountdown();
        });

        footer.appendChild(btn);
        wrapper.appendChild(footer);
    }

    function startThrottleCountdown() {
        if (throttleTimer) { clearInterval(throttleTimer); }

        throttleTimer = setInterval(function () {
            throttleRemaining--;
            if (throttleRemaining <= 0) {
                throttleRemaining = 0;
                clearInterval(throttleTimer);
                throttleTimer = null;
            }
            // Update the button text in place
            var btn = wrapper.querySelector('.solih-seo-refresh-btn');
            if (btn) {
                if (throttleRemaining > 0) {
                    var throttleMsg = (t('throttleMsg') || 'Wait %ss...').replace('%s', throttleRemaining);
                    btn.textContent = throttleMsg;
                    btn.disabled = true;
                    btn.classList.add('solih-seo-btn-throttled');
                } else {
                    btn.textContent = t('refresh');
                    btn.disabled = false;
                    btn.classList.remove('solih-seo-btn-throttled');
                    btn.style.background = 'linear-gradient(135deg, ' + brandColor + ', ' + brandColor + 'dd)';
                }
            }
        }, 1000);
    }

    /* ============================================================
       تشغيل
       ============================================================ */
    $(document).ready(function () {
        if (postId && parseInt(postId, 10) > 0) {
            fetchSuggestions();
        }
    });

})(jQuery);
