<?php

class MockupPresetManager {

    public function __construct() {
        // AJAX action kayıtları mockup-creator.php içindeki register_ajax_handlers()
        // tarafından (hem wp_ajax_ hem wp_ajax_nopriv_) yapılıyor. Burada tekrar
        // kaydetmiyoruz; aksi halde çift kayıt olur.
    }

    private function get_all_presets() {
        $presets = get_option('mockup_presets', []);
        if (!is_array($presets)) $presets = [];
        return $presets;
    }

    private function save_all_presets($presets) {
        update_option('mockup_presets', $presets);
    }

    /** ---------------------------------------------------------
     *  PRESET OLUŞTUR
     * --------------------------------------------------------- */
    public function save_preset() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $name  = sanitize_text_field($_POST['preset_name']);
        $width = floatval($_POST['width']);
        $left  = floatval($_POST['left']);
        $top   = floatval($_POST['top']);
        $code  = sanitize_text_field($_POST['preset_code']);  // 🔥 YENİ ALAN

        if (!$name) {
            wp_send_json_error("Preset adı boş olamaz");
        }

        if (!$code) {
            wp_send_json_error("Preset kodu boş olamaz");
        }

        $presets = $this->get_all_presets();

        $id = uniqid("preset_");

        $presets[$id] = [
            'name'  => $name,
            'code'  => $code,  // 🔥 YENİ
            'width' => $width,
            'left'  => $left,
            'top'   => $top
        ];

        $this->save_all_presets($presets);

        wp_send_json_success("Preset kaydedildi");
    }

    /** ---------------------------------------------------------
     *  PRESET GÜNCELLE
     * --------------------------------------------------------- */
    public function update_preset() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $id    = sanitize_text_field($_POST['preset_id']);
        $name  = sanitize_text_field($_POST['preset_name']);
        $width = floatval($_POST['width']);
        $left  = floatval($_POST['left']);
        $top   = floatval($_POST['top']);
        $code  = sanitize_text_field($_POST['preset_code']); // 🔥 YENİ

        $presets = $this->get_all_presets();

        if (!isset($presets[$id])) {
            wp_send_json_error("Preset bulunamadı");
        }

        $presets[$id] = [
            'name'  => $name,
            'code'  => $code, // 🔥
            'width' => $width,
            'left'  => $left,
            'top'   => $top
        ];

        $this->save_all_presets($presets);

        wp_send_json_success("Preset güncellendi");
    }

    /** ---------------------------------------------------------
     *  PRESET SİL
     * --------------------------------------------------------- */
    public function delete_preset() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $id = sanitize_text_field($_POST['preset_id']);
        $presets = $this->get_all_presets();

        if (isset($presets[$id])) {
            unset($presets[$id]);
            $this->save_all_presets($presets);
            wp_send_json_success("Preset silindi");
        } else {
            wp_send_json_error("Preset bulunamadı");
        }
    }

    /** ---------------------------------------------------------
     *  PRESET LİSTELE (ADMİN)
     * --------------------------------------------------------- */
    public function get_presets() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $presets = $this->get_all_presets();
        wp_send_json_success($presets);
    }

    /** ---------------------------------------------------------
     *  FRONTEND + GÖRSEL ÖNİZLEME İÇİN
     * --------------------------------------------------------- */
    public function get_presets_with_images() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $presets = $this->get_all_presets();

        foreach ($presets as $id => $preset) {
            $presets[$id]['image'] = "https://tasarim.store/wp-content/uploads/2025/11/Tasarim-Boyutlari.png";
        }

        wp_send_json_success($presets);
    }
}