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
		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
	}
	
	function WP_Import() { /* nothing */ }
	
	function admin_enqueue_scripts($hook) {
		if( 'admin.php' != $hook )
			return;
		
		wp_enqueue_script( 'jquery' );
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
			case 0:
				$this->greet();
				break;
			case 1:
				check_admin_referer( 'import-upload' );
                            
				if ( $this->handle_upload() )
					$this->import_options();
				break;
			case 2:
                            
				check_admin_referer( 'pinterest-importer' );
				$this->id = (int) $_POST['import_id'];
				$file = get_attached_file( $this->id );
				set_time_limit(0);
				$this->import( $file );
				break;
                        break;
		}

		$this->footer();
	}


	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the HTML file for importing
	 */
	function import( $file ) {
		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );
 
		$this->attachments = $this->get_imported_attachments( 'pinterest' );
		$this->processed_posts = $this->get_imported_posts( 'pinterest' ); 
		
		$this->import_start( $file );

                $this->author_mapping = array(); //no author mapping

		wp_suspend_cache_invalidation( true );
		$this->process_posts();
		wp_suspend_cache_invalidation( false );
		$this->import_end();
	}
	
	function get_imported_posts( $importer_name ) {
		global $wpdb;

		$hashtable = array ();

		// Get all attachments
		$sql = $wpdb->prepare( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '%s'", $importer_name . '_post' );
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
		if ( ! is_file($file) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			echo __( 'The file does not exist, please try again.', 'wordpress-importer' ) . '</p>';
			$this->footer();
			die();
		}

		$import_data = $this->parse( $file );

		if ( is_wp_error( $import_data ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			echo esc_html( $import_data->get_error_message() ) . '</p>';
			$this->footer();
			die();
		}
                
		$this->get_authors_from_import( $import_data );
		$this->posts = $import_data['posts'];
		$this->terms = $import_data['terms'];

		wp_defer_term_counting( true );

		do_action( 'import_start' );
	}

	/**
	 * Performs post-import cleanup of files and the cache
	 */
	function import_end() {
		wp_import_cleanup( $this->id );
                wp_import_cleanup( $this->cookie_id );

		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
			delete_option( "{$tax}_children" );
			_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );

		echo '<p>' . __( 'All done.', 'wordpress-importer' ) . ' <a href="' . admin_url() . '">' . __( 'Have fun!', 'wordpress-importer' ) . '</a>' . '</p>';

		do_action( 'import_end' );
	}

	/**
	 * Handles the HTML upload and initial parsing of the file to prepare for
	 * displaying author import options
	 *
	 * @return bool False if error uploading or invalid file, true otherwise
	 */
	function handle_upload() {

                //TO FIX
                /*
                $cookie = self::handle_cookie_upload();

		if ( isset( $cookie['error'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error uploading the cookie file.', 'pinterest-importer' ) . '</strong><br />';
			echo esc_html( $file['error'] ) . '</p>';
			return false;
		} else if ( ! file_exists( $cookie['file'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			printf( __( 'The cookie file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'pinterest-importer' ), esc_html( $cookie['file'] ) );
			echo '</p>';
			return false;
		}
                */
            
		$file = wp_import_handle_upload();

		if ( isset( $file['error'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error uploading the html file.', 'pinterest-importer' ) . '</strong><br />';
			echo esc_html( $file['error'] ) . '</p>';
			return false;
		} else if ( ! file_exists( $file['file'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			printf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'wordpress-importer' ), esc_html( $file['file'] ) );
			echo '</p>';
			return false;
		}

		$this->id = (int) $file['id'];
		$import_data = $this->parse( $file['file'] );

		if ( is_wp_error( $import_data ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			echo esc_html( $import_data->get_error_message() ) . '</p>';
			return false;
		}

		$this->get_authors_from_import( $import_data );

		return true;
	}
        
        function handle_cookie_upload(){
            
                $cookiestring = null;
                
                if (isset($_POST['cookie'])) {
                    $cookiestring=trim($_POST['cookie']);
                }
                
                if(!$cookiestring){
                    $file['error'] = __( 'Cookie is empty. Please upload something more substantial.','pinterest-importer');
                    return $file;
                }
            
                $file = wp_upload_bits('pinterestcookie.txt',false,$cookiestring);
                
                $url = $file['url'];
                $type = $file['type'];
                $file = $file['file'];
                $filename = basename( $file );

                // Construct the object array
                $object = array( 'post_title' => $filename,
                        'post_content' => $url,
                        'post_mime_type' => $type,
                        'guid' => $url,
                        'context' => 'import',
                        'post_status' => 'private'
                );

                // Save the data
                $id = wp_insert_attachment( $object, $file );

                // schedule a cleanup for one day from now in case of failed import or missing wp_import_cleanup() call
                wp_schedule_single_event( time() + DAY_IN_SECONDS, 'importer_scheduled_cleanup', array( $id ) );

                return array( 'file' => $file, 'id' => $id );
        }

	/**
	 * Retrieve authors from parsed HTML data
	 *
	 * Uses the provided author information from HTML 1.1 files
	 * or extracts info from each post for HTML 1.0 files
	 *
	 * @param array $import_data Data returned by a HTML parser
	 */
	function get_authors_from_import( $import_data ) {
		if ( ! empty( $import_data['authors'] ) ) {
			$this->authors = $import_data['authors'];
		// no author information, grab it from the posts
		} else {
			foreach ( $import_data['posts'] as $post ) {
				$login = sanitize_user( $post['post_author'], true );
				if ( empty( $login ) ) {
					printf( __( 'Failed to import author %s. Their posts will be attributed to the current user.', 'wordpress-importer' ), esc_html( $post['post_author'] ) );
					echo '<br />';
					continue;
				}

				if ( ! isset($this->authors[$login]) )
					$this->authors[$login] = array(
						'author_login' => $login,
						'author_display_name' => $post['post_author']
					);
			}
		}
	}
	
	function import_finished() {
		?>
		<h3><?php _e( 'Completed Import', 'pinterest-importer' ); ?></h3>
		<p><?php _e( 'We have succesfully imported your content from Pinterest.', 'wordpress-importer' ); ?></p>
		<p><?php _e( 'Happy Blogging!', 'wordpress-importer' ); ?></p>
		<p style="text-align: center">
			<a href="https://premium.wpmudev.org/join/"><img href="<?php echo plugins_url('pinterest-importer-advanced/img/wpmudev.jpg', __FILE__); ?>" style="border:none;"/></a>
		</p>
		<?php

	}

	/**
	 * Display pre-import options, author importing/mapping and option to
	 * fetch attachments
	 */
	function import_options() {
		$j = 0;
?>
<form action="<?php echo admin_url( 'admin.php?import=pinterest-html&amp;step=2' ); ?>" method="post">
	<?php wp_nonce_field( 'pinterest-importer' ); ?>
	<input type="hidden" name="import_id" value="<?php echo $this->id; ?>" />
        <input type="hidden" name="cookie_id" value="<?php echo $this->cookie_id; ?>" />

<?php if ( ! empty( $this->authors ) ) : ?>
	<h3><?php _e( 'Assign Authors', 'wordpress-importer' ); ?></h3>
	<p><?php _e( 'To make it easier for you to edit and save the imported content, you may want to reassign the author of the imported item to an existing user of this site. For example, you may want to import all the entries as <code>admin</code>s entries.', 'wordpress-importer' ); ?></p>
<?php if ( pinterest_importer()->import_allow_create_users ) : ?>
	<p><?php printf( __( 'If a new user is created by WordPress, a new password will be randomly generated and the new user&#8217;s role will be set as %s. Manually changing the new user&#8217;s details will be necessary.', 'wordpress-importer' ), esc_html( get_option('default_role') ) ); ?></p>
<?php endif; ?>
	<ol id="authors">
<?php foreach ( $this->authors as $author ) : ?>
		<li><?php $this->author_select( $j++, $author ); ?></li>
<?php endforeach; ?>
	</ol>
<?php endif; ?>

	<p class="submit"><input type="submit" class="button" value="<?php esc_attr_e( 'Submit', 'wordpress-importer' ); ?>" /></p>
</form>
<?php
	}

	/**
	 * Display import options for an individual author. That is, either create
	 * a new user based on import info or map to an existing user
	 *
	 * @param int $n Index for each author in the form
	 * @param array $author Author information, e.g. login, display name, email
	 */
	function author_select( $n, $author ) {
		_e( 'Import author:', 'wordpress-importer' );
		echo ' <strong>' . esc_html( $author['author_display_name'] );
		if ( $this->version != '1.0' ) echo ' (' . esc_html( $author['author_login'] ) . ')';
		echo '</strong><br />';

		if ( $this->version != '1.0' )
			echo '<div style="margin-left:18px">';

		$create_users = pinterest_importer()->import_allow_create_users;
		if ( $create_users ) {
			if ( $this->version != '1.0' ) {
				_e( 'or create new user with login name:', 'wordpress-importer' );
				$value = '';
			} else {
				_e( 'as a new user:', 'wordpress-importer' );
				$value = esc_attr( sanitize_user( $author['author_login'], true ) );
			}

			echo ' <input type="text" name="user_new['.$n.']" value="'. $value .'" /><br />';
		}

		if ( ! $create_users && $this->version == '1.0' )
			_e( 'assign posts to an existing user:', 'wordpress-importer' );
		else
			_e( 'or assign posts to an existing user:', 'wordpress-importer' );
		wp_dropdown_users( array( 'name' => "user_map[$n]", 'multi' => true, 'show_option_all' => __( '- Select -', 'wordpress-importer' ) ) );
		echo '<input type="hidden" name="imported_authors['.$n.']" value="' . esc_attr( $author['author_login'] ) . '" />';

		if ( $this->version != '1.0' )
			echo '</div>';
	}

        function pinterest_pin_post_exists($pin_id){
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
        
        function pinterest_pin_image_exists($img_url){
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
	 * Create new posts based on import information
	 *
	 * Posts marked as having a parent which doesn't exist will become top level items.
	 * Doesn't create a new post if: the post type doesn't exist, the given post ID
	 * is already noted as imported or a post with the same title and date already exists.
	 * Note that new/updated terms, comments and meta are imported for the last of the above.
	 */
	function process_posts() {
                
                $i=0;
            
		foreach ( $this->posts as $import_id=>$post ) {
                    $i++;

                    $postmetas = array();

			if ( ! post_type_exists( $post['post_type'] ) ) {
                                //feedback
				printf( __( 'Invalid post type %s', 'wordpress-importer' ),esc_html($post['post_type']) );
				echo '<br />';
				continue;
			}

			if ( isset( $this->processed_posts[$import_id] )) continue;

 
			$post_type_object = get_post_type_object( $post['post_type'] );
			
			$post['post_date'] = date('Y-m-d H:i:s', strtotime($post['post_date']));
			$post['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime($post['post_date']));

			$post_exists = self::pinterest_pin_post_exists($import_id);
                        
                        $pin_url = sprintf('http://www.pinterest.com/pin/%s',$import_id);
                        
                        $pin_title = (strlen($post['post_title']) > 60) ? substr($post['post_title'],0,60).'...' : $post['post_title'];

			if ( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
                                
                                //feedback
                                
                                echo '<span class="pinterest-feedback" style="color:#B6B6B4"><br/>#'.$i.'  <em>'.$pin_title.'</em> (pin id#'.$import_id.') ';
                                _e('already exists','pinterest-importer');
                                echo '</span>';
                                
                                continue;
                                
				$post_id = $post_exists;
				$new_post = get_post($post_id);
			} else {
                            
                                //feedback
                                echo '<span class="pinterest-feedback"><br/><br/><strong>#'.$i.'  <em>'.$pin_title.'</em> (pin id#<a href="'.$pin_url.'" target="_blank">'.$import_id.'</a>)</strong></span><br/>';

				$postdata = array(
					'import_id' => $import_id,
                                        'post_author' => $post['post_author'],
                                        'post_date' => $post['post_date'],
					'post_date_gmt' => $post['post_date_gmt'],
                                        //'post_content' => $post['post_content'],
					//'post_excerpt' => $post['post_excerpt'],
                                        'post_title' => $post['post_title'],
					//'post_status' => $post['post_status'],
                                        //'post_name' => $post['post_name'],
					//'comment_status' => $post['comment_status'],
                                        //'ping_status' => $post['ping_status'],
					//'guid' => $post['guid'],
                                        //'post_parent' => $post_parent,
                                        //'menu_order' => $post['menu_order'],
					//'post_type' => $post['post_type'],
                                        //'post_password' => $post['post_password']
				);

				$post_id = wp_insert_post( $postdata, true );

				if ( is_wp_error( $post_id ) ) {
                                    
                                        //feedback
                                        echo'<span class="pinterest-feedback" style="color:red">';
                                        _e('Error while creating the post', 'pinterest-importer');
					if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
						echo ': ' . $post_id->get_error_message();
                                        echo '<br />';
                                        echo'</span>';
 
					continue;
				}
					
				$new_post = get_post($post_id);
                                
                                
                                ///OPEN THE PIN///
                                
                                
                                
                                //post format
                                $post_format = $post['pinterest_data']['data-media_type'];

                                /*
                                switch ($post_format) {
                                    case 'video':
                                        $post_format = 'video';
                                        $subtype = $post['pinterest_data']['data-media_subtype'];

                                        switch ($subtype) {
                                            case 'vimeo_video':
                                            break;
                                            case 'youtube_video':
                                            break;
                                        }
                                      
                                    break;

                                }
                                */
                                
                                if($post_format){
                                    set_post_format( $post_id , $post_format);
                                }


				
				// Now get the attachments
                                
                                //feedback
                                echo'<span class="pinterest-feedback">';
                                    _e('...Importing image...', 'pinterest-importer');
                                echo'</span>';
                                echo '<br />';
                                
				$image_url = $this->get_featured_image_url($import_id, $post['post_id']);
                                
				if (!$this->process_featured_image($new_post, $image_url)){
                                    
                                    //feedback
                                    echo'<span class="pinterest-feedback" style="color:red">';
                                        _e('Error saving post image, delete this post', 'pinterest-importer');
                                    echo'</span>';
                                    echo '<br />';
                                    
                                    wp_delete_post($post_id, true );
                                    continue;
                                }
			}

			// map pre-import ID to local ID
			$this->processed_posts[$import_id] = (int) $post_id;

			// add categories, tags and other terms
			if ( ! empty( $post['terms'] ) ) {
				$terms_to_set = array();
				foreach ( $post['terms'] as $term ) {
                                    $term_id = pai_get_term_id($term['term_name'],$term['term_taxonomy'],$term['term_args']);

                                    if ( ! $term_id ) {
                                        //feedback
                                        echo'<span class="pinterest-feedback" style="color:red">';
                                        printf( __( 'Failed to import %s %s', 'wordpress-importer' ), esc_html($taxonomy), esc_html($term['name']) );
                                        echo '<br />';
                                        echo'</span>';
                                        continue;
                                    }

                                    $terms_to_set[$term['term_taxonomy']][] = intval( $term_id );
				}

				foreach ( (array)$terms_to_set as $tax => $ids ) {
					$tt_ids = wp_set_post_terms( $post_id, $ids, $tax );
				}
				unset( $post['terms'], $terms_to_set );
			}

			// add/update post meta
                        
			if ( isset( $post['postmeta'] ) ) {
				foreach ( $post['postmeta'] as $key=>$value ) {
                                    add_post_meta( $post_id, $key, $value );
                                    do_action( 'import_post_meta', $post_id, $key, $value );
				}
			}
                        $this->process_post_content($new_post);
                        
                        //feedback
                        echo'<span class="pinterest-feedback" style="color:green">';
                            _e('Post created !', 'pinterest-importer');
                            echo '<br />';
                        echo'</span>';
                        
		}
		unset( $this->posts );
	}
        
        function process_post_content($post){
            $media_type = get_post_meta($post->ID,'_pinterest-media_type',true);
            $source = get_post_meta($post->ID,'_pinterest-pin_target',true);
            
            switch($media_type){
                
                case 'image':
                    $content.='<a href="' . $source . '" title="' . the_title_attribute('echo=0') . '" >';
                    $content.=get_the_post_thumbnail($post->ID,'full');
                    $content.='</a>';
                break;
            
                case 'video':
                    
                $subtype = get_post_meta($post->ID,'_pinterest-media_subtype',true);
                $video_id = get_post_meta($post->ID,'_pinterest-media_external_id',true);
                    
                switch($subtype){
                    case 'vimeo_video':
                        $content='[vimeo '.$video_id.']';
                    break;
                    case 'youtube_video':
                        $content='[youtube '.$video_id.']';
                    break;
                    case 'dailymotion_video':
                        $content='[dailymotion id='.$video_id.']';
                    break;
                }
                    
                break;
            }
            
            //allow to filter
            $content = apply_filters('pinterest_importer_post_format_content',$content,$post);
            
            
            //add extra infos
            $list=array();
            $list['source']='<a href="'.$source.'" target="_blank">'.__('Source','pinterest-importer').'</a>';
            
            if($list){
                
                foreach((array)$list as $list_item){
                    $li_str[]='<li>'.$list_item.'</li>';
                }
                
                $li_str=implode("\r\n",$list);
                $extra_content='<ul class="pinterest-pin">'.$li_str.'</ul>';
                $extra_content = apply_filters('pinterest_importer_post_extra_content',$extra_content,$post);
            }
            
            $content = $content."\r\n".$extra_content;
            
            $my_post = array();
            $my_post['ID'] = $post->ID;
            $my_post['post_content'] = $content;
            if (!wp_update_post( $my_post )){
                //feedback
                echo'<span class="pinterest-feedback" style="color:red">';
                    _e('Error while updateing post content', 'pinterest-importer');
                    echo '<br />';
                echo'</span>';
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
	function process_featured_image( $post, $image_url ) {
		
		if ( empty( $image_url ) )
			return false;
                
                $attachment_id = self::pinterest_pin_image_exists($image_url);
                
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
                            printf(__("Image size: %s",'pinterest-importer'), size_format( filesize( $upload['file'] ) ) );
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
                    printf(__("Image already exists, link attachment #%s",'pinterest-importer'),$attachment_id);
                    echo"</em>";
                    echo"</span>";
                    echo"<br/>";
                }

                //set thumbnail
                set_post_thumbnail($post->ID, $attachment_id);          
                
                return true;
	}

        
        function get_pin_page($pin_id){

            $url = sprintf('http://www.pinterest.com/pin/%s',$pin_id);


            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            //curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); // TO FIX : cannot be activated when safe_mode is enabled or an open_basedir 
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt ($curl, CURLOPT_HEADER, false);
            
            //curl_setopt($curl, CURLOPT_REFERER,'http://www.pinterest.com/');
            //curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)");
            

            $content = curl_exec($curl);
            $info = curl_getinfo($curl); //Some information on the fetch
            curl_close($curl);

            $valid_http_codes = array(200,302);

            if(!in_array($info['http_code'],$valid_http_codes)) return false;

            if($info['http_code']==200){ // we have some content
                //$content = $content;
            }elseif($info['http_code']==302){ //we have been redirected
                $content = self::get_pin_page($info['redirect_url'],$url);
            }

            return $content;

        }
	
	/**
	 * Return array of images from the post
	 *
	 * @param string $post_content
	 * @return array
	 */
	function get_featured_image_url( $pin_id, $post_id ) {

                //populate pin HTML
                
                $pin_page_markup = self::get_pin_page($pin_id);

                if($pin_page_markup){
                    $pin_page = phpQuery::newDocumentHTML($pin_page_markup);
                }

                if(!isset($pin_page)){
                    return new WP_Error( 'phpQuery_parse_error', __( 'There was an error when reading this HTML file', 'wordpress-importer' ));
                }
                
                phpQuery::selectDocument($pin_page);
                
                //default thumbnail url, from the document metas.
                $featured_image = pq($pin_page)->find('meta[property=og:image]')->attr('content');
                
                //check if we can get better.
                
                $container_link_el = pq('.paddedPinLink');
                $container_link = $container_link_el->attr('href');
                $container_content = pq($container_link_el)->find('div');

                if(pq($container_content)->hasClass('imageContainer')){ 
                    if ($image = pq($container_content)->find('img.pinImage')){
                        $featured_image = $image->attr('src');
                    }
                }

		return $featured_image;
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
		echo '<h2>' . __( 'Import Pinterest "Pins" HTML', 'wordpress-importer' ) . '</h2>';
		
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
                echo '<p>'.__("Howdy! Wanna backup your Pinterest.com profile ?  Here's how to do.",'pinterest-importer').'</p>';
                echo '<h2>1. '.__('Save and upload your Pinterest.com page','pinterest-importer').'</h2>';
		echo '<p><ol><li>'.sprintf(__("Login to %1s and head to your pins page, which url should be %2s.", 'pinterest-importer' ),'<a href="http://www.pinterest.com" target="_blank">Pinterest.com</a>','<code>http://www.pinterest.com/YOURLOGIN/pins/</code>').'</li>';
		echo '<li>'.__( 'Scroll down the page and be sure all your collection is loaded.', 'pinterest-importer' ).'</li>';
                echo '<li>'.__( 'Save this file to your computer as an HTML file, then upload it here.', 'pinterest-importer' ).'</li></ol></p>';
		self::import_upload_form( 'admin.php?import=pinterest-html&amp;step=1' );
		echo '</div>';
	}
        
        //copy of wp_import_upload_form, but with textarea field added.
        function import_upload_form( $action ) {
                $bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
                $size = wp_convert_bytes_to_hr( $bytes );
                $upload_dir = wp_upload_dir();
                if ( ! empty( $upload_dir['error'] ) ) :
                        ?><div class="error"><p><?php _e('Before you can upload your import file, you will need to fix the following error:'); ?></p>
                        <p><strong><?php echo $upload_dir['error']; ?></strong></p></div><?php
                else :
        ?>
        <form enctype="multipart/form-data" id="import-upload-form" method="post" class="wp-upload-form" action="<?php echo esc_attr(wp_nonce_url($action, 'import-upload')); ?>">
        <p>
        <label for="upload"><?php _e( 'Choose a file from your computer:' ); ?></label> (<?php printf( __('Maximum size: %s' ), $size ); ?>)
        <input type="file" id="upload" name="import" size="25" />
        <?php self::import_cookie_field();?>
        <input type="hidden" name="action" value="save" />
        <input type="hidden" name="max_file_size" value="<?php echo $bytes; ?>" />
        </p>
        <?php submit_button( __('Upload file and import'), 'button' ); ?>
        </form>
        <?php
                endif;
        }
        
        function import_cookie_field(){
            ?>
                <p>
                    <h2>2. <?php _e('Copy and paste your Pinterest.com cookies','pinterest-importer');?></h2>
                    <?php _e('You also need to set your cookies for Pinterest.com or it will not work.','pinterest-importer');?><br/>
                <ol>
                    <li><?php printf(__('To retrieve the cookies, you can install the extension %1s (for Chrome).','pinterest-importer'),'<a href="https://chrome.google.com/webstore/detail/cookietxt-export/lopabhfecdfhgogdbojmaicoicjekelh" target="_blank">cookie.txt export</a>');?></li>
                    <li><?php printf(__('Then, while visiting %s, click the extension icon, and copy the content that is displayed.','pinterest-importer'),'<a href="http://www.pinterest.com" target="_blank">Pinterest.com</a>');?></li>
                    <li><?php _e('Paste it here.','pinterest-importer');?></li>
                </ol>
                    
                    <textarea id="cookie" name="cookie" rows="10" cols="100"></textarea>
                </p>
            <?php
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


	/**
	 * Added to http_request_timeout filter to force timeout at 60 seconds during import
	 * @return int 60
	 */
	function bump_request_timeout() {
		if ($this->auth) {
			return 2;
		}
		return 60;
	}

	// return the difference in length between two strings
	function cmpr_strlen( $a, $b ) {
		return strlen($b) - strlen($a);
	}
}

?>
