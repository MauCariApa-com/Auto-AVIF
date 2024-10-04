<?php
/*
Plugin Name: Auto Convert Images to AVIF
Plugin URI: https://maucariapa.com
Description: Automatically converts JPG and PNG images to AVIF on upload, serves AVIF versions where available, replaces image URLs with AVIF in WordPress posts, and removes original JPG or PNG images after conversion. Includes options for managing thumbnails, viewing progress, enhanced responsive images, and image placeholders.
Version: 1.0.0
Author: MauCariApa.com
Author URI: https://maucariapa.com
License: GPLv2
License URI: https://opensource.org/licenses/GPL-2.0
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 8.2
Text Domain: auto-convert-images-to-avif
Domain Path: /languages/
*/

// Load plugin text domain for translations
function aci_load_textdomain() {
    load_plugin_textdomain('auto-convert-images-to-avif', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', 'aci_load_textdomain');

if (!defined('ABSPATH')) {
    exit;
}

class AutoConvertImagesToAVIF {
    private $plugin_version = '1.0.0';

    public function __construct() {
        // Hook into image uploads
        add_filter('wp_handle_upload', array($this, 'convert_image_to_avif'));

        // Hook into thumbnail regeneration
        add_action('wp_update_attachment_metadata', array($this, 'regenerate_thumbnails'), 10, 2);

        // Redirection to AVIF when available for all images
        add_filter('wp_get_attachment_url', array($this, 'redirect_to_avif_if_exists'), 10, 2);

        // Replace image URLs in post content
        add_filter('the_content', array($this, 'replace_images_in_content'));

        // Add placeholder support, if enabled
        // add_filter('wp_get_attachment_image_attributes', array($this, 'add_placeholder'), 10, 2);

        // Admin options and settings
        add_action('admin_menu', array($this, 'settings_page'));
        add_action('admin_init', array($this, 'register_settings'));

        // Enqueue color picker script
        // add_action('admin_enqueue_scripts', array($this, 'enqueue_color_picker'));
    }

    public function convert_image_to_avif($image) {
        $file_path = $image['file'];
        $file_ext = pathinfo($file_path, PATHINFO_EXTENSION);

        // Only convert JPG and PNG files
        if (in_array(strtolower($file_ext), array('jpg', 'jpeg', 'png'))) {
            $this->convert_to_avif($file_path);
        }
        return $image;
    }

    private function convert_to_avif($file_path) {
        $compression_speed = get_option('avif_compression_speed', 5);
        $quality = get_option('avif_quality', 70);
        $delete_original = get_option('avif_delete_original', 'no');

        if (extension_loaded('imagick')) {
            $imagick = new Imagick($file_path);
            $imagick->setImageFormat('avif');
            $imagick->setOption('avif:compression-speed', $compression_speed);
            $imagick->setImageCompressionQuality($quality);
            $avif_file = preg_replace('/\.(jpg|jpeg|png)$/i', '.avif', $file_path);
            $imagick->writeImage($avif_file);
            $imagick->destroy();

            if (file_exists($avif_file)) {
                $this->log_progress('Generated AVIF file: ' . basename($avif_file));

                // Delete original file if option is enabled
                if ($delete_original === 'yes') {
                    unlink($file_path);
                    $this->log_progress('Removed original file: ' . basename($file_path));
                }
            }
        } else {
            $this->log_progress('Imagick extension not available.');
        }
    }

    public function regenerate_thumbnails($attachment_id, $metadata) {
        $attachment_path = get_attached_file($attachment_id);
        $file_ext = pathinfo($attachment_path, PATHINFO_EXTENSION);

        if (in_array(strtolower($file_ext), array('jpg', 'jpeg', 'png'))) {
            $this->convert_to_avif($attachment_path);
            // $this->remove_old_thumbnails($attachment_id);
        }
    }

    private function remove_old_thumbnails($attachment_id) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!isset($metadata['sizes'])) return;

        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'];

        foreach ($metadata['sizes'] as $size => $size_data) {
            $thumbnail_path = $upload_path . '/' . dirname($metadata['file']) . '/' . $size_data['file'];
            if (file_exists($thumbnail_path)) {
                unlink($thumbnail_path);
                $this->log_progress('Removed old thumbnail: ' . basename($thumbnail_path));
            }
        }
    }

    private function log_progress($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[AVIF Plugin] ' . $message);
        }
    }

    public function redirect_to_avif_if_exists($url, $post_id) {
        $file_path = get_attached_file($post_id);
        $avif_file = preg_replace('/\.(jpg|jpeg|png)$/i', '.avif', $file_path);

        if (file_exists($avif_file)) {
            $url = preg_replace('/\.(jpg|jpeg|png)$/i', '.avif', $url);
        }
        return $url;
    }

    public function replace_images_in_content($content) {
        $enhanced_responsive = get_option('avif_enhanced_responsive', 'no');
        if ($enhanced_responsive === 'yes') {
            $pattern = '/<img\s+([^>]*?)src\s*=\s*[\'"]([^\'"]+)\.(jpg|jpeg|png)[\'"]([^>]*)>/i';
            $content = preg_replace_callback($pattern, function($matches) {
                $src = $matches[2] . '.avif';
                return '<img ' . $matches[1] . 'src="' . esc_url($src) . '" ' . $matches[4] . '>';
            }, $content);
        }

        return $content;
    }

    /* public function add_placeholder($attr, $attachment) {
        // Retrieve the value of the checkbox option
        $enable_placeholder = get_option('avif_enable_placeholder', 'yes');
        
        // If the checkbox is not checked, return the attributes as is (no placeholder)
        if ($enable_placeholder !== 'yes') {
            return $attr; // Do not add placeholder styles or classes if not enabled
        }
    
        // Apply placeholder styles and classes only if the checkbox is checked
        $placeholder_color = get_option('avif_placeholder_color', '#cccccc');
        $attr['style'] = 'background-color: ' . esc_attr($placeholder_color) . ';';
        $attr['class'] .= ' avif-placeholder';
    
        return $attr;
    }
    */

    public function settings_page() {
        add_options_page('AVIF Conversion Settings', 'AVIF Settings', 'manage_options', 'avif-settings', array($this, 'settings_page_html'));
    }

    public function settings_page_html() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';
        ?>

        <div class="wrap">
            <h1>AVIF Conversion Settings</h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=avif-settings&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=avif-settings&tab=system_info" class="nav-tab <?php echo $active_tab == 'system_info' ? 'nav-tab-active' : ''; ?>">System Info</a>
                <a href="?page=avif-settings&tab=about" class="nav-tab <?php echo $active_tab == 'about' ? 'nav-tab-active' : ''; ?>">About</a>
                <a href="?page=avif-settings&tab=changelog" class="nav-tab <?php echo $active_tab == 'changelog' ? 'nav-tab-active' : ''; ?>">Changelog</a>
                <a href="?page=avif-settings&tab=donation" class="nav-tab <?php echo $active_tab == 'donation' ? 'nav-tab-active' : ''; ?>">Donation</a>
            </h2>

            <?php
            if ($active_tab == 'settings') {
                ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('avif-settings-group');
                    do_settings_sections('avif-settings');
                    submit_button();
                    ?>
                </form>
                <?php
            } elseif ($active_tab == 'system_info') {
                $this->display_system_info();
            } elseif ($active_tab == 'about') {
                $this->display_about_tab();
            } elseif ($active_tab == 'changelog') {
                $this->display_changelog_tab();
            } elseif ($active_tab == 'donation') {
                $this->display_donation_tab();
            }
            ?>
        </div>
        <?php
    }

public function display_system_info() {
    // Detect OS information
    $os = php_uname('s');
    $os_version = php_uname('r');
    
    // Linux detection
    if (stristr(PHP_OS, 'Linux')) {
        if (file_exists('/etc/os-release')) {
            $os_data = parse_ini_file('/etc/os-release');
            $os = isset($os_data['NAME']) ? $os_data['NAME'] : 'Unknown Linux';
            $os_version = isset($os_data['VERSION']) ? $os_data['VERSION'] : php_uname('r');
        } else {
            $os = 'Linux';
        }
    }
    // BSD detection
    elseif (stristr(PHP_OS, 'BSD')) {
        if (stristr($os, 'FreeBSD')) {
            $os = 'FreeBSD';
        } elseif (stristr($os, 'OpenBSD')) {
            $os = 'OpenBSD';
        } elseif (stristr($os, 'NetBSD')) {
            $os = 'NetBSD';
        } elseif (stristr($os, 'DragonFlyBSD')) {
            $os = 'DragonFlyBSD';
        } else {
            $os = 'BSD Family';
        }
    }
    // Windows Server detection
    elseif (stristr(PHP_OS, 'WINNT')) {
        $os = 'Windows Server';
        $os_version = php_uname('r');
    }

    
    // Web server information
    $web_server = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown Web Server';

    // Check PHP-FPM status
    $php_fpm_status = strpos(php_sapi_name(), 'fpm-fcgi') !== false ? 'Loaded' : 'Not Loaded';

    // AVIF support detection starts here:
    $avif_engine = 'None'; // Default
    $avif_support_imageavif = function_exists('imageavif') ? 'Supported' : 'Not Supported';

    // Check Imagick support for AVIF
    if (extension_loaded('imagick') && class_exists('Imagick')) {
        $imagick_version = Imagick::getVersion()['versionString'];
        $imagick_formats = Imagick::queryFormats();
        $imagick_avif = in_array('AVIF', $imagick_formats) ? 'Supported' : 'Not Supported';
        if ($imagick_avif === 'Supported') {
            $avif_engine = 'Imagick';
        }
    } else {
        $imagick_version = 'Not Loaded';
        $imagick_avif = 'Not Supported';
    }

    // Check Gmagick support for AVIF
    if (extension_loaded('gmagick') && class_exists('Gmagick')) {
        $gmagick_status = 'Loaded';
        $gmagick_avif = (strpos(shell_exec('gm convert -list format 2>&1'), 'AVIF') !== false) ? 'Supported' : 'Not Supported';
        if ($gmagick_avif === 'Supported' && $avif_engine === 'None') {
            $avif_engine = 'Gmagick';
        }
    } else {
        $gmagick_status = 'Not Loaded';
        $gmagick_avif = 'Not Supported';
    }

    // Check if the native PHP `imageavif()` function is available
    if ($avif_support_imageavif === 'Supported' && $avif_engine === 'None') {
        $avif_engine = 'PHP imageavif()';
    }

    // Check FFmpeg for AVIF support
    $ffmpeg_avif = strpos(shell_exec('ffmpeg -encoders 2>&1'), 'libaom-av1') !== false ? 'Supported' : 'Not Supported';
    if ($ffmpeg_avif === 'Supported' && $avif_engine === 'None') {
        $avif_engine = 'FFmpeg';
    }

    // Check libavif for AVIF support
    $libavif_avif = strpos(shell_exec('avifenc --version 2>&1'), 'avifenc') !== false ? 'Supported' : 'Not Supported';
    if ($libavif_avif === 'Supported' && $avif_engine === 'None') {
        $avif_engine = 'libavif';
    }


    // Output the system information
    ?>
    <div class="wrap">
        <h2>System Information</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Plugin Version</th>
                <td><?php echo esc_html($this->plugin_version); ?></td>
            </tr>
            <tr>
                <th scope="row">Server Info:</th>
            </tr>
            <tr>
                <th scope="row">Operating System</th>
                <td><?php echo esc_html($os . ' ' . $os_version); ?></td>
            </tr>
            <tr>
                <th scope="row">Web Server</th>
                <td><?php echo esc_html($web_server); ?></td>
            </tr>
            <tr>
                <th scope="row">PHP Version</th>
                <td><?php echo esc_html(PHP_VERSION); ?></td>
            </tr>
            <tr>
                <th scope="row">PHP-FPM</th>
                <td><?php echo esc_html($php_fpm_status); ?></td>
            </tr>
            <tr>
                <th scope="row">AVIF Image Support:</th>
            </tr>
            <tr>
                <th scope="row">Preferred AVIF Engine</th>
                <td><?php echo esc_html($avif_engine); ?></td>
            </tr>
            <tr>
                <th scope="row">imageavif</th>
                <td><?php echo esc_html($avif_support_imageavif); ?></td>
            </tr>
            <tr>
                <th scope="row">Imagick/ImageMagick</th>
                <td><?php echo esc_html($imagick_avif . ' (' . $imagick_version . ')'); ?></td>
            </tr>
            <tr>
                <th scope="row">Gmagick</th>
                <td><?php echo esc_html($gmagick_avif . ' (' . $gmagick_status . ')'); ?></td>
            </tr>
            <tr>
                <th scope="row">FFmpeg</th>
                <td><?php echo esc_html($ffmpeg_avif); ?></td>
            </tr>
            <tr>
                <th scope="row">libavif</th>
                <td><?php echo esc_html($libavif_avif); ?></td>
            </tr>
        </table>
    </div>
    <?php
}


    public function register_settings() {
        register_setting('avif-settings-group', 'avif_compression_speed');
        register_setting('avif-settings-group', 'avif_quality');
        register_setting('avif-settings-group', 'avif_delete_original');
        register_setting('avif-settings-group', 'avif_enhanced_responsive');
        // register_setting('avif-settings-group', 'avif_enable_placeholder'); // New setting
        // register_setting('avif-settings-group', 'avif_placeholder_color');


        add_settings_section('avif_general_settings', 'General Settings', null, 'avif-settings');

        add_settings_field('avif_compression_speed', 'Compression Speed', array($this, 'field_compression_speed'), 'avif-settings', 'avif_general_settings');
        add_settings_field('avif_quality', 'Image Quality', array($this, 'field_quality'), 'avif-settings', 'avif_general_settings');
        add_settings_field('avif_delete_original', 'Delete Original Images', array($this, 'field_delete_original'), 'avif-settings', 'avif_general_settings');
        add_settings_field('avif_enhanced_responsive', 'Enhanced Responsive Images', array($this, 'field_enhanced_responsive'), 'avif-settings', 'avif_general_settings');
        // add_settings_field('avif_enable_placeholder', 'Enable Image Placeholder', array($this, 'field_enable_placeholder'), 'avif-settings', 'avif_general_settings');
        // add_settings_field('avif_placeholder_color', 'Placeholder Color', array($this, 'field_placeholder_color'), 'avif-settings', 'avif_general_settings');
    }

    public function field_compression_speed() {
        $value = get_option('avif_compression_speed', 5);
        echo '<input type="number" name="avif_compression_speed" value="' . esc_attr($value) . '" min="0" max="10" />';
    }

    public function field_quality() {
        $value = get_option('avif_quality', 70);
        echo '<input type="number" name="avif_quality" value="' . esc_attr($value) . '" min="1" max="100" />';
    }

    public function field_delete_original() {
        $value = get_option('avif_delete_original', 'no');
        echo '<input type="checkbox" name="avif_delete_original" value="yes" ' . checked($value, 'yes', false) . ' />';
    }

    public function field_enhanced_responsive() {
        $value = get_option('avif_enhanced_responsive', 'no');
        echo '<input type="checkbox" name="avif_enhanced_responsive" value="yes" ' . checked($value, 'yes', false) . ' />';
    }

    /* public function field_enable_placeholder() {
        $value = get_option('avif_enable_placeholder', 'yes');
        echo '<input type="checkbox" name="avif_enable_placeholder" value="yes" ' . checked($value, 'yes', false) . ' />';
    }
    */

    public function field_placeholder_color() {
        $value = get_option('avif_placeholder_color', '#cccccc');
        echo '<input type="text" name="avif_placeholder_color" value="' . esc_attr($value) . '" class="wp-color-picker" />';
    }
    

    public function display_system_information() {
        // Other system info
        $php_fpm_status = $this->is_php_fpm_loaded() ? 'Loaded' : 'Not Loaded';
        
        echo '<h3>System Information</h3>';
        echo '<ul>';
        // Other system information...
        echo '<li><strong>PHP-FPM:</strong> ' . esc_html($php_fpm_status) . '</li>';
        echo '</ul>';
    }
    

    public function enqueue_color_picker() {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        
        // Initialize the color picker in the WordPress admin panel
        add_action('admin_footer', function() {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('.wp-color-picker').wpColorPicker();
                });
            </script>
            <?php
        });
    }

    public function display_about_tab() {
        ?>
        <div class="wrap">
            <h2>About Auto Convert Images to AVIF</h2>
                <p>This plugin automatically converts JPG and PNG images to AVIF format on upload, and serves AVIF images where available. It includes options for managing thumbnails, viewing conversion progress, and adding image placeholders.</p>
                <p>For more information, visit the <a href="https://maucariapa.com">plugin page</a>.</p>
            <h2>About AVIF</h2>
                <p>AVIF (AV1 Image File Format) is a modern image format that offers superior compression while maintaining high image quality.</p>
                <p>This plugin helps websites benefit from AVIF by automatically converting images.</p>                           
        </div>
        <?php
    }

    public function display_changelog_tab() {
        ?>
        <div class="wrap">
            <h2>Changelog</h2>
            <ul>
                <li><strong>1.0.0</strong> - Initial release with basic AVIF conversion, thumbnail regeneration, and settings options.</li>
            </ul>
        </div>
        <?php
    }


    public function display_donation_tab() {
        ?>
        <div class="wrap">
            <h2>Support This Plugin</h2>
            <p>If you find this plugin useful, consider supporting its development via donation.</p>
            <a href="https://www.paypal.com/paypalme/kodester" target="_blank" class="button button-primary">Donate via PayPal</a>
        </div>
        <?php
    }

}

new AutoConvertImagesToAVIF();
