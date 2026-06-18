<?php
class MockupImageProcessor {
    
    public function generate_mockup() {
        check_ajax_referer('mockup_nonce', 'nonce');
        
        $mockup_id = sanitize_text_field($_POST['mockup_id']);
        $design_id = sanitize_text_field($_POST['design_id']);
        $width_percent = intval($_POST['width_percent']);
        $left_percent = intval($_POST['left_percent']);
        $top_percent = intval($_POST['top_percent']);
        $preset_code = isset($_POST['preset_code']) ? sanitize_text_field($_POST['preset_code']) : '';
        
        $api_key = get_option('mockup_drive_api_key', '');
        
        if (!$api_key || !$mockup_id || !$design_id) {
            wp_send_json_error('Eksik parametreler: API anahtarı veya dosya IDleri');
        }
        
        try {
            $mockup_info = $this->get_drive_file_info($api_key, $mockup_id);
            $design_info = $this->get_drive_file_info($api_key, $design_id);
            
            if (!$mockup_info || !$design_info) {
                wp_send_json_error('Dosya bilgileri alınamadı');
            }

            $mockup_url = "https://drive.google.com/uc?export=view&id={$mockup_id}";
            $design_url = "https://drive.google.com/uc?export=view&id={$design_id}";
            
            $result = $this->create_composite_image(
                $mockup_url, 
                $design_url, 
                $width_percent, 
                $left_percent, 
                $top_percent,
                $mockup_info['name'],
                $design_info['name'],
                $preset_code
            );
            
            if ($result['success']) {
                wp_send_json_success(array(
                    'url' => $result['url'],
                    'message' => 'Ürün görseli başarıyla oluşturuldu!',
                    'mockup_name' => $mockup_info['name'],
                    'design_name' => $design_info['name']
                ));
            } else {
                wp_send_json_error($result['message']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Ürün görseli oluşturma hatası: ' . $e->getMessage());
        }
    }
    
    /* =========================================================
     *  YENİ: MEDYA KÜTÜPHANESİ TABANLI ÖN/ARKA ÜRETİM
     * ========================================================= */
    public function generate_mockup_v2() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $mockup_id       = sanitize_text_field($_POST['mockup_id'] ?? '');
        $front_design_id = intval($_POST['front_design_id'] ?? 0);
        $back_design_id  = intval($_POST['back_design_id'] ?? 0);
        $width_percent   = intval($_POST['width_percent'] ?? 0);
        $left_percent    = intval($_POST['left_percent'] ?? 0);
        $top_percent     = intval($_POST['top_percent'] ?? 0);
        $preset_code     = isset($_POST['preset_code']) ? sanitize_text_field($_POST['preset_code']) : '';

        $items = MockupLibraryManager::get_items();
        if (!isset($items[$mockup_id])) {
            wp_send_json_error('Mockup bulunamadı');
        }
        $item = $items[$mockup_id];

        $front_mockup_path = get_attached_file(intval($item['front_id'] ?? 0));
        if (!$front_mockup_path || !file_exists($front_mockup_path)) {
            wp_send_json_error('Mockup ön görseli bulunamadı');
        }

        if (!$front_design_id) {
            wp_send_json_error('Ön tasarım seçilmedi');
        }
        $front_design_path = get_attached_file($front_design_id);
        if (!$front_design_path || !file_exists($front_design_path)) {
            wp_send_json_error('Ön tasarım görseli bulunamadı');
        }

        $mockup_name = $item['name'] ?: 'mockup';

        // --- ÖN KOMPOZİT ---
        $front = $this->composite_from_paths(
            $front_mockup_path, $front_design_path,
            $width_percent, $left_percent, $top_percent,
            $mockup_name, get_the_title($front_design_id), $preset_code, 'on'
        );
        if (!$front['success']) {
            wp_send_json_error('Ön görsel: ' . $front['message']);
        }

        $result = [
            'front_url' => $front['url'],
            'back_url'  => '',
            'mockup_name' => $mockup_name,
        ];

        // --- ARKA KOMPOZİT (opsiyonel) ---
        $back_mockup_path = get_attached_file(intval($item['back_id'] ?? 0));
        if (!empty($item['back_id']) && $back_mockup_path && file_exists($back_mockup_path) && $back_design_id) {
            $back_design_path = get_attached_file($back_design_id);
            if ($back_design_path && file_exists($back_design_path)) {
                $back = $this->composite_from_paths(
                    $back_mockup_path, $back_design_path,
                    $width_percent, $left_percent, $top_percent,
                    $mockup_name, get_the_title($back_design_id), $preset_code, 'arka'
                );
                if ($back['success']) {
                    $result['back_url'] = $back['url'];
                }
            }
        }

        wp_send_json_success($result);
    }

    /**
     * Yerel dosya yollarından kompozit üretir (GD).
     * $side: 'on' | 'arka' — dosya adına eklenir.
     */
    private function composite_from_paths($mockup_path, $design_path, $width_percent, $left_percent, $top_percent, $mockup_name, $design_name, $preset_code = '', $side = '') {

        $upload_dir = wp_upload_dir();
        $mockup_dir = $upload_dir['basedir'] . '/mockups';
        if (!file_exists($mockup_dir)) {
            wp_mkdir_p($mockup_dir);
        }

        $code_with_side = trim($preset_code . ($side ? '-' . $side : ''), '-');
        $filename = $this->generate_filename($mockup_name, $design_name, $code_with_side);
        $filepath = $mockup_dir . '/' . $filename;

        $mockup_image = @file_get_contents($mockup_path);
        $design_image = @file_get_contents($design_path);
        if ($mockup_image === false || $design_image === false) {
            return ['success' => false, 'message' => 'Görsel dosyaları okunamadı'];
        }

        $mockup = imagecreatefromstring($mockup_image);
        $design = imagecreatefromstring($design_image);
        if (!$mockup || !$design) {
            return ['success' => false, 'message' => 'Görseller işlenemedi'];
        }

        $mockup_width  = imagesx($mockup);
        $mockup_height = imagesy($mockup);
        $design_width  = imagesx($design);
        $design_height = imagesy($design);

        $new_design_width  = ($mockup_width * $width_percent) / 100;
        $new_design_height = ($design_height * $new_design_width) / $design_width;
        $position_left     = ($mockup_width * $left_percent) / 100;
        $position_top      = ($mockup_height * $top_percent) / 100;

        $resized_design = imagecreatetruecolor($new_design_width, $new_design_height);
        imagealphablending($resized_design, false);
        imagesavealpha($resized_design, true);
        $transparent = imagecolorallocatealpha($resized_design, 0, 0, 0, 127);
        imagefill($resized_design, 0, 0, $transparent);

        imagecopyresampled($resized_design, $design, 0, 0, 0, 0,
            $new_design_width, $new_design_height, $design_width, $design_height);

        imagealphablending($mockup, true);
        imagesavealpha($mockup, true);

        imagecopy($mockup, $resized_design, $position_left, $position_top, 0, 0,
            $new_design_width, $new_design_height);

        $ok = imagepng($mockup, $filepath, 9);

        imagedestroy($mockup);
        imagedestroy($design);
        imagedestroy($resized_design);

        if (!$ok) {
            return ['success' => false, 'message' => 'Oluşturulan görsel kaydedilemedi'];
        }

        return [
            'success' => true,
            'url'     => $upload_dir['baseurl'] . '/mockups/' . $filename,
            'path'    => $filepath,
        ];
    }

    private function get_drive_file_info($api_key, $file_id) {
        $url = "https://www.googleapis.com/drive/v3/files/{$file_id}";
        $params = [
            'key' => $api_key,
            'fields' => 'name'
        ];
        
        $response = wp_remote_get($url . '?' . http_build_query($params));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['error'])) {
            return false;
        }
        
        return array('name' => $data['name']);
    }
    
    private function create_composite_image($mockup_url, $design_url, $width_percent, $left_percent, $top_percent, $mockup_name, $design_name, $preset_code = '') {
        $upload_dir = wp_upload_dir();
        $mockup_dir = $upload_dir['basedir'] . '/mockups';
        
        if (!file_exists($mockup_dir)) {
            wp_mkdir_p($mockup_dir);
        }
        
        $filename = $this->generate_filename($mockup_name, $design_name, $preset_code);
        $filepath = $mockup_dir . '/' . $filename;
        
        $mockup_image = $this->download_with_fallback($mockup_url);
        if ($mockup_image === false) {
            return array('success' => false, 'message' => 'Mockup görseli indirilemedi');
        }
        
        $design_image = $this->download_with_fallback($design_url);
        if ($design_image === false) {
            return array('success' => false, 'message' => 'Tasarım görseli indirilemedi');
        }
        
        $mockup = imagecreatefromstring($mockup_image);
        $design = imagecreatefromstring($design_image);
        
        if (!$mockup || !$design) {
            return array('success' => false, 'message' => 'Görseller işlenemedi');
        }
        
        $mockup_width = imagesx($mockup);
        $mockup_height = imagesy($mockup);
        $design_width = imagesx($design);
        $design_height = imagesy($design);
        
        $new_design_width = ($mockup_width * $width_percent) / 100;
        $new_design_height = ($design_height * $new_design_width) / $design_width;
        $position_left = ($mockup_width * $left_percent) / 100;
        $position_top = ($mockup_height * $top_percent) / 100;
        
        $resized_design = imagecreatetruecolor($new_design_width, $new_design_height);
        if (!$resized_design) {
            imagedestroy($mockup);
            imagedestroy($design);
            return array('success' => false, 'message' => 'Yeniden boyutlandırılmış tasarım oluşturulamadı');
        }
        
        imagealphablending($resized_design, false);
        imagesavealpha($resized_design, true);
        $transparent = imagecolorallocatealpha($resized_design, 0, 0, 0, 127);
        imagefill($resized_design, 0, 0, $transparent);
        
        imagecopyresampled($resized_design, $design, 0, 0, 0, 0, 
                          $new_design_width, $new_design_height, 
                          $design_width, $design_height);
        
        imagealphablending($mockup, true);
        imagesavealpha($mockup, true);
        
        imagecopy($mockup, $resized_design, $position_left, $position_top, 0, 0, 
                 $new_design_width, $new_design_height);
        
        if (imagepng($mockup, $filepath, 9)) {
            $file_url = $upload_dir['baseurl'] . '/mockups/' . $filename;
            
            imagedestroy($mockup);
            imagedestroy($design);
            imagedestroy($resized_design);
            
            return array(
                'success' => true,
                'url' => $file_url,
                'path' => $filepath,
                'mockup_name' => $mockup_name,
                'design_name' => $design_name
            );
        } else {
            imagedestroy($mockup);
            imagedestroy($design);
            imagedestroy($resized_design);
            
            return array('success' => false, 'message' => 'Oluşturulan görsel kaydedilemedi');
        }
    }

    private function download_with_fallback($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'sslverify' => false
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            return wp_remote_retrieve_body($response);
        }
        
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]);
            
            $content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($content !== false && $http_code === 200) {
                return $content;
            }
        }
        
        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            
            $content = file_get_contents($url, false, $context);
            if ($content !== false) {
                return $content;
            }
        }
        
        return false;
    }
    
    private function generate_filename($mockup_name, $design_name, $preset_code = '') {
        $mockup_name = pathinfo($mockup_name, PATHINFO_FILENAME);
        $design_name = pathinfo($design_name, PATHINFO_FILENAME);
        
        $turkish_chars = array('ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç');
        $english_chars = array('i', 'g', 'u', 's', 'o', 'c', 'i', 'g', 'u', 's', 'o', 'c');
        
        $mockup_name = str_replace($turkish_chars, $english_chars, $mockup_name);
        $design_name = str_replace($turkish_chars, $english_chars, $design_name);
        
        $mockup_name = strtolower(str_replace(' ', '-', $mockup_name));
        $design_name = strtolower(str_replace(' ', '-', $design_name));
        
        $mockup_name = preg_replace('/[^a-z0-9\-]/', '', $mockup_name);
        $design_name = preg_replace('/[^a-z0-9\-]/', '', $design_name);
        
        $mockup_name = substr($mockup_name, 0, 30);
        $design_name = substr($design_name, 0, 30);
        
        $preset_code = strtolower($preset_code);
        $preset_suffix = $preset_code ? '-' . $preset_code : '';

        return $mockup_name . '-' . $design_name . $preset_suffix . '.png';
    }
    
    public function save_to_media() {
        check_ajax_referer('mockup_nonce', 'nonce');
        
        $file_url = sanitize_text_field($_POST['file_url']);
        $mockup_name = sanitize_text_field($_POST['mockup_name']);
        $design_name = sanitize_text_field($_POST['design_name']);
        
        $file_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $file_url);
        
        if (!file_exists($file_path)) {
            wp_send_json_error('Dosya bulunamadı');
        }
        
        $preset_code = isset($_POST['preset_code']) ? sanitize_text_field($_POST['preset_code']) : '';
        $new_filename = $this->generate_filename($mockup_name, $design_name, $preset_code);
        $result = $this->save_to_media_library($file_path, $new_filename);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => 'Ortam kütüphanesine kaydedildi!',
                'attachment_id' => $result['attachment_id'],
                'edit_url' => admin_url('post.php?post=' . $result['attachment_id'] . '&action=edit')
            ));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    private function save_to_media_library($file_path, $file_name) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $upload_dir = wp_upload_dir();
        $new_file_path = $upload_dir['path'] . '/' . $file_name;
        
        if (!copy($file_path, $new_file_path)) {
            return array('success' => false, 'message' => 'Dosya kopyalanamadı');
        }
        
        $file_array = array(
            'name' => $file_name,
            'tmp_name' => $new_file_path
        );
        
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            return array('success' => false, 'message' => $attachment_id->get_error_message());
        }
        
        return array('success' => true, 'attachment_id' => $attachment_id);
    }
    
    public function download_mockup() {
        // Nonce doğrulaması (path traversal ve yetkisiz erişime karşı)
        check_ajax_referer('mockup_nonce', 'nonce');

        // Sadece dosya adını kabul et — yol bileşenlerini at
        $requested = isset($_GET['file']) ? sanitize_file_name(basename($_GET['file'])) : '';

        if ($requested === '') {
            wp_die('Geçersiz dosya');
        }

        // Dosya yalnızca mockups klasörünün içinde olabilir
        $upload_dir = wp_upload_dir();
        $mockup_dir = trailingslashit($upload_dir['basedir'] . '/mockups');
        $file_path  = $mockup_dir . $requested;

        // Gerçek yolun mockups klasörü altında kaldığını doğrula
        $real_base = realpath($mockup_dir);
        $real_file = realpath($file_path);

        if ($real_file === false || $real_base === false || strpos($real_file, $real_base) !== 0) {
            wp_die('Dosya bulunamadı');
        }

        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $requested . '"');
        readfile($real_file);
        exit;
    }
}