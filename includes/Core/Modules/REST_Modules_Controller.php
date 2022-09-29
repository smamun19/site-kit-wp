<?php
/**
 * Class Google\Site_Kit\Core\Modules\REST_Modules_Controller
 *
 * @package   Google\Site_Kit\Core\Modules
 * @copyright 2022 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Core\Modules;

use Google\Site_Kit\Core\Modules\REST_Modules_Controller;
use Google\Site_Kit\Core\Permissions\Permissions;
use Google\Site_Kit\Core\REST_API\REST_Routes;
use Google\Site_Kit\Core\REST_API\REST_Route;
use Google\Site_Kit\Core\REST_API\Exception\Invalid_Datapoint_Exception;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Exception;

/**
 * Class for handling modules rest routes.
 *
 * @since n.e.x.t
 * @access private
 * @ignore
 */
class REST_Modules_Controller {

	const REST_ENDPOINT_CHECK_ACCESS = 'core/modules/data/check-access';

	/**
	 * Modules instance.
	 *
	 * @since n.e.x.t
	 * @var Modules
	 */
	protected $modules;

	/**
	 * Constructor.
	 *
	 * @since n.e.x.t
	 *
	 * @param Modules $modules Modules instance.
	 */
	public function __construct( Modules $modules ) {
		$this->modules = $modules;
	}

	/**
	 * Registers functionality through WordPress hooks.
	 *
	 * @since n.e.x.t
	 */
	public function register() {
		add_filter(
			'googlesitekit_rest_routes',
			function( $routes ) {
				return array_merge( $routes, $this->get_rest_routes() );
			}
		);

		$active_modules = $this->modules->get_active_modules();
		add_filter(
			'googlesitekit_apifetch_preload_paths',
			function ( $paths ) use ( $active_modules ) {
				$modules_routes = array(
					'/' . REST_Routes::REST_ROOT . '/core/modules/data/list',
				);

				$settings_routes = array_map(
					function ( Module $module ) {
						if ( $module instanceof Module_With_Settings ) {
							return '/' . REST_Routes::REST_ROOT . "/modules/{$module->slug}/data/settings";
						}
						return null;
					},
					array_values( $active_modules )
				);

				return array_merge( $paths, $modules_routes, array_filter( $settings_routes ) );
			}
		);
	}

	/**
	 * Gets the REST schema for a module.
	 *
	 * @since n.e.x.t
	 *
	 * @return array Module REST schema.
	 */
	private function get_module_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'module',
			'type'       => 'object',
			'properties' => array(
				'slug'         => array(
					'type'        => 'string',
					'description' => __( 'Identifier for the module.', 'google-site-kit' ),
					'readonly'    => true,
				),
				'name'         => array(
					'type'        => 'string',
					'description' => __( 'Name of the module.', 'google-site-kit' ),
					'readonly'    => true,
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'Description of the module.', 'google-site-kit' ),
					'readonly'    => true,
				),
				'homepage'     => array(
					'type'        => 'string',
					'description' => __( 'The module homepage.', 'google-site-kit' ),
					'format'      => 'uri',
					'readonly'    => true,
				),
				'internal'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the module is internal, thus without any UI.', 'google-site-kit' ),
					'readonly'    => true,
				),
				'active'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the module is active.', 'google-site-kit' ),
				),
				'connected'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the module setup has been completed.', 'google-site-kit' ),
					'readonly'    => true,
				),
				'dependencies' => array(
					'type'        => 'array',
					'description' => __( 'List of slugs of other modules that the module depends on.', 'google-site-kit' ),
					'items'       => array(
						'type' => 'string',
					),
					'readonly'    => true,
				),
				'dependants'   => array(
					'type'        => 'array',
					'description' => __( 'List of slugs of other modules depending on the module.', 'google-site-kit' ),
					'items'       => array(
						'type' => 'string',
					),
					'readonly'    => true,
				),
				'owner'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => __( 'Owner ID.', 'google-site-kit' ),
							'readonly'    => true,
						),
						'login' => array(
							'type'        => 'string',
							'description' => __( 'Owner login.', 'google-site-kit' ),
							'readonly'    => true,
						),
					),
				),
			),
		);
	}

	/**
	 * Gets related REST routes.
	 *
	 * @since n.e.x.t
	 *
	 * @return array List of REST_Route objects.
	 */
	private function get_rest_routes() {
		$can_setup = function() {
			return current_user_can( Permissions::SETUP );
		};

		$can_authenticate = function() {
			return current_user_can( Permissions::AUTHENTICATE );
		};

		$can_list_data = function() {
			return current_user_can( Permissions::VIEW_SPLASH ) || current_user_can( Permissions::VIEW_DASHBOARD );
		};

		$can_view_insights = function() {
			// This accounts for routes that need to be called before user has completed setup flow.
			if ( current_user_can( Permissions::SETUP ) ) {
				return true;
			}

			return current_user_can( Permissions::VIEW_POSTS_INSIGHTS );
		};

		$can_manage_options = function() {
			// This accounts for routes that need to be called before user has completed setup flow.
			if ( current_user_can( Permissions::SETUP ) ) {
				return true;
			}

			return current_user_can( Permissions::MANAGE_OPTIONS );
		};

		$get_module_schema = function () {
			return $this->get_module_schema();
		};

		return array(
			new REST_Route(
				'core/modules/data/list',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$modules = array_map(
								array( $this, 'prepare_module_data_for_response' ),
								$this->modules->get_available_modules()
							);
							return new WP_REST_Response( array_values( $modules ) );
						},
						'permission_callback' => $can_list_data,
					),
				),
				array(
					'schema' => $get_module_schema,
				)
			),
			new REST_Route(
				'core/modules/data/activation',
				array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$data = $request['data'];
							$slug = isset( $data['slug'] ) ? $data['slug'] : '';

							try {
								$this->modules->get_module( $slug );
							} catch ( Exception $e ) {
								return new WP_Error( 'invalid_module_slug', $e->getMessage() );
							}

							$modules = $this->modules->get_available_modules();

							if ( ! empty( $data['active'] ) ) {
								// Prevent activation if one of the dependencies is not active.
								$dependency_slugs = $this->modules->get_module_dependencies( $slug );
								foreach ( $dependency_slugs as $dependency_slug ) {
									if ( ! $this->modules->is_module_active( $dependency_slug ) ) {
										/* translators: 1: module name */
										return new WP_Error( 'inactive_dependencies', sprintf( __( 'Module cannot be activated because of inactive dependency %s.', 'google-site-kit' ), $modules[ $dependency_slug ]->name ), array( 'status' => 500 ) );
									}
								}
								if ( ! $this->activate_module( $slug ) ) {
									return new WP_Error( 'cannot_activate_module', __( 'An internal error occurred while trying to activate the module.', 'google-site-kit' ), array( 'status' => 500 ) );
								}
							} else {
								// Automatically deactivate dependants.
								$dependant_slugs = $this->modules->get_module_dependants( $slug );
								foreach ( $dependant_slugs as $dependant_slug ) {
									if ( $this->modules->is_module_active( $dependant_slug ) ) {
										if ( ! $this->deactivate_module( $dependant_slug ) ) {
											/* translators: 1: module name */
											return new WP_Error( 'cannot_deactivate_dependant', sprintf( __( 'Module cannot be deactivated because deactivation of dependant %s failed.', 'google-site-kit' ), $modules[ $dependant_slug ]->name ), array( 'status' => 500 ) );
										}
									}
								}
								if ( ! $this->deactivate_module( $slug ) ) {
									return new WP_Error( 'cannot_deactivate_module', __( 'An internal error occurred while trying to deactivate the module.', 'google-site-kit' ), array( 'status' => 500 ) );
								}
							}

							return new WP_REST_Response( array( 'success' => true ) );
						},
						'permission_callback' => $can_manage_options,
						'args'                => array(
							'data' => array(
								'type'     => 'object',
								'required' => true,
							),
						),
					),
				),
				array(
					'schema' => $get_module_schema,
				)
			),
			new REST_Route(
				'core/modules/data/info',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							try {
								$module = $this->modules->get_module( $request['slug'] );
							} catch ( Exception $e ) {
								return new WP_Error( 'invalid_module_slug', $e->getMessage() );
							}

							return new WP_REST_Response( $this->prepare_module_data_for_response( $module ) );
						},
						'permission_callback' => $can_authenticate,
						'args'                => array(
							'slug' => array(
								'type'              => 'string',
								'description'       => __( 'Identifier for the module.', 'google-site-kit' ),
								'sanitize_callback' => 'sanitize_key',
							),
						),
					),
				),
				array(
					'schema' => $get_module_schema,
				)
			),
			new REST_Route(
				self::REST_ENDPOINT_CHECK_ACCESS,
				array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$data = $request['data'];
							$slug = isset( $data['slug'] ) ? $data['slug'] : '';

							try {
								$module = $this->modules->get_module( $slug );
							} catch ( Exception $e ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}

							if ( ! $this->modules->is_module_connected( $slug ) ) {
								return new WP_Error( 'module_not_connected', __( 'Module is not connected.', 'google-site-kit' ), array( 'status' => 400 ) );
							}

							if ( ! $module instanceof Module_With_Service_Entity ) {
								if ( $module->is_shareable() ) {
									return new WP_REST_Response(
										array(
											'access' => true,
										)
									);
								}

								return new WP_Error( 'invalid_module', __( 'Module access cannot be checked.', 'google-site-kit' ), array( 'status' => 400 ) );
							}

							$access = $module->check_service_entity_access();

							if ( is_wp_error( $access ) ) {
								return $access;
							}

							return new WP_REST_Response(
								array(
									'access' => $access,
								)
							);
						},
						'permission_callback' => $can_setup,
						'args'                => array(
							'slug' => array(
								'type'              => 'string',
								'description'       => __( 'Identifier for the module.', 'google-site-kit' ),
								'sanitize_callback' => 'sanitize_key',
							),
						),
					),
				)
			),
			new REST_Route(
				'modules/(?P<slug>[a-z0-9\-]+)/data/notifications',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$slug = $request['slug'];
							$modules = $this->modules->get_available_modules();
							if ( ! isset( $modules[ $slug ] ) ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							$notifications = array();
							if ( $this->modules->is_module_active( $slug ) ) {
								$notifications = $modules[ $slug ]->get_data( 'notifications' );
								if ( is_wp_error( $notifications ) ) {
									// Don't consider it an error if the module does not have a 'notifications' datapoint.
									if ( Invalid_Datapoint_Exception::WP_ERROR_CODE === $notifications->get_error_code() ) {
										$notifications = array();
									}
									return $notifications;
								}
							}
							return new WP_REST_Response( $notifications );
						},
						'permission_callback' => $can_authenticate,
					),
				),
				array(
					'args' => array(
						'slug' => array(
							'type'              => 'string',
							'description'       => __( 'Identifier for the module.', 'google-site-kit' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			),
			new REST_Route(
				'modules/(?P<slug>[a-z0-9\-]+)/data/settings',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$slug = $request['slug'];
							try {
								$module = $this->modules->get_module( $slug );
							} catch ( Exception $e ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}

							if ( ! $module instanceof Module_With_Settings ) {
								return new WP_Error( 'invalid_module_slug', __( 'Module does not support settings.', 'google-site-kit' ), array( 'status' => 400 ) );
							}
							return new WP_REST_Response( $module->get_settings()->get() );
						},
						'permission_callback' => $can_manage_options,
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$slug = $request['slug'];
							try {
								$module = $this->modules->get_module( $slug );
							} catch ( Exception $e ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}

							if ( ! $module instanceof Module_With_Settings ) {
								return new WP_Error( 'invalid_module_slug', __( 'Module does not support settings.', 'google-site-kit' ), array( 'status' => 400 ) );
							}

							do_action( 'googlesitekit_pre_save_settings_' . $slug );

							$module->get_settings()->merge( (array) $request['data'] );

							do_action( 'googlesitekit_save_settings_' . $slug );

							return new WP_REST_Response( $module->get_settings()->get() );
						},
						'permission_callback' => $can_manage_options,
						'args'                => array(
							'data' => array(
								'type'              => 'object',
								'description'       => __( 'Settings to set.', 'google-site-kit' ),
								'validate_callback' => function( $value ) {
									return is_array( $value );
								},
							),
						),
					),
				),
				array(
					'args' => array(
						'slug' => array(
							'type'              => 'string',
							'description'       => __( 'Identifier for the module.', 'google-site-kit' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			),
			new REST_Route(
				'modules/(?P<slug>[a-z0-9\-]+)/data/(?P<datapoint>[a-z\-]+)',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$slug = $request['slug'];
							try {
								$module = $this->modules->get_module( $slug );
							} catch ( Exception $e ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							$data = $module->get_data( $request['datapoint'], $request->get_params() );
							if ( is_wp_error( $data ) ) {
								return $data;
							}
							return new WP_REST_Response( $data );
						},
						'permission_callback' => $can_view_insights,
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$slug = $request['slug'];
							try {
								$module = $this->modules->get_module( $slug );
							} catch ( Exception $e ) {
								return new WP_Error( 'invalid_module_slug', __( 'Invalid module slug.', 'google-site-kit' ), array( 'status' => 404 ) );
							}
							$data = isset( $request['data'] ) ? (array) $request['data'] : array();
							$data = $module->set_data( $request['datapoint'], $data );
							if ( is_wp_error( $data ) ) {
								return $data;
							}
							return new WP_REST_Response( $data );
						},
						'permission_callback' => $can_manage_options,
						'args'                => array(
							'data' => array(
								'type'              => 'object',
								'description'       => __( 'Data to set.', 'google-site-kit' ),
								'validate_callback' => function( $value ) {
									return is_array( $value );
								},
							),
						),
					),
				),
				array(
					'args' => array(
						'slug'      => array(
							'type'              => 'string',
							'description'       => __( 'Identifier for the module.', 'google-site-kit' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'datapoint' => array(
							'type'              => 'string',
							'description'       => __( 'Module data point to address.', 'google-site-kit' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			),
			new REST_Route(
				'core/modules/data/recover-module',
				array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => function( WP_REST_Request $request ) {
							$data = $request['data'];
							$slug = isset( $data['slug'] ) ? $data['slug'] : '';
							try {
								$module = $this->modules->get_module( $slug );
							} catch ( Exception $e ) {
								return new WP_Error( 'invalid_module_slug', $e->getMessage(), array( 'status' => 404 ) );
							}

							if ( ! $module->is_shareable() ) {
								return new WP_Error( 'module_not_shareable', __( 'Module is not shareable.', 'google-site-kit' ), array( 'status' => 404 ) );
							}

							if ( ! $this->modules->is_module_recoverable( $module ) ) {
								return new WP_Error( 'module_not_recoverable', __( 'Module is not recoverable.', 'google-site-kit' ), array( 'status' => 403 ) );
							}

							$check_access_endpoint = '/' . REST_Routes::REST_ROOT . '/' . self::REST_ENDPOINT_CHECK_ACCESS;
							$check_access_request = new WP_REST_Request( 'POST', $check_access_endpoint );
							$check_access_request->set_body_params(
								array(
									'data' => array(
										'slug' => $slug,
									),
								)
							);
							$check_access_response = rest_do_request( $check_access_request );

							if ( is_wp_error( $check_access_response ) ) {
								return $check_access_response;
							}
							$access = isset( $check_access_response->data['access'] ) ? $check_access_response->data['access'] : false;
							if ( ! $access ) {
								return new WP_Error( 'module_not_accessible', __( 'Module is not accessible by current user.', 'google-site-kit' ), array( 'status' => 403 ) );
							}

							// Update the module's ownerID to the ID of the user making the request.
							$module_setting_updates = array(
								'ownerID' => get_current_user_id(),
							);
							$module->get_settings()->merge( $module_setting_updates );

							return new WP_REST_Response( $module_setting_updates );
						},
						'permission_callback' => $can_setup,
					),
				),
				array(
					'schema' => $get_module_schema,
				)
			),
		);
	}

	/**
	 * Prepares module data for a REST response according to the schema.
	 *
	 * @since 1.3.0
	 *
	 * @param Module $module Module instance.
	 * @return array Module REST response data.
	 */
	private function prepare_module_data_for_response( Module $module ) {
		$module_data = array(
			'slug'         => $module->slug,
			'name'         => $module->name,
			'description'  => $module->description,
			'homepage'     => $module->homepage,
			'internal'     => $module->internal,
			'order'        => $module->order,
			'forceActive'  => $module->force_active,
			'shareable'    => $module->is_shareable(),
			'recoverable'  => $module->is_recoverable(),
			'active'       => $this->modules->is_module_active( $module->slug ),
			'connected'    => $this->modules->is_module_connected( $module->slug ),
			'dependencies' => $this->modules->get_module_dependencies( $module->slug ),
			'dependants'   => $this->modules->get_module_dependants( $module->slug ),
			'owner'        => null,
		);

		if ( current_user_can( 'list_users' ) && $module instanceof Module_With_Owner ) {
			$owner_id = $module->get_owner_id();
			if ( $owner_id ) {
				$module_data['owner'] = array(
					'id'    => $owner_id,
					'login' => get_the_author_meta( 'user_login', $owner_id ),
				);
			}
		}

		return $module_data;
	}
}
