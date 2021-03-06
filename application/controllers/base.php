<?php

class Base_Controller extends Controller {

	/**
	 * Catch-all method for requests that can't be matched.
	 *
	 * @param  string    $method
	 * @param  array     $parameters
	 * @return Response
	 */
	public function __call($method, $parameters) {
		return Response::error('404');
	}

	/**
	 * Base contorller loader
	 *
	 * @access	public
	 */
	public function __construct() {
		// to pass data to views
		$this->data	= new StdClass;

		// javascript files
		Asset::add('jquery',		'js/jquery.js');
		Asset::add('bootstrap',	'js/bootstrap.js');
		Asset::add('select',		'js/select.js');
		Asset::add('holder',		'js/holder.js');
		Asset::add('functions',	'js/functions.js');

		// styles
		Asset::add('styles',			'css/styles.css');
		Asset::add('responsive',	'css/responsive.css');
		Asset::add('icons',			'css/icons.css');
		Asset::add('icons-ie7',		'css/icons-ie7.css');
		Asset::add('theme',			'css/theme.css');
		Asset::add('select',			'css/select.css');
		Asset::add('custom',			'css/custom.css');

		// keep updated the information of the logged in user
		$id = Session::get('id');

		if (!empty($id)) {

			$user = User::find($id);
			$role = User::find($id)->role()->first()->role_id;

			Session::put('firstname', $user->firstname);
			Session::put('lastname', $user->lastname);
			Session::put('fullname', $user->fullname);
			Session::put('email', $user->email);
			Session::put('role', $role);
			Session::put('deleted', $user->deleted);
		}
	}

}