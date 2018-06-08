<?php

class WPML_Media_Populate_Media_Strings_Translations implements IWPML_Action {

	/**
	 * @var WPML_Translation_Element_Factory
	 */
	private $translation_element_factory;

	/**
	 * @var WPML_Element_Translation_Package
	 */
	private $translation_package;

	public function __construct(
		WPML_Translation_Element_Factory $translation_element_factory,
		WPML_Element_Translation_Package $translation_package
	) {
		$this->translation_element_factory = $translation_element_factory;
		$this->translation_package         = $translation_package;
	}

	public function add_hooks() {
		add_filter( 'wpml_tm_populate_prev_translation', array( $this, 'populate' ), 10, 3 );
	}

	public function populate( $prev_translation, $package, $lang ) {

		if ( ! $prev_translation ) {
			foreach ( $package['contents'] as $field => $data ) {
				if ( $media_field = $this->is_media_field( $field ) ) {

					$attachment             = $this->translation_element_factory->create( $media_field['id'], 'post' );
					$attachment_translation = $attachment->get_translation( $lang );

					if ( $attachment_translation ) {
						switch ( $media_field['field'] ) {
							case 'title':
								$translated_value = get_post_field( 'post_title', $media_field['id'] );
								break;
							case 'caption':
								$translated_value = get_post_field( 'post_excerpt', $media_field['id'] );
								break;
							case 'description':
								$translated_value = get_post_field( 'post_content', $media_field['id'] );
								break;
							case 'alt_text':
								$translated_value = get_post_meta( $media_field['id'], '_wp_attachment_image_alt', true );
								break;
							default:
								$translated_value = false;

						}

						if ( $translated_value ) {
							$prev_translation[ $field ] = new WPML_TM_Translated_Field( $field,
								'', $this->translation_package->encode_field_data( $translated_value, 'base64' ), true );
						}
					}

				}
			}


		}

		return $prev_translation;
	}

	private function is_media_field( $field ) {
		$media_field = array();

		if ( preg_match( '#^media_([0-9]+)_([a-z_]+)$#', $field, $matches ) ) {
			$media_field['id']    = $matches[1];
			$media_field['field'] = $matches[2];
		}

		return $media_field;
	}
}