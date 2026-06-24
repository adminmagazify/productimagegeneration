<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merkez senkronizasyon — Ürün Profillerini wp-central (Railway) ile eşitler.
 *
 * Profiller merkezde "isim bazlı" tutulur (kategori/marka/beden tablosu ADLARI);
 * bu site çekerken adları kendi term'lerine eşler. Auth için wp-distributor'ın
 * zaten sakladığı kimlik bilgilerini paylaşır (ayrı kayıt gerekmez):
 *   - get_option('wpd_central_url')  → merkez API adresi
 *   - get_option('wpd_api_key')      → site API anahtarı
 */
class PigCentralSync {

    const OPTION = 'mockup_product_profiles';

    private static function central_url() {
        return rtrim(trim(get_option('wpd_central_url', '')), '/');
    }
    private static function api_key() {
        return trim(get_option('wpd_api_key', ''));
    }
    public static function is_ready() {
        return self::central_url() !== '' && self::api_key() !== '';
    }

    /** Yereldeki mevcut profilleri merkeze aktarır (ilk kurulum). */
    public static function import_to_central() {
        if (!self::is_ready()) {
            return ['success' => false, 'message' => 'wp-distributor merkeze kayıtlı değil (API anahtarı yok). Önce Ürün Dağıtım panelinden kaydolun.'];
        }
        $profiles = get_option(self::OPTION, []);
        if (empty($profiles)) {
            return ['success' => false, 'message' => 'Aktarılacak yerel profil yok.'];
        }
        $payload = [];
        foreach ($profiles as $p) {
            $payload[] = self::to_named($p);
        }
        $res = wp_remote_post(self::central_url() . '/api/public/mockup-profiles/import', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => self::api_key(),
            ],
            'body' => wp_json_encode(['profiles' => $payload]),
        ]);
        if (is_wp_error($res)) {
            return ['success' => false, 'message' => $res->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'message' => 'Merkez hatası (HTTP ' . $code . ')'];
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return ['success' => true, 'imported' => isset($data['imported']) ? (int) $data['imported'] : 0];
    }

    /** Merkezden profilleri çeker ve yerel option'ı merkezdekiyle değiştirir. */
    public static function pull_from_central() {
        if (!self::is_ready()) {
            return ['success' => false, 'message' => 'wp-distributor merkeze kayıtlı değil (API anahtarı yok). Önce Ürün Dağıtım panelinden kaydolun.'];
        }
        $res = wp_remote_get(self::central_url() . '/api/public/mockup-profiles', [
            'timeout' => 30,
            'headers' => ['X-API-Key' => self::api_key()],
        ]);
        if (is_wp_error($res)) {
            return ['success' => false, 'message' => $res->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'message' => 'Merkez hatası (HTTP ' . $code . ')'];
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $incoming = (is_array($data) && isset($data['profiles']) && is_array($data['profiles'])) ? $data['profiles'] : [];

        $profiles = [];
        foreach ($incoming as $row) {
            // Merkez ID'siyle anahtarla → tekrar çekişte çoğalmaz, eşleme korunur
            $key = isset($row['id']) ? 'central_' . intval($row['id']) : uniqid('profile_');
            $profiles[$key] = self::from_named($row);
        }
        update_option(self::OPTION, $profiles);
        $priceUpdated = self::sync_product_prices($profiles);
        return ['success' => true, 'count' => count($profiles), 'pricesUpdated' => $priceUpdated];
    }

    /** Yerel profil (term_id'li) → merkez formatı (isim bazlı). */
    private static function to_named($p) {
        $cats = [];
        foreach ((array) (isset($p['kategori']) ? $p['kategori'] : []) as $tid) {
            $t = get_term((int) $tid, 'product_cat');
            if ($t && !is_wp_error($t)) {
                $cats[] = $t->name;
            }
        }
        $brands = [];
        foreach ((array) (isset($p['brands']) ? $p['brands'] : []) as $tid) {
            $t = get_term((int) $tid, 'product_brand');
            if ($t && !is_wp_error($t)) {
                $brands[] = $t->name;
            }
        }
        $chart_name = '';
        if (!empty($p['size_chart'])) {
            $title = get_the_title((int) $p['size_chart']);
            if ($title) {
                $chart_name = $title;
            }
        }
        return [
            'title'            => (string) (isset($p['profile_title']) ? $p['profile_title'] : ''),
            'productType'      => (string) (isset($p['product_type']) ? $p['product_type'] : ''),
            'price'            => (string) (isset($p['price']) ? $p['price'] : ''),
            'salePrice'        => (string) (isset($p['sale_price']) ? $p['sale_price'] : ''),
            'categoryNames'    => $cats,
            'brandNames'       => $brands,
            'skuPrefix'        => (string) (isset($p['sku_prefix']) ? $p['sku_prefix'] : ''),
            'stockMode'        => (string) (isset($p['stock_mode']) ? $p['stock_mode'] : 'instock'),
            'stockQuantity'    => (int) (isset($p['stock_quantity']) ? $p['stock_quantity'] : 0),
            'shortDescription' => (string) (isset($p['short_description']) ? $p['short_description'] : ''),
            'description'      => (string) (isset($p['description']) ? $p['description'] : ''),
            'sizeChartName'    => $chart_name,
            'shippingClass'    => (string) (isset($p['shipping_class']) ? $p['shipping_class'] : ''),
        ];
    }

    /** Merkez formatı (isim bazlı) → yerel profil (term_id'li, mevcut yapı). */
    private static function from_named($row) {
        $cat_ids = [];
        foreach ((array) (isset($row['categoryNames']) ? $row['categoryNames'] : []) as $name) {
            $tid = self::ensure_term($name, 'product_cat');
            if ($tid) {
                $cat_ids[] = $tid;
            }
        }
        $brand_ids = [];
        foreach ((array) (isset($row['brandNames']) ? $row['brandNames'] : []) as $name) {
            $tid = self::ensure_term($name, 'product_brand');
            if ($tid) {
                $brand_ids[] = $tid;
            }
        }
        $chart_id = '';
        if (!empty($row['sizeChartName'])) {
            $cid = self::find_size_chart((string) $row['sizeChartName']);
            if ($cid) {
                $chart_id = $cid;
            }
        }
        return [
            'profile_title'     => sanitize_text_field(isset($row['title']) ? $row['title'] : ''),
            'product_type'      => sanitize_text_field(isset($row['productType']) ? $row['productType'] : ''),
            'price'             => sanitize_text_field(isset($row['price']) ? $row['price'] : ''),
            'sale_price'        => sanitize_text_field(isset($row['salePrice']) ? $row['salePrice'] : ''),
            'kategori'          => $cat_ids,
            'brands'            => $brand_ids,
            'sku_prefix'        => sanitize_text_field(isset($row['skuPrefix']) ? $row['skuPrefix'] : ''),
            'stock_mode'        => sanitize_text_field(isset($row['stockMode']) ? $row['stockMode'] : 'instock'),
            'stock_quantity'    => intval(isset($row['stockQuantity']) ? $row['stockQuantity'] : 0),
            'short_description' => wp_kses_post(isset($row['shortDescription']) ? $row['shortDescription'] : ''),
            'description'       => wp_kses_post(isset($row['description']) ? $row['description'] : ''),
            'size_chart'        => $chart_id,
            'shipping_class'    => sanitize_text_field(isset($row['shippingClass']) ? $row['shippingClass'] : ''),
            'custom_fields'     => [],
        ];
    }

    /** Taksonomi term'ini isimle bulur; yoksa oluşturur. */
    private static function ensure_term($name, $taxonomy) {
        $name = trim((string) $name);
        if ($name === '' || !taxonomy_exists($taxonomy)) {
            return 0;
        }
        $term = get_term_by('name', $name, $taxonomy);
        if ($term) {
            return (int) $term->term_id;
        }
        $created = wp_insert_term($name, $taxonomy);
        if (is_wp_error($created)) {
            $term = get_term_by('name', $name, $taxonomy);
            return $term ? (int) $term->term_id : 0;
        }
        return (int) $created['term_id'];
    }

    /**
     * REST: merkezin profilleri push ettiği endpoint.
     * URL: POST /wp-json/pig/v1/mockup-profiles
     * Auth: X-Central-Key + X-Central-Secret = wpd_api_key + wpd_api_secret (wp-distributor ile ortak).
     */
    public static function register_rest_routes() {
        register_rest_route('pig/v1', '/mockup-profiles', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_push'],
            'permission_callback' => [__CLASS__, 'check_central_auth'],
        ]);
    }

    public static function check_central_auth($request) {
        $key    = (string) $request->get_header('x-central-key');
        $secret = (string) $request->get_header('x-central-secret');
        $stored_key    = trim(get_option('wpd_api_key', ''));
        $stored_secret = trim(get_option('wpd_api_secret', ''));
        if (!$stored_key || !$stored_secret) {
            return false;
        }
        return hash_equals($stored_key, $key) && hash_equals($stored_secret, $secret);
    }

    /** Merkezden gelen profilleri yerel option'a yazar (term eşlemesiyle). */
    public static function handle_push($request) {
        $body = $request->get_json_params();
        $incoming = (is_array($body) && isset($body['profiles']) && is_array($body['profiles'])) ? $body['profiles'] : [];
        $profiles = [];
        foreach ($incoming as $row) {
            $key = isset($row['id']) ? 'central_' . intval($row['id']) : uniqid('profile_');
            $profiles[$key] = self::from_named($row);
        }
        update_option(self::OPTION, $profiles);
        $priceUpdated = self::sync_product_prices($profiles);
        return rest_ensure_response(['applied' => count($profiles), 'pricesUpdated' => $priceUpdated]);
    }

    /** Beden tablosunu (ts_size_chart) başlığıyla bulur. */
    private static function find_size_chart($name) {
        $name = trim($name);
        if ($name === '' || !post_type_exists('ts_size_chart')) {
            return 0;
        }
        $posts = get_posts([
            'post_type'   => 'ts_size_chart',
            'title'       => $name,
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        return !empty($posts) ? (int) $posts[0] : 0;
    }

    /**
     * Profillere ait mevcut WooCommerce ürünlerinin fiyatlarını profil fiyatıyla günceller.
     * Eşleme: _pig_product_type meta'sı (üretilen ürünler) + SKU prefix (geriye dönük eski ürünler).
     */
    private static function sync_product_prices($profiles) {
        if (!function_exists('wc_get_product')) {
            return 0;
        }
        $updated = 0;
        foreach ($profiles as $p) {
            $ptype = isset($p['product_type']) ? trim((string) $p['product_type']) : '';
            if ($ptype === '') {
                continue;
            }
            $price  = isset($p['price']) ? (string) $p['price'] : '';
            $sale   = isset($p['sale_price']) ? (string) $p['sale_price'] : '';
            $prefix = isset($p['sku_prefix']) ? trim((string) $p['sku_prefix']) : '';

            foreach (self::find_products_for_profile($ptype, $prefix) as $pid) {
                $product = wc_get_product($pid);
                if (!$product || $product->get_type() === 'variation') {
                    continue;
                }
                if ($price !== '') {
                    $product->set_regular_price($price);
                }
                $product->set_sale_price(($sale !== '' && $sale !== null) ? $sale : '');
                $product->save();

                // Mağaza/arşiv listesindeki fiyat da güncellensin diye cache temizle
                // (tekil ürün sayfası canlı çeker; arşiv cache'lenmiş fiyatı gösterir).
                if (function_exists('wc_delete_product_transients')) {
                    wc_delete_product_transients($pid);
                }
                clean_post_cache($pid);
                // LiteSpeed Cache: bu ürünün sayfa cache'ini purge et (eklenti yoksa no-op)
                do_action('litespeed_purge_post', $pid);

                // Geriye dönük eşlenen ürüne kalıcı bağ ekle
                if (!get_post_meta($pid, '_pig_product_type', true)) {
                    update_post_meta($pid, '_pig_product_type', $ptype);
                }
                $updated++;
            }
        }

        // Toplu fiyat değişti: ürün arşivi/fiyat-aralığı transient'lerini topluca tazele
        if ($updated > 0 && function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }
        // LiteSpeed Cache: mağaza/arşiv sayfaları HTML cache'li → tümünü tazele
        // (fiyat senkronu seyrek/manuel tetiklenir, tam purge kabul edilebilir).
        if ($updated > 0) {
            do_action('litespeed_purge_all');
        }
        return $updated;
    }

    /** Profile ait ürün ID'leri: önce _pig_product_type meta, sonra SKU prefix (geriye dönük). */
    private static function find_products_for_profile($ptype, $prefix) {
        $ids = get_posts([
            'post_type'   => 'product',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_key'    => '_pig_product_type',
            'meta_value'  => $ptype,
        ]);
        if ($prefix !== '') {
            global $wpdb;
            $like = $wpdb->esc_like($prefix) . '%';
            $bySku = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s",
                $like
            ));
            $ids = array_merge($ids, array_map('intval', (array) $bySku));
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
