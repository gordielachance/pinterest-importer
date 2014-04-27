<?php

/**
 * Pinterest Importer class for managing the import process of a HTML file
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( !class_exists( 'WP_Importer' ) ) return false;

class Pinim_Pin {
    var $pin_id;
    var $description;
    var $board;
    var $format;
    var $source;
    var $attribution;
    var $domain;
    var $hashtags;
    var $thumb;
    var $pinner;
    
    function __construct($id){
        $this->pin_id = $id;
    }
    
    /**
     * Get more data from the pin by loading its page
     */

    function load_single_pin_html(){
            //populate pin HTML
            $pin_url = pinim_get_pin_url($this->pin_id);
            $pin_doc = wp_remote_get( $pin_url );

            if(isset($pin_doc['body'])){
                $pin_html = phpQuery::newDocumentHTML($pin_doc['body']);
            }

            if(!isset($pin_html)){
                return new WP_Error( 'phpQuery_parse_error', __( 'There was an error when reading this HTML file', 'wordpress-importer' ));
            }
            
            //TO FIX TO CHECK
            //if 404, abord
            
            $data = array(
                'thumb'     => $this->get_featured_image_url($pin_html),//thumbnail
                'source'    => $this->get_source_url($pin_html),//source
                'pinner'    => $this->get_pinner($pin_html),//pinner
            );
            
            $data = array_filter($data); //remove empty values
            
            foreach((array)$data as $prop=>$value){
                $this->$prop = $value;
            }
            

    }
    
    function get_title(){
        $title = sprintf(__('Pin #%1s','pinim'),$this->pin_id);
        $description = trim($this->description);
        if (!$description) return $title;
        
        $title = $this->description;
        //remove hashtags
        $tags = self::get_hashtags();
        
        foreach ((array)$tags as $tag){
            $title = str_replace('#'.$tag,'',$title);
        }
        $title = trim($title);

            
        return $title;
        
    }
    
    function get_hashtags(){
        $string = trim($this->description);
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
    
    function get_featured_image_url( $pin_html ) {
             phpQuery::selectDocument($pin_html);

            //default thumbnail url, from the document metas.
            $featured_image = pq($pin_html)->find('meta[property=og:image]')->attr('content');

            //check if we can get better.

            $container_link_el = pq('.paddedPinLink');
            $container_link = $container_link_el->attr('href');
            $container_content = pq($container_link_el)->find('div');

            if(pq($container_content)->hasClass('imageContainer')){ 
                if ($image = pq($container_content)->find('img.pinImage')){
                    $featured_image = $image->attr('src');
                }
            }

            //404 ERROR ? TO FIX TO CHECK
            if ($featured_image=='http://passets-ec.pinterest.com/images/about/logos/Pinterest_Favicon.png'){
                return false;
            }

            return $featured_image;
    }
    
    function get_source_url( $pin_html ) {
             phpQuery::selectDocument($pin_html);

            $source = pq($pin_html)->find('meta[property=og:see_also]')->attr('content');
            return $source;
    }

    function get_pinner( $pin_html ) {
        phpQuery::selectDocument($pin_html);
        $url = pq($pin_html)->find('meta[name=pinterestapp:pinner]')->attr('content');
        $user = pinim_url_extract_user($url);
        return $user;
    }


    /**
    * Filter the meta keys we want to keep in the DB.
    * @param type $data
    * @return type
    */

   function get_pin_metas(){

       $save_keys = array(
           'pin_id',
           'board',
           'source',
           'pinner',
       );

       $metas = array();

       foreach ($save_keys as $key){
           if (isset($this->$key)){
               $metas[$key] = $this->$key;
           }
       }

       return apply_filters('pinim_sanitize_single_post_metas',$metas,$this);

   }
    
    
}

class PinIm_List {
    
    var $pins_list;
    
    function __construct($file){

        $this->pins_list = self::load_pins_list($file);
        
        if (is_wp_error($this->pins_list)){
            return $this->pins_list;
        }

    }
    
    function load_pins_list($file){
        
            if ( ! is_file($file) ) {
                return new WP_Error( 'invalid_file', __( 'The file does not exist, please try again.', 'wordpress-importer' ));
            }

            $html = file_get_contents($file);
            $pins_list = phpQuery::newDocumentHTML($html);

            if(!isset($pins_list)){
                return new WP_Error( 'phpQuery_parse_error', __( 'There was an error when reading this HTML file', 'wordpress-importer' ));
            }
            
            if(!self::is_valid_list($pins_list)){
                return new WP_Error( 'invalid_pins_list', __( 'This pins list is invalid', 'wordpress-importer' ));
            }

            return $pins_list;

    }
    
    function is_valid_list($pins_page){

        //TO FIX TO CHECK : find a way to be sure this page contains a list of pins
        return true;

        phpQuery::selectDocument($pins_page);
    }
    
    function fetch_pins(){

        $pins = array();
        
        phpQuery::selectDocument($this->pins_list);

        //get pins
        foreach (pq('.pinWrapper') as $pin_html) {
            
            $pin_id = self::get_pin_id($pin_html);
            if (!$pin_id) continue;
            
            $pin = new Pinim_Pin($pin_id);
            
            $data = array(
                'description' => self::get_pin_description($pin_html),
                'board' => self::get_pin_board($pin_html),
                'format' => self::get_pin_format($pin_html),
                'source' => self::get_pin_source($pin_html),
                'attribution' => self::get_pin_attribution($pin_html),
                'domain' => self::get_pin_domain($pin_html)
            );
                    
            $data = array_filter($data);
            
            foreach ((array)$data as $prop => $value){
                $pin->$prop = $value;
            }            

            $pins[] = $pin;
        }

        if (empty($pins)){
            return new WP_Error( 'phpQuery_parse_error', __( 'No pins were found', 'pinim' ));
        }

        return $pins;
    }
    
   function get_pin_id($pin_html){
        //pin ID
        $pin_url = pq($pin_html)->find('.pinImageWrapper')->attr('href');
        return pinim_url_extract_pin_id($pin_url);
    }
    
    function get_pin_description($pin_html){
        //DESCRIPTION
        $description_el = pq($pin_html)->find('.pinDescription');
        if (!$description_el->length) return false;
        $description = trim(strip_tags(pq($description_el)->htmlOuter()));
        return $description;
    }
    
   function get_pin_board($pin_html){
        //CREDITS
        $credits_el = pq($pin_html)->find('.pinCredits');
        if (!$credits_el->length) return false;

        $credit_title = pq($pin_html)->find('.creditTitle')->htmlOuter();
        $credit_title = trim(strip_tags($credit_title));
        $credits_link = pq($credits_el)->find('a.creditItem')->attr('href');
        
        if (!$credits_link) return false;

        if ($board_slug = pinim_url_extract_board_slug($credits_link)){
            return $credit_title;
        }
    }
    
    function get_pin_format($pin_html){
        //default format
        $format = 'image';

        $videtype_el = pq($pin_html)->find('.videoType');

        if ($videtype_el->length){
            $format = 'video';
        }
        
        return $format;
    }
    
    function get_pin_source($pin_html){
        //CREDITS
        $credits_el = pq($pin_html)->find('.pinCredits');
        if (!$credits_el->length) return false;

        $credit_title = pq($pin_html)->find('.creditTitle')->htmlOuter();
        $credit_title = trim(strip_tags($credit_title));
        $credits_link = pq($credits_el)->find('a.creditItem')->attr('href');
        
        if (!$credits_link) return false;

        if ($source_slug = pinim_url_extract_source_slug($credits_link)){
            return $credit_title;
        }
    }

    function get_pin_attribution($pin_html){
        //ATTRIBUTION
        $attribution_link_el = pq($pin_html)->find('.pinAttribution a');
        if (!$attribution_link_el->length) return false;

        return array(
            'link'=>$attribution_link_el->attr('href'),
            'text'=>$attribution_link_el->htmlOuter()
        );
        
    }
    
    function get_pin_domain($pin_html){
        //DOMAIN
        $domain_el = pq($pin_html)->find('.pinDomain');
        if (!$domain_el->length) return false;
        $domain = trim(strip_tags(pq($domain_el)->htmlOuter()));
        return $domain;
    }
    


}


class Pinterest_Importer extends WP_Importer {

	var $id; // HTML attachment ID

	// information to import from HTML file

	var $authors = array();
	var $posts = array();
	var $terms = array();

	// mappings from old information to new
	var $processed_authors = array();
	var $author_mapping = array();
	var $processed_terms = array();
	var $processed_posts = array();
	var $url_remap = array();
	
	var $attachments = array();
	var $pinterest_site_id = 0;
	var $auth = false;

	function Pinterest_Importer() {
            if (isset($_GET['import']) && $_GET['import'] == 'pinterest-pins'){
                add_action('admin_print_scripts', array(&$this, 'queue_scripts'));
                add_action('admin_print_styles', array(&$this, 'queue_style'));
            }
	}
	
	function WP_Import() { /* nothing */ }
	
	function queue_scripts($hook) {
	}
        
        function queue_style() {
	}

	/**
	 * Registered callback function for the WordPress Importer
	 *
	 * Manages the three separate stages of the HTML import process
	 */
	function dispatch() {
		$this->header();

		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
		switch ( $step ) {
                        case 0: //welcome screen
				$this->greet();
                        break;
                        case 1: //import pins list
				check_admin_referer( 'import-upload' );

                                set_time_limit(0); //TO FIX TO CHECK
                                $result = $this->import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
                        break;
                        case 2: //process each pin
                        break;
		}

		$this->footer();
	}

        function feedback($message){
            echo $message.'<br/>';
        }

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the HTML file for importing
	 */
	function import() {

		$file = wp_import_handle_upload();

		if ( isset($file['error']) ) {
			echo $file['error'];
			return;
		}
                
                $bid = get_current_blog_id(); //blog ID
            
		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );
 
		//$this->attachments = $this->get_imported_attachments( 'pinterest' );
		//$this->processed_posts = $this->get_imported_posts( 'pinterest', $bid ); 
		
                $this->import_start( $file );
                
                $pins = $this->raw_posts;
                $found_count = count($pins);
                $duplicates = $this->find_duplicates($pins);
                
                
                if (!empty($duplicates)){
                    $dupli_count = count($duplicates);
                    $pins = array_udiff($pins, $duplicates,
                      function ($obj_a, $obj_b) {
                        return $obj_a->pin_id - $obj_b->pin_id;
                      }
                    );
                    
                    $waiting_count = $found_count - $dupli_count;
                    
                    $message = sprintf(__('%1s pins found, %2s already have been imported. Trying to import %3s pins...','pinim'),$found_count,$dupli_count,$waiting_count);
                    $this->feedback($message);
                }
                
                $pins = array_values($pins);//reset keys
		$this->process_pins($pins);
		$this->import_end();
	}
        
        function find_duplicates($pins){
            $duplicates = array();
            foreach ((array)$pins as $pin){
                if (pinim_pin_exists($pin->pin_id)) {
                    $duplicates[] = $pin;
                }
            }
            return $duplicates;
        }
        
        function process_pins($pins){
            
            $pin_count = 0;
            $total_pins = count($pins);
            
            foreach ((array)$pins as $pin){

                $pin_count++;
                
                //feedback
                $message = "<br/><br/>".sprintf(__('%1s/%2s - Importing pin #%3s...','pinim'),$pin_count,$total_pins,'<a href="'.pinim_get_pin_url($pin->pin_id).'" target="_blank">'.$pin->pin_id.'</a>');
                $this->feedback($message);
                
                if ( $existing_post_id = pinim_pin_exists($pin->pin_id) ) { //double check !
                    $existing_post = get_post($existing_post_id);
                    $error_msg = sprintf(__('Pin already exists as post#%1s : %2s','pinim'),$existing_post_id,'<a href="'.get_permalink($existing_post_id).'" target="_blank">'.$existing_post->post_title.'</a>');
                    $error = new WP_Error('existing_pin', $error_msg);
                    $this->feedback($error->get_error_message());
                    continue;
                }

                //populate pin HTML
                $message =  __('Loading pin HTML...','pinim');
                $this->feedback($message);
                $pin_html = $pin->load_single_pin_html();
                if ( is_wp_error($pin_html) ){
                    $this->feedback($pin_html->get_error_message());
                    continue;
                }

                //create post
                $post_id = $this->save_pin($pin);
                if ( is_wp_error($post_id) ){
                    $this->feedback($post_id->get_error_message());
                    $error_code = $post_id->get_error_code();
                    $bad_post_id = $post_id->get_error_data($error_code);

                    if ($bad_post_id){ // this post has been created but should be deleted
                        $message =  sprintf(__('Deleting post #%1s', 'pinim'),$bad_post_id);
                        $this->feedback($message);
                        wp_delete_post($bad_post_id, true );
                        continue;
                    }
                }

                $new_post = get_post($post_id);
                
                //feedback
                $message =  sprintf(__('Created post#%1s : %2s','pinim'),$new_post->ID,'<a href="'.get_permalink($new_post->ID).'" target="_blank">'.$new_post->post_title.'</a>');
                $this->feedback($message);

                
            }
        }
        
        function save_pin($pin){
            $error = false;

            $post = pinim_get_blank_post();
            $post['post_title'] = $pin->get_title();
            $post['tags_input'] = array_merge($post['tags_input'],$pin->get_hashtags());
            
            //set post category
            if ($pin->board){
                if ($sub_category = pinim_get_term_id($pin->board,'category',array('parent'=>pinim()->root_category_id))){
                    $post['post_category'] = array($sub_category);
                }
            }

            $post = array_filter($post);

            //insert post
            $post_id = wp_insert_post( $post, true );
            if ( is_wp_error($post_id) ) return $post_id; //ABORD

            $new_post = get_post($post_id);

            //set post format
            if($pin->format){
                if (set_post_format( $post_id , $pin->format)){
                    //feedback
                    $message =  sprintf(__('Set post format : %2s','pinim'),$pin->format);
                    self::feedback($message);
                }
            }

            //set featured image
            if ($featured_image_id = Pinterest_Importer::process_image($new_post,$pin->thumb)){
                    set_post_thumbnail($new_post, $featured_image_id);
                    $hd_file = wp_get_attachment_image_src($featured_image_id, 'full');
                    $hd_url = $hd_file[0];
                    //feedback
                    $message =  sprintf(__('Set post thumbnail: %1s','pinim'),'<a href="'.$hd_url.'" target="_blank">'.$featured_image_id.'</a>');
                    self::feedback($message);
            }else{
                $error_msg =  sprintf(__('Error while setting post thumbnail: %1s','pinim'),'<a href="'.$pin->thumb.'" target="_blank">'.$pin->thumb.'</a>');
                $error = new WP_Error('post_thumbnail_error', $error_msg, $post_id);
                return $error;
            }

            //set post metas
            $post_metas = $pin->get_pin_metas();

            foreach ( $post_metas as $key=>$value ) {
                $key = '_pinterest-'.$key;
                add_post_meta( $post_id, $key, $value );
                do_action( 'import_post_meta', $post_id, $key, $value );
            }

            //set post content
            if (!$this->set_post_content($new_post,$pin)){
                //feedback
                $error_msg =  __('Error while updating post content', 'pinim');
                $error = new WP_Error('post_content_error', $error_msg, $post_id);
                return $error;
            }
            return $post_id;
        }

	
	/**
	 * Set array with imported attachments from WordPress database
	 *
	 * @param string $importer_name
	 * @param string $bid
	 * @return array
	 */
	function get_imported_attachments( $importer_name ) {
		global $wpdb;

		$hashtable = array ();

		// Get all attachments
		$sql = $wpdb->prepare( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '%s'", $importer_name . '_attachment' );
		$results = $wpdb->get_results( $sql );

		if (! empty( $results )) {
			foreach ( $results as $r ) {
				// Set permalinks into array
				$hashtable[$r->meta_value] = (int) $r->post_id;
			}
		}

		// unset to save memory
		unset( $results, $r );

		return $hashtable;
	}

	/**
	 * Parses the HTML file and prepares us for the task of processing parsed data
	 *
	 * @param string $file Path to the HTML file for importing
	 */
	function import_start( $file ) {
            
                $this->id = $file['id']; //uploaded html file ID
                
		$file = $file['file'];
                
                $pins_list = new PinIm_List($file);
                
                if (is_wp_error($pins_list)){
                    echo esc_html( $pins_list->get_error_message() ) . '</p>';
                    $this->footer();
                    die();
                }

                $this->raw_posts = $pins_list->fetch_pins();

		wp_defer_term_counting( true );
                wp_suspend_cache_invalidation( true );
		do_action( 'import_start' );
	}

	/**
	 * Performs post-import cleanup of files and the cache
	 */
	function import_end() {
                wp_suspend_cache_invalidation( false );

		wp_import_cleanup( $this->id );

		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
			delete_option( "{$tax}_children" );
			_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );

		echo '<p>' . __( 'All done.', 'wordpress-importer' ) . ' <a href="' . admin_url() . '">' . __( 'Have fun!', 'wordpress-importer' ) . '</a>' . '</p>';

		do_action( 'import_end' );
	}
	
	function import_finished() {
		?>
		<h3><?php _e( 'Completed Import', 'pinim' ); ?></h3>
		<p><?php _e( 'We have succesfully imported your content from Pinterest.', 'wordpress-importer' ); ?></p>
		<p><?php _e( 'Happy Blogging!', 'wordpress-importer' ); ?></p>
		<p style="text-align: center">
			<a href="https://premium.wpmudev.org/join/"><img href="<?php echo plugins_url('pinterest-importer-advanced/img/wpmudev.jpg', __FILE__); ?>" style="border:none;"/></a>
		</p>
		<?php

	}

        function set_post_content($post,$pin){
            $post_format = get_post_format( $post->ID );
            $source = pinim_get_pin_meta('source', $post->ID, true);
            $content = null;

            switch($post_format){

                case 'image':
                    $content = get_the_post_thumbnail($post->ID,'full');
                    
                    if ($source){
                        $content ='<a href="' . $source . '" title="' . the_title_attribute('echo=0') . '" >'.$content.'</a>';
                    }
                    
                break;
            
                case 'video':
                    
                    //https://codex.wordpress.org/Embeds
                    $content = $source;
                    
                break;
            }
            
            $content .= "\n";//line break (avoid problems with embeds)
            
            //allow to filter
            $content = apply_filters('pinim_get_post_content',$content,$post,$pin);

            //print_r("<xmp>".$content."</xmp>");exit;
            
            $my_post = array();
            $my_post['ID'] = $post->ID;
            $my_post['post_content'] = $content;
            
            if (!wp_update_post( $my_post )){
                return false;
            }
            return true;
        }


	/**
	 * Import and processes each attachment
	 *
	 * @param object $post
	 * @param array $fullsizes
 	 * @param array $thumbs
	 * @return void
	 */
	function process_image( $post, $image_url ) {
		
		if ( empty( $image_url ) )
			return false;
                
                $attachment_id = pinim_image_exists($image_url);
                
                if (!$attachment_id){
      
                    if( $this->is_user_over_quota() )
                            return false;


                    if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
                        echo $label.'<em>'.htmlspecialchars( $image_url ).'</em>';

                    $upload = $this->fetch_remote_image( $image_url, $post );

                    if ( is_wp_error( $upload ) ) {
                            if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
                                echo sprintf( "<em>%s</em><br />\n", __( 'Remote file error:' ) . ' ' . htmlspecialchars( $upload->get_error_message() ) )."<br/>";


                            return false;

                    } else {
                            //feedback
                            echo'<span class="pinterest-feedback">';
                            echo"<em>";
                            printf(__("Image size: %s",'pinim'), size_format( filesize( $upload['file'] ) ) );
                            echo"</em>";
                            echo"</span>";
                            echo"<br/>";
                    }


                    if ( 0 == filesize( $upload['file'] ) ) {
                            if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
                                _e( "Zero length file, deleting..." ) . "<br />\n";

                            @unlink( $upload['file'] );
                            return false;
                    }

                    $info = wp_check_filetype( $upload['file'] );

                    if ( false === $info['ext'] ) {
                            if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
                                printf( "<em>%s</em><br />\n", $upload['file'] . __( 'has an invalid file type') );

                            @unlink( $upload['file'] );
                            return false;
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
                }else{
                    //feedback
                    echo'<span class="pinterest-feedback">';
                    echo"<em>";
                    printf(__("Image already exists, link attachment #%s",'pinim'),$attachment_id);
                    echo"</em>";
                    echo"</span>";
                    echo"<br/>";
                }
                
                return $attachment_id;
	}
        

	


        


	/**
	 * Download remote file, keep track of URL map
	 *
	 * @param object $post
	 * @param string $url
	 * @return array
	 */
	function fetch_remote_image( $url, $post ) {
		global $switched, $switched_stack, $blog_id;
		
		if (count($switched_stack) == 1 && in_array($blog_id, $switched_stack))
			$switched = false;
			
		// Increase the timeout
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );
                
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

		// get placeholder file in the upload dir with a unique sanitized filename
		$upload = wp_upload_bits( $filename,null,$image['body'], $post->post_date );

		if ( $upload['error'] )
			return new WP_Error( 'upload_dir_error', $upload['error'] );

		return apply_filters( 'wp_handle_upload', $upload );
	}


	/**
	 * Parse a HTML file
	 *
	 * @param string $file Path to HTML file for parsing
	 * @return array Information gathered from the HTML file
	 */
	function parse( $file ) {
		$parser = new PinterestGridParser();
		return $parser->parse( $file );
	}

	// Display import page title
	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>' . __( 'Pinterest Importer', 'pinim' ) . '</h2>';
		
		echo '<div class="pinterest-wrap">';
		$updates = get_plugin_updates();
		$basename = plugin_basename(__FILE__);
		if ( isset( $updates[$basename] ) ) {
			$update = $updates[$basename];
			echo '<div class="error"><p><strong>';
			printf( __( 'A new version of this importer is available. Please update to version %s to ensure compatibility with newer export files.', 'wordpress-importer' ), $update->update->new_version );
			echo '</strong></p></div>';
		}
	}

	// Close div.wrap
	function footer() {
		echo '</div></div>';
	}

	/**
	 * Display introductory text and file upload form
	 */
	function greet() {
		echo '<div class="narrow">';
                echo '<p>'.__("Howdy! Wanna backup your Pinterest profile ?  Here's how to do.",'pinim').'<br/>';
                echo __("You can run this plugin several time as it won't save twice the same pin.",'pinim').'</p>';
                echo '<h3>'.__('Save and upload your pins page','pinim').'</h3>';
		echo '<p><ol><li>'.sprintf(__("Login to %1s and head to your pins page, which url should be %2s.", 'pinim' ),'<a href="http://www.pinterest.com" target="_blank">Pinterest.com</a>','<code>http://www.pinterest.com/YOURLOGIN/pins/</code>').'</li>';
		echo '<li>'.__( 'Scroll down the page and be sure all your collection is loaded.', 'pinim' ).'</li>';
                echo '<li>'.__( 'Save this file to your computer as an HTML file, then upload it here.', 'pinim' ).'</li></ol></p>';
                echo '<p>'.__("<strong>Be careful</strong>, reloading this page <u>when the import is not finished</u> may create each pin several times.  Use at your own risks.",'pinim').'</p>';
                
		wp_import_upload_form( 'admin.php?import=pinterest-pins&amp;step=1' );
		echo '</div>';
	}


	/**
	 * Decide if the given meta key maps to information we will want to import
	 *
	 * @param string $key The meta key to check
	 * @return string|bool The key if we do want to import, false if not
	 */
	function is_valid_meta_key( $key ) {
		// skip attachment metadata since we'll regenerate it from scratch
		// skip _edit_lock as not relevant for import
		if ( in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata', '_edit_lock' ) ) )
			return false;
		return $key;
	}

}

?>