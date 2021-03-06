<?php

class m_UENTITY_ extends RecordModel
{
	var $table        = '_DB_TABLE_';
	var $enable_cache = false;

	function validate($data)
	{
		$errors = array();
		$this->validator->required($errors, array(), $data);
		return $errors;
	}

	function create_record()
	{
		return parent::create_record();
	}

	function save_record($data)
	{
		return parent::save_record($data);
	}

	function load_record($id)
	{
		return parent::load_record($id);
	}

	function delete_record($id)
	{
		parent::delete_record($id);
	}

	function enum_schema()
	{
		return array(
			'from'       => $this->table,
			'exprs'      => array(),
			'gexprs'     => array(),
			'select'     => '*',
			'where'      => '',
			'group_by'   => '',
			'having'     => '',
			'order'      => '_DEFAULT_SORT_ ASC',
			'limit'      => 50
		);
	}
}

?>
