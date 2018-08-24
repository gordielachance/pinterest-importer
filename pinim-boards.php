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
    
    function page_boards_init(){
        
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_boards') return;
        
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

            if (!$action) return;

            switch ($action) {

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
        if ( !pinim()->get_session_data() ) { //session exists
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
            $autocache_boards = array();
            $queued_boards = array();
            
            if ( pinim()->get_options('can_autocache') == 'on' ) {
                $autocache_boards = $this->filter_boards($all_boards,'autocache');
            }
            
            $queued_boards = $this->filter_boards($all_boards,'in_queue');

            $load_pins_boards = array_merge($autocache_boards,$queued_boards);
            
            if ($load_pins_boards){
                $this->cache_boards_pins($load_pins_boards);
            }

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
            if ( $pending_count = pinim_pending_imports()->get_pins_count_pending() ){

                $feedback =  array( __("We're ready to process !","pinim") );
                $feedback[] = sprintf( _n( '%s new pin was found in the queued boards.', '%s new pins were found in the queued boards.', $pending_count, 'pinim' ), $pending_count );
                $feedback[] = sprintf( __('You can <a href="%1$s">import them all</a>, or go to the <a href="%2$s">Pins list</a> for advanced control.',"pinim"),
                            pinim_get_menu_url(array('page'=>'pending-importation','all_pins_action'=>pinim_pending_imports()->all_action_str['import_all_pins'])),
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
        
        if ( !$raw_boards = pinim()->get_session_data('raw_user_boards') ){
            
            //auth to pinterest
            pinim_account()->do_pinterest_auth();

            if ( $logged = pinim()->bot->auth->isLoggedIn() ){

                $raw_boards = pinim()->bot->boards->forMe();
                pinim()->set_session_data('raw_user_boards',$raw_boards);

            }
            
        }
        
        //keep only boards (not stories or ads or...)
        $raw_boards = array_filter((array)$raw_boards, function($board){
            return ($board['type'] == 'board');
        });
        
        foreach((array)$raw_boards as $raw_board){
            $boards[] = new Pinim_Board_Item($raw_board['url'],$raw_board);
        }

        return $boards;
        
    }
    
    function get_boards_followed(){
        
        $boards = $raw_boards = array();
        
        //get raw boards from cache or request them
        
        if ( !$raw_boards = pinim()->get_session_data('raw_followed_boards') ){
            
            //auth to pinterest
            pinim_account()->do_pinterest_auth();

            if ( $logged = pinim()->bot->auth->isLoggedIn() ){

                $user_data = pinim_account()->get_user_profile();
                $raw_boards = pinim()->bot->pinners->followingBoards($user_data['username'])->toArray();
                pinim()->set_session_data('raw_followed_boards',$raw_boards);

            }
            
        }

        //keep only boards (not stories or ads or...)
        $raw_boards = array_filter((array)$raw_boards, function($board){
            return ($board['type'] == 'board');
        });

        foreach((array)$raw_boards as $raw_board){
            $boards[] = new Pinim_Board_Item($raw_board['url'],$raw_board);
        }

        return $boards;

    }

    function get_boards(){

        $boards = $this->get_boards_user();
        if ( is_wp_error($boards) ) return $boards;
        
        if ( pinim()->get_options('enable_followed') ){
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

    function filter_boards($boards,$filter){

        $output = array();
        $username = null;
        if( is_wp_error($boards) ) return $output;
        
        $user_data = pinim_account()->get_user_profile();
        if ( !is_wp_error($user_data) ){
            $username = $user_data['username'];
        }

        switch ($filter){
            case 'autocache':
                foreach((array)$boards as $board){
                    if ( $board->get_options('autocache') ){
                        $output[] = $board;
                    }
                }
                
            break;
            
            case 'cached':
                
                foreach((array)$boards as $board){
                    if ( !$board->is_pins_cache_built() ) continue;
                    $output[] = $board;

                }
                
            break;
            
            case 'not_cached':
                
                foreach((array)$boards as $board){
                    if ( $board->is_pins_cache_built() ) continue;
                    $output[] = $board;

                }
                
            break;
            
            case 'in_queue':
                
                foreach((array)$boards as $board){
                
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
    
    function get_boards_layout(){
        
        $default = pinim()->get_options('boards_layout');
        $stored = pinim()->get_session_data('boards_layout');
        $filter = $stored ? $stored : $default;

        $requested = ( isset($_REQUEST['boards_layout']) ) ? $_REQUEST['boards_layout'] : null;
        $allowed = array('simple','advanced');

        if ( $requested && in_array($requested,$allowed) ) {
            $filter = $requested;
            pinim()->set_session_data('boards_layout',$filter);
            
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
    
    function cache_boards_pins($boards){

        if (!is_array($boards)){
            $boards = array($boards); //support single items
        }

        foreach((array)$boards as $board){
            
            pinim()->debug_log("cache_boards_pins for board: %s",$board->board_id);
            
            if (!$board->is_pins_cache_built()){
                $board->in_queue = true;
            }

            $board_pins = $board->get_pins();

            if (is_wp_error($board_pins)){    
                add_settings_error('feedback_boards', 'cache_single_board_pins', $board_pins->get_error_message(),'inline');
            }

        }
   }

}

function pinim_boards() {
	return Pinim_Boards::instance();
}

pinim_boards();
