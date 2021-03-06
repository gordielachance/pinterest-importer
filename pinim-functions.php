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

function pinim_array_keys_exists($keys = null, $array){
    if (!$keys) return true;
    
    $keys = (array)$keys;
    $first_key = $keys[0];
    if(count($keys) > 1) {
        if ( isset($array[$keys[0]]) ){
            return pinim_array_keys_exists(array_slice($keys, 1), $array[$keys[0]]);
        }
    }elseif (isset($array[$first_key])){
        return true;
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

/**
* Make a nested HTML list from a multi-dimensionnal array.
*/

function pinim_get_list_from_array($input,$parent_slugs=array() ){
    
    $output = null;
    $output_classes = array("pure-tree");
    if ( empty($parent_slugs) ){
        $output_classes[] =  'main-tree';
    }
    
    
   foreach($input as $key=>$value){
        
       //if (!$value) continue; //ignore empty values
       
        $data_attr = $label = null;
        $checkbox_classes = array("checkbox-tree-checkbox");
        $item_classes = array("checkbox-tree-item");
       
        if( is_array($value) ){
            $parent_slugs[] = $key;
            $li_value = pinim_get_list_from_array($value,$parent_slugs);

            $item_classes[] = 'checkbox-tree-parent';
        }else{
            $li_value = $value;
        }
       
       if (!$li_value) continue;

        $u_key = implode('-',$parent_slugs);
        $data_attr = sprintf(' data-array-key="%s"',$key);

        $checkbox_classes_str = pinim_get_classes_attr($checkbox_classes);
        $item_classes_str = pinim_get_classes_attr($item_classes);
        $checkbox = sprintf('<input type="checkbox" %1$s id="%2$s"><label for="%2$s" class="checkbox-tree-icon">%3$s</label>',$checkbox_classes_str,$u_key,$key);

        $output.= sprintf('<li%1$s%2$s>%3$s%4$s</li>',$item_classes_str,$data_attr,$checkbox,$li_value);
    }
    
    if ($output){
        $output_classes_str = pinim_get_classes_attr($output_classes);
        return sprintf('<ul %s>%s</ul>',$output_classes_str,$output);
    }

}

function pinim_get_pin_capability($cap = 'edit_posts'){
    $post_type_obj = get_post_type_object( pinim()->pin_post_type );
    return $post_type_obj->cap->$cap;
}

//https://stackoverflow.com/questions/9546181/flatten-multidimensional-array-concatenating-keys
function pinim_array_flatten($array, $prefix = '', $delimiter = '.') {
    $result = array();
    foreach($array as $key=>$value) {
        if(is_array($value)) {
            $result = $result + pinim_array_flatten($value, $prefix . $key . $delimiter);
        }
        else {
            $result[$prefix.$key] = $value;
        }
    }
    return $result;
}