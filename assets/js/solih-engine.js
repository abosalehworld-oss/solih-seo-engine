/**
 * Solih SEO Engine v4.0 — Gutenberg Sidebar
 * =====================================================
 * Features:
 * - Language switcher (AR/EN/FR) with instant RTL/LTR switching
 * - Tab filtering: All / Products / Posts / Pages
 * - Refresh throttle (15s cooldown with countdown)
 * - Brand color integration from settings
 * - Relevance score bar (green/blue/gold)
 * - Clipboard with fallback
 * - React-based components
 */

(function (wp) {
    'use strict';

    if (!wp || !wp.plugins || !wp.editPost || !wp.element || !wp.components || !wp.data || !wp.apiFetch) {
        return;
    }

    var registerPlugin = wp.plugins.registerPlugin;
    var PluginSidebar = wp.editPost.PluginSidebar;
    var PanelBody = wp.components.PanelBody;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;
    var SelectControl = wp.components.SelectControl;
    var useSelect = wp.data.useSelect;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var apiFetch = wp.apiFetch;

    /* ============================================================
       البيانات المُمرَّرة من PHP
       ============================================================ */
    var config = window.solihSeoData || {};
    var allTranslations = config.translations || {};
    var supportedLangs = config.supportedLangs || {};
    var brandColor = config.brandColor || '#4F46E5';
    var throttleSeconds = parseInt(config.throttleSeconds, 10) || 15;

    /* ============================================================
       Tab type mapping
       ============================================================ */
    var TAB_TYPES = {
        all: null,
        product: 'product',
        post: 'post',
        page: 'page'
    };

    /* ============================================================
       أيقونة النسخ
       ============================================================ */
    var CopyIcon = el('svg',
        { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 16, height: 16, fill: 'currentColor' },
        el('path', { d: 'M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z' })
    );

    /* ============================================================
       Clipboard Fallback
       ============================================================ */
    function copyToClipboard(text, onSuccess) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onSuccess);
        } else {
            var t = document.createElement('textarea');
            t.value = text;
            t.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(t);
            t.select();
            try { document.execCommand('copy'); onSuccess(); } catch (e) { }
            document.body.removeChild(t);
        }
    }

    /* ============================================================
       ترجمة نوع المطابقة حسب اللغة الحالية
       ============================================================ */
    function translateMatchType(matchType, t) {
        if (matchType === 'both') return t.bothMatch;
        if (matchType === 'tax') return t.taxMatch;
        return t.kwMatch;
    }

    /* ============================================================
       لون شريط النقاط — يستخدم اللون الرئيسي
       ============================================================ */
    function getScoreColor(score) {
        if (score >= 90) return '#10B981';
        if (score >= 60) return brandColor;
        return '#f59e0b';
    }

    /* ============================================================
       المكوّن الرئيسي
       ============================================================ */
    function SolihSeoPanel() {

        var suggestionsState = useState([]);
        var loadingState = useState(false);
        var loadedState = useState(false);
        var errorState = useState('');
        var copiedState = useState(null);
        var langState = useState(config.lang || 'en');
        var activeTabState = useState('all');
        var throttleState = useState(0);
        var throttleTimerRef = useRef(null);

        var suggestions = suggestionsState[0], setSuggestions = suggestionsState[1];
        var isLoading = loadingState[0], setIsLoading = loadingState[1];
        var hasLoaded = loadedState[0], setHasLoaded = loadedState[1];
        var errorMsg = errorState[0], setErrorMsg = errorState[1];
        var copiedId = copiedState[0], setCopiedId = copiedState[1];
        var currentLang = langState[0], setCurrentLang = langState[1];
        var activeTab = activeTabState[0], setActiveTab = activeTabState[1];
        var throttleRemaining = throttleState[0], setThrottleRemaining = throttleState[1];

        // القاموس الحالي
        var t = allTranslations[currentLang] || allTranslations['en'] || {};

        var postId = useSelect(function (select) {
            return select('core/editor').getCurrentPostId();
        }, []);

        /* ============================================================
           Throttle countdown effect
           ============================================================ */
        useEffect(function () {
            if (throttleRemaining <= 0) {
                if (throttleTimerRef.current) {
                    clearInterval(throttleTimerRef.current);
                    throttleTimerRef.current = null;
                }
                return;
            }
            throttleTimerRef.current = setInterval(function () {
                setThrottleRemaining(function (prev) {
                    if (prev <= 1) {
                        clearInterval(throttleTimerRef.current);
                        throttleTimerRef.current = null;
                        return 0;
                    }
                    return prev - 1;
                });
            }, 1000);
            return function () {
                if (throttleTimerRef.current) {
                    clearInterval(throttleTimerRef.current);
                }
            };
        }, [throttleRemaining > 0]);

        /* ============================================================
           جلب الاقتراحات
           ============================================================ */
        function fetchSuggestions() {
            if (!postId || parseInt(postId, 10) <= 0) { return; }
            setIsLoading(true);
            setErrorMsg('');

            apiFetch({ path: '/solih-seo/v1/suggestions?post_id=' + parseInt(postId, 10) })
                .then(function (res) {
                    setSuggestions(res && res.success && Array.isArray(res.data) ? res.data : []);
                    setHasLoaded(true);
                    setIsLoading(false);
                })
                .catch(function (err) {
                    setErrorMsg((err && err.message) || t.errorGeneric);
                    setIsLoading(false);
                    setHasLoaded(true);
                });
        }

        function handleRefresh() {
            fetchSuggestions();
            setThrottleRemaining(throttleSeconds);
        }

        useEffect(function () { fetchSuggestions(); }, [postId]);

        /* ============================================================
           تغيير اللغة
           ============================================================ */
        function handleLangChange(newLang) {
            setCurrentLang(newLang);

            apiFetch({
                path: '/solih-seo/v1/language',
                method: 'POST',
                data: { lang: newLang },
            }).catch(function () { });
        }

        /* ============================================================
           نسخ الرابط
           ============================================================ */
        function handleCopy(url, id) {
            copyToClipboard(url, function () {
                setCopiedId(id);
                setTimeout(function () { setCopiedId(null); }, 2000);
            });
        }

        /* ============================================================
           فلترة النتائج حسب التبويب النشط
           ============================================================ */
        function getFilteredSuggestions() {
            if (activeTab === 'all') {
                return suggestions;
            }
            var targetType = TAB_TYPES[activeTab];
            if (!targetType) return suggestions;
            return suggestions.filter(function (item) {
                // Match against the raw post type slug
                var rawType = (item._rawType || item.postType || '').toLowerCase();
                return rawType === targetType;
            });
        }

        /* ============================================================
           بناء التبويبات
           ============================================================ */
        function renderTabs() {
            var tabs = [
                { key: 'all', label: t.tabAll || 'All' },
                { key: 'product', label: t.tabProducts || 'Products' },
                { key: 'post', label: t.tabPosts || 'Posts' },
                { key: 'page', label: t.tabPages || 'Pages' }
            ];

            return el('div', { className: 'solih-seo-tabs', style: { '--solih-brand': brandColor } },
                tabs.map(function (tab) {
                    var isActive = activeTab === tab.key;
                    return el('button', {
                        key: tab.key,
                        type: 'button',
                        className: 'solih-seo-tab' + (isActive ? ' active' : ''),
                        onClick: function () { setActiveTab(tab.key); },
                        style: isActive ? { borderBottomColor: brandColor, color: brandColor } : {}
                    }, tab.label);
                })
            );
        }

        /* ============================================================
           بناء المحتوى
           ============================================================ */
        function renderContent() {

            if (isLoading) {
                return el('div', { className: 'solih-seo-loading-container' },
                    el(Spinner, null),
                    el('p', null, t.loading)
                );
            }

            if (errorMsg) {
                return el('div', { className: 'solih-seo-error-state' },
                    el('p', null, '⚠ ' + errorMsg)
                );
            }

            var filtered = getFilteredSuggestions();

            if (hasLoaded && filtered.length === 0) {
                return el('div', { className: 'solih-seo-empty-state' },
                    el('p', null, t.empty)
                );
            }

            return el('div', { className: 'solih-seo-suggestions-list' },
                filtered.map(function (item) {
                    var isCopied = copiedId === item.id;
                    var scoreColor = getScoreColor(item.score || 0);
                    var scoreWidth = Math.min(item.score || 0, 100);

                    return el('div', { key: item.id, className: 'solih-seo-suggestion-card' },

                        el('div', { className: 'solih-seo-score-bar' },
                            el('div', {
                                className: 'solih-seo-score-fill',
                                style: { width: scoreWidth + '%', background: scoreColor },
                            }),
                            el('span', { className: 'solih-seo-score-text' }, item.score + '%')
                        ),

                        el('div', { className: 'solih-seo-card-header' },
                            el('h4', { className: 'solih-seo-title' }, item.title),
                            el('div', { className: 'solih-seo-badges' },
                                item.postType
                                    ? el('span', { className: 'solih-seo-badge solih-seo-badge-type' }, item.postType)
                                    : null,
                                el('span', { className: 'solih-seo-badge' },
                                    translateMatchType(item.matchType, t)
                                )
                            )
                        ),

                        el('div', { className: 'solih-seo-card-actions' },
                            el(Button, {
                                variant: 'secondary',
                                onClick: function () { handleCopy(item.url, item.id); },
                                className: isCopied ? 'solih-seo-btn is-copied' : 'solih-seo-btn',
                                icon: isCopied ? null : CopyIcon,
                            }, isCopied ? t.copied : t.copyLink)
                        )
                    );
                })
            );
        }

        /* ============================================================
           زر التحديث مع الـ Throttle
           ============================================================ */
        function renderRefreshButton() {
            var isThrottled = throttleRemaining > 0;
            var btnLabel = isThrottled
                ? (t.throttleMsg || 'Wait %ss...').replace('%s', throttleRemaining)
                : t.refresh;

            return el('div', { className: 'solih-seo-footer-actions' },
                el(Button, {
                    variant: 'primary',
                    onClick: handleRefresh,
                    isBusy: isLoading,
                    disabled: isThrottled || isLoading,
                    className: isThrottled ? 'solih-seo-btn-throttled' : '',
                    style: !isThrottled ? {
                        background: 'linear-gradient(135deg, ' + brandColor + ', ' + brandColor + 'dd)',
                    } : {}
                }, btnLabel)
            );
        }

        /* ============================================================
           البناء النهائي مع مُبدّل اللغة والتبويبات
           ============================================================ */
        var langOptions = Object.keys(supportedLangs).map(function (code) {
            return { label: supportedLangs[code], value: code };
        });

        return el(Fragment, null,
            el(PluginSidebar, {
                name: 'solih-seo-engine-sidebar',
                icon: 'admin-links',
                title: 'Solih SEO Engine',
            },
                // ===  مُبدّل اللغة ===
                el(PanelBody, { title: t.langLabel, initialOpen: false, className: 'solih-seo-lang-panel' },
                    el(SelectControl, {
                        value: currentLang,
                        options: langOptions,
                        onChange: handleLangChange,
                        __nextHasNoMarginBottom: true,
                    })
                ),

                // === اقتراحات الروابط ===
                el(PanelBody, { title: t.panelTitle, initialOpen: true },
                    el('div', {
                        className: 'solih-seo-sidebar-wrapper',
                        dir: t.dir,
                        style: { '--solih-brand': brandColor },
                    },
                        renderTabs(),
                        renderContent(),
                        renderRefreshButton()
                    )
                )
            )
        );
    }

    registerPlugin('solih-seo-engine', { render: SolihSeoPanel });

})(window.wp);
