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