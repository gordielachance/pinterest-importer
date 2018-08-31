<?php

class Pinim_Boards {
    
    /**
    * @var The one true Instance
    */
    private static $instance;

    public static function instance() {
            if ( ! isset( self::$instance ) ) {
                    self::$instance = new Pinim_Boards;
                    self::$instance->init();
            }
            return self::$instance;
    }
    
    private function __construct() { /* Do nothing here */ }
    
    function init(){
        add_action( 'admin_menu',array( $this,'admin_menu' ),20,2);
        add_action( 'current_screen', array( $this, 'process_bulk_board_action'), 9 );
        add_action( 'current_screen', array( $this, 'process_board_action'), 9 );
        add_action( 'current_screen', array( $this, 'page_boards_init') );
    }
    
    function admin_menu(){
        pinim()->page_boards = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Pinterest Boards','pinim'), 
            __('Pinterest Boards','pinim'), 
            pinim_get_pin_capability(), //capability required
            'boards', 
            array($this, 'page_boards')
        );
    }
    
    function process_bulk_board_action(){
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_boards') return;
        
        //process boards listing form
        $form_boards = isset($_POST['pinim_form_boards']) ? $_POST['pinim_form_boards'] : null;

        //bulk action
        $action = ( isset($_REQUEST['action']) && ($_REQUEST['action']!=-1)  ? $_REQUEST['action'] : null);
        if (!$action){
            $action = ( isset($_REQUEST['action2']) && ($_REQUEST['action2']!=-1)  ? $_REQUEST['action2'] : null);
        }
        
        if (!$action || !$form_boards) return;

        //get boards
        $bulk_boards_ids = array();
        $all_boards = $this->get_boards();
        
        $form_boards = array_filter( //keep only boards that are checked
            (array)$_POST['pinim_form_boards'],
            function ($e) {
                return isset($e['bulk']);
            }
        );
        
        foreach((array)$form_boards as $board){
            $bulk_boards_ids[] = $board['id'];
        }
        
        //
        pinim()->debug_log(json_encode(array('action'=>$action,'board_ids'=>$bulk_boards_ids)),'process_bulk_board_action');
        //

        //get boards from their IDs
        $boards = array_filter(
            (array)$all_boards,
            function ($e) use ($bulk_boards_ids) {
                return ( in_array($e->board_id,$bulk_boards_ids) );
            }
        ); 

        switch ($action) {

            case 'bulk_build_board_cache':

                foreach((array)$boards as $board){

                    //
                    $success = $board->get_pins();
                    if (is_wp_error($success)){    
                        add_settings_error('feedback_boards', 'bulk_build_board_cache', $success->get_error_message(),'inline');
                    }

                }
            break;

            case 'bulk_clear_board_cache':

                    foreach((array)$boards as $board){

                        $success = $board->delete_pins_cache();

                        //
                        if (is_wp_error($success)){    
                            add_settings_error('feedback_boards', 'bulk_clear_board_cache', $success->get_error_message(),'inline');
                        }


                    }
            break;

            case 'bulk_save_board_settings':
                //fetch form data
                $form_data = $_POST['pinim_form_boards'];

                foreach ((array)$bulk_boards as $board){

                    //extract form data for this board
                    $board_id = $board->board_id;
                    $form_data = array_filter(
                        (array)$form_data,
                        function ($e) use ($board_id) {
                            return ( $e['id'] == $board_id );
                        }
                    );
                    $input = array_shift($form_data);

                    //
                    $success = $board->save_form($input);
                    if (is_wp_error($success)){
                        add_settings_error('feedback_boards', 'bulk_save_board_settings', $success->get_error_message(),'inline');
                    }
                }
            break;

        }
    }
    
    function process_board_action(){
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_boards') return;
        
        //process URL board action

        $action = isset($_GET['action']) ? $_GET['action'] : null;
        $board_id = isset($_GET['board_id']) ? $_GET['board_id'] : null;
        
        if ( !$action || !$board_id ) return;

        //
        pinim()->debug_log(json_encode(array('action'=>$action,'board_id'=>$board_id)),'process_board_action');
        //

        $board = $this->get_board($board_id);
        if ( is_wp_error($board) ) return $board;

        switch ($action) {

            case 'build_board_cache':

                //
                $success = $board->get_pins();
                if (is_wp_error($success)){    
                    add_settings_error('feedback_boards', 'build_board_cache', $success->get_error_message(),'inline');
                }

            break;

            case 'clear_board_cache':
                $success = $board->delete_pins_cache();

                //
                if (is_wp_error($success)){    
                    add_settings_error('feedback_boards', 'clear_board_cache', $success->get_error_message(),'inline');
                }
            break;
                
            case 'export_board_cache':
                $success = $board->cache_to_xml();

                //
                if (is_wp_error($success)){    
                    add_settings_error('feedback_boards', 'export_board_cache', $success->get_error_message(),'inline');
                }
            break;
                
            case 'import_board_pins':
                die("IMPORT BOARD PINS");
            break;
                
        }
    }
    
    function page_boards_init(){
        
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_boards') return;

        /*
        INIT BOARDS
        */
        
        $all_boards = array();
        //check that we are logged
        if ( !pinim()->get_cached_data() ) { //session exists
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
            
            if ($all_boards){
                $boards_cached = $this->filter_boards($all_boards,'cached');

                //no boards cached message
                if ( !$boards_cached ){
                    $feedback = __("Build the cache of some boards so we can load their pins.",'pinim');
                    add_settings_error('feedback_boards','no_boards_cached',$feedback,'updated inline');
                }
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
                case 'followed':
                    $all_boards = $this->filter_boards($all_boards,'followed');
                break;
            }

            $this->table_boards_user->input_data = $all_boards;
            $this->table_boards_user->prepare_items();

            //display feedback with import links
            if ( $pending_pins = pinim_pending_imports()->get_all_raw_pins() ){
                
                //remove pins that already exists in the DB
                $existing_pin_ids = pinim()->get_processed_pin_ids();
                foreach((array)$pending_pins as $key=>$pin){
                    if ( in_array( $pin['id'],$existing_pin_ids ) ){
                        unset($pending_pins[$key]);
                        continue;
                    }
                }

                $pending_count = count($pending_pins);
                $feedback =  array( __("We're ready to process !","pinim") );
                $feedback[] = sprintf( _n( '%s new pin was found in the boards cache.', '%s new pins were found in the boards cache.', $pending_count, 'pinim' ), $pending_count );
                $feedback[] = sprintf( __('You can <a href="%1$s">import them all</a>, or go to the <a href="%2$s">Pins list</a> for advanced control.',"pinim"),
                            pinim_get_menu_url(array('page'=>'pending-importation','action'=>'import_all_pins')),
                            pinim_get_menu_url(array('page'=>'pending-importation'))
                );

                add_settings_error('feedback_boards','ready_to_import',implode('  ',$feedback),'updated inline');

            }

        }
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
        
            $form_classes[] = 'view-filter-'.$this->get_boards_layout();
            $form_classes[] = 'pinim-form-boards';

            settings_errors('feedback_boards');

            ?>  
            <form id="pinim-form-user-boards"<?php pinim_classes_attr($form_classes);?> action="<?php echo pinim_get_menu_url(array('page'=>'boards'));?>" method="post">
                <p class="description">
                    <?php _e("This is the list of all the boards we've fetched from your profile.","pinim");?>
                </p>
                <?php
                $this->table_boards_user->views_display();
                $this->table_boards_user->views();
                $this->table_boards_user->display();                            
                ?>
            </form>
        </div>
        <?php
    }

    function get_boards_user(){
        $boards = $raw_boards = array();
        
        //get raw boards from cache or request them
        if ( !$raw_boards = get_user_meta( get_current_user_id(),pinim()->usermeta_boards,true ) ){

            //auth to pinterest
            pinim_account()->do_pinterest_auth();

            if ( $logged = pinim()->bot->auth->isLoggedIn() ){

                $raw_boards = pinim()->bot->boards->forMe();
                
                $success = update_user_meta( get_current_user_id(),pinim()->usermeta_boards,$raw_boards );

            }
            
        }
        
        if ($raw_boards){
            //keep only boards (not stories or ads or...)
            $raw_boards = array_filter((array)$raw_boards, function($board){
                return ($board['type'] == 'board');
            });

            foreach((array)$raw_boards as $raw_board){
                $new_board = new Pinim_Board_Item();
                $new_board->populate_datas($raw_board);
                //TOUFIX check for board ID ?
                $boards[] = $new_board;
            }
        }

        return $boards;
        
    }
    
    function get_boards_followed(){
        
        $boards = $raw_boards = array();
        
        //get raw boards from cache or request them
        
        if ( !$raw_boards = get_user_meta( get_current_user_id(),pinim()->usermeta_followed_boards,true ) ){
            
            //auth to pinterest
            pinim_account()->do_pinterest_auth();

            if ( $logged = pinim()->bot->auth->isLoggedIn() ){

                $user_data = pinim_account()->get_user_profile();
                $raw_boards = pinim()->bot->pinners->followingBoards($user_data['username'])->toArray();
                $success = update_user_meta( get_current_user_id(),pinim()->usermeta_followed_boards,$raw_boards );

            }
            
        }
        
        if ($raw_boards){

            //keep only boards (not stories or ads or...)
            $raw_boards = array_filter((array)$raw_boards, function($board){
                return ($board['type'] == 'board');
            });

            foreach((array)$raw_boards as $raw_board){
                $new_board = new Pinim_Board_Item();
                $new_board->populate_datas($raw_board);
                //TOUFIX check for board ID ?
                $boards[] = $new_board;
            }
            
        }

        return $boards;

    }

    function get_boards(){

        $boards = $this->get_boards_user();
        if ( is_wp_error($boards) ) return $boards;

        if ( (pinim()->get_options('enable_followed')=='on') ){
            $followed_boards = $this->get_boards_followed();
            $boards = array_merge($boards,$followed_boards);
        }

        //remove boards with errors
        foreach ((array)$boards as $key=>$board){
            if ( is_wp_error($board) ) unset($boards[$key]);
        }

        //TO FIX check if we should not save some stuff in the session, at this step
        return $boards;
    }

    function get_board($board_id){
        $all_boards = $this->get_boards();
        
        //get this board only
        $filtered = array_filter(
            (array)$all_boards,
            function ($e) use ($board_id) {
                return ( $e->board_id == $board_id );
            }
        );
        
        $board = array_shift($filtered);
        return $board;
        
    }


    function filter_boards($boards,$filter){

        $output = array();
        $username = null;
        if( is_wp_error($boards) ) return $output;
        
        $user_data = pinim_account()->get_user_profile();
        if ( !is_wp_error($user_data) ){
            $username = $user_data['username'];
        }

        switch ($filter){

            case 'cached':
                
                foreach((array)$boards as $board){
                    if ( empty($board->raw_pins) ) continue;
                    $output[] = $board;

                }
                
            break;
            
            case 'not_cached':
                
                foreach((array)$boards as $board){
                    if ( !empty($board->raw_pins) ) continue;
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
    
    function get_boards_layout(){
        
        $default = pinim()->get_options('boards_layout');
        
        $stored = get_user_meta( get_current_user_id(),pinim()->usermeta_layout_filter,true );
        $filter = $stored ? $stored : $default;

        $requested = ( isset($_REQUEST['boards_layout']) ) ? $_REQUEST['boards_layout'] : null;
        $allowed = array('simple','advanced');

        if ( $requested && in_array($requested,$allowed) ) {
            $filter = $requested;
            update_user_meta( get_current_user_id(), pinim()->usermeta_layout_filter, $filter );
            
        }
        
        return $filter;
        
    }
    
    function get_screen_boards_filter(){
        $default = pinim()->get_options('boards_filter');
        
        $stored = get_user_meta( get_current_user_id(),pinim()->usermeta_boards_filter,true );
        $filter = $stored ? $stored : $default;

        if ( isset($_REQUEST['boards_filter']) ) {
            $filter = $_REQUEST['boards_filter'];
            update_user_meta( get_current_user_id(), pinim()->usermeta_boards_filter, $filter );
        }
        
        return $filter;
    }

}

function pinim_boards() {
	return Pinim_Boards::instance();
}

pinim_boards();
