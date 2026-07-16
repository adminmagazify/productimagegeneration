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
// SKU parçası için: Türkçe karakterleri ASCII'ye çevir, büyük harf, sadece harf/rakam.
// "Yıldız" → "YILDIZ", "Kırmızı" → "KIRMIZI", "CTY001" → "CTY001"
if (!function_exists('pig_code_upper')) {
    function pig_code_upper($s) {
        $s = (string) $s;
        $tr = ['ç'=>'c','Ç'=>'C','ğ'=>'g','Ğ'=>'G','ı'=>'i','İ'=>'I','ö'=>'o','Ö'=>'O','ş'=>'s','Ş'=>'S','ü'=>'u','Ü'=>'U'];
        $s = strtr($s, $tr);
        $s = strtoupper($s);
        return preg_replace('/[^A-Z0-9]+/', '', $s);
    }
}

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
    $base = isset($all_codes[$collection_code]) ? trim($all_codes[$collection_code]) : $collection_code;
    // "basketbol" -> "Basketbol Koleksiyonu" (zaten "Koleksiyon" içeriyorsa tekrar ekleme)
    $collection_name = (stripos($base, 'koleksiyon') !== false)
        ? ucwords($base)
        : ucwords($base) . ' Koleksiyonu';

    // Tasarım adı (frontend'den) — dosya uzantısı ve ayraçları temizle, okunur hale getir
    $design_name = isset($_POST['design_name']) ? sanitize_text_field(wp_unslash($_POST['design_name'])) : '';
    $design_name = preg_replace('/\.(png|jpe?g|webp)$/i', '', $design_name);
    $design_name = trim(preg_replace('/[-_]+/', ' ', $design_name));
    $design_name = $design_name !== '' ? ucwords($design_name) : '';

    // Renk: seçili mockup dosya adının SON parçası ("Hoodie-Standart-Erkek-Siyah" → "Siyah")
    $mockup_name_in = isset($_POST['mockup_name']) ? sanitize_text_field(wp_unslash($_POST['mockup_name'])) : '';
    $mockup_base    = preg_replace('/\.(png|jpe?g|webp)$/i', '', $mockup_name_in);
    $mock_parts     = preg_split('/[-_\s]+/', trim((string) $mockup_base));
    $color          = (is_array($mock_parts) && count($mock_parts)) ? (string) end($mock_parts) : '';
    if ($color !== '') {
        $color = function_exists('mb_convert_case') ? mb_convert_case($color, MB_CASE_TITLE, 'UTF-8') : ucfirst($color);
    }

    // Görünen ürün adı: {Renk} {Kesim} {Ürün} {Cinsiyet}
    // Profil başlığından: Basic/Standart/Premium GİZLE, Oversize öne al, Tshirt→Tişört.
    // Örn: profil "Tshirt Oversize Premium Erkek" + renk "Siyah" → "Siyah Oversize Tişört Erkek"
    $ptitle = (isset($profile['profile_title']) && $profile['profile_title'] !== '')
        ? (string) $profile['profile_title'] : $visible_type;
    $clean = preg_replace('/\b(basic|standart|premium)\b/iu', '', $ptitle);
    $clean = preg_replace('/\btshirt\b|\btisort\b|\btiş?ört\b/iu', 'Tişört', $clean);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    if (preg_match('/\boversize\b/iu', $clean)) {
        $clean = trim(preg_replace('/\s+/', ' ', preg_replace('/\boversize\b/iu', '', $clean)));
        $clean = 'Oversize ' . $clean;
    }
    $product_name = trim(($color !== '' ? $color . ' ' : '') . $clean);

    // Slug: görünen ad + tasarım + koleksiyon no (tasarım dahil → gerçek ürün benzersiz)
    $product_slug = sanitize_title(
        $product_name .
        ($design_name !== '' ? "-{$design_name}" : '') .
        "-{$collection_number}" . ($isPreset ? "-{$lastPart}" : "")
    );

    // Duplicate kontrol — SLUG bazlı (görünen ad tasarımlar arası tekrar edebilir; slug tasarım dahil benzersiz)
    $existing_query = new WP_Query([
        'post_type'              => 'product',
        'post_status'            => 'any',
        'name'                   => $product_slug,
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

    // Beden listesi — productType'ın ürün CİNSİNDEN türetilir (format artık
    // {urun}-{premium/standart}-{cinsiyet} → sabit anahtar listesi tutmuyoruz).
    // Böylece hoodie-premium-kadin, tshirt-standart-erkek vb. TÜM kombinasyonlarda çalışır.
    $pt = strtolower($product_type);
    $first_tok = explode('-', $pt)[0];
    if (!empty($profile['sizes']) && is_array($profile['sizes'])) {
        $sizes = $profile['sizes'];
    } elseif (strpos($pt, 'cocuk') !== false || strpos($pt, 'çocuk') !== false) {
        $sizes = ['4-5 Yaş', '6-7 Yaş', '8-9 Yaş', '10-11 Yaş', '12-13 Yaş'];
    } elseif (strpos($pt, 'bebek') !== false) {
        $sizes = ['0-3 Ay', '3-6 Ay', '6-12 Ay', '12-18 Ay', '18-24 Ay'];
    } elseif ($first_tok === 'crop' || strpos($pt, 'crop') !== false) {
        $sizes = ['S', 'M', 'L', 'XL'];
    } elseif (in_array($first_tok, ['tshirt', 'hoodie', 'sweatshirt', 'sweat'], true)) {
        // Yetişkin giyim (premium & standart, kadın & erkek)
        $sizes = ['S', 'M', 'L', 'XL', 'XXL'];
    } else {
        // Tekstil-dışı (bez yastık, bardak vb.) → bedensiz, basit ürün
        $sizes = [];
    }
    $sizes = array_values(array_filter(array_map('trim', (array) $sizes), function ($s) { return $s !== ''; }));
    $has_sizes = !empty($sizes);

    // Ürün oluştur — bedenli tiplerde native VARYASYONLU ürün, yoksa basit ürün
    $product = $has_sizes ? new WC_Product_Variable() : new WC_Product_Simple();
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

    // SKU / ürün kodu: KOLEKSIYON-TASARIM-RENK-NO  (örn. BSK001-YILDIZ-SIYAH-4821)
    $sku_core = implode('-', array_filter([
        pig_code_upper($collectionPart),
        pig_code_upper($design_name),
        pig_code_upper($color),
    ]));
    $sku = ($sku_core !== '' ? $sku_core . '-' : '') . rand(1000, 9999);
    if (function_exists('wc_get_product_id_by_sku')) {
        while (wc_get_product_id_by_sku($sku)) {
            $sku = ($sku_core !== '' ? $sku_core . '-' : '') . rand(1000, 9999);
        }
    }
    $product->set_sku($sku);

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

    // Bedenli ürün: "Beden" özelliğini (ürüne özel attribute) varyasyon için tanımla
    if ($has_sizes) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_id(0); // 0 = taksonomisiz, ürüne özel özellik
        $attribute->set_name('Beden');
        $attribute->set_options($sizes);
        $attribute->set_position(0);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $product->set_attributes([$attribute]);
    }

    $product_id = $product->save();

    // Bedenli ürün: her beden için bir varyasyon (aynı fiyat, hep stokta — POD mantığı)
    if ($has_sizes && $product_id) {
        foreach ($sizes as $size_label) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_attributes(['beden' => $size_label]); // 'Beden' → sanitize_title = 'beden'
            $variation->set_regular_price($profile['price']);
            if (!empty($profile['sale_price'])) {
                $variation->set_sale_price($profile['sale_price']);
            }
            $variation->set_manage_stock(false);
            $variation->set_stock_status('instock');
            $variation->save();
        }
        WC_Product_Variable::sync($product_id); // fiyat aralığı/özet veriyi yeniden hesapla
    }

    // Profil bağı: bu ürün hangi profilden üretildi (fiyat senkronu için)
    if ($product_id && $product_type) {
        update_post_meta($product_id, '_pig_product_type', $product_type);
    }

    // (Beden seçimi artık native WooCommerce varyasyonu olarak yukarıda kuruluyor —
    //  eski WAPF/_wapf_fieldgroup yöntemi kaldırıldı.)

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