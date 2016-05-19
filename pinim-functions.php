<?php

/**
 * Get a value in a multidimensional array
 * http://stackoverflow.com/questions/1677099/how-to-use-a-string-as-an-array-index-path-to-retrieve-a-value
 * @param type $keys
 * @param type $array
 * @return type
 */
function pinim_get_array_value($keys = null, $array){
    if (!$keys) return $array;
    
    $keys = (array)$keys;
    $first_key = $keys[0];
    if(count($keys) > 1) {
        if ( isset($array[$keys[0]]) ){
            return pinim_get_array_value(array_slice($keys, 1), $array[$keys[0]]);
        }
    }elseif (isset($array[$first_key])){
        return $array[$first_key];
    }
    
    return false;
}


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
         //'board',
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
 * Import and pin image; store original URL as attachment meta
 * @param type $post_parent
 * @param type $image_url
 * @return string|\WP_Error
 */
function pinim_attach_remote_image( $post_parent, $image_url ) {

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
            'post_date'     => $post_parent->post_date, //use same date than parent
            'post_date_gmt' => $post_parent->post_date_gmt
        );
        
        /*
        if ($post_parent->post_title){ //set pin title as attachment title if any
            $attachment_data['post_title'] = $post_parent->post_title;
        }
         */

        $attachment_id = media_handle_sideload( $file_array, $post_parent->ID, null, $attachment_data );
        
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