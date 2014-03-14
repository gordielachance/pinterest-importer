<?php
/**
 * WordPress eXtended RSS file parser implementations
 *
 * @package WordPress
 * @subpackage Pinterest Importer
 */

/**
 * HTML Parser that makes use of the SimpleXML PHP extension.
 */


class PinterestGridParser {

	function parse( $file ) {

		$all_authors = $posts = $all_terms = array();
                
                $html = file_get_contents($file);
                $pins_page = phpQuery::newDocumentHTML($html);
                
                if(!isset($pins_page)){
                    return new WP_Error( 'phpQuery_parse_error', __( 'There was an error when reading this HTML file', 'wordpress-importer' ));
                }

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
                    if ($credits = pq($pin)->find('.pinCredits')){
                        
                        $credit_title = pq($pin)->find('.creditTitle')->htmlOuter();
                        $credit_title = trim(strip_tags($credit_title));

                        $credits_link = pq($credits)->find('a.creditItem')->attr('href');

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
                    
                    //ATTRIBUTION
                    if ($attribution_link_el = pq($pin)->find('.pinAttribution a')){
                        $attribution_text = $attribution_link_el->htmlOuter();
                        $attribution_link = $attribution_link_el->attr('href');
                        $pin_attr['attribution_link'] = $attribution_link;
                    }
                    
                    //DESCRIPTION
                    if ($description_el = pq($pin)->find('.pinDescription')){
                        $pin_attr['description'] = trim(strip_tags(pq($description_el)->htmlOuter()));
                    }
                    
                    //type
                    if (pq($pin)->find('.videoType')){
                        $type = 'video';
                    }else{
                        $type = 'image';
                    }
                    $pin_attr['mediatype'] = $type;
                    
                    //
                    $pins[$key] = array_filter($pin_attr);
                    
                }

                if (!$pins){
                    return new WP_Error( 'SimpleXML_parse_error', __( 'No pins were found', 'wordpress-importer' ));
                }

                // create empty post
                $blank_post = $this->blank_post();
                
                // create or get the root category
                $root_category_id = pai_get_term_id('Pinterest.com','category');

		foreach ($pins as $pin ) {

                        $import_id = $pin['pin_id'];

			$post = array(
				'post_title'    => (string) $pin['description'],
                                'post_author'   => (string) $pin['author'],
			);
                        
                        //keep that data attached, we will use it later
                        $post['pinterest_data'] = $pin;
                        
			$post = wp_parse_args($post,$blank_post);
                        
                        //post metas
                        $post['postmeta']['pinterest_pin_id']=$pin['pin_id'];
                        
                        $pinterest_metas = $pin; 
                        unset($pinterest_metas['pin_id']);
                        $post['postmeta']['pinterest_pin_metas']=$pinterest_metas;
  
                        //post category
                        $post['terms'][] = array(
                                'term_name'=>$pin['board_name'],
                                'term_taxonomy'=>'category',
                                'term_args'=>array('parent'=>$root_category_id) //child of root category
                        );
                        
                        $posts[$import_id] = $post;
		}

		foreach ((array)$posts as $post ) {
                    
                    // grab authors
                    $username = $post['post_author'];
                    $login = (string) sanitize_title($username);
                    $all_authors[] = array(
                            'author_login' => $login,
                            'author_display_name' => (string) $username
                    );
                    
                    // grab terms
                    foreach((array)$post['terms'] as $term){
                        $all_terms[]=$term;
                    }
		}
                
                //order chronologically
                $posts = array_reverse($posts,true);
                //$posts = array_slice($posts, 0,5,true); //FOR DEBUG

		$parsed =  array(
                        'authors' => array_unique($all_authors, SORT_REGULAR),
			'posts' => $posts,
			'terms' => array_unique($all_terms, SORT_REGULAR)
		);
                
                return $parsed;
                
	}
        
    function blank_post(){
        $post=array(
            'post_type'=>'post',
            'post_status'=>'publish',
            'post_date'=>current_time('mysql'),
            'post_date_gmt'=>current_time('mysql',1)
        );
        return $post;
    }
}

