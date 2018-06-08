<?php

class WPML_Media_Add_To_Translation_Package implements IWPML_Action {

	public function add_hooks() {

		add_action( 'wpml_tm_translation_job_data', array( $this, 'add_media_strings' ), 10, 2 );
	}

	public function add_media_strings( $package, $post ) {

		$bundled_media_data = $this->get_bundled_media_to_translate( $post );
		if ( $bundled_media_data ) {

			$translation_package = new WPML_Element_Translation_Package();

			$package['contents'][ 'includes_media_translation' ] = array(
				'translate' => 0,
				'data' => count( $bundled_media_data ),
				'format' => ''
			);

			foreach ( $bundled_media_data as $attachment_id => $data ) {
				foreach( $data as $field => $value ){
					$package['contents'][ 'media_' . $attachment_id . '_' . $field ] = array(
						'translate' => 1,
						'data' => $translation_package->encode_field_data( $value, 'base64' ),
						'format' => 'base64'
					);
				}

			}

		}

		return $package;
	}

	private function get_bundled_media_to_translate( $post ) {
		$basket = TranslationProxy_Basket::get_basket( true );

		$bundled_media_data = array();

		if ( isset( $basket['post'][ $post->ID ]['media-translation'] ) ) {

			foreach ( $basket['post'][ $post->ID ]['media-translation'] as $attachment_id ) {

				$attachment = get_post( $attachment_id );

				if ( $attachment->post_title ) {
					$bundled_media_data[ $attachment_id ]['title'] = $attachment->post_title;
				}
				if ( $attachment->post_excerpt ) {
					$bundled_media_data[ $attachment_id ]['caption'] = $attachment->post_excerpt;
				}
				if ( $attachment->post_content ) {
					$bundled_media_data[ $attachment_id ]['description'] = $attachment->post_content;
				}
				if ( $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
					$bundled_media_data[ $attachment_id ]['alt_text'] = $alt;
				}

			}

		}

		return $bundled_media_data;

	}

}