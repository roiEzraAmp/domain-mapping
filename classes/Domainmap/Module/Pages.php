<?php

// +----------------------------------------------------------------------+
// | Copyright Incsub (http://incsub.com/)                                |
// | Based on an original by Donncha (http://ocaoimh.ie/)                 |
// +----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License, version 2, as  |
// | published by the Free Software Foundation.                           |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to the Free Software          |
// | Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,               |
// | MA 02110-1301 USA                                                    |
// +----------------------------------------------------------------------+

/**
 * The module responsible for admin pages.
 *
 * @category Domainmap
 * @package Module
 *
 * @since 4.0.0
 */
class Domainmap_Module_Pages extends Domainmap_Module {

	const NAME = __CLASS__;

	/**
	 * Admin page handle.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 * @var string
	 */
	private $_admin_page;

	/**
	 * Constructor.
	 *
	 * @since 4.0.0
	 *
	 * @access public
	 * @param Domainmap_Plugin $plugin The instance of Domainmap_Plugin class.
	 */
	public function __construct( Domainmap_Plugin $plugin ) {
		parent::__construct( $plugin );

		$this->_add_action( 'admin_menu', 'add_site_options_page' );
		$this->_add_action( 'network_admin_menu', 'add_network_options_page' );
		$this->_add_action( 'admin_enqueue_scripts', 'enqueue_scripts' );
	}

	/**
	 * Registers site options page in admin menu.
	 *
	 * @since 4.0.0
	 * @action admin_menu
	 *
	 * @access public
	 */
	public function add_site_options_page() {
		if ( $this->_plugin->is_site_permitted() ) {
			$title = __( 'Domain Mapping', 'domainmap' );
			$this->_admin_page = add_management_page( $title, $title, 'manage_options', 'domainmapping', array( $this, 'render_site_options_page' ) );
		}
	}

	/**
	 * Renders network options page.
	 *
	 * @since 4.0.0
	 * @callback add_management_page()
	 *
	 * @access public
	 */
	public function render_site_options_page() {
		$reseller = $this->_plugin->get_reseller();

		$tabs = array( 'mapping' => __( 'Map domain', 'domainmap' ) );
		if ( $reseller && $reseller->is_valid() ) {
			$tabs['purchase'] = __( 'Purchase domain', 'domainmap' );
		}

		$activetab = strtolower( trim( filter_input( INPUT_GET, 'tab', FILTER_DEFAULT ) ) );
		if ( !in_array( $activetab, array_keys( $tabs ) ) ) {
			$activetab = key( $tabs );
		}

		$page = null;
		$options = $this->_plugin->get_options();
		if ( $activetab == 'purchase' ) {
			$page = new Domainmap_Render_Site_Purchase( $tabs, $activetab, $options );
			$page->reseller = $reseller;
		} else {
			// fetch unchanged domain name from database, because get_option function could return mapped domain name
			$basedomain = parse_url( $this->_wpdb->get_var( "SELECT option_value FROM {$this->_wpdb->options} WHERE option_name = 'siteurl'" ), PHP_URL_HOST );

			// if server ip addresses are provided, use it to populate DNS records
			if ( !empty( $options['map_ipaddress'] ) ) {
				foreach ( explode( ',', trim( $options['map_ipaddress'] ) ) as $ip ) {
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						$ips[] = $ip;
					}
				}
			}

			// looks like server ip addresses are not set, then try to read it automatically
			if ( empty( $ips ) && function_exists( 'dns_get_record' ) ) {
				$ips = wp_list_pluck( dns_get_record( $basedomain, DNS_A ), 'ip' );
			}

			// if we have an ip address to populate DNS record, then try to detect if we use shared or dedicated hosting
			$dedicated = false;
			if ( !empty( $ips ) ) {
				$current_ip = current( $ips );
				$transient = "domainmap-hosting-type-{$current_ip}";
				$dedicated = get_site_transient( $transient );
				if ( $dedicated === false ) {
					$check = sha1( time() );
					$ajax_url = admin_url( 'admin-ajax.php' );
					$ajax_url = str_replace( parse_url( $ajax_url, PHP_URL_HOST ), $current_ip, $ajax_url );

					$response = wp_remote_request( add_query_arg( array(
						'action' => 'domainmapping_heartbeat_check',
						'check'  => $check,
					), $ajax_url ) );

					$dedicated = !is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) == 200 && wp_remote_retrieve_body( $response ) == $check ? 1 : 0;
					set_site_transient( $transient, $dedicated, WEEK_IN_SECONDS );
				}
			}

			$page = new Domainmap_Render_Site_Map( $tabs, $activetab, $options );
			$page->origin = $this->_wpdb->get_row( "SELECT * FROM {$this->_wpdb->blogs} WHERE blog_id = " . intval( $this->_wpdb->blogid ) );
			$page->domains = (array)$this->_wpdb->get_col( "SELECT domain FROM " . DOMAINMAP_TABLE_MAP . " WHERE blog_id = " . intval( $this->_wpdb->blogid ) .  " ORDER BY id ASC" );
			$page->ips = $ips;
			$page->dedicated = $dedicated;
		}

		if ( $page ) {
			$page->render();
		}
	}

	/**
	 * Registers network options page in admin menu.
	 *
	 * @since 4.0.0
	 * @action network_admin_menu
	 *
	 * @access public
	 */
	public function add_network_options_page() {
		$title = __( 'Domain Mapping', 'domainmap' );
		$this->_admin_page = add_submenu_page( 'settings.php', $title, $title, 'manage_network_options', 'domainmapping_options', array( $this, 'render_network_options_page' ) );
	}

	/**
	 * Updates network options.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 * @param string $nonce_action The nonce action param.
	 */
	private function _update_network_options( $nonce_action ) {
		// if request method is post, then save options
		if ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
			// check referer
			check_admin_referer( $nonce_action );

			// Update the domain mapping settings
			$options = $this->_plugin->get_options();

			// parse IP addresses
			$ips = array();
			foreach ( explode( ',', filter_input( INPUT_POST, 'map_ipaddress' ) ) as $ip ) {
				$ip = filter_var( trim( $ip ), FILTER_VALIDATE_IP );
				if ( $ip ) {
					$ips[] = $ip;
				}
			}

			// parse supported levels
			$supporters = array();
			if ( isset( $_POST['map_supporteronly'] ) ) {
				$supporters = array_filter( array_map( 'intval', (array)$_POST['map_supporteronly'] ) );
			}

			$options['map_ipaddress'] = implode( ', ', array_unique( $ips ) );
			$options['map_supporteronly'] = $supporters;
			$options['map_admindomain'] = filter_input( INPUT_POST, 'map_admindomain' );
			$options['map_logindomain'] = filter_input( INPUT_POST, 'map_logindomain' );

			// update options
			update_site_option( 'domain_mapping', $options );

			// if noheader argument is passed, then redirect back to options page
			if ( filter_input( INPUT_GET, 'noheader', FILTER_VALIDATE_BOOLEAN ) ) {
				wp_safe_redirect( add_query_arg( array( 'noheader' => false, 'saved' => 'true' ) ) );
				exit;
			}
		}
	}

	/**
	 * Updates reseller options.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 * @param string $nonce_action The nonce action param.
	 */
	private function _update_reseller_options( $nonce_action ) {
		// if request method is post, then save options
		if ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
			// check referer
			check_admin_referer( $nonce_action );

			// Update the domain mapping settings
			$options = $this->_plugin->get_options();

			// save reseller options
			$options['map_reseller'] = '';
			$resellers = $this->_plugin->get_resellers();
			$reseller = filter_input( INPUT_POST, 'map_reseller' );
			if ( isset( $resellers[$reseller] ) ) {
				$options['map_reseller'] = $reseller;
				$resellers[$reseller]->save_options( $options );
			}

			// save reseller API requests log level
			$options['map_reseller_log'] = filter_input( INPUT_POST, 'map_reseller_log', FILTER_VALIDATE_INT, array(
				'options' => array(
					'min_range' => Domainmap_Reseller::LOG_LEVEL_DISABLED,
					'max_range' => Domainmap_Reseller::LOG_LEVEL_ALL,
					'default'   => Domainmap_Reseller::LOG_LEVEL_DISABLED,
				),
			) );

			// update options
			update_site_option( 'domain_mapping', $options );

			// if noheader argument is passed, then redirect back to options page
			if ( filter_input( INPUT_GET, 'noheader', FILTER_VALIDATE_BOOLEAN ) ) {
				wp_safe_redirect( add_query_arg( array( 'noheader' => false, 'saved' => 'true' ) ) );
				exit;
			}
		}
	}

	/**
	 * Handles table log actions.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 * @param string $nonce_action The nonce action param.
	 */
	private function _handle_log_actions( $nonce_action ) {
		$redirect = wp_get_referer();
		$nonce = filter_input( INPUT_GET, 'nonce' );
		if ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
			$nonce = filter_input( INPUT_POST, '_wpnonce' );
		}

		$table = new Domainmap_Table_Reseller_Log();
		switch ( $table->current_action() ) {
			case 'reseller-log-view':
				$item = filter_input( INPUT_GET, 'items', FILTER_VALIDATE_INT );
				if ( wp_verify_nonce( $nonce, $nonce_action ) && $item ) {
					$log = $this->_wpdb->get_row( 'SELECT * FROM ' . DOMAINMAP_TABLE_RESELLER_LOG . ' WHERE id = ' . $item );
					if ( !$log ) {
						status_header( 404 );
					} else {
						@header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
						@header( sprintf(
							'Content-Disposition: inline; filename="%s-%s-%d-%s-request.json"',
							parse_url( home_url(), PHP_URL_HOST ),
							$log->provider,
							$log->id,
							preg_replace( '/\D+/', '', $log->requested_at )
						) );

						echo $log->response;
					}
					exit;
				}
				break;

			case 'reseller-log-delete':
				$items = isset( $_REQUEST['items'] ) ? (array)$_REQUEST['items'] : array();
				$items = array_filter( array_map( 'intval', $items ) );

				if ( wp_verify_nonce( $nonce, $nonce_action ) && !empty( $items ) ) {
					$this->_wpdb->query( 'DELETE FROM ' . DOMAINMAP_TABLE_RESELLER_LOG . ' WHERE id IN (' . implode( ', ', $items ) . ')' );

					$redirect = add_query_arg( 'deleted', 'true', $redirect );
				}
				break;
		}


		// if noheader argument is passed, then redirect back to options page
		if ( filter_input( INPUT_GET, 'noheader', FILTER_VALIDATE_BOOLEAN ) ) {
			wp_safe_redirect( add_query_arg( 'type', isset( $_REQUEST['type'] ) ? $_REQUEST['type'] : false, $redirect ) );
			exit;
		}
	}

	/**
	 * Processes POST request sent to network options page.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 * @param string $activetab The active tab.
	 * @param string $nonce_action The nonce action param.
	 */
	private function _save_network_options_page( $activetab, $nonce_action ) {
		// update options
		switch ( $activetab ) {
			case 'general-options':
				$this->_update_network_options( $nonce_action );
				break;
			case 'reseller-options':
				$this->_update_reseller_options( $nonce_action );
				break;
			case 'reseller-api-log':
				$this->_handle_log_actions( $nonce_action );
				break;
		}

		// if noheader argument is passed, then redirect back to options page
		if ( filter_input( INPUT_GET, 'noheader', FILTER_VALIDATE_BOOLEAN ) ) {
			wp_safe_redirect( wp_get_referer() );
			exit;
		}
	}

	/**
	 * Renders network options page.
	 *
	 * @since 4.0.0
	 * @callback add_submenu_page()
	 *
	 * @access public
	 */
	public function render_network_options_page() {
		$options = $this->_plugin->get_options();

		$tabs = array(
			'general-options'  => __( 'Mapping options', 'domainmap' ),
			'reseller-options' => __( 'Reseller options', 'domainmap' ),
		);

		$reseller = $this->_plugin->get_reseller();
		if ( isset( $options['map_reseller_log'] ) && $options['map_reseller_log'] && !is_null( $reseller ) ) {
			$tabs['reseller-api-log'] = __( 'Reseller API log', 'domainmap' );
		}

		$activetab = strtolower( trim( filter_input( INPUT_GET, 'tab', FILTER_DEFAULT ) ) );
		if ( !in_array( $activetab, array_keys( $tabs ) ) ) {
			$activetab = key( $tabs );
		}

		$nonce_action = "domainmapping-{$activetab}";
		$this->_save_network_options_page( $activetab, $nonce_action );

		// render page
		$page = null;
		switch ( $activetab ) {
			default:
			case 'general-options':
				$page = new Domainmap_Render_Network_Options( $tabs, $activetab, $nonce_action, $options );
				// fetch unchanged domain name from database, because get_option function could return mapped domain name
				$page->basedomain = $this->_wpdb->get_var( "SELECT option_value FROM {$this->_wpdb->options} WHERE option_name = 'siteurl'" );
				break;
			case 'reseller-options':
				$page = new Domainmap_Render_Network_Resellers( $tabs, $activetab, $nonce_action, $options );
				$page->resellers = $this->_plugin->get_resellers();
				break;
			case 'reseller-api-log':
				$page = new Domainmap_Render_Network_Log( $tabs, $activetab, $nonce_action, $options );
				$page->table = new Domainmap_Table_Reseller_Log( array(
					'reseller'     => $reseller->get_reseller_id(),
					'nonce_action' => $nonce_action,
					'actions'      => array(
						'reseller-log-delete' => __( 'Delete', 'domainmap' ),
					),
				) );
				break;
		}

		if ( $page ) {
			$page->render();
		}
	}

	/**
	 * Enqueues appropriate scripts and styles for specific admin pages.
	 *
	 * @since 3.3
	 * @action admin_enqueue_scripts
	 * @uses plugins_url() To generate base URL of assets files.
	 * @uses wp_register_script() To register javascript files.
	 * @uses wp_enqueue_script() To enqueue javascript files.
	 * @uses wp_enqueue_style() To enqueue CSS files.
	 *
	 * @access public
	 * @global WP_Styles $wp_styles The styles queue class object.
	 * @param string $page The page handle.
	 */
	public function enqueue_scripts( $page ) {
		global $wp_styles;

		// if we are not at the site admin page, then exit
		if ( $page != $this->_admin_page ) {
			return;
		}

		$baseurl = plugins_url( '/', DOMAINMAP_BASEFILE );

		// enqueue scripts
		wp_register_script( 'jquery-payment', $baseurl . 'js/jquery.payment.js', array( 'jquery' ), '1.0.1', true );
		wp_enqueue_script( 'domainmapping-admin', $baseurl . 'js/admin.js', array( 'jquery' ), Domainmap_Plugin::VERSION, true );
		wp_localize_script( 'domainmapping-admin', 'domainmapping', array(
			'button'  => array(
				'close' => __( 'OK', 'domainmap' ),
			),
			'message' => array(
				'unmap'   => __( 'You are about to unmap selected domain. Do you really want to proceed?', 'domainmap' ),
				'empty'   => __( 'Please, enter not empty domain name.', 'domainmap' ),
				'invalid' => array(
					'card_number' => __( 'Credit card number is invalid.', 'domainmap' ),
					'card_type'   => __( 'Credit card type is invalid.', 'domainmap' ),
					'card_expiry' => __( 'Credit card expiry date is invalid.', 'domainmap' ),
					'card_cvv'    => __( 'Credit card CVV2 code is invalid.', 'domainmap' ),
				),
				'purchase' => array(
					'success' => __( 'Domain name has been purchased successfully.', 'domainmap' ),
					'failed'  => __( 'Domain name purchase has failed.', 'domainmap' ),
				),
			),
		) );

		// enqueue styles
		wp_enqueue_style( 'font-awesome', $baseurl . 'css/font-awesome.min.css', array(), '3.2.1' );
		wp_enqueue_style( 'font-awesome-ie', $baseurl . 'css/font-awesome-ie7.min.css', array( 'font-awesome' ), '3.2.1' );
		wp_enqueue_style( 'google-font-lato', 'https://fonts.googleapis.com/css?family=Lato:300,400,700,400italic', array(), Domainmap_Plugin::VERSION );
		wp_enqueue_style( 'domainmapping-admin', $baseurl . 'css/admin.css', array( 'google-font-lato', 'buttons' ), Domainmap_Plugin::VERSION );

		$wp_styles->registered['font-awesome-ie']->add_data( 'conditional', 'IE 7' );
	}

}