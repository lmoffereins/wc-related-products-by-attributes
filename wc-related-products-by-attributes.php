<?php

/**
 * The WC Related Products by Attributes Plugin
 * 
 * @package WC Related Products by Attributes
 * @subpackage Main
 */

/**
 * Plugin Name:       WooCommerce Related Products by Attributes
 * Description:       Relate WooCommerce products by product attributes.
 * Plugin URI:        https://github.com/lmoffereins/wc-related-products-by-attributes/
 * Version:           1.0.0
 * Author:            Laurens Offereins
 * Author URI:        https://github.com/lmoffereins/
 * Text Domain:       wc-related-products-by-attributes
 * Domain Path:       /languages/
 * GitHub Plugin URI: lmoffereins/wc-related-products-by-attributes
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Related_Products_By_Attributes' ) ) :
/**
 * The main plugin class
 *
 * @since 1.0.0
 */
final class WC_Related_Products_By_Attributes {

	/**
	 * Setup and return the singleton pattern
	 *
	 * @since 1.0.0
	 *
	 * @uses WC_Related_Products_By_Attributes::setup_globals()
	 * @uses WC_Related_Products_By_Attributes::setup_actions()
	 * @return The single WC_Related_Products_By_Attributes
	 */
	public static function instance() {

		// Store instance locally
		static $instance = null;

		if ( null === $instance ) {
			$instance = new WC_Related_Products_By_Attributes;
			$instance->setup_globals();
			$instance->setup_actions();
		}

		return $instance;
	}

	/**
	 * Prevent the plugin class from being loaded more than once
	 */
	private function __construct() { /* Nothing to do */ }

	/** Private methods *************************************************/

	/**
	 * Setup default class globals
	 *
	 * @since 1.0.0
	 */
	private function setup_globals() {
		$this->priorities = get_option( 'wcrpba_attribute_priority',  array() );
		$this->threshold  = get_option( 'wcrpba_attribute_threshold', array() );
	}

	/**
	 * Setup default actions and filters
	 *
	 * @since 1.0.0
	 */
	private function setup_actions() {

		// Get default relations filter functions
		$by_categories = ( 'no' == get_option( 'wcrpba_relate_by_categories', 'yes' ) ) ? '__return_false' : '__return_true';
		$by_tags       = ( 'no' == get_option( 'wcrpba_relate_by_tags',       'no'  ) ) ? '__return_false' : '__return_true';

		// Only accept true or false
		add_filter( 'woocommerce_product_related_posts_relate_by_category', $by_categories, 10, 2 );
		add_filter( 'woocommerce_product_related_posts_relate_by_tag',      $by_tags,       10, 2 );

		// Modify related posts query
		add_filter( 'woocommerce_product_related_posts_query', array( $this, 'relate_by_php_attributes' ), 10, 2 );
		// add_filter( 'woocommerce_product_related_posts_query', array( $this, 'relate_by_sql_attributes' ), 10, 2 );

		// Admin
		add_filter( 'woocommerce_product_settings',                       array( $this, 'register_settings'           ) );
		add_action( 'woocommerce_admin_field_wcrpba_attribute_priority',  array( $this, 'setting_attribute_priority'  ) );
		add_action( 'woocommerce_admin_field_wcrpba_attribute_threshold', array( $this, 'setting_attribute_threshold' ) );

		// Plugin action links
		add_filter( 'plugin_action_links', array( $this, 'plugin_links' ), 10, 2 );
	}

	/** Public methods **************************************************/

	/**
	 * Modify the query to find a product's related posts by attributes
	 *
	 * Doing this the PHP way: first ad hoc querying for attribute matches,
	 * then extending the query with the found product ids.
	 *
	 * @since 1.0.0
	 * 
	 * @param array $query Query clauses
	 * @param int $product_id Product ID
	 * @return array Query clauses
	 */
	public function relate_by_php_attributes( $query, $product_id ) {

		// Define local variable(s)
		$product   = wc_get_product( $product_id );
		$tax_terms = array();

		// Collect the product's taxonomy terms
		foreach ( $product->get_attributes() as $taxonomy => $args ) {
			$terms = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! empty( $terms ) ) {
				$tax_terms[ $taxonomy ] = $terms;
			}
		}

		// Product has attributes
		if ( ! empty( $tax_terms ) ) {

			// Define local variable(s)
			$products = array();

			// Walk the taxonomies
			foreach ( $tax_terms as $taxonomy => $terms ) {

				// Get attribute ordering priority
				$priority = $this->get_attribute_priority( $taxonomy );

				// Walk each term
				foreach ( $terms as $term_id ) {

					$term_objects = get_objects_in_term( $term_id, $taxonomy );

					// Find associated products
					foreach ( $term_objects as $object_id ) {

						// Register product with priority
						if ( ! in_array( $object_id, array_keys( $products ) ) ) {
							$products[ $object_id ] = $priority;
						} else {
							$products[ $object_id ] += $priority;
						}
					}
				}
			}

			// Sort all products by their priority value
			arsort( $products );

			// Apply threshold: remove all selections below the threshold
			if ( isset( $this->threshold['enabled'] ) && $this->threshold['enabled'] ) {

				// Define priority ceiling and threshold
				$max_priority = $products[ $product_id ];
				$threshold    = (int) $this->threshold['threshold'];

				// Remove products with a priority lower than the given percentage of the ceiling
				$products = array_filter( $products, function( $priority ) use ( $max_priority, $threshold ) {
					return ( $priority / $max_priority ) * 100 > $threshold;
				});
			}

			// Remove current product, when queried
			unset( $products[ $product_id ] );

			// There are matches, so query them
			if ( ! empty( $products ) ) {

				// Collect product ids in string
				$p_ids = implode( ',', array_keys( $products ) );
				
				// Append found product ids to where clause
				$query['where'] .= " AND p.ID IN ( $p_ids )";

				// Order by found product ids priority
				$query['orderby'] = "ORDER BY FIELD( p.ID, $p_ids ) ASC";
			
			// No matches, so query no products at all
			} else {
				$query['where'] .= " AND 0 == 1";
			}
		}

		return $query;
	}

	/**
	 * Return the attribute's associated ordering priority
	 *
	 * @since 1.0.0
	 * 
	 * @param string $taxonomy Attribute's taxonomy name
	 * @return float Priority
	 */
	public function get_attribute_priority( $taxonomy ) {

		// Count terms in taxonomy
		$term_count = wp_count_terms( $taxonomy );
		if ( empty( $term_count ) )
			return 0;

		/**
		 * Define the priority term factor
		 *
		 * This is based on the assumption that when products match
		 * on a single term out of 12 terms, this is more meaningful
		 * then a match based on 1 out of 2. Therefor the term factor
		 * is introduced to account for this and multiply the priority
		 * of an attribute match: 12 terms make a 1.2 term factor, 
		 * 2 terms make 0.2.
		 */
		$term_factor = $term_count / 10; 

		/**
		 * Define the priority taxonomy factor
		 *
		 * This is based on a user defined order, which enables
		 * manual prioritization of the different attributes.
		 */
		$tax_factor = isset( $this->priorities[ $taxonomy ] ) ? (int) $this->priorities[ $taxonomy ] : 10;

		// Define final priority
		$priority = $term_factor * $tax_factor;

		return (float) apply_filters( 'wcrpba_get_attribute_priority', $priority, $taxonomy );
	}

	/**
	 * Modify the query to find a product's related posts by attributes
	 *
	 * Doing this the SQL way: extending the query with counting matching attributes,
	 * while storing it in a user variable, as well as sorting by that count.
	 * 
	 * NOTE: this is just an attempt, but not working properly yet.
	 *
	 * @since 1.0.0
	 * 
	 * @param array $query Query clauses
	 * @param int $product_id Product ID
	 * @return array Query clauses
	 */
	public function relate_by_sql_attributes( $query, $product_id ) {
		global $wpdb;

		// Get product attrs
		$product   = wc_get_product( $product_id );
		$tax_terms = array();

		// Collect taxonomy terms
		foreach ( $product->get_attributes() as $taxonomy => $args ) {
			$terms = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( ! empty( $terms ) ) {
				$terms = implode( ',', $terms );
				/**
				 * The query clauses are already setup to join with the
				 * term_taxonomy table (tt) and the terms table (t).
				 */
				$tax_terms[] = $wpdb->prepare( "tt.taxonomy = %s AND t.term_id IN ( $terms )", $taxonomy );
			}
		}

		// Product has attributes
		if ( ! empty( $tax_terms ) ) {
			$fields = $query['fields'];
			$where  = $query['where'];

			// Define local variable(s)
			$counter = '@matches';

			/**
			 * Overwrite SELECT ... FROM clause to enable user variables
			 *
			 * To fit the setting and using of a MySQL user variable within a single
			 * SQL query, the user variable must be declared in a dummy table before 
			 * selecting from the main table.
			 * 
			 * @link http://phaq.phunsites.net/2012/01/27/working-around-wordpress-wpdb-limitations-with-mysql-user-variables/
			 */
			$query['fields']  = "SELECT DISTINCT p.ID, $counter FROM";
			$query['fields'] .= " ( SELECT $counter := 0 ) dummy_table,"; // Dummy table to set and enable the user var
			$query['fields'] .= " {$wpdb->posts} p";

			/**
			 * Increment match counter when the row matches a term within the attribute
			 * 
			 * @link http://www.xaprb.com/blog/2006/12/15/advanced-mysql-user-variable-techniques/
			 */
			foreach ( array_keys( $tax_terms ) as $k ) {
				/**
				 * The incrementation always need to result in FALSE so the other attribute
				 * matches can be found. Then afterwards, the counter will be used for selecting
				 * the record when any match was found.
				 */
				$tax_terms[$k] .= " AND ( $counter := $counter + 1 )";
			}

			// Reset counter before matching
			$query['where'] .= " AND ( $counter := 0 ) IS NOT NULL";
			// Combine attribute taxonomies' WHERE clause
			$query['where'] .= " AND ( ( " . implode( " ) OR ( ", $tax_terms ) . " ) OR 1 = 1 )";
			// Select product when there is at least one match
			$query['where'] .= " AND ( 0 < $counter )";

			// Get posts not to be included
			// $exclude_ids = array_map( 'absint', array_merge( array( 0, $product_id ), $product->get_upsells() ) );

			// Return by priority so overwrite orderby clause
			$query['orderby'] = "ORDER BY $counter DESC, RAND()"; //$this->parse_query_order();

			// Temp: limit 5
			$query['limits'] = "";

			$sql  = $wpdb->get_results( implode( ' ', $query ) );
			$sql2 = $wpdb->get_results( "SELECT $counter" );
			// var_dump( $sql, (int) $sql2[0]->$counter, implode( ' ', $query ) );

			// Reset clauses
			$query['fields'] = $fields;
			$query['where']  = $where;
		}

		return $query;
	}

	/** Admin ***********************************************************/

	/**
	 * Add plugin settings
	 * 
	 * @since 1.0.0
	 * 
	 * @param array $settings Products settings
	 * @return array Products settings
	 */
	public function register_settings( $settings ) {

		// Append settings section
		$settings = array_merge( $settings, array(

			// Start section
			'wcrpba_section_start' => array( 
				'type'     => 'title', 
				'id'       => 'related_products_by_attributes',
				'title'    => __( 'Related Products by Attributes', 'wc-related-products-by-attributes' )
			), 

			// Attribute Priority
			'wcrpba_attribute_priority' => array(
				'title'    => __( 'Attribute Priority', 'wc-related-products-by-attributes' ),
				'desc'     => sprintf( __( 'A priority of %s means the attribute will be excluded from defining the relations.', 'wc-related-products-by-attributes' ), '<code>0</code>' ),
				'id'       => 'wcrpba_attribute_priority',
				'type'     => 'wcrpba_attribute_priority', // Custom type, with a custom callback
				'desc_tip' => true,
				'autoload' => false
			),

			// Attribute threshold
			'wcrpba_attribute_threshold' => array(
				'title'    => __( 'Attribute Threshold', 'wc-related-products-by-attributes' ),
				'desc'     => __( 'Use a threshold: all products with a match of less than %s will be excluded', 'wc-related-products-by-attributes' ),
				'id'       => 'wcrpba_attribute_threshold',
				'type'     => 'wcrpba_attribute_threshold', // Custom type, with a custom callback
				'default'  => array(
					'enabled'   => 0,
					'threshold' => 75
				),
				'autoload' => false
			),

			// Default relate by categories
			'wcrpba_relate_by_categories' => array(
				'title'         => __( 'Relation Methods', 'wc-related-products-by-attributes' ),
				'desc'          => __( 'Relate by categories', 'wc-related-products-by-attributes' ),
				'desc_tip'      => __( 'Enable this option to activate relating by product categories, next to attributes.', 'wc-related-products-by-attributes' ),
				'id'            => 'wcrpba_relate_by_categories',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
				'autoload'      => false
			),

			// Default relate by tags
			'wcrpba_relate_by_tags' => array(
				'desc'          => __( 'Relate by tags', 'wc-related-products-by-attributes' ),
				'desc_tip'      => __( 'Enable this option to activate relating by product tags, next to attributes.', 'wc-related-products-by-attributes' ),
				'id'            => 'wcrpba_relate_by_tags',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => 'end',
				'autoload'      => false
			),

			// End section
			'wcrpba_section_end' => array( 
				'type'     => 'sectionend',
				'id'       => 'related_products_by_attributes' 
			)
		) );

		return $settings;
	}

	/**
	 * Output the content for the Attribute Priority setting
	 *
	 * @since 1.0.0
	 *
	 * @uses get_option()
	 * @uses wc_get_attribute_taxonomies()
	 * @uses wp_count_terms()
	 * @uses wc_attribute_label()
	 * @uses apply_filters() Calls 'wcrpba_setting_attribute_priority'
	 *
	 * @param array $setting The setting's data
	 */
	public function setting_attribute_priority( $setting ) {

		// Get settings' option value
		$option_value = get_option( $setting['id'], array() );
		arsort( $option_value );

		// Get all attribute taxonomies
		$attributes = array_map( 'wc_attribute_taxonomy_name', wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_name' ) );
		$taxonomies = array();

		// Sort attributes by saved priority
		foreach ( array_keys( $option_value ) as $taxonomy ) {
			if ( false !== ( $key = array_search( $taxonomy, $attributes ) ) ) {
				$taxonomies[] = $taxonomy;
				unset( $attributes[ $key ] );
			}
		}

		// Append non-saved remaining attributes
		$taxonomies += $attributes;

		// Start output buffer
		ob_start(); ?>

		<tr valign="top" id="<?php echo $setting['id']; ?>">
			<th scope="row" class="titledesc"><?php echo $setting['title']; ?></th>
			<td>
				<p class="description">
					<?php echo $setting['desc']; ?>
				</p>

				<ul class="wcrpba_attributes">
					<?php foreach ( $taxonomies as $taxonomy ) : 
						$value = isset( $option_value[ $taxonomy ] ) ? (int) $option_value[ $taxonomy ] : 10; ?>

					<li class="attribute">
						<label for="wcrpba_attribute_priority_<?php echo $taxonomy; ?>"><?php echo wc_attribute_label( $taxonomy ); ?></label>
						<input  id="wcrpba_attribute_priority_<?php echo $taxonomy; ?>" type="number" name="wcrpba_attribute_priority[<?php echo $taxonomy; ?>]" value="<?php echo esc_attr( $value ); ?>" min="0" step="1" class="small-text">
						<span class="term_factor description">(<?php printf( _x( '%d terms', 'The taxonomy term count', 'wc-related-products-by-attributes' ), wp_count_terms( $taxonomy ) ); ?>)</span>
					</li>

					<?php endforeach; ?>
				</ul>

				<style type="text/css">
					.wcrpba_attributes .attribute label {
						display: inline-block;
						width: 160px;
					}
				</style>
			</td>
		</tr>

		<?php

		// Store and end output buffer in variable
		$output = ob_get_clean();

		echo apply_filters( 'wcrpba_setting_attribute_priority', $output, $setting );
	}

	/**
	 * Output the content for the Attribute Threshold setting
	 *
	 * @since 1.1.0
	 *
	 * @uses get_option()
	 * @uses apply_filters() Calls 'wcrpba_setting_attribute_threshold'
	 *
	 * @param array $setting The setting's data
	 */
	public function setting_attribute_threshold( $setting ) {

		// Get settings' option value
		$option_value = wp_parse_args( get_option( $setting['id'], array() ), $setting['default'] );

		// Start output buffer
		ob_start(); ?>

		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo $setting['title']; ?></th>
			<td>
				<input type="checkbox" id="<?php echo $setting['id']; ?>" name="<?php echo $setting['id']; ?>[enabled]" value="1" <?php checked( (bool) $option_value['enabled'] ); ?>/>
				<label for="<?php echo $setting['id']; ?>"><?php printf( $setting['desc'], sprintf( '<input type="number" name="%s" value="%s" class="small-text" min="0" max="100" step="1" />&#37;', $setting['id'] . '[threshold]', $option_value['threshold'] ) ); ?></label>
			</td>
		</tr>

		<?php

		// Store and end output buffer in variable
		$output = ob_get_clean();

		echo apply_filters( 'wcrpba_setting_attribute_threshold', $output, $setting );
	}

	/**
	 * Modify the plugin action links
	 * 
	 * @since 1.0.0
	 * 
	 * @param array $links Plugin links
	 * @param string $plugin Plugin basename
	 * @return array Plugin links
	 */
	public function plugin_links( $links, $plugin ) {

		// Considering our plugin
		if ( plugin_basename( __FILE__ ) == $plugin ) {

			// Link to plugin settings
			$links['settings'] = sprintf( '<a href="%s">%s</a>', 
				add_query_arg( array( 
					'page' => 'wc-settings', 
					'tab'  => 'products#wcrpba_attribute_priority' // Instead of section start id, which is never printed :(
				), admin_url( 'admin.php' ) ), 
				__( 'Settings' )
			);
		}

		return $links;
	}
}

/**
 * Return single instance of this main plugin class
 *
 * @since 1.0.0
 * 
 * @return WC_Related_Products_By_Attributes
 */
function wc_related_products_by_attributes() {
	return WC_Related_Products_By_Attributes::instance();
}

// Run when WC is initiated
add_action( 'woocommerce_init', 'wc_related_products_by_attributes' );

endif; // class_exists
