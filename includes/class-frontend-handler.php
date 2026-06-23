<?php
class PigFrontendHandler {
    
    private $preset_manager;
    
    public function __construct($preset_manager) {
        $this->preset_manager = $preset_manager;
    }
    
    public function enqueue_frontend_scripts() {

        wp_enqueue_script('jquery');

        // Cache-busting: dosyanın son değişiklik zamanını sürüm olarak kullan.
        // Böylece JS/CSS her güncellendiğinde tarayıcı/CDN önbelleği otomatik kırılır.
        $js_path  = plugin_dir_path(__FILE__) . '../assets/mockup-creator-frontend.js';
        $css_path = plugin_dir_path(__FILE__) . '../assets/mockup-creator-frontend.css';
        $js_ver   = file_exists($js_path)  ? filemtime($js_path)  : '3.0';
        $css_ver  = file_exists($css_path) ? filemtime($css_path) : '3.0';

        wp_enqueue_script(
            'mockup-creator-frontend-js',
            plugin_dir_url(__FILE__) . '../assets/mockup-creator-frontend.js',
            ['jquery'],
            $js_ver,
            true
        );

        wp_enqueue_style(
            'mockup-creator-frontend-css',
            plugin_dir_url(__FILE__) . '../assets/mockup-creator-frontend.css',
            [],
            $css_ver
        );

        /* -------------------------------------------
           GOOGLE DRIVE DEFAULT GÖRSELLER
           thumbnail endpoint'i: tarayıcıda gömmeye uygun, API anahtarı gerektirmez
        ------------------------------------------- */

        wp_localize_script('mockup-creator-frontend-js', 'mockup_defaults', [
            'product'    => "https://drive.google.com/thumbnail?id=18KZ-Rp_l2jxSX7up8Q9_jZIvsyLwAcYs&sz=w1000",
            'collection' => "https://drive.google.com/thumbnail?id=1NPA-m-7F00r9r9HzlfTidkQ8qG7dHSYw&sz=w1000",
            'design'     => "https://drive.google.com/thumbnail?id=1qP_gqHaiEdMul72mcFJm0c4u097hkskv&sz=w1000",
            'size'       => "https://drive.google.com/thumbnail?id=16wGtYkuMqxxh1sBMrcTF69BfIdrgpTgm&sz=w1000",
        ]);

        /* -------------------------------------------
           AJAX AYARLARI
        ------------------------------------------- */

        wp_localize_script('mockup-creator-frontend-js', 'mockup_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mockup_nonce'),
            'api_key'  => get_option('mockup_drive_api_key', ''),
            'mockup_folder_id'       => get_option('mockup_drive_mockup_folder', ''),
            'koleksiyon_folder_id'   => get_option('mockup_drive_koleksiyon_folder', '')
        ]);

        /* -------------------------------------------
           ÜRÜN PROFİLLERİ
        ------------------------------------------- */

        wp_localize_script('mockup-creator-frontend-js', 'mockup_profiles', [
            'profiles' => get_option('mockup_product_profiles', [])
        ]);
    }


    public function add_shortcode() {
        add_shortcode('mockup_creator', [$this, 'render_frontend_interface']);
    }


    public function render_frontend_interface() {
        ob_start();
        ?>

        <div id="mockup-creator-frontend">
            <div class="mockup-grid">

                <!-- ÜRÜN SEÇİMİ -->
                <div class="mockup-preview-wrapper">
                    <div class="mockup-row">

                        <div class="mockup-left">
                            <label>Ürün Seçimi:</label>

                            <div class="mockup-input-group">
                                <select id="frontend-mockup-select">
                                    <option value="">Ürün seçin</option>
                                </select>
                                <button class="frontend-nav-btn prev" data-target="mockup">⬅</button>
                                <button class="frontend-nav-btn next" data-target="mockup">➡</button>
                            </div>
                        </div>

                        <div class="mockup-right">
                            <div class="mockup-preview-box">
                                <!-- Drive’dan gelecek, o yüzden boş bırakıldı -->
                                <img id="selected-mockup-thumbnail" class="preview-img" src="" style="display:none;">
                            </div>
                        </div>

                    </div>
                </div>



                <!-- KOLEKSİYON SEÇİMİ -->
                <div class="mockup-preview-wrapper">
                    <div class="mockup-row">

                        <div class="mockup-left">
                            <label>Koleksiyon:</label>

                            <div class="mockup-input-group">
                                <select id="frontend-collection-select">
                                    <option value="">Koleksiyon seçin</option>
                                </select>
                                <button class="frontend-nav-btn prev" data-target="collection">⬅</button>
                                <button class="frontend-nav-btn next" data-target="collection">➡</button>
                            </div>
                        </div>

                        <div class="mockup-right">
                            <div class="mockup-preview-box">
                                <img id="collection-preview-image" class="preview-img" src="" style="display:none;">
                                <div id="collection-placeholder" style="display:none;"></div>
                            </div>
                        </div>

                    </div>
                </div>



                <!-- TASARIM SEÇİMİ -->
                <div class="mockup-preview-wrapper">
                    <div class="mockup-row">

                        <div class="mockup-left">
                            <label>Tasarım:</label>

                            <div class="mockup-input-group">
                                <select id="frontend-design-select">
                                    <option value="">Tasarım seçin</option>
                                </select>
                                <button class="frontend-nav-btn prev" data-target="design">⬅</button>
                                <button class="frontend-nav-btn next" data-target="design">➡</button>
                            </div>
                        </div>

                        <div class="mockup-right">
                            <div class="mockup-preview-box">
                                <img id="selected-design-thumbnail" class="preview-img" src="" style="display:none;">
                            </div>
                        </div>

                    </div>
                </div>



                <!-- TASARIM BOYUTU -->
                <div class="mockup-preview-wrapper">
                    <div class="mockup-row">

                        <div class="mockup-left">
                            <label>Tasarım Boyutu:</label>

                            <div class="mockup-input-group">
                                <select id="frontend-preset-select">
                                    <option value="">Boyut seçin</option>
                                </select>
                                <button class="frontend-nav-btn prev" data-target="preset">⬅</button>
                                <button class="frontend-nav-btn next" data-target="preset">➡</button>
                            </div>
                        </div>

                        <div class="mockup-right">
                            <div class="mockup-preview-box">
                                <img id="preset-preview-image" class="preview-img" src="" style="display:none;">
                            </div>
                        </div>

                    </div>
                </div>



                <!-- ÜRÜN GÖRSELİ OLUŞTUR -->
                <div class="mockup-row generate-row">
                    <div class="mockup-left">
                        <button id="frontend-generate" class="button button-primary generate-btn">
                            <span class="btn-text">Ürün Görseli Oluştur</span>
                        </button>
                    </div>
                </div>


                <!-- ÖNİZLEME ALANI -->
                <div class="frontend-preview-area">

                    <h3>Ön İzleme</h3>

                    <div class="mockup-preview-wrapper">
                        <div id="frontend-preview-container">
                            <div id="frontend-preview-placeholder">
                                Ürün görseli oluşturmak için yukarıdaki seçenekleri belirleyin.
                            </div>
                            <img id="frontend-preview-image" style="display:none;" />
                        </div>
                    </div>


                    <div class="frontend-output-controls" style="display:none;">
                        <button id="frontend-download" class="button button-primary">İndir</button>
                        <button id="frontend-copy-link" class="button">Link Kopyala</button>
                        <button id="frontend-create-product" class="button frontend-action success">Ürün Oluştur</button>
                    </div>

                    <div id="frontend-status"></div>

                </div>

            </div>
        </div>

        <?php
        return ob_get_clean();
    }
}
