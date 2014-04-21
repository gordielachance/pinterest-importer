<?php

/**
 * Pinterest Importer class for managing the import process of a HTML file
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( !class_exists( 'WP_Importer' ) ) return false;


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
			case 1:
				check_admin_referer( 'import-upload' );

                                set_time_limit(0);
                                $result = $this->import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
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
		$this->process_raw_posts($this->raw_posts);
		$this->import_end();
	}
        
        function process_raw_posts($raw_posts){
            
            $pin_count = 0;
            $total_pins = count($raw_posts);
            
            foreach ((array)$raw_posts as $pin_id=>$raw_post){
                
                $pin_count++;
                
                //feedback
                $message = "<br/><br/>".sprintf(__('%1s/%2s - Importing pin #%3s...','pinim'),$pin_count,$total_pins,'<a href="'.pai_get_pin_url($pin_id).'" target="_blank">'.$pin_id.'</a>');
                $this->feedback($message);
                
                
                if ( $existing_post_id = pai_pin_exists($pin_id) ) {
                    $existing_post = get_post($existing_post_id);
                    $error_msg = sprintf(__('Pin already exists as post#%1s : %2s','pinim'),$existing_post_id,'<a href="'.get_permalink($existing_post_id).'" target="_blank">'.$existing_post->post_title.'</a>');
                    $error = new WP_Error('existing_pin', $error_msg);
                    $this->feedback($error->get_error_message());
                    continue;
                }

                //populate pin HTML
                $message =  __('Loading pin HTML...','pinim');
                $this->feedback($message);
                $pin_html = self::get_single_pin_html($pin_id);
                if ( is_wp_error($pin_html) ){
                    $this->feedback($pin_html->get_error_message());
                    continue;
                }

                //create post
                $post_id = self::process_single_raw_post($pin_id,$raw_post,$pin_html);
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
        
        function process_single_raw_post($pin_id,$raw_post,$pin_html){
            $error = false;
            $data = $raw_post['data'];
            
            

            //sanitize post
            $sanitized_post = self::sanitize_single_post($raw_post);
            if ( is_wp_error($sanitized_post) ) return $sanitized_post; //ABORD

            //insert post
            $post_id = wp_insert_post( $sanitized_post, true );
            if ( is_wp_error($post_id) ) return $post_id; //ABORD
            
            $new_post = get_post($post_id);

            $data['featured_url'] = $this->get_featured_image_url($pin_html);//thumbnail
            $data['source'] = $this->get_source_url($pin_html);//source
            $data['pinner'] = self::get_pinner($pin_html); //pinner

            
            
            //set post format
            if($data['format']){
                if (set_post_format( $post_id , $data['format'])){
                    //feedback
                    $message =  sprintf(__('Set post format : %2s','pinim'),$data['format']);
                    $this->feedback($message);
                }
            }

            //set featured image
            if ($featured_image_id = $this->process_image($new_post, $data['featured_url'])){
                    set_post_thumbnail($new_post, $featured_image_id);
                    $hd_file = wp_get_attachment_image_src($featured_image_id, 'full');
                    $hd_url = $hd_file[0];
                    //feedback
                    $message =  sprintf(__('Set post thumbnail: %1s','pinim'),'<a href="'.$hd_url.'" target="_blank">'.$featured_image_id.'</a>');
                    $this->feedback($message);
            }else{
                $error_msg =  sprintf(__('Error while setting post thumbnail: %1s','pinim'),'<a href="'.$data['featured_url'].'" target="_blank">'.$data['featured_url'].'</a>');
                $error = new WP_Error('post_thumbnail_error', $error_msg, $post_id);
                return $error;
            }
            
            
            
            //set post metas
            $post_metas = self::sanitize_single_post_metas($data);

            foreach ( $post_metas as $key=>$value ) {
                $key = '_pinterest-'.$key;
                add_post_meta( $post_id, $key, $value );
                do_action( 'import_post_meta', $post_id, $key, $value );
            }
            
            //set post content
            if (!$this->set_post_content($new_post,$pin_html)){
                //feedback
                $error_msg =  __('Error while updating post content', 'pinim');
                $error = new WP_Error('post_content_error', $error_msg, $post_id);
                return $error;
            }

            return $post_id;
        }
        

        
        function get_pins_html($file){
                $html = file_get_contents($file);
                $pins_page = phpQuery::newDocumentHTML($html);
                
                if(!isset($pins_page)){
                    return new WP_Error( 'phpQuery_parse_error', __( 'There was an error when reading this HTML file', 'wordpress-importer' ));
                }
                
                //check is a correct pins page
                
                return $pins_page;
                
        }
        
        function is_pins_page($pins_page){
            
            //TO FIX TO CHECK : find a way to be sure this is a /pins page
            return true;
            
            phpQuery::selectDocument($pins_page);
        } 
        
        function get_raw_posts($pins_page){

                $pins = $this->parse_pins($pins_page);

                if (!$pins){
                    return new WP_Error( 'phpQuery_parse_error', __( 'No pins were found', 'pinim' ));
                }

                $posts = array();

		foreach ($pins as $pin_id=>$pin ) {
                    
                        //title
                        if (isset($pin['description'])){
                            $title = $pin['description'];
                            unset($pin['description']);
                        }else{
                            $title = 'pin #'.$pin['pin_id'];
                        }
                        
                        $post['post_title'] = $title;
                        
                        //sub category
                        if ($pin['board_name']){
                            if ($sub_category = pai_get_term_id($pin['board_name'],'category',array('parent'=>pinim()->root_category_id))){
                                $post['post_category'] = array($sub_category);
                            }
                        }
 
                        //other data
                        $post['data'] = $pin;

                        $posts[$pin_id] = apply_filters('pai_post',$post,$pin_id,$pin);
		}
                
                return $posts;
                
	}
        
        function parse_pins($pins_page){

            phpQuery::selectDocument($pins_page);

            $pins = array();

            //get pins
            foreach (pq('.pinWrapper') as $key=>$pin) {

                $pin_attr = array();

                //pin ID
                $pin_url = pq($pin)->find('.pinImageWrapper')->attr('href');
                if ($pin_id = pai_url_extract_pin_id($pin_url)){
                    $pin_attr['pin_id'] = $pin_id;
                }

                //CREDITS
                $credits_el = pq($pin)->find('.pinCredits');
                if ($has_credits = $credits_el->length){

                    $credit_title = pq($pin)->find('.creditTitle')->htmlOuter();
                    $credit_title = trim(strip_tags($credit_title));

                    $credits_link = pq($credits_el)->find('a.creditItem')->attr('href');

                    if ($credits_link){
                        
                        //board
                        if ($board_slug = pai_url_extract_board_slug($credits_link)){
                            $pin_attr['board_slug'] = $board_slug;
                            $pin_attr['board_name'] = $credit_title;
                        }

                        //source
                        if ($source_slug = pai_url_extract_source_slug($credits_link)){
                            $pin_attr['source_slug'] = $source_slug;
                            $pin_attr['source_name'] = $credit_title;
                        }
                        
                    }

                }

                //ATTRIBUTION
                $attribution_link_el = pq($pin)->find('.pinAttribution a');
                if ($attribution_link_el->length){
                    $attribution_text = $attribution_link_el->htmlOuter();
                    $attribution_link = $attribution_link_el->attr('href');
                    $pin_attr['attribution_link'] = $attribution_link;
                }

                //DESCRIPTION
                $description_el = pq($pin)->find('.pinDescription');
                if ($description_el->length){
                    $pin_attr['description'] = trim(strip_tags(pq($description_el)->htmlOuter()));
                }
                
                //DOMAIN
                $domain_el = pq($pin)->find('.pinDomain');
                if ($domain_el->length){
                    $pin_attr['domain'] = trim(strip_tags(pq($domain_el)->htmlOuter()));
                }

                //format
                $format = 'image';
                
                $videtype_el = pq($pin)->find('.videoType');

                if ($videtype_el->length){
                    $format = 'video';
                }
                
                $pin_attr['format'] = $format;
                $pins[$pin_id] = array_filter($pin_attr);

            }

            return $pins;
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

		if ( ! is_file($file) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			echo __( 'The file does not exist, please try again.', 'wordpress-importer' ) . '</p>';
			$this->footer();
			die();
		}
                
                $pins_page = self::get_pins_html($file);
                
                if ((is_wp_error($pins_page)) || (!self::is_pins_page($pins_page))){
                    echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
                    echo esc_html( $import_data->get_error_message() ) . '</p>';
                    $this->footer();
                    die();
                }

                $this->raw_posts = self::get_raw_posts($pins_page);

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

        
        function sanitize_single_post($raw){

            $default = pai_get_blank_post();

            $post = wp_parse_args($raw,$default);

            //WRONG POST TYPE
            if ( ! post_type_exists( $post['post_type'] ) ) {
                $message = sprintf( __( 'Invalid post type %s', 'wordpress-importer' ),esc_html($post['post_type']) );
                return new WP_Error('invalid_post_type', $message);
            }

            //sanitize title TO FIX TO MOVE
            //$post['post_title'] = (strlen($post['post_title']) > 60) ? substr($post['post_title'],0,60).'...' : $post['post_title'];
            return apply_filters('pai_sanitize_single_post',$post,$raw);
        }
        
        /**
         * Filter the meta keys we want to keep in the DB.
         * @param type $data
         * @return type
         */
        
        function sanitize_single_post_metas($data){

            $save_keys = array(
                'pinner',
                'pin_id',
                'board_slug',
                'source'
            );
            
            $metas = array();
            
            foreach ($save_keys as $key){
                if (isset($data[$key])){
                    $metas[$key] = $data[$key];
                }
            }

            return apply_filters('pai_sanitize_single_post_metas',$metas,$data);
            
        }

        
        function set_post_content($post,$pin_html){
            $post_format = get_post_format( $post->ID );
            $source = pai_get_pin_meta('source', $post->ID, true);
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
            $content = apply_filters('pai_get_post_content',$content,$post,$pin_html);

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
                
                $attachment_id = pai_image_exists($image_url);
                
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
        
        function get_single_pin_html($pin_id){
                //populate pin HTML
                $pin_url = pai_get_pin_url($pin_id);
                $pin_doc = self::get_page($pin_url);
      
                if(isset($pin_doc['body'])){
                    $pin_html = phpQuery::newDocumentHTML($pin_doc['body']);
                }

                if(!isset($pin_html)){
                    return new WP_Error( 'phpQuery_parse_error', __( 'There was an error when reading this HTML file', 'wordpress-importer' ));
                }

                return $pin_html;
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
            $user = pai_url_extract_user($url);
            return $user;
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