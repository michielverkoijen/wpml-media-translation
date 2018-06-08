<?php

class WPML_Media_Add_To_Translation_Package_Factory implements IWPML_Backend_Action_Loader {

	public function create() {
		return new WPML_Media_Add_To_Translation_Package();
	}

}