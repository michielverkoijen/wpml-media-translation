<?php

class WPML_Media_Detect_Media_Without_Language implements IWPML_Action {
	/**
	 * @var wpdb
	 */
	private $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function add_hooks() {
		add_action( 'init', array( $this, 'maybe_trigger_setup' ) );
	}

	public function maybe_trigger_setup() {
		if ( $this->have_media_without_language() ) {
			WPML_Media::set_setup_run( 0 );
		}
	}

	private function have_media_without_language() {

		$sql = "
				SELECT COUNT(*)
				FROM {$this->wpdb->posts}
				WHERE post_type = 'attachment'
					AND ID NOT IN (
						SELECT element_id
							FROM {$this->wpdb->prefix}icl_translations
							WHERE element_type='post_attachment'
						)";

		return $this->wpdb->get_var( $sql );

	}
}