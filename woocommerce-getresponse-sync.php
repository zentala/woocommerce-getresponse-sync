<?php 
/* 
Plugin Name:        WooCommerce - GetResponse Syncing
Plugin URI:         http://zentala.pl/woocommerce-getresponse-syncing/
Description:        Sync customers data between WooCommerce and GetResponse.
Author:             Paul Zentala
Author URI:         http://zentala.co.uk
Version:            1.0
License:            CC BY-NC-SA 4.0
License URI:        http://creativecommons.org/licenses/by-nc-sa/4.0/
Domain Path:        /langs
Text Domain:        wc_getres_sync
*/



// Exit if accessed directly
defined('ABSPATH') or die("Cannot access pages directly.");



class ZntlGetresponseSync {
    
    protected $options;
    protected $api;
    
    
    
    // Construct ********************************************************************
    public function __construct() 
    {
        $this->load_files();
        $this->register_hooks();
    }
    
    
    
    // Load files *******************************************************************
    public function load_files() 
    {
        if ( ! class_exists( 'GetResponse' ) ) {
            require_once( plugin_dir_path( __FILE__ ) . 'GetResponseAPI.class.php' );
        }
    }
    
    
    
    // Register hooks  **************************************************************
    public function register_hooks() 
    {
        register_activation_hook( __FILE__, array('ZntlGetresponseSync', 'plugin_activation'));
        add_action('woocommerce_order_status_completed', array($this, 'status_completed'), 90, 1);
        add_action('admin_init', array($this, 'register_setting_and_fields'));
        add_action('plugins_loaded', array($this, 'init_plugin'));
        add_action('admin_menu', array($this, 'add_menu_page') );
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links') );
        set_error_handler(array($this, 'error_handler'), E_USER_ERROR);
        add_action('admin_notices', array($this, 'notices'),100);
        
    }
  
    
    
    // Plugin activation ************************************************************
    public static function plugin_activation() {
        update_option('wc_getres_sync_admin_notices', array());
        $notices = array();
        
        // WC active check
        if (!is_plugin_active('woocommerce/woocommerce.php')) {
            $notices['woo']= array('WooCommerce is required.', 'error');
            deactivate_plugins( plugin_basename( __FILE__ ));
        }


        // PHP version check
        if ( version_compare(PHP_VERSION, '5.3', '<') ) {
            $notices['php']= array('PHP 5.3 is required.', 'error');
            deactivate_plugins( plugin_basename( __FILE__ ) );
        }

        // WP version check
        if ( version_compare(get_bloginfo('version'), '4.5', '<') ) {
            $notices['wp']= array('WordPress 4.5 is required.', 'error');
            deactivate_plugins( plugin_basename( __FILE__ ) );
        }
        
        update_option('wc_getres_sync_admin_notices', $notices);
        update_option('wc_getres_sync_old_mails', array(), 'no');
    }
    
    
    
    // Plugin init ******************************************************************
    public function init_plugin() {
        $this->options = get_option('wc_getres_sync_options');
        $this->verify_api_key();
        load_plugin_textdomain( 'wc_getres_sync', FALSE, basename( dirname( __FILE__ ) ) . '/langs/' );
    }

    
    // Add action links *************************************************************
    public function add_action_links($links) {
        $mylinks = array(
            '<a href="' . admin_url( 'admin.php?page=wc_getres_sync' ) . '">'. esc_html__('Settings') .'</a>',
        );
        return array_merge( $links, $mylinks );
    }
    
    // Add menu page ****************************************************************
    public function add_menu_page() 
    {
        add_submenu_page(
            'woocommerce',
            __( 'GetResponce Sync', 'wc_getres_sync' ),
            __( 'GetResponce Sync', 'wc_getres_sync' ),
            'manage_woocommerce',
            'wc_getres_sync',
            array($this, 'display_options_page')

        );
    }
    
    
    
    // Display options page *********************************************************
    public function display_options_page() 
    {
        ?>  
        
        <div class="wrap woocomerce">
            <h2><?php _e( 'GetResponce Sync', 'wc_getres_sync' ); ?></h2>
            <form method="post" action="options.php" enctype="multipart/form-data">
                <?php settings_fields('wc_getres_sync_options'); ?>
                <?php do_settings_sections(__FILE__); ?>
                <p class="submit">
                    <input type="submit" class="button-primary" value="<?php esc_html_e('Save Changes'); ?>">
                </p>
            </form>
        </div>
        
        <?php  
    }
    
    
    
    // Register setting and fields **************************************************
    public function register_setting_and_fields() 
    {
        register_setting( 
            'wc_getres_sync_options', 
            'wc_getres_sync_options'
        ); 
        add_settings_section( 
            'wc_getres_sync_section', 
            esc_html__('GetResponce Settings', 'wc_getres_sync'), 
            array($this, 'wc_getres_sync_section_cb'),
            __FILE__ 
        );
        add_settings_field( 
            'wc_getres_sync_api_key', 
            esc_html__('GetResponse API Key', 'wc_getres_sync'), 
            array($this, 'wc_getres_sync_api_key_setting'), 
            __FILE__, 
            'wc_getres_sync_section'
        );
        
        add_settings_field( 
            'wc_getres_sync_campagins_list', 
            esc_html__('GetResponse Campagin', 'wc_getres_sync'), 
            array($this, 'wc_getres_sync_campagins_list_setting'), 
            __FILE__, 
            'wc_getres_sync_section'
        );
        
        add_settings_field( 
            'wc_getres_sync_products_list', 
            esc_html__('WooCommerce Product', 'wc_getres_sync'), 
            array($this, 'wc_getres_sync_products_list_setting'), 
            __FILE__, 
            'wc_getres_sync_section'
        );
    }
    
    
    
    // GetResponse Section Callback *************************************************    
    public function wc_getres_sync_section_cb() {
        // optional
    }
    
    
        
    // Verify API Key ***************************************************************
    public function verify_api_key() 
    {
        $key = $this->options['wc_getres_sync_api_key']; 
        $notices = get_option('wc_getres_sync_admin_notices', array());
        
        if(!empty($key)) {
            try {
                $api = new GetResponse($key);
                $ping = $api->ping();
                if( $ping === "pong") {
                    $this->api = $api;
                    $this->add_old_email();
                    if ( empty( $this->options['wc_getres_sync_campagins_list'] ) ) {
                        $notices['ok'] = array('Connected. Choose campagin.', 'success');
                    }
                } else {
                    $notices[] = array('Invalid API key.', 'error');
                }
            } catch (Exception $ex) {
                if ( strpos($ex->getMessage(), "Could not resolve host:") !== false ) {
                    $this->api = 'error';
                    echo "Could not resolve host:"; // 
                    $notices['cant'] = array('Can\'t connect now. Try again.', 'success');
                } elseif ( strpos($ex->getMessage(), "API call failed.") !== false ) {
                    $this->api = 'error'; 
                    $notices['api'] = array('API call failed. Probably GetResponse API class is outdated.', 'success');
                } 
            }
        } else {
            $notices['key'] = array('Enter GetResponse API key.', 'error');
        }
        
        update_option('wc_getres_sync_admin_notices', $notices);
        
    }
    
    
    
    // Check product ****************************************************************
    public function status_completed($order_id) {
        $order = wc_get_order($order_id);
        if($this->options['wc_getres_sync_products_list'] == 'all') {
            if (!$this->add_email_to_get_response($order)) {
                $this->save_email( array( $order->billing_first_name, $order->billing_email ) );
            }
        } else {
            $items = $order->get_items();
            foreach ($items as $item) {
                $product = $order->get_product_from_item($item);
                if ($product->get_id() == $this->options['wc_getres_sync_products_list']) {
                    if (!$this->add_email_to_get_response($order)) {
                        $this->save_email( array( $order->billing_first_name, $order->billing_email ) );
                    }
                    break;
                }
            }
        } 
    }
 
     
    
    // Add email to GetResponse *****************************************************
    public function add_email_to_get_response($order) {
        if ($this->api == "error") {
            return false;
        } else { 
            try {
                $api = $this->api;
                $addContact = $api->addContact(
                    $this->options['wc_getres_sync_campagins_list'], 
                    $order->billing_first_name, 
                    $order->billing_email
                );
                return true;
            } catch (Exception $ex) {
                return false;
            }
        }
    }
    
    
    
    // Save e-mail
    public function save_email($email) {
        $emails = get_option('wc_getres_sync_old_mails');
        array_push($emails, $email);
        update_option('wc_getres_sync_old_mails', $emails, 'no');
    }
    
    
    
    // Add old emails // zamienić $i na $key =>
    public function add_old_email() {
        $emails = get_option('wc_getres_sync_old_mails');
        $i = 0;
        $order = new stdClass();
        foreach ($emails as $email) {
            $order->billing_first_name = $email[0];
            $order->billing_email = $email[1];
            if($this->add_email_to_get_response($order)){
                unset($emails[$i]);    
            }
            $i  ++;
        }
        update_option('wc_getres_sync_old_mails', $emails, 'no');
    }
    
    
    
    // Notices **********************************************************************
    public static function wc_getres_sync_notice($msg, $type)
    {
        ?>
        
        <div class="notice notice-<?php echo $type; ?>">
            <p><?php echo( '<b>GetResponce Sync</b>: ' . __($msg, 'wc_getres_sync') ); ?></p>
        </div>
        
        <?php
    }
    
    
    // Notices new
    public function notices() {
        $notices = get_option('wc_getres_sync_admin_notices');
        
        if ( $notices ) {
            foreach ($notices as $notice) {
                ?>

                <div class="notice notice-<?php echo $notice[1]; ?>">
                    <p><?php echo( '<b>GetResponce Sync</b>: ' . $notice[0] ); ?></p>
                </div>

                <?php
            }
        }
        update_option('wc_getres_sync_admin_notices', array());
    }
        
    
    
    /*
     *
     *
     *
     * Inputs 
     * 
     */
    


    // [input] GetResponse API Key *************************************************
    public function wc_getres_sync_api_key_setting()
    {
        echo "<input name='wc_getres_sync_options[wc_getres_sync_api_key]' type='text' value='{$this->options['wc_getres_sync_api_key']}' class='regular-text'>";
    }
    
    
    
    // [select] Campagins List Settings ********************************************
    public function wc_getres_sync_campagins_list_setting()
    {
        if ($this->api == 'error') {
            echo esc_html__('Need connection to show campagins list.', 'wc_getres_sync');
        } elseif (!empty($this->api)) {
            try {
                $api = $this->api;
                $campaigns = (array)$api->getCampaigns();
                echo '<select name="wc_getres_sync_options[wc_getres_sync_campagins_list]">';
                if (empty($this->options['wc_getres_sync_campagins_list'])) {
                    echo '<option disabled selected value> -- '. esc_html__('select campagin', 'wc_getres_sync') .' -- </option>';
                }

                foreach ($campaigns as $id => $campagin) {
                    $selected = ($this->options['wc_getres_sync_campagins_list'] === $id) ? 'selected' : '';
                    ?>        
                        <option value="<?php echo $id; ?>" <?php echo $selected; ?>>
                                <?php echo $campagin->name; ?> 
                                (<?php echo $campagin->optin; ?> optin)
                        </option>
                    <?php
                }
                echo '</select>';
            } catch (Exception $ex) {
                echo esc_html__('Need connection to show campagins list.', 'wc_getres_sync');
            }
        } else {
            echo esc_html__('Invalid API Key.', 'wc_getres_sync');
        }
    }



    // [select] Procusts List Settings **********************************************
    public function wc_getres_sync_products_list_setting()
    {
		$loop = new WP_Query( array( 'post_type' => 'product', 'posts_per_page' => 200) );  
        ?>
        
        <select name="wc_getres_sync_options[wc_getres_sync_products_list]"> 
        
    	<?php
        if(empty($this->options['wc_getres_sync_products_list'])) {
            echo '<option disabled selected value> -- '. esc_html__('select product', 'wc_getres_sync') .' -- </option>';
        }?>
            $selected = ($this->options['wc_getres_sync_products_list'] == get_the_ID()) ? 'selected' : '';
            <option selected value="all" <?php echo $selected; ?>>
                <?php esc_html_e('All products', 'wc_getres_sync') ?>
            </option>
        
        <?php 
        
        if ( $loop->have_posts() ) {
			while ( $loop->have_posts() ) : $loop->the_post(); 
                $selected = ($this->options['wc_getres_sync_products_list'] == get_the_ID()) ? 'selected' : '';
                ?>
                
                <option value="<?php echo get_the_ID(); ?>" <?php echo $selected; ?> >
                    <?php echo get_the_title(); ?>
                </option>
                
                <?php
            endwhile;
		} else {
			echo __( 'No products found' );
		}
        
        wp_reset_postdata();
        ?>

        </select>
        
        <?php
    }
    
    
    
    // Error handler
    public function error_handler($error_code, $message, $file, $line)  {
        throw new ErrorException($message, $error_code, 0, $file, $line);
    }



}



// Admin init
new ZntlGetresponseSync();
