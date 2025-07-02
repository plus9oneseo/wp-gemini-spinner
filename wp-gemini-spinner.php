<?php
/*
Plugin Name: WP Gemini Spinner
Description: Automatically rewrites posts published by third-party plugins using Gemini AI, with support for multiple languages, custom prompts, and Rank Math integration.
Version: 3.5.5
Author: Your Name
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('WGS_LOG_TABLE', $GLOBALS['wpdb']->prefix . 'wgs_spin_log');
define('WGS_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Check for Parsedown dependency
if (!file_exists(WGS_PLUGIN_DIR . 'includes/Parsedown.php')) {
    error_log('WP Gemini Spinner: Parsedown.php is missing');
    return;
}
require_once WGS_PLUGIN_DIR . 'includes/Parsedown.php';

// Plugin activation
function wgs_plugin_activate() {
    global $wpdb;
    $table_name = WGS_LOG_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        original_content text NOT NULL,
        spun_content text NOT NULL,
        word_count int NOT NULL,
        fields_spun varchar(255) NOT NULL,
        spin_date datetime DEFAULT CURRENT_TIMESTAMP,
        status varchar(20) DEFAULT 'success',
        PRIMARY KEY (id)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    // Schedule cron
    if (!wp_next_scheduled('wgs_spin_cron')) {
        wp_schedule_event(time(), 'hourly', 'wgs_spin_cron');
    }
}
register_activation_hook(__FILE__, 'wgs_plugin_activate');

// Admin menu
add_action('init', function() {
    add_action('admin_menu', function() {
        add_options_page('WP Gemini Spinner Settings', 'WP Gemini Spinner', 'manage_options', 'wgs-spinner', 'wgs_settings_page');
    });
});

// Enqueue admin scripts and styles
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'settings_page_wgs-spinner') return;
    wp_enqueue_style('wgs-admin-style', plugin_dir_url(__FILE__) . 'admin/admin-style.css', [], '3.5.5');
    wp_enqueue_style('dashicons');
    wp_enqueue_script('wgs-admin-script', plugin_dir_url(__FILE__) . 'admin/admin-script.js', ['jquery'], '3.5.5', true);
    wp_localize_script('wgs-admin-script', 'wgsAjax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wgs_manual_spin')
    ]);
});

// Settings page
function wgs_settings_page() {
    global $wpdb;
    $default_options = [
        'api_key' => '',
        'seo_plugin' => 'none',
        'min_word_count' => 800,
        'output_language' => 'hindi',
        'gemini_model' => 'gemini-1.5-flash-latest',
        'post_types' => ['post'],
        'auto_spin_enabled' => true,
        'debug_force_spin' => false,
        'spin_title' => false,
        'spin_content' => true,
        'spin_slug' => false,
        'spin_meta_desc' => false,
        'spin_seo_title' => false,
        'spin_focus_keyword' => false,
        'prompt_title' => 'Rewrite this title to be engaging, SEO-friendly, and concise (max 60 characters) for a blog post. Keep the focus keyword in English, and use [language] for the rest of the title. Output language: [language]. Original: "[text]"',
        'prompt_content' => 'Rewrite this article content to be unique, well-structured, and plagiarism-free. Ensure the content is at least 800 words, written entirely in [language] except for the focus keyword and other SEO keywords, which must remain in English. Use Markdown formatting with ## for main sections, ### for subsections, #### for sub-subsections, - or * for bullet points, and include at least one table (| Header | Header |) to summarize key points. Incorporate paragraphs, lists, and **bold text** where appropriate to enhance readability. Expand the content by adding relevant background information, detailed explanations, and examples (e.g., historical context, trends, or practical insights) to meet the 800-word minimum while maintaining the original meaning. Replace [language]-transliterated English terms (e.g., "मशीन" in Hindi) with bold English terms (e.g., "**Machine**") for SEO. Output only the rewritten content in valid Markdown format, with at least 2 ## headings and 2 ### headings. Output language: [language]. Original content: [text]',
        'prompt_slug' => 'Create a short, SEO-friendly URL slug (kebab-case, max 5 words) in English for this title: "[text]"',
        'prompt_meta_desc' => 'Write a compelling, SEO-optimized meta description (max 155 characters) for an article with this content: "[text]". Include the focus keyword in English and use [language] for the rest. Output language: [language].',
        'prompt_seo_title' => 'Create a compelling, SEO-optimized title (max 60 characters) for this content in [language]. Keep the focus keyword in English and use [language] for the rest. Original: [text]',
        'prompt_seo_meta_desc' => 'Write a compelling, SEO-optimized meta description (max 155 characters) for this content in [language]. Include the focus keyword in English and use [language] for the rest. Original: [text]',
        'prompt_focus_keyword' => 'Generate a strong focus keyword (1-4 words) in English for this content. Ensure it is relevant to the main topic. Original: [text]',
        'default_prompt' => 'Rewrite the provided text to be unique and engaging in [language]. Maintain the original meaning, ensure SEO compatibility, and use Markdown formatting. Original: "[text]"'
    ];
    $options = get_option('wgs_options', $default_options);

    // Validate API key on settings save
    if (isset($_POST['wgs_options']['api_key']) && base64_encode($_POST['wgs_options']['api_key']) !== $options['api_key']) {
        $test_response = wgs_validate_api_key(trim(sanitize_text_field($_POST['wgs_options']['api_key'])));
        if (is_wp_error($test_response)) {
            add_settings_error('wgs_options', 'api_key_invalid', 'Invalid Gemini API key: ' . $test_response->get_error_message(), 'error');
        } else {
            add_settings_error('wgs_options', 'api_key_valid', 'Gemini API key validated successfully!', 'success');
        }
    }

    // Stats for widgets
    $stats = $wpdb->get_row("SELECT COUNT(*) as total_spins, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_spins, AVG(word_count) as avg_word_count FROM " . WGS_LOG_TABLE);
    $total_spins = $stats ? $stats->total_spins : 0;
    $successful_spins = $stats ? $stats->successful_spins : 0;
    $avg_word_count = $stats ? ($stats->avg_word_count ?: 0) : 0;

    // Available languages
    $languages = [
        'hindi' => 'Hindi (हिंदी)',
        'tamil' => 'Tamil (தமிழ்)',
        'telugu' => 'Telugu (తెలుగు)',
        'kannada' => 'Kannada (ಕನ್ನಡ)',
        'malayalam' => 'Malayalam (മലയാളം)',
        'bengali' => 'Bengali (বাংলা)',
        'marathi' => 'Marathi (मराठी)',
        'gujarati' => 'Gujarati (ગુજરાતી)',
        'punjabi' => 'Punjabi (ਪੰਜਾਬੀ)',
        'odia' => 'Odia (ଓଡ଼ିଆ)',
        'assamese' => 'Assamese (অসমীয়া)',
        'urdu' => 'Urdu (اردو)',
        'english' => 'English',
        'spanish' => 'Spanish (Español)',
        'french' => 'French (Français)',
        'german' => 'German (Deutsch)',
        'chinese' => 'Chinese (Simplified, 简体中文)',
        'japanese' => 'Japanese (日本語)'
    ];

    // Available Gemini models
    $gemini_models = [
        'gemini-1.0-pro' => 'Gemini 1.0 Pro',
        'gemini-1.5-flash-latest' => 'Gemini 1.5 Flash (Latest)',
        'gemini-1.5-pro' => 'Gemini 1.5 Pro',
        'gemini-1.5-lite' => 'Gemini 1.5 Lite',
        'gemini-2.0-flash' => 'Gemini 2.0 Flash',
        'gemini-2.0-pro' => 'Gemini 2.0 Pro',
        'gemini-2.0-lite' => 'Gemini 2.0 Lite',
        'gemini-2.5-flash' => 'Gemini 2.5 Flash',
        'gemini-2.5-pro' => 'Gemini 2.5 Pro',
        'gemini-2.5-lite' => 'Gemini 2.5 Lite'
    ];
    ?>
    <div class="wrap wgs-wrap">
        <div class="wgs-header">
            <div class="wgs-header-logo">
                <img src="<?php echo plugin_dir_url(__FILE__) . 'images/logo.png'; ?>" alt="WP Gemini Spinner Logo" class="wgs-logo">
                <h1>डब्ल्यूपी जेमिनी स्पिनर</h1>
            </div>
            <div class="wgs-header-actions">
                <a href="#settings" class="button"><span class="dashicons dashicons-admin-generic"></span> सेटिंग्स</a>
            </div>
        </div>
        <div class="wgs-stats-grid">
            <div class="wgs-stats-card">
                <h3>कुल स्पिन्स 📊</h3>
                <p class="wgs-stats-number"><?php echo esc_html($total_spins); ?></p>
                <small>कुल किए गए कंटेंट स्पिन्स की संख्या</small>
            </div>
            <div class="wgs-stats-card">
                <h3>सफल स्पिन्स ✅</h3>
                <p class="wgs-stats-number"><?php echo esc_html($successful_spins); ?></p>
                <small>बिना त्रुटि के पूर्ण किए गए स्पिन्स</small>
            </div>
            <div class="wgs-stats-card">
                <h3>औसत शब्द संख्या ✍️</h3>
                <p class="wgs-stats-number"><?php echo esc_html(round($avg_word_count)); ?></p>
                <small>स्पिन किए गए कंटेंट में औसत शब्द</small>
            </div>
        </div>
        <div class="wgs-tabs">
            <a href="#settings" class="wgs-tab-link active" data-tab="settings"><span class="dashicons dashicons-admin-settings"></span> सेटिंग्स</a>
            <a href="#prompts" class="wgs-tab-link" data-tab="prompts"><span class="dashicons dashicons-text"></span> कस्टम प्रॉम्प्ट्स</a>
            <a href="#log" class="wgs-tab-link" data-tab="log"><span class="dashicons dashicons-list-view"></span> लॉग</a>
            <a href="#manual" class="wgs-tab-link" data-tab="manual"><span class="dashicons dashicons-edit"></span> मैनुअल रिवाइटर</a>
        </div>
        <div class="wgs-main-content">
            <div class="wgs-sidebar">
                <div class="wgs-card">
                    <div class="wgs-card-header">
                        <h3><span class="dashicons dashicons-info"></span> प्लगिन के बारे में</h3>
                    </div>
                    <div class="wgs-card-body">
                        <p>डब्ल्यूपी जेमिनी स्पिनर, Gemini API का उपयोग करके थर्ड-पार्टी प्लगिन्स द्वारा प्रकाशित पोस्ट्स को स्वचालित रूप से रिवाइट करता है, जिसमें न्यूनतम 800 शब्दों के साथ Rank Math के लिए SEO ऑप्टिमाइज्ड फील्ड्स शामिल हैं।</p>
                        <hr>
                        <p><small>संस्करण: 3.5.5 | डेवलपर: आपका नाम</small></p>
                    </div>
                </div>
            </div>
            <div class="wgs-content-area">
                <div class="wgs-tab-content active" id="settings">
                    <div class="wgs-card">
                        <div class="wgs-card-header">
                            <h3><span class="dashicons dashicons-admin-generic"></span> सेटिंग्स</h3>
                        </div>
                        <div class="wgs-card-body">
                            <?php settings_errors('wgs_options'); ?>
                            <form method="post" action="options.php">
                                <?php
                                settings_fields('wgs_options');
                                do_settings_sections('wgs-spinner');
                                ?>
                                <table class="form-table">
                                    <tr><th>Gemini API Key</th><td><input type="text" name="wgs_options[api_key]" value="<?php echo esc_attr(base64_decode($options['api_key'])); ?>" class="wgs-input"></td></tr>
                                    <tr><th>Gemini Model</th><td>
                                        <select name="wgs_options[gemini_model]" class="wgs-input">
                                            <?php foreach ($gemini_models as $value => $label) : ?>
                                                <option value="<?php echo esc_attr($value); ?>" <?php selected($options['gemini_model'], $value); ?>><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td></tr>
                                    <tr><th>Output Language</th><td>
                                        <select name="wgs_options[output_language]" class="wgs-input">
                                            <?php foreach ($languages as $value => $label) : ?>
                                                <option value="<?php echo esc_attr($value); ?>" <?php selected($options['output_language'], $value); ?>><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td></tr>
                                    <tr><th>न्यूनतम शब्द संख्या</th><td><input type="number" name="wgs_options[min_word_count]" value="<?php echo esc_attr($options['min_word_count']); ?>" min="100" class="wgs-input"></td></tr>
                                    <tr><th>ऑटो स्पिनिंग सक्षम करें</th><td>
                                        <label><input type="checkbox" name="wgs_options[auto_spin_enabled]" <?php checked($options['auto_spin_enabled']); ?> value="1"> थर्ड-पार्टी पोस्ट्स के लिए ऑटोमैटिक स्पिनिंग सक्षम करें</label>
                                    </td></tr>
                                    <tr><th>डिबग: सभी पोस्ट्स स्पिन करें</th><td>
                                        <label><input type="checkbox" name="wgs_options[debug_force_spin]" <?php checked($options['debug_force_spin']); ?> value="1"> सभी पोस्ट्स को स्पिन करें (थर्ड-पार्टी चेक बायपास करें, डिबगिंग के लिए)</label>
                                    </td></tr>
                                    <tr><th>स्पिन करने वाले फील्ड्स</th><td>
                                        <label><input type="checkbox" name="wgs_options[spin_title]" <?php checked($options['spin_title']); ?> value="1"> पोस्ट टाइटल</label><br>
                                        <label><input type="checkbox" name="wgs_options[spin_content]" <?php checked($options['spin_content']); ?> value="1"> पोस्ट कंटेंट</label><br>
                                        <label><input type="checkbox" name="wgs_options[spin_slug]" <?php checked($options['spin_slug']); ?> value="1"> URL Slug</label><br>
                                        <label><input type="checkbox" name="wgs_options[spin_meta_desc]" <?php checked($options['spin_meta_desc']); ?> value="1"> मेटा डिस्क्रिप्शन</label><br>
                                        <label><input type="checkbox" name="wgs_options[spin_seo_title]" <?php checked($options['spin_seo_title']); ?> value="1"> SEO टाइटल</label><br>
                                        <label><input type="checkbox" name="wgs_options[spin_focus_keyword]" <?php checked($options['spin_focus_keyword']); ?> value="1"> फोकस कीवर्ड</label>
                                    </td></tr>
                                    <tr><th>पोस्ट टाइप्स</th><td>
                                        <?php foreach (get_post_types(['public' => true], 'objects') as $post_type) : ?>
                                            <label><input type="checkbox" name="wgs_options[post_types][]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, $options['post_types'])); ?>> <?php echo esc_html($post_type->label); ?></label><br>
                                        <?php endforeach; ?>
                                    </td></tr>
                                </table>
                                <?php submit_button('सेटिंग्स सहेजें', 'primary', 'submit', true, ['class' => 'button-primary']); ?>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="wgs-tab-content" id="prompts">
                    <div class="wgs-card">
                        <div class="wgs-card-header">
                            <h3><span class="dashicons dashicons-text"></span> कस्टम प्रॉम्प्ट्स</h3>
                        </div>
                        <div class="wgs-card-body">
                            <form method="post" action="options.php">
                                <?php
                                settings_fields('wgs_options');
                                do_settings_sections('wgs-spinner');
                                ?>
                                <table class="form-table">
                                    <tr><th>डिफ़ॉल्ट प्रॉम्प्ट</th><td>
                                        <textarea name="wgs_options[default_prompt]" class="wgs-input large"><?php echo esc_textarea($options['default_prompt']); ?></textarea>
                                        <p><small>यह डिफ़ॉल्ट प्रॉम्प्ट मैनुअल रिवाइटर में उपयोग किया जाता है यदि कोई कस्टम प्रॉम्प्ट प्रदान नहीं किया जाता है।</small></p>
                                    </td></tr>
                                    <tr><th>पोस्ट टाइटल प्रॉम्प्ट</th><td>
                                        <textarea name="wgs_options[prompt_title]" class="wgs-input large"><?php echo esc_textarea($options['prompt_title']); ?></textarea>
                                    </td></tr>
                                    <tr><th>पोस्ट कंटेंट प्रॉम्प्ट</th><td>
                                        <textarea name="wgs_options[prompt_content]" class="wgs-input large"><?php echo esc_textarea($options['prompt_content']); ?></textarea>
                                    </td></tr>
                                    <tr><th>URL Slug प्रॉम्प्ट</th><td>
                                        <textarea name="wgs_options[prompt_slug]" class="wgs-input large"><?php echo esc_textarea($options['prompt_slug']); ?></textarea>
                                    </td></tr>
                                    <tr><th>मेटा डिस्क्रिप्शन प्रॉम्प्ट</th><td>
                                        <textarea name="wgs_options[prompt_meta_desc]" class="wgs-input large"><?php echo esc_textarea($options['prompt_meta_desc']); ?></textarea>
                                    </td></tr>
                                    <tr><th>SEO टाइटल प्रॉम्प्ट (Rank Math)</th><td>
                                        <textarea name="wgs_options[prompt_seo_title]" class="wgs-input large"><?php echo esc_textarea($options['prompt_seo_title']); ?></textarea>
                                    </td></tr>
                                    <tr><th>SEO मेटा डिस्क्रिप्शन प्रॉम्प्ट (Rank Math)</th><td>
                                        <textarea name="wgs_options[prompt_seo_meta_desc]" class="wgs-input large"><?php echo esc_textarea($options['prompt_seo_meta_desc']); ?></textarea>
                                    </td></tr>
                                    <tr><th>फोकस कीवर्ड प्रॉम्प्ट</th><td>
                                        <textarea name="wgs_options[prompt_focus_keyword]" class="wgs-input large"><?php echo esc_textarea($options['prompt_focus_keyword']); ?></textarea>
                                    </td></tr>
                                </table>
                                <?php submit_button('प्रॉम्प्ट्स सहेजें', 'primary', 'submit', true, ['class' => 'button-primary']); ?>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="wgs-tab-content" id="log">
                    <div class="wgs-card">
                        <div class="wgs-card-header">
                            <h3><span class="dashicons dashicons-list-view"></span> लॉग</h3>
                            <button id="wgs-refresh-log" class="button"><span class="dashicons dashicons-update"></span> रिफ्रेश करें</button>
                        </div>
                        <div class="wgs-card-body">
                            <table class="wgs-log-table">
                                <thead><tr><th>ID</th><th>पोस्ट ID</th><th>शब्द संख्या</th><th>स्पिन किए गए फील्ड्स</th><th>तारीख</th><th>स्थिति</th></tr></thead>
                                <tbody id="wgs-log-table"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="wgs-tab-content" id="manual">
                    <div class="wgs-card">
                        <div class="wgs-card-header">
                            <h3><span class="dashicons dashicons-edit"></span> मैनुअल रिवाइटर</h3>
                        </div>
                        <div class="wgs-card-body">
                            <div class="wgs-tester-grid">
                                <div>
                                    <p><strong>इनपुट कंटेंट</strong></p>
                                    <textarea id="wgs-manual-content" class="wgs-input large"></textarea>
                                    <p><strong>Output Language</strong></p>
                                    <select id="wgs-manual-language" class="wgs-input">
                                        <?php foreach ($languages as $value => $label) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($options['output_language'], $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p><strong>Custom Prompt (Optional)</strong></p>
                                    <textarea id="wgs-manual-prompt" class="wgs-input large" placeholder="Enter custom prompt or leave blank for default"></textarea>
                                    <button id="wgs-manual-spinner" class="button button-primary"><span class="dashicons dashicons-image-rotate"></span> कंटेंट रिवाइट करें</button>
                                </div>
                                <div>
                                    <p><strong>आउटपुट कंटेंट</strong></p>
                                    <div id="wgs-manual-result" class="wgs-output-box"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div id="wgs-notice-area"></div>
    </div>
    <?php
}

// Register settings
add_action('admin_init', function() {
    register_setting('wgs_options', 'wgs_options', [
        'sanitize_callback' => function($input) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wgs_options-options')) {
                add_settings_error('wgs_options', 'nonce_failed', 'Security check failed.', 'error');
                return get_option('wgs_options');
            }
            $default_options = [
                'api_key' => '',
                'seo_plugin' => 'none',
                'min_word_count' => 800,
                'output_language' => 'hindi',
                'gemini_model' => 'gemini-1.5-flash-latest',
                'post_types' => ['post'],
                'auto_spin_enabled' => true,
                'debug_force_spin' => false,
                'spin_title' => false,
                'spin_content' => true,
                'spin_slug' => false,
                'spin_meta_desc' => false,
                'spin_seo_title' => false,
                'spin_focus_keyword' => false,
                'prompt_title' => 'Rewrite this title to be engaging, SEO-friendly, and concise (max 60 characters) for a blog post. Keep the focus keyword in English, and use [language] for the rest of the title. Output language: [language]. Original: "[text]"',
                'prompt_content' => 'Rewrite this article content to be unique, well-structured, and plagiarism-free. Ensure the content is at least 800 words, written entirely in [language] except for the focus keyword and other SEO keywords, which must remain in English. Use Markdown formatting with ## for main sections, ### for subsections, #### for sub-subsections, - or * for bullet points, and include at least one table (| Header | Header |) to summarize key points. Incorporate paragraphs, lists, and **bold text** where appropriate to enhance readability. Expand the content by adding relevant background information, detailed explanations, and examples (e.g., historical context, trends, or practical insights) to meet the 800-word minimum while maintaining the original meaning. Replace [language]-transliterated English terms (e.g., "मशीन" in Hindi) with bold English terms (e.g., "**Machine**") for SEO. Output only the rewritten content in valid Markdown format, with at least 2 ## headings and 2 ### headings. Output language: [language]. Original content: [text]',
                'prompt_slug' => 'Create a short, SEO-friendly URL slug (kebab-case, max 5 words) in English for this title: "[text]"',
                'prompt_meta_desc' => 'Write a compelling, SEO-optimized meta description (max 155 characters) for an article with this content: "[text]". Include the focus keyword in English and use [language] for the rest. Output language: [language].',
                'prompt_seo_title' => 'Create a compelling, SEO-optimized title (max 60 characters) for this content in [language]. Keep the focus keyword in English and use [language] for the rest. Original: [text]',
                'prompt_seo_meta_desc' => 'Write a compelling, SEO-optimized meta description (max 155 characters) for this content in [language]. Include the focus keyword in English and use [language] for the rest. Original: [text]',
                'prompt_focus_keyword' => 'Generate a strong focus keyword (1-4 words) in English for this content. Ensure it is relevant to the main topic. Original: [text]',
                'default_prompt' => 'Rewrite the provided text to be unique and engaging in [language]. Maintain the original meaning, ensure SEO compatibility, and use Markdown formatting. Original: "[text]"'
            ];
            $input = wp_parse_args($input, $default_options);
            $input['api_key'] = isset($input['api_key']) ? base64_encode(sanitize_text_field($input['api_key'])) : '';
            $input['seo_plugin'] = sanitize_text_field($input['seo_plugin']);
            $input['min_word_count'] = absint($input['min_word_count']);
            $input['output_language'] = sanitize_text_field($input['output_language']);
            $input['gemini_model'] = sanitize_text_field($input['gemini_model']);
            $input['auto_spin_enabled'] = isset($input['auto_spin_enabled']) ? 1 : 0;
            $input['debug_force_spin'] = isset($input['debug_force_spin']) ? 1 : 0;
            $input['spin_title'] = isset($input['spin_title']) ? 1 : 0;
            $input['spin_content'] = isset($input['spin_content']) ? 1 : 0;
            $input['spin_slug'] = isset($input['spin_slug']) ? 1 : 0;
            $input['spin_meta_desc'] = isset($input['spin_meta_desc']) ? 1 : 0;
            $input['spin_seo_title'] = isset($input['spin_seo_title']) ? 1 : 0;
            $input['spin_focus_keyword'] = isset($input['spin_focus_keyword']) ? 1 : 0;
            $input['prompt_title'] = sanitize_textarea_field($input['prompt_title']);
            $input['prompt_content'] = sanitize_textarea_field($input['prompt_content']);
            $input['prompt_slug'] = sanitize_textarea_field($input['prompt_slug']);
            $input['prompt_meta_desc'] = sanitize_textarea_field($input['prompt_meta_desc']);
            $input['prompt_seo_title'] = sanitize_textarea_field($input['prompt_seo_title']);
            $input['prompt_seo_meta_desc'] = sanitize_textarea_field($input['prompt_seo_meta_desc']);
            $input['prompt_focus_keyword'] = sanitize_textarea_field($input['prompt_focus_keyword']);
            $input['default_prompt'] = sanitize_textarea_field($input['default_prompt']);
            $input['post_types'] = array_map('sanitize_text_field', (array) $input['post_types']);
            return $input;
        }
    ]);
});

// Validate API key
function wgs_validate_api_key($api_key) {
    if (empty($api_key)) {
        return new WP_Error('api_key_invalid', 'API key is empty');
    }
    $response = wp_remote_get('https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($api_key), [
        'timeout' => 15
    ]);
    if (is_wp_error($response)) {
        return new WP_Error('api_key_invalid', $response->get_error_message());
    }
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        return new WP_Error('api_key_invalid', "API key validation failed with status code: $status_code");
    }
    return true;
}

// Detect third-party published posts (preserved as is)
function wgs_is_third_party_post($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        error_log("WP Gemini Spinner: Invalid post ID $post_id in wgs_is_third_party_post");
        return false;
    }
    $post_meta = get_post_meta($post_id);
    $third_party_plugins = ['wp-automatic', 'rss-aggregator', 'feedwordpress', 'wp-all-import', 'cyberseo', 'content-egg'];
    
    // Check for common third-party plugin meta keys
    $meta_indicators = [
        '_source_plugin', // Generic source plugin meta
        'wp_automatic_source', // WP Automatic
        'syndication_source', // FeedWordPress
        'wpai_source', // WP All Import
        'cyberseo_source', // CyberSEO
        'cegg_data' // Content Egg
    ];
    
    foreach ($meta_indicators as $meta_key) {
        if (isset($post_meta[$meta_key]) && !empty($post_meta[$meta_key])) {
            error_log("WP Gemini Spinner: Post ID $post_id detected as third-party via meta $meta_key");
            return true;
        }
    }
    
    // Check for specific plugins
    foreach ($third_party_plugins as $plugin) {
        if (isset($post_meta['_source_plugin']) && strpos($post_meta['_source_plugin'][0], $plugin) !== false) {
            error_log("WP Gemini Spinner: Post ID $post_id detected as third-party via plugin $plugin");
            return true;
        }
    }
    
    // Check if post author is not an administrator or has a bot-like role
    $user_id = $post->post_author;
    $user = get_userdata($user_id);
    $is_third_party = !$user || !in_array('administrator', (array) $user->roles, true);
    error_log("WP Gemini Spinner: Post ID $post_id third-party check: " . ($is_third_party ? 'True' : 'False') . " (Author ID: $user_id, Roles: " . print_r($user ? $user->roles : [], true) . ")");
    
    return $is_third_party;
}

// Spin post on publish
add_action('wp_insert_post', function($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || in_array($post->post_status, ['draft', 'pending', 'trash', 'auto-draft'])) {
        error_log("WP Gemini Spinner: Skipped Post ID $post_id (Revision or status: {$post->post_status})");
        return;
    }
    
    $options = get_option('wgs_options', []);
    $default_options = [
        'post_types' => ['post'],
        'auto_spin_enabled' => true,
        'debug_force_spin' => false,
        'api_key' => ''
    ];
    $options = wp_parse_args($options, $default_options);
    
    error_log("WP Gemini Spinner: wp_insert_post triggered for Post ID $post_id, Status: {$post->post_status}, Type: {$post->post_type}, Update: " . ($update ? 'True' : 'False'));
    error_log("WP Gemini Spinner: Post ID $post_id metadata: " . print_r(get_post_meta($post_id), true));
    
    if (!in_array($post->post_type, $options['post_types'])) {
        error_log("WP Gemini Spinner: Post ID $post_id skipped, post type {$post->post_type} not in " . print_r($options['post_types'], true));
        return;
    }
    
    if (!$options['auto_spin_enabled']) {
        error_log("WP Gemini Spinner: Post ID $post_id skipped, auto spinning disabled");
        return;
    }
    
    if (!$options['debug_force_spin'] && !wgs_is_third_party_post($post_id)) {
        error_log("WP Gemini Spinner: Post ID $post_id is not a third-party post");
        return;
    }
    
    // Validate API key before attempting to spin
    $api_key = base64_decode($options['api_key']);
    if (empty($api_key) || is_wp_error(wgs_validate_api_key($api_key))) {
        error_log("WP Gemini Spinner: Invalid or missing API key for Post ID $post_id, queuing for later");
        update_post_meta($post_id, '_wgs_spin_queued', '1');
        return;
    }
    
    // Attempt immediate spin, fallback to queue if it fails
    try {
        wgs_spin_post($post_id, $post);
        error_log("WP Gemini Spinner: Successfully spun Post ID $post_id");
    } catch (Exception $e) {
        error_log("WP Gemini Spinner: Failed to spin Post ID $post_id: " . $e->getMessage() . ", queuing for later");
        update_post_meta($post_id, '_wgs_spin_queued', '1');
    }
}, 20, 3); // Higher priority to capture more events

// Additional hook for transition_post_status
add_action('transition_post_status', function($new_status, $old_status, $post) {
    if ($new_status !== 'publish' || $old_status === 'publish' || wp_is_post_revision($post->ID)) {
        error_log("WP Gemini Spinner: Skipped Post ID {$post->ID} (Status transition: $old_status -> $new_status)");
        return;
    }
    
    $options = get_option('wgs_options', []);
    $default_options = [
        'post_types' => ['post'],
        'auto_spin_enabled' => true,
        'debug_force_spin' => false,
        'api_key' => ''
    ];
    $options = wp_parse_args($options, $default_options);
    
    error_log("WP Gemini Spinner: transition_post_status triggered for Post ID {$post->ID}, Status: $old_status -> $new_status, Type: {$post->post_type}");
    error_log("WP Gemini Spinner: Post ID {$post->ID} metadata: " . print_r(get_post_meta($post->ID), true));
    
    if (!in_array($post->post_type, $options['post_types'])) {
        error_log("WP Gemini Spinner: Post ID {$post->ID} skipped, post type {$post->post_type} not in " . print_r($options['post_types'], true));
        return;
    }
    
    if (!$options['auto_spin_enabled']) {
        error_log("WP Gemini Spinner: Post ID {$post->ID} skipped, auto spinning disabled");
        return;
    }
    
    if (!$options['debug_force_spin'] && !wgs_is_third_party_post($post->ID)) {
        error_log("WP Gemini Spinner: Post ID {$post->ID} is not a third-party post");
        return;
    }
    
    $api_key = base64_decode($options['api_key']);
    if (empty($api_key) || is_wp_error(wgs_validate_api_key($api_key))) {
        error_log("WP Gemini Spinner: Invalid or missing API key for Post ID {$post->ID}, queuing for later");
        update_post_meta($post->ID, '_wgs_spin_queued', '1');
        return;
    }
    
    try {
        wgs_spin_post($post->ID, $post);
        error_log("WP Gemini Spinner: Successfully spun Post ID {$post->ID} via transition_post_status");
    } catch (Exception $e) {
        error_log("WP Gemini Spinner: Failed to spin Post ID {$post->ID} via transition_post_status: " . $e->getMessage() . ", queuing for later");
        update_post_meta($post->ID, '_wgs_spin_queued', '1');
    }
}, 20, 3); // Higher priority

// Fallback hook for save_post
add_action('save_post', function($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || in_array($post->post_status, ['draft', 'pending', 'trash', 'auto-draft'])) {
        error_log("WP Gemini Spinner: Skipped Post ID $post_id (Revision or status: {$post->post_status}) via save_post");
        return;
    }
    
    $options = get_option('wgs_options', []);
    $default_options = [
        'post_types' => ['post'],
        'auto_spin_enabled' => true,
        'debug_force_spin' => false,
        'api_key' => ''
    ];
    $options = wp_parse_args($options, $default_options);
    
    error_log("WP Gemini Spinner: save_post triggered for Post ID $post_id, Status: {$post->post_status}, Type: {$post->post_type}, Update: " . ($update ? 'True' : 'False'));
    error_log("WP Gemini Spinner: Post ID $post_id metadata: " . print_r(get_post_meta($post_id), true));
    
    if (!in_array($post->post_type, $options['post_types'])) {
        error_log("WP Gemini Spinner: Post ID $post_id skipped, post type {$post->post_type} not in " . print_r($options['post_types'], true));
        return;
    }
    
    if (!$options['auto_spin_enabled']) {
        error_log("WP Gemini Spinner: Post ID $post_id skipped, auto spinning disabled");
        return;
    }
    
    if (!$options['debug_force_spin'] && !wgs_is_third_party_post($post_id)) {
        error_log("WP Gemini Spinner: Post ID $post_id is not a third-party post");
        return;
    }
    
    // Check if already processed to avoid duplicate spinning
    if (get_post_meta($post_id, '_wgs_spin_processed', true)) {
        error_log("WP Gemini Spinner: Post ID $post_id already processed, skipping");
        return;
    }
    
    $api_key = base64_decode($options['api_key']);
    if (empty($api_key) || is_wp_error(wgs_validate_api_key($api_key))) {
        error_log("WP Gemini Spinner: Invalid or missing API key for Post ID $post_id, queuing for later");
        update_post_meta($post_id, '_wgs_spin_queued', '1');
        return;
    }
    
    try {
        wgs_spin_post($post_id, $post);
        update_post_meta($post_id, '_wgs_spin_processed', '1');
        error_log("WP Gemini Spinner: Successfully spun Post ID $post_id via save_post");
    } catch (Exception $e) {
        error_log("WP Gemini Spinner: Failed to spin Post ID $post_id via save_post: " . $e->getMessage() . ", queuing for later");
        update_post_meta($post_id, '_wgs_spin_queued', '1');
    }
}, 20, 3);

function wgs_spin_post($post_id, $post) {
    static $is_processing = false;
    if ($is_processing) {
        error_log("WP Gemini Spinner: Skipped Post ID $post_id due to ongoing processing");
        return;
    }
    $is_processing = true;
    global $wpdb;
    $default_options = [
        'api_key' => '',
        'seo_plugin' => 'none',
        'min_word_count' => 800,
        'output_language' => 'hindi',
        'gemini_model' => 'gemini-1.5-flash-latest',
        'post_types' => ['post'],
        'spin_title' => false,
        'spin_content' => true,
        'spin_slug' => false,
        'spin_meta_desc' => false,
        'spin_seo_title' => false,
        'spin_focus_keyword' => false,
        'prompt_title' => 'Rewrite this title to be engaging, SEO-friendly, and concise (max 60 characters) for a blog post. Keep the focus keyword in English, and use [language] for the rest of the title. Output language: [language]. Original: "[text]"',
        'prompt_content' => 'Rewrite this article content to be unique, well-structured, and plagiarism-free. Ensure the content is at least 800 words, written entirely in [language] except for the focus keyword and other SEO keywords, which must remain in English. Use Markdown formatting with ## for main sections, ### for subsections, #### for sub-subsections, - or * for bullet points, and include at least one table (| Header | Header |) to summarize key points. Incorporate paragraphs, lists, and **bold text** where appropriate to enhance readability. Expand the content by adding relevant background information, detailed explanations, and examples (e.g., historical context, trends, or practical insights) to meet the 800-word minimum while maintaining the original meaning. Replace [language]-transliterated English terms (e.g., "मशीन" in Hindi) with bold English terms (e.g., "**Machine**") for SEO. Output only the rewritten content in valid Markdown format, with at least 2 ## headings and 2 ### headings. Output language: [language]. Original content: [text]',
        'prompt_slug' => 'Create a short, SEO-friendly URL slug (kebab-case, max 5 words) in English for this title: "[text]"',
        'prompt_meta_desc' => 'Write a compelling, SEO-optimized meta description (max 155 characters) for an article with this content: "[text]". Include the focus keyword in English and use [language] for the rest. Output language: [language].',
        'prompt_seo_title' => 'Create a compelling, SEO-optimized title (max 60 characters) for this content in [language]. Keep the focus keyword in English and use [language] for the rest. Original: [text]',
        'prompt_seo_meta_desc' => 'Write a compelling, SEO-optimized meta description (max 155 characters) for this content in [language]. Include the focus keyword in English and use [language] for the rest. Original: [text]',
        'prompt_focus_keyword' => 'Generate a strong focus keyword (1-4 words) in English for this content. Ensure it is relevant to the main topic. Original: [text]',
        'default_prompt' => 'Rewrite the provided text to be unique and engaging in [language]. Maintain the original meaning, ensure SEO compatibility, and use Markdown formatting. Original: "[text]"'
    ];
    $options = wp_parse_args(get_option('wgs_options', []), $default_options);
    $Parsedown = new Parsedown();
    $fields_spun = [];
    $status = 'success';
    $language = $options['output_language'];

    // Validate API key
    $api_key = base64_decode($options['api_key']);
    if (empty($api_key) || is_wp_error(wgs_validate_api_key($api_key))) {
        $status = 'error';
        error_log("WP Gemini Spinner: Invalid API key for Post ID: $post_id");
        $wpdb->insert(WGS_LOG_TABLE, [
            'post_id' => $post_id,
            'original_content' => $post->post_content,
            'spun_content' => $post->post_content,
            'word_count' => 0,
            'fields_spun' => '',
            'spin_date' => current_time('mysql'),
            'status' => $status
        ]);
        $is_processing = false;
        return;
    }

    // Log truncation warning
    if (strlen($post->post_content) > 10000) {
        error_log("WP Gemini Spinner: Content for Post ID: $post_id truncated to 10,000 characters");
    }

    // Use custom prompts
    $prompts = [
        'title' => str_replace('[language]', $language, $options['prompt_title']),
        'content' => str_replace('[language]', $language, $options['prompt_content']),
        'slug' => $options['prompt_slug'],
        'meta_desc' => str_replace('[language]', $language, $options['prompt_meta_desc']),
        'seo_title' => str_replace('[language]', $language, $options['prompt_seo_title']),
        'seo_meta_desc' => str_replace('[language]', $language, $options['prompt_seo_meta_desc']),
        'focus_keyword' => $options['prompt_focus_keyword']
    ];

    $spun_data = [];
    try {
        if ($options['spin_title']) {
            $spun_data['title'] = wgs_call_gemini_api(str_replace('[text]', substr($post->post_title, 0, 10000), $prompts['title']), $options['gemini_model'], $api_key);
            $fields_spun[] = 'title';
        }
        if ($options['spin_content']) {
            $spun_data['content'] = wgs_call_gemini_api(str_replace('[text]', substr($post->post_content, 0, 10000), $prompts['content']), $options['gemini_model'], $api_key);
            $fields_spun[] = 'content';
        }
        if ($options['spin_slug']) {
            $spun_data['slug'] = wgs_call_gemini_api(str_replace('[text]', substr($post->post_title, 0, 10000), $prompts['slug']), $options['gemini_model'], $api_key);
            $fields_spun[] = 'slug';
        }
        if ($options['spin_meta_desc'] && $options['seo_plugin'] === 'rank_math' && class_exists('RankMath') && function_exists('rank_math')) {
            $spun_data['meta_desc'] = wgs_call_gemini_api(str_replace('[text]', substr($post->post_content, 0, 10000), $prompts['meta_desc']), $options['gemini_model'], $api_key);
            $fields_spun[] = 'meta_desc';
        }
        if ($options['spin_seo_title'] && $options['seo_plugin'] === 'rank_math' && class_exists('RankMath') && function_exists('rank_math')) {
            $spun_data['seo_title'] = wgs_call_gemini_api(str_replace('[text]', substr($post->post_content, 0, 10000), $prompts['seo_title']), $options['gemini_model'], $api_key);
            $fields_spun[] = 'seo_title';
        }
        if ($options['spin_focus_keyword'] && $options['seo_plugin'] === 'rank_math' && class_exists('RankMath') && function_exists('rank_math')) {
            $spun_data['focus_keyword'] = wgs_call_gemini_api(str_replace('[text]', substr($post->post_content, 0, 10000), $prompts['focus_keyword']), $options['gemini_model'], $api_key);
            $fields_spun[] = 'focus_keyword';
        } else if ($options['seo_plugin'] === 'rank_math') {
            error_log("WP Gemini Spinner: Rank Math is selected but not properly installed or activated");
            $status = 'error';
        }

        // Ensure content meets minimum word count
        $min_words = $options['min_word_count'];
        $retry_count = 0;
        $max_retries = 3;
        $word_count = 0;
        if ($options['spin_content']) {
            while ($retry_count < $max_retries) {
                $word_count = str_word_count(strip_tags($Parsedown->text($spun_data['content'])));
                error_log("WP Gemini Spinner: Word count for Post ID: $post_id is $word_count words");
                if ($word_count >= $min_words) {
                    break;
                }
                $spun_data['content'] = wgs_call_gemini_api(str_replace('[text]', substr($post->post_content, 0, 10000), $prompts['content'] . " Previous attempt had insufficient word count. Expand the content to at least $min_words words by adding relevant details, background information, and examples while maintaining the original meaning."), $options['gemini_model'], $api_key);
                $retry_count++;
            }

            // Fallback expansion
            if ($word_count < $min_words) {
                $spun_data['content'] = wgs_expand_content($spun_data['content'], $min_words, $post->post_title, $language);
                $word_count = str_word_count(strip_tags($Parsedown->text($spun_data['content'])));
                error_log("WP Gemini Spinner: After fallback, word count for Post ID: $post_id is $word_count words");
            }
        }

        // Update post
        $update_args = ['ID' => $post_id];
        if (!empty($spun_data['title'])) {
            $update_args['post_title'] = $spun_data['title'];
        }
        if (!empty($spun_data['content'])) {
            $update_args['post_content'] = $spun_data['content'];
        }
        if (!empty($spun_data['slug'])) {
            $update_args['post_name'] = $spun_data['slug'];
        }
        if (count($update_args) > 1) { // Ensure there's something to update
            wp_update_post($update_args);
            error_log("WP Gemini Spinner: Updated Post ID $post_id with new data: " . print_r($update_args, true));
        }

        // Update Rank Math meta
        if ($options['seo_plugin'] === 'rank_math' && class_exists('RankMath') && function_exists('rank_math')) {
            if (!empty($spun_data['meta_desc'])) {
                update_post_meta($post_id, 'rank_math_description', $spun_data['meta_desc']);
            }
            if (!empty($spun_data['seo_title'])) {
                update_post_meta($post_id, 'rank_math_title', $spun_data['seo_title']);
            }
            if (!empty($spun_data['focus_keyword'])) {
                update_post_meta($post_id, 'rank_math_focus_keyword', $spun_data['focus_keyword']);
            }
            error_log("WP Gemini Spinner: Updated Rank Math meta for Post ID $post_id");
        }
    } catch (Exception $e) {
        $status = 'error';
        error_log("WP Gemini Spinner: Error spinning Post ID: $post_id - " . $e->getMessage());
    }

    // Log spin
    $wpdb->insert(WGS_LOG_TABLE, [
        'post_id' => $post_id,
        'original_content' => $post->post_content,
        'spun_content' => $spun_data['content'] ?? $post->post_content,
        'word_count' => $word_count,
        'fields_spun' => implode(',', $fields_spun),
        'spin_date' => current_time('mysql'),
        'status' => $status
    ]);
    error_log("WP Gemini Spinner: Logged spin for Post ID $post_id, Status: $status, Fields: " . implode(',', $fields_spun));

    $is_processing = false;
}

// Gemini API call
function wgs_call_gemini_api($prompt, $model, $api_key) {
    $fallback_model = 'gemini-1.5-flash-latest';
    $max_attempts = 3;
    $attempt = 0;

    while ($attempt < $max_attempts) {
        $response = wp_remote_post("https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . urlencode($api_key), [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            $attempt++;
            error_log("WP Gemini Spinner: API request failed, attempt $attempt/$max_attempts: " . $response->get_error_message());
            if ($attempt === $max_attempts) {
                throw new Exception('Gemini API request failed after retries: ' . $response->get_error_message());
            }
            sleep(pow(2, $attempt)); // Exponential backoff
            continue;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        error_log("WP Gemini Spinner: API response status: $status_code, Response body: " . print_r($body, true));

        if ($status_code === 401) {
            throw new Exception('Invalid Gemini API key');
        } elseif (in_array($status_code, [429, 503])) {
            $attempt++;
            if ($attempt === $max_attempts) {
                throw new Exception('Gemini API rate limit or service unavailable');
            }
            sleep(pow(2, $attempt)); // Exponential backoff
            continue;
        } elseif ($status_code === 400 && $model !== $fallback_model) {
            error_log("WP Gemini Spinner: Model $model unavailable, falling back to $fallback_model");
            $model = $fallback_model;
            $attempt++;
            continue;
        } elseif ($status_code !== 200 || empty($body['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception("Gemini API returned invalid response (Status: $status_code)");
        }

        return $body['candidates'][0]['content']['parts'][0]['text'];
    }

    throw new Exception('Gemini API failed after maximum retries');
}

// Content expansion fallback
function wgs_expand_content($content, $min_words, $title, $language) {
    $Parsedown = new Parsedown();
    $current_words = str_word_count(strip_tags($Parsedown->text($content)));
    $keyword = sanitize_title($title);
    $language_label = $language === 'hindi' ? 'अतिरिक्त जानकारी' : 'Additional Information';
    $additional_content = "\n\n## $language_label\n\n**$keyword** के बारे में और जानकारी जो इस विषय को और गहराई से समझाती है।\n\n### पृष्ठभूमि जानकारी\n- **ऐतिहासिक संदर्भ**: $keyword का इतिहास और विकास।\n- **प्रमुख रुझान**: वर्तमान में **$keyword** से जुड़े रुझान।\n\n| पहलू | विवरण |\n|-------|-------|\n| **$keyword** का महत्व | इस क्षेत्र में इसका प्रभाव |\n| उपयोग के उदाहरण | वास्तविक जीवन में **$keyword** का उपयोग |\n\n### अतिरिक्त विवरण\n**$keyword** से संबंधित और जानकारी जो पाठकों के लिए उपयोगी हो सकती है।\n\n";
    $content .= $additional_content;
    $new_words = str_word_count(strip_tags($Parsedown->text($content)));
    if ($new_words < $min_words) {
        $content .= str_repeat("\n\n**$keyword** के बारे में और जानकारी जो इस विषय को और स्पष्ट करती है। यह पाठकों के लिए उपयोगी और प्रासंगिक है।\n", ceil(($min_words - $new_words) / 10));
    }
    return $content;
}

// Manual spin AJAX
add_action('wp_ajax_wgs_manual_spin', function() {
    check_ajax_referer('wgs_manual_spin', 'nonce');
    $content = sanitize_textarea_field($_POST['content'] ?? '');
    $language = sanitize_text_field($_POST['language'] ?? 'hindi');
    $custom_prompt = sanitize_textarea_field($_POST['prompt'] ?? '');
    $default_options = [
        'api_key' => '',
        'gemini_model' => 'gemini-1.5-flash-latest',
        'default_prompt' => 'Rewrite the provided text to be unique and engaging in [language]. Maintain the original meaning, ensure SEO compatibility, and use Markdown formatting. Original: "[text]"'
    ];
    $options = wp_parse_args(get_option('wgs_options', []), $default_options);
    $default_prompt = str_replace('[language]', $language, $options['default_prompt']);
    $prompt = $custom_prompt ?: $default_prompt;
    $prompt = str_replace('[text]', substr($content, 0, 10000), $prompt);

    try {
        $api_key = base64_decode($options['api_key']);
        if (empty($api_key) || is_wp_error(wgs_validate_api_key($api_key))) {
            wp_send_json_error(['message' => 'Invalid Gemini API key']);
        }
        $spun_content = wgs_call_gemini_api($prompt, $options['gemini_model'], $api_key);
        $Parsedown = new Parsedown();
        $word_count = str_word_count(strip_tags($Parsedown->text($spun_content)));
        error_log("WP Gemini Spinner: Manual spin word count: $word_count words");
        wp_send_json_success(['content' => $spun_content]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'कंटेंट रिवाइट करने में त्रुटि: ' . $e->getMessage()]);
    }
});

// Activity log AJAX
add_action('wp_ajax_wgs_refresh_log', function() {
    check_ajax_referer('wgs_manual_spin', 'nonce');
    global $wpdb;
    $logs = $wpdb->get_results("SELECT * FROM " . WGS_LOG_TABLE . " ORDER BY spin_date DESC LIMIT 50");
    $output = '';
    foreach ($logs as $log) {
        $output .= "<tr><td>" . esc_html($log->id) . "</td><td>" . esc_html($log->post_id) . "</td><td>" . esc_html($log->word_count) . "</td><td>" . esc_html($log->fields_spun) . "</td><td>" . esc_html($log->spin_date) . "</td><td><span class='status-{$log->status}'>" . esc_html($log->status) . "</span></td></tr>";
    }
    wp_send_json_success(['html' => $output]);
});

// Schedule cron for spinning queued posts
add_action('wgs_spin_cron', 'wgs_process_spin_queue');
function wgs_process_spin_queue() {
    $options = get_option('wgs_options', []);
    $default_options = [
        'post_types' => ['post'],
        'auto_spin_enabled' => true
    ];
    $options = wp_parse_args($options, $default_options);
    
    if (!$options['auto_spin_enabled']) {
        error_log("WP Gemini Spinner: Cron skipped, auto spinning disabled");
        return;
    }
    
    $posts = get_posts([
        'post_type' => $options['post_types'],
        'post_status' => 'publish',
        'meta_query' => [
            [
                'key' => '_wgs_spin_queued',
                'value' => '1',
                'compare' => '='
            ]
        ],
        'posts_per_page' => 5,
    ]);
    
    error_log("WP Gemini Spinner: Cron running, found " . count($posts) . " queued posts");
    
    foreach ($posts as $post) {
        try {
            if (!get_post_meta($post->ID, '_wgs_spin_processed', true)) {
                wgs_spin_post($post->ID, $post);
                update_post_meta($post->ID, '_wgs_spin_processed', '1');
                delete_post_meta($post->ID, '_wgs_spin_queued');
                error_log("WP Gemini Spinner: Successfully processed queued Post ID {$post->ID}");
            } else {
                error_log("WP Gemini Spinner: Queued Post ID {$post->ID} already processed, removing from queue");
                delete_post_meta($post->ID, '_wgs_spin_queued');
            }
        } catch (Exception $e) {
            error_log("WP Gemini Spinner: Failed to process queued Post ID {$post->ID}: " . $e->getMessage());
        }
    }
}

// Clean up cron on deactivation
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wgs_spin_cron');
});
?>