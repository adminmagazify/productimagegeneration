<?php
/**
 * Cloudflare R2 (S3 uyumlu) depolama istemcisi.
 *
 * Görseller R2'den otomatik listelenir (ListObjectsV2 + AWS SigV4 imzalama)
 * ve public URL üzerinden gösterilir. Google Drive'ın yerini alır.
 *
 * Yapılandırma (wp-config.php sabitleri options'ı geçersiz kılar):
 *   define('R2_ACCOUNT_ID', '...');
 *   define('R2_BUCKET',     '...');
 *   define('R2_PUBLIC_URL', 'https://cdn.site.com');   // sonunda / olmasa da olur
 *   define('R2_ACCESS_KEY', '...');
 *   define('R2_SECRET_KEY', '...');   // GİZLİ — tercihen wp-config'de tut
 *
 * Options (R2 Ayarları sayfasından):
 *   mockup_r2_account_id, mockup_r2_bucket, mockup_r2_public_url,
 *   mockup_r2_access_key, mockup_r2_secret_key,
 *   mockup_r2_mockups_prefix (varsayılan "mockups/"),
 *   mockup_r2_collections_prefix (varsayılan "koleksiyonlar/")
 *
 * R2'de beklenen yapı (Drive ile aynı mantık):
 *   mockups/Tshirt-Standart-Siyah.png            → ürün mockup'ları (düz)
 *   koleksiyonlar/MSC/0preview.png               → koleksiyon önizlemesi
 *   koleksiyonlar/MSC/Tasarim-1.png ...          → koleksiyon tasarımları
 */
class PigR2Storage {

    /** Koleksiyon kapaklarının bulunduğu özel klasör (koleksiyon değil). */
    const COVER_FOLDER = 'KOLEKSIYON-COVER';

    /* =========================================================
     *  HOOK KAYITLARI
     * ========================================================= */
    public function __construct() {
        // Öncelik 11: ana menü (mockup-creator) kaydolduktan SONRA alt menü ekle.
        add_action('admin_menu', array($this, 'add_menu'), 11);
    }

    /* =========================================================
     *  YAPILANDIRMA
     * ========================================================= */
    private static function cfg($const, $option) {
        if (defined($const) && constant($const)) {
            return (string) constant($const);
        }
        return (string) get_option($option, '');
    }

    public static function account_id() { return self::cfg('R2_ACCOUNT_ID', 'mockup_r2_account_id'); }
    public static function bucket()     { return self::cfg('R2_BUCKET',     'mockup_r2_bucket'); }
    public static function access_key() { return self::cfg('R2_ACCESS_KEY', 'mockup_r2_access_key'); }
    public static function secret_key() { return self::cfg('R2_SECRET_KEY', 'mockup_r2_secret_key'); }

    public static function public_url() {
        $u = self::cfg('R2_PUBLIC_URL', 'mockup_r2_public_url');
        return $u ? trailingslashit($u) : '';
    }

    private static function norm_prefix($value) {
        $p = ltrim((string) $value, '/');
        return $p === '' ? '' : trailingslashit($p);
    }

    public static function mockups_prefix() {
        return self::norm_prefix(get_option('mockup_r2_mockups_prefix', 'mockups/'));
    }

    public static function collections_prefix() {
        return self::norm_prefix(get_option('mockup_r2_collections_prefix', 'koleksiyonlar/'));
    }

    public static function is_configured() {
        return self::account_id() && self::bucket() && self::access_key() && self::secret_key();
    }

    /**
     * Obje anahtarından görüntülenebilir URL üretir.
     * public_url tanımlıysa onu (CDN/custom domain) kullanır; değilse görseli
     * site üzerinden servis eden proxy'yi kullanır (DNS/public URL gerekmez).
     */
    public static function url_for($key) {
        $key = ltrim((string) $key, '/');
        $pub = self::public_url();
        if ($pub !== '') {
            return $pub . $key;
        }
        return admin_url('admin-ajax.php') . '?action=pig_r2_img&key=' . rawurlencode($key);
    }

    /* =========================================================
     *  YARDIMCILAR
     * ========================================================= */
    private static function is_image($key) {
        return (bool) preg_match('/\.(png|jpe?g|webp|gif)$/i', $key);
    }

    /** "0preview.png", "preview.jpg", "0-preview.png" vb. esnek tanı. */
    private static function is_preview($name) {
        $base = strtolower(preg_replace('/\.[^.]+$/', '', $name));
        return strpos(preg_replace('/[^a-z]/', '', $base), 'preview') !== false;
    }

    private static function basename_key($key) {
        $key = rtrim((string) $key, '/');
        $pos = strrpos($key, '/');
        return $pos === false ? $key : substr($key, $pos + 1);
    }

    /** "koleksiyonlar/MSC/" + base "koleksiyonlar/" → "MSC" */
    private static function prefix_name($full, $base) {
        $n = (string) $full;
        if ($base !== '' && strpos($n, $base) === 0) {
            $n = substr($n, strlen($base));
        }
        return trim($n, '/');
    }

    /* =========================================================
     *  S3 LISTOBJECTSV2 + AWS SIGNATURE V4
     * ========================================================= */

    /** Tüm sayfaları (continuation token) dolaşarak obje + ortak prefiks listesini döner. */
    public static function list_objects($prefix = '', $delimiter = '') {
        // Önbellek: aynı listeyi 10 dk boyunca tekrar S3'ten çekme (hız)
        $cache_key = 'pig_r2_ls_' . md5(self::account_id() . '|' . self::bucket() . '|' . $prefix . '|' . $delimiter);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $all_objects  = array();
        $all_prefixes = array();
        $token        = '';

        do {
            $page = self::list_objects_page($prefix, $delimiter, $token);
            if (is_wp_error($page)) {
                return $page;
            }
            $all_objects  = array_merge($all_objects, $page['objects']);
            $all_prefixes = array_merge($all_prefixes, $page['prefixes']);
            $token        = $page['next_token'];
        } while ($token !== '');

        $result = array(
            'objects'  => $all_objects,
            'prefixes' => array_values(array_unique($all_prefixes)),
        );
        set_transient($cache_key, $result, 10 * MINUTE_IN_SECONDS);
        return $result;
    }

    private static function list_objects_page($prefix, $delimiter, $continuation_token) {

        $account = self::account_id();
        $bucket  = self::bucket();
        $access  = self::access_key();
        $secret  = self::secret_key();

        if (!$account || !$bucket || !$access || !$secret) {
            return new WP_Error('r2_config', 'R2 yapılandırması eksik (account / bucket / anahtarlar).');
        }

        $host    = $account . '.r2.cloudflarestorage.com';
        $region  = 'auto';
        $service = 's3';

        // --- Sıralı query parametreleri (AWS kanonik sıra) ---
        $params = array('list-type' => '2');
        if ($delimiter !== '')          $params['delimiter'] = $delimiter;
        if ($prefix !== '')             $params['prefix'] = $prefix;
        if ($continuation_token !== '') $params['continuation-token'] = $continuation_token;
        ksort($params);
        $canonical_query = self::build_query($params);

        $canonical_uri = '/' . rawurlencode($bucket);

        $amzdate      = gmdate('Ymd\THis\Z');
        $datestamp    = gmdate('Ymd');
        $payload_hash = hash('sha256', '');

        $canonical_headers = "host:{$host}\n"
            . "x-amz-content-sha256:{$payload_hash}\n"
            . "x-amz-date:{$amzdate}\n";
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';

        $canonical_request = "GET\n{$canonical_uri}\n{$canonical_query}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

        $algorithm = 'AWS4-HMAC-SHA256';
        $scope     = "{$datestamp}/{$region}/{$service}/aws4_request";
        $string_to_sign = "{$algorithm}\n{$amzdate}\n{$scope}\n" . hash('sha256', $canonical_request);

        $kDate    = hash_hmac('sha256', $datestamp, 'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);

        $authorization = "{$algorithm} Credential={$access}/{$scope}, "
            . "SignedHeaders={$signed_headers}, Signature={$signature}";

        $url = "https://{$host}{$canonical_uri}";
        if ($canonical_query !== '') {
            $url .= '?' . $canonical_query;
        }

        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization'        => $authorization,
                'x-amz-date'           => $amzdate,
                'x-amz-content-sha256' => $payload_hash,
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $msg = substr(wp_strip_all_tags($body), 0, 300);
            return new WP_Error('r2_http', 'R2 hatası (HTTP ' . $code . '): ' . $msg);
        }

        return self::parse_list_xml($body);
    }

    /** AWS kanonik query string: RFC3986 kodlama, key'e göre sıralı (önceden ksort). */
    private static function build_query($params) {
        $pairs = array();
        foreach ($params as $k => $v) {
            $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        return implode('&', $pairs);
    }

    private static function parse_list_xml($xml_string) {
        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($xml_string);
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            return new WP_Error('r2_xml', 'R2 yanıtı (XML) çözümlenemedi.');
        }

        $objects = array();
        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $c) {
                $objects[] = array(
                    'key'  => (string) $c->Key,
                    'size' => (int) $c->Size,
                );
            }
        }

        $prefixes = array();
        if (isset($xml->CommonPrefixes)) {
            foreach ($xml->CommonPrefixes as $p) {
                $prefixes[] = (string) $p->Prefix;
            }
        }

        $next = '';
        if (isset($xml->IsTruncated) && (string) $xml->IsTruncated === 'true'
            && isset($xml->NextContinuationToken)) {
            $next = (string) $xml->NextContinuationToken;
        }

        return array(
            'objects'    => $objects,
            'prefixes'   => $prefixes,
            'next_token' => $next,
        );
    }

    /* =========================================================
     *  TEK OBJE İNDİRME (imzalı GET) + GÖRSEL PROXY
     *  Görseller r2.dev/public URL yerine site üzerinden servis edilir.
     * ========================================================= */

    /** Bir objeyi R2'den indirir (S3 imzalı GET). Bytes veya WP_Error döner. */
    public static function get_object($key) {
        $account = self::account_id();
        $bucket  = self::bucket();
        $access  = self::access_key();
        $secret  = self::secret_key();

        if (!$account || !$bucket || !$access || !$secret) {
            return new WP_Error('r2_config', 'R2 yapılandırması eksik.');
        }

        $host    = $account . '.r2.cloudflarestorage.com';
        $region  = 'auto';
        $service = 's3';

        $canonical_uri = '/' . rawurlencode($bucket);
        foreach (explode('/', ltrim((string) $key, '/')) as $seg) {
            if ($seg === '') continue;
            $canonical_uri .= '/' . rawurlencode($seg);
        }

        $amzdate      = gmdate('Ymd\THis\Z');
        $datestamp    = gmdate('Ymd');
        $payload_hash = hash('sha256', '');

        $canonical_headers = "host:{$host}\n"
            . "x-amz-content-sha256:{$payload_hash}\n"
            . "x-amz-date:{$amzdate}\n";
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';

        $canonical_request = "GET\n{$canonical_uri}\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

        $algorithm = 'AWS4-HMAC-SHA256';
        $scope     = "{$datestamp}/{$region}/{$service}/aws4_request";
        $string_to_sign = "{$algorithm}\n{$amzdate}\n{$scope}\n" . hash('sha256', $canonical_request);

        $kDate    = hash_hmac('sha256', $datestamp, 'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);

        $authorization = "{$algorithm} Credential={$access}/{$scope}, "
            . "SignedHeaders={$signed_headers}, Signature={$signature}";

        $response = wp_remote_get("https://{$host}{$canonical_uri}", array(
            'timeout' => 30,
            'headers' => array(
                'Authorization'        => $authorization,
                'x-amz-date'           => $amzdate,
                'x-amz-content-sha256' => $payload_hash,
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('r2_http', 'R2 obje indirilemedi (HTTP ' . wp_remote_retrieve_response_code($response) . ')');
        }
        return wp_remote_retrieve_body($response);
    }

    /** Görsel proxy: R2'deki görseli site üzerinden servis eder (yerel önbellekli). */
    public function ajax_image() {
        $key = isset($_GET['key']) ? wp_unslash($_GET['key']) : '';
        $key = ltrim((string) $key, '/');

        // Güvenlik: yalnızca yapılandırılan prefiksler + görsel uzantısı; ".." yok
        if ($key === '' || strpos($key, '..') !== false || !self::is_image($key)) {
            status_header(400);
            exit;
        }
        $mck = self::mockups_prefix();
        $col = self::collections_prefix();
        $allowed = ($mck !== '' && strpos($key, $mck) === 0)
                || ($col !== '' && strpos($key, $col) === 0);
        if (!$allowed) {
            status_header(403);
            exit;
        }

        // İstenen küçük boyut (thumbnail genişliği), örn. ?w=90
        $w = isset($_GET['w']) ? max(0, min(1200, intval($_GET['w']))) : 0;

        // Yerel önbellek (ilk istekte R2'den indir, sonra dosyadan servis et)
        $up         = wp_upload_dir();
        $base_cache = trailingslashit($up['basedir']) . 'pig-r2-cache/';
        $full       = $base_cache . $key;

        if (!file_exists($full)) {
            $bytes = self::get_object($key);
            if (is_wp_error($bytes) || $bytes === '') {
                status_header(502);
                exit;
            }
            wp_mkdir_p(dirname($full));
            file_put_contents($full, $bytes);
        }

        // Küçük boyut istendiyse thumbnail üret + önbelleğe al
        $serve = $full;
        if ($w > 0) {
            $thumb = $base_cache . 'thumb-' . $w . '/' . $key;
            if (!file_exists($thumb)) {
                $resized = self::make_thumb($full, $w);
                if ($resized !== false) {
                    wp_mkdir_p(dirname($thumb));
                    file_put_contents($thumb, $resized);
                }
            }
            if (file_exists($thumb)) {
                $serve = $thumb;
            }
        }

        $ext   = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $types = array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif');
        $ctype = isset($types[$ext]) ? $types[$ext] : 'application/octet-stream';

        header('Content-Type: ' . $ctype);
        header('Content-Length: ' . filesize($serve));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($serve);
        exit;
    }

    /** GD ile küçük thumbnail üretir (genişlik $w px). Bytes ya da false döner. */
    private static function make_thumb($path, $w) {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return false;
        }
        $src = @imagecreatefromstring($data);
        if (!$src) {
            return false;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw <= $w) {                 // zaten küçük → orijinali kullan
            imagedestroy($src);
            return false;
        }
        $h   = (int) max(1, round($sh * ($w / $sw)));
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        ob_start();
        if ($ext === 'jpg' || $ext === 'jpeg') {
            imagejpeg($dst, null, 82);
        } elseif ($ext === 'webp' && function_exists('imagewebp')) {
            imagewebp($dst);
        } else {
            imagepng($dst);
        }
        $out = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);
        return $out;
    }

    /* =========================================================
     *  FRONTEND İÇİN AJAX VERİ KAYNAKLARI
     * ========================================================= */

    /** Ürün mockup'ları (frontend dropdown) */
    public function ajax_get_mockups() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $res = self::list_objects(self::mockups_prefix(), '/');
        if (is_wp_error($res)) {
            wp_send_json_error($res->get_error_message());
        }

        $out = array();
        foreach ($res['objects'] as $o) {
            if (!self::is_image($o['key'])) continue;
            $name = self::basename_key($o['key']);
            if ($name === '') continue;
            // "-arka" mockup'ları ürün listesinde gösterme (onlar arka görsel için)
            $stem = strtolower(preg_replace('/\.[^.]+$/', '', $name));
            if (substr($stem, -5) === '-arka') continue;
            $out[] = array(
                'id'   => $o['key'],
                'name' => $name,
                'url'  => self::url_for($o['key']),
            );
        }
        usort($out, function ($a, $b) { return strcoll($a['name'], $b['name']); });

        wp_send_json_success($out);
    }

    /** Koleksiyonlar = collections prefix'i altındaki "klasörler" (CommonPrefixes) */
    public function ajax_get_collections() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $base = self::collections_prefix();
        $res  = self::list_objects($base, '/');
        if (is_wp_error($res)) {
            wp_send_json_error($res->get_error_message());
        }

        $covers = self::cover_map();
        $out = array();
        foreach ($res['prefixes'] as $p) {
            $name = self::prefix_name($p, $base);
            if ($name === '') continue;
            // Kapak klasörü gerçek bir koleksiyon değil — listede gösterme
            if (strcasecmp($name, self::COVER_FOLDER) === 0) continue;
            $ck = strtolower($name);
            $out[] = array(
                'id'   => $p,      // ör: "koleksiyonlar/MSC/"
                'name' => $name,   // ör: "MSC"
                'url'  => isset($covers[$ck]) ? $covers[$ck] : '',  // kapak (thumbnail)
            );
        }
        usort($out, function ($a, $b) { return strcoll($a['name'], $b['name']); });

        wp_send_json_success($out);
    }

    /** Bir koleksiyonun tasarımları + önizleme */
    public function ajax_get_designs() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $col  = isset($_POST['collection_id']) ? sanitize_text_field(wp_unslash($_POST['collection_id'])) : '';
        $base = self::collections_prefix();

        if ($col === '') {
            wp_send_json_error('Koleksiyon belirtilmedi');
        }
        // Güvenlik: yalnızca yapılandırılan koleksiyon prefiksi altı
        if ($base !== '' && strpos($col, $base) !== 0) {
            wp_send_json_error('Geçersiz koleksiyon');
        }

        $res = self::list_objects($col, '/');
        if (is_wp_error($res)) {
            wp_send_json_error($res->get_error_message());
        }

        $designs        = array();
        $folder_preview = '';
        foreach ($res['objects'] as $o) {
            if (!self::is_image($o['key'])) continue;
            $name = self::basename_key($o['key']);
            if ($name === '') continue;
            $url = self::url_for($o['key']);

            if (self::is_preview($name)) {
                $folder_preview = $url;
                continue;
            }
            $designs[] = array(
                'id'   => $o['key'],
                'name' => $name,
                'url'  => $url,
            );
        }
        usort($designs, function ($a, $b) { return strcoll($a['name'], $b['name']); });

        // Önizleme önceliği: KOLEKSIYON-COVER kapağı > klasör içi "preview" > ilk tasarım
        $preview_url = self::cover_url_for_collection(self::prefix_name($col, $base));
        if ($preview_url === '') {
            $preview_url = $folder_preview;
        }
        if ($preview_url === '' && !empty($designs)) {
            $preview_url = $designs[0]['url'];
        }

        wp_send_json_success(array(
            'preview_url' => $preview_url,
            'designs'     => $designs,
        ));
    }

    /** KOLEKSIYON-COVER klasöründeki kapakları "muzik" => url şeklinde haritalar. */
    private static function cover_map() {
        $map = array();
        $cover_prefix = self::collections_prefix() . self::COVER_FOLDER . '/';
        $res = self::list_objects($cover_prefix, '/');
        if (is_wp_error($res)) {
            return $map;
        }
        foreach ($res['objects'] as $o) {
            if (!self::is_image($o['key'])) continue;
            $name = self::basename_key($o['key']);                      // "Muzik-Koleksiyonu.png"
            $core = strtolower(preg_replace('/\.[^.]+$/', '', $name));  // "muzik-koleksiyonu"
            $core = preg_replace('/[-_ ]*koleksiyonu$/', '', $core);    // "muzik"
            $core = trim($core, '-_ ');
            if ($core !== '') {
                $map[$core] = self::url_for($o['key']);
            }
        }
        return $map;
    }

    /** Koleksiyon adına uyan kapak görselinin URL'i (yoksa ''). */
    private static function cover_url_for_collection($collection_name) {
        $key = strtolower(trim((string) $collection_name));
        if ($key === '') {
            return '';
        }
        $map = self::cover_map();
        return isset($map[$key]) ? $map[$key] : '';
    }

    /** Bağlantı testi (admin) */
    public function ajax_test_connection() {
        check_ajax_referer('mockup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Yetki yok');
        }

        if (!self::is_configured()) {
            wp_send_json_error('Yapılandırma eksik. Account ID, Bucket, Public URL ve anahtarları girin.');
        }

        $mockups = self::list_objects(self::mockups_prefix(), '/');
        if (is_wp_error($mockups)) {
            wp_send_json_error($mockups->get_error_message());
        }

        $cols = self::list_objects(self::collections_prefix(), '/');
        if (is_wp_error($cols)) {
            wp_send_json_error($cols->get_error_message());
        }

        $img_count = 0;
        foreach ($mockups['objects'] as $o) {
            if (self::is_image($o['key'])) $img_count++;
        }

        wp_send_json_success(sprintf(
            'Bağlantı başarılı ✅  Mockup görseli: %d  •  Koleksiyon: %d',
            $img_count,
            count($cols['prefixes'])
        ));
    }

    /* =========================================================
     *  AYARLAR SAYFASI
     * ========================================================= */
    public function add_menu() {
        add_submenu_page(
            'mockup-creator',
            'R2 Depolama (Cloudflare)',
            'R2 Ayarları',
            'manage_options',
            'mockup-r2-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // KAYDET
        if (isset($_POST['save_r2']) && check_admin_referer('save_r2_settings')) {
            update_option('mockup_r2_account_id',         sanitize_text_field(wp_unslash($_POST['account_id'] ?? '')));
            update_option('mockup_r2_bucket',             sanitize_text_field(wp_unslash($_POST['bucket'] ?? '')));
            update_option('mockup_r2_public_url',         esc_url_raw(wp_unslash($_POST['public_url'] ?? '')));
            update_option('mockup_r2_access_key',         sanitize_text_field(wp_unslash($_POST['access_key'] ?? '')));
            update_option('mockup_r2_mockups_prefix',     sanitize_text_field(wp_unslash($_POST['mockups_prefix'] ?? '')));
            update_option('mockup_r2_collections_prefix', sanitize_text_field(wp_unslash($_POST['collections_prefix'] ?? '')));

            // Secret yalnızca yeni bir değer girildiyse güncellenir (boşsa eskisi korunur).
            $secret_in = trim((string) wp_unslash($_POST['secret_key'] ?? ''));
            if ($secret_in !== '') {
                update_option('mockup_r2_secret_key', $secret_in);
            }

            echo '<div class="notice notice-success is-dismissible"><p>R2 ayarları kaydedildi.</p></div>';
        }

        $const_account = defined('R2_ACCOUNT_ID') && R2_ACCOUNT_ID;
        $const_bucket  = defined('R2_BUCKET') && R2_BUCKET;
        $const_public  = defined('R2_PUBLIC_URL') && R2_PUBLIC_URL;
        $const_access  = defined('R2_ACCESS_KEY') && R2_ACCESS_KEY;
        $const_secret  = defined('R2_SECRET_KEY') && R2_SECRET_KEY;

        $account_id = get_option('mockup_r2_account_id', '');
        $bucket     = get_option('mockup_r2_bucket', '');
        $public     = get_option('mockup_r2_public_url', '');
        $access     = get_option('mockup_r2_access_key', '');
        $has_secret = (bool) get_option('mockup_r2_secret_key', '');
        $mck_prefix = get_option('mockup_r2_mockups_prefix', 'mockups/');
        $col_prefix = get_option('mockup_r2_collections_prefix', 'koleksiyonlar/');

        $from_const = ' <span style="color:#2271b1;">(wp-config.php sabitinden geliyor — burada düzenlemeye gerek yok)</span>';
        ?>
        <div class="wrap">
            <h1>R2 Depolama (Cloudflare)</h1>
            <p class="description">
                Görseller Cloudflare R2'den otomatik listelenir. Gizli anahtarı tercihen
                <code>wp-config.php</code> içinde <code>define('R2_SECRET_KEY', '...')</code> ile tanımla.
            </p>

            <form method="post">
                <?php wp_nonce_field('save_r2_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label>Account ID</label><?php echo $const_account ? $from_const : ''; ?></th>
                        <td><input type="text" name="account_id" class="regular-text"
                                   value="<?php echo esc_attr($account_id); ?>"
                                   placeholder="ör: 1a2b3c4d5e6f..."></td>
                    </tr>
                    <tr>
                        <th><label>Bucket Adı</label><?php echo $const_bucket ? $from_const : ''; ?></th>
                        <td><input type="text" name="bucket" class="regular-text"
                                   value="<?php echo esc_attr($bucket); ?>"
                                   placeholder="ör: gorseller"></td>
                    </tr>
                    <tr>
                        <th><label>Public URL</label><?php echo $const_public ? $from_const : ''; ?></th>
                        <td>
                            <input type="text" name="public_url" class="regular-text"
                                   value="<?php echo esc_attr($public); ?>"
                                   placeholder="https://cdn.siten.com veya https://pub-xxxx.r2.dev">
                            <p class="description">Bucket'ın public erişim adresi (r2.dev veya özel domain).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Access Key ID</label><?php echo $const_access ? $from_const : ''; ?></th>
                        <td><input type="text" name="access_key" class="regular-text"
                                   value="<?php echo esc_attr($access); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th><label>Secret Access Key</label><?php echo $const_secret ? $from_const : ''; ?></th>
                        <td>
                            <input type="password" name="secret_key" class="regular-text"
                                   value="" autocomplete="new-password"
                                   placeholder="<?php echo ($const_secret || $has_secret) ? '•••••• (kayıtlı — değiştirmek için yaz)' : 'gizli anahtar'; ?>">
                            <p class="description">Güvenlik için boş bırakırsan mevcut anahtar korunur.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Mockup Prefix</label></th>
                        <td><input type="text" name="mockups_prefix" class="regular-text"
                                   value="<?php echo esc_attr($mck_prefix); ?>" placeholder="mockups/"></td>
                    </tr>
                    <tr>
                        <th><label>Koleksiyon Prefix</label></th>
                        <td><input type="text" name="collections_prefix" class="regular-text"
                                   value="<?php echo esc_attr($col_prefix); ?>" placeholder="koleksiyonlar/"></td>
                    </tr>
                </table>

                <p>
                    <button class="button button-primary" name="save_r2">Kaydet</button>
                    <button type="button" class="button" id="r2-test-btn">Bağlantıyı Test Et</button>
                    <span id="r2-test-result" style="margin-left:10px;font-weight:600;"></span>
                </p>
            </form>
        </div>

        <script>
        jQuery(function ($) {
            $('#r2-test-btn').on('click', function () {
                var $r = $('#r2-test-result').css('color', '#666').text('Test ediliyor...');
                $.post(ajaxurl, {
                    action: 'test_r2_connection',
                    nonce: '<?php echo esc_js(wp_create_nonce('mockup_nonce')); ?>'
                }).done(function (res) {
                    if (res.success) {
                        $r.css('color', '#1a7f37').text(res.data);
                    } else {
                        $r.css('color', '#b32d2e').text('Hata: ' + res.data);
                    }
                }).fail(function () {
                    $r.css('color', '#b32d2e').text('İstek başarısız oldu.');
                });
            });
        });
        </script>
        <?php
    }
}
