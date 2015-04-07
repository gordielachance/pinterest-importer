<?php
/*
Plugin Name: Pinterest Importer
Description: Import images & videos from a Pinterest account.
Version: 0.1.3
Author: G.Breant
Author URI: http://sandbox.pencil2d.org
Plugin URI: http://wordpress.org/extend/plugins/pinterest-importer
License: GPL2
*/



class PinIm {

    /** Version ***************************************************************/

    /**
    * @public string plugin version
    */
    public $version = '0.1.3';

    /**
    * @public string plugin DB version
    */
    public $db_version = '100';

    /** Paths *****************************************************************/

    public $file = '';

    /**
    * @public string Basename of the plugin directory
    */
    public $basename = '';

    /**
    * @public string Absolute path to the plugin directory
    */
    public $plugin_dir = '';
    
    private $meta_name_options = 'pinim_options';
    private $usermeta_name_options = 'pinim_options';


    /**
    * @var The one true Instance
    */
    private static $instance;

    public static function instance() {
            if ( ! isset( self::$instance ) ) {
                    self::$instance = new PinIm;
                    self::$instance->setup_globals();
                    self::$instance->includes();
                    self::$instance->setup_actions();
            }
            return self::$instance;
    }

    public $import_attachment_size_limit;

    /**
        * A dummy constructor to prevent bbPress from being loaded more than once.
        *
        * @since bbPress (r2464)
        * @see bbPress::instance()
        * @see bbpress();
        */
    private function __construct() { /* Do nothing here */ }

    function setup_globals() {

            /** Paths *************************************************************/
            $this->file       = __FILE__;
            $this->basename   = plugin_basename( $this->file );
            $this->plugin_dir = plugin_dir_path( $this->file );
            $this->plugin_url = plugin_dir_url ( $this->file );

            $this->import_attachment_size_limit = 0; //0 = unlimited
            
            $this->options_default = array(

            );
            
            $this->options = wp_parse_args(get_option( $this->meta_name_options), $this->options_default);

    }

    
    
    function includes(){

        // Load Importer API
        require_once ABSPATH . 'wp-admin/includes/import.php';
        
        require $this->plugin_dir . '/pinim-templates.php';

        if ( ! class_exists( 'WP_Importer' ) ) {
                $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
                if ( file_exists( $class_wp_importer ) ) {
                    require $class_wp_importer;
                }
        }

        require $this->plugin_dir . '/pinim-class.php';
    }

    function setup_actions(){  
        
        //upgrade
        add_action( 'plugins_loaded', array($this, 'upgrade'));        
        add_action( 'add_meta_boxes', array($this, 'pinim_metabox'));
        
        add_action( 'admin_init', array(&$this,'load_textdomain'));
        add_action( 'admin_init', array(&$this,'register_importer'));
        add_action( 'admin_init', array( $this, 'settings_page_init' ) );
        add_action( 'admin_menu',array(&$this,'admin_menu'),10,2);

        if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) return;

        /** Display verbose errors */
        if (!defined('IMPORT_DEBUG')) define( 'IMPORT_DEBUG', false );


        
        $root_category_id = pinim_get_term_id('Pinterest.com','category'); // create or get the root category
        $this->root_category_id = apply_filters('pinim_get_root_category_id',$root_category_id);

        add_filter('pinim_get_post_content','pinim_add_source_text',10,2);
        
        // Must run after wp's `option_update_filter()`, so priority > 10
        add_action( 'whitelist_options', array( $this, 'whitelist_custom_options_page' ),11 );
        


    }
    
    function admin_menu(){
        
        // This page will be under "Settings"
        
        $this->options_page = add_management_page(
                __('Pinterest Importer','pinim'),
                __('Pinterest Importer','pinim'),
                'manage_options',
                'pinim-import',
                array( $this, 'importer_page' )
        );
        
        //add_submenu_page('edit.php?post_type='.celiogame()->round_post_type, __('Export Entries','celiogame'), __('Export Entries','celiogame'), 'manage_options', 'contest-export', array($this, 'admin_page_export'));
    }
    
    function importer_page(){
        // Set class property
            ?>
            <div class="wrap">
                <?php screen_icon(); ?>
                <h2><?php _e('Pinterest Importer','pinim');?></h2>  

                <form method="post" action="options.php">
                <?php
                    // This prints out all hidden setting fields
                    settings_fields( 'pinim_user_settings' );   
                    do_settings_sections( 'pinim-settings-admin' );
                    submit_button(__('Login'),'pinim'); 
                ?>
                </form>
            </div>
            <?php
    }
    
    
    
    function load_textdomain() {
        load_plugin_textdomain( 'pinim', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }
    
    function get_user_option($key = false){

        $default = array(
            'login'     => null,
            'password'  => null,
        );

        if ($user_id = get_current_user_id()){
            $user_options = get_user_meta( $user_id, $this->usermeta_name_options, true);
            $user_options = wp_parse_args($user_options, $default);
        };
        
        if ($key && isset($user_options[$key])){
            return $user_options[$key];
        }else{
            return $user_options;
        }
        
    }
    
    function sanitize_user_settings( $input ){
        
        $user_id = get_current_user_id();
        $new_input = array();

        if( isset( $input['login'] )  ){
            $new_input['login'] = $input['login']; 
        }
        if( isset( $input['password'] )  ){
            $new_input['password'] = $input['password']; 
        }

        update_user_meta( $user_id, $this->usermeta_name_options, $new_input );
        
        //We do not save this as a site option
        return false;
    }
    
    function settings_page_init(){
        register_setting(
            'pinim_user_settings', // Option group
            pinim()->usermeta_name_options, // Option name
            array( $this, 'sanitize_user_settings' ) // Sanitize
        );

        add_settings_section(
            'settings_general', // ID
            __('Pinterest credentials','pinim'), // Title
            array( $this, 'section_general_desc' ), // Callback
            'pinim-settings-admin' // Page
        );  
        
        add_settings_field(
            'login', 
            __('Login','pinim'), 
            array( $this, 'login_field_callback' ), 
            'pinim-settings-admin', 
            'settings_general'
        );
        
        add_settings_field(
            'password', 
            __('Password','pinim'), 
            array( $this, 'password_field_callback' ), 
            'pinim-settings-admin', 
            'settings_general'
        );
        
        $login = $this->get_user_option('login');
        $password = $this->get_user_option('password');

        if ($login && $password){
            
            require pinim()->plugin_dir . '_inc/lib/pinterest-pinner/PinterestPinner.php';
            $this->PinterestPinner = new PinterestPinner($login,$password);
            
            add_settings_field(
                'status', 
                __('Status','pinim'), 
                array( $this, 'status_field_callback' ), 
                'pinim-settings-admin', 
                'settings_general'
            );
            
            if($this->PinterestPinner->isLoggedIn()){

                $this->user_boards = $this->PinterestPinner->getUserBoards();
                $this->current_user_board = 0;

                add_settings_section(
                    'settings_boards', // ID
                    __('Boards','pinim'), // Title
                    array( $this, 'section_boards_desc' ), // Callback
                    'pinim-settings-admin' // Page
                );  
                
                foreach((array)$this->user_boards as $board){
                    $boardname = $board['name'];
                    if ($board['privacy']=='secret'){
                        $boardname.=' <em>('.__('Secret','pinim').')</em>';
                    }
                    add_settings_field(
                        'user_board_'.$board['id'], 
                        $boardname, 
                        array( $this, 'boards_field_callback' ), 
                        'pinim-settings-admin', 
                        'settings_boards'
                    );
                }


                
            }

        }

    }
    
    function section_general_desc(){
        
    }
    
    function login_field_callback(){
        $option = $this->get_user_option('login');
        printf(
            '<input type="text" name="%1$s[login]" value="%2$s"/>',
            $this->usermeta_name_options,
            $option
        );
    }
    
    function password_field_callback(){
        $option = $this->get_user_option('login');
        printf(
            '<input type="password" name="%1$s[password]" value="%2$s"/>',
            $this->usermeta_name_options,
            $option
        );
    }
    
    function status_field_callback(){
       
        try {
            $user_resources = $this->PinterestPinner->getUserResources();
            print_r($user_resources);
        } catch (Exception $e) {
            echo $e->getMessage();
        }

    }
    
    function section_boards_desc(){
        
    }
    
    function boards_field_callback(){

        $board = $this->user_boards[$this->current_user_board];
        $this->current_user_board++;
        
        $boards_checked = $this->get_user_option('include_boards');
        if (isset($boards_checked[$board['id']])){
            $checked = $boards_checked[$board['id']];
        }else{
            $checked = ($board['privacy']=='public');
        }
        
        $checked_str = checked($checked, true, false );
        
        printf(
            '<input type="checkbox" name="%1$s[include_boards][%2$s]" value="on" %3$s/> %4$s',
            $this->usermeta_name_options,
            $board['id'],
            $checked_str,
            __('Include this board','pinim')
        );
        
        $cat_args = array(
            'hide_empty' => false,
            'depth' => 20,
            'hierarchical'  => 1
        );
        
        wp_dropdown_categories( $cat_args );
        
        ?>

        <?php
        
        
    }
    
    function register_importer() {
            /**
            * WordPress Importer object for registering the import callback
            * @global WP_Import $wp_import
            */
            $GLOBALS['pinterest_wp_import'] = new Pinterest_Importer();
            register_importer( 'pinterest-pins', 'Pinterest', sprintf(__('Import pins from your %s account to Wordpress.', 'pinim'),'<a href="http://www.pinterest.com" target="_blank">Pinterest.com</a>'), array( $GLOBALS['pinterest_wp_import'], 'dispatch' ) );
    }

    function upgrade(){
        global $wpdb;

        $current_version = get_option("_pinterest-importer-db_version");

        if ($current_version==$this->db_version) return false;

        if(!$current_version){
            /*
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
             */
        }

        //update DB version
        update_option("_pinterest-importer-db_version", $this->db_version );

    }
    
    /**
     * Display a metabox for posts having imported with this plugin
     * @return type
     */
    
    function pinim_metabox(){
        $metas = pinim_get_pin_meta();
        
        if (empty($metas)) return;
        
        add_meta_box(
                'pinterest_datas',
                __( 'Pinterest', 'pinim' ),
                array(&$this,'pinim_metabox_content'),
                'post'
        );
    }
    
    function pinim_metabox_content( $post ) {
        
        $metas = pinim_get_pin_meta();

        
        ?>
        <table id="pinterest-list-table">
                <thead>
                <tr>
                        <th class="left"><?php _ex( 'Name', 'meta name' ) ?></th>
                        <th><?php _e( 'Value' ) ?></th>
                </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ( $metas as $meta_key => $meta ) {
                        
                        $content = null;
                        
                         switch ($meta_key){
                            case 'pinner':
                                $meta_key = __('Pinner URL','pinim');
                                $pinner_url = pinim_get_user_url($meta[0]);
                                $content = '<a href="'.$pinner_url.'" target="_blank">'.$pinner_url.'</a>';
                            break;
                            case 'pin_id':
                                $meta_key = __('Pin URL','pinim');
                                
                                $links = array();
                                $links_str = null;
                                
                                foreach((array)$meta as $pin_id){
                                    $pin_url = pinim_get_pin_url($pin_id);
                                    $links[] = '<a href="'.$pin_url.'" target="_blank">'.$pin_url.'</a>';
                                }
                                
                                $content = implode('<br/>',$links);
                                

                            break;
                            case 'board_slug':
                                $meta_key = __('Board URL','pinim');
                                $board_url = pinim_get_board_url($metas['pinner'],$meta[0]);
                                $content = '<a href="'.$board_url.'" target="_blank">'.$board_url.'</a>';
                            break;
                            case 'source':
                                $meta_key = __('Source URL','pinim');
                                $content = '<a href="'.$meta[0].'" target="_blank">'.$meta[0].'</a>';
                            break;
                            default:
                                $content = $meta[0];
                            break;
                        }
                        
                        
                            ?>
                            <tr class="alternate">
                                <td class="left">
                                    <?php echo $meta_key;?>
                                </td>
                                <td>
                                    <?php echo $content;?>
                                </td>
                            </tr>
                            <?php
                    }

                    ?>
                </tbody>
        </table>
        <?php
    }

}

/**
 * The main function responsible for returning the one Instance
 * to functions everywhere.
 *
 * Use this function like you would a global variable, except without needing
 * to declare the global.
 *
 */

function pinim() {
	return PinIm::instance();
}

if (is_admin()){
    pinim();
}

?>