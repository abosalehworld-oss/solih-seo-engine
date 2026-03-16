<?php
/**
 * Solih SEO Engine — Uninstall
 * =====================================================
 * يتم تنفيذ هذا الملف تلقائياً عند حذف الإضافة نهائياً.
 * ينظف كل البيانات من قاعدة البيانات:
 * 1. الـ Transients (الكاش المؤقت).
 * 2. الإعدادات في wp_options.
 * 3. تفضيلات اللغة في wp_usermeta.
 *
 * Security: يستخدم $wpdb->prepare() و esc_like() لحماية SQL.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// === حذف كل الـ Transients (باستخدام prepared statements) ===
$like_transient = $wpdb->esc_like('_transient_solih_seo_') . '%';
$like_timeout = $wpdb->esc_like('_transient_timeout_solih_seo_') . '%';

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $like_transient,
        $like_timeout
    )
);

// === حذف إعدادات الإضافة ===
delete_option('solih_seo_settings');
delete_option('solih_seo_version');
delete_option('solih_seo_plugin_settings');

// === حذف تفضيلات اللغة من كل المستخدمين (prepared statement) ===
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
        'solih_seo_language'
    )
);
