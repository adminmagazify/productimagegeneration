<?php
/**
 * Medya Kütüphanesi tabanlı mockup & koleksiyon yönetimi.
 * Google Drive'ın yerini alır: tüm görseller WordPress Medya Kütüphanesi'nden seçilir.
 *
 * Options:
 *   mockup_library_items       => [ id => ['name','product_type','front_id','back_id'] ]
 *   mockup_library_collections => [ id => ['name','preview_id','design_ids'=>[...]] ]
 */
class PigLibraryManager {

    const OPT_ITEMS       = 'mockup_library_items';
    const OPT_COLLECTIONS = 'mockup_library_collections';

    public function __construct() {
        // Öncelik 11: ana menü (mockup-creator, öncelik 10) kaydolduktan SONRA
        // alt menüleri ekle. Aksi halde alt menü URL'leri bozulup 404 verir.
        add_action('admin_menu', array($this, 'add_menu'), 11);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_media'));
    }

    /* =========================================================
     *  VERİ ERİŞİMİ
     * ========================================================= */
    public static function get_items() {
        $items = get_option(self::OPT_ITEMS, []);
        return is_array($items) ? $items : [];
    }

    public static function get_collections() {
        $cols = get_option(self::OPT_COLLECTIONS, []);
        return is_array($cols) ? $cols : [];
    }

    /** Bir attachment ID -> public URL (yoksa boş) */
    public static function img_url($id) {
        $id = intval($id);
        if (!$id) return '';
        $url = wp_get_attachment_url($id);
        return $url ? $url : '';
    }

    /* =========================================================
     *  MENÜ
     * ========================================================= */
    public function add_menu() {
        add_submenu_page(
            'mockup-creator',
            'Mockuplar (Medya)',
            'Mockuplar',
            'manage_options',
            'mockup-library-items',
            array($this, 'render_items_page')
        );

        add_submenu_page(
            'mockup-creator',
            'Koleksiyonlar (Medya)',
            'Koleksiyonlar',
            'manage_options',
            'mockup-library-collections',
            array($this, 'render_collections_page')
        );
    }

    public function enqueue_media($hook) {
        // Yalnızca kendi sayfalarımızda medya seçiciyi yükle
        if (strpos((string) $hook, 'mockup-library-items') === false &&
            strpos((string) $hook, 'mockup-library-collections') === false) {
            return;
        }
        wp_enqueue_media();
    }

    /* =========================================================
     *  MOCKUPLAR SAYFASI
     * ========================================================= */
    public function render_items_page() {

        $items = self::get_items();

        // KAYDET
        if (isset($_POST['save_mockup_item']) && check_admin_referer('save_mockup_item')) {
            $id = sanitize_text_field($_POST['item_id'] ?? '');
            if ($id === '') $id = uniqid('mck_');

            $items[$id] = [
                'name'         => sanitize_text_field($_POST['name'] ?? ''),
                'product_type' => sanitize_text_field($_POST['product_type'] ?? ''),
                'front_id'     => intval($_POST['front_id'] ?? 0),
                'back_id'      => intval($_POST['back_id'] ?? 0),
            ];
            update_option(self::OPT_ITEMS, $items);
            echo '<div class="notice notice-success is-dismissible"><p>Mockup kaydedildi.</p></div>';
        }

        // SİL
        if (isset($_GET['delete']) && check_admin_referer('delete_mockup_item')) {
            unset($items[sanitize_text_field($_GET['delete'])]);
            update_option(self::OPT_ITEMS, $items);
            echo '<div class="notice notice-success is-dismissible"><p>Mockup silindi.</p></div>';
        }

        // Düzenlenen kayıt
        $edit_id   = isset($_GET['edit']) ? sanitize_text_field($_GET['edit']) : '';
        $edit      = ($edit_id && isset($items[$edit_id])) ? $items[$edit_id] : null;
        ?>
        <div class="wrap">
            <h1>Mockuplar</h1>
            <p class="description">Her mockup için ürün tipini, ön ve (varsa) arka görselini seçin. Görseller Medya Kütüphanesi'nden gelir.</p>

            <h2><?php echo $edit ? 'Mockup Düzenle' : 'Yeni Mockup'; ?></h2>
            <form method="post">
                <?php wp_nonce_field('save_mockup_item'); ?>
                <input type="hidden" name="item_id" value="<?php echo esc_attr($edit_id); ?>">

                <table class="form-table">
                    <tr>
                        <th><label>Ad</label></th>
                        <td><input type="text" name="name" class="regular-text"
                                   value="<?php echo esc_attr($edit['name'] ?? ''); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label>Ürün Tipi (kod)</label></th>
                        <td>
                            <input type="text" name="product_type" class="regular-text"
                                   value="<?php echo esc_attr($edit['product_type'] ?? ''); ?>"
                                   placeholder="ör: tshirt-standart">
                            <p class="description">WooCommerce profiliyle eşleşen ürün tipi kodu.</p>
                        </td>
                    </tr>

                    <?php
                    $this->media_field('front_id', 'Ön Görsel', intval($edit['front_id'] ?? 0));
                    $this->media_field('back_id',  'Arka Görsel (opsiyonel)', intval($edit['back_id'] ?? 0));
                    ?>
                </table>

                <p>
                    <button class="button button-primary" name="save_mockup_item">Kaydet</button>
                    <?php if ($edit): ?>
                        <a class="button" href="<?php echo admin_url('admin.php?page=mockup-library-items'); ?>">İptal</a>
                    <?php endif; ?>
                </p>
            </form>

            <hr>
            <h2>Kayıtlı Mockuplar</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Ad</th><th>Ürün Tipi</th><th>Ön</th><th>Arka</th><th width="160">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="5">Henüz mockup eklenmemiş.</td></tr>
                <?php else: foreach ($items as $id => $it):
                    $front = self::img_url($it['front_id'] ?? 0);
                    $back  = self::img_url($it['back_id'] ?? 0); ?>
                    <tr>
                        <td><?php echo esc_html($it['name']); ?></td>
                        <td><?php echo esc_html($it['product_type']); ?></td>
                        <td><?php echo $front ? '<img src="'.esc_url($front).'" style="height:48px">' : '—'; ?></td>
                        <td><?php echo $back ? '<img src="'.esc_url($back).'" style="height:48px">' : '—'; ?></td>
                        <td>
                            <a class="button" href="<?php echo admin_url('admin.php?page=mockup-library-items&edit='.$id); ?>">Düzenle</a>
                            <a class="button" href="<?php echo wp_nonce_url(admin_url('admin.php?page=mockup-library-items&delete='.$id), 'delete_mockup_item'); ?>"
                               onclick="return confirm('Silinsin mi?');">Sil</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $this->media_picker_script();
    }

    /* =========================================================
     *  KOLEKSİYONLAR SAYFASI
     * ========================================================= */
    public function render_collections_page() {

        $cols = self::get_collections();

        // KAYDET
        if (isset($_POST['save_collection']) && check_admin_referer('save_collection')) {
            $id = sanitize_text_field($_POST['collection_id'] ?? '');
            if ($id === '') $id = uniqid('col_');

            $design_ids = array_filter(array_map('intval', explode(',', $_POST['design_ids'] ?? '')));

            $cols[$id] = [
                'name'       => sanitize_text_field($_POST['name'] ?? ''),
                'preview_id' => intval($_POST['preview_id'] ?? 0),
                'design_ids' => array_values($design_ids),
            ];
            update_option(self::OPT_COLLECTIONS, $cols);
            echo '<div class="notice notice-success is-dismissible"><p>Koleksiyon kaydedildi.</p></div>';
        }

        // SİL
        if (isset($_GET['delete']) && check_admin_referer('delete_collection')) {
            unset($cols[sanitize_text_field($_GET['delete'])]);
            update_option(self::OPT_COLLECTIONS, $cols);
            echo '<div class="notice notice-success is-dismissible"><p>Koleksiyon silindi.</p></div>';
        }

        $edit_id = isset($_GET['edit']) ? sanitize_text_field($_GET['edit']) : '';
        $edit    = ($edit_id && isset($cols[$edit_id])) ? $cols[$edit_id] : null;
        $design_ids_csv = $edit ? implode(',', $edit['design_ids']) : '';
        ?>
        <div class="wrap">
            <h1>Koleksiyonlar</h1>
            <p class="description">Her koleksiyon bir önizleme görseli ve birden çok tasarım içerir. Tasarım adları Medya Kütüphanesi'ndeki başlıktan gelir.</p>

            <h2><?php echo $edit ? 'Koleksiyon Düzenle' : 'Yeni Koleksiyon'; ?></h2>
            <form method="post">
                <?php wp_nonce_field('save_collection'); ?>
                <input type="hidden" name="collection_id" value="<?php echo esc_attr($edit_id); ?>">

                <table class="form-table">
                    <tr>
                        <th><label>Ad</label></th>
                        <td><input type="text" name="name" class="regular-text"
                                   value="<?php echo esc_attr($edit['name'] ?? ''); ?>" required></td>
                    </tr>

                    <?php $this->media_field('preview_id', 'Önizleme Görseli', intval($edit['preview_id'] ?? 0)); ?>

                    <tr>
                        <th><label>Tasarımlar</label></th>
                        <td>
                            <input type="hidden" name="design_ids" id="design_ids" value="<?php echo esc_attr($design_ids_csv); ?>">
                            <div id="design_ids-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
                                <?php
                                if ($edit) {
                                    foreach ($edit['design_ids'] as $did) {
                                        $u = self::img_url($did);
                                        if ($u) echo '<img src="'.esc_url($u).'" style="height:60px;border:1px solid #ccc">';
                                    }
                                }
                                ?>
                            </div>
                            <button type="button" class="button" id="design_ids-pick">Tasarım Seç / Ekle</button>
                            <button type="button" class="button" id="design_ids-clear">Temizle</button>
                            <p class="description">Birden çok görsel seçebilirsiniz.</p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button class="button button-primary" name="save_collection">Kaydet</button>
                    <?php if ($edit): ?>
                        <a class="button" href="<?php echo admin_url('admin.php?page=mockup-library-collections'); ?>">İptal</a>
                    <?php endif; ?>
                </p>
            </form>

            <hr>
            <h2>Kayıtlı Koleksiyonlar</h2>
            <table class="widefat striped">
                <thead><tr><th>Ad</th><th>Önizleme</th><th>Tasarım Sayısı</th><th width="160">İşlemler</th></tr></thead>
                <tbody>
                <?php if (empty($cols)): ?>
                    <tr><td colspan="4">Henüz koleksiyon eklenmemiş.</td></tr>
                <?php else: foreach ($cols as $id => $c):
                    $prev = self::img_url($c['preview_id'] ?? 0); ?>
                    <tr>
                        <td><?php echo esc_html($c['name']); ?></td>
                        <td><?php echo $prev ? '<img src="'.esc_url($prev).'" style="height:48px">' : '—'; ?></td>
                        <td><?php echo count($c['design_ids'] ?? []); ?></td>
                        <td>
                            <a class="button" href="<?php echo admin_url('admin.php?page=mockup-library-collections&edit='.$id); ?>">Düzenle</a>
                            <a class="button" href="<?php echo wp_nonce_url(admin_url('admin.php?page=mockup-library-collections&delete='.$id), 'delete_collection'); ?>"
                               onclick="return confirm('Silinsin mi?');">Sil</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $this->media_picker_script(true);
    }

    /* =========================================================
     *  TEK GÖRSELLİK MEDYA ALANI
     * ========================================================= */
    private function media_field($field, $label, $current_id = 0) {
        $url = self::img_url($current_id);
        ?>
        <tr>
            <th><label><?php echo esc_html($label); ?></label></th>
            <td>
                <input type="hidden" name="<?php echo esc_attr($field); ?>" id="<?php echo esc_attr($field); ?>"
                       value="<?php echo esc_attr($current_id); ?>">
                <div id="<?php echo esc_attr($field); ?>-preview" style="margin-bottom:8px;">
                    <?php if ($url): ?>
                        <img src="<?php echo esc_url($url); ?>" style="height:80px;border:1px solid #ccc">
                    <?php endif; ?>
                </div>
                <button type="button" class="button mc-media-pick" data-target="<?php echo esc_attr($field); ?>">Görsel Seç</button>
                <button type="button" class="button mc-media-clear" data-target="<?php echo esc_attr($field); ?>">Kaldır</button>
            </td>
        </tr>
        <?php
    }

    /* =========================================================
     *  MEDYA SEÇİCİ JS
     * ========================================================= */
    private function media_picker_script($multi = false) {
        ?>
        <script>
        jQuery(function ($) {
            // Tekli seçim alanları
            $('.mc-media-pick').on('click', function () {
                const target = $(this).data('target');
                const frame = wp.media({ title: 'Görsel Seç', multiple: false, library: { type: 'image' } });
                frame.on('select', function () {
                    const att = frame.state().get('selection').first().toJSON();
                    $('#' + target).val(att.id);
                    const src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                    $('#' + target + '-preview').html('<img src="' + src + '" style="height:80px;border:1px solid #ccc">');
                });
                frame.open();
            });
            $('.mc-media-clear').on('click', function () {
                const target = $(this).data('target');
                $('#' + target).val('');
                $('#' + target + '-preview').empty();
            });

            <?php if ($multi): ?>
            // Çoklu tasarım seçimi
            $('#design_ids-pick').on('click', function () {
                const frame = wp.media({ title: 'Tasarımları Seç', multiple: true, library: { type: 'image' } });
                frame.on('select', function () {
                    const sel = frame.state().get('selection').toJSON();
                    let ids = ($('#design_ids').val() || '').split(',').filter(Boolean);
                    let html = $('#design_ids-preview').html();
                    sel.forEach(att => {
                        if (ids.indexOf(String(att.id)) === -1) {
                            ids.push(String(att.id));
                            const src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                            html += '<img src="' + src + '" style="height:60px;border:1px solid #ccc">';
                        }
                    });
                    $('#design_ids').val(ids.join(','));
                    $('#design_ids-preview').html(html);
                });
                frame.open();
            });
            $('#design_ids-clear').on('click', function () {
                $('#design_ids').val('');
                $('#design_ids-preview').empty();
            });
            <?php endif; ?>
        });
        </script>
        <?php
    }

    /* =========================================================
     *  FRONTEND İÇİN AJAX VERİ KAYNAKLARI
     * ========================================================= */

    /** Mockup listesi (frontend dropdown) */
    public function ajax_get_library_mockups() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $out = [];
        foreach (self::get_items() as $id => $it) {
            $out[] = [
                'id'           => $id,
                'name'         => $it['name'],
                'product_type' => $it['product_type'],
                'front_url'    => self::img_url($it['front_id'] ?? 0),
                'back_url'     => self::img_url($it['back_id'] ?? 0),
                'has_back'     => !empty($it['back_id']),
            ];
        }
        // Ada göre sırala
        usort($out, function ($a, $b) { return strcoll($a['name'], $b['name']); });

        wp_send_json_success($out);
    }

    /** Koleksiyon listesi (frontend dropdown) */
    public function ajax_get_library_collections() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $out = [];
        foreach (self::get_collections() as $id => $c) {
            $out[] = [
                'id'          => $id,
                'name'        => $c['name'],
                'preview_url' => self::img_url($c['preview_id'] ?? 0),
            ];
        }
        usort($out, function ($a, $b) { return strcoll($a['name'], $b['name']); });

        wp_send_json_success($out);
    }

    /** Bir koleksiyonun tasarımları */
    public function ajax_get_library_designs() {
        check_ajax_referer('mockup_nonce', 'nonce');

        $col_id = sanitize_text_field($_POST['collection_id'] ?? '');
        $cols   = self::get_collections();

        if (!isset($cols[$col_id])) {
            wp_send_json_error('Koleksiyon bulunamadı');
        }

        $designs = [];
        foreach ($cols[$col_id]['design_ids'] as $did) {
            $url = self::img_url($did);
            if (!$url) continue;
            $designs[] = [
                'id'   => intval($did),
                'name' => get_the_title($did) ?: ('Tasarım ' . $did),
                'url'  => $url,
            ];
        }
        usort($designs, function ($a, $b) { return strcoll($a['name'], $b['name']); });

        wp_send_json_success([
            'preview_url' => self::img_url($cols[$col_id]['preview_id'] ?? 0),
            'designs'     => $designs,
        ]);
    }
}
