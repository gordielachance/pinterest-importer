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
 * Checks if a pin ID already has been importer
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

    $term_exists = term_exists($term_name,$term_tax,$term_args['parent']);
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

function pai_get_pin_url($pin_id){
    $pin_url = sprintf('http://www.pinterest.com/pin/%s',$pin_id);
    return $pin_url;
}


?>
