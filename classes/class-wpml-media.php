<?php

/**
 * Class WPML_Media
 */
class WPML_Media implements IWPML_Action {
	private static $settings;
	private static $settings_option_key = '_wpml_media';
	public $languages;
	public $parents;
	public $unattached;
	/**
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * @var SitePress
	 */
	private $sitepress;

	private $languages_to_clear = array();

	/**
	 * @var WPML_Media_Menus_Factory
	 */
	private $menus_factory;

	/**
	 * WPML_Media constructor.
	 *
	 * @param SitePress $sitepress
	 * @param wpdb $wpdb
	 * @param WPML_Media_Menus_Factory $menus_factory
	 */
	public function __construct( SitePress $sitepress, wpdb $wpdb, WPML_Media_Menus_Factory $menus_factory ) {
		$this->sitepress     = $sitepress;
		$this->wpdb          = $wpdb;
		$this->menus_factory = $menus_factory;
	}

	public function add_hooks() {
		add_action( 'wpml_loaded', array( $this, 'loaded' ), 2 );
	}

	public static function has_settings() {
		return get_option( self::$settings_option_key );
	}

	public function loaded() {
		global $sitepress;
		if ( ! isset( $sitepress ) || ! $sitepress->get_setting( 'setup_complete' ) ) {
			return null;
		}

		$this->plugin_localization();

		if ( is_admin() ) {
			WPML_Media_Upgrade::run();
		}

		self::init_settings();

		$this->overrides();

		global $sitepress_settings, $pagenow;

		$active_languages = $sitepress->get_active_languages();

		$this->languages = null;

		// do not run this when user is importing posts in Tools > Import
		if ( ! isset( $_GET['import'] ) || $_GET['import'] !== 'wordpress' ) {
			add_action( 'add_attachment', array( $this, 'save_attachment_actions' ) );
		}

		if ( $this->is_admin_or_xmlrpc() && ! $this->is_uploading_plugin_or_theme() ) {

			if ( 1 < count( $active_languages ) ) {

				add_action( 'wpml_admin_menu_configure', array( $this, 'menu' ) );
				add_filter( 'views_upload', array( $this, 'views_upload' ) );

				// Post/page save actions
				add_action( 'icl_make_duplicate', array( $this, 'make_duplicate' ), 10, 4 );
				add_action( 'edit_attachment', array( $this, 'save_attachment_actions' ) );

				//wp_delete_file file filter
				add_filter( 'wp_delete_file', array( $this, 'delete_file' ) );

				if ( $pagenow == 'media-upload.php' ) {
					add_action( 'pre_get_posts', array( $this, 'filter_media_upload_items' ), 10, 1 );
				}

				if ( $pagenow == 'media.php' ) {
					add_action( 'admin_footer', array( $this, 'media_language_options' ) );
				}

				add_action( 'wp_ajax_wpml_media_scan_prepare', array( $this, 'batch_scan_prepare' ) );

				add_action( 'wp_ajax_find_posts', array( $this, 'find_posts_filter' ), 0 );
			}

		} else {
			if ( WPML_LANGUAGE_NEGOTIATION_TYPE_DOMAIN === (int) $sitepress_settings['language_negotiation_type'] ) {
				// Translate media url when in front-end and only when using custom domain
				add_filter( 'wp_get_attachment_url', array( $this, 'wp_get_attachment_url' ), 10, 2 );
			}
		}

		add_filter( 'WPML_filter_link', array( $this, 'filter_link' ), 10, 2 );
		add_filter( 'icl_ls_languages', array( $this, 'icl_ls_languages' ), 10, 1 );

		return null;
	}

	function is_admin_or_xmlrpc() {
		$is_admin  = is_admin();
		$is_xmlrpc = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );

		return $is_admin || $is_xmlrpc;
	}

	function is_uploading_plugin_or_theme() {
		global $action;

		return ( isset( $action ) && ( $action == 'upload-plugin' || $action == 'upload-theme' ) );
	}

	function plugin_localization() {
		load_plugin_textdomain( 'wpml-media', false, WPML_MEDIA_FOLDER . '/locale' );
	}

	/**
	 *    Needed by class init and by all static methods that use self::$settings
	 */
	public static function init_settings() {
		if ( ! self::$settings ) {
			self::$settings = get_option( self::$settings_option_key );
		}

		$default_settings = array(
			'version'                  => false,
			'new_content_settings'     => array( // @deprecated, backward compatibility
				'always_translate_media' => 0,
				'duplicate_media'        => 0,
				'duplicate_featured'     => 0
			),
			'media_files_localization' => array(
				'posts'         => true,
				'custom_fields' => true,
				'strings'       => true
			),
			'wpml_media_2_3_migration' => true,
			'setup_run'                => false
		);

		if ( ! self::$settings ) {
			self::$settings = $default_settings;
		}
	}

	public static function has_setup_run() {
		return self::get_setting( 'setup_run' );
	}

	public static function set_setup_run( $value = 1 ) {
		return self::update_setting( 'setup_run', $value );
	}

	/**
	 *    This method, called on 'plugins_loaded' action, overrides or replaces WPML default behavior
	 */
	public function overrides() {
		global $sitepress, $pagenow;

		//Removes the WPML language metabox on media and replace it with the custom one
		remove_action( 'admin_head', array( $sitepress, 'post_edit_language_options' ) );
		if ( $pagenow == 'post.php' || $pagenow == 'post-new.php' || $pagenow == 'edit.php' ) {
			add_action( 'admin_head', array( $this, 'post_edit_language_options' ) );
		}
	}

	public static function get_setting( $name, $default = false ) {
		self::init_settings();
		if ( ! isset( self::$settings[ $name ] ) || ! self::$settings[ $name ] ) {
			return $default;
		}

		return self::$settings[ $name ];
	}

	public static function update_setting( $name, $value ) {
		self::init_settings();
		self::$settings[ $name ] = $value;

		return update_option( self::$settings_option_key, self::$settings );
	}

	function post_edit_language_options() {
		global $post, $sitepress, $pagenow;

		//Removes the language metabox on media
		if ( ( isset( $_POST['wp-preview'] ) && $_POST['wp-preview'] === 'dopreview' ) || is_preview() ) {
			$is_preview = true;
		} else {
			$is_preview = false;
		}

		//If not a media admin page, call the default WPML post_edit_language_options() method
		if ( ! ( $pagenow === 'upload.php' || $pagenow === 'media-upload.php' || $is_preview || ( isset( $post ) && $post->post_type === 'attachment' ) || is_attachment() ) ) {
			$sitepress->post_edit_language_options();
		}

	}

	function batch_scan_prepare() {
		global $wpdb;

		$response = array();
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'wpml_media_processed' ) );

		$response['message'] = __( 'Started...', 'wpml-media' );

		echo wp_json_encode( $response );
		exit;
	}

	static function create_duplicate_attachment( $attachment_id, $parent_id, $target_language ) {
		global $sitepress;

		$attachments_model      = new WPML_Model_Attachments( $sitepress, wpml_get_post_status_helper() );
		$attachment_duplication = new WPML_Media_Attachments_Duplication( $sitepress, $attachments_model );

		return $attachment_duplication->create_duplicate_attachment( $attachment_id, $parent_id, $target_language );
	}

	static function is_valid_post_type( $post_type ) {
		global $wp_post_types;

		$post_types = array_keys( (array) $wp_post_types );

		return in_array( $post_type, $post_types );
	}



	function find_posts_filter() {
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
	}

	function pre_get_posts( $query ) {
		$query->query['suppress_filters']      = 0;
		$query->query_vars['suppress_filters'] = 0;
	}

	function media_language_options() {
		global $sitepress;
		$att_id       = filter_input( INPUT_GET, 'attachment_id', FILTER_SANITIZE_NUMBER_INT, FILTER_NULL_ON_FAILURE );
		$translations = $sitepress->get_element_translations( $att_id, 'post_attachment' );
		$current_lang = '';
		foreach ( $translations as $lang => $id ) {
			if ( $id == $att_id ) {
				$current_lang = $lang;
				unset( $translations[ $lang ] );
				break;
			}
		}

		$active_languages = icl_get_languages( 'orderby=id&order=asc&skip_missing=0' );
		$lang_links       = '';

		if ( $current_lang ) {

			$lang_links = '<strong>' . $active_languages[ $current_lang ]['native_name'] . '</strong>';

		}

		foreach ( $translations as $lang => $id ) {
			$lang_links .= ' | <a href="' . admin_url( 'media.php?attachment_id=' . $id . '&action=edit' ) . '">' . $active_languages[ $lang ]['native_name'] . '</a>';
		}


		echo '<div id="icl_lang_options" style="display:none">' . $lang_links . '</div>';
	}

	/**
	 * @param $source_attachment_id
	 * @param $pidd
	 * @param $lang
	 *
	 * @return int|null|WP_Error
	 */
	public function create_duplicate_attachment_not_static( $source_attachment_id, $pidd, $lang ) {
		return self::create_duplicate_attachment( $source_attachment_id, $pidd, $lang );
	}

	function make_duplicate( $master_post_id, $target_lang, $post_array, $target_post_id ) {
		global $wpdb, $sitepress;

		$translated_attachment_id = false;
		//Get Master Post attachments
		$master_post_attachment_ids_prepared = $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_parent = %d AND post_type = %s", array(
			$master_post_id,
			'attachment'
		) );
		$master_post_attachment_ids          = $wpdb->get_col( $master_post_attachment_ids_prepared );

		if ( $master_post_attachment_ids ) {
			foreach ( $master_post_attachment_ids as $master_post_attachment_id ) {

				$attachment_trid = $sitepress->get_element_trid( $master_post_attachment_id, 'post_attachment' );

				if ( $attachment_trid ) {
					//Get attachment translation
					$attachment_translations = $sitepress->get_element_translations( $attachment_trid, 'post_attachment' );

					foreach ( $attachment_translations as $attachment_translation ) {
						if ( $attachment_translation->language_code == $target_lang ) {
							$translated_attachment_id = $attachment_translation->element_id;
							break;
						}
					}

					if ( ! $translated_attachment_id ) {
						$translated_attachment_id = self::create_duplicate_attachment( $master_post_attachment_id, wp_get_post_parent_id( $master_post_id ), $target_lang );
					}

					if ( $translated_attachment_id ) {
						//Set the parent post, if not already set
						$translated_attachment = get_post( $translated_attachment_id );
						if ( ! $translated_attachment->post_parent ) {
							$prepared_query = $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_parent=%d WHERE ID=%d", array(
								$target_post_id,
								$translated_attachment_id
							) );
							$wpdb->query( $prepared_query );
						}
					}
				}

			}
		}

		// Duplicate the featured image.

		$thumbnail_id = get_post_meta( $master_post_id, '_thumbnail_id', true );

		if ( $thumbnail_id ) {

			$thumbnail_trid = $sitepress->get_element_trid( $thumbnail_id, 'post_attachment' );

			if ( $thumbnail_trid ) {
				// translation doesn't have a featured image
				$t_thumbnail_id = icl_object_id( $thumbnail_id, 'attachment', false, $target_lang );
				if ( $t_thumbnail_id == null ) {
					$dup_att_id     = self::create_duplicate_attachment( $thumbnail_id, $target_post_id, $target_lang );
					$t_thumbnail_id = $dup_att_id;
				}

				if ( $t_thumbnail_id != null ) {
					update_post_meta( $target_post_id, '_thumbnail_id', $t_thumbnail_id );
				}
			}

		}

		return $translated_attachment_id;
	}

	/**
	 * Synchronizes _wpml_media_* meta fields with all translations
	 *
	 * @param int $meta_id
	 * @param int $object_id
	 * @param string $meta_key
	 * @param string|mixed $meta_value
	 */
	function updated_postmeta( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( in_array( $meta_key, array( '_wpml_media_duplicate', '_wpml_media_featured' ) ) ) {
			global $sitepress;
			$el_type      = 'post_' . get_post_type( $object_id );
			$trid         = $sitepress->get_element_trid( $object_id, $el_type );
			$translations = $sitepress->get_element_translations( $trid, $el_type, true, true );
			foreach ( $translations as $translation ) {
				if ( $translation->element_id != $object_id ) {
					$t_meta_value = get_post_meta( $translation->element_id, $meta_key, true );
					if ( $t_meta_value != $meta_value ) {
						update_post_meta( $translation->element_id, $meta_key, $meta_value );
					}
				}
			}
		}
	}

	function save_attachment_actions( $post_id ) {
		if ( $this->is_uploading_plugin_or_theme() && get_post_type( $post_id ) == 'attachment' ) {
			return;
		}

		global $wpdb, $sitepress;

		$media_language = $sitepress->get_language_for_element( $post_id, 'post_attachment' );
		$trid           = false;
		if ( ! empty( $media_language ) ) {
			$trid = $sitepress->get_element_trid( $post_id, 'post_attachment' );
		}
		if ( empty( $media_language ) ) {
			$parent_post_sql      = "SELECT p2.ID, p2.post_type FROM {$wpdb->posts} p1 JOIN {$wpdb->posts} p2 ON p1.post_parent = p2.ID WHERE p1.ID=%d";
			$parent_post_prepared = $wpdb->prepare( $parent_post_sql, array( $post_id ) );
			$parent_post          = $wpdb->get_row( $parent_post_prepared );

			if ( $parent_post ) {
				$media_language = $sitepress->get_language_for_element( $parent_post->ID, 'post_' . $parent_post->post_type );
			}

			if ( empty( $media_language ) ) {
				$media_language = $sitepress->get_admin_language_cookie();
			}
			if ( empty( $media_language ) ) {
				$media_language = $sitepress->get_default_language();
			}

		}
		if ( ! empty( $media_language ) ) {
			$sitepress->set_element_language_details( $post_id, 'post_attachment', $trid, $media_language );
		}
	}

	/**
	 *Add a filter to fix the links for attachments in the language switcher so
	 *they point to the corresponding pages in different languages.
	 */
	function filter_link( $url, $lang_info ) {
		return $url;
	}

	function wp_get_attachment_url( $url, $post_id ) {
		global $sitepress;

		return $sitepress->convert_url( $url );
	}

	function icl_ls_languages( $w_active_languages ) {
		static $doing_it = false;

		if ( is_attachment() && ! $doing_it ) {
			$doing_it = true;
			// Always include missing languages.
			$w_active_languages = icl_get_languages( 'skip_missing=0' );
			$doing_it           = false;
		}

		return $w_active_languages;
	}

	function get_post_metadata( $value, $object_id, $meta_key, $single ) {
		if ( $meta_key == '_thumbnail_id' ) {

			global $wpdb;

			$thumbnail_prepared = $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", array(
				$object_id,
				$meta_key
			) );
			$thumbnail          = $wpdb->get_var( $thumbnail_prepared );

			if ( $thumbnail == null ) {
				// see if it's available in the original language.

				$post_type_prepared = $wpdb->prepare( "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", array( $object_id ) );
				$post_type          = $wpdb->get_var( $post_type_prepared );
				$trid_prepared      = $wpdb->prepare( "SELECT trid, source_language_code FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type = %s", array(
					$object_id,
					'post_' . $post_type
				) );
				$trid               = $wpdb->get_row( $trid_prepared );
				if ( $trid ) {

					global $sitepress;

					$translations = $sitepress->get_element_translations( $trid->trid, 'post_' . $post_type );
					if ( isset( $translations[ $trid->source_language_code ] ) ) {
						$translation = $translations[ $trid->source_language_code ];
						// see if the original has a thumbnail.
						$thumbnail_prepared = $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", array(
							$translation->element_id,
							$meta_key
						) );
						$thumbnail          = $wpdb->get_var( $thumbnail_prepared );
						if ( $thumbnail ) {
							$value = $thumbnail;
						}
					}
				}
			} else {
				$value = $thumbnail;
			}

		}

		return $value;
	}

	/**
	 * @param string $menu_id
	 */
	public function menu( $menu_id ) {
		if ( 'WPML' !== $menu_id ) {
			return;
		}

		$menu_label         = __( 'Media Translation', 'wpml-media' );
		$menu               = array();
		$menu['order']      = 600;
		$menu['page_title'] = $menu_label;
		$menu['menu_title'] = $menu_label;
		$menu['capability'] = 'edit_others_posts';
		$menu['menu_slug']  = 'wpml-media';
		$menu['function']   = array( $this, 'menu_content' );

		do_action( 'wpml_admin_menu_register_item', $menu );
	}

	public function menu_content() {
		$menus = $this->menus_factory->create();
		$menus->display();
	}

	//check if the image is not duplicated to another post before deleting it physically
	function views_upload( $views ) {
		global $sitepress, $wpdb, $pagenow;

		if ( $pagenow == 'upload.php' ) {
			//get current language
			$lang = $sitepress->get_current_language();

			foreach ( $views as $key => $view ) {
				// extract the base URL and query parameters
				$href_count = preg_match( '/(href=["\'])([\s\S]+?)\?([\s\S]+?)(["\'])/', $view, $href_matches );
				if ( $href_count && isset( $href_args ) ) {
					$href_base = $href_matches[2];
					wp_parse_str( $href_matches[3], $href_args );
				} else {
					$href_base = 'upload.php';
					$href_args = array();
				}

				if ( $lang != 'all' ) {
					$sql = $wpdb->prepare( "
						SELECT COUNT(p.id)
						FROM {$wpdb->posts} AS p
							INNER JOIN {$wpdb->prefix}icl_translations AS t
								ON p.id = t.element_id
						WHERE p.post_type = 'attachment'
						AND t.element_type='post_attachment'
						AND t.language_code = %s ", $lang );

					switch ( $key ) {
						case 'all';
							$and = " AND p.post_status != 'trash' ";
							break;
						case 'detached':
							$and = " AND p.post_status != 'trash' AND p.post_parent = 0 ";
							break;
						case 'trash':
							$and = " AND p.post_status = 'trash' ";
							break;
						default:
							if ( isset( $href_args['post_mime_type'] ) ) {
								$and = " AND p.post_status != 'trash' " . wp_post_mime_type_where( $href_args['post_mime_type'], 'p' );
							} else {
								$and = $wpdb->prepare( " AND p.post_status != 'trash' AND p.post_mime_type LIKE %s", $key . '%' );
							}
					}

					$and = apply_filters( 'wpml-media_view-upload-sql_and', $and, $key, $view, $lang );

					$sql_and = $sql . $and;
					$sql     = apply_filters( 'wpml-media_view-upload-sql', $sql_and, $key, $view, $lang );

					$res = apply_filters( 'wpml-media_view-upload-count', null, $key, $view, $lang );
					if ( null === $res ) {
						$res = $wpdb->get_col( $sql );
					}
					//replace count
					$view = preg_replace( '/\((\d+)\)/', '(' . $res[0] . ')', $view );
				}

				//replace href link, adding the 'lang' argument and the revised count
				$href_args['lang'] = $lang;
				$href_args         = array_map( 'urlencode', $href_args );
				$new_href          = add_query_arg( $href_args, $href_base );
				$views[ $key ]     = preg_replace( '/(href=["\'])([\s\S]+?)(["\'])/', '$1' . $new_href . '$3', $view );
			}
		}

		return $views;
	}

	function delete_file( $file ) {
		if ( $file ) {
			global $wpdb;
			//get file name from full name
			$file_name = $this->get_file_name_without_size_from_full_name( $file );
			//check file name in DB
			$attachment_prepared = $wpdb->prepare( "SELECT pm.meta_id, pm.post_id FROM {$wpdb->postmeta} AS pm WHERE pm.meta_value LIKE %s", array( '%' . $file_name ) );
			$attachment          = $wpdb->get_row( $attachment_prepared );
			//if exist return NULL(do not delete physically)
			if ( ! empty( $attachment ) ) {
				$file = null;
			}
		}

		return $file;
	}

	public function get_file_name_without_size_from_full_name( $file ) {
		$file_name = preg_replace( '/^(.+)\-\d+x\d+(\.\w+)$/', '$1$2', $file );
		$file_name = preg_replace( '/^[\s\S]+(\/.+)$/', '$1', $file_name );
		$file_name = str_replace( '/', '', $file_name );

		return $file_name;
	}

	/**
	 * @param $ids
	 * @param $target_language
	 *
	 * @return array|string
	 */
	public function translate_attachment_ids( $ids, $target_language ) {
		global $sitepress;
		$return_string = false;
		if ( ! is_array( $ids ) ) {
			$attachment_ids = explode( ',', $ids );
			$return_string  = true;
		}

		$translated_ids = array();
		if ( ! empty( $attachment_ids ) ) {
			foreach ( $attachment_ids as $attachment_id ) {
				//Fallback to the original ID
				$translated_id = $attachment_id;

				//Find the ID translation
				$trid = $sitepress->get_element_trid( $attachment_id, 'post_attachment' );
				if ( $trid ) {
					$id_translations = $sitepress->get_element_translations( $trid, 'post_attachment', false, true );
					foreach ( $id_translations as $language_code => $id_translation ) {
						if ( $language_code == $target_language ) {
							$translated_id = $id_translation->element_id;
							break;
						}
					}
				}

				$translated_ids[] = $translated_id;
			}
		}

		if ( $return_string ) {
			return implode( ',', $translated_ids );
		}

		return $translated_ids;

	}

	/**
	 * Update query for media-upload.php page.
	 *
	 * @param object $query WP_Query
	 */
	public function filter_media_upload_items( $query ) {
		$current_lang = $this->sitepress->get_current_language();
		$ids          = icl_cache_get( '_media_upload_attachments' . $current_lang );

		if ( false === $ids ) {
			$tbl      = $this->wpdb->prefix . 'icl_translations';
			$db_query = "
				SELECT posts.ID
				FROM {$this->wpdb->posts} as posts, $tbl as icl_translations
				WHERE posts.post_type = 'attachment'
				AND icl_translations.element_id = posts.ID
				AND icl_translations.language_code = %s
				";

			$posts = $this->wpdb->get_results( $this->wpdb->prepare( $db_query, $current_lang ) );
			$ids   = array();
			if ( ! empty( $posts ) ) {
				foreach ( $posts as $post ) {
					$ids[] = absint( $post->ID );
				}
			}

			icl_cache_set( '_media_upload_attachments' . $current_lang, $ids );
		}

		$query->set( 'post__in', $ids );
	}

}
