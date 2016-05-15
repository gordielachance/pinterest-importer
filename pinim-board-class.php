<?php

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Pinim_Board{
    
    var $board_id;
    var $options;
    var $data;
    var $pins;
    
    
    function __construct($board_id){
        $this->board_id = $board_id;
        
    }
    
    function get_options($key = null){
        
        $boards_options = pinim_get_boards_options();
        
        if (!$boards_options) return null;

        //keep only our board
        $current_board_id = $this->board_id;
        $matching_boards = array_filter(
            $boards_options,
            function ($e) use ($current_board_id) {
                return $e['id'] == $current_board_id;
            }
        );  
        
        $board_keys = array_values($matching_boards);
        $board_options = array_shift($board_keys);

        if (!isset($key)) return $board_options;
        if (!isset($board_options[$key])) return null;
        return $board_options[$key];

    }
    
    function get_category(){
        
        $cats_ids = null;

        if (!$cats_ids = $this->get_options('categories')){
            $root_term_id = pinim_get_root_category_id();
            
            $cat_name = $this->get_datas('name');

            $board_term = pinim_get_term_id($cat_name,'category',array('parent' => $root_term_id));

            if ( is_wp_error($board_term)) return $board_term;
            
            $cats_ids = $board_term['term_id'];
        }

        return $cats_ids;

    }
    
    function queue_board($add_to_queue=true){
        //queued board
        $queued_boards_ids = (array)pinim_tool_page()->get_session_data('queued_boards_ids');

        if ( $add_to_queue ){
            $queued_boards_ids[] = $this->board_id;
        }else{
            $queued_boards_ids = array_diff( $queued_boards_ids, array($this->board_id) );
        }
        $queued_boards_ids = array_unique($queued_boards_ids);
        pinim_tool_page()->set_session_data('queued_boards_ids',$queued_boards_ids);

    }
    
    function set_options($input){
        
        //TO FIX have defaults ?

        //sanitize
        
        $new_input = array(
            'id'    => $input['id']
        );

        //queued board
        $do_queue_board = (isset($input['queued_board']));

        $this->queue_board($do_queue_board);

        //autocache
        $new_input['autocache'] = ( isset($input['autocache']) );

        //private
        $new_input['private'] = ( isset($input['private']) );
        
        //custom category
        if ( isset($input['categories']) && ($input['categories']=='custom') && isset($input['category_custom']) && get_term_by('id', $input['category_custom'], 'category') ){ //custom cat
                $new_input['categories'] = $input['category_custom'];
        }

        
        if ( $boards_settings = pinim_get_boards_options(true) ){ //(force) reloading boards settings...
            //remove previous settings if they exists
            foreach ((array)$boards_settings as $key => $single_board_settings){

                if ($single_board_settings['id'] == $new_input['id']){
                    unset($boards_settings[$key]);
                }
            }
        }

        //append new settings
        $boards_settings[] = $new_input;
        
        //remove empty + reset keys...
        $boards_settings = array_filter($boards_settings);
        $boards_settings = array_values($boards_settings);
        
        if ($success = update_user_meta( get_current_user_id(), 'pinim_boards_settings', $boards_settings)){
            //reload all boards settings
            pinim_get_boards_options(true);
            return $new_input;
        }else{
            return new WP_Error( 'pinim', sprintf(__( 'Error while saving settings for board "%1$s"', 'pinim' ),$this->get_datas('name')));
        }

    }
    
    /*
     * Get data from Pinterest
     */
    
    function get_datas($key = null){

        $boards_datas = pinim_tool_page()->get_boards_raw();

        //keep only our board
        $current_board_id = $this->board_id;
        $matching_boards = array_filter(
            $boards_datas,
            function ($e) use ($current_board_id) {
                return $e['id'] == $current_board_id;
            }
        );  

        $board_keys = array_values($matching_boards);
        $board_datas = array_shift($board_keys);

        if (!$board_datas){
            return new WP_Error( 'get_datas_board_'.$this->board_id, sprintf(__( 'Unable to load datas for board #%1$s', 'wordpress-importer' ),$this->board_id));
        }

        if (!isset($key)) return $board_datas;
        if (!isset($board_datas[$key])) return false;
        return $board_datas[$key];
    }
    
    function get_count_cached_pins(){
        return count( $this->get_cached_pins() );
    }
    
    function get_pc_cached_pins(){
        $percent = 0;
        $count = $this->get_count_cached_pins();
        if ($total_pins  = $this->get_datas('pin_count')){
            $percent = $count / $total_pins * 100;
        }
        return $percent;
    }
    
    function get_count_imported_pins(){
        $imported = 0;
        $cached_pins = $this->get_cached_pins();
        
        foreach ((array)$cached_pins as $raw_pin){
            if (in_array($raw_pin['id'],pinim_tool_page()->existing_pin_ids)) $imported++;
        }
        return $imported;
    }
    
    function get_pc_imported_pins(){
        $percent = 0;
        $count = $this->get_count_imported_pins();
        if ($total_pins  = $this->get_datas('pin_count')){
            $percent = $count / $total_pins * 100;
        }
        return $percent;
    }
    
    function is_private_board(){

        $is_secret = false;
        $option = $this->get_options('private');

        if ( is_bool($option) ){ //saved option
            $is_secret = $option;
        }else{ //default
            $is_secret = ($this->get_datas('privacy')=='secret');
        }
        return $is_secret;
    }
    
    function is_queue_complete(){
        
        $count = $this->get_count_cached_pins();
        if ($count  < $this->get_datas('pin_count')) return false;
        return true;
    }
    
    function is_fully_imported(){
        $cached_pins = $this->get_cached_pins();
        if ( $this->get_count_imported_pins() < $this->get_count_cached_pins() ) return false;
        
        return true;
        
    }
    
    function get_pins_queue(){
        $all_queues = (array)pinim_tool_page()->get_session_data('queues');
        if (isset($all_queues[$this->board_id])){
            return $all_queues[$this->board_id];
        }
        
    }
    
    /*
     * Append new pins to the board.
     */
    
    function set_pins_queue($queue){
        $existing_pins = $new_pins = array();
        
        //no pins
        if ( !isset($queue['pins']) || empty($queue['pins'])){
            return $this->reset_pins_queue();
        }

        $all_queues = (array)pinim_tool_page()->get_session_data('queues');
        
        if(isset($all_queues[$this->board_id]['pins'])){
            $existing_pins = $all_queues[$this->board_id]['pins'];
        }
        
        //special key for likes (will be used in function 'get_cached_pins' )
        if ( ($this->board_id=='likes') && isset($queue['pins']) ){
            foreach ($queue['pins'] as $key=>$pin_raw){
                $pin_raw['is_like'] = true;
                $queue['pins'][$key] = $pin_raw;
            }
        }
        
        foreach ((array)$queue['pins'] as $pin){
            $new_pins[] = apply_filters('pin_sanitize_raw_datas',$pin);
        }

        $queue = array(
            'pins'      => array_merge($existing_pins,$new_pins),
            'bookmark'  => $queue['bookmark']
        );

        $all_queues[$this->board_id] = $queue;

        return pinim_tool_page()->set_session_data('queues',$all_queues);
    }
    
    function reset_pins_queue(){
        $all_queues = (array)pinim_tool_page()->get_session_data('queues');
        unset($all_queues[$this->board_id]);
        return pinim_tool_page()->set_session_data('queues',$all_queues);
    }
    
    function get_cached_pins(){
        $board_pins = array();
 
        if ($all_pins = pinim_tool_page()->get_all_cached_pins_raw()){
            
            foreach($all_pins as $pin){
                if ( $this->board_id=='likes' ){
                    if (!isset($pin['is_like']))  continue;
                }else{
                    if ($pin['board']['id'] != $this->board_id)  continue;
                }
                
                $board_pins[] = $pin;
            }
        }

        return $board_pins;
    }
    
    /**
     * $cache = 'auto', 'disabled', 'only'
     * @param type $cache
     * @return \WP_Error
     */
    
    function get_pins($reset = false){

        $error = null;
        
        if (!isset($this->pins)){
            $pins = array();
            $board_queue = $this->get_pins_queue();
            $bookmark = null;
            
            if ( isset($board_queue['bookmark']) && ($board_queue['bookmark']!='-end-') ){ //uncomplete queue
                $bookmark = $board_queue['bookmark'];
                $reset = false; //do not reset queue, it is not filled yet
            }

            if ( $reset || !$board_queue || $bookmark ){
                
                if ($reset){
                    $this->reset_pins_queue();
                }
                
                //try to auth
                $logged = pinim_tool_page()->do_bridge_login();

                if ( is_wp_error($logged) ){
                    return new WP_Error( 'pinim', $logged->get_error_message() ); 
                }

                $board_queue = pinim_tool_page()->bridge->get_board_pins($this,$bookmark);

                if (is_wp_error($board_queue)){
                    
                    $error = $board_queue;
                    
                    //check if we have an incomplete queue
                    $error_code = $error->get_error_code();
                    $board_queue = $error->get_error_data($error_code);
                    $this->set_pins_queue($board_queue);

                }

                $this->set_pins_queue($board_queue);
                
            }
            
            $board_queue = $this->get_pins_queue(); //reload queue

            if (isset($board_queue['pins'])){
                $this->pins = $board_queue['pins'];
            }

        }

        if ($error){
            return $error;
        }else{
            return $this->pins;
        }
        
        
    }

    function get_remote_url(){
        $url = pinim()->pinterest_url.$this->get_datas('url');
        return $url;
    }
    
}

class Pinim_Boards_Table extends WP_List_Table {
    
    var $input_data = array();
    var $board_idx = -1;

    /** ************************************************************************
     * REQUIRED. Set up a constructor that references the parent constructor. We 
     * use the parent reference to set some default configs.
     ***************************************************************************/
    function __construct($data = null){
        global $status, $page;
        
        $this->input_data = $data;

        //Set parent defaults
        parent::__construct( array(
            'singular'  => __('board','pinim'),     //singular name of the listed records
            'plural'    => __('boards','pinim'),    //plural name of the listed records
            'ajax'      => true        //does this table support ajax?
        ) );
        
    }


    /** ************************************************************************
     * Recommended. This method is called when the parent class can't find a method
     * specifically build for a given column. Generally, it's recommended to include
     * one method for each column you want to render, keeping your package class
     * neat and organized. For example, if the class needs to process a column
     * named 'title', it would first see if a method named $this->column_title() 
     * exists - if it does, that method will be used. If it doesn't, this one will
     * be used. Generally, you should try to use custom column methods as much as 
     * possible. 
     * 
     * Since we have defined a column_title() method later on, this method doesn't
     * need to concern itself with any column with a name of 'title'. Instead, it
     * needs to handle everything else.
     * 
     * For more detailed insight into how columns are handled, take a look at 
     * WP_List_Table::single_row_columns()
     * 
     * @param array $item A singular item (one full row's worth of data)
     * @param array $column_name The name/slug of the column to be processed
     * @return string Text or HTML to be placed inside the column <td>
     **************************************************************************/
    function column_default($item, $column_name){
        switch($column_name){
            case 'thumbnail':
            case 'category':
                return $item[$column_name];
            default:
                return print_r($item,true); //Show the whole array for troubleshooting purposes
        }
    }


    /** ************************************************************************
     * Recommended. This is a custom column method and is responsible for what
     * is rendered in any column with a name/slug of 'title'. Every time the class
     * needs to render a column, it first looks for a method named 
     * column_{$column_title} - if it exists, that method is run. If it doesn't
     * exist, column_default() is called instead.
     * 
     * This example also illustrates how to implement rollover actions. Actions
     * should be an associative array formatted as 'slug'=>'link html' - and you
     * will need to generate the URLs yourself. You could even ensure the links
     * 
     * 
     * @see WP_List_Table::::single_row_columns()
     * @param array $item A singular item (one full row's worth of data)
     * @return string Text to be placed inside the column <td> (movie title only)
     **************************************************************************/

    function column_title($board){

        //Build row actions
        $actions = array(
            'view'                        => sprintf('<a href="%1$s" target="_blank">%2$s</a>',$board->get_remote_url(),__('View on Pinterest','pinim'),'view'),
        );

        if ($board->board_id == 'likes'){
            $title = '<i class="fa fa-heart"></i> '.$board->get_datas('name');
        }else{
            $title = sprintf('%1$s <span class="item-id">(id:%2$s)</span>',$board->get_datas('name'),$board->board_id);
        }
        
        return $title.$this->row_actions($actions);

        
    }

    /** ************************************************************************
     * REQUIRED if displaying checkboxes or using bulk actions! The 'cb' column
     * is given special treatment when columns are processed. It ALWAYS needs to
     * have it's own method.
     * 
     * @see WP_List_Table::::single_row_columns()
     * @param array $item A singular item (one full row's worth of data)
     * @return string Text to be placed inside the column <td> (movie title only)
     **************************************************************************/
    function column_cb($board){
        
        $this->board_idx++;
        
        $hidden = sprintf('<input type="hidden" name="pinim_form_boards[%1$s][id]" value="%2$s" />',
            $this->board_idx,
            $board->board_id
        );

        $input = sprintf(
            '<input type="checkbox" class="bulk" name="pinim_form_boards[%1$s][bulk]" value="on"/>',
            $this->board_idx
        );
        return $hidden.$input;
    }
    
    function column_autocache($board){

        $option = $board->get_options('autocache');

        return sprintf(
            '<input type="checkbox" name="pinim_form_boards[%1$s][autocache]" value="on" %2$s/>',
            $this->board_idx,
            checked($option, true, false )
        );
        
    }
    
    function column_queued_board($board){
        $queued_boards_ids = (array)pinim_tool_page()->get_session_data('queued_boards_ids');
        $option = in_array($board->board_id,$queued_boards_ids);
        $can_queue = (bool)!$board->get_pins_queue(); //we already did try to reach Pinterest

        return sprintf(
            '<input type="checkbox" name="pinim_form_boards[%1$s][queued_board]" value="on" %2$s %3$s />',
            $this->board_idx,
            checked($option, true, false ),
            disabled( $can_queue, true, false )
        );
        
    }
    
    function column_private($board){

        //privacy
        $is_private = $board->is_private_board();

        $secret_checked_str = checked($is_private, true, false );
        
        return sprintf(
            '<input type="checkbox" name="pinim_form_boards[%1$s][private]" value="on" %2$s/>',
            $this->board_idx,
            $secret_checked_str
        );
        
    }
    
    function column_thumbnail($board){
        if ( !$images = $board->get_datas('cover_images') ) return;
        $image_key = array_values($images);
        $image = array_shift($image_key);
        return sprintf(
            '<img src="%1$s" />',
            $image['url']
        );
    }
    
    function column_details(){
        return sprintf('<button>%1$s</button>',__('Details','pinim'));
    }
    
    function column_category($board){
        $board_term = null;
        $root_cat = pinim_get_root_category_id();
        
        $category = $board->get_options('categories');

        if ( !$selected_cat = $board->get_options('categories') ){
            $is_auto_cat = true;
            $selected_cat = $root_cat;
            $cat_name = $board->get_datas('name');
            if ( $board_term = term_exists($cat_name,'category',$root_cat) ){
                $selected_cat = $board_term['term_id'];
            }
        }
        
        $is_auto_cat = (($selected_cat == $root_cat) || ($board_term));


        $checked_auto_str = checked($is_auto_cat, true, false );
        
        $category_auto = sprintf(
            '<input type="radio" name="pinim_form_boards[%1$s][categories]" value="auto" %2$s/>%3$s',
            $this->board_idx,
            $checked_auto_str,
            __('auto','pinim')
        );
        
        $cat_args = array(
            'hide_empty'    => false,
            'depth'         => 20, //TO FIX better value here ?
            'hierarchical'  => 1,
            'echo'          => false,
            'selected'      => $selected_cat,
            'name'          => sprintf('pinim_form_boards[%1$s][category_custom]',$this->board_idx)
        );

        $checked_custom_str = checked($is_auto_cat, false, false );
        $custom_cats = wp_dropdown_categories( $cat_args );
        
        $category_custom = sprintf(
            '<input type="radio" name="pinim_form_boards[%1$s][categories]" value="custom" %2$s/>%3$s',
            $this->board_idx,
            $checked_custom_str,
            __('custom','pinim')
        );
        
        return "<span>".$category_auto."</span><span>".$category_custom."</span><br/><span>".$custom_cats."</span>";
        
    }
    
    function column_pin_count_remote($board){
        return $board->get_datas('pin_count');
    }
    
    function column_pin_count_imported($board){
        
            $percent = 0;
            
            $pc_status_classes = array('pinim-pc-bar');
            $text_bar = $bar_width = null;
            
            if ( $board->get_cached_pins() ){
                $percent = $board->get_pc_imported_pins();
                $percent = floor($percent);
            }
            
            $bar_width = $percent;
            
            $imported = $board->get_count_imported_pins();
            $text_bar = $imported.'/'.$board->get_datas('pin_count');
            $text_bar .= '<i class="fa fa-refresh" aria-hidden="true"></i>';

            switch($percent){
                case 0:
                    $pc_status_classes[] = 'empty';
                break;
                case 100:
                    $pc_status_classes[] = 'complete';
                    $text_bar = '<i class="fa fa-check-circle" aria-hidden="true"></i>';
                break;
                default:
                    $pc_status_classes[] = 'incomplete';
                break;
            }
            
            if ( !$board->get_cached_pins() ){
                $pc_status_classes[] = "offline";
                $link = pinim_get_tool_page_url(array('step'=>'boards-settings','boards_filter'=>'cached','action'=>'boards_cache_pins','board_ids'=>$board->board_id));
                $text_bar = sprintf('<a href="%1$s">%2$s</a>',$link,__('Not cached yet','pinim'));
            }else{
   
            if ($percent<50){
                    $pc_status_classes[] = 'color-light';
                }
            }

            $pc_status_classes = pinim_get_classes($pc_status_classes);
            $red_opacity = (100 - $percent) / 100;

            return sprintf('<span %1$s><span class="pinim-pc-bar-fill" style="width:%2$s"><span class="pinim-pc-bar-fill-color pinim-pc-bar-fill-yellow"></span><span class="pinim-pc-bar-fill-color pinim-pc-bar-fill-red" style="opacity:%3$s"></span><span class="pinim-pc-bar-text">%4$s</span></span>',$pc_status_classes,$bar_width.'%',$red_opacity,$text_bar);

    }


    /** ************************************************************************
     * REQUIRED! This method dictates the table's columns and titles. This should
     * return an array where the key is the column slug (and class) and the value 
     * is the column's title text. If you need a checkbox for bulk actions, refer
     * to the $columns array below.
     * 
     * The 'cb' column is treated differently than the rest. If including a checkbox
     * column in your table you must create a column_cb() method. If you don't need
     * bulk actions or checkboxes, simply leave the 'cb' entry out of your array.
     * 
     * @see WP_List_Table::::single_row_columns()
     * @return array An associative array containing column information: 'slugs'=>'Visible Titles'
     **************************************************************************/

    function get_columns(){

        $columns = array(
            'cb'                    => '<input type="checkbox" />', //Render a checkbox instead of text
            'thumbnail'             => '',
            'title'                 => __('Board Title','pinim'),
            'details'               => __('Details','pinim'),
            'category'              => __('Category','pinim'),
            'private'               => __('Private','pinim'),
            'pin_count_remote'      => __('Board Pins','pinim'),
            'pin_count_imported'    => __('Status','pinim'),
        );
        
        if ( pinim()->get_options('autocache') ){
            $columns['autocache'] = __('Auto-cache','pinim');
        }
        
        if ( pinim_tool_page()->get_screen_boards_filter() != 'not_cached' ){
            $columns['queued_board'] = __('Queue pins','pinim');
        }

        return $columns;
    }


    /** ************************************************************************
     * Optional. If you want one or more columns to be sortable (ASC/DESC toggle), 
     * you will need to register it here. This should return an array where the 
     * key is the column that needs to be sortable, and the value is db column to 
     * sort by. Often, the key and value will be the same, but this is not always
     * the case (as the value is a column name from the database, not the list table).
     * 
     * This method merely defines which columns should be sortable and makes them
     * clickable - it does not handle the actual sorting. You still need to detect
     * the ORDERBY and ORDER querystring variables within prepare_items() and sort
     * your data accordingly (usually by modifying your query).
     * 
     * @return array An associative array containing all the columns that should be sortable: 'slugs'=>array('data_values',bool)
     **************************************************************************/
    function get_sortable_columns() {
        $sortable_columns = array(
            'title'                 => array('title',false),     //true means it's already sorted
            'pin_count_remote'      => array('pin_count_remote',false),
            
        );
        
        //do not allow to sort 'pin_count_imported' as it should force to populate the pins from all boards to work

        return $sortable_columns;
    }
    
    /**
     * @param string $which
     */
    protected function extra_tablenav( $which ) {
        ?>
        <div class="alignleft actions">
        <?php
        if ( 'top' == $which && !is_singular() ) {

            /*
            switch (pinim_tool_page()->get_screen_boards_filter()){
                case 'pending':
                    //Import All Pins
                    submit_button( pinim_tool_page()->all_action_str['import_all_pins'], 'button', 'all_boards_action', false );
                break;
            }
             * 
             */
        }

        ?>
        </div>
        <?php
    }
    
    /**
     * Get an associative array ( id => link ) with the list
     * of views available on this table.
     *
     * @since 3.1.0
     * @access protected
     *
     * @return array
     */

    protected function get_views() {

        $link_all = $link_cached = $link_not_cached = null;
        $link_all_classes = $link_cached_classes = $link_not_cached_classes = $link_in_queue_classes = array();
        $all_count = count(pinim_tool_page()->get_boards());
        $cached_count = count(pinim_tool_page()->get_boards_cached());
        $not_cached_count = count(pinim_tool_page()->get_boards_not_cached());
        $in_queue_count = count(pinim_tool_page()->get_boards_in_queue());

        switch (pinim_tool_page()->get_screen_boards_filter()){
            case 'all':
                $link_all_classes[] = 'current';
            break;
            case 'cached':
                $link_cached_classes[] = 'current';
            break;
            case 'not_cached':
                $link_not_cached_classes[] = 'current';
            break;
            case 'in_queue':
                $link_in_queue_classes[] = 'current';
            break;
        }
        
        $link_all = sprintf(
            __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
            pinim_get_tool_page_url(array('step'=>'boards-settings','boards_filter'=>'all')),
            pinim_get_classes($link_all_classes),
            __('All','pinim'),
            $all_count
        );

        $link_cached = sprintf(
            __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
            pinim_get_tool_page_url(array('step'=>'boards-settings','boards_filter'=>'cached')),
            pinim_get_classes($link_cached_classes),
            __('Cached','pinim'),
            $cached_count
        );

        $link_not_cached = sprintf(
            __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
            pinim_get_tool_page_url(array('step'=>'boards-settings','boards_filter'=>'not_cached')),
            pinim_get_classes($link_not_cached_classes),
            __('Not cached','pinim'),
            $not_cached_count
        );
        
        $link_in_queue = sprintf(
            __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
            pinim_get_tool_page_url(array('step'=>'boards-settings','boards_filter'=>'in_queue')),
            pinim_get_classes($link_in_queue_classes),
            __('In queue','pinim'),
            $in_queue_count
        );

        $links = array(
            'all'           => $link_all,
            'cached'        => $link_cached,
            'not_cached'    => $link_not_cached,
            'in_queue'      => $link_in_queue,
        );

        return $links;
    }
    
    /**
     * Get an associative array ( id => link ) with the list
     * of views available on this table.
     *
     * @since 3.1.0
     * @access protected
     *
     * @return array
     */

    protected function get_views_display() {

        $link_simple_classes = $link_advanced_classes = array();

        switch (pinim_tool_page()->get_screen_boards_view_filter()){
            case 'simple':
                $link_simple_classes[] = 'current';
            break;
            case 'advanced':
                $link_advanced_classes[] = 'current';
            break;
        }
        
        $boards_filter = pinim_tool_page()->get_screen_boards_filter();

        $link_simple = sprintf(
            __('<a href="%1$s"%2$s>%3$s</a>'),
            pinim_get_tool_page_url(array('step'=>'boards-settings','boards_view_filter'=>'simple')),
            pinim_get_classes($link_simple_classes),
            __('Simple','pinim')
        );

        $link_advanced = sprintf(
            __('<a href="%1$s"%2$s>%3$s</a>'),
            pinim_get_tool_page_url(array('step'=>'boards-settings','boards_view_filter'=>'advanced')),
            pinim_get_classes($link_advanced_classes),
            __('Advanced','pinim')
        );

        return array(
            'simple'       => $link_simple,
            'advanced'        => $link_advanced,
        );
    }

    /**
     * Display the list of views available on this table.
     *
     * @since 3.1.0
     * @access public
     */
    
    public function views_display() {
            $views = $this->get_views_display();

            if ( empty( $views ) )
                    return;

            echo '<ul id="boards_view_filter" class="view_filter subsubsub">'."\n";
            foreach ( $views as $class => $view ) {
                    $views[ $class ] = "\t<li class='$class'>$view";
            }
            echo implode( " |</li>\n", $views ) . "</li>\n";
            echo "</ul>";
    }
    
    public function views() {
            $views = $this->get_views();

            if ( empty( $views ) )
                    return;

            echo '<ul class="subsubsub">'."\n";
            foreach ( $views as $class => $view ) {
                    $views[ $class ] = "\t<li class='$class'>$view";
            }
            echo implode( " |</li>\n", $views ) . "</li>\n";
            echo "</ul>";
    }


    /** ************************************************************************
     * Optional. If you need to include bulk actions in your list table, this is
     * the place to define them. Bulk actions are an associative array in the format
     * 'slug'=>'Visible Title'
     * 
     * If this method returns an empty value, no bulk action will be rendered. If
     * you specify any bulk actions, the bulk actions box will be rendered with
     * the table automatically on display().
     * 
     * Also note that list tables are not automatically wrapped in <form> elements,
     * so you will need to create those manually in order for bulk actions to function.
     * 
     * @return array An associative array containing all the bulk actions: 'slugs'=>'Visible Titles'
     **************************************************************************/
    function get_bulk_actions() {
        
        //TO FIX can be cleared out
        
        $actions = array(
            'boards_cache_pins'         => __('Cache Boards','pinim'),
            'boards_save_settings'      => __('Save Settings','pinim')
        );
        return $actions;
    }


    /** ************************************************************************
     * Optional. You can handle your bulk actions anywhere or anyhow you prefer.
     * For this example package, we will handle it in the class to keep things
     * clean and organized.
     * 
     * @see $this->prepare_items()
     **************************************************************************/
    function process_bulk_action() {

    }


    /** ************************************************************************
     * REQUIRED! This is where you prepare your data for display. This method will
     * usually be used to query the database, sort and filter the data, and generally
     * get it ready to be displayed. At a minimum, we should set $this->items and
     * $this->set_pagination_args(), although the following properties and methods
     * are frequently interacted with here...
     * 
     * @global WPDB $wpdb
     * @uses $this->_column_headers
     * @uses $this->items
     * @uses $this->get_columns()
     * @uses $this->get_sortable_columns()
     * @uses $this->get_pagenum()
     * @uses $this->set_pagination_args()
     **************************************************************************/
    function prepare_items() {
        global $wpdb; //This is used only if making any database queries

        /**
         * First, lets decide how many records per page to show
         */
        $per_page = pinim()->get_options('boards_per_page');
        
        
        /**
         * REQUIRED. Now we need to define our column headers. This includes a complete
         * array of columns to be displayed (slugs & titles), a list of columns
         * to keep hidden, and a list of columns that are sortable. Each of these
         * can be defined in another method (as we've done here) before being
         * used to build the value for our _column_headers property.
         */
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        
        /**
         * REQUIRED. Finally, we build an array to be used by the class for column 
         * headers. The $this->_column_headers property takes an array which contains
         * 3 other arrays. One for all columns, one for hidden columns, and one
         * for sortable columns.
         */
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        
        /**
         * Optional. You can handle your bulk actions however you see fit. In this
         * case, we'll handle them within our package just to keep things clean.
         */
        $this->process_bulk_action();
        
        
        /**
         * Instead of querying a database, we're going to fetch the example data
         * property we created for use in this plugin. This makes this example 
         * package slightly different than one you might build on your own. In 
         * this example, we'll be using array manipulation to sort and paginate 
         * our data. In a real-world implementation, you will probably want to 
         * use sort and pagination data to build a custom query instead, as you'll
         * be able to use your precisely-queried data immediately.
         */
        $data = $this->input_data;   
        
        /**
         * This checks for sorting input and sorts the data in our array accordingly.
         * 
         * In a real-world situation involving a database, you would probably want 
         * to handle sorting by passing the 'orderby' and 'order' values directly 
         * to a custom query. The returned data will be pre-sorted, and this array
         * sorting technique would be unnecessary.
         */
        function usort_reorder($a,$b){

            $orderby_default = 'title';
            $order_default = 'desc';

            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : $orderby_default;
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : $order_default;
            
            switch ($orderby){
                case 'title':
                    $result = strcmp($a->get_datas('name'), $b->get_datas('name'));
                break;
                case 'pin_count_remote':
                    $result = $a->get_datas('pin_count') - $b->get_datas('pin_count');
                break;
                
            }

            return ($order==='asc') ? $result : -$result; //Send final sort direction to usort
        }
        usort($data, 'usort_reorder');
        
        
        /***********************************************************************
         * ---------------------------------------------------------------------
         * vvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvv
         * 
         * In a real-world situation, this is where you would place your query.
         *
         * For information on making queries in WordPress, see this Codex entry:
         * http://codex.wordpress.org/Class_Reference/wpdb
         * 
         * ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
         * ---------------------------------------------------------------------
         **********************************************************************/
        
                
        /**
         * REQUIRED for pagination. Let's figure out what page the user is currently 
         * looking at. We'll need this later, so you should always include it in 
         * your own package classes.
         */
        $current_page = $this->get_pagenum();
        
        /**
         * REQUIRED for pagination. Let's check how many items are in our data array. 
         * In real-world use, this would be the total number of items in your database, 
         * without filtering. We'll need this later, so you should always include it 
         * in your own package classes.
         */
        $total_items = count($data);
        
        
        /**
         * The WP_List_Table class does not handle pagination for us, so we need
         * to ensure that the data is trimmed to only the current page. We can use
         * array_slice() to 
         */
        if ($per_page){
            $data = array_slice($data,(($current_page-1)*$per_page),$per_page);
        }

        /**
         * REQUIRED. Now we can add our *sorted* data to the items property, where 
         * it can be used by the rest of the class.
         */
        $this->items = $data;
        
        
        /**
         * REQUIRED. We also have to register our pagination options & calculations.
         */

        $this->set_pagination_args( array(
            'total_items' => $total_items,                                      //WE have to calculate the total number of items
            'per_page'    => $per_page ? $per_page : $total_items,              //WE have to determine how many items to show on a page
            'total_pages' => $per_page ? ceil($total_items/$per_page)   : 1     //WE have to calculate the total number of pages
        ) );

    }


}