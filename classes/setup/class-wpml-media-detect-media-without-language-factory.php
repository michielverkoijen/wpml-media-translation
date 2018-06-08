<?php

class WPML_Media_Detect_Media_Without_Language_Factory implements IWPML_Backend_Action_Loader, IWPML_AJAX_Action_Loader {

	public function create() {
		global $wpdb;
		return new WPML_Media_Detect_Media_Without_Language( $wpdb );
	}
}