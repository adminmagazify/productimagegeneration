<?php
class PigDriveAutomation {
    
    public function __construct() {
        add_action('init', array($this, 'init_drive_automation'));
        add_action('mockup_drive_auto_check', array($this, 'auto_check_drive_connection'));
    }
    
    public function init_drive_automation() {
        add_filter('cron_schedules', array($this, 'add_custom_cron_intervals'));
        
        if (!wp_next_scheduled('mockup_drive_auto_check')) {
            wp_schedule_event(time(), 'every_ten_minutes', 'mockup_drive_auto_check');
        }
    }
    
    public function add_custom_cron_intervals($schedules) {
        $schedules['every_ten_minutes'] = array(
            'interval' => 600,
            'display' => __('Her 10 Dakikada Bir')
        );
        
        $schedules['every_thirty_minutes'] = array(
            'interval' => 1800,
            'display' => __('Her 30 Dakikada Bir')
        );
        
        return $schedules;
    }
    
    public function auto_check_drive_connection() {
        $api_key = get_option('mockup_drive_api_key', '');
        $mockup_folder_id = get_option('mockup_drive_mockup_folder', '');

        if (empty($api_key)) {
            $this->log_drive_status('API anahtarı bulunamadı', 'error');
            return;
        }

        $result = $this->test_drive_connection($api_key, $mockup_folder_id);
        $this->handle_drive_check_result($result);
    }
    
    private function test_drive_connection($api_key, $folder_id) {
        $url = "https://www.googleapis.com/drive/v3/files";
        $params = [
            'q' => "'{$folder_id}' in parents",
            'key' => $api_key,
            'fields' => 'files(id,name,mimeType)',
            'pageSize' => 1
        ];
        
        $response = wp_remote_get($url . '?' . http_build_query($params), [
            'timeout' => 15
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Bağlantı hatası: ' . $response->get_error_message(),
                'file_count' => 0
            ];
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['error'])) {
            return [
                'success' => false,
                'message' => 'API hatası: ' . $data['error']['message'],
                'file_count' => 0
            ];
        }
        
        $file_count = isset($data['files']) ? count($data['files']) : 0;
        
        return [
            'success' => true,
            'message' => 'Bağlantı başarılı',
            'file_count' => $file_count
        ];
    }
    
    private function handle_drive_check_result($result) {
        $current_status = get_option('mockup_drive_last_status', 'unknown');
        $last_check = get_option('mockup_drive_last_check', 0);
        
        $new_status = $result['success'] ? 'connected' : 'disconnected';
        $status_changed = ($current_status !== $new_status);
        
        update_option('mockup_drive_last_status', $new_status);
        update_option('mockup_drive_last_check', time());
        update_option('mockup_drive_last_message', $result['message']);
        
        $this->log_drive_status($result['message'], $result['success'] ? 'success' : 'error');
        
        if ($status_changed || !$result['success']) {
            $this->send_drive_status_email($result, $status_changed);
        }
    }
    
    private function log_drive_status($message, $type = 'info') {
        $logs = get_option('mockup_drive_logs', []);
        $log_entry = [
            'time' => current_time('mysql'),
            'type' => $type,
            'message' => $message
        ];
        
        array_unshift($logs, $log_entry);
        $logs = array_slice($logs, 0, 50);
        update_option('mockup_drive_logs', $logs);
    }
    
    private function send_drive_status_email($result, $status_changed = false) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        if ($status_changed) {
            $subject = "📊 [Drive Bağlantı Durumu Değişti] - {$site_name}";
            $status_text = $result['success'] ? 'BAĞLANDI' : 'KOPUK';
        } else {
            $subject = "⚠ [Drive Bağlantı Hatası] - {$site_name}";
            $status_text = 'HATA';
        }
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .success { color: #28a745; }
                .error { color: #dc3545; }
                .info { color: #17a2b8; }
                .card { border: 1px solid #ddd; padding: 20px; border-radius: 5px; }
            </style>
        </head>
        <body>
            <h2>Google Drive Bağlantı Durumu</h2>
            <div class='card'>
                <p><strong>Site:</strong> {$site_name}</p>
                <p><strong>Durum:</strong> <span class='".($result['success'] ? 'success' : 'error')."'>{$status_text}</span></p>
                <p><strong>Mesaj:</strong> {$result['message']}</p>
                <p><strong>Dosya Sayısı:</strong> {$result['file_count']}</p>
                <p><strong>Zaman:</strong> ".current_time('mysql')."</p>
                <p><strong>Durum Değişimi:</strong> ".($status_changed ? 'Evet' : 'Hayır')."</p>
            </div>
            <p><a href='".admin_url('admin.php?page=mockup-creator')."'>Ayarları Kontrol Et →</a></p>
        </body>
        </html>
        ";
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>'
        ];
        
        wp_mail($admin_email, $subject, $message, $headers);
    }
    
    public function test_drive_connection_manual() {
        check_ajax_referer('mockup_nonce', 'nonce');
        
        $api_key = get_option('mockup_drive_api_key', '');
        $mockup_folder_id = get_option('mockup_drive_mockup_folder', '');
        
        if (empty($api_key)) {
            wp_send_json_error('API anahtarı bulunamadı');
        }
        
        $result = $this->test_drive_connection($api_key, $mockup_folder_id);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'file_count' => $result['file_count']
            ]);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function get_drive_files() {
        check_ajax_referer('mockup_nonce', 'nonce');
        
        $api_key = sanitize_text_field($_POST['api_key']);
        $folder_id = sanitize_text_field($_POST['folder_id']);
        
        $url = "https://www.googleapis.com/drive/v3/files";
        $params = [
            'q' => "'{$folder_id}' in parents",
            'key' => $api_key,
            'fields' => 'files(id,name,mimeType,webContentLink)'
        ];
        
        $response = wp_remote_get($url . '?' . http_build_query($params));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Drive bağlantı hatası: ' . $response->get_error_message());
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['error'])) {
            wp_send_json_error('Drive API hatası: ' . $data['error']['message']);
        }
        
        wp_send_json_success($data['files']);
    }
    
    public function save_settings() {
        check_ajax_referer('mockup_nonce', 'nonce');
        
        $settings = array(
            'api_key' => sanitize_text_field($_POST['api_key']),
            'mockup_folder' => sanitize_text_field($_POST['mockup_folder']),
            'koleksiyon_folder' => sanitize_text_field($_POST['koleksiyon_folder'])
        );
        
        foreach ($settings as $key => $value) {
            update_option('mockup_drive_' . $key, $value);
        }
        
        wp_send_json_success('Ayarlar kaydedildi');
    }
    
    public function activate_automation() {
        wp_schedule_event(time(), 'every_ten_minutes', 'mockup_drive_auto_check');
    }
    
    public function deactivate_automation() {
        wp_clear_scheduled_hook('mockup_drive_auto_check');
    }
}


/**
 * ------------------------------------------
 * 🔥 Sabit Permalink Üretici (En kritik parça)
 * ------------------------------------------
 */
function mc_stable_permalink($product_id) {
    $link = get_permalink($product_id);

    if ($link && !str_contains($link, "?post_type")) {
        return $link;
    }

    for ($i = 0; $i < 3; $i++) {
        usleep(150000);
        clean_post_cache($product_id);
        $link = get_permalink($product_id);

        if ($link && !str_contains($link, "?post_type")) {
            return $link;
        }
    }

    return get_permalink($product_id);
}

/**
 * ------------------------------------------
 * Ürün Oluşturma (AJAX)
 * ------------------------------------------
 */
add_action('wp_ajax_mockup_create_wc_product', 'pig_create_wc_product');
add_action('wp_ajax_nopriv_mockup_create_wc_product', 'pig_create_wc_product');

function pig_create_wc_product() {

    check_ajax_referer('mockup_nonce', 'nonce');

    $image_url = esc_url_raw($_POST['image_url']);
    $back_image_url = isset($_POST['back_image_url']) ? esc_url_raw($_POST['back_image_url']) : '';

    // Ürün tipi normalize
    $product_type = sanitize_text_field($_POST['product_type']);

    if (!$product_type) {
        wp_send_json_error("Ürün tipi alınamadı.");
    }

    // Profil yükle
    $profiles = get_option('mockup_product_profiles', []);
    if (empty($profiles)) {
        wp_send_json_error("Hiç profil tanımlanmamış.");
    }

    $profile = null;
    foreach ($profiles as $p) {
        if (
            isset($p['product_type']) &&
            trim(strtolower($p['product_type'])) === $product_type
        ) {
            $profile = $p;
            break;
        }
    }

    if (!$profile) {
        wp_send_json_error("Bu ürün tipi için profil bulunamadı! ($product_type)");
    }

    // Ürün adı oluşturma (senin mevcut kodun aynen duruyor)
    $filename = strtolower(basename($image_url));
    $filename = preg_replace('/\.(png|jpg|jpeg)$/', '', $filename);
    $parts = explode('-', $filename);

    $name_parts = [];

    if (count($parts) >= 3) {
        // Örn: bebek-body-antrasit
        $name_parts[] = ucfirst($parts[0]); // Bebek
        $name_parts[] = ucfirst($parts[1]); // Body
        $name_parts[] = ucfirst($parts[2]); // Antrasit  ← ✔ RENK EKLENDİ
    } elseif (count($parts) == 2) {
        $name_parts[] = ucfirst($parts[0]);
        $name_parts[] = ucfirst($parts[1]);
    } else {
        $name_parts[] = ucfirst($parts[0]);
    }

    $visible_type = implode(' ', $name_parts);

    // --- Koleksiyon & preset ayrıştırma --- //
    $lastIndex = count($parts) - 1;
    $lastPart  = strtoupper($parts[$lastIndex]); // örn: O3 veya CTY001

    $collectionPart = $lastPart;
    $isPreset       = false;

    // Eğer son parça O1, O2, O3 gibi preset ise
    if (preg_match('/^O[0-9]+$/', $lastPart) && $lastIndex > 0) {
        $isPreset       = true;
        $collectionPart = strtoupper($parts[$lastIndex - 1]); // örn: CTY001
    }

    // Koleksiyon kodu & numarası
    $collection_code   = substr($collectionPart, 0, 3);
    $collection_number = substr($collectionPart, -3);

    $all_codes = get_option('mockup_collection_codes', []);
    $collection_name = isset($all_codes[$collection_code])
        ? $all_codes[$collection_code]
        : "{$collection_code} Koleksiyonu";

    // Ürün adı: tür + koleksiyon adı + koleksiyon numarası + preset (varsa)
    $product_name = "{$visible_type} #{$collection_name} #{$collection_number}";

    // Preset varsa (#O3 gibi) ürün adına ekle
    if ($isPreset) {
        $product_name .= " #{$lastPart}";
    }

    // Slug da aynı sırayı izlesin
    $product_slug = sanitize_title("{$visible_type}-{$collection_name}-{$collection_number}" . ($isPreset ? "-{$lastPart}" : ""));

    // Duplicate kontrol (get_page_by_title WP 6.2+ ile deprecated)
    $existing_query = new WP_Query([
        'post_type'              => 'product',
        'post_status'            => 'any',
        'title'                  => $product_name,
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ]);

    if (!empty($existing_query->posts)) {
        $existing_id = $existing_query->posts[0];
        wp_send_json_success([
            'id'  => $existing_id,
            'url' => get_permalink($existing_id),
            'already_exists' => true
        ]);
    }

    // Ürün oluştur
    $product = new WC_Product_Simple();
    $product->set_name($product_name);
    $product->set_slug($product_slug);

    // 🔥 Profil açıklamalarını güvenli şekilde WooCommerce'e aktar
    $desc  = isset($profile['description']) ? trim(wp_kses_post($profile['description'])) : '';
    $short = isset($profile['short_description']) ? trim(wp_kses_post($profile['short_description'])) : '';

    if (!empty($desc)) {
        $product->set_description($desc);
    }

    if (!empty($short)) {
        $product->set_short_description($short);
    }

    $product->set_regular_price($profile['price']);

    if (!empty($profile['sale_price'])) {
        $product->set_sale_price($profile['sale_price']);
    }

    if (!empty($profile['kategori'])) {
        $product->set_category_ids($profile['kategori']);
    }

    $product->set_sku($profile['sku_prefix'] . rand(10000, 99999));

    // Stok yönetimi
    $mode = $profile['stock_mode'] ?? 'instock';
    $qty  = intval($profile['stock_quantity'] ?? 0);

    switch ($mode) {
        case 'managed':
            $product->set_manage_stock(true);
            $product->set_stock_quantity($qty);
            $product->set_backorders('no');
            $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
            break;

        case 'backorder':
            $product->set_manage_stock(true);
            $product->set_backorders('notify');
            $product->set_stock_quantity(0);
            $product->set_stock_status('onbackorder');
            break;

        case 'outofstock':
            $product->set_manage_stock(false);
            $product->set_backorders('no');
            $product->set_stock_status('outofstock');
            break;

        case 'instock':
        default:
            $product->set_manage_stock(false);
            $product->set_backorders('no');
            $product->set_stock_status('instock');
            break;
    }

    $product_id = $product->save();

    // Profil bağı: bu ürün hangi profilden üretildi (fiyat senkronu için)
    if ($product_id && $product_type) {
        update_post_meta($product_id, '_pig_product_type', $product_type);
    }

    /**
     * =====================================================
     *  APF — "Beden Seçimi" Alanını Otomatik Oluştur
     * =====================================================
     */

    // 1) Beden preset'lerini tanımla
    $size_presets = [
        // product_type => beden listesi
        'tshirt-standart' => ['XS','S','M','L','XL','2XL'],
        'tshirt-oversize' => ['S','M','L','XL'],
        'hoodie-standart' => ['S','M','L','XL','2XL'],
        'sweatshirt-standart' => ['S','M','L','XL','2XL'],
        'crop-top' => ['S','M','L','XL'],
        'tshirt-cocuk' => ['1-2 Yaş','3-4 Yaş','5-6 Yaş','7-8- Yaş','9-10 Yaş','10-11 Yaş'],
        'bebek-body' => ['0-3 Ay','3-6 Ay','6-12 Ay','12-18 Ay','18-24 Ay'],
        // ihtiyaca göre buraya yenilerini eklersin
    ];

    // 2) Önce profilden oku, boşsa preset kullan
    $sizes = [];
    if (!empty($profile['sizes']) && is_array($profile['sizes'])) {
        // ileride profil tarafına "bedenler" alanı eklediğimizde burası devreye girecek
        $sizes = $profile['sizes'];
    } elseif (isset($size_presets[$product_type])) {
        // şimdilik sadece preset
        $sizes = $size_presets[$product_type];
    }

    if (!empty($sizes)) {

        // APF'nin kullandığı formata benzeyen küçük slug generator
        if (!function_exists('mc_apf_random_slug')) {
            function mc_apf_random_slug() {
                $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
                $s = '';
                for ($i = 0; $i < 5; $i++) {
                    $s .= $chars[rand(0, strlen($chars) - 1)];
                }
                return $s;
            }
        }

        // 3) Seçenekleri APF formatında hazırla
        $choices = [];
        foreach ($sizes as $size_label) {
            $label = trim($size_label);
            if ($label === '') continue;

            $choices[] = [
                "slug"           => mc_apf_random_slug(),
                "label"          => $label,
                "selected"       => false,
                "disabled"       => false,
                "options"        => [],
                "pricing_type"   => "none",
                "pricing_amount" => 0,
            ];
        }

        if (!empty($choices)) {

            $field_id = substr(md5(uniqid('', true)), 0, 7); // 7 karakterlik ID

            // 4) APF fieldgroup array — manuel ürün rule’u koymuyoruz
            $apf = [
                "id"        => "p_" . $product_id,
                "type"      => "wapf_product",
                "layout"    => [
                    "labels_position"       => "above",
                    "instructions_position" => "field",
                    "mark_required"         => true,
                    "enable_gallery_images" => false,
                    "gallery_images"        => [],
                    "swap_type"             => "rules",
                ],
                "variables" => [],
                "fields"    => [
                    [
                        "id"           => $field_id,
                        "label"        => "Beden Seçimi",
                        "description"  => null,
                        "type"         => "select",
                        "required"     => true,
                        "class"        => null,
                        "width"        => null,
                        "parent_clone" => [],
                        "options"      => [
                            "choices" => $choices,
                            "group"   => "field",
                        ],
                        "conditionals" => [],
                        "clone"        => ["enabled" => false],
                        "pricing"      => [
                            "type"    => "fixed",
                            "amount"  => 0,
                            "enabled" => false,
                        ],
                    ]
                ],
                // rule_groups boş → bu fieldgroup doğrudan ürün meta’sından okunuyor
                "rule_groups" => [],
            ];

            // 5) APF'nin gerçekten kullandığı meta key
            update_post_meta($product_id, '_wapf_fieldgroup', serialize($apf));
            update_post_meta($product_id, '_wapf_layers_enabled', "1");
        }
    }

    // Marka ekle
    if (!empty($profile['brands'])) {
        $brand_slugs = [];
        foreach ($profile['brands'] as $bid) {
            $term = get_term($bid, 'product_brand');
            if ($term && !is_wp_error($term)) {
                $brand_slugs[] = $term->slug;
            }
        }
        if (!empty($brand_slugs)) {
            wp_set_object_terms($product_id, $brand_slugs, 'product_brand', false);
        }
    }

    // Ürün görseli
    $upload_dir = wp_upload_dir();
    $image_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);

    if (!file_exists($image_path)) {
        wp_send_json_error("Görsel dosyası bulunamadı: $image_path");
    }

    $attachment = [
        'post_mime_type' => 'image/png',
        'post_title'     => sanitize_file_name(basename($image_path)),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ];

    // Attachment'ı oluştur (thumbnail/metadata ÜRETME — onu yanıttan sonra yapacağız)
    $attach_id = wp_insert_attachment($attachment, $image_path, $product_id);
    $product->set_image_id($attach_id);

    // =====================================================
    // ARKA GÖRSEL → GALERİ (metadata yine sonra üretilir)
    // =====================================================
    $back_attach_id = 0;
    $back_path      = '';
    if (!empty($back_image_url)) {
        $back_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $back_image_url);

        if (file_exists($back_path)) {
            $back_attachment = [
                'post_mime_type' => 'image/png',
                'post_title'     => sanitize_file_name(basename($back_path)),
                'post_content'   => '',
                'post_status'    => 'inherit'
            ];
            $back_attach_id = wp_insert_attachment($back_attachment, $back_path, $product_id);
            $product->set_gallery_image_ids([$back_attach_id]);
        }
    }

    $product->save();

    // =====================================================
    // BEDEN TABLOSU
    // =====================================================
    if (!empty($profile['size_chart'])) {
        update_post_meta($product_id, '_ts_size_chart', $profile['size_chart']);
        update_post_meta($product_id, 'ts_prod_size_chart', $profile['size_chart']);
    }

    // =====================================================
    // 🔥 GÖNDERİM SINIFI
    // =====================================================
    if (!empty($profile['shipping_class'])) {
        $term = get_term_by('slug', $profile['shipping_class'], 'product_shipping_class');
        if ($term && !is_wp_error($term)) {
            wp_set_object_terms($product_id, intval($term->term_id), 'product_shipping_class', false);
        }
    }

    // Sabit URL
    $stable_url = mc_stable_permalink($product_id);

    // =====================================================
    // YANITI HEMEN GÖNDER → operatör beklemesin
    // Ağır thumbnail üretimi bağlantı kapandıktan SONRA yapılır.
    // =====================================================
    @header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    echo wp_json_encode([
        'success' => true,
        'data'    => ['id' => $product_id, 'url' => $stable_url],
    ]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();           // PHP-FPM
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();         // LiteSpeed
    } else {
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }

    // --- İstemci artık beklemiyor: thumbnail boyutlarını burada üret ---
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $meta = wp_generate_attachment_metadata($attach_id, $image_path);
    wp_update_attachment_metadata($attach_id, $meta);

    if ($back_attach_id && $back_path && file_exists($back_path)) {
        $bmeta = wp_generate_attachment_metadata($back_attach_id, $back_path);
        wp_update_attachment_metadata($back_attach_id, $bmeta);
    }

    exit;
}