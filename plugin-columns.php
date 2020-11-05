<?php
/*
 * Plugin Name: Plugin Columns
 * Description: Various columns for the plugins list.
 * Author: Roger Grimstad
 * Version: 1.2.2
 */

 // Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// This is an admin only plugin
if ( ! is_admin() ) return;

// Actions for adding pinned categories. Multisite support.
add_action('admin_menu', 'plugin_columns_add_pinned_categories' );
add_action( 'network_admin_menu', 'plugin_columns_add_pinned_categories' );

/**
 * Wrapper for get option.
 */
function plugin_columns_get_option( $option, $default = array() ) {
	$get_option = is_multisite() ? 'get_site_option' : 'get_option';
	return $get_option( $option, $default );
}

/**
 * Add submenu under plugins menu for pinned categories.
 */
function plugin_columns_add_pinned_categories() {	
	$index = 0;
	if ( is_multisite() && ! is_network_admin() ) {		
		$index = get_current_blog_id();		
	}	
	$pinned_categories = plugin_columns_get_option( 'plugin-columns-pinned-categories' );	
	if ( ! empty( $pinned_categories[$index] ) ) {
		foreach( $pinned_categories[$index] as $pinned_category ) {			
			add_submenu_page(
				'plugins.php',
				$pinned_category,
				$pinned_category,
				'manage_options',
				str_replace( ' ', '+', $pinned_category) .'-plugin-columns-category-page',				
				function(){}
			);
		}
	}
}

// Prevent updates for plugins added in a no-update category.
$plugin_columns_noupdate_plugins = plugin_columns_get_option('plugin-columns-noupdate-plugins');

if ( !empty( $plugin_columns_noupdate_plugins ) ) {
	add_filter( 'pre_set_site_transient_update_plugins', 'plugin_columns_noupdate_filter', 99 );
	add_filter( 'site_transient_update_plugins', 'plugin_columns_noupdate_filter', 99 );
	add_filter( 'transient_update_plugins', 'plugin_columns_noupdate_filter', 99 );
}

function plugin_columns_noupdate_filter( $transient ) {
	global $plugin_columns_noupdate_plugins;
	if ( !empty( $transient->response ) && !empty( $plugin_columns_noupdate_plugins ) ) {
		foreach( $plugin_columns_noupdate_plugins as $plugin ) {
			if ( isset( $transient->response[$plugin] ) ) {
				unset( $transient->response[$plugin] );				
			}
		}				
	}						
	return $transient;
}

// Chillout at most pages
$pcCurrentPage = basename( strtok( $_SERVER["REQUEST_URI"], '?' ) );
if ( ! empty( $pcCurrentPage ) && $pcCurrentPage !== 'plugins.php' && $pcCurrentPage !== 'admin-ajax.php' && $pcCurrentPage !== 'update.php' && $pcCurrentPage !== 'update-core.php' ) {
	return;
}

if ( !class_exists( 'PluginColumns' ) ) :

final class PluginColumns {
	
	private $data;	
	private $plugins;
	private $columns;	
	private $categories;
	private $pinned_categories;
	private $hidden_categories;
	private $warning_categories;
	private $imported;
	private $trash;
	private $options;
	private $allPlugins;
	private $url_parameters;	
	
	public static function instance() {
		static $instance = null;
		
		if ( null === $instance ) {
			$instance = new self();	
			if ( isset( $_POST['clearall'] ) ) {			
				$instance->delete_plugin_options();
			}

			add_action( 'init', array( $instance, 'pin_category' ) );
			add_action( 'init', array( $instance, 'pinned_category_redirect' ) );
			add_action( 'admin_init', array( $instance, 'init' ), 0 );
		}

		return $instance;
	}

	/** Magic Methods *********************************************************/

	/**
	 * A dummy constructor to prevent the class from being loaded more than once.	 
	 */
	private function __construct() { /* Do nothing here */ }

	/**
	 * A dummy magic method to prevent the class from being cloned	
	 */
	public function __clone() { _doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?' ) ); }

	/**
	 * A dummy magic method to prevent the class from being unserialized	 
	 */
	public function __wakeup() { _doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?' ) ); }

	/**
	 * Magic method for checking the existence of a certain custom field	 
	 */
	public function __isset( $key ) { return isset( $this->data[$key] ); }

	/**
	 * Magic method for getting variables	 
	 */
	public function __get( $key ) { return isset( $this->data[$key] ) ? $this->data[$key] : null; }

	/**
	 * Magic method for setting variables	 
	 */
	public function __set( $key, $value ) { $this->data[$key] = $value; }

	/**
	 * Magic method for unsetting variables	 
	 */
	public function __unset( $key ) { if ( isset( $this->data[$key] ) ) unset( $this->data[$key] ); }

	/**
	 * Magic method to prevent notices and errors from invalid method calls	 
	 */
	public function __call( $name = '', $args = array() ) { unset( $name, $args ); return null; }

	/**
	 * Setup the plugin.
	 */
	public function init() {
		if ( ! function_exists( 'check_admin_referer' ) ) return;
		if ( ! $this->user = wp_get_current_user() ) wp_die( -1 );

		$this->columns = array( 
			'categories' => __('Categories'),
			'activated' => __('Activated'),
			'deactivated' => __('Deactivated'),
			'installed' => __('Installed'),
			'updated' => __('Updated'),			
			'folder' => __('Folder'),
			'source' => __('Source'),
			'author' => __('Author'),
			'activatedcounter' => __('Activated #'),
			'updatedcounter' => __('Updated #'),			
		);
		$this->version    = '1.2.1';		
		$this->file = __FILE__;
		$this->basename = plugin_basename( $this->file );				
		$this->plugin_dir = plugin_dir_path( $this->file );
		$this->plugin_url = plugin_dir_url ( $this->file );			
		$this->plugins_page_url = self_admin_url( 'plugins.php' );		
		$this->url = home_url( add_query_arg( null, null ) );		
		$this->url_parsed = wp_parse_url( $this->url );
		$this->url_parameters = array();
		if ( isset( $this->url_parsed['query'] ) ) {
			parse_str( $this->url_parsed['query'], $this->url_parameters );
		}
		$this->currentPage = basename( strtok( $_SERVER["REQUEST_URI"], '?' ) );
		$this->dateformat = 'Y.m.d';
		$this->deactivateWarning = 'This is a required plugin. Are you sure you want to deactivate it?';		
		$this->view = 'all';
		$this->custom_view = false;
		$this->allPlugins = array();
		$this->plugins = $this->get_option( 'plugin-columns-plugins', array() );		
		$this->categories = $this->get_option( 'plugin-columns-categories', array() );		
		$this->pinned_categories = $this->get_option( 'plugin-columns-pinned-categories', array() );
		$this->hidden_categories = $this->get_option( 'plugin-columns-hidden-categories', array() );
		$this->warning_categories = $this->get_option( 'plugin-columns-warning-categories', array() );
		$this->noupdate_categories = $this->get_option( 'plugin-columns-noupdate-categories', array() );
		$this->noupdate_plugins = $this->get_option( 'plugin-columns-noupdate-plugins', array() );
		$this->imported = $this->get_option( 'plugin-columns-imported-plugins', array() );
		$this->trash = $this->get_option( 'plugin-columns-trash', array() );
		$this->options = $this->get_option( 'plugin-columns-options', array() );
		$this->updatePluginData = false;
		$this->firstRun = empty( $this->plugins );

		// Options form handler.
		if ( isset( $_POST['pcadmin-categories'] ) || isset( $_POST['pcclear'] ) || isset( $_POST['pc-empty-trash'] ) || isset( $_POST['pc-options-update-meta'] ) ) {			
			$this->form_post_action();			
		}

		// Add meta columns
		if ( !empty( $this->options['metacols'] ) ) {
			$this->columns['rating'] = __('Rating');
			$this->columns['downloaded'] = __('Downloaded');
			$this->columns['last_updated'] = __('Last Updated');
			$this->columns['added'] = __('Added');

			if ( !empty( $this->options['screenshots'] ) ) {
				add_filter( 'plugin_row_meta', array( $this, 'description_meta' ), 10, 2 );
			}			
		}

		// Set view
		if ( isset( $_REQUEST['plugin_status'] ) ) {
			$this->view = $_REQUEST['plugin_status'];
			if ( $this->view === 'imported' || $this->view === 'trash' ) {
				$this->custom_view = true;
			}
		}

		// Set allPlugins variable
		add_filter( 'all_plugins', function( $allPlugins ) {
			$this->allPlugins = $allPlugins;
			return $allPlugins;
		}, 0);

		// Add views links for imported and deleted plugins.
		if ( ! is_multisite() || is_network_admin() ) {
			add_filter( 'views_plugins', array( $this, 'add_views_link' ) );
			add_filter( 'views_plugins-network', array( $this, 'add_views_link' ) );
		}
		
		if ( $this->view === 'mustuse' || $this->view === 'dropins' ) return;
				
		// Import.
		if ( isset( $_FILES['pcimport'] ) ) {			
			$this->import();
		}

		// Export.
		if ( isset( $_POST['pcexport'] ) ) {
			$this->export();
		}

		// Backup.
		if ( isset( $_POST['pcbackup'] ) ) {
			$this->backup();
		}	

		// Add sort data to plugin array.
		if ( isset( $_GET['orderby'] ) ) {
			add_filter( 'all_plugins', array( $this, 'add_plugin_sort_data' ) );
		}

		// Show custom views.
		if ( ( isset( $_GET['plugin_status'] ) && ( $_GET['plugin_status'] === 'imported' || $_GET['plugin_status'] === 'trash' ) ) 
				&& ( ! is_multisite() || is_network_admin() ) ) {
			add_filter( 'network_admin_plugin_action_links', array( $this, 'custom_view_actions' ), 10, 4 );
			add_filter( 'plugin_action_links', array( $this, 'custom_view_actions' ), 10, 4 );
			add_filter( 'manage_plugins_sortable_columns', array( $this, 'custom_view_plugins_filter' ) );
			add_filter( 'manage_plugins-network_sortable_columns', array( $this, 'custom_view_plugins_filter' ) );
			add_filter( 'admin_body_class', function( $classes ) {
				$classes .= " {$this->view}-plugins custom-plugin-view ";
				return $classes;
			});			
		}

		// Filter category.
		if ( isset( $_GET['category_name'] ) ) {
			add_filter( 'manage_plugins_sortable_columns', array( $this, 'plugin_category_filter' ), 11 );
			add_filter( 'manage_plugins-network_sortable_columns', array( $this, 'plugin_category_filter' ), 11 );
		}

		// Sort by column.
		if ( isset( $_GET['orderby'] ) ) {
			add_filter( 'manage_plugins_sortable_columns', array( $this, 'plugin_sort' ), 12 );
			add_filter( 'manage_plugins-network_sortable_columns', array( $this, 'plugin_sort' ), 12 );
		}

		// Redirect filter.
		if ( $this->currentPage === 'plugins.php' ) {
			add_filter( 'wp_redirect', array( $this, 'redirect_filter' ), 99 );
		}
		
		// Prevent fs redirection if option activated
		if ( !empty( $this->options['redirect'] ) && function_exists('fs_redirect') ) {
			$pc_activated = get_transient( "pc_activated_plugin" );
			if ( !empty( $pc_activated ) ) {
				delete_transient( "fs_plugin_{$pc_activated}_activated" );
				delete_option( "fs_{$pc_activated}_activated" );
				delete_transient( "pc_activated_plugin" );
			}
		}

		// Filter hidden categories from the all plugins list.		
		if ( ! empty( $this->get_hidden_categories() ) && empty( $_REQUEST['s'] ) ) {				
			add_filter( 'all_plugins', array( $this, 'filter_hidden_categories' ) );
		}
		
		// Register when plugins are activated/deactived.
		add_action( 'activate_plugin', array( $this, 'activated_plugin_stats' ) );
		add_action( 'deactivate_plugin', array( $this, 'deactived_plugin_stats' ) );
		
		// Register when plugins are installed/updated/deleted		
		add_action( 'upgrader_process_complete', array( $this, 'installed_plugin_stats' ), 10, 2 );
		add_action( 'delete_plugin', array( $this, 'deleted_plugin_stats' ) );

		// Add the columns.
		add_filter( 'manage_plugins_columns', array( $this, 'plugin_columns' ) );
		add_filter( 'manage_plugins-network_columns', array( $this, 'plugin_columns' ) );
		add_action( 'manage_plugins_custom_column', array( $this, 'show_plugin_columns' ), 10, 3 );

		// Bulk actions.
		add_filter( 'bulk_actions-plugins', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-plugins', array( $this, 'bulk_action_handle' ), 10, 3 );
		add_filter( 'bulk_actions-plugins-network', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-plugins-network', array( $this, 'bulk_action_handler' ), 10, 3 );
		
		// Update plugin data when category removed etc.
		add_action( 'admin_footer-plugins.php', array( $this, 'update_plugin_data' ) );

		// Output Javascript templates.
		add_action( 'admin_footer-plugins.php', array( $this, 'js_templates' ) );

		// Add category filter and sort selects at the top with a hack.
		add_action( 'pre_current_active_plugins', function () { ob_start(); }, 9999 );
		add_action( 'admin_footer-plugins.php', array( $this, 'add_category_filter_select' ), 0 );		
				
		// Ajax action.
		add_action( 'wp_ajax_plugin_columns_action', array( $this, 'plugin_columns_action' ) );

		// Add custom html at the top of the plugins page (options dialogs).
		add_action( 'pre_current_active_plugins', array( $this, 'plugin_columns_top_html' ) );

		// Screen options.
		add_filter( 'screen_settings', array( $this, 'add_screen_options' ), 10, 2 );

		// Add deactivate warning class.
		if ( ! $this->custom_view && ! empty( $this->warning_categories ) ) {
			add_filter( 'plugin_action_links', array( $this, 'add_deactivate_warning_class' ), 10, 4 );
			add_filter( 'network_admin_plugin_action_links', array( $this, 'add_deactivate_warning_class' ), 10, 4 );			
		}
		
		// Enqueue scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 99 );		
	}


	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'plugins.php' != $hook ) { return; }
			
		$deactivateWarning = '';
		if ( ! empty( $this->options['deactivate-warning'] ) ) {
			$deactivateWarning = $this->options['deactivate-warning'];
		}
		wp_enqueue_style( 'select2.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.5/css/select2.min.css', array(), '4.0.5' );
		wp_enqueue_script( 'select2.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.5/js/select2.min.js', array('jquery'), '4.0.5', true );
		
		if ( !empty( $this->options['screenshots'] ) ) {
			wp_enqueue_style( 'jquery.fancybox.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.4.1/jquery.fancybox.min.css', array(), '3.4.1' );
			wp_enqueue_script( 'jquery.fancybox.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.4.1/jquery.fancybox.min.js', array('jquery'), '3.4.1', true );
		}
		
		wp_enqueue_style( 'plugin-columns.css', $this->plugin_url . 'assets/css/plugin-columns.css', array(), $this->version );						
		wp_enqueue_script( 'plugin-columns.js', $this->plugin_url . 'assets/js/plugin-columns.js', array('jquery'), $this->version, true );
		wp_localize_script( 'plugin-columns.js', 'pcvars', array(
			'nonce' => wp_create_nonce( 'pcadmin-ajax' ),
			'pluginsUrl' => $this->plugins_page_url,
			'categories' => implode(',', $this->categories),
			'pinnedCategories' => implode(',', $this->get_pinned_categories()),				
			'hiddenCategories' => implode(',', $this->get_hidden_categories()),
			'warningCategories' => implode(',', $this->get_warning_categories()),
			'noupdateCategories' => implode(',', $this->get_noupdate_categories()),
			'categoryName' => isset($_GET['category_name']) ? urldecode( esc_attr($_GET['category_name']) ) : '',
			'orderby' => isset($_GET['orderby']) ? esc_attr($_GET['orderby']) : '',
			'pluginView' => $this->view,
			'deactivateWarning' => $deactivateWarning,
			'hideFeedbackDialog' => !empty( $this->options['feedback'] ),
			'stickyHeader' => !empty( $this->options['sticky'] ),
			'metaInfoPlugins' => !empty( $this->getMetaInfo ) ? json_encode( $this->get_meta_info_list( $this->metaInfoUpdate ) ) : ''
		));
	}

	/**
	 * Register when plugins are activated.
	 */
	public function activated_plugin_stats( $plugin ) {
		$timestamp = current_time('timestamp');
		$this->plugins[$plugin]['activated'] = $timestamp;
		$activatedCounter = 1;
		if ( isset( $this->plugins[$plugin]['activatedcounter'] ) ) {
			$activatedCounter = ((int)$this->plugins[$plugin]['activatedcounter']) + 1;
		}
		$this->plugins[$plugin]['activatedcounter'] = $activatedCounter;
		if ( is_multisite() && ! is_network_admin() ) {
			$blog_id = get_current_blog_id();			
			$this->plugins[$plugin]['activated_'.$blog_id] = $timestamp;
			$counterName = 'activatedcounter_'.$blog_id;
			$activatedCounter = 1;
			if ( isset( $this->plugins[$plugin][$counterName] ) ) {
				$activatedCounter = ((int)$this->plugins[$plugin][$counterName]) + 1;
			}
			$this->plugins[$plugin][$counterName] = $activatedCounter;
		}
		$this->update_option( 'plugin-columns-plugins', $this->plugins );
	}

	/**
	 * Register when plugins are deactivated.
	 */
	public function deactived_plugin_stats( $plugin ) {
		$timestamp = current_time('timestamp');
		$this->plugins[$plugin]['deactivated'] = $timestamp;
		if ( is_multisite() && ! is_network_admin() ) {
			$blog_id = get_current_blog_id();			
			$this->plugins[$plugin]['deactivated_'.$blog_id] = $timestamp;			
		}
		$this->update_option( 'plugin-columns-plugins', $this->plugins );
	}


	/**
	 * Register when plugins are installed/updated.
	 */
	public function installed_plugin_stats( $upgrader, $info ) {		
		if ( $info['type'] === 'plugin' ) {
			$update = false;
			$install = '';
			if ( $info['action'] === 'install' ) {
				$plugin_name = $upgrader->result['destination_name'];
				$plugins = get_plugins();				
				foreach ( $plugins as $plugin_file => $values ) {
					if ( strpos( $plugin_file, $plugin_name.'/' ) !== false ) {
						$this->plugins[$plugin_file]['installed'] = current_time('timestamp');
						
						if ( isset( $this->imported[$plugin_file] ) ) {
							unset( $this->imported[$plugin_file] );
							$this->update_option( 'plugin-columns-imported-plugins', $this->imported );
						}
						
						if ( isset( $this->trash[$plugin_file] ) ) {
							unset( $this->trash[$plugin_file] );
							$this->update_option( 'plugin-columns-trash', $this->trash );
						}
						
						$install = $plugin_file;
						$update = true;
						break;
					}
				}				 
			}
			else if ( $info['action'] === 'update' ) {				
				foreach ( $info['plugins'] as $plugin_file ) {
					$this->plugins[$plugin_file]['updated'] = current_time('timestamp');
					$updatedCounter = 1;
					if ( isset( $this->plugins[$plugin_file]['updatedcounter'] ) ) {
						$updatedCounter = ((int)$this->plugins[$plugin_file]['updatedcounter']) + 1;
					}
					$this->plugins[$plugin_file]['updatedcounter'] = $updatedCounter;
					$update = true;
				}				
			}

			if ( $update ) {
				$this->update_option( 'plugin-columns-plugins', $this->plugins );

				if ( !empty( $install ) && !empty( $this->options['metacols'] ) ) {
					$this->get_meta_info( array( $install ), true, true );
				}
			}			
		}		
	}

	/**
	 * Register when plugins are deleted.
	 */
	public function deleted_plugin_stats( $plugin_file ) {				
		if ( isset( $this->plugins[$plugin_file] ) ) {
			$plugins = get_plugins();			
			$this->trash[$plugin_file] = $plugins[$plugin_file];
			$this->trash[$plugin_file]['categories'] = $this->plugins[$plugin_file]['categories'];
			$this->update_option( 'plugin-columns-trash', $this->trash );
			unset( $this->plugins[$plugin_file] );
			$this->update_option( 'plugin-columns-plugins', $this->plugins );			
		}		
	}

	/**
	 * Add plugin columns.
	 */
	public function plugin_columns( $columns ) {		
		$customColumns = $this->columns;
		
		if ( $this->custom_view ) {
			$customColumns = array( 'categories' => __('Categories') );
		}
		
		$args['urlParameters'] = $this->url_parameters;
		$screen = get_current_screen();		
		$hidden = get_user_option( 'manage' . $screen->id . 'columnshidden' );			
		$orderby = isset( $_GET['orderby'] ) ? urldecode( $_GET['orderby'] ) : '';

		foreach ( $customColumns as $column => $columnTitle ) {
			$sortable = !empty( $orderby ) && $orderby === $column ? 'sorted' : 'sortable';			
			if ( $column === 'categories' ) {
				$order = $sortable === 'sorted' && $_GET['order'] === 'asc' ? 'asc' : 'desc';				
			}
			else {
				$order = $sortable === 'sorted' && $_GET['order'] === 'desc' ? 'desc' : 'asc';				
			}
			$linkOrder = $order === 'desc' ? 'asc': 'desc';
			$args['headerClasses'] = "pc-{$column} $sortable $order";			
			$args['urlParameters']['orderby'] = $column;
			$args['urlParameters']['order'] = $linkOrder;			
			$args['columnTitle'] = $columnTitle;
			$args['style'] = '';
			if ( $column === $orderby && in_array( $column, $hidden ) ) {
				$args['style'] = '<style>.plugins-php .column-'.$column.'.hidden { display: table-cell; }</style>';
			}
			$columns[$column] = $this->column_header_template( $args );
		}

		return $columns;
	}

	/**
	 * Show plugin column data.
	 */
	public function show_plugin_columns( $column_name, $plugin_file, $plugin_data ) {
		$val = $html = '';

		if ( !$this->firstRun && !isset( $this->plugins[$plugin_file] ) ) {
			$this->plugins[$plugin_file]['installed'] = current_time('timestamp');
			$this->updatePluginData = true;
		}
		
		if ( ( is_multisite() && ! is_network_admin() ) && ( $column_name === 'activated' || $column_name === 'deactivated' ||  $column_name === 'activatedcounter' ) ) {			
			$blog_id = get_current_blog_id();
			if ( isset( $this->plugins[$plugin_file][$column_name.'_'.$blog_id] ) ) {
				$val = $this->plugins[$plugin_file][$column_name.'_'.$blog_id];
			}
		}
		else if ( ! empty( $this->plugins[$plugin_file][$column_name] ) ) {
			$val = $this->plugins[$plugin_file][$column_name];
		}
		else if ( $column_name === 'updated' ) {			
			$pluginFile = trailingslashit( WP_PLUGIN_DIR ) . $plugin_file;
			if ( file_exists( $pluginFile ) ) {			
				$stat = stat( $pluginFile );				
				if ( isset( $stat['mtime'] ) ) {
					$this->plugins[$plugin_file][$column_name] = $val = $stat['mtime'];
					$this->updatePluginData = true;
				}				
			}			
		}		
		else if ( $column_name === 'folder' ) {			
			$val = dirname($plugin_file);
			if ( $val === '.' ) {
				$val = '';
			}
			$this->plugins[$plugin_file][$column_name] = $val;
			$this->updatePluginData = true;
		}
		else if ( $column_name === 'source' ) {
			if ( isset( $plugin_data['url'] ) ) {
				$parsedUrl = parse_url( $plugin_data['url'] );
				if ( ! empty( $parsedUrl['host'] ) ) {
					$val = $parsedUrl['host'];
					$this->plugins[$plugin_file][$column_name] = $val;
					$this->updatePluginData = true;
				}				
			}
			else if ( isset( $plugin_data['PluginURI'] ) ) {
				$parsedUrl = parse_url( $plugin_data['PluginURI'] );
				if ( ! empty( $parsedUrl['host'] ) ) {
					$val = $parsedUrl['host'];
					$this->plugins[$plugin_file][$column_name] = $val;
					$this->updatePluginData = true;
				}
			}
		}
		else if ( $column_name === 'author' ) {
			if ( isset( $plugin_data['AuthorName'] ) ) {
				$val = $plugin_data['AuthorName'];			
				$this->plugins[$plugin_file][$column_name] = $val;
			}			
		}		

		if ( $column_name === 'categories' ) {			
			if ( $this->view === 'imported' ) {
				$val = '';
				if ( ! empty( $this->imported[$plugin_file][$column_name] ) ) {
					$val = $this->imported[$plugin_file][$column_name];					
				}					
			}
			else if ( $this->view === 'trash' ) {
				$val = '';
				if ( ! empty( $this->trash[$plugin_file][$column_name] ) ) {
					$val = $this->trash[$plugin_file][$column_name];					
				}
			}

			$categories = $val;
			$catLinks = $cats = '';
			$shownCats = array();
			$numCats = 0;
			if ( !empty( $categories ) ) {
				foreach ( $categories as $key => $category ) {					
					if ( in_array( $category, $this->categories ) ) {
						if ( $numCats > 0 ) {
							$catLinks .= ', ';
							$cats .= ',';
						}
						$catLinks .= '<a href="'.$this->plugins_page_url.'?category_name='.$category.'">'.$category.'</a>';
						$cats .= $category;
						$shownCats[] = $category;
						$numCats++;
					}					
				}
			}

			if ( $shownCats !== $categories ) {
				$this->plugins[$plugin_file][$column_name] = $shownCats;
				$this->updatePluginData = true;
			}

			$html = '<div class="pc-val-'.$column_name.'"><span class="pc-cat-values">'.$catLinks.'</span>';
			$html .= '<span class="pc-cat-edit dashicons dashicons-edit" data-plugin="'.$plugin_file.'" data-cats="'.$cats.'"></span>';
			$html .= '<span class="pc-cat-cancel dashicons dashicons-no-alt"></span>';
			$html .= '</div>';
		}		
		else if ( $column_name === 'source' ) {
			$sourceUrl = '';
			if ( isset( $plugin_data['url'] ) ) {
				$sourceUrl = $plugin_data['url'];
			}
			else if ( isset( $plugin_data['PluginURI'] ) ) {
				$sourceUrl = $plugin_data['PluginURI'];
			}
			if ( ! empty( $sourceUrl ) ) {
				$val = '<a href="'.$sourceUrl.'" target="_blank">'.$val.'</a>';
			}
			$html = '<div class="pc-val-'.$column_name.'">'.$val.'</div>';
		}		
		else if ( !empty( $val ) ) {				
			if ( $column_name === 'activated' || $column_name === 'deactivated' || $column_name === 'updated' || $column_name === 'installed' || $column_name === 'last_updated' ) {
				$val = date( $this->get_date_format(), (int)$val );
			}
			else if ( $column_name === 'downloaded' ) {
				$val = number_format($val);
			}

			$html = '<div class="pc-val-'.$column_name.'">'.$val.'</div>';			
		}
		
		echo $html;		
	}
	
	/**
	 * Update plugin data.
	 */
	public function update_plugin_data() {		
		if ( $this->updatePluginData ) {			
			$this->update_option( 'plugin-columns-plugins', $this->plugins );			
			$this->updatePluginData = false;
		}
	}

	/**
	 * Add plugin sort data.
	 */
	public function add_plugin_sort_data( $allPlugins, $plugins = 'plugins' ) {		
		$column_name = urldecode( $_GET['orderby'] );
		$lastChar = chr(255);		
		foreach ( $allPlugins as $plugin_file => $plugin_data ) {
			if ( isset( $plugin_data['Name'] ) ) {
				$val = array();				
				if ( isset( $this->columns[$column_name] ) ) {					
					if ( isset( $this->{$plugins}[$plugin_file][$column_name] ) ) {						
						$val = $this->{$plugins}[$plugin_file][$column_name];						
					}
					if ( $column_name === 'categories' && empty( $val ) ) {
						$val = $lastChar;
					}					
					$allPlugins[$plugin_file][ucfirst($column_name)] = is_array($val)?implode( ',', $val ):$val;
				}
			}
		}		
		return $allPlugins;
	}

	/**
	 * Plugin sort for custom views.
	 */
	public function plugin_sort( $sortable_columns ) {
		global $wp_list_table;
		$plugins = $this->custom_view ? $this->view : 'plugins';
		$wp_list_table->items = $this->add_plugin_sort_data( $wp_list_table->items, $plugins );		
		uasort( $wp_list_table->items, array( $this, 'sort_callback' ) );		
		return $sortable_columns;
	}

	/**
	 * Plugin sort callback.
	 */
	public function sort_callback( $plugin_a, $plugin_b ) {
		global $orderby, $order;

		$a = $plugin_a[$orderby];
		$b = $plugin_b[$orderby];

		if ( $a == $b )
			return 0;

		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			if ( 'DESC' === $order ) {
				return $b - $a;
			} else {
				return $a - $b;
			}
		}
		else {
			if ( 'DESC' === $order ) {
				return strcasecmp( $b, $a );
			} else {
				return strcasecmp( $a, $b );
			}
		}		
	}

	/**
	 * Category filter.
	 */
	public function plugin_category_filter( $sortable_columns ) {
		global $wp_list_table;
		$category_name = urldecode( $_GET['category_name'] );

		if ( strpos( $category_name, ',' ) !== false ) {					
			$filter_categories = explode( ',', $category_name );			
		}
		else {
			$filter_categories = array( $category_name );
		}

		$plugins = 'plugins';
		if ( $this->custom_view ) {
			$plugins = $this->view;
			$filtered = $this->{$plugins};			
		}
		else {
			$filtered = $this->get_plugins();
		}

		foreach ( $filtered as $plugin_file => $plugin_data ) {			
			$match = false;
			if ( ! empty( $this->{$plugins}[$plugin_file]['categories'] ) ) {				
				foreach( $filter_categories as $filter_category ) {
					$plugin_categories = $this->{$plugins}[$plugin_file]['categories'];					
					if ( in_array( $filter_category, $plugin_categories ) ) {					
						$match = true;
						break;
					}
				}				
			}
			if ( ! $match ) {
				unset( $filtered[$plugin_file] );				
			}			
		}
		
		$wp_list_table->items = $filtered;
		
		return $sortable_columns;		
	}

	/**
	 * Redirect filter.
	 */
	public function redirect_filter( $location ) {		
		if ( !empty( $this->options['redirect'] )
		&& isset( $_GET['action'] )
		&& $_GET['action'] === 'activate' 
		&& isset( $_GET['plugin'] )
		&& strpos( $location, 'error=true&plugin' ) !== false ) {			
			$slug = dirname( $_GET['plugin'] );			
			set_transient( "pc_activated_plugin", $slug, 60 );
		}
		else if ( !empty( $this->options['redirect'] ) 
		&& $this->currentPage === 'plugins.php'
		&& basename( strtok( $location, '?' ) ) !== 'plugins.php'
		&& isset( $_GET['activate'] ) ) {			
			$location = $this->get_plugin_url_parameters( $this->plugins_page_url );			
		}
		else if ( isset( $_GET['action'] ) 		
		&& ( isset( $_GET['plugin_status'] ) && $_GET['plugin_status'] === 'all' ) 
		|| ( isset($_GET['action']) && ( $_GET['action'] == 'deactivate' || $_GET['action'] == 'activate' ) ) ) {
			$location = $this->get_plugin_url_parameters( $location );
		}		
		
		return $location;			
	}	

	/**
	 * Filter hidden categories.
	 */
	public function filter_hidden_categories( $plugins ) {
		$filter_categories = $this->get_hidden_categories();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$match = false;
			if ( !empty( $this->plugins[$plugin_file]['categories'] ) ) {
				foreach( $filter_categories as $filter_category ) {
					$plugin_categories = $this->plugins[$plugin_file]['categories'];
					if ( in_array( $filter_category, $plugin_categories ) ) {					
						$match = true;
						break;
					}
				}				
			}
			if ( $match ) {
				unset( $plugins[$plugin_file] );
			}			
		}	
		return $plugins;
	}
	

	/**
	 * Add category filter at the top.
	 */
	public function add_category_filter_select() {
		$html = ob_get_contents();
		ob_end_clean();
		$toReplace = "<div class='tablenav-pages one-page'>";
		$pos = strpos( $html, $toReplace );
		if ( $pos !== false ) {
			$customActions = '';

			if ( ! empty( $this->categories ) ) {
				$category_name = isset($_GET['category_name'])?$_GET['category_name']:'';
				$cats = '';
				if ( ! empty( $this->categories ) ) {
					foreach ( $this->categories as $category ) {
						$selected = '';
						if ( ! empty( $category_name ) && $category_name === $category ) {
							$selected = ' selected';
						}					
						$cats .= '<option value="'.$category.'"'.$selected.'>'.$category.'</option>';
					}
				}
				$customActions = '<div class="pc-category-filter alignleft actions"><label class="screen-reader-text" for="category-filter-select">Filter by category</label>';
				$customActions .= '<select id="category-filter-select"><option value="0">All Categories</option>'.$cats.'</select></div>';
			}

			$colOptions = '';
			$orderby = isset($_GET['orderby'])?$_GET['orderby']:'';			
			$columns = array_merge( array( 'name' => __('Name') ), $this->columns );

			foreach ( $columns as $column => $column_title ) {
				$selected = '';
				if ( ! empty( $orderby ) && $orderby === $column ) {
					$selected = ' selected';
				}			
				$colOptions .= '<option value="'.$column.'"'.$selected.'>'.$column_title.'</option>';
			}
			
			if ( ! $this->custom_view ) {
				$customActions .= '<div class="pc-column-sort alignleft actions"><label class="screen-reader-text" for="column-sort-select">Sort by column</label>';
				$customActions .= '<select id="column-sort-select"><option value="0">Sort by</option>'.$colOptions.'</select></div>';
			}			
			else if ( $this->view === 'trash' ) {
				$customActions .= '<div class="alignleft actions"><input type="submit" name="remove_all" id="pc-category-empty-trash" class="button apply" value="Empty Trash"></div>';
			}
			$customActions .= $toReplace;
			$html = substr_replace( $html, $customActions, $pos, strlen($toReplace) );
		}
		echo $html;
	}
	
	/**
	 * Add custom views links.
	 */
	public function add_views_link( $views ) {
		// Imported	
		if ( ! empty( $this->imported ) ) {
			$num = count( $this->imported );
			$current = $this->view === 'imported' ? ' class="current"' : '';			
			$views['imported'] = '<a href="plugins.php?plugin_status=imported"'.$current.'>Imported <span class="count">(<span id="imported-count">'.$num.'</span>)</span></a>';
		}		
		
		// Trash
		if ( ! empty( $this->trash ) ) {		
			$num = count( $this->trash );
			$current = $this->view === 'trash' ? ' class="current"' : '';		
			$views['trash'] = '<a href="plugins.php?plugin_status=trash"'.$current.'>Trash <span class="count">(<span id="trash-count">'.$num.'</span>)</span></a>';
		}

		if ( isset( $views['all'] ) && $this->custom_view ) {
			$views['all'] = str_replace( 'current', '', $views['all'] );
		}
		
		return $views;
	}

	/**
	 * Modify the action links for the custom view.
	 */
	public function custom_view_actions( $actions, $plugin_file, $plugin_data, $context ) {
		global $page, $s;		
		$actions = array();

		if ( current_user_can( 'install_plugins' ) ) {
			$plugins_allowedtags = array(
				'a' => array( 'href' => array(),'title' => array(), 'target' => array() ),
				'abbr' => array( 'title' => array() ),'acronym' => array( 'title' => array() ),
				'code' => array(), 'pre' => array(), 'em' => array(),'strong' => array(),
				'ul' => array(), 'ol' => array(), 'li' => array(), 'p' => array(), 'br' => array()
			);
			$title = wp_kses( $plugin_data['Name'], $plugins_allowedtags );
			$version = wp_kses( $plugin_data['Version'], $plugins_allowedtags );
			$name = strip_tags( $title . ' ' . $version );
			$slug = dirname( $plugin_file );
			$url = wp_nonce_url(self_admin_url('update.php?action=install-plugin&plugin=' . $slug), 'install-plugin_' . $slug);
			$actions['install'] = '<div class="plugin-card plugin-card-'.$slug.'"><a class="install-now cv-action-'.($this->view).'" data-slug="' . esc_attr( $slug ) . '" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( sprintf( __( 'Install %s now' ), $name ) ) . '" data-name="' . esc_attr( $name ) . '">' . __( 'Install' ) . '</a></div>';
		}
		
		$actions['remove'] = '<a href="javascript:;" class="pcremove cv-action-'.($this->view).'" data-plugin="' . esc_attr( $plugin_file ) . '" aria-label="' . esc_attr( sprintf( _x( 'Remove %s from imported plugin list', 'plugin' ), $plugin_data['Name'] ) ) . '">' . __( 'Remove' ) . '</a>';
		
		$details_link = self_admin_url( 'plugin-install.php?tab=plugin-information&amp;plugin='.$slug.'&amp;TB_iframe=true&amp;width=600&amp;height=800' );
		$actions['more_details'] = '<a href="' . esc_url( $details_link ) . '" class="thickbox open-plugin-details-modal" aria-label="' . esc_attr( sprintf( __( 'More information about %s' ), $name ) ) . '" data-title="' . esc_attr( $name ) . '">' . __( 'More Details' ) . '</a>';		
		
		return $actions;
	}

	/**
	 * Filter custom views.
	 */
	public function custom_view_plugins_filter( $sortable_columns ) {
		global $wp_list_table;			
		if ( $this->custom_view ) {
			$wp_list_table->items = $this->{$this->view};
		}
		return $sortable_columns;
	}		
	
	/**
	 * Import options from a file.
	 */
	public function import() {
		if ( ! isset( $_FILES['pcimport'] ) ) return;

		check_admin_referer( 'pcimportexport' );		
		
		if ( $_FILES['pcimport']['error'] == UPLOAD_ERR_OK && is_uploaded_file( $_FILES['pcimport']['tmp_name'] ) ) {
			$importData = file_get_contents( $_FILES['pcimport']['tmp_name'] );
			$data = json_decode( $importData, true );			
			if ( json_last_error() === JSON_ERROR_NONE && ( ! empty( $data['plugins'] ) || ! empty( $data['categories'] ) ) ) {				
				if ( !empty( $data['plugins'] ) ) {					
					$allPlugins = get_plugins();
					$update = $updateNI = false;
					$backup = isset( $data['type'] ) && $data['type'] === 'backup';
					foreach ( $data['plugins'] as $plugin_file => $values ) {						
						if ( isset( $allPlugins[$plugin_file] ) ) {
							if ( $backup ) {
								if ( isset( $values['info'] ) ) {
									unset( $values['info'] );
								}
								$this->plugins[$plugin_file] = $values;
							}
							else {
								if ( !isset( $this->plugins[$plugin_file] ) ) {
									$this->plugins[$plugin_file]['categories'] = array();
								}							
								if ( !empty( $values['categories'] ) ) {
									$this->plugins[$plugin_file]['categories'] = $this->array_concat( $this->plugins[$plugin_file]['categories'], $values['categories'] );
								}
							}

							$update = true;
						}
						else {
							if ( isset( $this->plugins[$plugin_file] ) ) {
								unset( $this->plugins[$plugin_file] );
								$update = true;
							}

							if ( !isset( $this->imported[$plugin_file] ) && isset( $values['info'] ) ) {							
								$this->imported[$plugin_file] = $values['info'];
								if ( ! empty( $values['categories'] ) ) {
									$this->imported[$plugin_file]['categories'] = $values['categories'];
								}
								$updateNI = true;
							}
						}
					}

					if ( $update ) {						
						$this->update_option( 'plugin-columns-plugins', $this->plugins );
					}
					if ( $updateNI ) {
						$this->update_option( 'plugin-columns-imported-plugins', $this->imported );
					}
				}
				if ( !empty( $data['categories'] ) ) {
					if ( !empty( $this->categories ) && !$backup ) {
						$this->categories = $this->array_concat( $this->categories, $data['categories'] );
					}
					else {
						$this->categories = $data['categories'];
					}
					$this->update_option( 'plugin-columns-categories', $this->categories );
				}
				if ( !empty( $data['pinned_categories'] ) ) {
					if ( !empty( $this->pinned_categories ) && !$backup ) {
						$this->pinned_categories = $this->array_concat( $this->pinned_categories, $data['pinned_categories'] );
					}
					else {
						$this->pinned_categories = $data['pinned_categories'];
					}
					$this->update_option( 'plugin-columns-pinned-categories', $this->pinned_categories );
				}
				if ( !empty( $data['hidden_categories'] ) ) {
					if ( !empty( $this->hidden_categories ) && !$backup ) {
						$this->hidden_categories = $this->array_concat( $this->hidden_categories, $data['hidden_categories'] );
					}
					else {
						$this->hidden_categories = $data['hidden_categories'];
					}
					$this->update_option( 'plugin-columns-hidden-categories', $this->hidden_categories );
				}
				if ( !empty( $data['warning_categories'] ) ) {
					if ( !empty( $this->warning_categories ) && !$backup ) {
						$this->warning_categories = $this->array_concat( $this->warning_categories, $data['warning_categories'] );
					}
					else {
						$this->warning_categories = $data['warning_categories'];
					}
					$this->update_option( 'plugin-columns-warning-categories', $this->warning_categories );
				}
				if ( !empty( $data['noupdate_categories'] ) ) {
					if ( !empty( $this->noupdate_categories ) && !$backup ) {
						$this->noupdate_categories = $this->array_concat( $this->noupdate_categories, $data['noupdate_categories'] );
					}
					else {
						$this->noupdate_categories = $data['noupdate_categories'];
					}
					$this->update_option( 'plugin-columns-noupdate-categories', $this->noupdate_categories );
				}
				if ( !empty( $data['noupdate_plugins'] ) ) {
					if ( !empty( $this->noupdate_plugins ) && !$backup ) {
						$this->noupdate_plugins = $this->array_concat( $this->noupdate_plugins, $data['noupdate_plugins'] );
					}
					else {
						$this->noupdate_plugins = $data['noupdate_plugins'];
					}
					$this->update_option( 'plugin-columns-noupdate-plugins', $this->noupdate_plugins );
				}
				if ( !empty( $data['imported'] ) ) {
					if ( !empty( $this->imported ) && !$backup ) {
						$this->imported = $this->array_concat( $this->imported, $data['imported'] );
					}
					else {
						$this->imported = $data['imported'];
					}
					$this->update_option( 'plugin-columns-imported-plugins', $this->imported );
				}
				if ( !empty( $data['trash'] ) ) {
					if ( !empty( $this->trash ) && !$backup ) {
						$this->trash = $this->array_concat( $this->trash, $data['trash'] );
					}
					else {
						$this->trash = $data['trash'];
					}
					$this->update_option( 'plugin-columns-trash', $this->trash );
				}
				if ( !empty( $data['options'] ) ) {
					if ( !empty( $this->options ) && !$backup ) {
						$this->options = $this->array_concat( $this->options, $data['options'] );
					}
					else {
						$this->options = $data['options'];
					}
					$this->update_option( 'plugin-columns-options', $this->options );
				}
			} else { 
				$this->error = 'Import failed';
			} 
		}
	}

	/**
	 * Export plugins and data to a file.
	 */
	public function export( $type = 'export' ) {
		if ( $type === 'export' && ( !isset( $_POST['pcexport'] ) || ( empty( $this->plugins ) && empty( $this->categories ) ) ) ) return;
		else if ( $type === 'backup' && ( !isset( $_POST['pcbackup'] ) || empty( $this->plugins ) ) ) return;
		check_admin_referer( 'pcimportexport' );
		$site_title = sanitize_key( get_bloginfo( 'name' ) );
		$date = date("Y-m-d");
		$data = array();
		$data['type'] = $type;
		$exportPlugins = array();
		$plugins = get_plugins();		
		
		
		foreach ( $this->plugins as $key => $plugin ) {	
			if ( isset( $plugins[$key] ) ) {
				if ( $type === 'backup' ) {
					$exportPlugins[$key] = $plugin;
				}
				else if ( !empty( $plugin['categories'] ) ) {
					$exportPlugins[$key]['categories'] = $plugin['categories'];
				}
				$exportPlugins[$key]['info'] = $plugins[$key];
			}			
		}

		if ( $type === 'backup' ) {
			if ( !empty( $this->categories ) ) $data['categories'] = $this->categories;
			if ( !empty( $this->pinned_categories ) ) $data['pinned_categories'] = $this->pinned_categories;
			if ( !empty( $this->hidden_categories ) ) $data['hidden_categories'] = $this->hidden_categories;
			if ( !empty( $this->warning_categories ) ) $data['warning_categories'] = $this->warning_categories;
			if ( !empty( $this->noupdate_categories ) ) $data['noupdate_categories'] = $this->noupdate_categories;
			if ( !empty( $this->noupdate_plugins ) ) $data['noupdate_plugins'] = $this->noupdate_plugins;
			if ( !empty( $this->imported ) ) $data['imported'] = $this->imported;
			if ( !empty( $this->trash ) ) $data['trash'] = $this->trash;
			if ( !empty( $this->options ) ) $data['options'] = $this->options;
		}
		
		if ( isset( $_POST['pc-export-include-imported'] ) && !empty( $this->imported ) ) {			
			foreach ( $this->imported as $key => $plugin ) {
				if ( isset( $plugin['categories'] ) ) {
					if ( ! empty( $plugin['categories'] ) ) {
						$exportPlugins[$key]['categories'] = $plugin['categories'];					
					}
					unset( $plugin['categories'] );
				}				
				$exportPlugins[$key]['info'] = $plugin;
			}			
		}

		if ( isset( $_POST['pc-export-option'] ) ) {
			$catOption = trim( $_POST['pc-export-option'] );
			if ( $catOption !== '0' ) {				
				$data['categories'] = $this->categories;
				if ( in_array( $catOption, $this->categories ) ) {
					$data['plugins'] = array();					
					foreach ( $exportPlugins as $key => $plugin ) {						
						if ( in_array( $catOption, $plugin['categories'] ) ) {							
							$data['plugins'][$key] = $exportPlugins[$key];
						}
					}
				} else {
					$data['plugins'] = $exportPlugins;
				}
			}	
		}
		else {			
			$data['plugins'] = $exportPlugins;			
		}

		$filename = sanitize_file_name( 'plugin-columns-'.$type.'-'.$site_title.'-'.$date.'.json"' );

		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="'.$filename.'"');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		echo json_encode( $data );
		exit;
	}

	/**
	 * Backup column data to a file.
	 */
	public function backup() {
		$this->export('backup');
	}

	/**
	 * pin categories to the plugins submenu.
	 */
	public function pin_category() {
		if ( ! isset( $_POST['pcadmin-categories'] ) ) return;
		$pinned_categories = array();
		$this->pinned_categories = $this->get_option( 'plugin-columns-pinned-categories', array() );
		if ( isset( $this->pinned_categories[$this->get_site_id()] ) ) {
			$pinned_categories = $this->pinned_categories[$this->get_site_id()];
		}		
		$pinned = !empty( $_POST['category_pinned'] ) ? explode( ',', $_POST['category_pinned'] ) : array();
		$categories = array_map( 'sanitize_text_field', explode( ',', $_POST['pcadmin-categories'] ) );
		$pinned = array_intersect( $categories, $pinned );

		if ( $pinned_categories != $pinned ) {
			$this->pinned_categories[$this->get_site_id()] = $pinned;			
			$this->update_option( 'plugin-columns-pinned-categories', $this->pinned_categories );			
		}
	}	

	/**
	 * Redirect pinned category.
	 */
	public function pinned_category_redirect() {
		if ( isset( $_GET['page'] ) && strpos( $_GET['page'], '-plugin-columns-category-page' ) !== false ) {
			$category = str_replace( '-plugin-columns-category-page', '', urlencode( $_GET['page'] ) );	
			wp_redirect( self_admin_url( 'plugins.php' ) . '?category_name='.$category );
			exit;
		}
	}

	/**
	 * Handle form post actions.
	 */
	public function form_post_action() {				
		check_admin_referer( 'pcadmin' );
				
		if ( isset( $_POST['pc-empty-trash'] ) ) {			
			if ( ! empty( $this->trash ) ) {				
				$this->trash = array();
				$this->update_option( 'plugin-columns-trash', $this->trash );
				wp_safe_redirect( $this->plugins_page_url );
				exit;
			}
		}

		else if ( isset( $_POST['pcclear'] ) ) {

			if ( isset( $_POST['clearcol'] ) && ! empty( $this->plugins ) ) {
				foreach( $_POST['clearcol'] as $col => $cbValue ) {
					foreach ( $this->plugins as $plugin => $value ) {
						if ( $col === 'activated' || $col === 'deactivated' || $col === 'activatedcounter' ) {
							if ( is_multisite() && is_network_admin() ) {							
								foreach ( get_sites() as $site ) {
									if ( isset( $this->plugins[$plugin][$col.'_'.$site->blog_id] ) ) {								
										unset( $this->plugins[$plugin][$col.'_'.$site->blog_id] );
									}								
								}
							}
						}
						if ( isset( $this->plugins[$plugin][$col] ) ) {							
							unset( $this->plugins[$plugin][$col] );
						}
					}

					$this->updatePluginData = true;
				}
			}
			
			if ( isset( $_POST['clearimported'] ) ) {
				$this->delete_option( 'plugin-columns-imported-plugins' );
			}

			if ( isset( $_POST['clearnoupdateplugins'] ) ) {				
				$this->delete_option( 'plugin-columns-noupdate-categories' );
				$this->delete_option( 'plugin-columns-noupdate-plugins' );
			}			
		}

		else if ( isset( $_POST['pc-options-update-meta'] ) ) {			
			$this->getMetaInfo = true;
			$this->metaInfoUpdate = isset( $_POST['pc-update-meta-ctrl'] ) ? false : true;
		}
		
		else if ( isset( $_POST['pcadmin-categories'] ) ) {
			$cats = array();
			$catsChanged = false;
			
			if ( ! empty( $_POST['pcadmin-categories'] ) ) {			
				$cats = array_map( 'sanitize_text_field', explode( ',', $_POST['pcadmin-categories'] ) );
				sort( $cats );
			}
			
			if ( $this->array_changed( $this->categories, $cats ) ) {
				$this->categories = $cats;
				$this->update_option( 'plugin-columns-categories', $this->categories );
				$catsChanged = true;
			}			

			if ( $catsChanged || isset( $_POST['category_hidden'] ) ) {
				$hiddenCategories = array();				
				if ( !empty( $_POST['category_hidden'] ) ) {
					$hiddenCategories = explode( ',', $_POST['category_hidden'] );
				}
				else if ( $catsChanged ) {
					$hiddenCategories = $this->get_hidden_categories();					
				}
				if ( $catsChanged || $this->array_changed( $this->get_hidden_categories() , $hiddenCategories ) ) {
					$this->hidden_categories[$this->get_site_id()] = array_intersect( $this->categories, $hiddenCategories );
					$this->update_option( 'plugin-columns-hidden-categories', $this->hidden_categories );					
				}
			}

			if ( $catsChanged || isset( $_POST['category_warning'] ) ) {
				$warningCategories = array();				
				if ( !empty( $_POST['category_warning'] ) ) {
					$warningCategories = explode( ',', $_POST['category_warning'] );
				}
				else if ( $catsChanged ) {
					$warningCategories = $this->get_warning_categories();					
				}
				if ( $catsChanged || $this->array_changed( $this->get_warning_categories() , $warningCategories ) ) {
					$this->warning_categories[$this->get_site_id()] = array_intersect( $this->categories, $warningCategories );					
					$this->update_option( 'plugin-columns-warning-categories', $this->warning_categories );					
				}
			}

			if ( $catsChanged || isset( $_POST['category_noupdate'] ) ) {
				$noupdateCategories = array();				
				if ( !empty( $_POST['category_noupdate'] ) ) {
					$noupdateCategories = explode( ',', $_POST['category_noupdate'] );
				}
				else if ( $catsChanged ) {
					$noupdateCategories = $this->get_noupdate_categories();					
				}
				if ( $catsChanged || $this->array_changed( $this->get_noupdate_categories() , $noupdateCategories ) ) {
					$this->noupdate_categories = array_intersect( $this->categories, $noupdateCategories );					
					$this->update_option( 'plugin-columns-noupdate-categories', $this->noupdate_categories );					
					$this->update_noupdate_plugins_list();
				}
			}

			$updateOptions = false;

			if ( isset( $_POST['pc-dateformat'] ) ) {
				$format = $_POST['pc-dateformat'];
				if ( empty( $this->options['dateformat'] ) || $this->options['dateformat'] != $format )	{
					$this->options['dateformat'] = $format;
					$updateOptions = true;
				}				
			}

			if ( isset( $_POST['pc-deactivate-warning'] ) ) {
				$warning = $_POST['pc-deactivate-warning'];
				if ( empty( $this->options['deactivate-warning'] ) || $this->options['deactivate-warning'] != $warning )	{				
					$this->options['deactivate-warning'] = $_POST['pc-deactivate-warning'];
					$updateOptions = true;
				}
			}

			$metaInfo = false;

			if ( isset( $_POST['pc-options-misc'] ) ) {
				$miscOptions = $_POST['pc-options-misc'];
				$metacols = isset( $miscOptions['metacols'] );
				$redirect = isset( $miscOptions['redirect'] );
				$feedback = isset( $miscOptions['feedback'] );
				$sticky = isset( $miscOptions['sticky'] );
				$screenshots = $metacols && isset( $miscOptions['screenshots'] );				

				if ( !isset( $this->options['metacols'] ) || $metacols !== $this->options['metacols'] ) {
					$this->options['metacols'] = $metaInfo = $metacols;					
					$updateOptions = true;					
				}

				if ( !isset( $this->options['redirect'] ) || $redirect !== $this->options['redirect'] ) {
					$this->options['redirect'] = $redirect;
					$updateOptions = true;
				}

				if ( !isset( $this->options['feedback'] ) || $feedback !== $this->options['feedback'] ) {
					$this->options['feedback'] = $feedback;
					$updateOptions = true;
				}
				
				if ( !isset( $this->options['screenshots'] ) || $screenshots !== $this->options['screenshots'] ) {				
					$this->options['screenshots'] = $screenshots;
					$updateOptions = true;
				}

				if ( !isset( $this->options['sticky'] ) || $sticky !== $this->options['sticky'] ) {				
					$this->options['sticky'] = $sticky;
					$updateOptions = true;
				}
			}

			if ( $updateOptions ) {
				if ( $metaInfo ) {
					$this->getMetaInfo = true;
					$this->metaInfoUpdate = false;					
				}

				$this->update_option( 'plugin-columns-options', $this->options );
			}
		}		
	}	 
	
	/**
	 * Ajax handler.
	 */
	public function plugin_columns_action() {
		check_ajax_referer( 'pcadmin-ajax' );
		$data = array();
		$plugin = $_POST['plugin'];

		// Get meta info for plugins
		if ( !empty( $_POST['get_plugin_meta'] ) ) {
			$pluginList = $_POST['get_plugin_meta'];
			$update = isset( $_POST['plugin_meta_update'] ) && $_POST['plugin_meta_update'] === 'true' ? true : false;
			$data['success'] = $this->get_meta_info( $pluginList, $update );			
			$data['result'] = $pluginList;
		}

		// Remove plugin from imported list
		else if ( isset( $_POST['plugin_remove'] ) ) {
			$plugin = $_POST['plugin_remove'];
			$view = $_POST['pcview'];
			
			if ( $view === 'imported' ) {
				if ( isset( $this->imported[$plugin] ) ) {
					unset( $this->imported[$plugin] );
					$this->update_option( 'plugin-columns-imported-plugins', $this->imported );
					$data['success'] = 'Plugin Removed';
				}				
			}
			else if ( $view === 'trash' ) {
				if ( isset( $this->trash[$plugin] ) ) {
					unset( $this->trash[$plugin] );
					$this->update_option( 'plugin-columns-trash', $this->trash );
					$data['success'] = 'Plugin Removed';
				}				
			}
			
			if ( ! isset( $data['success'] ) ) {
				$data['failure'] = "Plugin doesn\'t exist in the $view list.";
			}
		}

		// Bulk edit categories
		else if ( isset( $_POST['categories_add'] ) || isset( $_POST['categories_remove'] ) ) {			
			if ( isset( $_POST['plugins'] ) ) {	
				$update = false;
				$plugins = 'plugins';

				if ( $this->custom_view ) {
					$plugins = $this->view;
				}

				foreach( $_POST['plugins'] as $plugin ) {					
					$cats = array();
					if ( isset( $this->{$plugins}[$plugin]['categories'] ) ) {
						$cats = $this->{$plugins}[$plugin]['categories'];
					}
					if ( ! empty( $_POST['categories_add'] ) ) {						
						foreach( $_POST['categories_add'] as $category ) {							
							if ( ! in_array( $category, $cats ) ) {
								array_push( $cats, $category );
								$this->{$plugins}[$plugin]['categories'] = $cats;
								$update = true;
							}
						}
					}
					if ( ! empty( $_POST['categories_remove'] ) ) {
						foreach( $_POST['categories_remove'] as $category ) {
							if ( in_array( $category, $cats ) ) {
								$cats = array_diff( $cats, array( $category ) );
								$this->{$plugins}[$plugin]['categories'] = $cats;
								$update = true;
							}
						}
					}
				}
				if ( $update ) {
					sort( $this->{$plugins}[$plugin]['categories'] );
					$this->update_plugins();
					$this->update_noupdate_plugins_list( $this->{$plugins} );
					$data['bulk_edit'] = 'success';
				}
				else {
					$data['bulk_edit'] = 'no changes';
				}
			}			
		}

		// Delete category
		else if ( isset( $_POST['category_delete'] ) ) {
			$category = $_POST['category_delete'];
			if ( !empty( $this->categories ) && in_array( $category, $this->categories ) ) {
				$this->categories = array_diff( $this->categories, array( $category ) );
				$this->update_option( 'plugin-columns-categories', $this->categories );
				$data['deleteCategory'] = $category;
				if ( !empty( $this->noupdate_categories ) && in_array( $category, $this->noupdate_categories ) ) {
					$this->update_noupdate_plugins_list();
				}
			}
		}

		// Create category
		else if ( isset( $_POST['category_create'] ) ) {
			$categories = $_POST['category_create'];
			$categories = explode(',', $categories);			
			$data['createCategory'] = $this->add_categories( $categories );					
		}

		// Add/remove plugins to/from category
		else if ( isset( $_POST['categories'] ) ) {
			$categories = sanitize_text_field( $_POST['categories'] );
			$plugins = 'plugins';
			if ( $this->custom_view ) {
				$plugins = $this->view;
			}

			if ( !empty( $categories ) ) {
				$categories = explode(',', $categories);				

				// Add new categories
				$data['newCats'] = $this->add_categories( $categories );

				sort( $categories );

				// Add category to plugin
				if ( ! isset( $this->{$plugins}[$plugin]['categories'] ) || $this->{$plugins}[$plugin]['categories'] !== $categories ) {					 					
					$this->{$plugins}[$plugin]['categories'] = $categories;					
					$this->update_plugins();					
					$this->update_noupdate_plugins_list( $this->{$plugins}, $plugin, 'add' );
					$data['update'] = 'success';					
				}
				else {
					$data['update'] = 'not updated';
				}
			}

			// Remove categories from plugin
			else if ( !empty( $this->{$plugins}[$plugin]['categories'] ) ) {				
				if ( !empty( $this->noupdate_categories ) ) {
					$categories = $this->{$plugins}[$plugin]['categories'];
					foreach( $categories as $category ) {
						if ( in_array( $category, $this->noupdate_categories ) ) {
							$this->update_noupdate_plugins_list( $this->{$plugins}, $plugin, 'remove' );							
							break;			
						}
					}					
				}
				$this->{$plugins}[$plugin]['categories'] = '';
				$this->update_plugins();								
				$data['update'] = 'success';
				$data['delete'] = 'Categories deleted';
			}
		}		

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode( $data );
		wp_die();	
	}	
	
	/**
	 * Register bulk actions.
	 */
	public function register_bulk_actions( $bulk_actions ) {		
		if ( $this->custom_view ) {
			$bulk_actions = array();
			$bulk_actions['install_plugins'] = 'Install';
			$bulk_actions['remove_plugins'] = 'Remove';
		}		
		
		if ( $this->view !== 'dropins' && $this->view !== 'mustuse' ) {
			$bulk_actions['edit_categories'] = 'Categories';
		}		
		
  		return $bulk_actions;
	}

	/**
	 * Handle bulk actions.
	 */
	public function bulk_action_handler( $sendback, $action, $plugins ) {
		$updateImported = $updateTrash = false;

		if ( $action === 'install_plugins' ) {
			if ( ! current_user_can('install_plugins') ) wp_die( __( 'Sorry, you are not allowed to install plugins on this site.' ) );
			include_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );			
			include_once( ABSPATH . 'wp-admin/includes/plugin-install.php' ); //for plugins_api..	
			include_once( ABSPATH . 'wp-admin/admin-header.php');			
			
			foreach ( $plugins as $plugin ) {
				$plugin = dirname( $plugin );
				$api = plugins_api( 'plugin_information', array(
					'slug' => $plugin,
					'fields' => array(
						'short_description' => false,
						'sections' => false,
						'requires' => false,
						'rating' => false,
						'ratings' => false,
						'downloaded' => false,
						'last_updated' => false,
						'added' => false,
						'tags' => false,
						'compatibility' => false,
						'homepage' => false,
						'donate_link' => false,
					),
				) );

				if ( is_wp_error( $api ) ) {
					wp_die( $api );
				}			

				$title = __('Plugin Installation');
				$parent_file = 'plugins.php';
				$submenu_file = 'plugin-install.php';
				$title = sprintf( __('Installing Plugin: %s'), $api->name . ' ' . $api->version );
				$nonce = 'install-plugin_' . $plugin;
				$url = 'update.php?action=install-plugin&plugin=' . urlencode( $plugin );
				$type = 'web'; //Install plugin type, From Web or an Upload.

				$upgrader = new Plugin_Upgrader( new Plugin_Installer_Skin( compact('title', 'url', 'nonce', 'plugin', 'api') ) );
				$upgrader->install($api->download_link);

				if ( $this->view === 'imported' ) {
					if ( isset( $this->imported[$plugin] ) ) {
						unset( $this->imported[$plugin] );
						$updateImported = true;
					}
				}
				else if ( $this->view === 'trash' ) {
					if ( isset( $this->trash[$plugin] ) ) {
						unset( $this->trash[$plugin] );
						$updateTrash = true;
					}
				}
			}			
		}

		else if ( $action === 'remove_plugins' ) {			
			foreach ( $plugins as $plugin ) {				
				if ( $this->view === 'imported' ) {					
					if ( isset( $this->imported[$plugin] ) ) {
						unset( $this->imported[$plugin] );
						$updateImported = true;
					}
				}
				else if ( $this->view === 'trash' ) {
					if ( isset( $this->trash[$plugin] ) ) {
						unset( $this->trash[$plugin] );
						$updateTrash = true;
					}
				}				
			}
			
			$viewLink = '?plugin_status=imported';
			if ( ( $this->view === 'imported' && empty( $this->imported ) )
			|| ( $this->view === 'trash' && empty( $this->trash ) ) ) {
				$viewLink = '';
			}			
			$sendback = $this->plugins_page_url.$viewLink;
		}

		if ( $updateImported ) {
			$this->update_option( 'plugin-columns-imported-plugins', $this->imported );
		}
		if ( $updateTrash ) {
			$this->update_option( 'plugin-columns-trash', $this->trash );
		}
		
  		return $sendback;
	}	

	/**
	 * Custom html for the plugin page.
	 */
	public function plugin_columns_top_html() {
		$catOptions = $catList = $colOptions = $colList = $exportOptions = $optionLinks = $miscOptions = $clearList = $metaUpdate = $screenshotsOption = '';

		if ( !empty( $this->categories ) ) {
			$pinned_categories = $this->get_pinned_categories();
			$hidden_categories = $this->get_hidden_categories();
			$warning_categories = $this->get_warning_categories();
			$noupdate_categories = $this->get_noupdate_categories();
			$enabledClass = ' catfeature-enabled';
			
			foreach ( $this->categories as $category ) {
				$pinned = in_array( $category, $pinned_categories ) ? $enabledClass : '';
				$categoryHidden = in_array( $category, $hidden_categories ) ? $enabledClass : '';
				$deactivateCategory = in_array( $category, $warning_categories ) ? $enabledClass : '';
				$noupdateCategory = in_array( $category, $noupdate_categories ) ? $enabledClass : '';
				$catOptions .= '<option value="'.$category.'">'.$category.'</option>';
				$catList .= '<li id="category-admin-'.$category.'" class="pc-catadm-item"><span class="pc-delcat dashicons dashicons-no" title="Delete Category"></span> '.$category.'
				 <span class="pc-cat-toggle pc-cat-pin dashicons dashicons-admin-post'.$pinned.'" title="Pin to the plugins menu"></span>
				 <span class="pc-cat-toggle pc-cat-hide dashicons dashicons-hidden'.$categoryHidden.'" title="Hide from the plugin list (will show in category filter lists)"></span>
				 <span class="pc-cat-toggle pc-cat-warning dashicons dashicons-warning'.$deactivateCategory.'" title="Show the warning message below on plugin deactivation"></span>
				 <span class="pc-cat-toggle pc-cat-noupdate dashicons dashicons-lock'.$noupdateCategory.'" title="Prevent plugin update"></span>
				 </li>';
			}				
		}

		if ( empty( $catList ) ) {
			$catList = '<li id="pc-nocats">No Categories</li>';
		}

		$columns = array_merge( array( 'description' => __('Description') ), $this->columns );

		foreach ( $columns as $column => $column_title ) {			
			$colList .= '<li><label><input type="checkbox" name="'.$column.'" class="pc-col-checkbox"> '.$column_title.'</label></li>';
			if ( $column !== 'description' ) {
				$clearList .= '<li><label><input type="checkbox" name="clearcol['.$column.']" class="pc-col-checkbox">'.$column_title.'</label></li>';
			}
		}

		// Category edit for plugin columns
		$html = '<div class="pc-modal-overlay"></div>';			
		$html .= '<div class="pc-categories-input"><select name="categories[]" multiple="multiple">';			
		$html .= $catOptions.'</select></div>';

		$multisiteLabel = '';

		if ( is_multisite() && ! is_network_admin() ) {
			$multisiteLabel = ' (current site)';
		}		

		if  ( ! empty( $this->imported ) ) {
			$clearList .= '<li><label><input type="checkbox" name="clearimported" class="pc-col-checkbox">Imported plugins list</label></li>';
		}

		if  ( ! empty( $this->noupdate_plugins ) ) {
			$clearList .= '<li><label><input type="checkbox" name="clearnoupdateplugins" class="pc-col-checkbox">Update block plugins list</label></li>';
		}

		$clearList .= '<li><label><input type="checkbox" name="clearall" class="pc-col-checkbox">All</label></li>';
		
		if ( ! empty( $catOptions ) ) {
			$exportOptions = '
			<label>Export</label><select class="pc-export-select" name="pc-export-option">
			<option value="-1">All Plugins and Categories</option><option value="0">All Plugins without Categories</option>'.$exportOptions.$catOptions.'</select>
			';			
		}

		if ( ! empty( $this->imported ) ) {
			$exportOptions .= '<label class="pc-export-include-label"><input type="checkbox" name="pc-export-include-imported"> Include imported plugins</label>';
		}

		if ( ! is_multisite() || is_network_admin() ) {
			$optionLinks = '<div class="pc-misc-options"><a href="javascript:;" id="pc-export" title="Export plugins and categories">Export</a> / <a href="javascript:;" id="pc-import" title="Import plugins and categories">Import</a> / <a href="javascript:;" id="pc-backup" title="Backup column data. Use import to restore.">Backup</a> / <a href="javascript:;" id="pc-option-clear" title="Clear column data">Clear</a></div>';
			$miscOptions = '
			<form method="post" enctype="multipart/form-data" id="pc-import-form"><input name="pcimport" type="file" id="pc-import-input">'.(wp_nonce_field( "pcimportexport" )).'</form>
			<form method="post" id="pc-clear-form">
			<label>Clear</label>
			<ul class="pc-clear-options-list">'.$clearList.'</ul>
			<input type="hidden" name="pcclear">'.(wp_nonce_field( "pcadmin" )).'
			<input type="submit" name="clear-options-apply" id="clear-options-apply" class="button button-primary" value="Clear">
			</form>
			<form method="post" id="pc-export-form"><input type="hidden" name="pcexport">
			'.$exportOptions.'
			'.(wp_nonce_field( "pcimportexport" )).'
			<input type="submit" name="export-options-apply" id="export-options-apply" class="button button-primary" value="Export">
			</form>
			<form method="post" id="pc-backup-form"><input type="hidden" name="pcbackup">
			'.(wp_nonce_field( "pcimportexport" )).'
			</form>
			';
		}

		$deactivateWarning = $this->deactivateWarning;
		if ( isset( $this->options['deactivate-warning'] ) ) {
			$deactivateWarning = $this->options['deactivate-warning'];
		}

		$deactivateCategory = '';
		if ( isset( $this->warning_categories ) ) {
			$deactivateCategory = $this->warning_categories;
		}

		$dateformat = isset( $this->options['dateformat'] ) ? $this->options['dateformat'] : '';
		$metacols = !empty( $this->options['metacols'] ) ? ' checked' : '';
		$redirect = !empty( $this->options['redirect'] ) ? ' checked' : '';
		$feedback = !empty( $this->options['feedback'] ) ? ' checked' : '';
		$screenshots = !empty( $this->options['screenshots'] ) ? ' checked' : '';
		$sticky = !empty( $this->options['sticky'] ) ? ' checked' : '';

		if ( !empty( $this->options['metacols'] ) ) {
			$metaUpdate = '<span class="pc-meta-update dashicons dashicons-update" title="Click to update the meta info for all plugins, or Ctrl - click to only update plugins missing meta info."></span>';
			$screenshotsOption = '<li title="Will show screenshot links for the Fancybox viewer in the description columns."><label><input type="checkbox" name="pc-options-misc[screenshots]" class="pc-col-checkbox"'.$screenshots.'>Show plugin screenshots</label></li>';
		}
		$metaColsTitle = "Rating, downloads, last updated, added and screenshots.\nFetches the info from Wordpress.org, so it will take some time when enabled.\nThis information will be cached, so it will not slow down stuff later.";

		// Options dialog
		$html .= '<div class="pc-options-dialog">
		<div class="pc-options-tabs"><a href="javascript:;" id="pc-tab-columns-link" class="active">Columns</a><a href="javascript:;" id="pc-tab-options-link">Options</a></div>
		<div class="pc-tab-columns pc-tab">		
		<ul class="pc-column-admin">'.$colList.'</ul></div>
		<div class="pc-tab-options pc-tab"><form method="post" id="pc-category-edit-form">		
		<div class="pc-category-options"><label>Categories</label><ul class="pc-category-admin">'.$catList.'</ul>			
		<div class="pc-category-admin-add"><input type="text" placeholder="Add new Category" id="pc-cat-add-input"> <input type="button" value="Add" class="button"></div>
		<div class="pc-dateformat-options">
		<label>Deactivation Warning</label>
		<textarea id="sopc-options-warning-text" name="pc-deactivate-warning">'.$deactivateWarning.'</textarea>
		<p class="sopc-options-desc">Enable category above to show this message.</p>		
		<label>Date Format <span class="pc-options-desc"><a href="https://codex.wordpress.org/Formatting_Date_and_Time" target="_blanc" title="Click here for more info on dateformatting">?</a></span></label>
		<input type="text" name="pc-dateformat" placeholder="Y.m.d" value="'.$dateformat.'">		
		<ul>
		<li title="'.$metaColsTitle.'"><label><input type="checkbox" name="pc-options-misc[metacols]" class="pc-col-checkbox"'.$metacols.'>Enable meta columns</label>'.$metaUpdate.'</li>
		'.$screenshotsOption.'		
		<li><label><input type="checkbox" name="pc-options-misc[redirect]" class="pc-col-checkbox"'.$redirect.'>Prevent redirection on activation</label></li>
		<li><label><input type="checkbox" name="pc-options-misc[feedback]" class="pc-col-checkbox"'.$feedback.'>Prevent feedback dialog</label></li>
		<li><label><input type="checkbox" name="pc-options-misc[sticky]" class="pc-col-checkbox"'.$sticky.'>Sticky columns header</label></li>
		</ul>		
		</div>
		<input type="hidden" id="pcadmin-categories" name="pcadmin-categories" value="'.( implode( ',', $this->categories )  ).'">
		<input type="hidden" name="category_pinned" value="'.( implode( ',', $this->get_pinned_categories() )  ).'"></div>
		'.(wp_nonce_field( "pcadmin" )).'
		<input type="submit" name="category-options-apply" id="category-options-apply" class="button button-primary" value="Apply">
		'.$optionLinks.'		
		</form></div>
		'.$miscOptions.'
		</div>';

		if ( !empty( $this->options['metacols'] ) ) {
			$html .= '<form method="post" id="pc-update-meta-form"><input type="hidden" name="pc-options-update-meta" value="true">'.(wp_nonce_field( "pcadmin" )).'</form>';
		}

		// Empty trash
		if ( $this->view === 'trash' ) {
			$html .= '<form method="post" id="pc-empty-trash-form"><input type="hidden" name="pc-empty-trash" value="true">'.(wp_nonce_field( "pcadmin" )).'</form>';
		}

		if ( isset( $this->error ) ) {
			$html .= '<div class="pc-errors">'.$this->error.'</div>';
		}

		echo $html;
	}

	/**
	 * Javascript templates.
	 */
	public function js_templates() { ?>
		<script type="text/html" id="tmpl-bulk-inline-edit-form">
		<tr id="bulk-edit-plugins" class="inline-edit-row inline-edit-row-post inline-edit-row-plugins bulk-edit-row bulk-edit-row-posts bulk-edit-row-plugins bulk-edit-post bulk-edit-plugins inline-editor"><td colspan="7" class="colspanchange">
			<fieldset class="inline-edit-col-left">
				<legend class="inline-edit-legend">Bulk Edit</legend>
				<div class="inline-edit-col">
					<div id="bulk-title-div">
						<div id="bulk-titles">
							<# _.each(data.plugins, function(value) { #>
								<div id="{{ value }}"><a id="_{{ value }}" class="ntdelbutton" title="Remove From Bulk Edit">X</a>{{ value }}</div>
							<# }); #>														
						</div>
					</div>	
				</div>
			</fieldset>
			<fieldset class="inline-edit-col-center inline-edit-add-categories">
				<div class="inline-edit-col">
					<span class="title inline-edit-categories-label">Add Categories</span>
					<ul class="cat-checklist category-checklist">
						<# _.each(data.categories, function(value) { #>
							<li id="category-delete-{{ value }}" class="popular-category"><label class="selectit"><input value="{{ value }}" type="checkbox" name="plugin_category_add[]" id="in-category-add-{{ value }}">{{ value }}</label></li>
						<# }); #>
					</ul>
				</div>
			</fieldset>
			<fieldset class="inline-edit-col-center inline-edit-remove-categories">
				<div class="inline-edit-col">
					<span class="title inline-edit-categories-label">Remove Categories</span>
					<ul class="cat-checklist category-checklist">
						<# _.each(data.categories, function(value) { #>
							<li id="category-remove-{{ value }}" class="popular-category"><label class="selectit"><input value="{{ value }}" type="checkbox" name="plugin_category_remove[]" id="in-category-remove-{{ value }}">{{ value }}</label></li>
						<# }); #>
					</ul>	
				</div>
			</fieldset>			
			<div class="submit inline-edit-save">
				<button type="button" class="button cancel alignleft">Cancel</button>
				<input type="submit" name="bulk_edit" id="bulk_edit" class="button button-primary alignright" value="Update">
				<input type="hidden" name="post_view" value="list">
				<input type="hidden" name="screen" value="edit-post">
				<br class="clear">
				<div class="notice notice-error notice-alt inline hidden">
					<p class="error"></p>
				</div>
			</div>
		</td></tr>
		</script>
		<script type="text/html" id="tmpl-trash-view-link">
		<li class="trash"> | <a href="?plugin_status=trash">Trash <span class="count">(<span id="trash-count">1</span>)</span></a></li>
		</script>
		<script type="text/html" id="tmpl-category-option-item">
		<li id="category-admin-{{ data.category }}" class="pc-catadm-item"><span class="pc-delcat dashicons dashicons-no" title="Delete Category"></span> {{ data.category }} 
			<span class="pc-cat-toggle pc-cat-pin dashicons dashicons-admin-post{{ data.pinned }}" title="Pin to the plugins menu"></span>
			<span class="pc-cat-toggle pc-cat-hide dashicons dashicons-hidden{{ data.hidden }}" title="Hide from the plugin list (will show in category filter lists)"></span>
			<span class="pc-cat-toggle pc-cat-warning dashicons dashicons-warning{{ data.warning }}" title="Show the warning message below on plugin deactivation"></span>
			<span class="pc-cat-toggle pc-cat-noupdate dashicons dashicons-lock{{ data.noupdate }}" title="Prevent plugin update"></span>
		</li>
		</script>
		<?php
		$column = 'name';
		$sortable = isset( $_GET['orderby'] ) && $_GET['orderby'] === $column ? 'sorted' : 'sortable';
		if ( isset( $_GET['orderby'] ) && $sortable !== 'sorted' ) {
			$order = 'desc';
		}
		else {
			$order = isset( $_GET['order'] ) && $_GET['order'] === 'desc' ? 'desc' : 'asc';				
		}
		// $order = $sortable === 'sorted' && $_GET['order'] === 'asc' ? 'asc' : 'desc';
		$linkOrder = $order === 'asc' ? 'desc': 'asc';
		$args['headerClasses'] = "pc-{$column} $sortable $order";
		$args['urlParameters'] = $this->url_parameters;
		$args['urlParameters']['orderby'] = $column;
		$args['urlParameters']['order'] = $linkOrder;
		$args['columnTitle'] = __('Plugin');		
		echo '<script type="text/html" id="tmpl-plugin-column-header">'. $this->column_header_template( $args ) . '</script>';
		?>		
	<?php
	}

	/**
	 * Screen options. Also set intial hidden columns.
	 */
	public function add_screen_options( $settings, $args ) {		
		$hidden_columns = get_user_option( 'manage' . $args->id . 'columnshidden' );		
		if ( ! isset( $hidden_columns ) || $hidden_columns === false ) {
			$hiddenCols = array_keys( $this->columns );
			$hiddenCols = array_diff( $hiddenCols, ['categories','activated','installed'] );			
			update_user_option( $this->user->ID, 'manage' . $args->id . 'columnshidden', $hiddenCols, true );
			if ( is_multisite() || $args->id === 'plugins-network' ) {
				update_user_option( $this->user->ID, 'managepluginscolumnshidden', $hiddenCols, true );
			}
		}
		
		return $settings.'<div class="sopc-options"><input type="button" name="pc-options" id="sopc-options-button" class="button button-secondary" value="Plugin Columns Options"></div>';
	}	

	/**
	 * Add deactivate warning class.
	 */
	public function add_deactivate_warning_class( $actions, $plugin_file, $plugin_data, $context ) {
		if ( isset( $actions['deactivate'] ) ) {
			if ( ! empty( $this->warning_categories )
			&& ! empty( $this->warning_categories[$this->get_site_id()] )
			&& isset( $this->plugins[$plugin_file]['categories'] ) 
			) {
				$match = false;
				foreach ( $this->warning_categories[$this->get_site_id()] as $cat ) {
					if ( !empty( $this->plugins[$plugin_file]['categories'] ) && in_array( $cat, $this->plugins[$plugin_file]['categories'] ) ) {
						$match = true;
						break;
					}
				}
				if ( $match ) {
					if ( isset( $this->deactivate_has_class ) || strpos( $actions['deactivate'], 'class' ) !== false ) {
						$this->deactivate_has_class = true;
						$actions['deactivate'] = str_replace('class="', 'class="pc-deactivate-warning ', $actions['deactivate'] );
					}
					else {					
						$actions['deactivate'] = str_replace('<a', '<a class="pc-deactivate-warning" ', $actions['deactivate'] );
					}
				}				
			}			
		}
		return $actions;
	}

	/**
	 * Add screenshot links to the description.
	 */
	public function description_meta( $plugin_meta, $plugin_file ) {
		if ( isset( $this->plugins[$plugin_file]['screenshots'] ) ) {
			$images = array();
			$i = 1;			
			foreach( $this->plugins[$plugin_file]['screenshots'] as $screenshot ) {
				$caption = !empty( $screenshot['caption'] ) ? ' data-caption="'.$screenshot['caption'].'"' : '';				
				$images[] = '<a href="'.$screenshot['src'].'" data-fancybox="'.$plugin_file.'"'.$caption.'>'.$i.'</a>';
				$i++;
			}

			if ( !empty( $images ) ) {
				$html = implode(',', $images);			
				$plugin_meta[] = "<span>Screenshots: $html</span>";;
			}			
		}		
		return $plugin_meta;
	}

	/* Private Functions */

	/**
	 * Column header template
	 */
	private function column_header_template( $args ) {
		$style = isset( $args['style'] ) ? $args['style'] : '';
		return '<div class="pc-header '.$args['headerClasses'].'">
		<a href="'.$this->plugins_page_url.'?'.(build_query($args['urlParameters'])).'" class="pc-column-header-link"><span class="pc-col-title">'.$args['columnTitle'].'</span>
		<span class="sorting-indicator"></span></a></div>'.$style;
	}

	/**
	 * Wrapper for get_plugins.
	 */
	private function get_plugins(){
		if ( empty( $this->allPlugins ) ) {		
			$this->allPlugins = get_plugins();
		}
		return $this->allPlugins;
	}

	/**
	 * Merges arrays returns distinct and sorted values
	 */
	private function array_concat(){		
		$arrays = func_get_args();
		$dv = array();
		foreach( $arrays as $arr ){
			if ( is_array( $arr ) ) {
				foreach( $arr as $v ){
					$dv[$v] = true;
				}
			}
		}
		$values = array_keys( $dv );
		sort( $values );
		return $values;
	}
	

	/**
	 * Get pinned categories.
	 */
	private function get_pinned_categories() {
		$pinned_categories = array();
		if ( ! isset( $this->pinned_categories ) ) {			 
			$this->pinned_categories = $this->get_option( 'plugin-columns-pinned-categories', array() );			
		}
		if ( isset( $this->pinned_categories[$this->get_site_id()] ) ) {
			$pinned_categories = $this->pinned_categories[$this->get_site_id()];
		}		
		return $pinned_categories;
	}

	/**
	 * Get hidden categories.
	 */
	private function get_hidden_categories() {
		$hidden_categories = array();
		if ( ! isset( $this->hidden_categories ) ) {			 
			$this->hidden_categories = $this->get_option( 'plugin-columns-hidden-categories', array() );			
		}
		if ( isset( $this->hidden_categories[$this->get_site_id()] ) ) {
			$hidden_categories = $this->hidden_categories[$this->get_site_id()];
		}		
		return $hidden_categories;
	}

	/**
	 * Get warning categories.
	 */
	private function get_warning_categories() {
		$warning_categories = array();
		if ( ! isset( $this->warning_categories ) ) {			 
			$this->warning_categories = $this->get_option( 'plugin-columns-warning-categories', array() );			
		}
		if ( isset( $this->warning_categories[$this->get_site_id()] ) ) {
			$warning_categories = $this->warning_categories[$this->get_site_id()];
		}		
		return $warning_categories;
	}

	/**
	 * Get no update categories.
	 */
	private function get_noupdate_categories() {
		$noupdate_categories = array();
		if ( ! isset( $this->noupdate_categories ) ) {			 
			$this->noupdate_categories = $this->get_option( 'plugin-columns-noupdate-categories', array() );			
		}
		if ( !empty( $this->noupdate_categories ) ) {
			$noupdate_categories = $this->noupdate_categories;
		}		
		return $noupdate_categories;
	}

	/**
	 * Get site index.
	 */
	private function get_site_id() {
		$index = 0;
		if ( is_multisite() && ! is_network_admin() ) {			
			$index = get_current_blog_id();			
		}
		return $index;
	}

	/**
	 * Check if array is changed.
	 */
	private function array_changed( $arrayA , $arrayB ) { 
		sort( $arrayA ); 
		sort( $arrayB );		
		return $arrayA != $arrayB; 
	}

	/**
	 * Update plugins data.
	 */
	private function update_plugins() {		
		if ( $this->view === 'imported' ) {
			$this->update_option( 'plugin-columns-imported-plugins', $this->imported );
		}
		else if	( $this->view === 'trash' ) {
			$this->update_option( 'plugin-columns-trash', $this->trash );			
		}
		else {
			$this->update_option( 'plugin-columns-plugins', $this->plugins );			
		}
	}

	/**
	 * Add categories.
	 */
	private function add_categories( $categories ) {
		$nCats = array();
		if ( !empty( $categories ) ) {			
			foreach ( $categories as $category ) {
				$category = trim($category);
				if ( ! in_array( $category, $this->categories ) ) {
					array_push( $this->categories, $category );
					$nCats[] = $category;
				}
			}
			if ( !empty ( $nCats ) ) {
				sort( $this->categories );
				$this->update_option( 'plugin-columns-categories', $this->categories );
			}
		}
		return empty($nCats)?'':implode(',',$nCats);
	}

	/**
	 * Update the noupdate plugins list.
	 */
	private function update_noupdate_plugins_list( $list = '', $plugin = '', $action = '' ) {
		global $plugin_columns_noupdate_plugins;
		$plugins = array();
		if ( empty( $list ) ) {
			$list = $this->plugins;
		}
		if ( !empty( $plugin ) ) {
			$plugins[$plugin] = $list[$plugin];
		}
		else {
			$plugins = $list;
		}

		if ( !empty( $this->noupdate_categories ) ) {
			$noupdate_plugins = array();
			foreach( $plugins as $key => $plugin_info ) {
				if ( !empty( $plugin_info['categories'] ) ) {
					foreach( $plugin_info['categories'] as $category ) {
						if ( in_array( $category, $this->noupdate_categories ) ) {
							$noupdate_plugins[] = $key;
							break;
						}
					}
				}
			}
			if ( $noupdate_plugins !== $this->noupdate_plugins ) {				
				if ( !empty( $plugin ) && empty( $noupdate_plugins ) && in_array( $plugin, $this->noupdate_plugins ) ) {
					$action = 'remove';
					$noupdate_plugins[] = $plugin;
				}
				
				if ( $action === 'add' ) {
					$this->noupdate_plugins = $this->array_concat($this->noupdate_plugins, $noupdate_plugins);					
				}
				else if ( $action === 'remove' ) {					
					$this->noupdate_plugins = array_diff( $this->noupdate_plugins, $noupdate_plugins );					
				}
				else {					
					$this->noupdate_plugins = $noupdate_plugins;										
				}

				$plugin_columns_noupdate_plugins = $this->noupdate_plugins;
				$this->update_option( 'plugin-columns-noupdate-plugins', $this->noupdate_plugins );
			}						
		}
	}

	/**
	 * Get plugin information.
	 */
	private function get_plugin_information( $plugin, $fields ) {
		if ( isset( $this->api_error ) ) return false;
		include_once( ABSPATH . 'wp-admin/includes/plugin-install.php' );

		$defaults = array(			
			'short_description' => false,
			'sections' => false,
			'tested' => false,
			'requires' => false,
			'rating' => false,
			'ratings' => false,
			'downloaded' => false,
			'downloadlink' => false,
			'last_updated' => false,
			'added' => false,
			'tags' => false,
			'compatibility' => false,
			'homepage' => false,
			'versions' => false,
			'donate_link' => false,			
		);

		$fields = wp_parse_args( $fields, $defaults );

		$api = plugins_api( 'plugin_information', array( 'slug' => $plugin,	'fields' => $fields ) );

		if ( is_wp_error( $api ) ) {
			$this->api_error = true;
			return false;
		}

		return $api;
	}

	/**
	 * Get remote plugin meta information.
	 */
	private function get_meta_info( $plugins = array(), $update = false, $install = false ) {
		if ( empty( $plugins ) ) {
			$plugins = array_keys( get_plugins() );
		}

		foreach ( $plugins as $plugin_file ) {
			$slug = dirname($plugin_file);			
			if ( $slug !== '.' 
			&& ( $install || ( !empty( $this->plugins[$plugin_file]['source'] ) && strpos( $this->plugins[$plugin_file]['source'], 'wordpress') !== false ) )
			&& isset( $this->plugins[$plugin_file] ) 
			&& ( !isset( $this->plugins[$plugin_file]['rating'] ) || $update ) ) {
					
				$result = $this->get_plugin_information( $slug, array( 'rating' => true, 'ratings' => true, 'downloaded' => true, 'last_updated' => true, 'added' => true, 'screenshots' => true ) );				
				if ( !empty( $result ) ) {					
					if ( isset( $result->name ) ) $this->plugins[$plugin_file]['name'] = $result->name;
					if ( isset( $result->slug ) ) $this->plugins[$plugin_file]['slug'] = $result->slug;
					if ( isset( $result->version ) ) $this->plugins[$plugin_file]['version'] = $result->version;
					if ( isset( $result->rating ) ) $this->plugins[$plugin_file]['rating'] = $result->rating;
					if ( isset( $result->num_ratings ) ) $this->plugins[$plugin_file]['num_ratings'] = $result->num_ratings;
					if ( isset( $result->support_threads ) ) $this->plugins[$plugin_file]['support_threads'] = $result->support_threads;
					if ( isset( $result->downloaded ) ) $this->plugins[$plugin_file]['downloaded'] = $result->downloaded;					
					if ( isset( $result->added ) ) $this->plugins[$plugin_file]['added'] = $result->added;
					if ( isset( $result->screenshots ) ) {
						ksort($result->screenshots);						
						foreach( $result->screenshots as $key => $screenshot ) {
							if ( !empty( $screenshot['src'] ) ) {
								$this->plugins[$plugin_file]['screenshots'][$key]['src'] = $screenshot['src'];
								if ( !empty( $screenshot['caption'] ) ) {								
									$this->plugins[$plugin_file]['screenshots'][$key]['caption'] = wptexturize( wp_strip_all_tags( $screenshot['caption'] ) );
								}
							}
						}
					}
					if ( isset( $result->last_updated ) ) {
						if (($timestamp = strtotime($result->last_updated)) === false) {
							$timestamp = $result->last_updated;
						}
						$this->plugins[$plugin_file]['last_updated'] = $timestamp;
					}					
				}
			}			
		}
		$this->update_option( 'plugin-columns-plugins', $this->plugins );
		return true;
	}

	/**
	 * Get plugin list for getting meta info.
	 */
	private function get_meta_info_list( $update = false ) {
		$pluginList = array();		
		$plugins = array_keys( get_plugins() );		
		foreach ( $plugins as $plugin_file ) {
			$slug = dirname($plugin_file);			
			if ( $slug !== '.' && !empty( $this->plugins[$plugin_file]['source'] ) 
			&& strpos( $this->plugins[$plugin_file]['source'], 'wordpress') !== false 
			&& isset( $this->plugins[$plugin_file] ) 
			&& ( !isset( $this->plugins[$plugin_file]['rating'] ) || $update ) ) {
				$pluginList[] = $plugin_file;
			}
		}
		return !empty($pluginList)?$pluginList:'';
	}

	/**
	 * Get date format.
	 */
	private function get_date_format() {
		if ( ! empty( $this->options['dateformat'] ) ) {
			$dateformat = $this->options['dateformat'];
		}
		else {
			$dateformat = $this->dateformat;
		}
		return $dateformat;
	}

	/**
	 * Get category and orderby parameters.
	 */
	private function get_plugin_url_parameters( $location ) {		
		$url = '';		

		if ( isset( $_GET['category_name'] ) ) {
			$category_name = urldecode( esc_attr( $_GET['category_name'] ) );				
			if ( in_array( $category_name, $this->categories ) ) {
				$category_name = str_replace(' ', '+', $category_name);
				if ( strpos( $location, 'plugin_status' ) !== false ) {
					$location = preg_replace('/plugin_status=(\w+)/', 'category_name='.$category_name, $location);
				}
				else {
					$url = '&category_name='.$category_name;
				}								
			}
		}
		if ( isset( $_GET['orderby'] ) ) {					
			$orderby = esc_attr( $_GET['orderby'] );
			$order = esc_attr( $_GET['order'] );					
			if ( isset( $this->columns[$orderby] ) ) {				
				$url .= '&orderby='.$orderby.'&order='.$order;
			}
		}

		if ( !empty( $url ) ) {
			if ( strpos( $location, '?' ) === false ) {
				$location .= '?';
				$location .= ltrim( $url, '&' );
			}
			else {
				$location .= $url;
			}			
		}

		return $location;
	}

	/**
	 * get_option wrapper.
	 */
	private function get_option( $option, $default = false ) {
		if ( is_multisite() ) {
			$val = get_site_option( $option, $default );	
		}
		else {
			$val = get_option( $option, $default );
		}
		return $val;
	}

	/**
	 * update_option wrapper.
	 */
	private function update_option( $option, $value ) {		
		if ( is_multisite() ) {
			update_site_option( $option, $value );	
		}
		else {
			update_option( $option, $value );
		}
	}

	/**
	 * delete_option wrapper.
	 */
	private function delete_option( $option ) {		
		if ( is_multisite() ) {
			delete_site_option( $option );						
		}
		else {
			delete_option( $option );
		}
		if ( $option === 'plugin-columns-plugins' ) {
			$this->plugins = array();
		}
		else if ( $option === 'plugin-columns-categories' ) {
			$this->categories = array();
		}
		else if ( $option === 'plugin-columns-pinned-categories' ) {
			$this->pinned_categories = array();
		}
		else if ( $option === 'plugin-columns-hidden-categories' ) {
			$this->hidden_categories = array();
		}
		else if ( $option === 'plugin-columns-warning-categories' ) {
			$this->warning_categories = array();
		}
		else if ( $option === 'plugin-columns-noupdate-categories' ) {
			$this->noupdate_categories = array();
		}
		else if ( $option === 'plugin-columns-noupdate-plugins' ) {
			$this->noupdate_plugins = array();
		}		
		else if ( $option === 'plugin-columns-imported-plugins' ) {
			$this->imported = array();
		}
		else if ( $option === 'plugin-columns-options' ) {
			$this->options = array();
		}
		else if ( $option === 'plugin-columns-trash' ) {
			$this->trash = array();
		}
	}

	/**
	 * Delete plugin options.
	 */
	private function delete_plugin_options() {
		$this->delete_option( 'plugin-columns-plugins' );
		$this->delete_option( 'plugin-columns-categories' );		
		$this->delete_option( 'plugin-columns-imported-plugins' );
		$this->delete_option( 'plugin-columns-options' );
		$this->delete_option( 'plugin-columns-trash' );
		$this->delete_option( 'plugin-columns-pinned-categories' );
		$this->delete_option( 'plugin-columns-hidden-categories' );
		$this->delete_option( 'plugin-columns-warning-categories' );
		$this->delete_option( 'plugin-columns-noupdate-categories' );
		$this->delete_option( 'plugin-columns-noupdate-plugins' );
	}

}

endif; // class_exists check

PluginColumns::instance();
