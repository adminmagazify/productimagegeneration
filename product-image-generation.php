<?php
/*
Plugin Name: Product Image Generation
Description: CDN Etegre Ürün Görsel Oluşturma Eklentisi
Version: 5.8
Author: Magazac
GitHub Plugin URI: https://github.com/adminmagazify/productimagegeneration
*/

require plugin_dir_path(__FILE__) . 'plugin-update-checker-master/plugin-update-checker.php';

$updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/adminmagazify/productimagegeneration',
    __FILE__,
    'productimagegeneration'
);

$updateChecker->setBranch('main');

/*
 * GitHub API hız limiti (403) sorununu önlemek için kimlik doğrulama.
 * Token public repoya YAZILMAZ; wp-config.php içinde tanımlanır:
 *     define('MOCKUP_GH_TOKEN', 'github_pat_xxx');
 * Token sadece "public repo / contents: read" iznine sahip olmalı.
 */
if (defined('MOCKUP_GH_TOKEN') && MOCKUP_GH_TOKEN) {
    $updateChecker->setAuthentication(MOCKUP_GH_TOKEN);
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
require_once plugin_dir_path(__FILE__) . 'includes/class-frontend-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-interface.php';

class MockupCreator {

    private $drive_automation;
    private $image_processor;
    private $preset_manager;
    private $library_manager;
    private $frontend_handler;
    private $admin_interface;

    public function __construct() {
        $this->init_components();
        $this->register_hooks();
    }

    private function init_components() {
        $this->drive_automation = new MockupDriveAutomation();
        $this->image_processor = new MockupImageProcessor();
        $this->preset_manager = new MockupPresetManager();
        $this->library_manager = new MockupLibraryManager();
        $this->frontend_handler = new MockupFrontendHandler($this->preset_manager);
        $this->admin_interface = new MockupAdminInterface($this->preset_manager);
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
        );

        foreach ($ajax_actions as $action => $callback) {
            add_action("wp_ajax_{$action}", $callback);
            add_action("wp_ajax_nopriv_{$action}", $callback);
        }
    }
}

new MockupCreator();
