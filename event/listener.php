<?php
/**
*
* @package searchcustomprofile v 1.0.0
* @copyright (c) 2026 Татьяна5
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace tatiana5\searchcustomprofile\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

//require '/home/u523962/geoip2/vendor/autoload.php';
//use GeoIp2\Database\Reader;

class listener implements EventSubscriberInterface
{
	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\profilefields\manager */
	protected $cp;

	protected $phpbb_root_path;
	protected $php_ext;

	protected $cpf_req;
	protected $s_sort_key;

	public function __construct(\phpbb\auth\auth $auth,
								\phpbb\db\driver\driver_interface $db,
								\phpbb\template\template $template,
								\phpbb\request\request $request,
								\phpbb\user $user,
								\phpbb\profilefields\manager $cp,
								$phpbb_root_path,
								$php_ext)
	{
		$this->auth = $auth;
		$this->db = $db;
		$this->template = $template;
		$this->request = $request;
		$this->user = $user;
		$this->cp = $cp;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->cpf_req = [];
		$this->s_sort_key = '';
	}

	static public function getSubscribedEvents()
	{
		return array(
			'core.memberlist_modify_sql_query_data'			=> 'memberlist_modify_sql_query_data',
			'core.memberlist_modify_sort_pagination_params'	=> 'memberlist_modify_sort_pagination_params',
			'core.memberlist_modify_template_vars'			=> 'memberlist_modify_template_vars',
		);
	}

	public function memberlist_modify_sql_query_data($event)
	{
		$order_by = $event['order_by'];
		$sort_key_sql = $event['sort_key_sql'];
		$sql_from = $event['sql_from'];
		$sql_where = $event['sql_where'];
		$this->s_sort_key = '';

		// this var may need to be set false in some cases (this option allows/disallows search by dates)
		$allow_pf_dates_search = true;

		// if user is not admin, we exclude hidden fields
		$cpfs_sql_where = ($this->auth->acl_get('a_')) ? '' : ' AND pf.field_no_view = 0 AND pf.field_hide = 0';

		// get list of fields to be included in search page
		$sql = 'SELECT pf.field_ident, pf.field_type, pl.lang_name 
			FROM ' . PROFILE_FIELDS_TABLE . ' pf, ' . PROFILE_LANG_TABLE . " pl 
			WHERE pf.field_active = 1$cpfs_sql_where 
				AND pf.field_id = pl.field_id AND pl.lang_id = '" . $this->user->get_iso_lang_id() . "'";
		$result = $this->db->sql_query($sql);
		$this->cpf_req = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$this->cpf_req[$row['field_ident']]['value'] = $this->request->variable('pf_' . $row['field_ident'], '', true);
			$this->cpf_req[$row['field_ident']]['type'] = $row['field_type'];
			$this->cpf_req[$row['field_ident']]['name'] = $row['lang_name'];
		}
		$this->db->sql_freeresult($result);

		// main process of forming of appendixes to searching sql-query in memberlist.php (one iteration = one field)
		foreach ($this->cpf_req as $ident => $ary)
		{
			if (!$this->request->is_set('pf_' . $ident, \phpbb\request\request_interface::REQUEST))
			{
				$this->request->enable_super_globals();
				$_REQUEST['pf_' . $ident] = ''; // here we prevents the assignment of default values by 'get_var()' function
				$this->request->disable_super_globals();
			}
			switch ($ary['type'])
			{
				case FIELD_INT: // "Numbers"
					// remember selected comparison operator
					$this_int_select = $this->request->variable(('pf_' . $ident . '_select'), 'eq');
					$s_find_cp_int[$ident] = '';
					foreach ($find_count as $key => $value)
					{
						$selected = ($this_int_select == $key) ? ' selected="selected"' : '';
						$s_find_cp_int[$ident] .= '<option value="' . $key . '"' . $selected . '>' . $value . '</option>';
					}
					// this field is selected as sorting key? remember this fact
					$selected = ($sort_key == 'pf_' . $ident) ? ' selected="selected"' : '';
					$this->s_sort_key .= '<option value="' . 'pf_' . $ident . '"' . $selected . '>' . $ary['name'] . '</option>';
					// add new element in the array, which is used to insert a sorting key to sql-query
					$sort_key_sql = array_merge($sort_key_sql, array('pf_' . $ident => 'pfd.pf_' . $ident));
					// sql WHERE appendix
					$sql_where .= (is_numeric($ary['value'])) ? ' AND pfd.pf_' . $ident . ' ' . $find_key_match[$this_int_select] . ' ' . (int) $ary['value'] . ' ' : '';
				break;
			
				case FIELD_DATE:
					if ($allow_pf_dates_search)
					{
						$this_date_select = $this->request->variable(('pf_' . $ident . '_select'), 'lt');
						$s_find_cp_date[$ident] = '';
						foreach ($find_time as $key => $value)
						{
							$selected = ($this_date_select == $key) ? ' selected="selected"' : '';
							$s_find_cp_date[$ident] .= '<option value="' . $key . '"' . $selected . '>' . $value . '</option>';
						}
						// because of differences in SQL syntax in different DBMSs we are forced to build different queries
						switch ($dbms)
						{
							case 'firebird':
							case 'postgres':
								$sql_where .= (!$ary['value']) ? '' : ' AND 
									CAST(( 
										TRIM(SUBSTRING(pfd.pf_' . $ident . ' FROM 7)) || \'-\' || 
										TRIM(SUBSTRING(pfd.pf_' . $ident . ' FROM 4 FOR 2)) || \'-\' || 
										TRIM(SUBSTRING(pfd.pf_' . $ident . ' FROM 1 FOR 2))
									) AS DATE) ' . $find_key_match[$this_date_select] . ' 
									CAST(\'' . $ary['value'] . '\' AS DATE)';
			
								$sort_key_sql = array_merge($sort_key_sql, array('pf_' . $ident => 
									'CAST(( 
										TRIM(SUBSTRING(pfd.pf_' . $ident . ' FROM 7)) || \'-\' || 
										TRIM(SUBSTRING(pfd.pf_' . $ident . ' FROM 4 FOR 2)) || \'-\' || 
										TRIM(SUBSTRING(pfd.pf_' . $ident . ' FROM 1 FOR 2))
									) AS DATE)'));
							break;
							
							// MySQL uses "||" for concatenation in Compatibility mode only, so we can not use it and instead use CONCAT_WS()
							case 'mysql':
							case 'mysqli':
								$sql_where .= (!$ary['value']) ? '' : ' AND 
									CAST(CONCAT_WS(\'-\', 
										TRIM(RIGHT(pfd.pf_' . $ident . ', 4)), 
										TRIM(SUBSTRING(pfd.pf_' . $ident . ', 4, 2)), 
										TRIM(LEFT(pfd.pf_' . $ident . ', 2))
									) AS DATE) ' . $find_key_match[$this_date_select] . ' 
									CAST(\'' . $ary['value'] . '\' AS DATE)';

								$sort_key_sql = array_merge($sort_key_sql, array('pf_' . $ident => 
									'CAST(CONCAT_WS(\'-\', 
										TRIM(RIGHT(pfd.pf_' . $ident . ', 4)), 
										TRIM(SUBSTRING(pfd.pf_' . $ident . ', 4, 2)), 
										TRIM(LEFT(pfd.pf_' . $ident . ', 2))
									) AS DATE)'));
							break;

							// MSSQL uses "+" instead of "||", datatype DATETIME instead of DATE, does not support the TRIM(), and has other syntax differences
							case 'mssql':
							case 'mssql_odbc':
								$sql_where .= (!$ary['value']) ? '' : ' AND 
									CAST((
										REPLACE(SUBSTRING(pfd.pf_' . $ident . ', 7, 4), \' \', \'0\') + \'/\' + 
										REPLACE(SUBSTRING(pfd.pf_' . $ident . ', 4, 2), \' \', \'0\') + \'/\' + 
										REPLACE(SUBSTRING(pfd.pf_' . $ident . ', 1, 2), \' \', \'0\')
									) AS DATETIME) ' . $find_key_match[$this_date_select] . ' 
									CAST(\'' . $ary['value'] . '\' AS DATETIME)';
				
								$sort_key_sql = array_merge($sort_key_sql, array('pf_' . $ident => 
									'CAST((
										REPLACE(SUBSTRING(pfd.pf_' . $ident . ', 7, 4), \' \', \'0\') + \'/\' + 
										REPLACE(SUBSTRING(pfd.pf_' . $ident . ', 4, 2), \' \', \'0\') + \'/\' + 
										REPLACE(SUBSTRING(pfd.pf_' . $ident . ', 1, 2), \' \', \'0\')
									) AS DATETIME)'));
							break;

							// Oracle uses SUBSTR instead of SUBSTRING and does not support CAST (instead we must use the special function TO_DATE)
							case 'oracle':
								$sql_where .= (!$ary['value']) ? '' : ' AND 
									TO_DATE(
										REPLACE(SUBSTR(pfd.pf_' . $ident . ', 7, 4), \' \', \'0\') || \'-\' || 
										REPLACE(SUBSTR(pfd.pf_' . $ident . ', 4, 2), \' \', \'0\') || \'-\' || 
										REPLACE(SUBSTR(pfd.pf_' . $ident . ', 1, 2), \' \', \'0\'), 
									\'YYYY-MM-DD\') ' . $find_key_match[$this_date_select] . ' 
									TO_DATE(\'' . $ary['value'] . '\', \'YYYY-MM-DD\')';
				
								$sort_key_sql = array_merge($sort_key_sql, array('pf_' . $ident => 
									'TO_DATE(
										REPLACE(SUBSTR(pfd.pf_' . $ident . ', 7, 4), \' \', \'0\') || \'-\' || 
										REPLACE(SUBSTR(pfd.pf_' . $ident . ', 4, 2), \' \', \'0\') || \'-\' || 
										REPLACE(SUBSTR(pfd.pf_' . $ident . ', 1, 2), \' \', \'0\'), 
									\'YYYY-MM-DD\')'));
							break;
				
							// SQLite supports few string functions, but we can create own functions very easy
							case 'sqlite':
								if (!function_exists('cpf_date_to_int'))
								{
									function cpf_date_to_int($var)
									{
										return (int)str_replace(' ', '0', (substr($var, 6) . substr($var, 3, 2) . substr($var, 0, 2)));
									}
									sqlite_create_function($this->db->db_connect_id, 'cpf_date_to_int', 'cpf_date_to_int', 1);
								}
								$sql_where .= (!$ary['value']) ? '' : ' AND 
									cpf_date_to_int(pfd.pf_' . $ident . ') ' . $find_key_match[$this_date_select] . 
									(int)str_pad(str_replace('-', '', $ary['value']), 8, '0');
				
								$sort_key_sql = array_merge($sort_key_sql, array(
									'pf_' . $ident => 'cpf_date_to_int(pfd.pf_' . $ident . ')'
								));
							break;
						}
						$selected = ($sort_key == 'pf_' . $ident) ? ' selected="selected"' : '';
						$this->s_sort_key .= '<option value="' . 'pf_' . $ident . '"' . $selected . '>' . $ary['name'] . '</option>';
					}
				break;
				
				case FIELD_STRING: // "Single text field"
					$selected = ($sort_key == 'pf_' . $ident) ? ' selected="selected"' : '';
					$this->s_sort_key .= '<option value="' . 'pf_' . $ident . '"' . $selected . '>' . $ary['name'] . '</option>';
					$sort_key_sql = array_merge($sort_key_sql, array('pf_' . $ident => 'pfd.pf_' . $ident));
				// no break here
			
				default:
					$sql_where .= ($ary['value']) ? ' AND pfd.pf_' . $ident . ' ' . $this->db->sql_like_expression(str_replace('*', $this->db->get_any_char(), $ary['value'])) . ' ' : '';
				break;
			}
		}

		if (substr_count($sql_where, 'pfd.') || substr_count($order_by, 'pfd.'))
		{
			$sql_from = ', ' . PROFILE_FIELDS_DATA_TABLE . ' pfd' . $sql_from;
			$sql_where .= ' AND u.user_id = pfd.user_id ';
		}

		$event['sort_key_sql'] = $sort_key_sql;
		$event['sql_from'] = $sql_from;
		$event['sql_where'] = $sql_where;
	}

	public function memberlist_modify_sort_pagination_params($event)
	{
		$params = $event['params'];
		$sort_params = $event['sort_params'];

		// here we prepare parameters for pagination and sorting URLs
		if (sizeof($this->cpf_req))
		{
			foreach ($this->cpf_req as $ident => $ary)
			{
				if ($ary['value'] !== '')
				{
					$param = urlencode('pf_' . $ident) . '=' . urlencode($ary['value']);
					$params[] = $param;
					$sort_params[] = $param;
					if ($ary['type'] == FIELD_DATE)
					{
						$param = urlencode('pf_' . $ident . '_select') . '=' . urlencode(request_var(('pf_' . $ident . '_select'), 'lt'));
						$params[] = $param;
						$sort_params[] = $param;
					}
					elseif ($ary['type'] == FIELD_INT)
					{
						$param = urlencode('pf_' . $ident . '_select') . '=' . urlencode(request_var(('pf_' . $ident . '_select'), 'eq'));
						$params[] = $param;
						$sort_params[] = $param;
					}
				}
			}
		}

		$event['params'] = $params;
		$event['sort_params'] = $sort_params;
	}

	public function memberlist_modify_template_vars($event)
	{
		$template_vars = $event['template_vars'];

		$template_vars['S_MODE_SELECT'] .= $this->s_sort_key;
		$this->template->append_var('S_SORT_OPTIONS', $this->s_sort_key);

		$this->cp->generate_profile_fields('profile', $this->user->get_iso_lang_id());

		$event['template_vars'] = $template_vars;
	}
}
