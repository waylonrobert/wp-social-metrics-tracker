<?php

/***************************************************
* This table class is based on the "Custom List Table Example" provided by Matt van Andel
*
* http://wordpress.org/plugins/custom-list-table-example/
***************************************************/

if(!class_exists('WP_List_Table')){
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class SocialMetricsTrackerWidget extends WP_List_Table {


	function __construct(){
		global $status, $page;

		$this->options = get_option('smt_settings');

		// Do not run if current user not allowed to see this
		if (!current_user_can($this->options['smt_options_report_visibility'])) return false;

		add_action('wp_dashboard_setup', array($this, 'dashboard_setup'));

		//Set parent defaults
		parent::__construct( array(
			'singular'  => 'post',     //singular name of the listed records
			'plural'    => 'posts',    //plural name of the listed records
			'ajax'      => false        //does this table support ajax?
		) );

	}

	function dashboard_setup() {
		add_meta_box( 'social-metrics-tracker', 'Popular stories', array($this, 'render_widget'), 'dashboard', 'normal', 'high' );

	}

	function render_widget() {

		// add_action('admin_head', 'admin_header_scripts');

		$this->prepare_items();
		$this->display();
	}

	function column_default($item, $column_name){
		switch($column_name){
			case 'date':
				$dateString = date("M j, Y",strtotime($item['post_date']));
				return $dateString;
			default:
				return 'Not Set';
		}
	}

	function column_title($item){

		//Build row actions
		$actions = array(
			// 'view'      => sprintf('<a href="%s">View</a>',$item['permalink']),
			'edit'      => sprintf('<a href="post.php?post=%s&action=edit">Edit</a>',$item['ID']),
			'pubdate'   => 'Published on ' . date("M j, Y",strtotime($item['post_date'])),
			//'update'    => sprintf('Stats updated %s',SocialMetricsTracker::timeago($item['socialcount_LAST_UPDATED']))
		);

		//Return the title contents

		return '<a href="'.$item['permalink'].'"><b>'.$item['post_title'] . '</b></a>' . $this->row_actions($actions);
	}

	// Column for Social

	function column_social($item) {

		//return print_r($item,true);
		$total = max($item['socialcount_total'], 1);

		$facebook = $item['socialcount_facebook'];
		$facebook_percent = floor($facebook / $total * 100);

		$twitter = $item['socialcount_twitter'];
		$twitter_percent = floor($twitter / $total * 100);

		$other = $total - $facebook - $twitter;
		$other_percent = floor($other / $total * 100);

		$bar_width = round($total / $this->data_max['socialcount_total'] * 100);
		if ($total == 0) $bar_width = 0;

		$bar_class = ($bar_width > 50) ? ' stats' : '';

		$output = '';
		$output .= '<div class="bar'.$bar_class.'" style="width:'.$bar_width.'%">';
		$output .= '<span class="facebook" style="width:'.$facebook_percent.'%">'. $facebook_percent .'% Facebook</span>';
		$output .= '<span class="twitter" style="width:'.$twitter_percent.'%">'. $twitter_percent .'% Twitter</span>';
		$output .= '<span class="other" style="width:'.$other_percent.'%">'. $other_percent .'% Other</span>';
		$output .= '</div>';
		$output .= '<div class="total">'.number_format($total,0,'.',',') . '</div>';

		return $output;

	}

	// Column for views
	function column_views($item) {
		$output = '';
		$output .= '<div class="bar" style="width:'.round($item['views'] / $this->data_max['views'] * 100).'%">';
		$output .= '<div class="total">'.number_format($item['views'],0,'.',',') . '</div>';
		$output .= '</div>';

		return $output;
	}

	// Column for comments
	function column_comments($item) {
		$output = '';
		$output .= '<div class="bar" style="width:'.round($item['comment_count'] / $this->data_max['comment_count'] * 100).'%">';
		$output .= '<div class="total">'.number_format($item['comment_count'],0,'.',',') . '</div>';
		$output .= '</div>';

		return $output;
	}

	function get_columns(){

		// $columns['date'] = 'Date';
		$columns['title'] = 'Title';

		if ($this->options['smt_options_enable_social']) {
			$columns['social'] = 'Social Score';
		}
		if ($this->options['smt_options_enable_analytics']) {
			$columns['views'] = 'Views';
		}
		if ($this->options['smt_options_enable_comments']) {
			$columns['comments'] = 'Comments';
		}

		return $columns;
	}

	function get_sortable_columns() {
		$sortable_columns = array(
			// 'date'      => array('post_date',true),
			//'title'     => array('title',false),
			// 'views'    => array('views',true),
			// 'social'  => array('social',true),
			// 'comments'  => array('comments',true)
		);
		return $sortable_columns;
	}

	function get_bulk_actions() {
		$actions = array(
			//'delete'    => 'Delete'
		);
		return $actions;
	}

	function process_bulk_action() {


	}

	function date_range_filter( $where = '' ) {

		$range = (isset($_GET['range'])) ? $_GET['range'] : $this->options['smt_options_default_date_range_months'];

		if ($range <= 0) return $where;

		$range_bottom = " AND post_date >= '".date("Y-m-d", strtotime('-'.$range.' month') );
		$range_top = "' AND post_date <= '".date("Y-m-d")."'";

		$where .= $range_bottom . $range_top;
		return $where;
	}


	function prepare_items() {
		global $wpdb; //This is used only if making any database queries


		/**
		 * First, lets decide how many records per page to show
		 */
		$per_page = 10;


		$columns = $this->get_columns();
		$hidden = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array($columns, $hidden, $sortable);

		$this->process_bulk_action();


		$order = 'DESC';
		$orderby = $this->options['smt_options_default_sort_column']; //If no sort, default


		// Get custom post types to display in our report.
		$post_types = get_post_types(array('public'=>true, 'show_ui'=>true));
		unset($post_types['page']);
		unset($post_types['attachment']);

		$limit = 6;

		add_filter( 'posts_where', array($this, 'date_range_filter') );

		if ($orderby == 'views') {
			$querydata = new WP_Query(array(
				'order'=>$order,
				'orderby'=>'meta_value_num',
				'meta_key'=>'ga_pageviews',
				'posts_per_page'=>$limit,
				'post_status'   => 'publish',
				'post_type'     => $post_types
			));
		}

		if ($orderby == 'comments') {
			$querydata = new WP_Query(array(
				'order'=>$order,
				'orderby'=>'comment_count',
				'posts_per_page'=>$limit,
				'post_status'   => 'publish',
				'post_type'     => $post_types
			));
		}

		if ($orderby == 'social') {
			$querydata = new WP_Query(array(
				'order'=>$order,
				'orderby'=>'meta_value_num',
				'meta_key'=>'socialcount_TOTAL',
				'posts_per_page'=>$limit,
				'post_status'   => 'publish',
				'post_type'     => $post_types
			));
		}

		if ($orderby == 'aggregate') {
			$querydata = new WP_Query(array(
				'order'=>$order,
				'orderby'=>'meta_value_num',
				'meta_key'=>'social_aggregate_score',
				'posts_per_page'=>$limit,
				'post_status'   => 'publish',
				'post_type'     => $post_types
			));
		}

		if ($orderby == 'post_date') {
			$querydata = new WP_Query(array(
				'order'=>$order,
				'orderby'=>'post_date',
				'posts_per_page'=>$limit,
				'post_status'   => 'publish',
				'post_type'     => $post_types
			));
		}

		// Remove our date filter
		remove_filter( 'posts_where', array($this, 'date_range_filter') );

		$data=array();

		$this->data_max['socialcount_total'] = 1;
		$this->data_max['views'] = 1;
		$this->data_max['comment_count'] = 1;

		// foreach ($querydata as $querydatum ) {
		if ( $querydata->have_posts() ) : while ( $querydata->have_posts() ) : $querydata->the_post();
			global $post;

			$item['ID'] = $post->ID;
			$item['post_title'] = $post->post_title;
			$item['post_date'] = $post->post_date;
			$item['comment_count'] = $post->comment_count;
			$item['socialcount_total'] = (get_post_meta($post->ID, "socialcount_TOTAL", true)) ? get_post_meta($post->ID, "socialcount_TOTAL", true) : 0;
			$item['socialcount_twitter'] = get_post_meta($post->ID, "socialcount_twitter", true);
			$item['socialcount_facebook'] = get_post_meta($post->ID, "socialcount_facebook", true);
			$item['socialcount_LAST_UPDATED'] = get_post_meta($post->ID, "socialcount_LAST_UPDATED", true);
			$item['views'] = (get_post_meta($post->ID, "ga_pageviews", true)) ? get_post_meta($post->ID, "ga_pageviews", true) : 0;
			$item['permalink'] = get_permalink($post->ID);

			$this->data_max['socialcount_total'] = max($this->data_max['socialcount_total'], $item['socialcount_total']);

			$this->data_max['views'] = max($this->data_max['views'], $item['views']);

			$this->data_max['comment_count'] = max($this->data_max['comment_count'], $item['comment_count']);

		   array_push($data, $item);
		endwhile;
		endif;

		$current_page = $this->get_pagenum();

		$total_items = count($data);

		$data = array_slice($data,(($current_page-1)*$per_page),$per_page);

		$this->items = $data;

		$this->set_pagination_args( array(
			'total_items' => $total_items,                  //WE have to calculate the total number of items
			'per_page'    => $per_page,                     //WE have to determine how many items to show on a page
			'total_pages' => ceil($total_items/$per_page)   //WE have to calculate the total number of pages
		) );
	}


	/**
	 * Add extra markup in the toolbars before or after the list
	 * @param string $which, helps you decide if you add the markup after (bottom) or before (top) the list
	 */
	function extra_tablenav( $which ) {

		if ( $which == "top" ){

		}
		if ( $which == "bottom" ){
			//The code that goes after the table is there
			$period = ($this->options['smt_options_default_date_range_months'] > 1) ? 'months' : 'month';

			echo '<p style="float:left;">Showing most popular posts published within '.$this->options['smt_options_default_date_range_months'].' '.$period.'.</p>';
			echo '<a href="admin.php?page=social-metrics-tracker" style="float:right; margin:10px;" class="button-primary">More Social Metrics &raquo;</a>';

		}
	}

}

$socialMetricsTrackerWidget = new SocialMetricsTrackerWidget();
