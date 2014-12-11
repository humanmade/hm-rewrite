hm-rewrite
==========

`HM_Rewrite` and `HM_Rewrite_Rule` are wrappers for the WordPress rewrite / wp_query system.

The goal of HM_Rewrite and associated fuctions / classes is to make it very easy to add new routing points with new pages (as in dynamic pages, `post_type_archive` etc). It basically wraps a few tasks into a nice API. Everything (almost) you need for setting up a new routing page can be done all at once, relying heavily on PHP Closures. It essentially wraps adding to the `rewrite_rules`, adding your template file to `template_redirect`, `wp_title` hook, `body_class` hook, `parse_query` hook etc. Also also provides some callbacks for conveniance. Each rewrite rule is an instance of `HM_Rewrite_Rule`. Here you add the regex / `wp_query` vars and any other options for the "page". For example a callback function to `parse_request` to add additional query vars, or a callback * `body_class`. There is also a wrapper function for all of this in one call `hm_add_rewrite_rule()`. `hm_add_rewrite_rule()` is generally the recommended interface, you can interact with the underlying objects for more advanced stuff (and also tacking onto other rewrite rules)Simple use case example:  

````php
hm_add_rewrite_rule( array( 
  'regex' 	  => '^users/([^/]+)/?',  
  'query'	  	=> 'author_name=$matches[1]', 
  'template'	=> 'user-archive.php',
  'body_class_callback' => function( $classes ) { 
    $classes[] = 'user-archive';
    $classes[] = 'user-' . get_query_var( 'author_name' );
    
    return $classes;  
  },
  'title_callback' => function( $title, $seperator ) {
    return get_query_var( 'author_name' ) . ' ' . $seperator . ' ' . $title;
  } ) );
  ````
  
  A more advanced example using more callbacks:
  
  ````php
  hm_add_rewrite_rule( array( 
    'regex' 	=> '^reviews/([^/]+)/?', // a review category page 
    'query'		=> 'review_category=$matches[1]', 
    'template'	=> 'review-category.php',
    'request_callback' => function( WP $wp ) {
      // if the review category is "laptops" then only show items in draft
      if ( $wp->query_vars['review_category'] == 'laptops' )
        $wp->query_vars['post_status'] = 'draft'; 
      },
      'query_callback' => function( WP_Query $query ) {
        //overwrite is_home because WordPress gets it wrong here
        $query->is_home = false;
      },
      'body_class_callback' => function( $classes ) {
        $classes[] = get_query_var( 'review_category' );
        return $classes;
      },
      'title_callback' => function( $title, $seperator ) {
        return review_category . ' ' . $seperator . ' ' . $title;
      }
    )
);
````

## Contribution guidelines ##

see https://github.com/humanmade/hm-rewrite/blob/master/CONTRIBUTING.md

