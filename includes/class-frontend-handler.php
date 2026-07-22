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

        // Profillere kategori ADLARINI ekle (frontend kategori filtresi term_id değil isim kullanır)
        $profiles_raw = get_option('mockup_product_profiles', []);
        foreach ($profiles_raw as $pk => $pv) {
            $cat_names = [];
            foreach ((array) (isset($pv['kategori']) ? $pv['kategori'] : []) as $tid) {
                $t = get_term((int) $tid, 'product_cat');
                if ($t && !is_wp_error($t)) {
                    $cat_names[] = $t->name;
                }
            }
            $profiles_raw[$pk]['category_names'] = $cat_names;
        }

        wp_localize_script('mockup-creator-frontend-js', 'mockup_profiles', [
            'profiles' => $profiles_raw
        ]);
    }


    public function add_shortcode() {
        add_shortcode('mockup_creator', [$this, 'render_frontend_interface']);
        // Butona basınca tam ekran modal açan sürüm: [mockup_creator_button label="..." title="..."]
        add_shortcode('mockup_creator_button', [$this, 'render_button']);
    }

    /**
     * Sayfada sadece bir BUTON gösterir; tıklanınca mockup creator tam ekran modal açılır.
     * Kendi kendine yeter (inline CSS/JS) — tema/sayfa yapıcıdan bağımsız çalışır.
     * Aynı sayfada [mockup_creator] ile BİRLİKTE kullanma (ID çakışması olur).
     * Öznitelikler:
     *   label  → buton yazısı (varsayılan "Ürün Görseli Oluştur")
     *   title  → modal başlığı (varsayılan buton yazısıyla aynı)
     */
    public function render_button($atts) {
        $a = shortcode_atts([
            'label' => 'Ürün Görseli Oluştur',
            'title' => '',
        ], $atts, 'mockup_creator_button');
        $label = $a['label'];
        $title = $a['title'] !== '' ? $a['title'] : $label;

        $creator = $this->render_frontend_interface();

        ob_start();
        ?>
        <button type="button" class="pig-open-btn" onclick="pigOpenCreator()"><?php echo esc_html($label); ?></button>

        <div id="pig-creator-modal" class="pig-creator-modal" role="dialog" aria-modal="true" aria-hidden="true">
            <div class="pig-creator-backdrop" onclick="pigCloseCreator()"></div>
            <div class="pig-creator-dialog" role="document">
                <div class="pig-creator-head">
                    <span class="pig-creator-title"><?php echo esc_html($title); ?></span>
                    <button type="button" class="pig-creator-close" onclick="pigCloseCreator()" aria-label="Kapat">&times;</button>
                </div>
                <div class="pig-creator-body">
                    <?php echo $creator; // render_frontend_interface çıktısı (kaçış içeride yapılır) ?>
                </div>
            </div>
        </div>

        <style>
            .pig-open-btn{display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;color:#fff;
                background:#2271b1;border:none;border-radius:8px;cursor:pointer;line-height:1.2;
                box-shadow:0 2px 6px rgba(0,0,0,.15);transition:background .15s,transform .05s}
            .pig-open-btn:hover{background:#185a8f}
            .pig-open-btn:active{transform:translateY(1px)}
            .pig-creator-modal{position:fixed;inset:0;z-index:99999;display:none}
            .pig-creator-modal.open{display:block}
            .pig-creator-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55)}
            .pig-creator-dialog{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
                width:min(960px,94vw);max-height:92vh;display:flex;flex-direction:column;
                background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.35)}
            .pig-creator-head{display:flex;align-items:center;justify-content:space-between;gap:12px;
                padding:14px 18px;border-bottom:1px solid #eee;background:#fafafa}
            .pig-creator-title{font-size:16px;font-weight:700;color:#222}
            .pig-creator-close{background:none;border:none;font-size:28px;line-height:1;cursor:pointer;
                color:#777;padding:0 4px}
            .pig-creator-close:hover{color:#222}
            .pig-creator-body{padding:18px;overflow:auto;-webkit-overflow-scrolling:touch}
            body.pig-modal-open{overflow:hidden}
        </style>
        <script>
            (function(){
                if (window.pigOpenCreator) return; // birden fazla shortcode olsa da bir kez tanımla
                var m = function(){ return document.getElementById('pig-creator-modal'); };
                window.pigOpenCreator = function(){
                    var el = m(); if(!el) return;
                    el.classList.add('open'); el.setAttribute('aria-hidden','false');
                    document.body.classList.add('pig-modal-open');
                };
                window.pigCloseCreator = function(){
                    var el = m(); if(!el) return;
                    el.classList.remove('open'); el.setAttribute('aria-hidden','true');
                    document.body.classList.remove('pig-modal-open');
                };
                document.addEventListener('keydown', function(e){
                    if(e.key === 'Escape'){ window.pigCloseCreator(); }
                });
            })();
        </script>
        <?php
        return ob_get_clean();
    }


    public function render_frontend_interface() {
        ob_start();
        ?>

        <div id="mockup-creator-frontend">
            <div class="mockup-grid">

                <!-- ÜST BİLGİLENDİRME (panelden yönetilir: pig_frontend_notes_top; boşsa gizli) -->
                <?php
                $pig_notes_top_raw = get_option('pig_frontend_notes_top', '');
                $pig_notes_top = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $pig_notes_top_raw)));
                if (!empty($pig_notes_top)) : ?>
                <div class="mockup-notes mockup-notes-top" style="margin:0 0 14px;padding:12px 16px;background:#fff8e1;border:1px solid #ffe082;border-left:4px solid #ffb300;border-radius:8px;font-size:13px;line-height:1.55;color:#5d4037;">
                    <div style="font-weight:600;margin-bottom:6px;">ℹ️ Bilgilendirme</div>
                    <ul style="margin:0;padding-left:18px;">
                        <?php foreach ($pig_notes_top as $pig_note_t) :
                            $pig_note_t_html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', esc_html($pig_note_t)); ?>
                        <li><?php echo wp_kses($pig_note_t_html, array('strong' => array(), 'b' => array(), 'br' => array(), 'a' => array('href' => array(), 'target' => array()))); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- KATEGORİ SEÇİMİ -->
                <div class="mockup-preview-wrapper">
                    <div class="mockup-row">

                        <div class="mockup-left">
                            <label>Ürün Profili:</label>

                            <div class="mockup-input-group">
                                <select id="frontend-category-select">
                                    <option value="">Ürün profili seçin</option>
                                </select>
                                <button class="frontend-nav-btn prev" data-target="category">⬅</button>
                                <button class="frontend-nav-btn next" data-target="category">➡</button>
                            </div>
                        </div>

                        <div class="mockup-right">
                            <div class="mockup-preview-box">
                                <?php
                                // koleksiyonlar/ kökünde adında "profil" geçen rehber görsel (Profil Seçimi.jpeg).
                                // Ad birebir tutmasa da (boşluk/tire, ç/ş/ı) bulunur.
                                $cat_guide = class_exists('PigR2Storage') ? PigR2Storage::guide_image_url('profil') : '';
                                ?>
                                <img id="category-preview-image" class="preview-img"
                                    src="<?php echo esc_url($cat_guide); ?>" alt="Profil Seçimi"
                                    <?php echo $cat_guide === '' ? 'style="display:none;"' : ''; ?>>
                                <div id="category-placeholder" style="display:none;"></div>
                            </div>
                        </div>

                    </div>
                </div>


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



                <!-- BİLGİLENDİRME / UYARILAR (WP admin: PoD Ürün Oluşturma → Bilgilendirme Notları) -->
                <?php
                $pig_notes_raw = get_option('pig_frontend_notes', '');
                $pig_notes = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $pig_notes_raw)));
                if (empty($pig_notes)) {
                    // Varsayılan (ayar boşsa) — ilk kurulumda kutu boş kalmasın
                    $pig_notes = array(
                        'Sweatshirt ve hoodie ürünlerinde **büyük boy tasarım** seçildiği takdirde, tasarım ürünlerin cep alanlarının üzerine geldiği için **büyük seçimlerde orta boy olarak basılacaktır**.',
                        'Bardak üzerine basım şu anda iki tarafa da **aynı tasarım** ile yapılmaktadır; en kısa sürede iki tarafa da farklı baskı seçeneği sunulacaktır.',
                        'Tekstil ürünlerinde basım şu anda **sadece ön yüze** yapılmaktadır; en kısa sürede iki tarafa da farklı baskı seçeneği sunulacaktır.',
                    );
                }
                ?>
                <?php if (!empty($pig_notes)) : ?>
                <div class="mockup-notes" style="margin:14px 0;padding:12px 16px;background:#fff8e1;border:1px solid #ffe082;border-left:4px solid #ffb300;border-radius:8px;font-size:13px;line-height:1.55;color:#5d4037;">
                    <div style="font-weight:600;margin-bottom:6px;">ℹ️ Bilgilendirme</div>
                    <ul style="margin:0;padding-left:18px;">
                        <?php foreach ($pig_notes as $pig_note) :
                            $pig_note_html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', esc_html($pig_note)); ?>
                        <li><?php echo wp_kses($pig_note_html, array('strong' => array(), 'b' => array(), 'br' => array(), 'a' => array('href' => array(), 'target' => array()))); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

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
