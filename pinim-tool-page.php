<?php
    
class Pinim_Tool_Page {
    var $page_acount;
    var $page_boards;
    var $page_settings;
    var $existing_pin_ids = array();
    var $bridge = null;
    var $session = null;
    var $all_action_str = array(); //text on all pins | boards actions
    
    /**
    * @var The one true Instance
    */
    private static $instance;

    public static function instance() {
            if ( ! isset( self::$instance ) ) {
                    self::$instance = new Pinim_Tool_Page;
                    self::$instance->init();
            }
            return self::$instance;
    }
    
    private function __construct() { /* Do nothing here */ }
    
    function init(){
        
        $this->existing_pin_ids = pinim_get_meta_value_by_key('_pinterest-pin_id');
        $this->bridge = new Pinim_Bridge;

        $this->all_action_str = array(
            'import_all_pins'       =>__( 'Import All Pins','pinim' ),
            'update_all_pins'       =>__( 'Update All Pins','pinim' )
        );
        
        add_action( 'admin_init', array( $this, 'settings_init' ) );
        add_action( 'admin_menu',array(&$this,'admin_menu'),10,2);

        add_action( 'current_screen', array( $this, 'page_account_init') );
        add_action( 'current_screen', array( $this, 'page_boards_init') );
        add_action( 'current_screen', array( $this, 'page_pending_import_init') );

        add_action( 'all_admin_notices', array($this, 'plugin_header_feedback_notice') );

    }

    function page_account_init(){
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_account') return;
        
        if ( isset($_REQUEST['logout']) ){
            pinim()->destroy_session();
            add_settings_error('feedback_login', 'clear_cache', __( 'You have logged out, and the plugin cache has been cleared', 'pinim' ), 'updated inline');
        }elseif ( isset($_POST['pinim_form_login']) ){

            $login = ( isset($_POST['pinim_form_login']['username']) ? $_POST['pinim_form_login']['username'] : null);
            $password = ( isset($_POST['pinim_form_login']['password']) ? $_POST['pinim_form_login']['password'] : null);

            $logged = $this->form_do_login($login,$password);

            if (is_wp_error($logged)){
                add_settings_error('feedback_login', 'do_login', $logged->get_error_message(),'inline' );
                return;
            }

            //redirect to next step
            $args = array(
                'page'=>    'boards'
            );

            $url = pinim_get_menu_url($args);
            wp_redirect( $url );
            die();

            
        }
        
    }
    
    function page_boards_init(){
        
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_boards') return;
        
        //warn users secret boards are temporary disabled()
        add_settings_error('feedback_pinim','secret_boards_ignored',__("The plugin is currently unable to load secret boards. We'll try to fix this in the next release.",'pinim'),'error inline');
        
        /*
        SAVE BOARDS
        */
        
        //action
        $action = ( isset($_REQUEST['action']) && ($_REQUEST['action']!=-1)  ? $_REQUEST['action'] : null);
        if (!$action){
            $action = ( isset($_REQUEST['action2']) && ($_REQUEST['action2']!=-1)  ? $_REQUEST['action2'] : null);
        }
        
        if ($action){
            $board_settings = array();
            $board_errors = array();
            $bulk_boards = $this->get_requested_boards();

            if ( is_wp_error($bulk_boards) ) return;

            if (!$action) break;

            switch ($action) {

                case 'boards_save_followed':

                    if ( !pinim()->get_options('enable_follow_boards') ) break;

                    $boards_urls = array();

                    if ($_POST['pinim_form_boards_followed']){

                        $input_urls = $_POST['pinim_form_boards_followed'];

                        $input_urls = trim($input_urls);
                        $input_urls = explode("\n", $input_urls);
                        $input_urls = array_filter($input_urls, 'trim'); // remove any extra \r characters left behind

                        foreach ($input_urls as $url) {
                            $board_args = Pinim_Bridge::validate_board_url($url);
                            if ( is_wp_error($board_args) ) continue;
                            $url = $board_args['url'];
                            $boards_urls[] = esc_url($url);
                            //TO FIX validate board URL

                        }

                    }

                    if ($boards_urls){
                        update_user_meta( get_current_user_id(), 'pinim_followed_boards_urls', $boards_urls);
                    }else{
                        delete_user_meta( get_current_user_id(), 'pinim_followed_boards_urls');
                    }

                    //update current value
                    pinim()->boards_followed_urls = $boards_urls;

                break;

                case 'boards_save_settings':

                    $bulk_data = array();

                    foreach ((array)$bulk_boards as $board){
                        //fetch form data
                        $form_data = $_POST['pinim_form_boards'];

                        $board_id = $board->board_id;

                        //keep only our board
                        $form_data = array_filter(
                            (array)$form_data,
                            function ($e) use ($board_id) {
                                return ( $e['id'] == $board_id );
                            }
                        ); 

                        //keep only first array item
                        $input = array_shift($form_data);

                        //update board
                        $board->in_queue = (isset($input['in_queue']));

                        //autocache
                        $board->options['autocache'] = ( isset($input['autocache']) );

                        //private
                        $board->options['private'] = ( isset($input['private']) );

                        //custom category
                        if ( isset($input['categories']) && ($input['categories']=='custom') && isset($input['category_custom']) && get_term_by('id', $input['category_custom'], 'category') ){ //custom cat
                                $board->options['categories'] = $input['category_custom'];
                        }

                        //save
                        $board->save_session();
                        $board_saved = $board->save_options();

                        if (is_wp_error($board_saved)){
                            add_settings_error('feedback_boards', 'set_options_'.$board->board_id, $board_saved->get_error_message(),'inline');
                        }

                    }

                break;

                case 'boards_cache_pins':

                    $this->cache_boards_pins($bulk_boards);

                break;

            }
        }
        
        /*
        INIT BOARDS
        */
        
        $all_boards = array();
        //check that we are logged
        $user_data = $this->get_user_infos();
        if ( is_wp_error($user_data) || !$user_data ){
            $login_url = pinim_get_menu_url(array('page'=>'account'));
            add_settings_error('feedback_boards','not_logged',sprintf(__('Please <a href="%s">login</a> to be able to list your board.','pinim'),$login_url),'error inline');
        }else{
            $all_boards = $this->get_boards();
        }

        $has_new_boards = false;
        $this->table_boards_user = new Pinim_Boards_Table();

        //load boards
        
        if ( is_wp_error($all_boards) ){
            add_settings_error('feedback_boards', 'get_boards', $all_boards->get_error_message(),'inline');
        }else{
            //cache pins for auto-cache & queued boards
            $autocache_boards = $this->filter_boards($all_boards,'autocache');
            $queued_boards = $this->filter_boards($all_boards,'in_queue');
            $load_pins_boards = array_merge($autocache_boards,$queued_boards);
            $this->cache_boards_pins($load_pins_boards);

            $boards_cached = $this->filter_boards($all_boards,'cached');

            //no boards cached message
            if ( $all_boards && !$boards_cached ){
                $feedback = array(__("Start by caching a bunch of boards so we can get informations about their pins !",'pinim') );
                $feedback[] =   __("You could also check the <em>auto-cache</em> option for some of your boards, so they will always be preloaded.",'pinim');
                add_settings_error('feedback_boards','no_boards_cached',implode('<br/>',$feedback),'updated inline');
            }



            switch ( $this->get_screen_boards_filter() ){
                case 'user':
                    $all_boards = $this->filter_boards($all_boards,'user');
                break;
                case 'cached':
                    $all_boards = $this->filter_boards($all_boards,'cached');
                break;
                case 'not_cached':
                    $all_boards = $this->filter_boards($all_boards,'not_cached');
                break;
                case 'in_queue':
                    $all_boards = $this->filter_boards($all_boards,'in_queue');
                break;
                case 'followed':
                    $all_boards = $this->filter_boards($all_boards,'followed');
                break;
            }

            $this->table_boards_user->input_data = $all_boards;
            $this->table_boards_user->prepare_items();

            //display feedback with import links
            if ( $pending_count = $this->get_pins_count_pending() ){

                $feedback =  array( __("We're ready to process !","pinim") );
                $feedback[] = sprintf( _n( '%s new pin was found in the queued boards.', '%s new pins were found in the queued boards.', $pending_count, 'pinim' ), $pending_count );
                $feedback[] = sprintf( __('You can <a href="%1$s">import them all</a>, or go to the <a href="%2$s">Pins list</a> for advanced control.',"pinim"),
                            pinim_get_menu_url(array('page'=>'pending-importation','all_pins_action'=>$this->all_action_str['import_all_pins'])),
                            pinim_get_menu_url(array('page'=>'pending-importation'))
                );

                add_settings_error('feedback_boards','ready_to_import',implode('  ',$feedback),'updated inline');

            }

        }
    }
    
    function page_pending_import_init(){
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_pending-importation') return;
        
        /*
        IMPORT PINS
        */

        $action = ( isset($_REQUEST['action']) && ($_REQUEST['action']!=-1)  ? $_REQUEST['action'] : null);
        if (!$action){
            $action = ( isset($_REQUEST['action2']) && ($_REQUEST['action2']!=-1)  ? $_REQUEST['action2'] : null);
        }

        //check if a filter action is set
        if ($all_pins_action = $this->get_all_pins_action()){
            $action = $all_pins_action;
        }
        
        if ($action){
            $pin_settings = array();
            $pin_error_ids = array();
            $skip_pin_import = array();
            $bulk_pins_ids = $this->get_requested_pins_ids();
            
            switch ($action) {

                case 'pins_delete_pins':

                    foreach((array)$bulk_pins_ids as $key=>$pin_id){

                        $pin = new Pinim_Pin($pin_id);
                        $pin->get_post();

                        if ( !current_user_can('delete_posts', $pin->post->ID) ) continue;

                        wp_delete_post( $pin->post->ID );

                    }

                break;

                case 'pins_update_pins':

                    if ( !pinim()->get_options('enable_update_pins') ) break;

                    foreach((array)$bulk_pins_ids as $key=>$pin_id){

                        //skip

                        if (!in_array($pin_id,$this->existing_pin_ids)){
                            $skip_pin_import[] = $pin_id;
                            continue;
                        }

                        //save pin
                        $pin = new Pinim_Pin($pin_id);
                        $pin_saved = $pin->save(true);
                        if (is_wp_error($pin_saved)){
                            $pins_errors[$pin->pin_id] = $pin_saved;
                        }

                    }

                    //errors

                    if (!empty($bulk_pins_ids) && !empty($skip_pin_import)){

                        //remove skipped pins from bulk
                        foreach((array)$bulk_pins_ids as $key=>$pin_id){
                            if (!in_array($pin_id,$skip_pin_import)) continue;
                            unset($bulk_pins_ids[$key]);
                        }

                        if (!$all_pins_action){

                            add_settings_error('feedback_pending_import', 'pins_never_imported', 
                                sprintf(
                                    __( 'Some pins cannot be updated because they never have been imported.  Choose "%1$s" if you want import pins. (Pins: %2$s)', 'pinim' ),
                                    __('Import Pins','pinim'),
                                    implode(',',$skip_pin_import)
                                ),
                                'inline'
                            );
                        }

                    }


                    if (!empty($bulk_pins_ids)){

                        $bulk_count = count($bulk_pins_ids);
                        $errors_count = (!empty($pins_errors)) ? count($pins_errors) : 0;
                        $success_count = $bulk_count-$errors_count;

                        if ($success_count){
                            add_settings_error('feedback_pending_import', 'update_pins', sprintf( _n( '%s pin was successfully updated.', '%s pins were successfully updated.', $success_count,'pinim' ), $success_count ), 'updated inline');
                        }

                        if (!empty($pins_errors)){
                            foreach ((array)$pins_errors as $pin_id=>$pin_error){
                                add_settings_error('feedback_pending_import', 'update_pin_'.$pin_id, $pin_error->get_error_message(),'inline');
                            }
                        }
                    }
                break;
                case 'pins_import_pins':

                    foreach((array)$bulk_pins_ids as $key=>$pin_id){

                        //skip
                        if (in_array($pin_id,$this->existing_pin_ids)){
                            $skip_pin_import[] = $pin_id;
                            continue;
                        }

                        //save pin
                        $pin = new Pinim_Pin($pin_id);
                        $pin_saved = $pin->save();
                        if (is_wp_error($pin_saved)){
                            $pins_errors[$pin->pin_id] = $pin_saved;
                        }

                    }


                    //errors

                    if (!empty($bulk_pins_ids) && !empty($skip_pin_import)){

                        //remove skipped pins from bulk
                        foreach((array)$bulk_pins_ids as $key=>$pin_id){
                            if (!in_array($pin_id,$skip_pin_import)) continue;
                            unset($bulk_pins_ids[$key]);
                        }

                        if (!$all_pins_action){

                            add_settings_error('feedback_pending_import', 'pins_already_imported', 
                                sprintf(
                                    __( 'Some pins have been skipped because they already have been imported.  Choose "%1$s" if you want update the existing pins. (Pins: %2$s)', 'pinim' ),
                                    __('Update pins','pinim'),
                                    implode(',',$skip_pin_import)
                                ),
                                'inline'
                            );
                        }
                    }


                    if (!empty($bulk_pins_ids)){

                        $bulk_count = count($bulk_pins_ids);
                        $errors_count = (!empty($pins_errors)) ? count($pins_errors) : 0;
                        $success_count = $bulk_count-$errors_count;

                        if ($success_count){
                            add_settings_error('feedback_pending_import', 'import_pins', 
                                sprintf( _n( '%s pin have been successfully imported.', '%s pins have been successfully imported.', $success_count,'pinim' ), $success_count ),
                                'updated inline'
                            );
                            //refresh pins list
                            $this->existing_pin_ids = pinim_get_meta_value_by_key('_pinterest-pin_id');
                        }

                        if (!empty($pins_errors)){
                            foreach ((array)$pins_errors as $pin_id=>$pin_error){
                                add_settings_error('feedback_pending_import', 'import_pin_'.$pin_id, $pin_error->get_error_message(),'inline');
                            }
                        }
                    }

                    //redirect to processed pins
                    $url = pinim_get_menu_url();
                    wp_redirect( $url );

                break;
            }
            
        }
        
        //clear pins selection //TO FIX REQUIRED ?
        //unset($_REQUEST['pin_ids']);
        //unset($_POST['pinim_form_pins']);
        
        /*
        INIT PENDING  PINS
        */
        if ( !pinim_tool_page()->get_pins_count_pending() ){
            $boards_url = pinim_get_menu_url(array('page'=>'boards'));
            add_settings_error('feedback_pending_import','not_logged',sprintf(__('To list the pins you can import here, you first need to <a href="%s">cache some Pinterest Boards</a>.','pinim'),$boards_url),'error inline');
        }

        
        $this->table_pins = new Pinim_Pending_Pins_Table();
        if ($pins_ids = $this->get_requested_pins_ids()){
            $pins_ids = array_diff($pins_ids, $this->existing_pin_ids);

            //populate pins
            foreach ((array)$pins_ids as $pin_id){
                $pins[] = new Pinim_Pin($pin_id);
            }

            $this->table_pins->input_data = $pins;
            $this->table_pins->prepare_items();
        }
        
        
    }

    
    function get_screen_boards_view_filter(){
        
        $default = pinim()->get_options('boards_view_filter');
        $stored = pinim()->get_session_data('boards_view_filter');
                
        $filter = $stored ? $stored : $default;

        if ( isset($_REQUEST['boards_view_filter']) ) {
            $filter = $_REQUEST['boards_view_filter'];
            pinim()->set_session_data('boards_view_filter',$filter);
        }
        
        return $filter;
        
    }
    
    function get_screen_boards_filter(){
        $default = pinim()->get_options('boards_filter');
        $stored = pinim()->get_session_data('boards_filter');
                
        $filter = $stored ? $stored : $default;

        if ( isset($_REQUEST['boards_filter']) ) {
            $filter = $_REQUEST['boards_filter'];
            pinim()->set_session_data('boards_filter',$filter);
        }
        
        return $filter;
    }
    
    function form_do_login($login=null,$password=null){

        //try to auth
        $logged = $this->do_bridge_login($login,$password);
        if ( is_wp_error($logged) ) return $logged;
        
        //store login / password
        pinim()->set_session_data('login',$login);
        pinim()->set_session_data('password',$password);

        //try to get user datas
        $user_datas = $this->get_user_infos();
        if (is_wp_error($user_datas)) return $user_datas;

        return true;
        
    }

    function do_bridge_login($login = null, $password = null){
       
        if ( !$logged = $this->bridge->is_logged_in() ){
            
            if (!$login) $login = pinim()->get_session_data('login');
            $login = trim($login);

            if (!$password) $password = pinim()->get_session_data('password');
            $password = trim($password);

            if (!$login || !$password){
                return new WP_Error( 'pinim',__('Missing login and/or password','pinim') );
            }

           //force use Pinterest username
            if (strpos($login, '@') !== false) {
                return new WP_Error( 'pinim',__('Use your Pinterest username here, not an email address.','pinim').' <code>https://www.pinterest.com/USERNAME/</code>' );
            }


            //try to auth
            $this->bridge->set_login($login)->set_password($password);
            $logged = $this->bridge->do_login();

            if ( is_wp_error($logged) ){
                return new WP_Error( 'pinim',$logged->get_error_message() );
            }
            
        }

        return $logged;

   }
   
    function cache_boards_pins($boards){

       if (!is_array($boards)){
            $boards = array($boards); //support single items
       }

        foreach((array)$boards as $board){ 
            
            if (!$board->is_queue_complete()){
                $board->in_queue = true;
            }

            $board_pins = $board->get_pins();

            if (is_wp_error($board_pins)){    
                add_settings_error('feedback_boards', 'cache_single_board_pins', $board_pins->get_error_message(),'inline');
            }

        }
   }

    function admin_menu(){
        $this->page_account = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Pinterest Account','pinim'), 
            __('Pinterest Account','pinim'), 
            'manage_options', //TO FIX
            'account', 
            array($this, 'page_account')
        );

        $this->page_boards = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Pinterest Boards','pinim'), 
            __('Pinterest Boards','pinim'), 
            'manage_options', //TO FIX
            'boards', 
            array($this, 'page_boards')
        );
        
        $this->page_pending_import = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Pending importation','pinim'), 
            __('Pending importation','pinim'), 
            'manage_options', //TO FIX
            'pending-importation', 
            array($this, 'page_pending_import')
        );
            
        
        $this->page_settings = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Settings','pinim'), 
            __('Settings','pinim'), 
            'manage_options',
            'settings', 
            array($this, 'page_settings')
        );
    }
    
    function settings_sanitize( $input ){
        $new_input = array();

        if( isset( $input['reset_options'] ) ){
            
            $new_input = pinim()->options_default;
            
        }else{ //sanitize values
            
            //delete boards settings
            if ( isset($input['delete_boards_settings']) ){
                delete_user_meta( get_current_user_id(), 'pinim_boards_settings');
            }

            //boards per page
            if ( isset ($input['boards_per_page']) && ctype_digit($input['boards_per_page']) ){
                $new_input['boards_per_page'] = $input['boards_per_page'];
            }
            
            //pins per page
            if ( isset ($input['pins_per_page']) && ctype_digit($input['pins_per_page']) ){
                $new_input['pins_per_page'] = $input['pins_per_page'];
            }
            
            //autocache
            $new_input['autocache']  = isset ($input['autocache']) ? true : false;
            
            //default post status
            if ( isset ($input['default_status']) ){
                $stati = Pinim_Pin::get_allowed_stati();
                $stati_keys = array_keys($stati);
                if (in_array($input['default_status'],$stati_keys)){
                    $new_input['default_status'] = $input['default_status'];
                }
            }

            //auto private
            $new_input['auto_private']  = isset ($input['auto_private']) ? true : false;

        }
        
        //remove default values
        foreach($input as $slug => $value){
            $default = pinim()->get_default_option($slug);
            if ($value == $default) unset ($input[$slug]);
        }

        $new_input = array_filter($new_input);

        return $new_input;
        
        
    }

    function settings_init(){

        register_setting(
            'pinim_option_group', // Option group
            PinIm::$meta_name_options, // Option name
            array( $this, 'settings_sanitize' ) // Sanitize
         );
        
        add_settings_section(
            'settings_general', // ID
            __('General','pinim'), // Title
            array( $this, 'pinim_settings_general_desc' ), // Callback
            'pinim-settings-page' // Page
        );

        add_settings_field(
            'boards_per_page', 
            __('Boards per page','pinim'), 
            array( $this, 'boards_per_page_field_callback' ), 
            'pinim-settings-page', // Page
            'settings_general' //section
        );
        
        add_settings_field(
            'pins_per_page', 
            __('Pins per page','pinim'), 
            array( $this, 'pins_per_page_field_callback' ), 
            'pinim-settings-page', // Page
            'settings_general' //section
        );
        
        add_settings_field(
            'autocache', 
            __('Auto Cache','pinim'), 
            array( $this, 'autocache_callback' ), 
            'pinim-settings-page', // Page
            'settings_general'//section
        );
        
        add_settings_section(
            'settings_import', // ID
            __('Import','pinim'), // Title
            array( $this, 'pinim_settings_import_desc' ), // Callback
            'pinim-settings-page' // Page
        );
        
        add_settings_field(
            'default_status', 
            __('Defaut post status','pinim'), 
            array( $this, 'default_status_callback' ), 
            'pinim-settings-page', // Page
            'settings_import'//section
        );
        
        add_settings_field(
            'auto_private', 
            __('Auto private status','pinim'), 
            array( $this, 'auto_private_callback' ), 
            'pinim-settings-page', // Page
            'settings_import'//section
        );
        
        add_settings_field(
            'enable_update_pins', 
            __('Enable pin updating','pinim'), 
            array( $this, 'update_pins_field_callback' ), 
            'pinim-settings-page', // Page
            'settings_import' //section
        );
        
        add_settings_section(
            'settings_system', // ID
            __('System','pinim'), // Title
            array( $this, 'pinim_settings_system_desc' ), // Callback
            'pinim-settings-page' // Page
        );
        
        add_settings_field(
            'reset_options', 
            __('Reset Options','pinim'), 
            array( $this, 'reset_options_callback' ), 
            'pinim-settings-page', // Page
            'settings_system'//section
        );
        
        if ( pinim_get_boards_options() ){
            add_settings_field(
                'delete_boards_settings', 
                __('Delete boards preferences','pinim'), 
                array( $this, 'delete_boards_settings_callback' ), 
                'pinim-settings-page', // Page
                'settings_system'//section
            );
        }

    }
    
    function plugin_header_feedback_notice(){
        $screen = get_current_screen();
        if ( $screen->post_type != pinim()->pin_post_type ) return;
        

        $pins_count = count($this->existing_pin_ids);
        if ($pins_count > 1){
            $rate_link_wp = 'https://wordpress.org/support/view/plugin-reviews/pinterest-importer?rate#postform';
            $rate_link = '<a href="'.$rate_link_wp.'" target="_blank" href=""><i class="fa fa-star"></i> '.__('Reviewing the plugin','pinim').'</a>';
            $donate_link = '<a href="'.pinim()->donate_link.'" target="_blank" href=""><i class="fa fa-usd"></i> '.__('make a donation','pinim').'</a>';
            ?>
            <div id="pinim-page-header">
                <p class="description" id="pinim-page-header-feedback">
                    <?php printf(__('<i class="fa fa-pinterest-p"></i>roudly already imported %1$s pins !  Happy with that ? %2$s and %3$s would help!','pinim'),'<strong>'.$pins_count.'</strong>',$rate_link,$donate_link);?>
                </p>
                <?php $this->user_infos_block();?>
            </div>
            <?php
        }

        //general notices
        settings_errors('feedback_pinim'); 
    }
    
    function user_infos_block(){
        
        $user_icon = $user_text = $user_stats = null;

        $user_data = $this->get_user_infos();
        if ( !is_wp_error($user_data) && $user_data ) { //logged
            
            $user_icon = $this->get_user_infos('image_medium_url');
            $username = $this->get_user_infos('username');
            $board_count = (int)$this->get_user_infos('board_count');
            $secret_board_count = (int)$this->get_user_infos('secret_board_count');
            $like_count = (int)$this->get_user_infos('like_count');

            //names
            $user_text = sprintf(__('Logged as %s','pinim'),'<strong>'.$username.'</strong>');

            $list = array();

            //public boards
            $list[] = sprintf(
                '<span>'.__('%1$s public boards','pinim').'</span>',
                '<strong>'.$board_count.'</strong>'
            );

            //public boards
            $list[] = sprintf(
                '<span><strike>'.__('%1$s private boards','pinim').'</strike></span>',
                '<strong>'.$secret_board_count.'</strong>'
            );

            //likes
            $list[] = sprintf(
                '<span>'.__('%1$s likes','pinim').'</span>',
                '<strong>'.$like_count.'</strong>'
            );

            $user_stats = implode(",",$list);

            $user_icon = sprintf('<img src="%s" class="img-cover"/>',$user_icon);
            $logout_link = pinim_get_menu_url(array('page'=>'account','logout'=>true));

            $content = sprintf('<span id="user-info-thumb">%1$s</span><span id="user-info-username">%2$s</span> <small id="user-info-stats">(%3$s)</small> — <a id="user-logout-link" href="%4$s">%5$s</a>',$user_icon,$user_text,$user_stats,$logout_link,__('Logout','pinim'));
            
        }else{ // not logged
            $user_icon = '';
            $user_text = '<strong>' . __('Not logged to Pinterest','pinim') . '</strong>';
            $login_link = pinim_get_menu_url(array('page'=>'account'));
            $content = sprintf('<span id="user-info-thumb">%1$s</span><span id="user-info-username">%2$s</span> — <a id="user-logout-link" href="%3$s">%4$s</a>',$user_icon,$user_text,$login_link,__('Login','pinim'));
        }
        
        printf('<div id="pinim-page-header-account">%s</div>',$content);

    }
    
    function page_account(){
        ?>
        <div class="wrap">
            <h2><?php _e('Pinterest Account','pinim');?></h2>
            <?php
            //check sessions are enabled
            if (!session_id()){
                add_settings_error('feedback_login', 'no_sessions', __("It seems that your host doesn't support PHP sessions.  This plugin will not work properly.  We'll try to fix this soon.","pinim"),'inline');
            }

            ?>

            <?php $this->pinim_form_login_desc();?>
            <form id="pinim-form-login" action="<?php echo pinim_get_menu_url(array('page'=>'account'));?>" method="post">
                <div id="pinim_login_box">
                    <p id="pinim_login_icon"><i class="fa fa-pinterest" aria-hidden="true"></i></p>
                    <?php settings_errors('feedback_login');?>
                    <?php $this->login_field_callback();?>
                    <?php $this->password_field_callback();?>
                    <?php submit_button(__('Login to Pinterest','pinim'));?>
                </div>
            </form>
        </div>
        <?php
    }
    
    function page_boards(){
        ?>
        <div class="wrap">
            <h2><?php _e('Pinterest Boards','pinim');?></h2>
            <?php
            //check sessions are enabled
            //TO FIX TO MOVE ?
            if (!session_id()){
                add_settings_error('feedback_login', 'no_sessions', __("It seems that your host doesn't support PHP sessions.  This plugin will not work properly.  We'll try to fix this soon.","pinim"),'inline');
            }
        
            $form_classes[] = 'view-filter-'.pinim_tool_page()->get_screen_boards_view_filter();
            $form_classes[] = 'pinim-form-boards';

            settings_errors('feedback_boards');

            ?>  
            <form id="pinim-form-user-boards"<?php pinim_classes_attr($form_classes);?> action="<?php echo pinim_get_menu_url(array('page'=>'boards'));?>" method="post">
                <p class="description">
                    <?php _e("This is the list of all the boards we've fetched from your profile, including your likes.","pinim");?>
                </p>
                <?php
                $this->table_boards_user->views_display();
                $this->table_boards_user->views();
                $this->table_boards_user->display();                            
                ?>
            </form>

            <?php
            //followed boards
            if ( pinim()->get_options('enable_follow_boards') ){

                $followed_boards_urls = pinim_get_followed_boards_urls();
                $textarea_content = null;
                foreach ((array)$followed_boards_urls as $board_url){
                    $textarea_content.= esc_url(pinim()->pinterest_url.$board_url)."\n";
                }

                ?>
                <form id="pinim-form-follow-boards-input" class="pinim-form" action="<?php echo pinim_get_menu_url(array('page'=>'boards'));?>" method="post">
                    <h4><?php _e('Add board to follow','pinim');?></h4>

                    <div id="follow-new-board">
                        <p class="description">
                            <?php _e("Enter the URLs of boards from other users.  One line per board url.","pinim");?>
                        </p>

                        <p id="follow-new-board-new">
                            <textarea name="pinim_form_boards_followed"><?php echo $textarea_content;?></textarea>
                        </p>
                    </div>
                    <input type="hidden" name="action" value="boards_save_followed" />
                    <?php submit_button(__('Save boards urls','pinim'));?>
                </form>
                <?php
            }
            ?>
        </div>
        <?php
    }
    
    function page_pending_import(){
?>
        <div class="wrap">
            <h2><?php _e('Pins pending importation','pinim');?></h2>
            <?php settings_errors('feedback_pending_import');?>
            <form action="<?php echo pinim_get_menu_url(array('page'=>'pending-importation'));?>" method="post">
                <?php
                $this->table_pins->views_display();
                $this->table_pins->views();
                $this->table_pins->display();                            
                ?>
            </form>
        </div>
        <?php
    }

    function page_settings(){
        ?>
        <div class="wrap">
            <h2><?php _e('Pinterest Importer Settings','pinim');?></h2>
            <form method="post" action="options.php">
                <?php

                // This prints out all hidden setting fields
                settings_fields( 'pinim_option_group' );   
                do_settings_sections( 'pinim-settings-page' );
                submit_button();

                ?>
            </form>
        </div>
        <?php
    }
    
    function pinim_settings_general_desc(){
        
    }
    
    function autocache_callback(){
        
        $option = (int)pinim()->get_options('autocache');
        $warning = '<i class="fa fa-exclamation-triangle" aria-hidden="true"></i> '.__("Auto-caching too many boards, or boards with a large amount of pins will slow the plugin, because we need to query informations for each pin of each board.","pinim");
        
        printf(
            '<input type="checkbox" name="%1$s[autocache_callback]" value="on" %2$s/> %3$s<br/><p><small>%4$s</small></p>',
            PinIm::$meta_name_options,
            checked( (bool)$option, true, false ),
            __("Automatically cache displayed active boards.","pinim"),
            $warning
        );
    }

    function pinim_form_login_desc(){
        $session_cache = session_cache_expire();
        echo '<p class="description">'.sprintf(__('Your login, password and datas retrieved from Pinterest will be stored for %1$s minutes in a PHP session. It is not stored in the database.','pinim'),$session_cache)."</p>";
    }
    
    function pinim_settings_import_desc(){
        
    }
    
    function default_status_callback(){
        $option = pinim()->get_options('default_status');
        $stati = Pinim_Pin::get_allowed_stati();

        $select_options = array();

        foreach ((array)$stati as $slug=>$status){
            $selected = selected( $option, $slug, false);
            $select_options[] = sprintf('<option value="%1$s" %2$s>%3$s</option>',$slug,$selected,$status);
        }

        printf(
            '<select name="%1$s[default_status]">%2$s</select>',
            PinIm::$meta_name_options,
            implode('',$select_options)
        );
    }
    
    function auto_private_callback(){
        $option = (int)pinim()->get_options('auto_private');

        printf(
            '<input type="checkbox" name="%1$s[auto_private]" value="on" %2$s/> %3$s<br/>',
            PinIm::$meta_name_options,
            checked( (bool)$option, true, false ),
            __("Set post status to private if the pin's board is secret.","pinim")
        );
    }
    
    function update_pins_field_callback(){
        $option = pinim()->get_options('enable_update_pins');

        $disabled = true;
        
        $desc = __('Enable pin updating, which will add links to reload the content of a pin that already has been imported.','pinim');
        $desc .= '  <small>'.__('(Not yet implemented)','pinim').'</small>';
        
        printf(
            '<input type="checkbox" name="%1$s[playlist_link]" value="on" %2$s %3$s/> %4$s',
            PinIm::$meta_name_options,
            checked( (bool)$option, true, false ),
            disabled( $disabled , true, false),
            $desc
        );
    }
    
    function pinim_settings_system_desc(){
        
    }
    
    function reset_options_callback(){
        printf(
            '<input type="checkbox" name="%1$s[reset_options]" value="on"/> %2$s',
            PinIm::$meta_name_options,
            __("Reset options to their default values.","pinim")
        );
    }
    
    function delete_boards_settings_callback(){
        printf(
            '<input type="checkbox" name="%1$s[delete_boards_settings]" value="on"/> %2$s',
            PinIm::$meta_name_options,
            __("Delete the boards preferences for the current user","pinim")
        );
    }
    
    function boards_per_page_field_callback(){
        $option = (int)pinim()->get_options('boards_per_page');
        
        printf(
            '<input type="number" name="%1$s[boards_per_page]" size="3" value="%2$s" /> %3$s',
            PinIm::$meta_name_options,
            $option,
            '<small>'.__("0 = display all boards.","pinim").'</small>'
        );
        
    }
    
    function pins_per_page_field_callback(){
        $option = (int)pinim()->get_options('pins_per_page');

        printf(
            '<input type="number" name="%1$s[pins_per_page]" size="3" min="10" value="%2$s" /><br/>',
            PinIm::$meta_name_options,
            $option
        );
        
    }
    
    function login_field_callback(){
        $option = pinim()->get_session_data('login');
        $disabled = disabled( (bool)$option , true, false);;
        $el_id = 'pinim_form_login_username';
        $el_txt = __('Username');
        $input = sprintf(
            '<input type="text" id="%1$s" name="%2$s[username]" value="%3$s"%4$s/>',
            $el_id,
            'pinim_form_login',
            $option,
            $disabled
        );
        
        printf('<p><label for="%1$s">%2$s</label>%3$s</p>',$el_id,$el_txt,$input);
        
    }
    
    function password_field_callback(){
        $option = pinim()->get_session_data('password');
        $disabled = disabled( (bool)$option, true, false);
        $el_id = 'pinim_form_login_username';
        $el_txt = __('Password');
        
        $input = sprintf(
            '<input type="password" id="%1$s" name="%2$s[password]" value="%3$s"%4$s/>',
            $el_id,
            'pinim_form_login',
            $option,
            $disabled
        );
        
        printf('<p><label for="%1$s">%2$s</label>%3$s</p>',$el_id,$el_txt,$input);
    }

    /**
     * Get datas for a user, from session cache or from Pinterest.
     * @param type $username
     * @return type
     */
    
    function get_user_infos($keys = null,$username = null){
        
        //ignore when logging out
        if ( isset($_REQUEST['logout']) ) return;
        
        if (!$username) $username = pinim()->get_session_data('login');
        
        $session_data = pinim()->get_session_data('user_datas');

        if ( !isset($session_data[$username]) ){
            
            $userdata = $this->bridge->get_user_datas($username);
            if ( is_wp_error($userdata) ) return $userdata;

            $session_data[$username] = $userdata;

            pinim()->set_session_data('user_datas',$session_data);
            
        }
        
        $datas = $session_data[$username];
        return pinim_get_array_value($keys, $datas);

    }
    
    /**
     * Get boards informations for a user, from session cache or from Pinterest.
     * if $username = 'me', get logged in user boards; but use real username or 
     * private boards won't be grabbed.
     * @param type $username
     * @return type
     */
    
    function get_user_boards_data($username = null){
        if (!$username) $username = pinim()->get_session_data('login');
        $session_data = pinim()->get_session_data('user_datas_boards');

        if ( !isset($session_data[$username]) ){

            //try to auth
            $logged = $this->do_bridge_login();
            if ( is_wp_error($logged) ) return $logged;

            $userdata = $this->bridge->get_user_boards($username);
            
            if ( is_wp_error($userdata) ){
                return $userdata;
            }
            
            $session_data[$username] = $userdata;
            pinim()->set_session_data('user_datas_boards',$session_data);

        }

        return $session_data[$username];

    }
    
    function get_boards_user(){
        $boards = array();

        $user_data = $this->get_user_infos();
        if ( is_wp_error($user_data) ) return $user_data;
        if ( !$user_data ) return $boards;

        $boards_datas = $this->get_user_boards_data();
        if ( is_wp_error($boards_datas) ) return $boards_datas;

        foreach((array)$boards_datas as $single_board_datas){
            $boards[] = new Pinim_Board($single_board_datas['url'],$single_board_datas);
        }
        
        //likes
        $username = $this->get_user_infos('username');
        $likes_url = Pinim_Bridge::get_short_url($username,'likes');
        $boards[] = new Pinim_Board($likes_url);
        
        return $boards;
        
    }
    
    function get_boards_followed(){
        
        $boards = array();
        $users_boards_data = array();
        
        //get users from followed boards
        $followed_boards_urls = pinim_get_followed_boards_urls();

        foreach((array)$followed_boards_urls as $board_url){
            $board_args = Pinim_Bridge::validate_board_url($board_url);
            if ( is_wp_error($board_args) ) continue;
            $username = $board_args['username'];
            $slug = $board_args['slug'];
            $url = $board_args['url'];
            
            //get user boards datas
            $user_boards_data = $this->get_user_boards_data($username);
            if ( !$user_boards_data || is_wp_error($user_boards_data) ) continue;
            
            if ($slug == 'likes'){
                $boards[] = new Pinim_Board($url);
            }else{
                //get our board
                $user_boards_data = array_filter(
                    (array)$user_boards_data,
                    function ($e) use ($board_url) {
                        return $e['url'] == $board_url;
                    }
                );  

                if (empty($user_boards_data)) continue;
                $board_data = array_shift($user_boards_data);
                $boards[] = new Pinim_Board($board_url,$board_data);
                
            }
        
        }

        return $boards;

    }

    function get_boards(){

        $user_boards = $this->get_boards_user();
        if ( is_wp_error($user_boards) ) return $user_boards;
        
        $followed_boards = $this->get_boards_followed();

        $boards = array_merge($user_boards,$followed_boards);

        //remove boards with errors
        foreach ((array)$boards as $key=>$board){
            if ( is_wp_error($board) ) unset($boards[$key]);
        }

        //TO FIX check if we should not save some stuff in the session, at this step (eg. board id for likes)
        return $boards;
    }
    
    function get_all_pins_action(){
        $action = null;

        //filter buttons
        if (isset($_REQUEST['all_pins_action'])){
            switch ($_REQUEST['all_pins_action']){
                //step 2
                case $this->all_action_str['import_all_pins']: //Import All Pins
                    $action = 'pins_import_pins';
                break;
                case $this->all_action_str['update_all_pins']: //Update All Pins
                    $action = 'pins_update_pins';
                break;

            }
        }

        return $action;
    }

    function get_all_boards_action(){
        $action = null;
        //filter buttons
        if (isset($_REQUEST['all_boards_action'])){
            switch ($_REQUEST['all_boards_action']){
                case $this->all_action_str['import_all_pins']: //Import All Pins
                    $action = 'boards_import_pins';
                break;

            }
        }
        return $action;
    }
    
    function get_requested_boards(){
        $boards = array();

        if ( $boards_ids = $this->get_requested_boards_ids() ){
            $all_boards = $this->get_boards();

            if ( is_wp_error($all_boards) ) return $all_boards;
            
            $boards = array_filter(
                (array)$all_boards,
                function ($e) use ($boards_ids) {
                    return ( in_array($e->board_id,$boards_ids) );
                }
            ); 
        }

        return $boards;
    }
    
    function get_requested_boards_ids(){

        $bulk_boards_ids = array();
        $all_boards = $this->get_boards();

        //bulk boards
        if ( isset($_POST['pinim_form_boards']) ) {

            $form_boards = $_POST['pinim_form_boards'];
            
            //remove items that are not checked
            $form_boards = array_filter(
                (array)$_POST['pinim_form_boards'],
                function ($e) {
                    return isset($e['bulk']);
                }
            ); 

            foreach((array)$form_boards as $board){
                $bulk_boards_ids[] = $board['id'];
            }

        }elseif ( isset($_REQUEST['board_ids']) ) {
            $bulk_boards_ids = explode(',',$_REQUEST['board_ids']);
        }

        return $bulk_boards_ids;
    }
    
    function get_requested_pins_ids(){

        
        $bulk_pins_ids = array();

        //bulk pins
        if ( isset($_POST['pinim_form_pins']) ) {

            $form_pins = $_POST['pinim_form_pins'];
            
            //remove items that are not checked
            $form_pins = array_filter(
                (array)$_POST['pinim_form_pins'],
                function ($e) {
                    return isset($e['bulk']);
                }
            ); 

            foreach((array)$form_pins as $pin){
                $bulk_pins_ids[] = $pin['id'];
            }

        }elseif ( isset($_REQUEST['pin_ids']) ) {
            $bulk_pins_ids = explode(',',$_REQUEST['pin_ids']);
        }

        if ( (!$bulk_pins_ids) && ($all_pins = pinim_tool_page()->get_queued_raw_pins()) && !is_wp_error($all_pins) ) {

            foreach((array)$all_pins as $pin){
                $bulk_pins_ids[] = $pin['id'];
            }

        }

        return $bulk_pins_ids;
    }
    
    function get_queued_raw_pins(){
        return $this->get_all_raw_pins(true);
    }

    function get_all_raw_pins($only_queued_boards = false){

        $pins = array();

        $boards = $this->get_boards();
        
        if (!is_wp_error($boards)) {

            foreach ((array)$boards as $board){

                if ( !$board->raw_pins ) continue;
                if ( $only_queued_boards && !$board->in_queue ) continue;

                $pins = array_merge($pins,$board->raw_pins);

            }
            
        }

        return $pins;

    }
    
    function get_pins_count_pending(){
        $pins_ids = $this->get_requested_pins_ids();
        $pins_ids = array_diff($pins_ids, $this->existing_pin_ids);

        return count($pins_ids);
    }
    
    function get_pins_count_processed(){
        return count(pinim_tool_page()->existing_pin_ids);
    }
    
    function filter_boards($boards,$filter){

        $output = array();
        if( is_wp_error($boards) ) return $output;
        
        $username = $this->get_user_infos('username');
        
        switch ($filter){
            case 'autocache':
                if ( !pinim()->get_options('autocache') ) break;
                
                foreach((array)$boards as $board){
                    if ( $board->get_options('autocache') ){
                        $output[] = $board;
                    }
                }
                
            break;
            
            case 'cached':
                
                foreach((array)$boards as $board){
                    if ( !$board->is_queue_complete() ) continue; //query done
                    $output[] = $board;

                }
                
            break;
            
            case 'not_cached':
                
                foreach((array)$boards as $board){
                    if ( $board->bookmark ==  '-end-' ) continue;
                    $output[] = $board;

                }
                
            break;
            
            case 'in_queue':
                
                foreach((array)$boards as $board){
                
                    if ( !$board->raw_pins ) continue; //empty
                    if ( !$board->in_queue ) continue; //not in queue                    
                    if ( $board->is_fully_imported() ) continue; //full                    
                    $output[] = $board;
                }
                
            break;
            
            case 'complete':
                
                foreach((array)$boards as $board){
                    if (!$board->raw_pins) continue; //empty
                    if ($board->is_fully_imported()){
                        $output[] = $board;
                    }
                }
                
            break;
            
            case 'incomplete':
                
                foreach((array)$boards as $board){
                    if (!$board->raw_pins || !$board->is_fully_imported()){
                        $output[] = $board;
                    }
                }
                
            break;
            
            case 'user':

                foreach((array)$boards as $board){
                    if($board->username != $username) continue;
                    $output[] = $board;
                }
                
            break;
            
            case 'followed':

                foreach((array)$boards as $board){
                    if($board->username == $username) continue;
                    $output[] = $board;
                }
                
            break;
            

            
        }
        
        return $output;
    }

}

function pinim_tool_page() {
	return Pinim_Tool_Page::instance();
}

if (is_admin()){
    pinim_tool_page();
}