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

function pinim_get_meta_value_by_key($meta_key,$limit = null){
    global $wpdb;
    if ($limit)
        return $value = $wpdb->get_var( $wpdb->prepare("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s LIMIT %d" , $meta_key,$limit) );
    else
        return $value = $wpdb->get_col( $wpdb->prepare("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s" , $meta_key) );
}
