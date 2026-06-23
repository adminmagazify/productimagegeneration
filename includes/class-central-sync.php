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
        return ['success' => true, 'count' => count($profiles)];
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
}
