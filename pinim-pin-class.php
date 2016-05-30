<?php

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}


// Include the WP_Posts_List_Table class
if(!class_exists('WP_Posts_List_Table')){
    require_once ( ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php' );
}

class Pinim_Pin{
    
    var $pin_id;
    
    var $options;
    var $datas_raw;
    var $datas;
    var $board_id;
    var $board;
    var $post; //wp post
    
    
    function __construct($pin_id){

        $this->pin_id = $pin_id;
        $this->datas = $this->get_raw_datas();
        $this->board_id = $this->get_datas(array('board','id'));
        
        //TO FIX : we should store the username and board slug, so storing is easier ?

    }
    
    function get_raw_datas(){

        $all_pins = pinim_tool_page()->get_all_raw_pins();
        //remove unecessary items
        $current_pin_id = $this->pin_id;
        $pins = array_filter(
            (array)$all_pins,
            function ($e) use ($current_pin_id) {
                return $e['id'] == $current_pin_id;
            }
        );  

        if (empty($pins)) return false;
        $pin = array_shift($pins);

        return $pin;
    }

    function get_board(){

        //load boards
        $boards = pinim_tool_page()->get_boards();
        
        $pin_board_id = $this->board_id;

        $boards = array_filter(
            (array)$boards,
            function ($e) use ($pin_board_id) {
                return $e->board_id == $pin_board_id;
            }
        );  

        return array_shift($boards); //keep only first
        
    }
    /*
     * Get data from Pinterest
     */
    
    function get_datas($keys = null){
        return pinim_get_array_value($keys, $this->datas);
    }
    
    /*
     * Get post saved on Wordpress
     */
    
    function get_post(){
        
        if (!$this->post){
            if ($post_id = pinim_get_post_by_pin_id($this->pin_id)){
                $this->post = get_post($post_id);
                
                $this->datas = pinim_get_pin_log($this->post->ID);
                $this->board_id = pinim_get_pin_meta('board_id',$this->post->ID,true);
                
            }
            
        }

        return $this->post;
    }
    
    function get_link_action_import(){
        //Refresh cache
        $link_args = array(
            'step'      => 'pins-list',
            'action'    => 'pins_import_pins',
            'pin_ids'  => $this->pin_id,
            //'paged'     => ( isset($_REQUEST['paged']) ? $_REQUEST['paged'] : null),
        );

        $link = sprintf(
            '<a href="%1$s">%2$s</a>',
            pinim_get_tool_page_url($link_args),
            __('Import pin','pinim')

        );

        return $link;
    }
    
    function get_link_action_update(){
        
        if ( !pinim()->get_options('enable_update_pins') ) return;
        
        if (!in_array($this->pin_id,pinim_tool_page()->existing_pin_ids)) return;

        $link_args = array(
            'step'          => 'pins-list',
            'action'        => 'pins_update_pins',
            'pin_ids'       => $this->pin_id,
            //'paged'         => ( isset($_REQUEST['paged']) ? $_REQUEST['paged'] : null),
        );

        $link = sprintf(
            '<a href="%1$s">%2$s</a>',
            pinim_get_tool_page_url($link_args),
            __('Update pin','pinim')

        );

        return $link;
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

        $private = $board->is_private_board();
        if ($private = $board->is_private_board()){
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
    
    function set_pin_metas($post_id){

        $datas = $this->get_datas();

        $prefix = '_pinterest-';

        $post_metas = array(
            'pin_id'        => $this->pin_id,
            'board_id'      => $this->board_id,
            'db_version'    => pinim()->db_version,
            'log'           => $datas //keep the pinterest original datas, may be useful one day or another.
        );

        foreach ( $post_metas as $key=>$value ) {
            update_post_meta( $post_id, $prefix.$key, $value );
        }

    }

    function build_post_content(){
        $post_format = get_post_format( $this->post->ID );
        $link = $this->get_datas('link');
        $domain = $this->get_datas('domain');
        $content = null;

        switch($post_format){

            case 'image':
                $content = get_the_post_thumbnail($this->post->ID,'full');

                $content ='<a href="' . $link . '" title="' . the_title_attribute('echo=0') . '" >'.$content.'</a>';
                
            break;

            case 'video':
                //https://codex.wordpress.org/Embeds
                $content = $link;

            break;
        }

        $content .= "\n";//line break (avoid problems with embeds)
        $link_el = sprintf(__('Source: <a href="%1$s" target="_blank">%2$s</a>','pinim'),$link,$domain);
        $content .=sprintf('<p class="pinim-pin-source">%s</p>',$link_el);

        return $content;
    }
    
    /*
     * Get best image (url) possible from datas
     */
    
    function get_datas_image_url(){
        
        $images_arr = $this->get_datas('images');
        $image = null;

        if ( !$images_arr ){
            return new WP_Error('pin_no_image',__("The current pin does not have an image file associated",'pinim'));
        }

        if (isset($images_arr['orig']['url'])){ //get best resolution
            $image = $images_arr['orig'];
        }else{ //get first array item
            $image = array_shift($images_arr);
        }
        
        if ( !isset($image['url']) ) return false;
        
        return $image['url'];

    }
    
    function save($update=false){
        
        $error = new WP_Error();
        $datas = $this->get_datas();
        $board = $this->get_board();
        
        if (!$update){
            $post = $this->get_blank_post();
        }elseif(!$post = (array)$this->get_post()){
            $error->add('nothing_to_update',__("The current pin has never been imported and can't be updated",'pinim'));
            return $error;
        }

        //image
        $image_url = $this->get_datas_image_url();

        if ( is_wp_error($image_url) ){
            
            //TO FIX USEFUL ? maybe we should rework the pins error handling.
            $code = $image->get_error_code();
            $msg = $image->get_error_message($code);
            $error->add($code,$msg);
            
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
        $post['post_category'] = (array)$board->get_category();
        
        //set post date
        $timestamp = strtotime($this->get_datas('created_at'));
        $post['post_date'] = date('Y-m-d H:i:s', $timestamp);

        $post = array_filter($post);

        //insert post
        $post_id = wp_insert_post( $post, true );
        if ( is_wp_error($post_id) ) return $post_id;
        
        //TO FIX : post is created before image is checked.
        // We should reverse that.

        
        //populate the post.  Can't use Pinim_Pin::get_post() here since the pin ID as not been stored yet.
        $this->post = get_post($post_id);

        //set post format
        $post_format = $this->get_post_format();
        if ( !set_post_format( $post_id , $post_format )){
            $error->add('pin_post_format',sprintf(__('Unable to set post format "%1$s"','pinim'),$post_format));
            return $error;
        }

        $attachment_id = $this->attach_remote_image();

        //set featured image
        if ( !is_wp_error($attachment_id) ){
            
                if ($update){ //delete old thumb
                    if ($old_thumb_id = get_post_thumbnail_id( $post_id )){
                        wp_delete_attachment( $old_thumb_id, true );
                    }
                }
            
                set_post_thumbnail($this->post, $attachment_id);
                $hd_file = wp_get_attachment_image_src($attachment_id, 'full');
                $hd_url = $hd_file[0];
                
        }else{
            
            wp_delete_post( $post_id, true );
            $error_code = $attachment_id->get_error_code();
            $error_message = $attachment_id->get_error_message($error_code);
            $image_name = basename( $image_url );
            $error_msg =  sprintf(
                __('Error while setting post thumbnail %1s : %2s','pinim'),
                '<a href="'.$image_url.'" target="_blank">'.$image_name.'</a>',
                $error_message
            );
            $error->add('pin_thumbnail_error', $error_msg, $post_id);
            return $error;
            
        }

        //set post metas
        $this->set_pin_metas($post_id);
        
        //final update
        $update_post = array();
        $update_post['ID'] = $this->post->ID;
        $update_post['post_content'] = $this->build_post_content(); //set post content
        
        //allow to filter before updating post
        $update_post = apply_filters('pinim_post_before_insert',$update_post,$this);

        if (!wp_update_post( $update_post )){
            //feedback
            $error_msg =  __('Error while updating post content', 'pinim');
            $error->add('pin_content_error', $error_msg, $post_id);
            return $error;
        }

        return $post_id;

    }
    
    /**
     * Import and pin image; store original URL as attachment meta
     * @return \WP_Error|string
     */
    function attach_remote_image() {

        $image_url = $this->get_datas_image_url();

        if ( is_wp_error($image_url) ) return $image_url;

        if (!$attachment_id = pinim_image_exists($image_url)){

            //TO FIX is this needed ?
            if ( !current_user_can('upload_files') ) {
                return new WP_Error('upload_capability',__("User cannot upload files",'pinim'));
            }

            if (empty($image_url)){
                return new WP_Error('upload_empty_url',__("Image url is empty",'pinim'));
            }

            // Image base name:
            $name = basename( $image_url );

            // Save as a temporary file
            $tmp = download_url( $image_url );

            $file_array = array(
                'name'     => $name,
                'tmp_name' => $tmp
            );

            // Check for download errors
            if ( is_wp_error( $tmp ) ) {
                @unlink( $file_array[ 'tmp_name' ] );
                return $tmp;
            }

            // Get file extension (without downloading the whole file)
            $extension = image_type_to_extension( exif_imagetype( $file_array['tmp_name'] ) );

            // Take care of image files without extension:
            $path = pathinfo( $tmp );
            if( ! isset( $path['extension'] ) ):
                $tmpnew = $tmp . '.tmp';
                if( ! rename( $tmp, $tmpnew ) ):
                    return '';
                else:
                    $ext  = pathinfo( $image_url, PATHINFO_EXTENSION );
                    $name = pathinfo( $image_url, PATHINFO_FILENAME )  . $extension;
                    $tmp = $tmpnew;
                endif;
            endif;

            // Construct the attachment array.
            $attachment_data = array (
                'post_date'     => $this->post->post_date, //use same date than parent
                'post_date_gmt' => $this->post->post_date_gmt
            );

            $attachment_data = apply_filters('pinim_attachment_before_insert',$attachment_data,$this);

            $attachment_id = media_handle_sideload( $file_array, $this->post->ID, null, $attachment_data );

            // Check for handle sideload errors:
           if ( is_wp_error( $attachment_id ) ){
               @unlink( $file_array['tmp_name'] );
               return $attachment_id;
           }

            //save URL in meta (so we can check if image already exists)
            add_post_meta($attachment_id, '_pinterest-image-url',$image_url);

        }

        return $attachment_id;
    }
    
}

abstract class Pinim_Pins_Table extends WP_List_Table {
    
    var $input_data = array();
    var $pin_idx = -1;

    var $orderby = 'title';
    var $order = 'asc';

    /** ************************************************************************
     * REQUIRED. Set up a constructor that references the parent constructor. We 
     * use the parent reference to set some default configs.
     ***************************************************************************/
    function __construct(){
        global $status, $page;

        $this->orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : $this->orderby; //If no sort, default to date
        $this->order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : $this->order; //If no order, default to desc

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
    
    function get_pin_id($pin){
        /*override me*/
    }
    
    function get_pin_title($pin){
        /*override me*/
    }
    
    function get_actions($pin){
        
        $pin_id = $this->get_pin_id($pin);
        
        return array(
            'view'      => sprintf('<a href="%1$s" target="_blank">%2$s</a>',pinim_get_pinterest_pin_url($pin_id),__('View on Pinterest','pinim'),'view'),
        );
    }
    
    function get_icons($pin){
        /*override me*/
    }
    
    function column_title($pin){

        //Return the title contents
        $title = sprintf('%1$s <span class="item-id">(id:%2$s)</span>%3$s',
            /*$1%s*/ $this->get_pin_title($pin),
            /*$2%s*/ $this->get_pin_id($pin),
            /*$3%s*/ $this->row_actions($this->get_actions($pin))
        );

        return $this->get_icons($pin).$title;
        
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
        
        $this->pin_idx++;
        
        $hidden = sprintf('<input type="hidden" name="%1$s[%2$s][id]" value="%3$s" />',
            'pinim_form_pins',
            $this->pin_idx,
            $pin->pin_id
        );
        $bulk = sprintf(
            '<input type="checkbox" class="bulk" name="%1$s[%2$s][bulk]" value="on" />',
            'pinim_form_pins',
            $this->pin_idx
        );
        return $hidden.$bulk;
    }
    
    function get_thumbnail_url($item){
        /*override me*/
        return null;
    }
    
    function column_thumbnail($item){

        return sprintf(
            '<img src="%1$s" />',
            $this->get_thumbnail_url($item)
        );

    }
    
    function column_source($pin){
        /*override me*/
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
        /*override me*/
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
                        //Import All Pins
                        submit_button( pinim_tool_page()->all_action_str['import_all_pins'], 'button', 'all_pins_action', false, array('id'=>'import_all_bt') );
                    break;
                    case 'processed':
                        //Update All Pins
                        if ( pinim()->get_options('enable_update_pins') ){
                            submit_button( pinim_tool_page()->all_action_str['update_all_pins'], 'button', 'all_pins_action', false, array('id'=>'update_all_bt') );
                        }
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
                'step'          => 'pins-list'
            );
            $link_args = array_filter($link_args);
            
            $link_processed_args = $link_args;
            $link_processed_args['pins_filter'] = 'processed';
            
            $link_pending_args = $link_args;
            $link_pending_args['pins_filter'] = 'pending';
            
            
            
            $link_processed_classes = array();
            $link_pending_classes = array();
            
            $processed_count = pinim_tool_page()->get_pins_count_processed();
            $pending_count = pinim_tool_page()->get_pins_count_pending();

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
        $actions = array();
        
        if (pinim_tool_page()->get_screen_pins_filter() =='pending'){
            $actions['pins_import_pins'] = __('Import pins','pinim');
        }elseif (pinim_tool_page()->get_screen_pins_filter() =='processed'){
            if ( pinim()->get_options('enable_update_pins') ){
                $actions['pins_update_pins'] = __('Update pins','pinim');
            }
            $actions['pins_delete_pins'] = __('Move to Trash');
        }
        
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


}

class Pinim_Pending_Pins_Table extends Pinim_Pins_Table {
    
    var $orderby = 'date';
    var $order = 'desc';
    
    function __construct(){
        parent::__construct();
    }
    
    function get_columns(){
        $columns = parent::get_columns();
        $columns['board'] = __('Board');
        
        return $columns;
    }
    
    function get_sortable_columns() {
        $sortable_columns = array(
            'title'             => array('title',false),
            'source'             => array('source',false),
            'date'              => array('date',true),     //true means it's already sorted
            'board'      => array('board',false),
        );
        return $sortable_columns;
    }
    
    /**
    * This checks for sorting input and sorts the data in our array accordingly.
    * 
    * In a real-world situation involving a database, you would probably want 
    * to handle sorting by passing the 'orderby' and 'order' values directly 
    * to a custom query. The returned data will be pre-sorted, and this array
    * sorting technique would be unnecessary.
    */
    function usort_reorder($a,$b){

       switch ($this->orderby){
           case 'title':

               $title_a = ($a->get_datas('title')) ? $a->get_datas('title') : $a->pin_id;
               $title_b = ($b->get_datas('title')) ? $b->get_datas('title') : $b->pin_id;
               $result = strcmp($title_a, $title_b);
           break;

           case 'date_updated':
               $post_a = $a->get_post();
               $post_b = $b->get_post();
               $timestamp_a = get_post_modified_time( 'U', false, $post_a );
               $timestamp_b = get_post_modified_time( 'U', false, $post_b );
               $result = strcmp($timestamp_a, $timestamp_b);
           break;

           case 'board':

               $board_a = $a->get_board();
               $board_b = $b->get_board();
               $board_a_name = $board_a->get_datas('name');
               $board_b_name = $board_b->get_datas('name');
               $result = strcmp($board_a_name, $board_b_name);

           break;

           case 'date':
               
               $pinterest_date_a = $a->get_datas('created_at');
               $pinterest_date_b = $b->get_datas('created_at');
               
               $timestamp_a = strtotime($pinterest_date_a);
               $timestamp_b = strtotime($pinterest_date_b);
               
               $result = strcmp($timestamp_a, $timestamp_b);
           break;

           case 'source':

               $source_a = $a->get_datas('domain');
               $source_b = $b->get_datas('domain');

               $result = strcmp($source_a, $source_b);
           break;

       }

       return ($this->order==='asc') ? $result : -$result; //Send final sort direction to usort
    }
    
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
        usort($data, array(&$this,'usort_reorder'));
        
        
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
    

    
    function get_actions($pin){
        
        $actions = parent::get_actions($pin);
        $actions['import'] = $pin->get_link_action_import();
        
        return $actions;
    }

    
    function get_pin_id($pin){
        return $pin->pin_id;
    }
    
    function get_pin_title($pin){
        return $pin->get_datas('title');
    }
    
    function get_thumbnail_url($pin){
        
        $image = null;
        
        if ($images = $pin->get_datas('images')){ //buffer pin
            //get last one
            $image = array_pop($images);
            $image = $image['url'];
        }
        
        return $image;

    }
    
    function get_icons($pin){
        //post icon
        $icon_classes = array('post-state-format','post-format-icon');
        
        if ( $pin->get_datas('is_video') ){
            $icon_classes[] = 'post-format-video';
        }else{
            $icon_classes[] = 'post-format-image';
        }
        return sprintf('<span%1$s></span>',pinim_get_classes($icon_classes));
    }
    
    function column_source($pin){
        
        $text = $url = null;

        $text = $pin->get_datas('domain');
        $url = $pin->get_datas('link');

        //if (!$text || !$url) return;

        return sprintf(
            '<a target="_blank" href="%1$s">%2$s</a>',
            esc_url($url),
            $text
        );
    }
    
    function column_board($pin){

        $board = $pin->get_board();

        if ( !$board || is_wp_error($board) ) return;

        $board_name = $board->get_datas('name');

        return $board_name;
    }
    
    function column_date($pin){
        $pinterest_date = $pin->get_datas('created_at');
        $timestamp = strtotime($pinterest_date);
        $date = date_i18n( get_option( 'date_format'), $timestamp );
        $time = date_i18n( get_option( 'time_format'), $timestamp );
        return sprintf( __('%1$s at %2$s','pinim'), $date, $time );
    }
    
}

class Pinim_Processed_Pins_Table extends Pinim_Pins_Table {
    
    var $query_args = array();
    var $query = null;
    var $per_page = 10;
    var $orderby = 'modified';
    var $order = 'desc';
    
    function __construct($args = array()){
        
        parent::__construct();
        
        $this->per_page = pinim()->get_options('pins_per_page');
        
        $default = array(
            'post_status'   => array('publish','pending','draft','future','private'),
            'meta_query'        => array(
                array(
                    'key'     => '_pinterest-pin_id',
                )
            ),
            'posts_per_page' => $this->per_page,
            'orderby'   => $this->orderby,
            'order'     => $this->order,
            'paged'     => ( isset($_REQUEST['paged']) ? $_REQUEST['paged'] : null),
        );

        $this->query_args = wp_parse_args($args,$default);
    }
    
    function get_columns(){
        $columns = parent::get_columns();

        $columns['date_updated'] = __('Last Updated','pinim');
        $columns['categories'] = __('Categories');
        
        return $columns;
    }
    
    function get_sortable_columns() {
        $sortable_columns = array(
            'title'             => array('title',false),     
            'date'              => array('date',false),
            'date_updated'      => array('modified',true), //true means it's already sorted
            //'categories'      => array('date_updated',false), //TO FIX TO WORK ON
        );
        return $sortable_columns;
    }
    
    function prepare_items() {
        global $wpdb; //This is used only if making any database queries

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

        //query
        $this->query = new WP_Query($this->query_args);

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
        $total_items = $this->query->found_posts;

        /**
         * REQUIRED. Now we can add our *sorted* data to the items property, where 
         * it can be used by the rest of the class.
         */
        $this->items = $this->query->posts;

        /**
         * REQUIRED. We also have to register our pagination options & calculations.
         */
        $this->set_pagination_args( array(
            'total_items' => $total_items,                  //WE have to calculate the total number of items
            'per_page'    => $this->per_page,               //WE have to determine how many items to show on a page
            'total_pages' => ceil($total_items/$this->per_page)   //WE have to calculate the total number of pages
        ) );
    }
    

    
    function get_pin_id($post){
        return pinim_get_pin_meta('pin_id',$post->ID,true);
    }
    
    function get_pin_title($post){
        return get_the_title($post->ID);
    }
    
    function get_thumbnail_url($item){
        return get_the_post_thumbnail_url($item->ID);
    }
    
    function get_icons($post){
        //post icon
        $icon_classes = array('post-state-format','post-format-icon');
        
        $format = get_post_format( $post->ID );
        
        switch ($format){
            case 'image':
                $icon_classes[] = 'post-format-image';
            break;
            case 'video':
                $icon_classes[] = 'post-format-video';
            break;
        }

        return sprintf('<span%1$s></span>',pinim_get_classes($icon_classes));
    }
    
    function get_actions($post){
        
        $actions = parent::get_actions($post);
        
        $actions['view'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            get_permalink( $post->ID ),
            __('View')

        );
        
        $actions['edit'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            get_edit_post_link( $post->ID ),
            __('Edit post','pinim')

        );
        $actions['delete'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            get_delete_post_link( $post->ID ),
            __('Move to Trash')

        );
        
        if ( pinim()->get_options('enable_update_pins') ) {
            $actions['update'] = $post->get_link_action_update();
        }
        
        return $actions;
        
    }
    
    function column_date($post){
        $timestamp = get_post_time( 'U', false, $post );
        $date = date_i18n( get_option( 'date_format'), $timestamp );
        $time = date_i18n( get_option( 'time_format'), $timestamp );
        return sprintf( __('%1$s at %2$s','pinim'), $date, $time );
    }
    
    function column_date_updated($post){
        $timestamp = get_post_modified_time( 'U', false, $post );
        $date = date_i18n( get_option( 'date_format'), $timestamp );
        $time = date_i18n( get_option( 'time_format'), $timestamp );
        return sprintf( __('%1$s at %2$s','pinim'), $date, $time );
    }    
    
    function column_source($post){
        
        $text = $url = null;
        $log = pinim_get_pin_log($post->ID);

        $text = $log['domain'];
        $url = $log['link'];

        //if (!$text || !$url) return;

        return sprintf(
            '<a target="_blank" href="%1$s">%2$s</a>',
            esc_url($url),
            $text
        );
    }
    
    
    function column_categories($post){
        return get_the_category_list( '', '', $post->ID );
    }
    
}
