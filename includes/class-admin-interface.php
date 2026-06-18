<?php
class MockupAdminInterface {
    
    private $preset_manager;
    
    public function __construct($preset_manager) {
        $this->preset_manager = $preset_manager;
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Mockup Creator',
            'Mockup Creator',
            'manage_options',
            'mockup-creator',
            array($this, 'admin_page'),
            'dashicons-format-image',
            30
        );
        // Ürün Profilleri liste sayfası
        add_submenu_page(
            'mockup-creator',
            'Ürün Profilleri',
            'Ürün Profilleri',
            'manage_options',
            'mockup-product-profiles',
            array($this, 'render_product_profiles_list')
        );

        // Düzenleme / Ekleme sayfası (menüde görünmez)
        add_submenu_page(
            null,
            'Profil Düzenle',
            'Profil Düzenle',
            'manage_options',
            'mockup-product-profiles-edit',
            array($this, 'render_product_profile_edit')
        );

        // Koleksiyon Kodları menüsü
        add_submenu_page(
            'mockup-creator',
            'Koleksiyon Kodları',
            'Koleksiyon Kodları',
            'manage_options',
            'mockup-collection-codes',
            array($this, 'render_collection_codes_page')
        );

        // Koleksiyon düzenleme (gizli sayfa)
        add_submenu_page(
            null,
            'Koleksiyon Düzenle',
            'Koleksiyon Düzenle',
            'manage_options',
            'mockup-collection-edit',
            array($this, 'render_collection_code_edit')
        );

    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_mockup-creator') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('mockup-creator-js', plugin_dir_url(__FILE__) . '../assets/mockup-creator.js', array('jquery'), '2.1', true);
        wp_enqueue_style('mockup-creator-css', plugin_dir_url(__FILE__) . '../assets/mockup-creator.css', array(), '2.1');
        
        wp_localize_script('mockup-creator-js', 'mockup_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mockup_nonce')
        ));
    }
        
    public function admin_page() {
        $api_key = get_option('mockup_drive_api_key', '');
        $mockup_folder_id = get_option('mockup_drive_mockup_folder', '');
        $koleksiyon_folder_id = get_option('mockup_drive_koleksiyon_folder', '');
        
        $last_status = get_option('mockup_drive_last_status', 'unknown');
        $last_check = get_option('mockup_drive_last_check', 0);
        $last_message = get_option('mockup_drive_last_message', 'Henüz kontrol edilmedi');
        $logs = get_option('mockup_drive_logs', []);
        ?>
        <div class="wrap">
            <h1>Mockup Creator</h1>
            
            <!-- Otomatik Drive Kontrol Sistemi -->
            <div class="drive-automation-status">
                <h3>🤖 Otomatik Drive Kontrol Sistemi</h3>
                
                <div class="status-cards">
                    <div class="status-card">
                        <h4>Son Durum</h4>
                        <div class="status-indicator <?php echo $last_status; ?>">
                            <?php 
                            echo $last_status === 'connected' ? '✅ BAĞLI' : 
                                 ($last_status === 'disconnected' ? '❌ KOPUK' : '❓ BİLİNMİYOR'); 
                            ?>
                        </div>
                        <p><?php echo esc_html($last_message); ?></p>
                        <p><small>Son kontrol: <?php echo $last_check ? date('d.m.Y H:i:s', $last_check) : 'Henüz yok'; ?></small></p>
                    </div>
                    
                    <div class="status-card">
                        <h4>Kontrol Sıklığı</h4>
                        <p>⏰ Her 10 dakikada bir</p>
                        <p>📧 Durum değişikliklerinde e-posta gönderilir</p>
                        <p>🔄 Son kontrol: <?php echo wp_next_scheduled('mockup_drive_auto_check') ? date('d.m.Y H:i:s', wp_next_scheduled('mockup_drive_auto_check')) : 'Planlanmamış'; ?></p>
                    </div>
                </div>
                
                <div class="automation-controls">
                    <button id="test-drive-now" class="button button-primary">🔍 Şimdi Test Et</button>
                    <button id="view-drive-logs" class="button">📋 Logları Görüntüle</button>
                    <button id="clear-drive-logs" class="button">🗑️ Logları Temizle</button>
                </div>
                
                <div id="drive-logs" style="display: none; margin-top: 20px;">
                    <h4>Son Log Kayıtları</h4>
                    <div class="log-container">
                        <?php if (empty($logs)): ?>
                            <p>Henüz log kaydı bulunmuyor.</p>
                        <?php else: ?>
                            <table class="wp-list-table widefat fixed">
                                <thead>
                                    <tr>
                                        <th>Zaman</th>
                                        <th>Tip</th>
                                        <th>Mesaj</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($logs, 0, 10) as $log): ?>
                                        <tr>
                                            <td><?php echo esc_html($log['time']); ?></td>
                                            <td>
                                                <span class="log-type <?php echo esc_attr($log['type']); ?>">
                                                    <?php echo esc_html($log['type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo esc_html($log['message']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div id="mockup-creator-app">
                <div class="mockup-controls">
                    <!-- Drive bağlantı ayarları -->
                    <div class="drive-settings">
                        <h3>Google Drive Ayarları</h3>
                        
                        <div class="api-key-group">
                            <label>API Anahtarı:</label>
                            <input type="text" id="drive-api-key" 
                                   value="<?php echo esc_attr($api_key); ?>" 
                                   class="regular-text" />
                        </div>
                        
                        <div class="folder-ids">
                            <div class="folder-group">
                                <label>Mockup Klasör ID:</label>
                                <input type="text" id="mockup-folder-id" 
                                       value="<?php echo esc_attr($mockup_folder_id); ?>"
                                       class="regular-text" />
                            </div>
                            
                            <div class="folder-group">
                                <label>Koleksiyonlar Klasör ID:</label>
                                <input type="text" id="koleksiyon-folder-id" 
                                       value="<?php echo esc_attr($koleksiyon_folder_id); ?>"
                                       placeholder="Koleksiyon klasör ID'si (opsiyonel)" 
                                       class="regular-text" />
                            </div>
                        </div>
                        
                        <div class="drive-buttons">
                            <button id="test-drive-connection" class="button button-primary">Drive Bağlantısını Test Et</button>
                            <button id="connect-drive" class="button">Dosyaları Yükle</button>
                            <button id="save-settings" class="button">Ayarları Kaydet</button>
                        </div>
                        <div id="drive-status"></div>
                    </div>
                    
                    <!-- Mockup seçimi -->
                    <div class="file-selection">
                        <div class="selection-group">
                            <label>Mockup:</label>
                            <select id="mockup-select">
                                <option value="">Mockup seçin</option>
                            </select>
                            <button class="nav-btn prev" data-target="mockup">⬅</button>
                            <button class="nav-btn next" data-target="mockup">➡</button>
                        </div>
                        
                        <div class="selection-group">
                            <label>Koleksiyon:</label>
                            <select id="collection-select">
                                <option value="">Koleksiyon seçin</option>
                            </select>
                            <button class="nav-btn prev" data-target="collection">⬅</button>
                            <button class="nav-btn next" data-target="collection">➡</button>
                        </div>
                        
                        <div class="selection-group">
                            <label>Tasarım:</label>
                            <select id="design-select">
                                <option value="">Tasarım seçin</option>
                            </select>
                            <button class="nav-btn prev" data-target="design">⬅</button>
                            <button class="nav-btn next" data-target="design">➡</button>
                        </div>
                    </div>
                    
                    <!-- Preset ayarları -->
                    <div class="preset-controls">
                        <div class="preset-selection">
                            <label>Preset:</label>
                            <select id="preset-select">
                                <option value="">Preset seçin</option>
                            </select>
                            <button id="apply-preset" class="button">Preset Uygula</button>
                        </div>
                        
                        <div class="preset-management">
                            <button id="add-preset" class="button button-secondary">Yeni Preset</button>
                            <button id="manage-presets" class="button">Preset Yönetimi</button>
                        </div>
                    </div>
                    
                    <!-- Slider kontrolleri -->
                    <div class="slider-controls">
                        <div class="slider-group">
                            <label>Genişlik: <span id="width-value">33</span>%</label>
                            <input type="range" id="width-slider" min="1" max="100" value="33" class="slider">
                        </div>
                        
                        <div class="slider-group">
                            <label>Soldan: <span id="left-value">34</span>%</label>
                            <input type="range" id="left-slider" min="0" max="100" value="34" class="slider">
                        </div>
                        
                        <div class="slider-group">
                            <label>Üstten: <span id="top-value">27</span>%</label>
                            <input type="range" id="top-slider" min="0" max="100" value="27" class="slider">
                        </div>
                    </div>
                    
                    <!-- Aksiyon butonları -->
                    <div class="action-buttons">
                        <button id="generate-single" class="button button-primary">Tek Oluştur</button>
                        <button id="refresh-files" class="button">Yenile</button>
                    </div>
                </div>
                
                <!-- Ön izleme alanı -->
                <div class="preview-area">
                    <h3>Ön İzleme</h3>
                    <div id="preview-container">
                        <div id="preview-placeholder">Ön izleme burada görünecek</div>
                        <img id="preview-image" style="display:none; max-width: 100%;" />
                    </div>
                    
                    <!-- İndirme/kopyalama butonları -->
                    <div class="output-controls" style="display:none;">
                        <button id="copy-image" class="button">Resmi Kopyala</button>
                        <button id="download-image" class="button">İndir</button>
                        <button id="save-to-media" class="button button-primary">Ortam'a Kaydet</button>
                    </div>
                </div>
                
                <!-- Preset Yönetim Modal -->
                <div id="preset-modal" class="modal" style="display:none;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Preset Yönetimi</h3>
                            <span class="close-modal">&times;</span>
                        </div>
                        <div class="modal-body">
                            <div id="preset-list">
                                <!-- Preset listesi burada yüklenecek -->
                            </div>
                            <div id="preset-form" style="display:none;">
                                <h4 id="form-title">Yeni Preset Ekle</h4>
                                <div class="form-group">
                                    <label>Preset Kodu:</label>
                                    <input type="text" id="preset-code" class="regular-text">
                                </div>
                                <div class="form-group">
                                    <label>Preset Adı:</label>
                                    <input type="text" id="preset-name" class="regular-text">
                                </div>
                                <div class="form-group">
                                    <label>Genişlik: <span id="form-width-value">33</span>%</label>
                                    <input type="range" id="form-width-slider" min="1" max="100" value="33" class="slider">
                                </div>
                                <div class="form-group">
                                    <label>Soldan: <span id="form-left-value">34</span>%</label>
                                    <input type="range" id="form-left-slider" min="0" max="100" value="34" class="slider">
                                </div>
                                <div class="form-group">
                                    <label>Üstten: <span id="form-top-value">27</span>%</label>
                                    <input type="range" id="form-top-slider" min="0" max="100" value="27" class="slider">
                                </div>
                                <div class="form-buttons">
                                    <button id="save-preset-form" class="button button-primary">Kaydet</button>
                                    <button id="cancel-preset-form" class="button">İptal</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_collection_codes_page() {

        $codes = get_option('mockup_collection_codes', []);

        ?>
        <div class="wrap">
            <h1>Koleksiyon Kodları</h1>

            <p>
                <a href="<?php echo admin_url('admin.php?page=mockup-collection-edit'); ?>" 
                class="button button-primary">Yeni Kod Ekle</a>
            </p>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Kod</th>
                        <th>Koleksiyon Adı</th>
                        <th width="120">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($codes)): ?>
                    <?php foreach ($codes as $code => $name): ?>
                        <tr>
                            <td><?php echo esc_html($code); ?></td>
                            <td><?php echo esc_html($name); ?></td>
                            <td>
                                <a class="button"
                                href="<?php echo admin_url('admin.php?page=mockup-collection-edit&edit=' . $code); ?>">
                                    Düzenle
                                </a>
                                <a class="button delete-code"
                                href="<?php echo wp_nonce_url(admin_url('admin.php?page=mockup-collection-codes&delete=' . $code), 'delete_code'); ?>">
                                    Sil
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3">Henüz kod eklenmemiş.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php

        // Silme işlemi
        if (isset($_GET['delete']) && check_admin_referer('delete_code')) {
            unset($codes[$_GET['delete']]);
            update_option('mockup_collection_codes', $codes);
            wp_redirect(admin_url('admin.php?page=mockup-collection-codes'));
            exit;
        }
    }

    public function render_collection_code_edit() {

        $codes = get_option('mockup_collection_codes', []);
        $editing = isset($_GET['edit']);
        $current_code = $editing ? sanitize_text_field($_GET['edit']) : '';
        $current_name = $editing && isset($codes[$current_code]) ? $codes[$current_code] : '';

        // Kaydetme işlemi
        if (isset($_POST['save_code'])) {

            $new_code = strtoupper(sanitize_text_field($_POST['collection_code']));
            $new_name = sanitize_text_field($_POST['collection_name']);

            if (!empty($new_code) && !empty($new_name)) {

                // eski kod silinip yenisi yazılır
                if ($editing && $current_code !== $new_code) {
                    unset($codes[$current_code]);
                }

                $codes[$new_code] = $new_name;
                update_option('mockup_collection_codes', $codes);
            }

            wp_redirect(admin_url('admin.php?page=mockup-collection-codes'));
            exit;
        }

        ?>
        <div class="wrap">
            <h1><?php echo $editing ? 'Kodu Düzenle' : 'Yeni Kod Ekle'; ?></h1>

            <form method="post">
                <table class="form-table">
                    <tr>
                        <th><label>Kod</label></th>
                        <td><input type="text" name="collection_code" class="regular-text" 
                                value="<?php echo esc_attr($current_code); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Koleksiyon Adı</label></th>
                        <td><input type="text" name="collection_name" class="regular-text"
                                value="<?php echo esc_attr($current_name); ?>"></td>
                    </tr>
                </table>

                <p><button class="button button-primary" name="save_code">Kaydet</button></p>
            </form>
        </div>
        <?php
    }

    public function render_product_profiles_list() {

        $profiles = get_option('mockup_product_profiles', []);

        ?>
        <div class="wrap">
            <h1>Ürün Profilleri</h1>

            <a href="<?php echo admin_url('admin.php?page=mockup-product-profiles-edit'); ?>" 
            class="button button-primary">
                Yeni Profil Ekle
            </a>

            <table class="widefat striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Profil Adı</th>
                        <th>Ürün Tipi</th>
                        <th>Fiyat</th>
                        <th>Kategoriler</th>
                        <th>Düzenle</th>
                        <th>Sil</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($profiles)): ?>
                    <tr><td colspan="7">Henüz profil oluşturulmamış.</td></tr>
                <?php else: ?>
                    <?php foreach ($profiles as $id => $p): ?>
                    <tr>
                        <td><?php echo esc_html($id); ?></td>
                        <td><?php echo esc_html($p['profile_title']); ?></td>
                        <td><?php echo esc_html($p['product_type']); ?></td>
                        <td><?php echo esc_html($p['price']); ?></td>
                        <td><?php echo implode(', ', $p['kategori']); ?></td>

                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mockup-product-profiles-edit&id=' . $id); ?>" 
                            class="button">Düzenle</a>
                        </td>

                        <td>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mockup-product-profiles&delete=' . $id), 'delete_profile'); ?>"
                            onclick="return confirm('Bu profili silmek istediğine emin misin?');"
                            class="button button-danger">Sil</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php

        // Silme işlemi
        if (isset($_GET['delete']) && check_admin_referer('delete_profile')) {
            $delete_id = sanitize_text_field($_GET['delete']);
            unset($profiles[$delete_id]);
            update_option('mockup_product_profiles', $profiles);
            wp_redirect(admin_url('admin.php?page=mockup-product-profiles'));
            exit;
        }
    }

public function render_product_profile_edit() {

    $profiles = get_option('mockup_product_profiles', []);

    $editing = isset($_GET['id']);
    $id = $editing ? sanitize_text_field($_GET['id']) : '';

    $default_profile = [
        'profile_title'     => '',
        'product_type'      => '',
        'price'             => '',
        'sale_price'        => '',
        'kategori'          => [],
        'brands'            => [],
        'sku_prefix'        => '',
        'stock_mode'        => 'instock',
        'stock_quantity'    => 0,
        'short_description' => '',
        'description'       => '',
        'size_chart'        => '',
        'shipping_class'    => '',
        'custom_fields'     => []
    ];

    $profile = ($editing && isset($profiles[$id])) ? $profiles[$id] : $default_profile;


    /* SAVE */
    if (isset($_POST['save_profile'])) {

        $profiles = get_option('mockup_product_profiles', []);
        $id = $editing ? $id : uniqid('profile_');

        $kategori_array = array_map('intval', $_POST['kategori'] ?? []);
        $brands_array   = array_map('intval', $_POST['brands'] ?? []);

        $profiles[$id] = [
            'profile_title'     => sanitize_text_field($_POST['profile_title']),
            'product_type'      => sanitize_text_field($_POST['product_type']),
            'price'             => sanitize_text_field($_POST['price']),
            'sale_price'        => sanitize_text_field($_POST['sale_price']),
            'kategori'          => $kategori_array,
            'brands'            => $brands_array,
            'sku_prefix'        => sanitize_text_field($_POST['sku_prefix']),
            'stock_mode'        => sanitize_text_field($_POST['stock_mode']),
            'stock_quantity'    => intval($_POST['stock_quantity']),
            'short_description' => wp_kses_post($_POST['short_description']),
            'description'       => wp_kses_post($_POST['description']),
            'size_chart'        => sanitize_text_field($_POST['size_chart']),
            'shipping_class'    => sanitize_text_field($_POST['shipping_class']), // ✔ DOĞRU YERDE
            'custom_fields'     => []
        ];

        update_option('mockup_product_profiles', $profiles);

        wp_redirect(admin_url('admin.php?page=mockup-product-profiles'));
        exit;
    }


    /* Categories */
    $all_categories = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false
    ]);

    /* Brands */
    $brand_terms = get_terms([
        'taxonomy'   => 'product_brand',
        'hide_empty' => false
    ]);

    /* Size charts */
    $size_charts = get_posts([
        'post_type'      => 'ts_size_chart',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    ]);

    /* SHIPPING CLASS LIST */
    $shipping_classes = get_terms([
        'taxonomy' => 'product_shipping_class',
        'hide_empty' => false
    ]);
    ?>

    <div class="wrap">
        <h1><?php echo $editing ? 'Profili Düzenle' : 'Yeni Profil Ekle'; ?></h1>

        <form method="POST">
            <table class="form-table">

                <tr>
                    <th><label>Profil Adı</label></th>
                    <td><input type="text" name="profile_title" class="regular-text"
                        value="<?php echo esc_attr($profile['profile_title']); ?>"></td>
                </tr>

                <tr>
                    <th><label>Ürün Tipi ID</label></th>
                    <td><input type="text" name="product_type" class="regular-text"
                        value="<?php echo esc_attr($profile['product_type']); ?>"></td>
                </tr>

                <tr>
                    <th><label>Fiyat</label></th>
                    <td><input type="text" name="price" class="regular-text"
                        value="<?php echo esc_attr($profile['price']); ?>"></td>
                </tr>

                <tr>
                    <th><label>İndirimli Fiyat</label></th>
                    <td><input type="text" name="sale_price" class="regular-text"
                        value="<?php echo esc_attr($profile['sale_price']); ?>"></td>
                </tr>

                <!-- KATEGORİLER -->
                <tr>
                    <th><label>Kategoriler</label></th>
                    <td>
                        <select name="kategori[]" multiple style="width:300px; height:150px;">
                            <?php foreach ($all_categories as $cat): ?>
                                <option value="<?php echo $cat->term_id; ?>"
                                    <?php echo in_array($cat->term_id, $profile['kategori']) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <!-- MARKALAR -->
                <tr>
                    <th><label>Markalar</label></th>
                    <td>
                        <select name="brands[]" multiple style="width:300px; height:150px;">
                            <?php foreach ($brand_terms as $term): ?>
                                <option value="<?php echo $term->term_id; ?>"
                                    <?php echo in_array($term->term_id, $profile['brands']) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>


                <!-- SKU -->
                <tr>
                    <th><label>SKU Prefix</label></th>
                    <td><input type="text" name="sku_prefix" class="regular-text"
                        value="<?php echo esc_attr($profile['sku_prefix']); ?>"></td>
                </tr>


                <!-- STOK -->
                <tr>
                    <th><label>Stok Durumu</label></th>
                    <td>
                        <select name="stock_mode" id="stock_mode">
                            <option value="instock" <?php selected($profile['stock_mode'], 'instock'); ?>>Stokta</option>
                            <option value="outofstock" <?php selected($profile['stock_mode'], 'outofstock'); ?>>Stok Yok</option>
                            <option value="backorder" <?php selected($profile['stock_mode'], 'backorder'); ?>>Sipariş Verilebilir</option>
                            <option value="managed" <?php selected($profile['stock_mode'], 'managed'); ?>>Stok Yönetimi</option>
                        </select>
                    </td>
                </tr>

                <tr id="stock_quantity_row"
                    style="<?php echo ($profile['stock_mode'] === 'managed') ? '' : 'display:none;'; ?>">
                    <th><label>Stok Adedi</label></th>
                    <td>
                        <input type="number" min="0" name="stock_quantity"
                            value="<?php echo esc_attr($profile['stock_quantity']); ?>" class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th><label>Kısa Açıklama</label></th>
                    <td><textarea name="short_description" rows="4" class="large-text"><?php echo esc_textarea($profile['short_description']); ?></textarea></td>
                </tr>

                <tr>
                    <th><label>Ürün Açıklaması</label></th>
                    <td><textarea name="description" rows="6" class="large-text"><?php echo esc_textarea($profile['description']); ?></textarea></td>
                </tr>

                <!-- BEDEN TABLOSU -->
                <tr>
                    <th><label>Beden Tablosu</label></th>
                    <td>
                        <select name="size_chart" class="regular-text">
                            <option value="">— Seçiniz —</option>
                            <?php foreach ($size_charts as $chart): ?>
                                <option value="<?php echo esc_attr($chart->ID); ?>"
                                    <?php selected($profile['size_chart'], $chart->ID); ?>>
                                    <?php echo esc_html($chart->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Bu profildeki ürünlere uygulanacak beden tablosu.</p>
                    </td>
                </tr>


                <!-- GÖNDERİM SINIFI -->
                <tr>
                    <th><label>Gönderim Sınıfı</label></th>
                    <td>
                        <select name="shipping_class" class="regular-text">
                            <option value="">— Seçiniz —</option>
                            <?php foreach ($shipping_classes as $sc): ?>
                                <option value="<?php echo esc_attr($sc->slug); ?>"
                                    <?php selected($profile['shipping_class'], $sc->slug); ?>>
                                    <?php echo esc_html($sc->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Bu profildeki ürünlere uygulanacak gönderim sınıfı.</p>
                    </td>
                </tr>

            </table>

            <p><button class="button button-primary" name="save_profile">Kaydet</button></p>
        </form>
    </div>

    <?php
}

}