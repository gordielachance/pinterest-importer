<?php

class Pinim_Tool_Page {
    
    var $options_page;
    var $current_step = 0;
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

        $this->all_action_str = array(
            'import_all_pins'       =>__( 'Import All Pins','pinim' ),
            'update_all_pins'       =>__( 'Update All Pins','pinim' )
        );

        add_action( 'admin_init', array( $this, 'init_tool_page' ) );
        //add_action( 'admin_init', array( $this, 'reduce_settings_errors' ) );
        add_action( 'admin_init', array( $this, 'settings_init' ) );
        add_action( 'admin_menu',array(&$this,'admin_menu'),10,2);
        
        add_action( 'admin_init', array( $this, 'register_session' ), 1);
        add_action('wp_logout', array( $this, 'destroy_session' ) );
        add_action('wp_login', array( $this, 'destroy_session' ) );
        
    }
    
    function can_show_step($slug){
        switch($slug){
            case 'pinterest-login':
                if ( !$this->get_session_data('user_datas') ) return true;
            break;
            case 'boards-settings':
                if ( $this->get_session_data('user_datas') ) return true;
            break;
            case 'pins-list':
                if ( $this->get_all_cached_pins_raw(true) || $this->existing_pin_ids ) return true;
            break;
            case 'pinim-options':
                if( current_user_can( 'manage_options' ) ) return true;
            break;
        }
    }
    
    function init_tool_page(){
        if (!pinim_is_tool_page()) return false;

        $this->existing_pin_ids = pinim_get_meta_value_by_key('_pinterest-pin_id');
        $this->bridge = new Pinim_Bridge;
        $step = pinim_get_tool_page_step();
        
        if($step!==false){
            $this->current_step = $step;
            
        }else{
            
            if ( $boards_data = $this->get_session_data('user_boards') ){ //we've got a boards cache
                $url = pinim_get_tool_page_url(array('step'=>'boards-settings'));
            }else{
                $url = pinim_get_tool_page_url(array('step'=>'pinterest-login'));
            }
            
            wp_redirect( $url );
            die();
            
        }

        $this->save_step();
        $this->init_step();

    }
    
    /**
     * Removes duplicate settings errors (based on their messages)
     * @global type $wp_settings_errors
     */
    
    function reduce_settings_errors(){
        //remove duplicates errors based on their message
        global $wp_settings_errors;

        if (empty($wp_settings_errors)) return;
        
        foreach($wp_settings_errors as $key => $value) {
          foreach($wp_settings_errors as $key2 => $value2) {
            if($key != $key2 && $value['message'] === $value['message']) {
              unset($wp_settings_errors[$key]);
            }
          }
        }
        
    }
    
    function get_screen_boards_view_filter(){
        
        $default = pinim()->get_options('boards_view_filter');
        $stored = $this->get_session_data('boards_view_filter');
                
        $filter = $stored ? $stored : $default;

        if ( isset($_REQUEST['boards_view_filter']) ) {
            $filter = $_REQUEST['boards_view_filter'];
            pinim_tool_page()->set_session_data('boards_view_filter',$filter);
        }
        
        return $filter;
        
    }
    
    function get_screen_boards_filter(){
        $default = pinim()->get_options('boards_filter');
        $stored = $this->get_session_data('boards_filter');
                
        $filter = $stored ? $stored : $default;

        if ( isset($_REQUEST['boards_filter']) ) {
            $filter = $_REQUEST['boards_filter'];
            pinim_tool_page()->set_session_data('boards_filter',$filter);
        }
        
        return $filter;
    }
    
    function get_screen_pins_filter(){

        $default = pinim()->get_options('pins_filter');
        $stored = $this->get_session_data('pins_filter');
        
        if ( !pinim_tool_page()->get_pins_count_pending() ){
            $default = 'processed';
        }

        $filter = $stored ? $stored : $default;

        if ( isset($_REQUEST['pins_filter']) ) {
            $filter = $_REQUEST['pins_filter'];
            pinim_tool_page()->set_session_data('pins_filter',$filter);
        }
        
        return $filter;
    }
    
    function form_do_login($login=null,$password=null){

        //try to auth
        $logged = $this->do_bridge_login($login,$password);
        if ( is_wp_error($logged) ) return $logged;
        
        //store login / password
        $this->set_session_data('login',$login);
        $this->set_session_data('password',$password);

        //try to get user datas
        $user_datas = $this->bridge->get_user_datas();
        if (is_wp_error($user_datas)) return $user_datas;

        //store user datas
        $this->set_session_data('user_datas',$user_datas);

        return true;
        
    }

    
    function save_step(){

        $user_id = get_current_user_id();
        
        //action
        $action = ( isset($_REQUEST['action']) && ($_REQUEST['action']!=-1)  ? $_REQUEST['action'] : null);
        if (!$action){
            $action = ( isset($_REQUEST['action2']) && ($_REQUEST['action2']!=-1)  ? $_REQUEST['action2'] : null);
        }

        switch ($this->current_step){

            case 'pins-list':

                $pin_settings = array();
                $pin_error_ids = array();
                $skip_pin_import = array();
                $bulk_pins_ids = $this->get_requested_pins_ids();

                //check if a filter action is set
                if ($all_pins_action = $this->get_all_pins_action()){
                    $action = $all_pins_action;
                }

                switch ($action) {
                    
                    case 'pins_delete_pins':

                        foreach((array)$bulk_pins_ids as $key=>$pin_id){
                            
                            $pin = new Pinim_Pin($pin_id);
                            $pin->get_post();
                            
                            if ( !current_user_can('delete_posts', $pin->post_id) ) continue;
                            
                            wp_delete_post( $pin->post_id );

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
                            
                                add_settings_error('pinim_form_pins', 'pins_never_imported', 
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
                                add_settings_error('pinim_form_pins', 'update_pins', sprintf( _n( '%s pin was successfully updated.', '%s pins were successfully updated.', $success_count,'pinim' ), $success_count ), 'updated inline');
                            }
                            
                            if (!empty($pins_errors)){
                                foreach ((array)$pins_errors as $pin_id=>$pin_error){
                                    add_settings_error('pinim_form_pins', 'update_pin_'.$pin_id, $pin_error->get_error_message(),'inline');
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
                                
                                add_settings_error('pinim_form_pins', 'pins_already_imported', 
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
                                add_settings_error('pinim_form_pins', 'import_pins', 
                                    sprintf( _n( '%s pin have been successfully imported.', '%s pins have been successfully imported.', $success_count,'pinim' ), $success_count ),
                                    'updated inline'
                                );
                                //refresh pins list
                                $this->existing_pin_ids = pinim_get_meta_value_by_key('_pinterest-pin_id');
                            }
                            
                            if (!empty($pins_errors)){
                                foreach ((array)$pins_errors as $pin_id=>$pin_error){
                                    add_settings_error('pinim_form_pins', 'import_pin_'.$pin_id, $pin_error->get_error_message(),'inline');
                                }
                            }
                        }
                        
                        //update screen filter
                        $_REQUEST['pins_filter'] = 'processed';

                    break;
                }

            break;
            
            case 'boards-settings'://'boards-settings':

                $board_settings = array();
                $board_errors = array();

                if (!$action) break;

                switch ($action) {
                    
                    case 'boards_save_followed':
                        
                        if (!$_POST['pinim_form_boards_followed']) break;
                        
                        $boards_urls = array();
                        $input_urls = $_POST['pinim_form_boards_followed'];
                        
                        $input_urls = trim($input_urls);
                        $input_urls = explode("\n", $input_urls);
                        $input_urls = array_filter($input_urls, 'trim'); // remove any extra \r characters left behind

                        foreach ($input_urls as $url) {
                            $boards_urls[] = esc_url($url);
                            //TO FIX validate board URL
                            
                        }
                        
                        //save
                        if ($boards_urls){
                            update_user_meta( get_current_user_id(), 'pinim_followed_boards_urls', $boards_urls);
                        }
                        
                    break;
                    
                    case 'boards_save_settings':

                        $bulk_data = array();
                        $bulk_boards = $this->get_requested_boards();

                        foreach ((array)$bulk_boards as $board){
                            //fetch form data
                            $form_data = $_POST['pinim_form_boards'];
 
                            $board_form_data = array_filter(
                                $form_data,
                                function ($e) use ($board) {
                                    return ( $e['id'] == $board->board_id );
                                }
                            ); 
                            
                            //keep only first array item
                            $board_form_data = array_slice($board_form_data, 0, 1);
                            $board_form_data = array_shift($board_form_data); 

                            //save
                            $board_saved = $board->set_options($board_form_data);

                            if (is_wp_error($board_saved)){
                                add_settings_error('pinim_form_boards', 'set_options_'.$board->board_id, $board_saved->get_error_message(),'inline');
                            }

                        }

                    break;
                    
                    case 'boards_cache_pins':
                        
                        foreach((array)$bulk_boards as $board){
                            $this->cache_boards_pins($board);
                            $board->queue_board();
                        }
                        
                        
                    break;

                }


            break;
            case 'pinterest-login':

                //logout
                if ( $this->get_session_data() && isset($_REQUEST['logout']) ){
                    $this->delete_session_data();
                    add_settings_error('pinim_form_login', 'clear_cache', __( 'You have logged out, and the plugin cache has been cleared', 'pinim' ), 'updated inline');
                    return;
                }

                if ( !isset($_POST['pinim_form_login']) ) return;

                $login = ( isset($_POST['pinim_form_login']['username']) ? $_POST['pinim_form_login']['username'] : null);
                $password = ( isset($_POST['pinim_form_login']['password']) ? $_POST['pinim_form_login']['password'] : null);

                $logged = $this->form_do_login($login,$password);

                if (is_wp_error($logged)){
                    add_settings_error('pinim_form_login', 'do_login', $logged->get_error_message(),'inline' );
                    return;
                }
                
                

                //redirect to next step
                $args = array(
                    'step'=>'boards-settings'
                );

                $url = pinim_get_tool_page_url($args);
                wp_redirect( $url );
                die();

            break;
        }
        
    }
    
   function do_bridge_login($login = null, $password = null){
       
       if ($this->bridge->is_logged_in) return $this->bridge->is_logged_in;
       
       if (!$login){
           $login = $this->get_session_data('login');
       }
       if (!$password){
           $password = $this->get_session_data('password');
       }

        //try to auth
        $this->bridge->set_login($login)->set_password($password);
        $logged = $this->bridge->do_login();

        if ( is_wp_error($logged) ){
            return new WP_Error( 'pinim',$logged->get_error_message() );
        }
        
        return $logged;

   }
   
   function cache_boards_pins($boards){
       
       if (!is_array($boards)){
            $boards = array($boards); //support single items
       }

        foreach((array)$boards as $board){ 
            $board_pins = $board->get_pins();

            if (is_wp_error($board_pins)){    
                add_settings_error('pinim_form_boards', 'cache_single_board_pins_'.$board->board_id, $board_pins->get_error_message(),'inline');
            }

        }
   }

    function init_step(){

        $board_ids = array();

        switch ($this->current_step){
            
            case 'pins-list':
                
                //we should not be here !
                if ( !$this->can_show_step('pins-list') ){
                    $url = pinim_get_tool_page_url(array('step'=>'pinterest-login'));
                    wp_redirect( $url );
                    die();
                }
                
                $pins = array();
                
                switch ( $this->get_screen_pins_filter() ){
                    case 'pending':
                        
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
                        
                    break;
                    case 'processed':
                        
                        $this->table_posts = new Pinim_Processed_Pins_Table();
                        $this->table_posts->prepare_items();
                        
                    break;
                }
                
                //clear pins selection
                unset($_REQUEST['pin_ids']);
                unset($_POST['pinim_form_pins']);
                

            break;
            
            case 'boards-settings': //boards settings
                
                //we should not be here !
                if ( !$this->can_show_step('boards-settings') ){
                    $url = pinim_get_tool_page_url(array('step'=>'pinterest-login'));
                    wp_redirect( $url );
                    die();
                }

                $boards = array();
                $has_new_boards = false;
                $this->table_boards = new Pinim_Boards_Table();

                //load boards
                $boards = $this->get_boards();

                if ( is_wp_error($boards) ){
                    
                    add_settings_error('pinim_form_boards', 'get_boards', $boards->get_error_message(),'inline');
                    $boards = array(); //reset boards
                    
                }else{

                    switch ( $this->get_screen_boards_filter() ){
                        case 'all':
                            $boards = $this->get_boards();
                        break;
                        case 'cached':
                            $boards = $this->get_boards_cached();
                        break;
                        case 'not_cached':
                            $boards = $this->get_boards_not_cached();
                        break;
                        case 'in_queue':
                            $boards = $this->get_boards_in_queue();
                        break;
                    }

                    $this->table_boards->input_data = $boards;
                    $this->table_boards->prepare_items();

                    //cache pins for auto-cache boards
                    if ( pinim()->get_options('autocache') ) {
                        $autocache_boards = $this->get_boards_autocache();

                        foreach((array)$autocache_boards as $board){
                            if ( $board->get_pins_queue() ) continue; //we already did try to reach Pinterest)
                            $this->cache_boards_pins($board);
                            $board->queue_board();
                        }


                    }
                    
                    //no boards cached message
                    if ( !$this->get_boards_cached() ){
                        $feedback = array(__("Start by caching a bunch of boards so we can get informations about their pins !",'pinim') );
                        $feedback[] =   __("You could also check the <em>auto-cache</em> option for some of your boards, so they will always be preloaded.",'pinim');
                        add_settings_error('pinim_form_boards','no_boards_cached',implode('<br/>',$feedback),'updated inline');
                    }

                    //display feedback with import links
                    if ( $pending_count = $this->get_pins_count_pending() ){

                        $feedback =  array( __("We're ready to process !","pinim") );
                        $feedback[] = sprintf( _n( '%s new pin was found in the queued boards.', '%s new pins were found in the queued boards.', $pending_count, 'pinim' ), $pending_count );
                        $feedback[] = sprintf( __('You can <a href="%1$s">import them all</a>, or go to the <a href="%2$s">Pins list</a> for advanced control.',"pinim"),
                                    pinim_get_tool_page_url(array('step'=>'pins-list','all_pins_action'=>$this->all_action_str['import_all_pins'])),
                                    pinim_get_tool_page_url(array('step'=>'pins-list'))
                        );

                        add_settings_error('pinim_form_boards','ready_to_import',implode('  ',$feedback),'updated inline');

                    }

                }

            break;
        }
    }
    
    function admin_menu(){
        $this->options_page = add_submenu_page('tools.php', __('Pinterest Importer','pinim'), __('Pinterest Importer','pinim'), 'manage_options', 'pinim', array($this, 'importer_page'));
    }
    
    function settings_sanitize( $input ){
        $new_input = array();

        if( isset( $input['reset_options'] ) ){
            
            $new_input = pinim()->options_default;
            
        }else{ //sanitize values

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

        add_settings_field(
            'enable_update_pins', 
            __('Enable pin updating','pinim'), 
            array( $this, 'update_pins_field_callback' ), 
            'pinim-settings-page', // Page
            'settings_general' //section
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
        
 
    }

    function importer_page(){
        // Set class property
        ?>
        <div class="wrap">
            <h2><?php _e('Pinterest Importer','pinim');?></h2>  
            <?php
                $pins_count = count($this->existing_pin_ids);
                if ($pins_count > 1){
                    $rate_link_wp = 'https://wordpress.org/support/view/plugin-reviews/pinterest-importer?rate#postform';
                    $rate_link = '<a href="'.$rate_link_wp.'" target="_blank" href=""><i class="fa fa-star"></i> '.__('Reviewing the plugin','pinim').'</a>';
                    $donate_link = '<a href="'.pinim()->donation_url.'" target="_blank" href=""><i class="fa fa-usd"></i> '.__('make a donation','pinim').'</a>';
                    ?>
                    <p class="description" id="header-links">
                        <?php printf(__('<i class="fa fa-pinterest-p"></i>roudly already imported %1$s pins !  Happy with that ? %2$s and %3$s would help!','pinim'),'<strong>'.$pins_count.'</strong>',$rate_link,$donate_link);?>
                    </p>
                    <?php
                }
            ?>
                    
            <?php $this->user_infos_block();?>
            
            <?php 
            
            //general notices
            settings_errors('pinim'); 
            
            $content_classes = array('pinim_tab_content');
            $content_classes[] = 'pinim_tab_content-'.$this->current_step;
            
            $form_classes = array('pinim-form');
            
            ?>
            
            <h2 class="nav-tab-wrapper">
                <?php $this->importer_page_tabs($this->current_step); ?>
            </h2>
            <div<?php pinim_classes($content_classes);?>>
                
                <?php
                     switch ($this->current_step){

                         case 'pinterest-login':

                            //check sessions are enabled
                            if (!session_id()){
                                add_settings_error('pinim_form_login', 'no_sessions', __("It seems that your host doesn't support PHP sessions.  This plugin will not work properly.  We'll try to fix this soon.","pinim"),'inline');
                            }

                            ?>

                            <?php $this->pinim_form_login_desc();?>
                            <form id="pinim-form-login"<?php pinim_classes($form_classes);?> action="<?php echo pinim_get_tool_page_url();?>" method="post">
                                <div id="pinim_login_box">
                                    <p id="pinim_login_icon"><i class="fa fa-pinterest" aria-hidden="true"></i></p>
                                    <?php settings_errors('pinim_form_login');?>
                                    <?php $this->login_field_callback();?>
                                    <?php $this->password_field_callback();?>
                                    <input type="hidden" name="step" value="<?php echo $this->current_step;?>" />
                                    <?php submit_button(__('Login to Pinterest','pinim'));?>
                                </div>
                            </form>
                            <?php
                         break;

                         case 'boards-settings':
                             
                            $form_classes[] = 'view-filter-'.pinim_tool_page()->get_screen_boards_view_filter();
                            $form_classes[] = 'pinim-form-boards';
                             
                            //user boards
                             
                            $boards_datas = pinim_tool_page()->get_user_boards_raw();
                            if (!is_wp_error($boards_datas)){ //TO FIX and is logged
                                ?>  
                                <form id="pinim-form-user-boards"<?php pinim_classes($form_classes);?> action="<?php echo pinim_get_tool_page_url();?>" method="post">
                                    <?php settings_errors('pinim_form_boards');?>
                                    <input type="hidden" name="step" value="<?php echo $this->current_step;?>" />

                                    <h3><?php _e('My boards','pinim');?></h3>
                                    <div class="tab-description">
                                        <p>
                                            <?php _e("This is the list of all the boards we've fetched from your profile, including your likes.","pinim");?>
                                        </p>
                                    </div>
                                    <?php
                                    $this->table_boards->views_display();
                                    $this->table_boards->views();
                                    $this->table_boards->display();                            
                                    ?>
                                </form>
                
                                <?php
                                //followed boards
                                /*
                                $followed_boards_urls = pinim_get_followed_boards_urls();
                                $textarea_content = null;
                                foreach ((array)$followed_boards_urls as $board_url){
                                    $textarea_content.= esc_url($board_url)."\n";
                                }
                                
                                ?>
                                <form id="pinim-form-followed-boards"<?php pinim_classes($form_classes);?> action="<?php echo pinim_get_tool_page_url();?>" method="post">
                                    <input type="hidden" name="step" value="<?php echo $this->current_step;?>" />
                                    <input type="hidden" name="action" value="boards_save_followed" />

                                    <h3><?php _e('Followed boards','pinim');?></h3>
                                    <div id="follow-new-board" class="tab-description">
                                        <p>
                                            <?php _e("Enter the URLs of the boards you would like to follow.  One line per board url.","pinim");?>
                                        </p>
                                        
                                        <?php settings_errors('pinim_form_followed_boards');?>
                                        
                                        <p id="follow-new-board-new">
                                            <textarea name="pinim_form_boards_followed"><?php echo $textarea_content;?></textarea>
                                        </p>
                                    </div>
                                    <?php submit_button(__('Save boards urls','pinim'));?>
                                </form>
                                <?php
                                 * 
                                 */
                            }
                             
                            

                         break;


                         case 'pins-list':
                             
                            ?>
                            <?php settings_errors('pinim_form_pins');?>
                            <form id="pinim-form-pins"<?php pinim_classes($form_classes);?> action="<?php echo pinim_get_tool_page_url();?>" method="post">
                                <?php
                                
                                //switch view
                                switch ( $this->get_screen_pins_filter() ){
                                    case 'pending':
    
                                        $this->table_pins->views();
                                        $this->table_pins->display();
                                            
                                    break;
                                    case 'processed':
                                        
                                        $this->table_posts->views();
                                        $this->table_posts->display();

                                        
                                    break;
                                }                                

                                ?>
                                <input type="hidden" name="step" value="<?php echo $this->current_step;?>" />
                            </form>
                            <?php

                         break;

                         case 'pinim-options':

                            settings_errors('spiff_option_group');
                             
                            ?>
                            <form<?php pinim_classes($form_classes);?> method="post" action="options.php">
                                <?php

                                // This prints out all hidden setting fields
                                settings_fields( 'pinim_option_group' );   
                                do_settings_sections( 'pinim-settings-page' );
                                submit_button();
                                
                                ?>
                            </form>
                            <?php
                         break;
                     }
                 ?>
                
            </div>
        </div>
        <?php
    }
    
    function importer_page_tabs( $active_tab = '' ) {
        
            $tabs = array();
            $tabs_html    = '';
            $idle_class   = 'nav-tab';
            $active_class = 'nav-tab nav-tab-active';
            $has_user_datas = (null !== $this->get_session_data('user_datas'));

            //login
            if ( $this->can_show_step('pinterest-login') ){
                $tabs['pinterest-login'] = array(
                    'href' => pinim_get_tool_page_url(array('step'=>'pinterest-login')),
                        'name' => __( 'My Account', 'pinim' )
                );
            }
            
            //boards
            if ( $this->can_show_step('boards-settings') ){
                $tabs['boards-settings'] = array(
                    'href' => pinim_get_tool_page_url(array('step'=>'boards-settings')),
                    'name' => sprintf( __( 'Boards Settings', 'pinim' ) )
                );
            }

            
            //pins
            if ( $this->can_show_step('pins-list') ){
                $tabs['pins-list'] = array(
                    'href' => pinim_get_tool_page_url(array('step'=>'pins-list')),
                    'name' => __( 'Pins list', 'pinim' )
                );
            }
            
            //login
            if ( $this->can_show_step('pinim-options') ){
                $tabs['pinim-options'] = array(
                    'href' => pinim_get_tool_page_url(array('step'=>'pinim-options')),
                        'name' => __( 'Plugin options', 'pinim' )
                );
            }

            // Loop through tabs and build navigation
            foreach ((array)$tabs as $slug=>$tab_data ) {
                    $is_current = (bool) ( $slug == $active_tab );
                    $tab_class  = $is_current ? $active_class : $idle_class;
                    $tabs_html .= '<a href="' . esc_url( $tab_data['href'] ) . '" class="' . esc_attr( $tab_class ) . '">' . esc_html( $tab_data['name'] ) . '</a>';
            }

            echo $tabs_html;
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
        echo '<div class="tab-description"><p>'.sprintf(__('Your login, password and datas retrieved from Pinterest will be stored for %1$s minutes in a PHP session. It is not stored in the database.','pinim'),$session_cache)."</p></div>";
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
    
    function login_field_callback(){
        $option = $this->get_session_data('login');
        $has_user_datas = (null !== $this->get_session_data('user_datas'));
        $disabled = disabled($has_user_datas, true, false);
        $el_id = 'pinim_form_login_username';
        $el_txt = __('Username or Email');
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
        $option = $this->get_session_data('password');
        $has_user_datas = (null !== $this->get_session_data('user_datas'));
        $disabled = disabled($has_user_datas, true, false);
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
    
    function user_infos_block(){
        
        $user_icon = $user_text = $user_stats = null;

        if ( !$user_datas = $this->get_session_data('user_datas') ) return;

        if (isset($user_datas['image_medium_url'])){
            $user_icon = $user_datas['image_medium_url'];
        }
        
        //names
        $user_text = sprintf(__('Logged as %s','pinim'),'<strong>'.$user_datas['username'].'</strong>');
        
        $list = array();
        
        //public boards
        $list[] = sprintf(
            '<span>'.__('%1$s public boards','pinim').'</span>',
            '<strong>'.$user_datas['board_count'].'</strong>'
        );
        
        //public boards
        $list[] = sprintf(
            '<span>'.__('%1$s private boards','pinim').'</span>',
            '<strong>'.$user_datas['secret_board_count'].'</strong>'
        );
        
        //likes
        $list[] = sprintf(
            '<span>'.__('%1$s likes','pinim').'</span>',
            '<strong>'.$user_datas['like_count'].'</strong>'
        );
        
        $user_stats = implode(",",$list);
        
        $logout_link = pinim_get_tool_page_url(array('step'=>'pinterest-login','logout'=>true));
        
        printf('<div id="user-info"><span id="user-info-username"><img src="%1$s"/>%2$s</span> <small id="user-info-stats">(%3$s)</small> — <a id="user-logout-link" href="%4$s">%5$s</a></div>',$user_icon,$user_text,$user_stats,$logout_link,__('Logout','pinim'));

    }

    function get_user_boards_raw(){

        $user_boards = null;

        if (!$user_boards = $this->get_session_data('user_boards')){ //already populated
            
            //user boards
            
            $logged = $this->do_bridge_login();
            $user_boards = $this->bridge->get_user_boards();

            if (is_wp_error($user_boards))return $user_boards;
            
            //likes board

            if ( $user_datas = pinim_tool_page()->get_session_data('user_datas') ){
                $likes_board = array(
                    'name'          => __('Likes','pinim'),
                    'id'            => 'likes',
                    'pin_count'     => $user_datas['like_count'],
                    'cover_images'  => array(
                        array(
                            'url'   => $user_datas['image_medium_url']
                        )
                    ),
                    'url'           => '/'.$user_datas['username'].'/likes',
                );
                $user_boards[] = $likes_board;
            }

            $this->set_session_data('user_boards',$user_boards);

        }
        
        //followed boards
        /*
        if ( $boards_urls = pinim_get_followed_boards_urls() ){
            echo "GOT SOME !";
            print_R( $user_boards );die();
        }
         * 
         */
        

        return $user_boards;
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
            $boards = array_filter(
                $all_boards,
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
                $_POST['pinim_form_boards'],
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
                $_POST['pinim_form_pins'],
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

        if ( (!$bulk_pins_ids) && ( $all_pins = pinim_tool_page()->get_all_cached_pins_raw(true) ) ) {
            foreach((array)$all_pins as $pin){
                $bulk_pins_ids[] = $pin['id'];
            }

        }

        return $bulk_pins_ids;
    }

    function get_all_cached_pins_raw($only_queued_boards = false){

        $pins = array();

        $queues = (array)$this->get_session_data('queues');

        foreach ((array)$queues as $board_id=>$queue){

            if ( isset($queue['pins']) ){
                
                if ( $only_queued_boards ){
                    $queued_boards_ids = (array)pinim_tool_page()->get_session_data('queued_boards_ids');
                    $queued = in_array($board_id,$queued_boards_ids);
                    if ( !$queued ) continue;
                }

                $pins = array_merge($pins,$queue['pins']);
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
    
    function get_boards(){
        
        $boards_data = $this->get_user_boards_raw();
        if (is_wp_error($boards_data)) return $boards_data;

        foreach((array)$boards_data as $board_data){

            $board_id = $board_data['id'];
            $board = new Pinim_Board($board_id);
            $boards[] = $board;

        }
        
        return $boards;
        
    }

    function get_boards_autocache(){
        $output = array();
        $boards = $this->get_boards();

       foreach((array)$boards as $board){
           if ( $board->get_options('autocache') ){
               $output[] = $board;
           }

       }

       return $output;
    }
    
    function get_boards_not_cached(){
        $output = array();
        $boards = $this->get_boards();

       foreach((array)$boards as $board){
           if ( !$board->get_pins_queue() ){ //we already did try to reach Pinterest
               $output[] = $board;
           }

       }

       return $output;
    }
    
    function get_boards_cached(){
        $output = array();
        $boards = $this->get_boards();

       foreach((array)$boards as $board){
           if ( $board->get_pins_queue() ){ //we already did try to reach Pinterest
               $output[] = $board;
           }

       }

       return $output;
    }
    
    function get_boards_in_queue(){
        $output = array();
        $boards = $this->get_boards();

       foreach((array)$boards as $board){
            $queued_boards_ids = (array)pinim_tool_page()->get_session_data('queued_boards_ids');
            $queued = in_array($board->board_id,$queued_boards_ids);
            if ( !$queued ) continue;
            if ( $board->is_fully_imported() ) continue;
            
            $output[] = $board;

       }

       return $output;
    }

    function get_boards_count_incomplete(){
        $count = 0;
        $boards = $this->get_boards();

       foreach((array)$boards as $board){
            $board = new Pinim_Board($board_data['id']);
            if ($board->is_queue_complete()){
                $count++;
            }

        }

        $count -= $this->get_boards_count_complete();

        return $count;

    }

    function get_boards_count_complete(){
        $count = 0;
        $boards_data = $this->get_user_boards_raw();

       foreach((array)$boards_data as $board_data){
           $board = new Pinim_Board($board_data['id']);
           if ($board->get_pins_queue() && $board->is_fully_imported()){
               $count++;
           }

       }

       return $count;

    }

    function get_boards_count_waiting(){
        $count = 0;
        $boards_data = $this->get_user_boards_raw();

        $count = count($boards_data) - $this->get_boards_count_incomplete() - $this->get_boards_count_complete();

       return $count;

    }
    
    /**
     * Register a session so we can store the temporary.
     */
    function register_session(){
        if (!pinim_is_tool_page()) return;
        if( !session_id() ) session_start();
    }
    
    function destroy_session(){
        $this->delete_session_data();
    }

    function set_session_data($key,$data){
        $_SESSION['pinim'][$key] = $data;
        return true;
    }
    
    function delete_session_data($key = null){
        if ($key){
            if (!isset($_SESSION['pinim'][$key])) return false;
            unset($_SESSION['pinim'][$key]);
            return;
        }
        unset($_SESSION['pinim']);
    }
    
    function get_session_data($key = null){

        if (!isset($_SESSION['pinim'])) return null;
        
        $data = $_SESSION['pinim'];
        
        if ($key){
            if (!isset($data[$key])) return null;
            return $data[$key];
        }
        
        return $data;
    }

}

function pinim_tool_page() {
	return Pinim_Tool_Page::instance();
}

if (is_admin()){
    pinim_tool_page();
}