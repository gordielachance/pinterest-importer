<?php

/**
  * Filter a single pin :
  * Remove the Pinterest "board" multidimentional array
  * Add a single "board_id" key.
  * @param array $raw_pin
  * @return type
  */

 function pin_raw_data_board_reduce($raw_pin){
     if (!isset($raw_pin['board']['id'])) return $raw_pin;
     $raw_pin['board_id'] = $raw_pin['board']['id'];
     unset($raw_pin['board']);
     return $raw_pin;
 }

 /**
  * Filter a single pin :
  * Replace the Pinterest "images" multidimentional array
  * Add a single "image" key with the image original url for value.
  * @param array $raw_pin
  * @return type
  */

 function pin_raw_data_images_reduce($raw_pin){

     if (!isset($raw_pin['images'])) return $raw_pin;

     if ($raw_pin['images']['orig']['url']){ //get best resolution
         $image = $raw_pin['images']['orig'];
     }else{ //get first array item
         $first_key = array_values($raw_pin['images']);
         $image = array_shift($first_key);
     }
     
     if ($image['url']){
         $raw_pin['image'] = $image['url'];
         unset($raw_pin['images']);
     }

     return $raw_pin;

 }

 /**
  * Filter a single pin :
  * Remove unecessary keys
  * @param array $raw_pin
  * @return type
  */

 function pin_raw_data_remove_unecessary_keys($raw_pin){
     //remove some keys
     $remove_keys = array(
         'image_signature',
         'like_count',
         'price_currency',
         'privacy',
         'comments',
         'access',
         'comment_count',
         'board',
         'method',
         'price_value',
         'is_repin',
         'liked_by_me',
         'is_uploaded',
         'repin_count',
         'dominant_color'
     );
     return array_diff_key($raw_pin,array_flip($remove_keys));
 }

 /**
  * Filter a single pin :
  * Replace the Pinterest formatted date by a timestamp for the key "created_at"
  * @param array $raw_pin
  * @return type
  */

 function pin_raw_data_date_to_timestamp($raw_pin){
     if (!isset($raw_pin['created_at'])) return;
     $raw_pin['created_at'] = strtotime($raw_pin['created_at']);
     return $raw_pin;
 }

 /**
  * Filter a single pin :
  * Remove the "pinner" key if the pinner is the current logged user
  * @param array $raw_pin
  * @return type
  */

 function pin_raw_data_remove_self_pinner($raw_pin){
     if (!isset($raw_pin['pinner'])) return $raw_pin;
     $user_datas = pinim()->get_session_data('user_datas');

     if ($raw_pin['pinner']['username'] != $user_datas['username']) return $raw_pin;

     unset($raw_pin['pinner']);

     return $raw_pin;
 }
 
function pinim_get_hashtags($string){
   $tags = array();
   if ($string){
       preg_match_all("/(#\w+)/",$string, $matches);
       $tags = $matches[0];
   }

   foreach((array)$tags as $key=>$tag){
       $tags[$key] = str_replace('#','',$tag);
   }

   return $tags;
}

/**
 * Download remote file, keep track of URL map
 *
 * @param object $post
 * @param string $url
 * @return array
 */
function pinim_fetch_remote_image( $url, $post ) {
        global $switched, $switched_stack, $blog_id;
        
        if (empty($url)){
            return new WP_Error('upload_empty_url',__("Image url is empty",'pinim'));
        }

        if (count($switched_stack) == 1 && in_array($blog_id, $switched_stack))
                $switched = false;

        // Build filename
        $filename = $post->post_title;
        $filename = (strlen($filename) > 49) ? substr($filename,0,49) : $filename;
        $filename = sanitize_file_name(trim($filename));

        // Set file extension
        $size = getimagesize($url);
        $extension = image_type_to_extension($size[2]);
        $filename = $filename.$extension;

        if(!$extension){
            return new WP_Error( 'import_file_error', sprintf( __( 'Invalid image: %d' ), $url ) );
        }

        //TO FIX check attachment already exists

        //Fetch image
        if( !class_exists( 'WP_Http' ) )
                include_once( ABSPATH . WPINC. '/class-http.php' );

        $image = new WP_Http();
        $image = $image->request($url);
        
        if (is_wp_error($image)){
            return $image;
        }

        // get placeholder file in the upload dir with a unique sanitized filename
        $upload = wp_upload_bits( $filename,null,$image['body'], $post->post_date );

        if ( $upload['error'] ){
            return new WP_Error( 'upload_dir_error', $upload['error'] );
        }
                

        //TO FIX TO CHECK do we need to apply those filters here ?
        return apply_filters( 'wp_handle_upload', $upload );
}

/**
 * Import and processes each attachment
 *
 * @param object $post
 * @param array $fullsizes
 * @param array $thumbs
 * @return void
 */
function pinim_process_post_image( $post, $image_url ) {

    if (!$attachment_id = pinim_image_exists($image_url)){

        if ( !current_user_can('upload_files') ) {
            return new WP_Error('upload_capability',__("User cannot upload files",'pinim'));
        }

        $upload = pinim_fetch_remote_image( $image_url, $post );
        if ( is_wp_error( $upload ) ) return $upload;

        if ( 0 == filesize( $upload['file'] ) ) {
                @unlink( $upload['file'] );
                return new WP_Error('upload_empty',__("Zero length file",'pinim'));
        }

        $info = wp_check_filetype( $upload['file'] );

        if ( false === $info['ext'] ) {
                @unlink( $upload['file'] );
                return new WP_Error('upload_invalid_file_type',__("Invalid file type.",'pinim'));
        }

        // as per wp-admin/includes/upload.php
        $attachment = array ( 
                'post_title' => $post->post_title, 
                'post_content' => '', 
                'post_status' => 'inherit', 
                'guid' => $upload['url'], 
                'post_mime_type' => $info['type'],
                'post_author' => $post->post_author,
                );

        $attachment_id = (int) wp_insert_attachment( $attachment, $upload['file'], $post->ID );
        if(!$attachment_id) return false;

        $attachment_meta = @wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $attachment_meta );

        //save URL in meta (so we can check if image already exists)
        add_post_meta($attachment_id, '_pinterest-image-url',$image_url);
    }

    return $attachment_id;
}

/**
* Get term ID for an existing term (with its name),
* Or create the term and return its ID
* @param string $term_name
* @param string $term_tax
* @param type $term_args
* @return boolean 
*/
function pinim_get_term_id($term_name,$term_tax,$term_args=array()){
    
    $parent = null;

    if (isset($term_args['parent'])){
        $parent = $term_args['parent'];
    }
    
    if ($existing_term = term_exists($term_name,$term_tax,$parent)){
        return $existing_term;
    }

    //create it
    return wp_insert_term($term_name,$term_tax,$term_args);
    
}

function pinim_get_pins_filter_action(){
    $action = null;
    
    //filter buttons
    if (isset($_REQUEST['filter_action'])){
        switch ($_REQUEST['filter_action']){
            //step 2
            case __( 'Import all pins','pinim' ):
                $action = 'pins_import_pins';
            break;
            case __( 'Update all pins','pinim' ):
                $action = 'pins_update_pins';
            break;

        }
    }
 
    return $action;
}



function pinim_get_boards_filter_action(){
    $action = null;
    //filter buttons
    if (isset($_REQUEST['filter_action'])){
        switch ($_REQUEST['filter_action']){
            //step 1
            case __( 'Update all boards settings','pinim' ):
                $action = 'boards_update_settings';
            break;
            case __( 'Cache all boards pins','pinim' ):
                $action = 'boards_cache_pins';
            break;
            case __( 'Import all boards pins','pinim' ):
                $action = 'boards_import_pins';
            break;

        }
    }
    return $action;
}

function pinim_get_requested_boards_ids(){
    $bulk_boards_ids = array();
    $bulk_boards = array();
    
    if (isset($_POST['pinim_tool'])){
        $input = $_POST['pinim_tool'];
    }
    
    if ( isset($input['boards']) ) { 

        $board_settings = $input['boards'];

        foreach((array)$board_settings as $board){
            if ( !pinim_get_boards_filter_action() && !isset($board['bulk']) ) continue;
                $bulk_boards_ids[] = $board['id'];
        }

    }elseif ( isset($_REQUEST['board_ids']) ) {
        $bulk_boards_ids = explode(',',$_REQUEST['board_ids']);
    }
    
    

    return $bulk_boards_ids;
}

function pinim_get_requested_boards(){
    
    $bulk_boards_ids = pinim_get_requested_boards_ids();
    $bulk_boards = array();

    foreach ((array)$bulk_boards_ids as $bulk_board_id){
        $bulk_boards[] = new Pinim_Board($bulk_board_id);
    }
    return $bulk_boards;
}

function pinim_get_requested_pins(){
    $pins = array();
    $raw_pins = array();
    $bulk_pins_ids = array();

    if (isset($_POST['pinim_tool'])){
        $input = $_POST['pinim_tool'];
    }
    
    //bulk pins
    if ( isset($input['pins']) ) {

        $pin_settings = $input['pins'];

        foreach((array)$pin_settings as $pin){
            if (!isset($pin['bulk'])) continue;
                $bulk_pins_ids[] = $pin['id'];
        }

    }elseif ( isset($_REQUEST['pin_ids']) ) {

        $bulk_pins_ids = explode(',',$_REQUEST['pin_ids']);

    }
 
    if ( (!$bulk_pins_ids) && ( $requested_boards = pinim_get_requested_boards() ) ) {
        //get board queues
        foreach ((array)$requested_boards as $board){
            
            $board_datas = $board->get_datas();

            if ( is_wp_error($board_datas) ){
                add_settings_error('pinim', 'get_datas_board_'.$board->board_id, $board_datas->get_error_message());
                continue;
            }else{
                $queue = $board->get_cached_pins();

                if ( empty($queue) || is_wp_error($queue) ){
                    $board_error_ids[]=$board->board_id;
                    $link_pins_cache = $board->get_link_action_cache();
                    add_settings_error('pinim', 'get_queue_board_'.$board->board_id, sprintf(__( 'No pins found for %1$s.  Please %2$s.', 'pinim' ),'<em>'.$board->get_datas('name').'</em>',$link_pins_cache));
                }else{
                    $raw_pins = array_merge($raw_pins,$queue);
                }
            }


        }

    }


    foreach ((array)$raw_pins as $key=>$raw_pin){
        $pin = new Pinim_Pin($raw_pin['id']);
        $pins[] = $pin;
    }

    return $pins;
}

function get_all_cached_pins($board_id = null){
    
    $pins = array();
    
    $queues = (array)pinim()->get_session_data('queues');

    if (!$board_id){
        foreach ($queues as $board_id=>$queue){
            if (!isset($queue['pins'])) continue;
            $pins = array_merge($pins,$queue['pins']);
        }
    }elseif( isset($queues[$board_id]['pins']) ){
        $pins = $queues[$board_id]['pins'];
    }

    return $pins;
    
}

function pinim_get_meta_value_by_key($meta_key,$limit = null){
    global $wpdb;
    if ($limit)
        return $value = $wpdb->get_var( $wpdb->prepare("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s LIMIT %d" , $meta_key,$limit) );
    else
        return $value = $wpdb->get_col( $wpdb->prepare("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s" , $meta_key) );
}
