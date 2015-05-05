<?php

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Pinim_Pin{
    
    var $pin_id;
    
    var $options;
    var $datas_raw;
    var $datas;
    var $board_id;
    var $board;
    var $post_id; //wp post
    var $post;
    
    
    function __construct($pin_id){

        $this->pin_id = $pin_id;
        $this->pin_datas_raw = $this->get_raw_datas();
        $this->board_id = $this->pin_datas_raw['board']['id'];
        $this->datas = $this->sanitize_raw_datas($this->pin_datas_raw);
        
    }
    
    function get_raw_datas(){

        $all_pins = pinim_tool_page()->get_all_cached_pins();

        //remove unecessary items
        $pins = array_filter(
            $all_pins,
            function ($e) {
                return $e['id'] == $this->pin_id;
            }
        );  
        
        if (empty($pins)) return false;
        $pin = array_shift($pins);
        return $pin;
    }
    
    function get_board(){
        
        if ( !isset($this->board) ){
            $this->board = new Pinim_Board($this->board_id);
        }
        return $this->board;
        
    }
    /**
     * When loading raw datas
     * @param type $pin_datas
     * @return type
     */
    function sanitize_raw_datas($pin_datas){

        /*
         * Hook/unhook filters here to sanitize raw datas
         */
        $pin_datas = apply_filters('pin_sanitize_raw_datas',$pin_datas);
        //$pin_datas = array_filter($pin_datas);

        return $pin_datas;
    }


    /*
     * Get data from Pinterest
     */
    
    function get_datas($key = null,$raw = false){
        $pin_datas = $this->datas;
        if (!isset($key)) return $pin_datas;
        if (!isset($pin_datas[$key])) return false;
        return $pin_datas[$key];
    }
    
    /*
     * Get post saved on Wordpress
     */
    
    function get_post(){
        
        if (!$this->post){
            if ($post_id = pinim_get_post_by_pin_id($this->pin_id)){
                $this->post_id = $post_id;
                $this->post = get_post($this->post_id);
            }
            
        }

        return $this->post;
    }
    
    function get_link_action_import(){
        //Refresh cache
        $link_args = array(
            'step'      => 2,
            'action'    => 'pins_import_pins',
            'pin_ids'  => $this->pin_id,
            'paged'     => ( isset($_REQUEST['paged']) ? $_REQUEST['paged'] : null),
            //'boards_ids'    => ( isset($_REQUEST['board_ids']) ? $_REQUEST['board_ids'] : null)
        );

        $link = sprintf(
            '<a href="%1$s">%2$s</a>',
            pinim_get_tool_page_url($link_args),
            __('Import pin','pinim')

        );

        return $link;
    }
    
    function get_link_action_update(){
        
        if (!in_array($this->pin_id,pinim_tool_page()->existing_pin_ids)) return;

        $link_args = array(
            'step'          => 2,
            'action'        => 'pins_update_pins',
            'pin_ids'       => $this->pin_id,
            'paged'         => ( isset($_REQUEST['paged']) ? $_REQUEST['paged'] : null),
            'boards_ids'    => ( isset($_REQUEST['board_ids']) ? $_REQUEST['board_ids'] : null)
        );

        $link = sprintf(
            '<a href="%1$s">%2$s</a>',
            pinim_get_tool_page_url($link_args),
            __('Update pin','pinim')

        );

        return $link;
    }
    
    function get_link_action_edit(){
        
        if (!in_array($this->pin_id,pinim_tool_page()->existing_pin_ids)) return;
        
        $post = $this->get_post();

        $link = sprintf(
            '<a href="%1$s">%2$s</a>',
            get_edit_post_link( $post->ID ),
            __('Edit post','pinim')

        );

        return $link;
    }
    
    function get_remote_url(){
        $url = pinim()->pinterest_url.'pin/'.$this->pin_id;
        return $url;
    }
    
    function get_blank_post(){
        $blank_post = array(
            'post_author'       => get_current_user_id(),
            'post_type'         => 'post',
            'post_status'       =>'publish',
            'post_category'     => array(pinim_get_root_category_id()),
            'tags_input'        => array()
        );

        return apply_filters('pinim_get_blank_post',$blank_post);
    }
    
    function get_post_status(){
        $board = $this->get_board();
        $private = $board->get_options('private');
        if ($private == 'on'){
            return 'private';
        }else{
            return 'publish';
        }
    }
    
    function get_post_tags(){
        $tags = array();
        if ($description = $this->get_datas('description')){
            $tags = pinim_get_hashtags($description);
        }
        return $tags;
    }
    
    function get_post_title(){
        
        $title = $this->get_datas('title');
        
        
        if (!$title = $this->get_datas('title')){
            if ($description = $this->get_datas('description')){
                $tags = pinim_get_hashtags($description);
                foreach ((array)$tags as $tag){
                    $description = str_replace('#'.$tag,'',$description);
                }
                $title = wp_trim_words( $description, 30, '...' );
            }
            
        }
        
        if (!$title) {
            return sprintf(__('Pin #%1s','pinim'),$this->pin_id);
        }

        return $title;
        
    }
    
    function get_post_format(){
        //default format
        $format = 'image';

        if ($this->get_datas('is_video')){
            $format = 'video';
        }
        
        return $format;
    }

   function get_post_log_meta(){
       
       $datas = apply_filters('pin_sanitize_before_insert',$this->get_datas());
       
       $prefix = '_pinterest-';
       
       $metas = array(
           $prefix.'pin_id'     => $this->pin_id,
           $prefix.'board_id'   => $this->board_id,
           $prefix.'log'        => $datas
       );

       return $metas;

   }

    function set_post_content($post){
        $post_format = get_post_format( $post->ID );
        $link = $this->get_datas('link');
        $domain = $this->get_datas('domain');
        $content = null;
        $log = pinim_get_pin_meta('log',$post->ID,true);

        switch($post_format){

            case 'image':
                $content = get_the_post_thumbnail($post->ID,'full');

                $content ='<a href="' . $link . '" title="' . the_title_attribute('echo=0') . '" >'.$content.'</a>';
                
            break;

            case 'video':
                //https://codex.wordpress.org/Embeds
                $content = $link;

            break;
        }

        $content .= "\n";//line break (avoid problems with embeds)
        $content .='<p class="pinim-pin-source"><a href="' . $link . '" title="' . $domain . '" >'.$domain.'</a></p>';

        //allow to filter
        $content = apply_filters('pinim_get_post_content',$content,$post,$this);

        //print_r("<xmp>".$content."</xmp>");exit;

        $my_post = array();
        $my_post['ID'] = $post->ID;
        $my_post['post_content'] = $content;

        if (!wp_update_post( $my_post )){
            return false;
        }
        return true;
    }
    

    
    function save($update=false){
        
        $error = new WP_Error();
        $datas = apply_filters('pin_sanitize_before_insert',$this->get_datas());
        $board = $this->get_board();
        
        if (!$update){
            $post = $this->get_blank_post();
        }elseif(!$post = (array)$this->get_post()){
            $error->add('nothing_to_update',__("The current pin has never been imported and can't be updated",'pinim'));
            return $error;
        }
        
        if (!isset($datas['image'])){
            $error->add('no_pin_image',__("The current pin does not have an image file associated",'pinim'));
            return $error;
        }

        //set title
        $post['post_title'] = $this->get_post_title();
        
        //set tags
        $tags_input = array();
        if (isset($post['tags_input'])){
            $tags_input = $post['tags_input'];
        }
        $post['tags_input'] = array_merge( $tags_input,$this->get_post_tags() );
        
        //set post status
        $post['post_status'] = $this->get_post_status();
        
        //set post categories
        $post['post_category'] = (array)$board->get_categories();
        
        //set post date
        $post['post_date'] = date('Y-m-d H:i:s', $this->get_datas('created_at'));

        $post = array_filter($post);

        //insert post
        $post_id = wp_insert_post( $post, true );
        if ( is_wp_error($post_id) ) return $post_id;

        $new_post = get_post($post_id);

        //set post format
        $post_format = $this->get_post_format();
        if ( !set_post_format( $post_id , $post_format )){
            $error->add('pin_post_format',sprintf(__('Unable to set post format "%1$s"','pinim'),$post_format));
            return $error;
        }

        $featured_image_id = pinim_process_post_image($new_post,$datas['image']);

        //set featured image
        if ( !is_wp_error($featured_image_id) ){
            
                if ($update){ //delete old thumb
                    if ($old_thumb_id = get_post_thumbnail_id( $post_id )){
                        wp_delete_attachment( $old_thumb_id, true );
                    }
                }
            
                set_post_thumbnail($new_post, $featured_image_id);
                $hd_file = wp_get_attachment_image_src($featured_image_id, 'full');
                $hd_url = $hd_file[0];
        }else{
            $error_msg =  sprintf(__('Error while setting post thumbnail: %1s','pinim'),'<a href="'.$datas['image'].'" target="_blank">'.$datas['image'].'</a>');
            $error->add('pin_thumbnail_error', $error_msg, $post_id);
            return $error;
        }

        //set post metas
        $post_metas = $this->get_post_log_meta();

        foreach ( $post_metas as $key=>$value ) {
            update_post_meta( $post_id, $key, $value );
        }
        
        //set post content
        if (!$this->set_post_content($new_post)){
            //feedback
            $error_msg =  __('Error while updating post content', 'pinim');
            $error->add('pin_content_error', $error_msg, $post_id);
            return $error;
        }

        return $post_id;

    }
    
}

class Pinim_Pins_Table extends WP_List_Table {
    
    var $input_data = array();

    /** ************************************************************************
     * REQUIRED. Set up a constructor that references the parent constructor. We 
     * use the parent reference to set some default configs.
     ***************************************************************************/
    function __construct($data){
        global $status, $page;
        
        $this->input_data = $data;

        //Set parent defaults
        parent::__construct( array(
            'singular'  => __('pin','pinim'),     //singular name of the listed records
            'plural'    => __('pins','pinim'),    //plural name of the listed records
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
    function column_title($pin){

        //Build row actions
        $actions = array(
            'view'      => sprintf('<a href="%1$s" target="_blank">%2$s</a>',$pin->get_remote_url(),__('View on Pinterest','pinim'),'view'),
        );
        
        if ($pin->get_post()){ //only if post exists
            $actions['update'] = $pin->get_link_action_update();
            $actions['edit'] = $pin->get_link_action_edit();
        }else{
            $actions['import'] = $pin->get_link_action_import();
        }

        $title = $pin->get_datas('title');
        
        //Return the title contents
        $title = sprintf('%1$s <span style="color:silver">(id:%2$s)</span>%3$s',
            /*$1%s*/ $title,
            /*$2%s*/ $pin->pin_id,
            /*$3%s*/ $this->row_actions($actions)
        );
        
        //post icon
        $icon_classes = array('post-state-format','post-format-icon');
        
        if ( $pin->get_datas('is_video') ){
            $icon_classes[] = 'post-format-video';
        }else{
            $icon_classes[] = 'post-format-image';
        }
        
        $icon = sprintf('<span%1$s></span>',pinim_get_classes($icon_classes));
        
        return $icon.$title;
        
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
    function column_cb($pin){
        $hidden = sprintf('<input type="hidden" name="%1$s[pins][%2$s][id]" value="%2$s" />',
            'pinim_tool',
            $pin->pin_id
        );
        $bulk = sprintf(
            '<input type="checkbox" name="%1$s[pins][%2$s][bulk]" value="on" />',
            'pinim_tool',
            $pin->pin_id
        );
        return $hidden.$bulk;
    }

    function column_private($pin){

        //privacy
        $secret_checked_str = checked(true, false, false );
        $is_secret_pin = ($pin->get_datas('privacy')=='secret');

        if ($private = $pin->get_options('private') ){
            $secret_checked_str = checked($private, 'on', false );
        }else{
            $secret_checked_str = checked($is_secret_pin, true, false );
        }
        
        return sprintf(
            '<input type="checkbox" name="%1$s[pins][%2$s][private]" value="on" %3$s/>',
            'pinim_tool',
            $pin->pin_id,
            $secret_checked_str
        );
        
    }
    
    function column_thumbnail($pin){

        if (!$images = $pin->get_datas('images')) return;

        //get last one
        $image = array_pop($images);

        return sprintf(
            '<img src="%1$s" />',
            $image['url']
        );
    }
    
    function column_source($pin){

        $text = $pin->get_datas('domain');
        $url = get_option( 'link' );
        return sprintf(
            '<a target="_blank" href="%1$s">%2$s</a>',
            esc_url($url),
            $text
        );
    }
    
    function column_date($pin){

        $timestamp = $pin->get_datas('created_at');
        $date_format = get_option( 'date_format' );
        return date( $date_format, $timestamp );
    }
    
    function column_board($pin){
        
        $board = $pin->get_board();
        $board_name = $board->get_datas('name');

        return $board_name;
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
            'cb'        => '<input type="checkbox" />', //Render a checkbox instead of text
            'thumbnail'    => '',
            'title'     => __('Pin Title','pinim'),
            'source'     => __('Source','pinim'),
            'date'     => __('Date','pinim'),
            'board'     => __('Board','pinim')
        );
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
            'title'     => array('title',false),     //true means it's already sorted
            'pin_count'    => array('title',false),
            'pin_count_cached'    => array('title',false),
        );
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
            
                switch (pinim_tool_page()->get_screen_pins_filter()){
                    case 'pending':
                        submit_button( __( 'Import All Pins','pinim' ), 'button', 'filter_action', false );
                    break;
                    case 'processed':
                        submit_button( __( 'Update All Pins','pinim' ), 'button', 'filter_action', false );
                    break;
                }
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
            
            $link_args = array(
                'step'          => 2,   
                'board_ids'     => implode(',',(array)pinim_tool_page()->get_requested_boards_ids())
            );
            
            $link_processed_args = $link_args;
            $link_processed_args['pin_status'] = 'processed';
            
            $link_pending_args = $link_args;
            $link_pending_args['pin_status'] = 'pending';
            
            
            
            $link_processed_classes = array();
            $link_pending_classes = array();
            
            $processed_count = 0;
            $pending_count = 0;
            
            $pins = pinim_tool_page()->get_requested_pins();

            
            foreach ($pins as $pin){
                if (in_array($pin->pin_id,pinim_tool_page()->existing_pin_ids)) $processed_count++;
            }
            
            $pending_count = count($pins) - $processed_count;
            
            //
            
            switch (pinim_tool_page()->get_screen_pins_filter()){
                case 'pending':
                    $link_pending_classes[] = 'current';
                break;
                case 'processed':
                    $link_processed_classes[] = 'current';
                break;
            }


            $link_processed = sprintf(
                __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
                pinim_get_tool_page_url($link_processed_args),
                pinim_get_classes($link_processed_classes),
                __('Processed','pinim'),
                $processed_count
            );
            
            $link_pending = sprintf(
                __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
                pinim_get_tool_page_url($link_pending_args),
                pinim_get_classes($link_pending_classes),
                __('Pending','pinim'),
                $pending_count
            );


		return array(
                    'pending'       => $link_pending,
                    'processed'   => $link_processed
                    
                    
                );
	}

	/**
	 * Display the list of views available on this table.
	 *
	 * @since 3.1.0
	 * @access public
	 */
	public function views() {
		$views = $this->get_views();

		if ( empty( $views ) )
			return;

		echo "<ul class='subsubsub'>\n";
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
        $actions = array(
            'pins_import_pins'    => __('Import Pins','pinim'),
            'pins_update_pins'    => __('Update Pins','pinim')
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
        $per_page = pinim()->get_options('pins_per_page');
        
        
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

            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'id'; //If no sort, default to title
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'desc'; //If no order, default to asc
            $result = strcmp($a->get_datas($orderby), $b->get_datas($orderby)); //Determine sort order
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
        $data = array_slice($data,(($current_page-1)*$per_page),$per_page);
        
        
        
        /**
         * REQUIRED. Now we can add our *sorted* data to the items property, where 
         * it can be used by the rest of the class.
         */
        $this->items = $data;
        
        
        /**
         * REQUIRED. We also have to register our pagination options & calculations.
         */
        $this->set_pagination_args( array(
            'total_items' => $total_items,                  //WE have to calculate the total number of items
            'per_page'    => $per_page,                     //WE have to determine how many items to show on a page
            'total_pages' => ceil($total_items/$per_page)   //WE have to calculate the total number of pages
        ) );
    }


}