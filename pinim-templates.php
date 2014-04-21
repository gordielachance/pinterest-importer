<?php

/**
 * Splits a pinterest url to get only the interesting parts. Used in functions below.
 * @param type $url
 * @return type
 */

function pai_url_extract_parts($url){
    $parts = parse_url($url);
    $path = trim($parts['path'], '/');
    return (array)explode('/',$path);
}

/**
 * Extract a username from a Pinterest url.
 * @param type $url
 * @return string (or false if this is not a valid user url).
 */

function pai_url_extract_user($url){
    $parts = pai_url_extract_parts($url);
    $not_allowed = array('pin','source');
    $user = $parts[0];
    if (in_array($user,$not_allowed)) return false;
    
    return (string)$user;
}

/**
 * Extract a pin ID from a Pinterest url.
 * @param type $url
 * @return int (or false if this is not a valid pin ID).
 */

function pai_url_extract_pin_id($url){
    $parts = pai_url_extract_parts($url);
    if($parts[0]!='pin') return false;
        
    return (int)$parts[1];
}

/**
 * Extract the board slug from a Pinterest url.
 * @param type $url
 * @return string (or false if this is not a valid board url).
 */

function pai_url_extract_board_slug($url){
    if (!pai_url_extract_user($url)) return false;
    $parts = pai_url_extract_parts($url);
    return (string)$parts[1];
}

/**
 * Extract the pin source from a Pinterest url.
 * @param type $url
 * @return string (or false if this is not a valid pin source).
 */

function pai_url_extract_source_slug($url){
    $parts = pai_url_extract_parts($url);
    if($parts[0]!='source') return false;
    
    return $parts[1];
}

/**
 * Checks if a pin ID already has been imported
 * @param type $pin_id
 * @return boolean
 */

function pai_pin_exists($pin_id){
    $query_args = array(
        'post_status'   => array('publish','pending','draft','future','private'),
        'meta_query'        => array(
            array(
                'key'     => '_pinterest-pin_id',
                'value'   => $pin_id,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    );
    $query = new WP_Query($query_args);
    if (!$query->have_posts()) return false;
    return $query->posts[0]->ID;
}

/**
 * Checks if a featured pin image already has been imported (eg. If we have two pins with the same image)
 * @param type $img_url
 * @return boolean
 */

function pai_image_exists($img_url){
    $query_args = array(
        'post_type'         => 'attachment',
        'post_status'       => 'inherit',
        'meta_query'        => array(
            array(
                'key'     => '_pinterest-image-url',
                'value'   => $img_url,
                'compare' => '='
            )
        ),
        'posts_per_page'    => 1
    );

    $query = new WP_Query($query_args);
    if (!$query->have_posts()) return false;
    return $query->posts[0]->ID;
}

/**
* Get term ID for an existing term (with its name),
* Or create the term and return its ID
* @param string $term_name
* @param string $term_tax
* @param type $term_args
* @return boolean 
*/
function pai_get_term_id($term_name,$term_tax,$term_args=array()){
    
    $parent = false;

    if (isset($term_args['parent'])){
        $parent = $term_args['parent'];
    }
    
    $term_exists = term_exists($term_name,$term_tax,$parent);
    $term_id = is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists;

    //it exists, return ID
    if($term_id) return $term_id;

    //create it
    $t = wp_insert_term($term_name,$term_tax,$term_args);
    if (!is_wp_error( $t ) ){
        return $t['term_id'];
    }elseif ( defined('IMPORT_DEBUG') && IMPORT_DEBUG ){
            echo ': ' . $t->get_error_message();
    }

    return false;
}

/**
 * Get a Pinterest pin url from its ID
 * @param type $pin_id
 * @return type
 */

function pai_get_pin_url($pin_id){
    $pin_url = sprintf('http://www.pinterest.com/pin/%s',$pin_id);
    return $pin_url;
}

/**
 * Get a Pinterest user url from its username
 * @param type $username
 * @return type
 */

function pai_get_user_url($username){
    $user_url = sprintf('http://www.pinterest.com/%s',$username);
    return $user_url;
}

/**
 * Get a Pinterest board url from its username & board slug
 * @param type $username
 * @param type $board_slug
 * @return type
 */

function pai_get_board_url($username,$board_slug){
    $board_url = sprintf('http://www.pinterest.com/%1s/%2s',$username,$board_slug);
    return $board_url;
}

/**
 * Generates the source text block for a pin
 * @param type $post_id
 * @return boolean
 */

function pai_get_source_text($post_id = false){
    
    if(!$post_id) $post_id = get_the_ID();
    
    $source = get_post_meta($post_id,'_pinterest-source',true);
    if(!$source) return false;
    
    $block = '<p class="pinterest-importer-source"><a href="'.$source.'" target="_blank">'.__('Source','pinim').'</a></p>';
    return apply_filters('pai_get_source_text',$block,$post_id);
}

/**
 * Filter the content to add the source text block
 * @param type $content
 * @param type $post
 * @return type
 */

function pai_add_source_text($content,$post){
    $post_id = $post->ID;
    if ($text_block = pai_get_source_text($post_id)){
        $content.= $text_block;
    }
    return $content;   
}

/**
 * Get a single pinterest meta (if key is defined) or all the pinterest metas for a post ID
 * @param type $key (optional)
 * @param type $post_id
 * @return type
 */

function pai_get_pin_meta($key = false, $post_id = false){
    $pin_metas = array();
    $prefix = '_pinterest-';
    $metas = get_post_custom($post_id);
    foreach((array)$metas as $meta_key=>$meta){
        $splitkey = explode($prefix,$meta_key);
        if (!isset($splitkey[1])) continue;
        $pin_key = $splitkey[1];
        
        //single
        if (count($meta) == 1){
            $meta = $meta[0];
        }
        
        if ($key){
            if ($pin_key == $key) return $meta;
        }else{
            $pin_metas[$pin_key] = $meta;
        }

    }

    return $pin_metas;
}


function pai_get_blank_post(){
    $blank_post = array(
        'post_author'       => get_current_user_id(),
        'post_type'         => 'post',
        'post_status'       =>'publish',
        'post_category'     => array(pinim()->root_category_id),
    );

    return apply_filters('pai_blank_post',$blank_post);
}


?>
