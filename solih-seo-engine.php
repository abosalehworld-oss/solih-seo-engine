<?php
/**
 * Plugin Name: Solih SEO Engine
 * Plugin URI:  https://github.com/abosalehworld-oss/solih-seo-engine
 * Description: The smartest internal linking engine for WordPress — supports articles, products, and all content types with AR/EN/FR interface.
 * Version:     4.0.0
 * Author:      Mohamed Saleh
 * Author URI:  https://linkedin.com/in/mr-mohamed-saleh
 * Text Domain: solih-seo-engine
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

/* ============================================================
 حماية الوصول المباشر
 ============================================================ */
if (!defined('ABSPATH')) {
    exit;
}

/* ============================================================
 ثوابت الإضافة
 ============================================================ */
define('SOLIH_SEO_VERSION', '4.0.0');
define('SOLIH_SEO_FILE', __FILE__);
define('SOLIH_SEO_DIR', plugin_dir_path(__FILE__));
define('SOLIH_SEO_URL', plugin_dir_url(__FILE__));
define('SOLIH_SEO_CACHE_TTL', 12 * HOUR_IN_SECONDS);
define('SOLIH_SEO_MAX_RESULTS', 10);
define('SOLIH_SEO_THROTTLE_SECONDS', 15);

/* ============================================================
 هوكات دورة حياة الإضافة (Plugin Lifecycle)
 ============================================================ */

// === التفعيل (Activation) ===
register_activation_hook(__FILE__, 'solih_seo_on_activate');

function solih_seo_on_activate()
{
    // فحص القدرات — فقط المسؤولين يمكنهم تفعيل الإضافات
    if (!current_user_can('activate_plugins')) {
        return;
    }

    $defaults = array(
        'version' => SOLIH_SEO_VERSION,
        'cache_ttl' => SOLIH_SEO_CACHE_TTL,
        'max_results' => SOLIH_SEO_MAX_RESULTS,
        'installed_at' => current_time('mysql'),
    );

    add_option('solih_seo_settings', $defaults);
    update_option('solih_seo_version', SOLIH_SEO_VERSION);

    // Initialize plugin settings with defaults
    $plugin_defaults = array(
        'brand_color' => '#4F46E5',
        'active_post_types' => array(),
        'blacklist_ids' => '',
    );
    add_option('solih_seo_plugin_settings', $plugin_defaults);
}

// === التعطيل (Deactivation) ===
register_deactivation_hook(__FILE__, 'solih_seo_on_deactivate');

function solih_seo_on_deactivate()
{
    // فحص القدرات
    if (!current_user_can('activate_plugins')) {
        return;
    }

    global $wpdb;

    // استخدام LIKE مع wpdb->esc_like للحماية من SQL Injection
    $like_transient = $wpdb->esc_like('_transient_solih_seo_') . '%';
    $like_timeout = $wpdb->esc_like('_transient_timeout_solih_seo_') . '%';

    $wpdb->query(
        $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $like_transient,
        $like_timeout
    )
    );
}

/* ============================================================
 الكلاس الرئيسي: Solih_SEO_Engine
 ============================================================ */
class Solih_SEO_Engine
{

    /* --------------------------------------------------------
     اللغات المدعومة وقاموس الترجمة المُضمّن
     -------------------------------------------------------- */
    private static $supported_langs = array('ar', 'en', 'fr');

    private static $translations = array(
        'ar' => array(
            'panelTitle' => 'اقتراحات الروابط الداخلية',
            'loading' => 'جاري تحليل المحتوى واكتشاف الروابط...',
            'empty' => 'لا توجد روابط مقترحة حالياً. أضف المزيد من المحتوى لتفعيل الاكتشاف الذكي.',
            'errorGeneric' => 'حدث خطأ غير متوقع.',
            'copyLink' => 'نسخ الرابط',
            'copied' => '✓ تم النسخ',
            'refresh' => 'تحديث الاقتراحات',
            'langLabel' => 'اللغة',
            'taxMatch' => 'تطابق تصنيفات',
            'kwMatch' => 'تطابق كلمات',
            'bothMatch' => 'تطابق مزدوج',
            'dir' => 'rtl',
            'tabAll' => 'الكل',
            'tabProducts' => 'المنتجات',
            'tabPosts' => 'المقالات',
            'tabPages' => 'الصفحات',
            'throttleMsg' => 'انتظر %s ث...',
        ),
        'en' => array(
            'panelTitle' => 'Internal Link Suggestions',
            'loading' => 'Analyzing content and discovering links...',
            'empty' => 'No suggestions found. Add more content to activate smart discovery.',
            'errorGeneric' => 'An unexpected error occurred.',
            'copyLink' => 'Copy Link',
            'copied' => '✓ Copied',
            'refresh' => 'Refresh Suggestions',
            'langLabel' => 'Language',
            'taxMatch' => 'Taxonomy Match',
            'kwMatch' => 'Keyword Match',
            'bothMatch' => 'Taxonomy + Keyword',
            'dir' => 'ltr',
            'tabAll' => 'All',
            'tabProducts' => 'Products',
            'tabPosts' => 'Posts',
            'tabPages' => 'Pages',
            'throttleMsg' => 'Wait %ss...',
        ),
        'fr' => array(
            'panelTitle' => 'Suggestions de liens internes',
            'loading' => 'Analyse du contenu et découverte des liens...',
            'empty' => 'Aucune suggestion trouvée. Ajoutez du contenu pour activer la découverte.',
            'errorGeneric' => 'Une erreur inattendue est survenue.',
            'copyLink' => 'Copier le lien',
            'copied' => '✓ Copié',
            'refresh' => 'Actualiser',
            'langLabel' => 'Langue',
            'taxMatch' => 'Taxonomie',
            'kwMatch' => 'Mots-clés',
            'bothMatch' => 'Taxonomie + Mots-clés',
            'dir' => 'ltr',
            'tabAll' => 'Tout',
            'tabProducts' => 'Produits',
            'tabPosts' => 'Articles',
            'tabPages' => 'Pages',
            'throttleMsg' => 'Patientez %ss...',
        ),
    );

    /* --------------------------------------------------------
     Singleton
     -------------------------------------------------------- */
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->load_textdomain();
        $this->register_hooks();
    }

    private function __clone()
    {
    }
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize Singleton.');
    }

    /* ============================================================
     تحميل ملفات الترجمة
     ============================================================ */
    private function load_textdomain()
    {
        load_plugin_textdomain(
            'solih-seo-engine',
            false,
            dirname(plugin_basename(SOLIH_SEO_FILE)) . '/languages'
        );
    }

    /* ============================================================
     جلب لغة المستخدم الحالي (مع تحقق مزدوج)
     ============================================================ */
    private function get_user_language()
    {
        $user_id = get_current_user_id();
        $saved = $user_id ? get_user_meta($user_id, 'solih_seo_language', true) : '';

        // تحقق صارم: whitelist فقط
        if (is_string($saved) && in_array($saved, self::$supported_langs, true)) {
            return $saved;
        }

        $locale = get_locale();
        $short = substr($locale, 0, 2);

        return in_array($short, self::$supported_langs, true) ? $short : 'en';
    }

    /* ============================================================
     جلب إعدادات البلجن (مع type casting آمن)
     ============================================================ */
    private function get_plugin_settings()
    {
        $defaults = array(
            'brand_color' => '#4F46E5',
            'active_post_types' => array(),
            'blacklist_ids' => '',
        );
        $raw = get_option('solih_seo_plugin_settings', $defaults);

        // حماية من التلاعب بنوع البيانات
        if (!is_array($raw)) {
            return $defaults;
        }

        $settings = wp_parse_args($raw, $defaults);

        // إعادة تطهير القيم عند القراءة (Defense in Depth)
        $settings['brand_color'] = sanitize_hex_color($settings['brand_color']) ?: '#4F46E5';

        if (!is_array($settings['active_post_types'])) {
            $settings['active_post_types'] = array();
        }

        if (!is_string($settings['blacklist_ids'])) {
            $settings['blacklist_ids'] = '';
        }

        return $settings;
    }

    /* ============================================================
     تسجيل الهوكات
     ============================================================ */
    private function register_hooks()
    {
        // Editor assets
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_gutenberg_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_classic_assets'));
        add_action('add_meta_boxes', array($this, 'register_classic_metabox'));

        // REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // AJAX
        add_action('wp_ajax_solih_seo_suggestions', array($this, 'handle_ajax_suggestions'));
        add_action('wp_ajax_solih_seo_set_language', array($this, 'handle_ajax_set_language'));

        // Cache invalidation
        add_action('save_post', array($this, 'on_post_save_clear_cache'), 10, 2);

        // Settings page
        add_action('admin_menu', array($this, 'register_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_settings_assets'));
    }

    /* ============================================================
     كشف المحرر النشط
     ============================================================ */
    private function is_gutenberg_active()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return false;
        }
        return method_exists($screen, 'is_block_editor') && $screen->is_block_editor();
    }

    /* ============================================================
     البيانات المشتركة المُمرَّرة لـ JavaScript
     ============================================================ */
    private function get_js_data()
    {
        $lang = $this->get_user_language();
        $settings = $this->get_plugin_settings();

        return array(
            'lang' => esc_attr($lang),
            'dir' => esc_attr(self::$translations[$lang]['dir']),
            'translations' => self::$translations,
            'supportedLangs' => array(
                'ar' => 'العربية',
                'en' => 'English',
                'fr' => 'Français',
            ),
            'brandColor' => esc_attr($settings['brand_color']),
            'throttleSeconds' => absint(SOLIH_SEO_THROTTLE_SECONDS),
        );
    }

    /* ============================================================
     تحميل ملفات جوتنبرج
     ============================================================ */
    public function enqueue_gutenberg_assets()
    {
        $css_path = SOLIH_SEO_DIR . 'assets/css/solih-engine.css';
        wp_enqueue_style(
            'solih-engine-style',
            SOLIH_SEO_URL . 'assets/css/solih-engine.css',
            array('wp-components'),
            file_exists($css_path) ? filemtime($css_path) : SOLIH_SEO_VERSION
        );

        $js_path = SOLIH_SEO_DIR . 'assets/js/solih-engine.js';
        wp_enqueue_script(
            'solih-engine-script',
            SOLIH_SEO_URL . 'assets/js/solih-engine.js',
            array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch'),
            file_exists($js_path) ? filemtime($js_path) : SOLIH_SEO_VERSION,
            true
        );

        wp_localize_script('solih-engine-script', 'solihSeoData', array_merge(
            array(
            'restUrl' => esc_url_raw(rest_url('solih-seo/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ),
            $this->get_js_data()
        ));
    }

    /* ============================================================
     تحميل ملفات المحرر الكلاسيكي
     ============================================================ */
    public function enqueue_classic_assets($hook_suffix)
    {
        if (!in_array($hook_suffix, array('post.php', 'post-new.php'), true)) {
            return;
        }
        if ($this->is_gutenberg_active()) {
            return;
        }

        $css_path = SOLIH_SEO_DIR . 'assets/css/solih-engine.css';
        wp_enqueue_style(
            'solih-engine-classic-style',
            SOLIH_SEO_URL . 'assets/css/solih-engine.css',
            array(),
            file_exists($css_path) ? filemtime($css_path) : SOLIH_SEO_VERSION
        );

        $js_path = SOLIH_SEO_DIR . 'assets/js/solih-classic.js';
        wp_enqueue_script(
            'solih-engine-classic-script',
            SOLIH_SEO_URL . 'assets/js/solih-classic.js',
            array('jquery'),
            file_exists($js_path) ? filemtime($js_path) : SOLIH_SEO_VERSION,
            true
        );

        wp_localize_script('solih-engine-classic-script', 'solihSeoClassic', array_merge(
            array(
            'ajaxUrl' => esc_url_raw(admin_url('admin-ajax.php')),
            'nonce' => wp_create_nonce('solih_seo_classic_nonce'),
            'postId' => absint(get_the_ID()),
        ),
            $this->get_js_data()
        ));
    }

    /* ============================================================
     صندوق الميتا (Classic Editor)
     ============================================================ */
    public function register_classic_metabox()
    {
        $settings = $this->get_plugin_settings();
        $active_types = $settings['active_post_types'];

        // If no active types configured, use all public types
        if (empty($active_types)) {
            $post_types = get_post_types(array('public' => true), 'names');
            unset($post_types['attachment']);
        }
        else {
            // تحقق أن الأنواع المحددة لا تزال موجودة ومسجلة
            $all_public = get_post_types(array('public' => true), 'names');
            $post_types = array_intersect($active_types, array_keys($all_public));
        }

        foreach ($post_types as $pt) {
            add_meta_box(
                'solih-seo-engine-metabox',
                '🔗 Solih SEO Engine',
                array($this, 'render_classic_metabox'),
                sanitize_key($pt),
                'side',
                'high'
            );
        }
    }

    public function render_classic_metabox($post)
    {
        // Nonce output مخفي لحماية CSRF الإضافية
        wp_nonce_field('solih_seo_metabox_nonce', 'solih_seo_metabox_nonce_field');

        echo '<div id="solih-seo-classic-wrapper" class="solih-seo-sidebar-wrapper">';
        echo '<div class="solih-seo-loading-container">';
        echo '<p>' . esc_html__('Loading suggestions...', 'solih-seo-engine') . '</p>';
        echo '</div>';
        echo '</div>';
    }

    /* ============================================================
     REST API Routes
     ============================================================ */
    public function register_rest_routes()
    {
        $edit_perm = function () {
            return current_user_can('edit_posts');
        };

        // === اقتراحات الروابط ===
        register_rest_route('solih-seo/v1', '/suggestions', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'handle_rest_suggestions'),
            'permission_callback' => $edit_perm,
            'args' => array(
                'post_id' => array(
                    'required' => true,
                    'validate_callback' => function ($v) {
                return is_numeric($v) && intval($v) > 0;
            },
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // === حفظ تفضيل اللغة ===
        register_rest_route('solih-seo/v1', '/language', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'handle_rest_set_language'),
            'permission_callback' => $edit_perm,
            'args' => array(
                'lang' => array(
                    'required' => true,
                    'validate_callback' => function ($v) {
                return in_array($v, array('ar', 'en', 'fr'), true);
            },
                    'sanitize_callback' => 'sanitize_key',
                ),
            ),
        ));
    }

    /* ============================================================
     REST: اقتراحات الروابط
     ============================================================ */
    public function handle_rest_suggestions(\WP_REST_Request $request)
    {
        $post_id = absint($request->get_param('post_id'));

        // تحقق إضافي: الحد الأقصى لل ID (حماية من قيم عشوائية كبيرة)
        if ($post_id > PHP_INT_MAX / 2) {
            return new \WP_Error('solih_invalid_id', __('Invalid post ID.', 'solih-seo-engine'), array('status' => 400));
        }

        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error('solih_not_found', __('Content not found.', 'solih-seo-engine'), array('status' => 404));
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new \WP_Error('solih_forbidden', __('Access denied.', 'solih-seo-engine'), array('status' => 403));
        }

        return rest_ensure_response(array(
            'success' => true,
            'data' => $this->get_suggestions_cached($post),
        ));
    }

    /* ============================================================
     REST: حفظ تفضيل اللغة (مع تحقق مزدوج)
     ============================================================ */
    public function handle_rest_set_language(\WP_REST_Request $request)
    {
        $lang = sanitize_key($request->get_param('lang'));
        $user_id = get_current_user_id();

        if (!$user_id) {
            return new \WP_Error('solih_no_user', __('Not logged in.', 'solih-seo-engine'), array('status' => 401));
        }

        // تحقق مزدوج بعد التطهير — Whitelist فقط
        if (!in_array($lang, self::$supported_langs, true)) {
            return new \WP_Error('solih_invalid_lang', __('Invalid language.', 'solih-seo-engine'), array('status' => 400));
        }

        update_user_meta($user_id, 'solih_seo_language', $lang);

        return rest_ensure_response(array(
            'success' => true,
            'lang' => esc_attr($lang),
            'dir' => esc_attr(self::$translations[$lang]['dir']),
        ));
    }

    /* ============================================================
     AJAX: اقتراحات الروابط (Classic Editor)
     ============================================================ */
    public function handle_ajax_suggestions()
    {
        if (!check_ajax_referer('solih_seo_classic_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Invalid security token.', 'solih-seo-engine')), 403);
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Access denied.', 'solih-seo-engine')), 403);
        }

        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if ($post_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid post ID.', 'solih-seo-engine')), 400);
        }

        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            wp_send_json_error(array('message' => __('Content not found.', 'solih-seo-engine')), 404);
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Access denied.', 'solih-seo-engine')), 403);
        }

        wp_send_json_success(array(
            'data' => $this->get_suggestions_cached($post),
        ));
    }

    /* ============================================================
     AJAX: حفظ تفضيل اللغة (Classic Editor)
     ============================================================ */
    public function handle_ajax_set_language()
    {
        if (!check_ajax_referer('solih_seo_classic_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Invalid security token.', 'solih-seo-engine')), 403);
        }

        $lang = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
        $user_id = get_current_user_id();

        // Whitelist + User check
        if (!in_array($lang, self::$supported_langs, true) || !$user_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'solih-seo-engine')), 400);
        }

        update_user_meta($user_id, 'solih_seo_language', $lang);

        wp_send_json_success(array(
            'lang' => esc_attr($lang),
            'dir' => esc_attr(self::$translations[$lang]['dir']),
        ));
    }

    /* ============================================================
     طبقة الكاش (مع تحقق من سلامة البيانات المخزنة)
     ============================================================ */
    private function get_suggestions_cached(\WP_Post $post)
    {
        $cache_key = 'solih_seo_' . absint($post->ID);
        $cached = get_transient($cache_key);

        // تحقق أن الكاش هو مصفوفة فعلاً (حماية من التلاعب)
        if (is_array($cached) && !empty($cached)) {
            // تحقق من بنية أول عنصر
            $first = reset($cached);
            if (is_array($first) && isset($first['id'], $first['title'], $first['url'])) {
                return $cached;
            }
            // الكاش تالف — نحذفه ونعيد البناء
            delete_transient($cache_key);
        }

        if (is_array($cached) && empty($cached)) {
            return $cached; // مصفوفة فارغة صالحة
        }

        $suggestions = $this->discover_related_content($post);
        $ttl = apply_filters('solih_seo_cache_ttl', SOLIH_SEO_CACHE_TTL, $post);

        // تأكد أن TTL ضمن حدود معقولة (1 دقيقة — 7 أيام)
        $ttl = max(MINUTE_IN_SECONDS, min($ttl, 7 * DAY_IN_SECONDS));

        set_transient($cache_key, $suggestions, $ttl);

        return $suggestions;
    }

    /* ============================================================
     خوارزمية الاكتشاف الذكي v4 (Enhanced Scoring + Filters)
     ============================================================ */
    private function discover_related_content(\WP_Post $post)
    {
        $settings = $this->get_plugin_settings();

        // Active post types from settings, fallback to all public
        $active_types = $settings['active_post_types'];
        if (empty($active_types)) {
            $post_types = get_post_types(array('public' => true), 'names');
            unset($post_types['attachment']);
            $post_types = array_values($post_types);
        }
        else {
            // تحقق أن الأنواع لا تزال مسجلة
            $all_registered = get_post_types(array('public' => true), 'names');
            $post_types = array_values(array_intersect($active_types, array_keys($all_registered)));
        }
        $post_types = apply_filters('solih_seo_post_types', $post_types, $post);

        // تطهير أنواع المحتوى بعد الفلتر
        $post_types = array_map('sanitize_key', $post_types);
        $post_types = array_filter($post_types);
        if (empty($post_types)) {
            return array();
        }

        // Blacklist IDs
        $blacklist_raw = $settings['blacklist_ids'];
        $blacklist_ids = array();
        if (!empty($blacklist_raw) && is_string($blacklist_raw)) {
            $blacklist_ids = array_filter(array_map('absint', explode(',', $blacklist_raw)));
        }

        $max = apply_filters('solih_seo_max_results', SOLIH_SEO_MAX_RESULTS, $post);
        $max = absint($max);
        $max = max(1, min($max, 50)); // حدود معقولة: 1-50

        // Combine the current post ID with blacklist for exclusion
        $exclude_ids = array_unique(array_merge(array(absint($post->ID)), $blacklist_ids));

        $tax_results = $this->find_by_taxonomy($post->ID, $post->post_type, $post_types, $exclude_ids);
        $kw_results = $this->find_by_keywords($post, $post_types, $exclude_ids);

        $merged = $this->merge_and_score($tax_results, $kw_results, $post);

        usort($merged, function ($a, $b) {
            return intval($b['score']) - intval($a['score']);
        });

        // Filter out results with score below 50
        $merged = array_filter($merged, function ($item) {
            return intval($item['score']) >= 50;
        });

        $merged = array_slice(array_values($merged), 0, $max);

        return apply_filters('solih_seo_suggestions', $merged, $post);
    }

    /* ============================================================
     دمج النتائج ونظام التقييم المحسّن v4
     Taxonomy + Keyword = 100 | Taxonomy = 75 | Keyword = 60
     Same post type bonus = +15
     Freshness bonus (< 30 days) = +10
     Title match bonus = +10
     ============================================================ */
    private function merge_and_score($tax_results, $kw_results, $source_post)
    {
        $merged = array();
        $current_type = sanitize_key($source_post->post_type);
        $source_title_lower = mb_strtolower(wp_strip_all_tags($source_post->post_title));

        foreach ($tax_results as $id => $item) {
            $id = absint($id);
            $score = 75;
            if (isset($item['_rawType']) && sanitize_key($item['_rawType']) === $current_type) {
                $score += 15;
            }
            $score += $this->calc_freshness_bonus($item);
            $score += $this->calc_title_match_bonus($source_title_lower, $item);

            $merged[$id] = $item;
            $merged[$id]['score'] = min($score, 100);
            $merged[$id]['matchType'] = 'tax';
        }

        foreach ($kw_results as $id => $item) {
            $id = absint($id);
            if (isset($merged[$id])) {
                // Double match — highest score
                $merged[$id]['score'] = 100;
                $merged[$id]['matchType'] = 'both';
            }
            else {
                $score = 60;
                if (isset($item['_rawType']) && sanitize_key($item['_rawType']) === $current_type) {
                    $score += 15;
                }
                $score += $this->calc_freshness_bonus($item);
                $score += $this->calc_title_match_bonus($source_title_lower, $item);

                $merged[$id] = $item;
                $merged[$id]['score'] = min($score, 100);
                $merged[$id]['matchType'] = 'kw';
            }
        }

        // Clean internal fields — لا نرسلها للـ frontend
        foreach ($merged as &$item) {
            unset($item['_rawType'], $item['_publishDate']);
        }

        return array_values($merged);
    }

    /* ============================================================
     مكافأة الحداثة: +10 إذا كان المنشور أحدث من 30 يوماً
     ============================================================ */
    private function calc_freshness_bonus($item)
    {
        if (empty($item['_publishDate']) || !is_string($item['_publishDate'])) {
            return 0;
        }
        $pub_time = strtotime($item['_publishDate']);
        if (!$pub_time || $pub_time > time()) {
            return 0; // تاريخ مستقبلي أو غير صالح
        }
        $days_ago = (time() - $pub_time) / DAY_IN_SECONDS;
        return ($days_ago <= 30) ? 10 : 0;
    }

    /* ============================================================
     مكافأة تطابق العنوان: +10 إذا كان هناك كلمة مشتركة مهمة
     ============================================================ */
    private function calc_title_match_bonus($source_title_lower, $item)
    {
        if (empty($item['title']) || !is_string($item['title'])) {
            return 0;
        }
        $target_title_lower = mb_strtolower(wp_strip_all_tags($item['title']));

        // Extract significant words (> 3 chars) from source title
        $source_words = preg_split('/[^\p{L}\p{N}]+/u', $source_title_lower, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($source_words)) {
            return 0;
        }
        $source_words = array_filter($source_words, function ($w) {
            return mb_strlen($w) > 3;
        });

        foreach ($source_words as $word) {
            if (mb_strpos($target_title_lower, $word) !== false) {
                return 10;
            }
        }
        return 0;
    }

    /* ============================================================
     المرحلة 1: التصنيفات العامة فقط
     ============================================================ */
    private function find_by_taxonomy($post_id, $post_type, $post_types, $exclude_ids = array())
    {
        $results = array();
        $post_id = absint($post_id);
        $all_taxonomies = get_object_taxonomies(sanitize_key($post_type), 'objects');
        $public_taxes = array();

        foreach ($all_taxonomies as $tax_obj) {
            if ($tax_obj->public || $tax_obj->publicly_queryable) {
                $public_taxes[] = sanitize_key($tax_obj->name);
            }
        }

        if (empty($public_taxes)) {
            return $results;
        }

        $tax_query = array('relation' => 'OR');

        foreach ($public_taxes as $taxonomy) {
            $term_ids = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'ids'));
            if (is_wp_error($term_ids) || empty($term_ids)) {
                continue;
            }

            $tax_query[] = array(
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => array_map('absint', $term_ids),
            );
        }

        if (count($tax_query) <= 1) {
            return $results;
        }

        $query = new \WP_Query(array(
            'post_type' => array_map('sanitize_key', $post_types),
            'post_status' => 'publish',
            'posts_per_page' => absint(SOLIH_SEO_MAX_RESULTS),
            'post__not_in' => array_map('absint', $exclude_ids),
            'tax_query' => $tax_query,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        foreach ($query->posts as $p) {
            $results[absint($p->ID)] = $this->format_suggestion($p);
        }

        wp_reset_postdata();
        return $results;
    }

    /* ============================================================
     المرحلة 2: Unicode Keywords (AR, EN, FR, etc.)
     ============================================================ */
    private function find_by_keywords(\WP_Post $post, $post_types, $exclude_ids = array())
    {
        $results = array();
        $content = is_string($post->post_content) ? $post->post_content : '';
        $clean_content = wp_strip_all_tags(strip_shortcodes($content));
        $title = is_string($post->post_title) ? $post->post_title : '';
        $raw_text = $title . ' ' . mb_substr($clean_content, 0, 200);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $raw_text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || empty($words)) {
            return $results;
        }

        $keywords = array_filter($words, function ($w) {
            return mb_strlen($w) > 2;
        });
        if (empty($keywords)) {
            return $results;
        }

        $freq = array_count_values(array_map('mb_strtolower', $keywords));
        arsort($freq);
        $top = array_slice($freq, 0, 3, true);
        $search_term = sanitize_text_field(implode(' ', array_keys($top)));

        if (empty($search_term)) {
            return $results;
        }

        $query = new \WP_Query(array(
            'post_type' => array_map('sanitize_key', $post_types),
            'post_status' => 'publish',
            'posts_per_page' => absint(SOLIH_SEO_MAX_RESULTS),
            'post__not_in' => array_map('absint', $exclude_ids),
            's' => $search_term,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        foreach ($query->posts as $p) {
            $results[absint($p->ID)] = $this->format_suggestion($p);
        }

        wp_reset_postdata();
        return $results;
    }

    /* ============================================================
     تنسيق اقتراح واحد (مع حماية null + بيانات الحداثة)
     ============================================================ */
    private function format_suggestion(\WP_Post $p)
    {
        $type_obj = get_post_type_object($p->post_type);
        $type_name = $type_obj ? $type_obj->labels->singular_name : $p->post_type;

        return array(
            'id' => absint($p->ID),
            'title' => esc_html(get_the_title($p->ID)),
            'url' => esc_url_raw(get_permalink($p->ID)),
            'postType' => esc_html($type_name),
            '_rawType' => sanitize_key($p->post_type),
            '_publishDate' => sanitize_text_field($p->post_date),
            'matchType' => '',
            'score' => 0,
        );
    }

    /* ============================================================
     مسح الكاش عند الحفظ (مع فحص القدرات)
     ============================================================ */
    public function on_post_save_clear_cache($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!in_array($post->post_status, array('publish', 'draft'), true)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        delete_transient('solih_seo_' . absint($post_id));
    }

    /* ================================================================
     ================================================================
     صفحة الإعدادات (Settings Page)
     ================================================================
     ================================================================ */

    /* ============================================================
     تسجيل صفحة الإعدادات في القائمة
     ============================================================ */
    public function register_settings_page()
    {
        add_options_page(
            __('Solih SEO Engine Settings', 'solih-seo-engine'),
            '🔗 Solih SEO',
            'manage_options',
            'solih-seo-settings',
            array($this, 'render_settings_page')
        );
    }

    /* ============================================================
     تسجيل الإعدادات (Settings API)
     ============================================================ */
    public function register_settings()
    {
        register_setting(
            'solih_seo_settings_group',
            'solih_seo_plugin_settings',
            array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'show_in_rest' => false, // منع الوصول عبر REST API العامة
        )
        );

        add_settings_section(
            'solih_seo_main_section',
            '',
            '__return_false',
            'solih-seo-settings'
        );

        // Brand Color
        add_settings_field(
            'solih_brand_color',
            __('Brand Color', 'solih-seo-engine'),
            array($this, 'render_color_field'),
            'solih-seo-settings',
            'solih_seo_main_section'
        );

        // Active Post Types
        add_settings_field(
            'solih_active_post_types',
            __('Active Post Types', 'solih-seo-engine'),
            array($this, 'render_post_types_field'),
            'solih-seo-settings',
            'solih_seo_main_section'
        );

        // Blacklist
        add_settings_field(
            'solih_blacklist',
            __('Blacklist (Excluded IDs)', 'solih-seo-engine'),
            array($this, 'render_blacklist_field'),
            'solih-seo-settings',
            'solih_seo_main_section'
        );
    }

    /* ============================================================
     Sanitize Settings (تطهير صارم مع Type Checking)
     ============================================================ */
    public function sanitize_settings($input)
    {
        $output = array();

        // حماية: التأكد أن المدخل مصفوفة
        if (!is_array($input)) {
            return array(
                'brand_color' => '#4F46E5',
                'active_post_types' => array(),
                'blacklist_ids' => '',
            );
        }

        // Brand color — sanitize_hex_color يقبل فقط #RRGGBB أو #RGB
        $output['brand_color'] = '#4F46E5';
        if (isset($input['brand_color']) && is_string($input['brand_color'])) {
            $sanitized_color = sanitize_hex_color($input['brand_color']);
            if ($sanitized_color) {
                $output['brand_color'] = $sanitized_color;
            }
        }

        // Active post types — whitelist validation ضد الأنواع المسجلة فعلاً
        $output['active_post_types'] = array();
        if (isset($input['active_post_types']) && is_array($input['active_post_types'])) {
            $all_public = get_post_types(array('public' => true), 'names');
            unset($all_public['attachment']);
            foreach ($input['active_post_types'] as $pt) {
                if (!is_string($pt)) {
                    continue;
                }
                $pt = sanitize_key($pt);
                if (isset($all_public[$pt])) {
                    $output['active_post_types'][] = $pt;
                }
            }
            // إزالة التكرار
            $output['active_post_types'] = array_unique($output['active_post_types']);
        }

        // Blacklist IDs — فقط أرقام موجبة
        $output['blacklist_ids'] = '';
        if (isset($input['blacklist_ids']) && is_string($input['blacklist_ids'])) {
            // تنظيف: فقط أرقام وفواصل ومسافات
            $cleaned = preg_replace('/[^0-9,\s]/', '', $input['blacklist_ids']);
            $ids = array_filter(array_map('absint', explode(',', $cleaned)));
            $ids = array_unique($ids);
            $output['blacklist_ids'] = implode(',', $ids);
        }

        return $output;
    }

    /* ============================================================
     حقل اختيار اللون
     ============================================================ */
    public function render_color_field()
    {
        $settings = $this->get_plugin_settings();
        $color = esc_attr($settings['brand_color']);
        echo '<input type="text" id="solih-brand-color" '
            . 'name="solih_seo_plugin_settings[brand_color]" '
            . 'value="' . $color . '" '
            . 'class="solih-color-picker" '
            . 'data-default-color="#4F46E5" '
            . 'autocomplete="off" />';
        echo '<p class="description">'
            . esc_html__('Choose the main brand color for the UI elements (buttons, progress bars, icons).', 'solih-seo-engine')
            . '</p>';
    }

    /* ============================================================
     حقل أنواع المحتوى
     ============================================================ */
    public function render_post_types_field()
    {
        $settings = $this->get_plugin_settings();
        $active = $settings['active_post_types'];
        $all_types = get_post_types(array('public' => true), 'objects');
        unset($all_types['attachment']);

        echo '<fieldset class="solih-seo-post-types-fieldset">';
        foreach ($all_types as $slug => $type_obj) {
            $checked = empty($active) || in_array($slug, $active, true) ? 'checked' : '';
            $safe_slug = esc_attr($slug);
            echo '<label class="solih-seo-checkbox-label" for="solih-pt-' . $safe_slug . '">';
            echo '<input type="checkbox" '
                . 'id="solih-pt-' . $safe_slug . '" '
                . 'name="solih_seo_plugin_settings[active_post_types][]" '
                . 'value="' . $safe_slug . '" '
                . $checked . ' />';
            echo '<span>' . esc_html($type_obj->labels->name) . '</span>';
            echo '</label>';
        }
        echo '</fieldset>';
        echo '<p class="description">'
            . esc_html__('Select which content types to include in link suggestions. Leave all checked for default behavior.', 'solih-seo-engine')
            . '</p>';
    }

    /* ============================================================
     حقل القائمة السوداء (مع esc_textarea)
     ============================================================ */
    public function render_blacklist_field()
    {
        $settings = $this->get_plugin_settings();
        $blacklist = esc_textarea($settings['blacklist_ids']);
        echo '<textarea id="solih-blacklist" '
            . 'name="solih_seo_plugin_settings[blacklist_ids]" '
            . 'class="solih-seo-blacklist-textarea" '
            . 'rows="3" '
            . 'placeholder="123, 456, 789" '
            . 'autocomplete="off">'
            . $blacklist
            . '</textarea>';
        echo '<p class="description">'
            . esc_html__('Comma-separated post/page IDs to exclude from suggestions.', 'solih-seo-engine')
            . '</p>';
    }

    /* ============================================================
     تحميل أصول صفحة الإعدادات
     ============================================================ */
    public function enqueue_settings_assets($hook_suffix)
    {
        if ($hook_suffix !== 'settings_page_solih-seo-settings') {
            return;
        }

        // WordPress Color Picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // Plugin settings CSS
        $css_path = SOLIH_SEO_DIR . 'assets/css/solih-engine.css';
        wp_enqueue_style(
            'solih-seo-settings-style',
            SOLIH_SEO_URL . 'assets/css/solih-engine.css',
            array('wp-color-picker'),
            file_exists($css_path) ? filemtime($css_path) : SOLIH_SEO_VERSION
        );

        // Inline script to init the color picker
        wp_add_inline_script('wp-color-picker', '
            jQuery(document).ready(function($) {
                $(".solih-color-picker").wpColorPicker();
            });
        ');
    }

    /* ============================================================
     عرض صفحة الإعدادات
     ============================================================ */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'solih-seo-engine'));
        }

        $settings = $this->get_plugin_settings();
        $brand = esc_attr($settings['brand_color']);
?>
        <div class="wrap solih-seo-settings-wrap" style="--solih-brand: <?php echo $brand; ?>;">

            <div class="solih-seo-settings-header">
                <div class="solih-seo-logo-section">
                    <span class="solih-seo-logo-icon">🔗</span>
                    <div>
                        <h1><?php echo esc_html__('Solih SEO Engine', 'solih-seo-engine'); ?></h1>
                        <p class="solih-seo-settings-subtitle">
                            <?php echo esc_html__('The smartest internal linking engine for WordPress', 'solih-seo-engine'); ?>
                        </p>
                    </div>
                </div>
                <span class="solih-seo-version-badge">v<?php echo esc_html(SOLIH_SEO_VERSION); ?></span>
            </div>

            <div class="solih-seo-settings-card">
                <div class="solih-seo-card-inner-header">
                    <h2><?php echo esc_html__('General Settings', 'solih-seo-engine'); ?></h2>
                    <p><?php echo esc_html__('Customize the appearance and behavior of the link suggestion engine.', 'solih-seo-engine'); ?>
                    </p>
                </div>
                <form method="post" action="options.php">
                    <?php
        settings_fields('solih_seo_settings_group');
        do_settings_sections('solih-seo-settings');
        submit_button(
            __('Save Settings', 'solih-seo-engine'),
            'primary solih-seo-save-btn',
            'submit',
            true
        );
?>
                </form>
            </div>

            <div class="solih-seo-settings-footer">
                <p>
                    Solih SEO Engine v<?php echo esc_html(SOLIH_SEO_VERSION); ?>
                    &middot;
                    <?php echo esc_html__('Built with ♥ for performance and security.', 'solih-seo-engine'); ?>
                </p>
            </div>
        </div>
        <?php
    }
}

/* ============================================================
 تشغيل الإضافة
 ============================================================ */
add_action('plugins_loaded', array('Solih_SEO_Engine', 'get_instance'));
