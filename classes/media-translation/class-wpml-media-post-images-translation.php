<?php

/**
 * Class WPML_Media_Post_Images_Translation
 * Translate images in posts translations when a post is created or updated
 */
class WPML_Media_Post_Images_Translation implements IWPML_Action {

	/**
	 * @var WPML_Media_Translated_Images_Update
	 */
	private $images_updater;

	/**
	 * @var SitePress
	 */
	private $sitepress;

	/**
	 * @var wpdb
	 */
	private $wpdb;
	/**
	 * @var WPML_Translation_Element_Factory
	 */
	private $translation_element_factory;
	/**
	 * @var WPML_Media_Custom_Field_Images_Translation_Factory
	 */
	private $custom_field_images_translation_factory;
	/**
	 * @var WPML_Media_Usage_Factory
	 */
	private $media_usage_factory;

	public function __construct(
		WPML_Media_Translated_Images_Update $images_updater,
		SitePress $sitepress,
		wpdb $wpdb,
		WPML_Translation_Element_Factory $translation_element_factory,
		WPML_Media_Custom_Field_Images_Translation_Factory $custom_field_images_translation_factory,
		WPML_Media_Usage_Factory $media_usage_factory
	) {
		$this->images_updater                          = $images_updater;
		$this->sitepress                               = $sitepress;
		$this->wpdb                                    = $wpdb;
		$this->translation_element_factory             = $translation_element_factory;
		$this->custom_field_images_translation_factory = $custom_field_images_translation_factory;
		$this->media_usage_factory                     = $media_usage_factory;
	}

	public function add_hooks() {
		add_action( 'save_post', array( $this, 'translate_images' ), PHP_INT_MAX, 2 );
		add_filter( 'wpml_pre_save_pro_translation', array( $this, 'translate_images_in_content' ), PHP_INT_MAX, 2 );

		add_action( 'icl_make_duplicate', array( $this, 'translate_images_in_duplicate' ), PHP_INT_MAX, 4 );

		add_action( 'wpml_added_media_file_translation', array( $this, 'translate_url_in_post' ), PHP_INT_MAX, 1 );
	}

	/**
	 * @param int $post_id
	 * @param WP_Post $post
	 */
	public function translate_images( $post_id, WP_Post $post = null ) {

		if ( null === $post ) {
			$post = get_post( $post_id );
		}

		$post_element    = $this->translation_element_factory->create( $post_id, 'post' );
		$source_language = $post_element->get_source_language_code();
		$language        = $post_element->get_language_code();
		if ( null !== $source_language ) {

			$this->translate_images_in_post_content( $post, $language, $source_language );
			$this->translate_featured_image( $post_id, $language, $source_language );
			$this->translate_images_in_custom_fields( $post_id, $language, $source_language );

		} else { // is original
			foreach ( array_keys( $this->sitepress->get_active_languages() ) as $target_language ) {
				$translation = $post_element->get_translation( $target_language );
				if ( null !== $translation && $post_id !== $translation->get_id() ) {
					$this->translate_images_in_post_content( get_post( $translation->get_id() ), $target_language, $language );
					$this->translate_featured_image( $translation->get_id(), $target_language, $language );
					$this->translate_images_in_custom_fields( $translation->get_id(), $target_language, $language );
				}
			}

		}
	}

	/**
	 * @param int $master_post_id
	 * @param string $language
	 * @param array $post_array
	 * @param int $post_id
	 */
	public function translate_images_in_duplicate( $master_post_id, $language, $post_array, $post_id ) {
		$this->translate_images( $post_id );
	}

	/**
	 * @param WP_Post $post
	 * @param string $target_language
	 * @param string $source_language
	 */
	private function translate_images_in_post_content( WP_Post $post, $target_language, $source_language ) {
		$post_content_filtered = $this->images_updater->replace_images_with_translations(
			$post->post_content,
			$target_language,
			$source_language
		);
		if ( $post_content_filtered !== $post->post_content ) {
			$this->wpdb->update(
				$this->wpdb->posts,
				array( 'post_content' => $post_content_filtered ),
				array( 'ID' => $post->ID ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	private function translate_featured_image( $translated_post_id, $language, $source_language ) {
		$translated_post_element = $this->translation_element_factory->create( $translated_post_id, 'post' );
		$original_post_element   = $translated_post_element->get_translation( $source_language );

		if ( $original_featured_image = get_post_meta( $original_post_element->get_id(), '_thumbnail_id', true ) ) {
			$attachment_element            = $this->translation_element_factory->create( $original_featured_image, 'post' );
			$translated_attachment_element = $attachment_element->get_translation( $language );
			$translated_featured_image     = null !== $translated_attachment_element ? $translated_attachment_element->get_id() : false;
			if ( $original_featured_image !== $translated_featured_image ) {
				update_post_meta( $translated_post_id, '_thumbnail_id', $translated_featured_image );
			}
		}
	}

	/**
	 * @param int $post_id
	 */
	private function translate_images_in_custom_fields( $post_id ) {

		$custom_fields_image_translation = $this->custom_field_images_translation_factory->create();
		if ( $custom_fields_image_translation ) {
			$post_meta = get_metadata( 'post', $post_id );
			foreach ( $post_meta as $meta_key => $meta_value ) {
				$custom_fields_image_translation->translate_images( null, $post_id, $meta_key, $meta_value[0] );
			}
		}

	}

	/**
	 * @param array $postarr
	 * @param stdClass $job
	 *
	 * @return array
	 */
	public function translate_images_in_content( array $postarr, stdclass $job ) {

		$postarr['post_content'] = $this->images_updater->replace_images_with_translations(
			$postarr['post_content'],
			$job->language_code,
			$job->source_language_code
		);

		return $postarr;
	}

	public function translate_url_in_post( $attachment_id ) {
		$media_usage = $this->media_usage_factory->create( $attachment_id );
		$posts = $media_usage->get_posts();
		foreach( $posts as $post_id ){
			$this->translate_images( $post_id );
		}
	}

}