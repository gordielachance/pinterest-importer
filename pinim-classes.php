<?php

class Pinim_Board_Item{

    var $board_id;
    var $username;

    var $datas = array();
    var $options = array(); //db stored options
    var $raw_pins = array();
    var $needs_refresh = true;

    //options
    protected $options_default = array(
        'private'       => null,
        'categories'    => null,
        'last_pin_cached' => null,
    );

    function __construct($username = null, $boardname = null){
        
        if ($username && $boardname){
            if ( $datas = pinim()->bot->boards->info($username, $boardname) ){
                $this->populate_datas($datas);
            }
            
        }

    }

    function populate_datas($datas){
        $this->datas = (array)$datas;
        
        //basic data
        $this->board_id = $this->get_datas( 'id' );
        $this->username = $this->get_datas( array('owner','username') );

        $this->populate_cached_raw_pins();
        
        /*
        if ($this->board_id == 153474368490695084){
            $this->get_options();
            print_r(json_encode($this));
            die();
        }
        */

    }

    function get_options($key = null){
        
        if (!$this->options){
            $boards_options = pinim_get_boards_options();

            if (!$boards_options) return null;
            
            //keep only our board
            $board_id = $this->board_id;
            $board_options = array_filter(
                (array)$boards_options,
                function ($e) use ($board_id) {
                    return ($e['board_id'] == $board_id);
                }
            );  

            $options = array_shift($board_options); //get first one only
            unset( $options['board_id'] ); //that one was stored only to retrieve the options set, no need to populate it.
            
            $this->options = wp_parse_args((array)$options,$this->options_default);

        }

        if (!isset($key)) return $this->options;
        if (!isset($this->options[$key])) return null;
        return $this->options[$key];

    }

    function save_options(){
        
        $updated = $this->options;
        $updated['board_id'] = $this->board_id;
        
        $boards_options = (array)pinim_get_boards_options();
        $boards_options[] = $updated;
        $boards_options = array_filter($boards_options);

        if ($success = update_user_meta( get_current_user_id(), pinim()->meta_name_user_boards_options, $boards_options)){
            pinim()->user_boards_options = $boards_options; //force reload
            return $this->options;
        }else{
            return new WP_Error( 'pinim', sprintf(__( 'Error while saving settings for board "%1$s"', 'pinim' ),$this->get_datas('name')));
        }

    }
    
    function save_form($form_data){

        //private
        $this->options['private'] = ( isset($form_data['private']) );

        //custom category
        if ( isset($form_data['categories']) && ($form_data['categories']=='custom') && isset($form_data['category_custom']) && get_term_by('id', $form_data['category_custom'], 'category') ){ //custom cat
            $this->options['categories'] = $form_data['category_custom'];
        }

        //save
        return $this->save_options();

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
    
    /*
     * Get data from Pinterest
     */
    
    function get_datas($keys = null){
        return pinim_get_array_value($keys, $this->datas);
    }

    /*
    This is heavy computing, so cache this. //TOUFIX TO CHECK ?
    */
    
    function get_count_cached_pins_imported(){

        $cached_pins = (array)$this->raw_pins;
        if ( empty($cached_pins) ) return false;

        $cache_key = sprintf('board-%s-imported-pins',$this->board_id);
        $imported = wp_cache_get( $cache_key, 'pinim' );
        
        if ( false === $imported ) {
            $imported = 0;
            
            $existing_pins = pinim()->get_processed_pin_ids();

            foreach ($cached_pins as $raw_pin){
                if ( in_array( $raw_pin['id'],$existing_pins ) ) $imported++;
            }
            wp_cache_set( $cache_key, $imported, 'pinim' ); //TOUFIX should not be in session ???
        }
        
        return $imported;
    }
    
    function get_count_cached_pins_importable(){
        $imported = $this->get_count_cached_pins_imported();
        $total_cached_pins  = count($this->raw_pins);
        return ($total_cached_pins - $imported);
    }
    
    function get_pc_cached_pins_imported(){
        $percent = 0;
        $count = $this->get_count_cached_pins_imported();
        $total_cached_pins  = count($this->raw_pins);
        if ($total_cached_pins){
            $percent = $count / $total_cached_pins * 100;
        }
        
        if ($percent > 100) $percent = 100;
        
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

    //TO FIX TO CHECK
    //should compare with pin ids ?
    function is_fully_imported(){
        
        $imported = false;

        if ( $this->raw_pins && ( $this->get_count_cached_pins_imported() >= count( $this->raw_pins ) ) ){
            $imported = true;
        }

        return $imported;

    }
    
    function get_cached_pin_ids(){
        $ids = array();
        foreach((array)$this->raw_pins as $pin){
            $ids[] = $pin['id'];
        }
        return $ids;
    }
    
    private function populate_cached_raw_pins(){
        $data = $this->read_pins_cache();
        if ( is_wp_error($data) ){
            add_settings_error('feedback_boards','loading_pins_cache',sprintf('Error while loading the pins cache for board %s: %s',$this->board_id,$data->get_error_message()),'inline');
        }else{
            $this->raw_pins = $data;
        }

        //updates needs refresh
        $total_pins  = $this->get_datas('pin_count');
        if ( count($this->raw_pins) >= $total_pins){
            $this->needs_refresh = false;
        }
    }
    
    /*
    Get the time the cache was updated
    */
    function get_cache_timestamp(){
        $json_file = $this->get_board_raw_pins_log_path();
        if ( !file_exists($json_file) ) return;
        $date = filectime($json_file);
        return strtotime($date);
    }
    
    function read_pins_cache(){
        $json_file = $this->get_board_raw_pins_log_path();
        if ( !file_exists($json_file) ) return;
        
        try{
            $handle = fopen($json_file, 'r');
            $json_data = fread($handle,filesize($json_file));
            fclose($handle);
        } catch ( Exception $e ) {
            return new WP_Error('load_cached_raw_pins',sprintf(__("Unable to load the cached file '%s'",'pinim'),$json_file));
        }
        
        if ( $data = json_decode($json_data,true) ){
            return $data;
        }else{
            return new WP_Error('load_cached_raw_pins',sprintf(__("Unable to decode the JSON file '%s'",'pinim'),$json_file));
        }
    }
    
    private function write_pins_cache(){
        //custom log
        if ( !$json_file = $this->get_board_raw_pins_log_path() ) return;

        $json_data = json_encode($this->raw_pins);
        
        try{
            $handle = fopen($json_file, 'w');
            flock($handle, LOCK_EX);
            fwrite($handle, $json_data);
            flock($handle, LOCK_UN);
            fclose($handle);

        } catch ( Exception $e ) {
            $error_msg = sprintf(__("Unable to write the cached file '%s'",'pinim'),$json_file);
            pinim()->debug_log($error_msg,sprintf('board #%s',$this->board_id));
            return new WP_Error('load_cached_raw_pins',$error_msg);
        }
        
        return true;

    }
    
    function delete_pins_cache(){
        if ( !$json_file = $this->get_board_raw_pins_log_path() ) return;
        if ( !file_exists($json_file) ) return;
        
        unlink($json_file);
        $this->raw_pins = array();
        
        pinim()->debug_log(sprintf('deleted cache file %s',$json_file),sprintf('board #%s',$this->board_id));
    }
    
    function get_board_raw_pins_log_path(){
        $log_dir = pinim()->get_uploads_dir();
        return $log_dir . sprintf('%s.json',$this->board_id);
    }
    
    function delete_board_raw_pins_log(){
        if ( !$log_file = $this->get_board_raw_pins_log_path() ) return;
        wp_delete_file($log_file);
    }
    
    /*
    Get the raw pins from Pinterest.
    As we get the newest pin first on Pinterest, process the last page first.
    If a cache exist, don't get the last XX pages.
    */
    
    function get_pins(){

        pinim()->debug_log('get_pins...',sprintf('board #%s',$this->board_id));
        
        $total_pins = $this->get_datas('pin_count');
        $limit = pinim()->get_options('pagination_limit');
        $total_pages = ceil($total_pins / $limit);

        $cached_pin_ids = $this->get_cached_pin_ids();
        $pin_offset = count($cached_pin_ids);
        $page_offset = floor($pin_offset / $limit);
        
        if ($pin_offset >= $total_pins){
            $this->needs_refresh = false;
            pinim()->debug_log('cache full, no need to get pins.',sprintf('board #%s',$this->board_id));
            return;
        }
        
        $current_page = $total_pages - $page_offset;  //we start at the last page
        
        if ($page_offset){
            pinim()->debug_log(sprintf('(%s pages of cache already exists (%s pins) and will be ignored.',$page_offset,$page_offset*$limit),sprintf('board #%s',$this->board_id));
        }
        
        while( $current_page > 0 ){
            
            $new_pins = $this->get_pins_page($current_page);

            if ( is_wp_error($new_pins) ){
                pinim()->debug_log($new_pins->get_error_message(),sprintf('board #%s',$this->board_id));
                break;
            }

            //eventually remove pins that are already populated in cache
            $duplicate_pin_count = 0;
            foreach((array)$new_pins as $key=>$pin){
                if (!in_array($pin['id'],$cached_pin_ids)) continue;
                unset($new_pins[$key]);
                $duplicate_pin_count+=1;
            }

            if ($duplicate_pin_count){
                pinim()->debug_log(sprintf('(ignored %s duplicate pins)',$duplicate_pin_count),sprintf('board #%s',$this->board_id));
            }

            //add new pins at the beginning
            $this->raw_pins = array_merge((array)$new_pins,((array)$this->raw_pins));
            
            //update pins cache at each slice (in case the script crashes)
            $success = $this->write_pins_cache();
            if ( is_wp_error($success) ) break;

            $current_page -= 1;
            
        }

        $this->needs_refresh = false;
        pinim()->debug_log(sprintf('get_pins... completed, %s pins in cache',count($this->raw_pins)),sprintf('board #%s',$this->board_id));
                                 
    }

    private function get_pins_page($page=1){
        
        //private boards requires to be logged
        if ( $this->is_private_board() ){
            $logged = pinim_account()->do_pinterest_auth();
            if (is_wp_error($logged) ) return $logged;
        }
        
        //pagination
        $limit = pinim()->get_options('pagination_limit');
        $total_pins = $this->get_datas('pin_count');
        $total_pages = ceil($total_pins / $limit);
        $offset = ($page - 1) * $limit;
        
        if ( $page > $total_pages ){
            return new WP_Error('empty_pins_slice',sprintf('cannot fetch page %s/%s',$page,$total_pages));
        }

        pinim()->debug_log(sprintf('getting pins page %s/%s',$page,$total_pages),sprintf('board #%s',$this->board_id));
        $slice = pinim()->bot->boards->pins($this->board_id)->take($limit)->skip($offset)->toArray();
        
        //keep only pins (not stories or ads or...)
        $slice = array_filter($slice, function($pin){
            return ($pin['type'] == 'pin');
        });
        
        if ( empty($slice) ){
            return new WP_Error('empty_pins_slice','Empty pins slice');
        }
        
        return $slice;
        
    }
    
    function get_pinterest_board_url(){
        $url = $this->get_datas( array('url') );
        $url = pinim()->pinterest_url . $url;
        return $url;
    }
    
    function get_xml_cache_datas(){
        if ( !$json_file = $this->get_board_raw_pins_log_path() ) return;
        
        $datas = array();
        
        foreach((array)$this->raw_pins as $pin){
            $xml_pin = array(
                'id' =>             pinim_get_array_value('id',$pin),
                'title' =>          pinim_get_array_value('grid_title',$pin),
                'description' =>    pinim_get_array_value('grid_description',$pin),
                'link' =>           pinim_get_array_value('link',$pin),
                'image' =>          pinim_get_array_value(array('images','orig','url'),$pin),
                'date' =>           pinim_get_array_value('created_at',$pin),
                'board' =>          pinim_get_array_value(array('board','url'),$pin),
                'pinner' =>         pinim_get_array_value(array('pinner','username'),$pin),
            );
            $datas[] = apply_filters('pinim_pin_xml',$xml_pin,$pin);
        }
        
        return $datas;
    }
    
    function cache_to_xml(){
        
        if ( !$xml_pins = $this->get_xml_cache_datas() ) return;
        
        $domtree = new DOMDocument('1.0', 'UTF-8');
        $domtree->formatOutput = true;
        $xmlRoot = $domtree->createElement("xml");
        $xmlRoot = $domtree->appendChild($xmlRoot);
        
        //create and append other elements
        foreach((array)$xml_pins as $xml_pin){
            $pin = $domtree->createElement('pin');

            foreach ($xml_pin as $key => $val) {
                $info     = $domtree->createElement($key);
                $info_data = $domtree->createTextNode($val);
                $info->appendChild($info_data);
                
                $pin->appendChild($info);
            }

            $domtree->appendChild($pin);
        }
        
        
        //save it to a file using a dialog box
        $file_name = sprintf('board-%s-%s.xml',$this->board_id,$this->get_cache_timestamp());
        header('Content-Disposition: attachment;filename=' . $file_name);
        header('Content-Type: text/xml');
        ob_clean();
        flush();
        echo $domtree->saveXML();
        exit;
    }

}

class Pinim_Pending_Pin{
    
    var $pin_id;
    var $board_id;
    
    var $datas;
    var $board;
    var $options;
    var $post; //wp post
    
    
    function __construct($data_or_id = null){
        
        if($data_or_id){
            if ( is_array($data_or_id) ) { //obj
                $this->populate_datas($data_or_id);
            }else{ //id
                if ( $datas = $this->get_raw_pin($data_or_id) ){
                    $this->populate_datas($datas);
                }
                
            }
        }
        
    }
    
    function populate_datas($datas){
        $this->datas = $datas;
        $this->pin_id = $this->get_datas(array('id'));
        $this->board_id = $this->get_datas(array('board','id'));
    }

    function get_board(){

        //load boards
        //TOUFIX this is slowing the whole plugin
        $boards = pinim_boards()->get_boards();

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
     * Get raw datas for the pin
     */
    private static function get_raw_pin($pin_id){

        $all_pins = pinim_pending_imports()->get_all_raw_pins();
        
        //remove all but ours
        $pins = array_filter(
            (array)$all_pins,
            function ($e) use ($pin_id) {
                return $e['id'] == $pin_id;
            }
        );  

        if (empty($pins)) return false;
        $pin = array_shift($pins);

        return $pin;
    }
    
    
    function get_datas($keys = null){
        return pinim_get_array_value($keys, $this->datas);
    }
    
    /*
     * Get post saved on Wordpress
     */
    
    function get_post(){

        if (!$this->post){
            if ($post_id = self::get_post_by_pin_id($this->pin_id)){
                $this->post = get_post($post_id);
                $datas = pinim_get_pin_log($this->post->ID);
                $this->populate_datas($datas);
            }
        }

        return $this->post;
    }
    
    /**
     * Checks if a pin ID already has been imported
     * @param type $pin_id
     * @return boolean
     */

    static function get_post_by_pin_id($pin_id){
        $query_args = array(
            'post_type'         => pinim()->pin_post_type,
            'post_status'       => array('publish','pending','draft','future','private'),
            'meta_query'        => array(
                array(
                    'key'     => '_pinterest-pin_id',
                    'value'   => $pin_id,
                    'compare' => '='
                )
            ),
            'posts_per_page'    => 1
        );
        $query = new WP_Query($query_args);
        if (!$query->have_posts()) return false;
        return $query->posts[0]->ID;
    }

    function get_link_action_import(){
        //Refresh cache
        $link_args = array(
            'page'      => 'pending-importation',
            'action'    => 'import_pin',
            'pin_id'  => $this->pin_id,
            //'paged'     => ( isset($_REQUEST['paged']) ? $_REQUEST['paged'] : null),
        );

        $link = sprintf(
            '<a href="%1$s">%2$s</a>',
            pinim_get_menu_url($link_args),
            __('Import pin','pinim')

        );

        return $link;
    }

    function get_post_tags(){
        $tags = array();
        if ($description = $this->get_datas('description')){
            $tags = pinim_get_hashtags($description);
        }
        return $tags;
    }
    
    function get_post_content(){
        $content = null;
        if ($content = $this->get_datas('description')){
            $tags = pinim_get_hashtags($content);
            foreach ((array)$tags as $tag){
                $content = str_replace('#'.$tag,'',$content); //remove tags here
            }
        }
        return $content;
    }
    
    function get_post_title(){
        
        $title = $this->get_datas('title');

        if (!$title = $this->get_datas('title')){
            if ( $content = $this->get_post_content() ){
                $title = wp_trim_words( $content, 30, '...' );
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

    function append_medias(){
        $post_format = get_post_format( $this->post->ID );
        $link = $this->get_datas('link');
        $domain = $this->get_datas('domain');
        $content = null;

        switch($post_format){

            case 'image':
                $thumb = get_the_post_thumbnail($this->post->ID,'full');
                $content =sprintf('<a href="%s" title="%s">%s</a>',$link,the_title_attribute('echo=0'),$thumb);
                
            break;

            case 'video':
                //https://codex.wordpress.org/Embeds
                $content = $link;

            break;
        }
        
        return sprintf("\n%s\n",$content);//wrap into linke breaks (avoid problems with embeds)
    }
    
    function get_source_link(){
        if ( $link = $this->get_datas('link') ){ //ignore if uploaded by user
            $domain = $this->get_datas('domain');
            $link_el = sprintf(__('Source: <a href="%1$s" target="_blank">%2$s</a>','pinim'),$link,$domain);
            return sprintf('<p class="pinim-pin-source">%s</p>',$link_el);
        }
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
    
    static function get_allowed_stati(){
        //$stati = get_post_stati();
        
        $stati = array(
            'publish'   => __('publish'),
            'pending'   => __('pending'),
            'draft'     => __('draft')
        );

        return $stati;
    }
    
    function save($is_update=false){
        
        $post = array();

        $error = new WP_Error();
        $datas = $this->get_datas();
        $board = $this->get_board();
        
        if (!$is_update){
            
            //create a new pin BUT with a 'auto-draft' status.
            //This will be switched when post is updated below.
            $post = array(
                'post_status'       => 'auto-draft',
                'post_author'       => get_current_user_id(),
                'post_type'         => pinim()->pin_post_type,
                'post_category'     => array(pinim_get_root_category_id()),
                'tags_input'        => array()
            );
            
            
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
        
        //set content
        //we will add our custom content (embed image or video) later with Pinim_Pending_Pin::append_medias()
        $post['post_content'] = $this->get_post_content();
        
        //set tags
        $tags_input = array();
        if (isset($post['tags_input'])){
            $tags_input = $post['tags_input'];
        }
        $post['tags_input'] = array_merge( $tags_input,$this->get_post_tags() );

        //set private post status
        if ( pinim()->get_options('can_autoprivate') && $board->is_private_board() ){
            $post['post_status'] = 'private';
        }

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

        
        //populate the post.  Can't use Pinim_Pending_Pin::get_post() here since the pin ID as not been stored yet.
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
            
                if ($is_update){ //delete old thumb
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

        //finalize post
        $update_post = array();
        $update_post['ID'] = $this->post->ID;
        $update_post['post_content'] = $this->post->post_content.$this->append_medias()."\n".$this->get_source_link(); //set post content

        if (!$is_update){ //new pin
            $update_post['post_status'] = pinim()->get_options('default_status');
        }
        
        $update_post = apply_filters('pinim_before_save_pin',$update_post,$this,$is_update); //allow to filter

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
    
    function get_pinterest_pin_url(){
        $url = sprintf('/pin/%s',$this->pin_id);
        $url = pinim()->pinterest_url . $url;
        return $url;
    }
    
}


if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
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
            'pinterest'  => sprintf('<a href="%1$s" target="_blank">%2$s</a>',$board->get_pinterest_board_url(),__('View on Pinterest','pinim'),'view'),
        );
        
        $board_name  = $board->get_datas('name');
        
        if ( $board->is_private_board() ){
            $board_name .= ' <i class="fa fa-key" aria-hidden="true"></i>';
        }

        $title = sprintf('%s <span class="item-id">(id:%s)</span>',$board_name,$board->board_id);

        
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
    
    function column_username($board){
        return $board->username;
    }
    
    function column_cache($board){

        $output = null;
        $total_pins_count  = $board->get_datas('pin_count');
        $cache_pins_count = count($board->raw_pins);
        
        //build cache bt
        $build_bt_class = array('button');
        if ( !$board->needs_refresh ){
            $build_bt_class[] = 'disabled';
        }
        
        $build_bt = pinim_get_menu_url(
            array(
                'page'      => 'boards',
                'action'    => 'build_board_cache',
                'board_id' => $board->board_id
            )
        );

        $output .= sprintf('<p><a class="%s" href="%s">%s</a></p>',implode(' ',$build_bt_class),$build_bt,__('Build','pinim'));
        
        //clear cache bt
        $clear_bt_class = array('button');

        if ( !$board->raw_pins ){
            $clear_bt_class[] = 'disabled';
        }
        
        $clear_bt = pinim_get_menu_url(
            array(
                'page'      => 'boards',
                'action'    => 'clear_board_cache',
                'board_id' => $board->board_id
            )
        );

        $output .= sprintf('<p><a class="%s" href="%s">%s</a></p>',implode(' ',$clear_bt_class),$clear_bt,__('Clear','pinim'));
        
        //XML output bt
        $xml_bt_class = array('button');

        if ( !$board->raw_pins ){
            $xml_bt_class[] = 'disabled';
        }
        
        $xml_bt = pinim_get_menu_url(
            array(
                'page'      => 'boards',
                'action'    => 'export_board_cache',
                'board_id' => $board->board_id
            )
        );

        $output .= sprintf('<p><a class="%s" href="%s">%s</a></p>',implode(' ',$xml_bt_class),$xml_bt,__('Get XML','pinim'));
        
        //cache status
        $pin_cache_txt = sprintf( _n( '%s/%s cached pin', '%s/%s cached pins', $cache_pins_count,'pinim' ), $cache_pins_count,$total_pins_count);
        $output .= sprintf('<small>%s</small>',$pin_cache_txt);

        return $output;
        
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
    
    function column_board_thumbnail($board){

        if ( !$image = $board->get_datas('image_cover_url') ) return;
        return sprintf(
            '<img src="%1$s" class="img-cover"/>',
            $image
        );
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
            'depth'         => 20, //aTO FIX better value here ?
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
    
    function column_cached_pins_imported($board){
        
        $output = null;
        $percent = 0;
        
        $total_pins = count($board->raw_pins);
        
        if ( $total_pins ){
            
            $imported_pins = $board->get_count_cached_pins_imported();

            $pc_status_classes = array('pinim-pc-bar');
            $text_bar = $bar_width = null;

            $percent = $board->get_pc_cached_pins_imported();
            $percent = floor($percent);

            $bar_width = $percent;

            $text_bar = sprintf('%s/%s',$imported_pins,$total_pins);
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

            if ( !$board->needs_refresh ){ //pins cache totally loaded
                if ($percent<50){
                    $pc_status_classes[] = 'color-light';
                }
            }


            $pc_status_classes = pinim_get_classes_attr($pc_status_classes);
            $red_opacity = (100 - $percent) / 100;
            
            $text_bar_el = sprintf('<span class="pinim-pc-bar-text">%s</span>',$text_bar);
            $yellow_bar_el = '<span class="pinim-pc-bar-fill-color pinim-pc-bar-fill-yellow"></span>';
            $red_bar_el = sprintf('<span class="pinim-pc-bar-fill-color pinim-pc-bar-fill-red" style="opacity:%s"></span>',$red_opacity);
            $bar_fill = sprintf('<span class="pinim-pc-bar-fill" style="width:%s">%s%s%s',$bar_width.'%',$yellow_bar_el,$red_bar_el,$text_bar_el);
            $bar_wrapper = sprintf('<p><span %s>%s</span></p>',$pc_status_classes,$bar_fill);
            
            $output .= $bar_wrapper;
            
        }
        
        //import pins bt

        $import_bt_class = array('button');
        $import_bt_txt = __('Import','pinim');
        if ( $board->needs_refresh || $board->is_fully_imported() ){
            $import_bt_class[] = 'disabled';
        }else{
            //$remaining_count = $board->get_count_cached_pins_importable();
            //$import_bt_txt = sprintf( _n( 'Import %s pin', 'Import %s pins', $remaining_count,'pinim' ), $remaining_count );
        }

        $import_bt = pinim_get_menu_url(
            array(
                'page'      => 'pending-importation',
                'action'    => 'import_board_pins',
                'board_id'  => $board->board_id
            )
        );

        $output .= sprintf('<p><a class="%s" href="%s">%s</a></p>',implode(' ',$import_bt_class),$import_bt,$import_bt_txt);
        

        return $output;

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
            'board_thumbnail'       => '',
            'title'                 => __('Board Title','pinim'),
            'category'              => __('Category','pinim'),
            'private'               => __('Private','pinim'),
            'cache'                 => __('Cache','pinim'),
            'cached_pins_imported'    => __('Status','pinim')
        );
        
        //should we display the usernames column ?
        $board_usernames = array();
        
        foreach((array)$this->input_data as $board){
            $board_usernames[] = $board->get_datas(array('owner','username'));
        }
        
        $board_usernames = array_unique($board_usernames);

        if (count($board_usernames) > 1){
            $columns['username'] = __('Username','pinim');
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
            'private'               => array('private',false),
            'pin_count_remote'      => array('pin_count_remote',false),
            'cached_pins_imported'    => array('cached_pins_imported',false),
            'username'              => array('username',false),
            
        );
        
        //do not allow to sort 'cached_pins_imported' as it should force to populate the pins from all boards to work

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
            //TOUFIX
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

        $link_all = $link_user = $link_cached = $link_not_cached = null;
        $link_all_classes = $link_user_classes = $link_cached_classes = $link_not_cached_classes = $link_followed_classes = array();
        
        $all_boards = pinim_boards()->get_boards();
        
        if ( is_wp_error($all_boards) ){
            $all_boards = array(); //reset it or it will count errors
        }
        
        $all_count = count($all_boards);
        $user_count = count(pinim_boards()->filter_boards($all_boards,'user'));
        $cached_count = count(pinim_boards()->filter_boards($all_boards,'cached'));
        $not_cached_count = count(pinim_boards()->filter_boards($all_boards,'not_cached'));
        $followed_count = count(pinim_boards()->filter_boards($all_boards,'followed'));

        switch (pinim_boards()->get_screen_boards_filter()){
            case 'all':
                $link_all_classes[] = 'current';
            break;
            case 'user':
                $link_user_classes[] = 'current';
            break;
            case 'cached':
                $link_cached_classes[] = 'current';
            break;
            case 'not_cached':
                $link_not_cached_classes[] = 'current';
            break;
            case 'followed':
                $link_followed_classes[] = 'current';
            break;
        }
        
        $link_all = sprintf(
            __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
            pinim_get_menu_url(
                array(
                    'page'          => 'boards',
                    'boards_filter' =>'all'
                )
            ),
            pinim_get_classes_attr($link_all_classes),
            __('All','pinim'),
            $all_count
        );
        
        $link_user = sprintf(
            __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
            pinim_get_menu_url(
                array(
                    'page'          => 'boards',
                    'boards_filter' => 'user'
                )
            ),
            pinim_get_classes_attr($link_user_classes),
            __('My boards','pinim'),
            $user_count
        );

        $link_cached = sprintf(
            __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
            pinim_get_menu_url(
                array(
                    'page'          => 'boards',
                    'boards_filter' => 'cached'
                )
            ),
            pinim_get_classes_attr($link_cached_classes),
            __('Cached','pinim'),
            $cached_count
        );

        $link_not_cached = sprintf(
            __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
            pinim_get_menu_url(
                array(
                    'page'          => 'boards',
                    'boards_filter' => 'not_cached'
                )
            ),
            pinim_get_classes_attr($link_not_cached_classes),
            __('Not cached','pinim'),
            $not_cached_count
        );

        $link_followed = sprintf(
            __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
            pinim_get_menu_url(
                array(
                    'page'          => 'boards',
                    'boards_filter' => 'followed'
                )
            ),
            pinim_get_classes_attr($link_followed_classes),
            __('Followed boards','pinim'),
            $followed_count
        );

        $links = array(
            'all'           => $link_all,
            'cached'        => $link_cached,
            'not_cached'    => $link_not_cached
        );
        
        if ( (pinim()->get_options('enable_followed')=='on') && $followed_count ){
            $links['user'] = $link_user;
            $links['followed'] = $link_followed;
        }

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

        switch (pinim_boards()->get_boards_layout()){
            case 'simple':
                $link_simple_classes[] = 'current';
            break;
            case 'advanced':
                $link_advanced_classes[] = 'current';
            break;
        }
        
        $boards_filter = pinim_boards()->get_screen_boards_filter();

        $link_simple = sprintf(
            __('<a href="%1$s"%2$s>%3$s</a>'),
            pinim_get_menu_url(
                array(
                    'page'                  => 'boards',
                    'boards_layout'    => 'simple'
                )
            ),
            pinim_get_classes_attr($link_simple_classes),
            __('Simple','pinim')
        );

        $link_advanced = sprintf(
            __('<a href="%1$s"%2$s>%3$s</a>'),
            pinim_get_menu_url(
                array(
                    'page'                  => 'boards',
                    'boards_layout'    =>'advanced'
                )
            ),
            pinim_get_classes_attr($link_advanced_classes),
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

            echo '<ul id="boards_layout" class="view_filter subsubsub">'."\n";
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
            'bulk_build_board_cache'    => __('Build Cache','pinim'),
            'bulk_save_board_settings'  => __('Save Settings','pinim')
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

            $orderby = 'title';
            $order = 'desc';

            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : $orderby;
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : $order;
            
            switch ($orderby){
                case 'title':
                    $result = strcmp($a->get_datas('name'), $b->get_datas('name'));
                break;
                case 'private':
                    $result = $a->is_private_board() - $b->is_private_board();
                break;
                case 'pin_count_remote':
                    $result = $a->get_datas('pin_count') - $b->get_datas('pin_count');
                break;
                case 'cached_pins_imported':
                    $result = $a->get_pc_cached_pins_imported() - $b->get_pc_cached_pins_imported();
                break;
                case 'username':
                    $result = $a->username  - $b->username;
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

class Pinim_Pins_Table extends WP_List_Table {
    
    var $input_data = array();
    var $pin_idx = -1;

    var $orderby = 'date';
    var $order = 'desc';

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

    function get_actions($pin){
        
        $pin_id = $this->get_pin_id($pin);
        
        return array(
            'pinterest'     => sprintf('<a href="%1$s" target="_blank">%2$s</a>',$pin->get_pinterest_pin_url(),__('View on Pinterest','pinim'),'view'),
            'import'        => $pin->get_link_action_import()
        );
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
            'cb'            => '<input type="checkbox" />', //Render a checkbox instead of text
            'pin_thumbnail' => '',
            'title'         => __('Pin Title','pinim'),
            'pin_source'    => __('Source','pinim'),
            'date'          => __('Date','pinim'),
            'pin_board'     => __('Board')
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
            'title'             => array('title',false),
            'source'             => array('source',false),
            'date'              => array('date',true),     //true means it's already sorted
            'board'      => array('board',false),
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
            //Import All Pins

            $link_args = array(
                'page'      => 'pending-importation',
                'action'    => 'import_pin'
            );
            
            printf(
                '<a class="button" href="%1$s">%2$s</a>',
                pinim_get_menu_url($link_args),
                __('Import all','pinim')

            );
        }

        ?>
        </div>
        <?php
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
		return array();
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
        
        $actions['bulk_import_pins'] = __('Import pins','pinim');
        
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

           case 'pin_board':

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

           case 'pin_source':

               $source_a = $a->get_datas('domain');
               $source_b = $b->get_datas('domain');

               $result = strcmp($source_a, $source_b);
           break;

       }

       return ($this->order==='asc') ? $result : -$result; //Send final sort direction to usort
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
        return sprintf('<span%1$s></span>',pinim_get_classes_attr($icon_classes));
    }
    
    function column_pin_thumbnail($item){

        return sprintf(
            '<img src="%1$s" class="img-cover"/>',
            $this->get_thumbnail_url($item)
        );

    }
    
    function column_pin_source($pin){
        
        $text = $url = null;

        $text = $pin->get_datas('domain');
        $url = $pin->get_datas('link');
        

        if (!$text || !$url) return; //ignore if uploaded by user

        return sprintf(
            '<a target="_blank" href="%1$s">%2$s</a>',
            esc_url($url),
            $text
        );
    }
    
    function column_pin_board($pin){

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
