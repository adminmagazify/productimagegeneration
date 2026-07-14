<?php
/*
Plugin Name: Product Image Generation
Description: CDN Etegre Ürün Görsel Oluşturma Eklentisi
Version: 6.11
Author: Magazac
GitHub Plugin URI: https://github.com/adminmagazify/productimagegeneration
*/

/*
 * GitHub üzerinden otomatik güncelleme (plugin-update-checker).
 * Savunmacı yükleme: dosyalar eksik/bozuksa bile eklenti ÇÖKMEZ.
 */
$puc_dir  = plugin_dir_path(__FILE__) . 'plugin-update-checker-master/';
$puc_file = $puc_dir . 'plugin-update-checker.php';
$puc_auto = $puc_dir . 'Puc/v5p6/Autoloader.php';
if (file_exists($puc_file) && file_exists($puc_auto)) {
    require_once $puc_file;

    if (class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
        try {
            $updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/adminmagazify/productimagegeneration',
                __FILE__,
                'productimagegeneration'
            );
            $updateChecker->setBranch('main');

            if (defined('MOCKUP_GH_TOKEN') && MOCKUP_GH_TOKEN) {
                $updateChecker->setAuthentication(MOCKUP_GH_TOKEN);
            }
        } catch (\Throwable $e) {
            // Güncelleme denetleyicisi başlatılamadı; eklenti yine de çalışır.
        }
    }
}

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit;
}

// Dosyaları yükle
require_once plugin_dir_path(__FILE__) . 'includes/class-drive-automation.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-image-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-preset-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-library-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-r2-storage.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-frontend-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-central-sync.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-interface.php';

class PigCreator {

    private $drive_automation;
    private $image_processor;
    private $preset_manager;
    private $library_manager;
    private $r2_storage;
    private $frontend_handler;
    private $admin_interface;

    public function __construct() {
        $this->init_components();
        $this->register_hooks();
    }

    private function init_components() {
        $this->drive_automation = new PigDriveAutomation();
        $this->image_processor = new PigImageProcessor();
        $this->preset_manager = new PigPresetManager();
        $this->library_manager = new PigLibraryManager();
        $this->r2_storage = new PigR2Storage();
        $this->frontend_handler = new PigFrontendHandler($this->preset_manager);
        $this->admin_interface = new PigAdminInterface($this->preset_manager);
    }

    private function register_hooks() {
        // Admin menü ve scriptler
        add_action('admin_menu', array($this->admin_interface, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this->admin_interface, 'enqueue_scripts'));

        // Frontend
        add_action('wp_enqueue_scripts', array($this->frontend_handler, 'enqueue_frontend_scripts'));
        add_action('init', array($this->frontend_handler, 'add_shortcode'));

        // AJAX işlemleri
        $this->register_ajax_handlers();

        // Merkezin profilleri push ettiği REST endpoint (panelden tek tuşla gönderim)
        add_action('rest_api_init', ['PigCentralSync', 'register_rest_routes']);

        // Aktivasyon/deaktivasyon
        register_activation_hook(__FILE__, array($this->drive_automation, 'activate_automation'));
        register_deactivation_hook(__FILE__, array($this->drive_automation, 'deactivate_automation'));
    }

    private function register_ajax_handlers() {
        $ajax_actions = array(
            'generate_mockup' => array($this->image_processor, 'generate_mockup'),
            'download_mockup' => array($this->image_processor, 'download_mockup'),
            'get_drive_files' => array($this->drive_automation, 'get_drive_files'),
            'save_mockup_settings' => array($this->drive_automation, 'save_settings'),
            'save_to_media' => array($this->image_processor, 'save_to_media'),
            'get_presets' => array($this->preset_manager, 'get_presets'),
            'save_preset' => array($this->preset_manager, 'save_preset'),
            'update_preset' => array($this->preset_manager, 'update_preset'),
            'delete_preset' => array($this->preset_manager, 'delete_preset'),
            'get_presets_with_images' => array($this->preset_manager, 'get_presets_with_images'),
            'test_drive_connection_manual' => array($this->drive_automation, 'test_drive_connection_manual'),

            // Medya Kütüphanesi tabanlı yeni kaynaklar
            'get_library_mockups'     => array($this->library_manager, 'ajax_get_library_mockups'),
            'get_library_collections' => array($this->library_manager, 'ajax_get_library_collections'),
            'get_library_designs'     => array($this->library_manager, 'ajax_get_library_designs'),

            // Ön/arka kompozit üretimi
            'generate_mockup_v2'      => array($this->image_processor, 'generate_mockup_v2'),

            // Cloudflare R2 kaynakları
            'get_r2_mockups'          => array($this->r2_storage, 'ajax_get_mockups'),
            'get_r2_collections'      => array($this->r2_storage, 'ajax_get_collections'),
            'get_r2_designs'          => array($this->r2_storage, 'ajax_get_designs'),
            'test_r2_connection'      => array($this->r2_storage, 'ajax_test_connection'),
            'generate_mockup_r2'      => array($this->image_processor, 'generate_mockup_r2'),

            // R2 görsel proxy (görselleri site üzerinden servis eder — DNS/public URL gerekmez)
            'pig_r2_img'              => array($this->r2_storage, 'ajax_image'),
        );

        foreach ($ajax_actions as $action => $callback) {
            add_action("wp_ajax_{$action}", $callback);
            add_action("wp_ajax_nopriv_{$action}", $callback);
        }
    }
}

new PigCreator();
