<?php
/**
 * PRONTO WEB FRAMEWORK
 * @copyright Copyright (C) 2006, Judd Vinet
 * @author Judd Vinet <jvinet@zeroflux.org>
 *
 * Description: Template plugin for generating "smart" and "dumb" tables.
 *              Dumb tables are simply a set of headers and rows of data.
 *              Smart tables have filters, sortable columns, pagination, and
 *              totals.
 *
 **/
class tpTable extends Plugin
{
	var $guid = 0;

	/**
	 * Constructor
	 */
	function tpTable() {
		$this->Plugin();
		$this->depend('html','form');
	}

	/**
	 * Generate a simple table.
	 *
	 * @param array $params Table parameters
	 *
	 * Parameters:
	 *   - class         :: css class for the table tag
	 *   - attribs       :: additional attributes for the table tag
	 *   - headers array
	 *   - rows 2-d array
	 */
	function build_table($params)
	{
		$attribs = $this->_getparam($params, 'attribs', array());
		$tbl_id = isset($attribs['id']) ? $attribs['id'] : 'table'.++$this->guid;

		// <table>
		$out = '<table id="'.$tbl_id.'" cellspacing="0"';
		if(isset($params['class'])) {
			$out .= ' class="'.$params['class'].'"';
		}
		foreach($attribs as $k=>$v) {
			$out .= " $k=\"$v\"";
		}
		$out .= ">\n";

		// COLUMN HEADERS
		if(isset($params['headers']) && is_array($params['headers'])) {
			$out .= "<tr>\n";
			foreach($params['headers'] as $label) {
				$out .= "<th>$label</th>\n";
			}
			$out .= "</tr>\n";
		}

		// ROWS
		$rowct = 0;
		foreach($params['rows'] as $row) {
			$out .= '<tr';
			if(++$rowct % 2 == 1) {
				$out .= ' class="altrow"';
			}
			//$out .= ' onMouseOver="$(this).addClass(\'highlight\')" onMouseOut="$(this).removeClass(\'highlight\')" onClick="$(\'#'.$tbl_id.' tr\').removeClass(\'selected\');$(this).addClass(\'selected\')">'."\n";
			$out .= '>'."\n";
			foreach($row as $data) {
				$out .= "<td>$data</td>\n";
			}
			$out .= "</tr>\n";
		}

		$js  = "$('#{$tbl_id} tr').click(function(){ $('#{$tbl_id} tr').removeClass('selected');$(this).addClass('selected'); });";
		$js .= "$('#{$tbl_id} tr').mouseover(function(){ $(this).addClass('highlight'); });";
		$js .= "$('#{$tbl_id} tr').mouseout(function(){ $(this).removeClass('highlight'); });";
		$this->depends->html->js_run('', $js);

		$out .= "</table>\n";
		return $out;
	}

	/**
	 * Generate a table of data with search filters/pagination/sorting/totals
	 *
	 * @param array $params Table parameters
	 *
	 * Parameters:
	 *   - class         :: css class for the table tag
	 *   - url           :: url to pass to for filter changes (CURRENT_URL)
	 *   - noresults_txt :: text to display if no results are found (No Matches)
	 *   - data_id       :: name of column in dataset that uniquely identifies each record (id)
	 *   - rows          :: total number of rows in result set
	 *   - perpage       :: number of rows per page
	 *   - curpage       :: the current page number
	 *   - rowclick      :: action URL to go to if a table row is clicked
	 *   - perpage_opts  :: array of rows-per-page options for pagination control
	 *   - options array :: override default table behavior ('noheaders','nosorting','nofilters','nofilterbutton''nototals','nopagination')
	 *   - totals array  :: array of data row indices that we should tally/display
	 *                      eg, 'totals' => array('amount' => array('format'=>'%.2f'))
	 *   - cb_vars array :: array of variables that get passed to callback functions (used for special display logic)
	 *   - rowclassfn    :: function to call with row data - use this to pass back an additional tr class
	 *   - columns array
	 *     - key = index into data array
	 *     - val = array
	 *       - label          :: header text label
	 *       - align          :: td alignment
	 *       - type           :: filter input type (text/select/date/none)
	 *       - flength        :: length of filter field (for text fields only)
	 *       - options        :: options array for type==select
	 *       - options_nokeys :: same as above, but use values in <option> fields instead of keys
	 *       - attribs        :: attributes array, passed to the form-field generator function
	 *       - format         :: a sprintf string to pass the data through before displaying it
	 *       - date_format    :: if field is a date, specify the format string to be passed to date()
	 *       - display_map    :: if the values of a column map directly to certain display values, assign the map array to 'display_map'
	 *       - display_func   :: a function to run on the entire row, handy for output that isn't necessarily bound to a specific column from the result set
	 *       - expr           :: if column key is not a real db column, specify real one here
	 *   - data array
	 */
	function build_grid($params)
	{
		$guid     = 'grid' . ++$this->guid;
		$class    = $this->_getparam($params, 'class', 'grid');
		$options  = $this->_getparam($params, 'options', array());
		$data_id  = $this->_getparam($params, 'data_id', 'id');
		$grid_url = $this->_getparam($params, 'url', $this->depends->html->url(CURRENT_URL));
		$pp_opts  = $this->_getparam($params, 'perpage_opts', array(50,200,500,1000));

		if(!isset($params['noresults_txt'])) $params['noresults_txt'] = __('No Matches');

		// setup tooltips
		$this->depends->html->js_load('jq_tooltip', 'jq/jquery.tooltip');
		$this->depends->html->js_run('jq_tooltip', '$(\'td.options img\').Tooltip({showURL:false,extraClass:\'action\'});');
		$this->depends->html->css_load('tooltip', 'tooltip');

		// grid styles
		$this->depends->html->css_load('grid', 'grid');

		// setup AJAX routines if necessary
		if($options['ajax']) {
			$this->depends->html->js_load('ajax', 'ajax');
			$this->depends->html->js_load('grid', 'grid');
			$this->depends->html->js_run('grid', '$(\'.ajax_action\').click(grid_dispatch);');
		}

		$cb_vars = array();
		if(isset($params['cb_vars'])) {
			foreach($params['cb_vars'] as $k=>$v) {
				// (foreach can't use references in PHP4, so we do it this way)
				$cb_vars[$k] =& $params['cb_vars']["$k"];
			}
		}

		$totals = array();
		if(isset($params['totals'])) {
			foreach($params['totals'] as $k=>$v) {
				$totals[$k] = 0;
			}
		}

		// <table>
		$out = '<table id="'.$guid.'" cellspacing="0"';
		if($class) {
			$out .= ' class="'.$class.'"';
		}
		$out .= ">\n";

		// COLUMN HEADERS
		if(!$options['noheaders']) {
			$out .= "<tr class=\"label\">\n";
			$i = 0;
			foreach($params['columns'] as $name=>$column) {
				$style = array();
				$class = array();
				$out .= '<th';
				if(++$i == count($params['columns'])) $style[] = 'border-right:none';
				if(!mb_ereg('^_OPTIONS_', $name)) {
					if($_GET['s_f'] == $name) {
						$class[] = 'hover';
					} else {
						$out .= ' onMouseOver="$(this).addClass(\'hover\')"';
						$out .= ' onMouseOut="$(this).removeClass(\'hover\')"';
					}
					if(!$options['nosorting'] && !$column['nosort']) {
						$out .= ' onClick="location.href=$(this).find(\'a\').attr(\'href\');return false;"';
					}
					if(mb_ereg('^_MULTI_', $name)) $style[] = 'text-align:center';
				}
				$out .= ' class="'.implode(' ',$class).'"';
				$out .= ' style="'.implode(';',$style).'">';
				if(mb_ereg('^_OPTIONS_', $name)) {
					if($name == '_OPTIONS_' && !$options['nofilters'] && !$options['nofilterbutton']) {
						// TODO: show a different filters.html based on language selected (i18n)
						$out .= $this->depends->html->link(__('Filter Help'), url('/static/filters.en.html'), false, true, array('class'=>'help'), true);
					} else {
						$out .= mb_substr($name, 9);
					}
				} else {
					if(mb_ereg('^_MULTI_',$name)) {
						$label = mb_substr($name, 7);
						$name  = '_m_'.strtolower($label);
					} else {
						$label = $column['label'] ? $column['label'] : '&nbsp;';
					}
					if(!$options['nosorting'] && !$column['nosort']) {
						// build new query string with sort parameters
						$GET = $_GET;
						$qs = array();
						$sortdir  = ($GET['s_f'] == $name && $GET['s_d'] == 'asc') ? 'desc' : 'asc';
						$GET['s_f'] = $name;
						$GET['s_d'] = $sortdir;
						foreach($GET as $k=>$v) $qs[] = "$k=$v";
						$qs = implode('&', $qs);
						if($_GET['s_f'] == $name) {
							$arrowimg = $_GET['s_d'] == 'desc' ? 'arrow_down.gif' : 'arrow_up.gif';
							$out .= ' '.$this->depends->html->image('icons/'.$arrowimg, array('style'=>'float:right'));
						}
						$label = '<a href="'.$grid_url.'?'.$qs.'">'.$label.'</a>';
					}
					$out .= $label;
				}
				$out .= "</th>\n";
			}
			$out .= "</tr>\n";
		}

		// SEARCH FILTERS
		if(!$options['nofilters']) {
			$out .= '<form method="get" action="'.$grid_url.'">';
			// propagate GET vars
			foreach($_GET as $k=>$v) {
				// ignore pagination vars
				if($k == 'p_p' || $k == 'p_pp') continue;
				$out .= $this->depends->form->hidden($k, $v, array('id'=>"{$guid}_1_$k"));
			}
			$out .= "<tr class=\"filter\">\n";
			$i = 0;
			foreach($params['columns'] as $name=>$column) {
				$style = array();
				$class = array();
				$out .= '<th';
				if(++$i == count($params['columns'])) $style[] = 'border-right:none';
				if($_GET['s_f'] == $name) $class[] = 'hover';
				if(mb_ereg('^_MULTI_', $name)) $style[] = 'text-align:center';
				$out .= ' class="'.implode(' ',$class).'"';
				$out .= ' style="'.implode(';',$style).'">';
				if(mb_ereg('^_OPTIONS_', $name)) {
					if($name == '_OPTIONS_' && !$options['nofilters'] && !$options['nofilterbutton']) {
						$out .= $this->depends->form->submit('filter_submit',__('Filter'),array('style'=>'width:auto'))."</th>\n";
					} else {
						$out .= '&nbsp;';
					}
					continue;
				}
				if(mb_ereg('^_MULTI_', $name)) {
					$mname = strtolower(mb_substr($name, 7));
					if($mname) $mname .= '_';
					$out .= $this->depends->form->checkbox("_{$mname}all",'all','',false,array('style'=>'width:auto;border:none','onClick'=>"var c=this.checked; $('#$guid input[@type=checkbox][@name^={$mname}ids]').attr('checked',c?'checked':'')"))."</th>\n";
					continue;
				}
				/* XXX: disabled, can't remember why this was here...
				if(isset($column['cb_fn'])) {
					// no filters for special display callback functions
					$out .= "&nbsp;</th>\n";
					continue;
				}
				*/
				if($column['type'] == 'none') {
					$elem = '';
				} else {
					$t = mb_substr($column['type'], 0, 1);
					if($t == '') $t = 't';
					$expr = isset($column['expr']) ? 'f_'.$t.'_'.$column['expr'] : 'f_'.$t.'_'.$name;
					$opts = array(''=>'');
					if(is_array($column['options'])) foreach($column['options'] as $k=>$v) $opts[$k] = $v;
					if(is_array($column['options_nokeys'])) foreach($column['options_nokeys'] as $v) $opts[$v] = $v;
					$attribs = isset($column['attribs']) ? $column['attribs'] : array();
					switch($column['type']) {
						case 'select': $elem = $this->depends->form->select($expr, $_GET[$expr], $opts, '', false, $attribs); break;
						case 'date':   $elem = $this->depends->form->date($expr, $_GET[$expr], '%Y-%m-%d', $attribs); break;
						case 'text':
						default:       $elem = $this->depends->form->text($expr, $_GET[$expr], isset($column['flength']) ? $column['flength'] : 10, 255, $attribs);
					}
				}
				$out .= "$elem</th>\n";
			}
			$out .= "</tr>\n";
			$out .= '</form>';
		}

		// TABLE DATA
		$rowct = 0;
		if(!count($params['data'])) {
			$out .= "<tr>\n";
			$out .= "<td colspan=\"100%\"><p>{$params['noresults_txt']}</p></td>\n";
			$out .= "</tr>\n";
		}

		// start a new form for multiselect boxes, if needed
		$multi_form = false;
		foreach($params['columns'] as $name=>$column) {
			if(ereg('^_MULTI_', $name)) $multi_form = true;
		}
		if($multi_form) {
			$out .= '<form name="multi" id="multi" method="get" action="'.$grid_url.'">';
		}

		foreach($params['data'] as $row) {
			$tr_dom_id = 'tr'.$this->guid++;
			$out .= '<tr id="'.$tr_dom_id.'"';
			$class = array();
			if(++$rowct % 2 != 1) $class[] = 'altrow';
			if(isset($params['rowclassfn'])) {
				$class[] = $params['rowclassfn']($row);
			}
			$out .= ' class="'.implode(' ',$class).'"';
			//$out .= ' onMouseOver="$(this).addClass(\'highlight\')" onMouseOut="$(this).removeClass(\'highlight\')" onClick="$(\'#'.$guid.' tr\').removeClass(\'selected\');$(this).addClass(\'selected\');';
			if($params['rowclick']) {
				$subs = array();
				preg_match_all('|<([A-z0-9\._-]+)>|U', $params['rowclick'], $subs);
				$out .= ' onClick="location.href=\''.$params['rowclick'].'\'.replace(\'_ID_\',\''.$this->_getrowdata($row, $data_id).'\')';
				if(is_array($subs[1])) foreach($subs[1] as $s) {
					$out .= ".replace('<$s>','".$this->_getrowdata($row, $s)."')";
				}
				$out .= '"';
			}
			$out .= '>';

			// fancy highlight/hover stuff
			$js  = "$('#{$guid} tr').click(function(){ $('#{$guid} tr').removeClass('selected');$(this).addClass('selected'); });";
			$js .= "$('#{$guid} tr').mouseover(function(){ $(this).addClass('highlight'); });";
			$js .= "$('#{$guid} tr').mouseout(function(){ $(this).removeClass('highlight'); });";
			$this->depends->html->js_run('', $js);

			foreach($params['columns'] as $name=>$column) {
				$out .= '<td';
				if(isset($column['align'])) {
					$out .= ' align="'.$column['align'].'"';
				}
				if(mb_ereg('^_OPTIONS_', $name)) {
					$out .= ' class="options">';
					foreach($column as $opt) {
						if(function_exists($opt)) {
							$out .= $opt($cb_vars, $row);
						} else {
							// perform substitutions in URL
							$lnk = str_replace('_ID_', $this->_getrowdata($row, $data_id), $opt).' ';

							$subs = array();
							preg_match_all('|<([A-z0-9\._-]+)>|U', $lnk, $subs);
							if(is_array($subs[1])) foreach($subs[1] as $s) {
								$lnk = str_replace("<$s>", $this->_getrowdata($row, $s), $lnk);
							}
							$out .= $lnk;
						}
					}
				} else if(mb_ereg('^_MULTI_', $name)) {
					$mname = strtolower(mb_substr($name, 7));
					if($mname) $mname .= '_';
					$out .= ' class="multi">';
					$out .= '<input type="checkbox" style="border:none" name="'.$mname.'ids[]" value="'.$this->_getrowdata($row, $data_id).'"';
					if($this->_getrowdata($row, "_m_$mname")) $out .= ' checked="checked"';
					$out .= '></td>'."\n";
				} else {
					$out .= '>';
					$data = $this->_getrowdata($row, $name);
					if(isset($totals[$name])) {
						$totals[$name] += $data;
					}
					/*
					 * Mangle row data if necessary
					 */
					if(isset($column['display_map'][$data])) {
						$data = $column['display_map'][$data];
					} else if(isset($column['cb_fn']) || isset($column['display_func'])) {
						// 'cb_fn' is the old one, left for compatibility
						$f = isset($column['display_func']) ? $column['display_func'] : $column['cb_fn'];
						if(!function_exists($f)) {
							// it's not a function, so create one
							// $g is an array of all global callback vars as defined in cb_vars
							// $d is the full data array for this row
							$f = create_function('$g,$d', $f);
						}
						$data = $f($cb_vars, $row);
					} else if(isset($column['format'])) {
						$data = sprintf($column['format'], $data);
					} else if(isset($column['date_format'])) {
						if($data == '0000-00-00') {
							$data = __('Never');
						} else {
							$data = date($column['date_format'], strtotime($data));
						}
					}
					$out .= $data;
				}
				$out .= "</td>\n";
			}
			$out .= "</tr>\n";
			// this <tr> is used by AJAX grids
			$out .= '<tr id="'.$tr_dom_id.'_form" class="ajaxcontent"';
			$out .= "><td id=\"{$tr_dom_id}_form_td\" style=\"display:none;padding-left:13px\" colspan=\"100%\"></td></tr>\n";
		}

		// TOTALS
		if(!$options['nototals'] && count($totals)) {
			$out .= "<tr class=\"totals\">\n";
			$i = 0;
			foreach($params['columns'] as $name=>$column) {
				$out .= '<td';
				if(isset($column['align'])) {
					$out .= ' align="'.$column['align'].'"';
				}
				$out .= '>';
				if($i == 0 && !isset($totals[$name])) {
					$out .= '<strong>'.__('Totals').':</strong>';
				}
				if(isset($totals[$name])) {
					if(isset($params['totals'][$name]['format'])) {
						$totals[$name] = sprintf($params['totals'][$name]['format'], $totals[$name]);
					}
					$out .= '<strong>'.$totals[$name].'</strong>';
				}
				$out .= "</td>\n";
				$i++;
			}
			$out .= "</tr>\n";
		}

		// MULTI-SELECT ACTIONS
		if(!empty($params['data'])) {
			$has_multi = false;
			foreach($params['columns'] as $name=>$column) {
				if(mb_ereg('^_MULTI_', $name)) $has_multi = true;
			}
			if($has_multi) {
				$out .= '<tr class="multi">'."\n";
				foreach($params['columns'] as $name=>$column) {
					$out .= '<td>';
					if(mb_ereg('^_MULTI_', $name)) {
						foreach($column as $action) $out .= $action."<br/>";
					}
					$out .= '</td>';
				}
				$out .= "</tr>\n";
			}
		}
		if($multi_form) {
			// close multiselect form
			$out .= '</form>';
		}

		// PAGINATION
		$numpages = 0;
		if(isset($params['rows'])) {
			$numpages = (int)($params['rows'] / $params['perpage']);
			if($params['rows'] % $params['perpage']) $numpages++;
		}
		if(!$options['nopagination'] && $numpages > 1) {
			$cp = $params['curpage'];
			$pp = $params['perpage'];
			$out .= "<tr class=\"pagination\">\n";
			$out .= '<form name="changepp" method="get" action="'.$grid_url.'">';
			// propagate everything but p_p and p_pp vars
			foreach($_GET as $k=>$v) {
				if($k != 'p_p' && $k != 'p_pp') {
					$out .= $this->depends->form->hidden($k, $v, array('id'=>"{$guid}_2_$k"));
				}
			}
			$out .= '<td colspan="100%">';
			$out .= '<div style="float:right">';
			$page = $this->_pagelink($cp, $cp, $pp, $grid_url);
			// left side
			if($cp > 1) {
				$page = $this->_pagelink($cp-1, $cp, $pp, $grid_url).$page;
				$left = $cp - 2;
				if($left > 0) {
					if($left > 2) $page = '... '.$page;
					if($left > 1) $page = $this->_pagelink(2, $cp, $pp, $grid_url).$page;
					$page = $this->_pagelink(1, $cp, $pp, $grid_url).$page;
				}
			}
			// right side
			if($cp < $numpages) {
				$page = $page.$this->_pagelink($cp+1, $cp, $pp, $grid_url);
				$left = $numpages - $cp - 1;
				if($left > 0) {
					if($left > 2) $page = $page.'... ';
					if($left > 1) $page = $page.$this->_pagelink($numpages-1, $cp, $pp, $grid_url);
					$page = $page.$this->_pagelink($numpages, $cp, $pp, $grid_url);
				}
			}
			$out .= rtrim($page);
			$out .= '</div>';
			$out .= __('Showing').' ';
			if(!isset($pp_opts[$pp])) {
				$pp_opts[] = $pp;
				sort($pp_opts);
			}
			$out .= $this->depends->form->select('p_pp', $pp, array_hash($pp_opts), '', false, array('onChange'=>'document.changepp.submit();'));
			$out .= " ".__('per page').' ('.__('total records').": {$params['rows']})\n";

			$out .= "</td>\n";
			$out .= "</form>\n";
			$out .= "</tr>\n";
		}

		$out .= "</table>\n";
		return $out;
	}

	function _getrowdata($row, $idx)
	{
		if(is_array($idx)) {
			if(count($idx) == 1) {
				return $row[$idx[0]];
			}
			$singleidx = array_shift($idx);
			return $this->_getrowdata($row[$singleidx], $idx);
		}
		return $row[$idx];
	}

	function _pagelink($pagenum, $curpage, $perpage, $url)
	{
		if($pagenum == $curpage) {
			$out = "<span>$pagenum</span> ";
		} else {
			// build a new query with pagination parameters
			$_GET['p_p']  = $pagenum;
			$_GET['p_pp'] = $perpage;
			$qs = array();
			foreach($_GET as $k=>$v) {
				$qs[] = "$k=$v";
			}
			$qs = implode('&', $qs);
			$out = '<a href="'.$url.'?'.$qs.'">'.$pagenum.'</a> ';
		}
		return $out;
	}

	function _getparam($params, $name, $default)
	{
		if(isset($params[$name])) {
			return $params[$name];
		}
		return $default;
	}

}

?>