<?php

/**
 * Class WPML_Media_Image_Translate
 * Allows getting translated images in a give language from an attachment
 */
class WPML_Media_Image_Translate {

	/**
	 * @var SitePress
	 */
	private $sitepress;

	/**
	 * @var WPML_Media_Attachment_By_URL_Factory
	 */
	private $attachment_by_url_factory;

	/**
	 * WPML_Media_Image_Translate constructor.
	 *
	 * @param SitePress $sitepress
	 */
	public function __construct( SitePress $sitepress, WPML_Media_Attachment_By_URL_Factory $attachment_by_url_factory ) {
		$this->sitepress                 = $sitepress;
		$this->attachment_by_url_factory = $attachment_by_url_factory;
	}

	/**
	 * @param int $attachment_id
	 * @param string $language
	 * @param string $size
	 *
	 * @return string
	 */
	public function get_translated_image( $attachment_id, $language, $size = null ) {

		$attachment             = new WPML_Post_Element( $attachment_id, $this->sitepress );
		$attachment_translation = $attachment->get_translation( $language );
		$image_url              = '';

		if ( $attachment_translation ) {
			$uploads_dir = wp_get_upload_dir();
			if ( null === $size ) {
				$image_url = $uploads_dir['baseurl'] . '/' .
				             get_post_meta( $attachment_translation->get_id(), '_wp_attached_file', true );
			} else {
				$meta_data = wp_get_attachment_metadata( $attachment_translation->get_id() );
				if ( isset( $meta_data['sizes'][ $size ] ) ) {
					$image_url = $uploads_dir['baseurl'] . '/' . $meta_data['sizes'][ $size ]['file'];
				}
			}
		}

		return $image_url;
	}

	/**
	 * @param string $img_src
	 * @param string $source_language
	 * @param string $target_language
	 *
	 * @return string|bool
	 */
	public function get_translated_image_by_url( $img_src, $source_language, $target_language ) {

		$attachment_by_url = $this->attachment_by_url_factory->create( $img_src, $source_language );
		$attachment_id = $attachment_by_url->get_id();

		if ( $attachment_id ) {
			$size = $this->get_image_size_from_url( $img_src, $attachment_id );
			try {
				$img_src = $this->get_translated_image( $attachment_id, $target_language, $size );
			} catch ( Exception $e ) {
				$img_src = false;
			}

		} else {
			$img_src = false;
		}

		return $img_src;
	}

	/**
	 * @param string $url
	 * @param int $attachment_id
	 *
	 * @return string
	 */
	private function get_image_size_from_url( $url, $attachment_id ) {
		$size = null;

		$thumb_file_name      = basename( $url );
		$attachment_meta_data = wp_get_attachment_metadata( $attachment_id );
		foreach ( $attachment_meta_data['sizes'] as $key => $size_array ) {
			if ( $thumb_file_name === $size_array['file'] ) {
				$size = $key;
				break;
			}
		}

		return $size;
	}


}