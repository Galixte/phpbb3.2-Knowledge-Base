<?php
/**
 *
 * Knowledge base. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Sheer
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace sheer\knowledgebase\acp;

class search_module
{
	var $u_action;
	var $state;
	var $search;
	var $max_post_id;
	var $batch_size = 100;

	function main($id, $mode)
	{
		// For some this may be of help...
		@ini_set('memory_limit', '128M');
		$this->settings($id, $mode);
		$this->index($id, $mode);
	}

	function settings($id, $mode)
	{
		global $db, $user, $auth, $template, $cache, $phpbb_log, $request;
		global $config, $phpbb_root_path, $phpbb_admin_path, $phpEx, $table_prefix;

		$this->kb_log_table = $table_prefix . 'kb_log';

		$phpbb_log->set_log_table($this->kb_log_table);

		$submit = (isset($_POST['submit'])) ? true : false;

		$search_types = $this->get_search_types();

		$settings = array(
			'kb_search'				=> 'bool',
			'kb_per_page_search'	=> 'int',
		);

		$search = null;
		$error = false;
		$search_options = '';

		if (!isset($config['kb_search_type']))
		{
			// Search type by default
			$type = 'kb_fulltext_native';
		}

		foreach ($search_types as $type)
		{
			if ($this->init_search($type, $search, $error))
			{
				continue;
			}

			$name = $search->get_name();

			$selected = ($config['kb_search_type'] === $type) ? ' selected="selected"' : '';
			$identifier = substr($type, strrpos($type, '\\') + 1);
			$search_options .= "<option value=\"$type\"$selected data-toggle-setting=\"#search_{$identifier}_settings\">$name</option>";
		}

		$cfg_array = (isset($_REQUEST['config'])) ? $request->variable('config', array('' => ''), true) : array();
		$updated = $request->variable('updated', false);

		foreach ($settings as $config_name => $var_type)
		{
			if (!isset($cfg_array[$config_name]))
			{
				continue;
			}

			// e.g. integer:4:12 (min 4, max 12)
			$var_type = explode(':', $var_type);

			$config_value = $cfg_array[$config_name];
			settype($config_value, $var_type[0]);

			if (isset($var_type[1]))
			{
				$config_value = max($var_type[1], $config_value);
			}

			if (isset($var_type[2]))
			{
				$config_value = min($var_type[2], $config_value);
			}

			// only change config if anything was actually changed
			if ($submit && ($config[$config_name] != $config_value))
			{
				$config->set($config_name, $config_value);
				$updated = true;
			}
		}

		if ($submit)
		{
			$extra_message = '';
			if ($updated)
			{
				$phpbb_log->add('admin', $user->data['user_id'], $user->data['user_ip'], 'LOG_KB_CONFIG_SEARCH', time());
			}

			if (isset($cfg_array['kb_search_type']) && in_array($cfg_array['kb_search_type'], $search_types, true) && ($cfg_array['kb_search_type'] != $config['kb_search_type']))
			{
				$search = null;
				$error = false;

				if (!$this->init_search($cfg_array['kb_search_type'], $search, $error))
				{
					if (confirm_box(true))
					{
						if (!method_exists($search, 'init') || !($error = $search->init()))
						{
							$config->set('kb_search_type', $cfg_array['kb_search_type']);

							if (!$updated)
							{
								$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_CONFIG_SEARCH');
							}
							$extra_message = '<br />' . $user->lang['SWITCHED_SEARCH_BACKEND'];
						}
						else
						{
							trigger_error($error . adm_back_link($this->u_action), E_USER_WARNING);
						}
					}
					else
					{
						confirm_box(false, $user->lang['CONFIRM_SEARCH_BACKEND'], build_hidden_fields(array(
							'i'			=> $id,
							'mode'		=> $mode,
							'submit'	=> true,
							'updated'	=> $updated,
							'config'	=> array('kb_search_type' => $cfg_array['kb_search_type']),
						)));
					}
				}
				else
				{
					trigger_error($error . adm_back_link($this->u_action), E_USER_WARNING);
				}
			}

			$search = null;
			$error = false;

			if (!$this->init_search($config['kb_search_type'], $search, $error))
			{
				if ($updated)
				{
					if (method_exists($search, 'config_updated'))
					{
						if ($search->config_updated())
						{
							trigger_error($error . adm_back_link($this->u_action), E_USER_WARNING);
						}
					}
				}
			}
			else
			{
				trigger_error($error . adm_back_link($this->u_action), E_USER_WARNING);
			}

			trigger_error($user->lang['CONFIG_UPDATED'] . $extra_message . adm_back_link($this->u_action));
		}
		unset($cfg_array);

		$this->tpl_name = 'acp_search_body';
		$this->page_title = 'ACP_SEARCH_SETTINGS';

		$template->assign_vars(array(
			'S_SEARCH_TYPES'		=> $search_options,
			'S_YES_SEARCH'			=> (bool) $config['kb_search'],
			'S_SETTINGS'			=> true,
			'PER_PAGE_KB_SEARCH'	=> ($config['kb_per_page_search']) ? $config['kb_per_page_search'] : 10,
			'U_ACTION'				=> $this->u_action)
		);
	}

	function index($id, $mode)
	{
		global $db, $user, $auth, $template, $cache, $phpbb_log, $request;
		global $config, $phpbb_root_path, $phpbb_admin_path, $phpEx;

		$phpbb_log->set_log_table($this->kb_log_table);
		$this->kb_articles_table = $table_prefix . 'kb_articles';

		$action = $request->variable('action', '');
		$this->state = explode(',', $config['search_indexing_state']);

		if (isset($_POST['cancel']))
		{
			$action = '';
			$this->state = array();
			$this->save_state();
		}

		if ($action)
		{
			switch ($action)
			{
				case 'progress_bar':
					$type = $request->variable('type', '');
					$this->display_progress_bar($type);
				break;
				case 'delete':
					$this->state[1] = 'delete';
				break;
				case 'create':
					$this->state[1] = 'create';
				break;

				default:
					trigger_error('NO_ACTION', E_USER_ERROR);
				break;
			}

			if (empty($this->state[0]))
			{
				$this->state[0] = $request->variable('search_type', '');
			}

			$this->search = null;
			$error = false;
			if ($this->init_search($this->state[0], $this->search, $error))
			{
				trigger_error($error . adm_back_link($this->u_action), E_USER_WARNING);
			}
			$name = $this->search->get_name();
			$action = &$this->state[1];
			$this->max_post_id = $this->get_max_article_id();

			$post_counter = (isset($this->state[2])) ? $this->state[2] : 0;
			$this->state[2] = &$post_counter;
			$this->save_state();

			switch ($action)
			{
				case 'delete':
					if (method_exists($this->search, 'delete_index'))
					{
						// pass a reference to myself so the $search object can make use of save_state() and attributes
						if ($error = $this->search->delete_index($this, append_sid("{$phpbb_admin_path}index.$phpEx", "i=$id&mode=$mode&action=delete", false)))
						{
							$this->state = array('');
							$this->save_state();
							trigger_error($error . adm_back_link($this->u_action) . $this->close_popup_js(), E_USER_WARNING);
						}
					}
					else
					{
						$starttime = explode(' ', microtime());
						$starttime = $starttime[1] + $starttime[0];
						$row_count = 0;
						while (still_on_time() && $post_counter <= $this->max_post_id)
						{
							$sql = 'SELECT article_id, author_id, article_category_id
								FROM ' . $this->kb_articles_table . '
								WHERE article_id >= ' . (int) ($post_counter + 1) . '
									AND article_id <= ' . (int) ($post_counter + $this->batch_size);
							$result = $db->sql_query($sql);

							$ids = $posters = $forum_ids = array();
							while ($row = $db->sql_fetchrow($result))
							{
								$ids[] = $row['article_id'];
								$authors[] = $row['author_id'];
								$category_ids[] = $row['article_category_id'];
							}
							$db->sql_freeresult($result);
							$row_count += sizeof($ids);

							if (sizeof($ids))
							{
								$this->search->index_remove($ids, $authors, $category_ids);
							}

							$post_counter += $this->batch_size;
						}
						// save the current state
						$this->save_state();

						if ($post_counter <= $this->max_post_id)
						{
							$mtime = explode(' ', microtime());
							$totaltime = $mtime[0] + $mtime[1] - $starttime;
							$rows_per_second = $row_count / $totaltime;
							meta_refresh(1, append_sid($this->u_action . '&amp;action=delete&amp;skip_rows=' . $post_counter));
							trigger_error($user->lang('SEARCH_INDEX_DELETE_REDIRECT', (int) $row_count, $post_counter, $rows_per_second));
						}
					}

					$this->search->tidy();

					$this->state = array('');
					$this->save_state();

					$phpbb_log->add('admin', $user->data['user_id'], $user->data['user_ip'], 'LOG_SEARCH_INDEX_REMOVED', time(), array($name));
					trigger_error($user->lang['SEARCH_INDEX_REMOVED'] . adm_back_link($this->u_action) . $this->close_popup_js());
				break;

				case 'create':
					$search_type	= $request->variable('search_type', '');
					if (method_exists($this->search, 'create_index'))
					{
						// pass a reference to acp_search so the $search object can make use of save_state() and attributes
						if ($error = $this->search->create_index($this, append_sid("{$phpbb_admin_path}index.$phpEx", "i=$id&mode=$mode&action=create", false)))
						{
							$this->state = array('');
							$this->save_state();
							trigger_error($error . adm_back_link($this->u_action) . $this->close_popup_js(), E_USER_WARNING);
						}
					}
					else
					{
						$starttime = explode(' ', microtime());
						$starttime = $starttime[1] + $starttime[0];
						$row_count = 0;

						$max = $this->max_post_id;
						while (still_on_time() && $post_counter <= $this->max_post_id)
						{
							$sql = 'SELECT article_id, article_title, article_body, author_id, article_description
								FROM ' . $this->kb_articles_table . '
								WHERE article_id >= ' . (int) ($post_counter + 1) . '
									AND article_id <= ' . (int) ($post_counter + $this->batch_size);
							$result = $db->sql_query($sql);

							$buffer = $db->sql_buffer_nested_transactions();

							if ($buffer)
							{
								$rows = $db->sql_fetchrowset($result);
								$rows[] = false; // indicate end of array for while loop below

								$db->sql_freeresult($result);
							}

							$i = 0;
							while ($row = ($buffer ? $rows[$i++] : $db->sql_fetchrow($result)))
							{
								$this->search->index('add', $row['article_id'], $row['article_body'], $row['article_title'], $row['article_description'], $row['author_id']);
								$row_count++;
							}
							if (!$buffer)
							{
								$db->sql_freeresult($result);
							}

							$post_counter += $this->batch_size;
						}
						// save the current state
						$this->save_state();

						// pretend the number of posts was as big as the number of ids we indexed so far
						// just an estimation as it includes deleted posts
						$num_posts = $config['num_posts'];
						$config['num_posts'] = min($config['num_posts'], $post_counter);
						$this->search->tidy();
						$config['num_posts'] = $num_posts;

						if ($post_counter <= $this->max_post_id)
						{
							$mtime = explode(' ', microtime());
							$totaltime = $mtime[0] + $mtime[1] - $starttime;
							$rows_per_second = $row_count / $totaltime;
							meta_refresh(1, append_sid($this->u_action . '&amp;action=create&amp;skip_rows=' . $post_counter));
							trigger_error($user->lang('SEARCH_INDEX_CREATE_REDIRECT', (int) $row_count, $post_counter) . $user->lang('SEARCH_INDEX_CREATE_REDIRECT_RATE', $rows_per_second));
						}
					}

					$this->search->tidy();

					$this->state = array('');
					$this->save_state();

					$phpbb_log->add('admin', $user->data['user_id'], $user->data['user_ip'], 'LOG_SEARCH_INDEX_CREATED', time(), array($name));
					trigger_error($user->lang['SEARCH_INDEX_CREATED'] . adm_back_link($this->u_action) . $this->close_popup_js());
				break;
			}
		}

		$search_types = $this->get_search_types();

		$search = null;
		$error = false;
		$search_options = '';
		foreach ($search_types as $type)
		{
			if ($this->init_search($type, $search, $error) || !method_exists($search, 'index_created'))
			{
				continue;
			}

			$name = $search->get_name();

			$data = array();
			if (method_exists($search, 'index_stats'))
			{
				$data = $search->index_stats();
			}

			$statistics = array();
			foreach ($data as $statistic => $value)
			{
				$n = sizeof($statistics);
				if ($n && sizeof($statistics[$n - 1]) < 3)
				{
					$statistics[$n - 1] += array('statistic_2' => $statistic, 'value_2' => $value);
				}
				else
				{
					$statistics[] = array('statistic_1' => $statistic, 'value_1' => $value);
				}
			}

			$template->assign_block_vars('backend', array(
				'L_NAME'			=> $name,
				'NAME'				=> $type,

				'S_ACTIVE'			=> ($type == $config['kb_search_type']) ? true : false,
				'S_HIDDEN_FIELDS'	=> build_hidden_fields(array('search_type' => $type)),
				'S_INDEXED'			=> (bool) $search->index_created(),
				'S_STATS'			=> (bool) sizeof($statistics))
			);

			foreach ($statistics as $statistic)
			{
				$template->assign_block_vars('backend.data', array(
					'STATISTIC_1'	=> $statistic['statistic_1'],
					'VALUE_1'		=> $statistic['value_1'],
					'STATISTIC_2'	=> (isset($statistic['statistic_2'])) ? $statistic['statistic_2'] : '',
					'VALUE_2'		=> (isset($statistic['value_2'])) ? $statistic['value_2'] : '')
				);
			}
		}
		unset($search);
		unset($error);
		unset($statistics);
		unset($data);

		$this->tpl_name = 'acp_search_body';
		$this->page_title = 'ACP_SEARCH_INDEX';

		$template->assign_vars(array(
			'S_INDEX'				=> true,
			'U_ACTION'				=> $this->u_action,
			'U_PROGRESS_BAR'		=> append_sid("{$phpbb_admin_path}index.$phpEx", "i=$id&amp;mode=$mode&amp;action=progress_bar"),
			'UA_PROGRESS_BAR'		=> str_replace('\\', '\\\\', append_sid("{$phpbb_admin_path}index.$phpEx", "i=$id&amp;mode=$mode&amp;action=progress_bar")),
		));

		if (isset($this->state[1]))
		{
			$template->assign_vars(array(
				'S_CONTINUE_INDEXING'	=> $this->state[1],
				'U_CONTINUE_INDEXING'	=> $this->u_action . '&amp;action=' . $this->state[1],
				'L_CONTINUE'			=> ($this->state[1] == 'create') ? $user->lang['CONTINUE_INDEXING'] : $user->lang['CONTINUE_DELETING_INDEX'],
				'L_CONTINUE_EXPLAIN'	=> ($this->state[1] == 'create') ? $user->lang['CONTINUE_INDEXING_EXPLAIN'] : $user->lang['CONTINUE_DELETING_INDEX_EXPLAIN'])
			);
		}
	}

	function display_progress_bar($type)
	{
		global $template, $user;

		$l_type = ($type == 'create') ? 'INDEXING_IN_PROGRESS' : 'DELETING_INDEX_IN_PROGRESS';

		adm_page_header($user->lang[$l_type]);

		$template->set_filenames(array(
			'body'	=> 'progress_bar.html')
		);

		$template->assign_vars(array(
			'L_PROGRESS'			=> $user->lang[$l_type],
			'L_PROGRESS_EXPLAIN'	=> $user->lang[$l_type . '_EXPLAIN'])
		);

		adm_page_footer();
	}

	function close_popup_js()
	{
		return "<script type=\"text/javascript\">\n" .
			"// <![CDATA[\n" .
			"	close_waitscreen = 1;\n" .
			"// ]]>\n" .
			"</script>\n";
	}

	function get_search_types()
	{
		global $phpbb_root_path, $phpEx;

		$search_types = array();

		$dp = @opendir($phpbb_root_path . 'ext/sheer/knowledgebase/search');

		if ($dp)
		{
			while (($file = readdir($dp)) !== false)
			{
				if ((preg_match('#\.' . $phpEx . '$#', $file)) && ($file != "kb_base.$phpEx"))
				{
					$search_types[] = preg_replace('#^(.*?)\.' . $phpEx . '$#', '\1', $file);
				}
			}
			closedir($dp);

			sort($search_types);
		}

		return $search_types;
	}

	function get_max_article_id()
	{
		global $db, $table_prefix;

		$this->kb_articles_table = $table_prefix . 'kb_articles';

		$sql = 'SELECT MAX(article_id) as max_article_id
			FROM '. $this->kb_articles_table;
		$result = $db->sql_query($sql);
		$max_article_id = (int) $db->sql_fetchfield('max_article_id');
		$db->sql_freeresult($result);

		return $max_article_id;
	}

	function save_state($state = false)
	{
		global $config;

		if ($state)
		{
			$this->state = $state;
		}

		ksort($this->state);

		$config->set('search_indexing_state', implode(',', $this->state), $cache = true);
	}

	/**
	* Initialises a search backend object
	*
	* @return false if no error occurred else an error message
	*/
	function init_search($type, &$search, &$error)
	{
		global $phpbb_root_path, $phpEx, $user, $auth, $config, $db;

		if (!preg_match('#^\w+$#', $type) || !file_exists("{$phpbb_root_path}ext/sheer/knowledgebase/search/$type.$phpEx"))
		{
			$error = $user->lang['NO_SUCH_SEARCH_MODULE'];
			return $error;
		}

		include_once("{$phpbb_root_path}ext/sheer/knowledgebase/search/$type.$phpEx");
		$type = '\sheer\knowledgebase\search\\' . $type . '';
		if (!class_exists($type))
		{
			$error = $user->lang['NO_SUCH_SEARCH_MODULE'];
			return $error;
		}

		$error = false;
		$search = new $type($error, $phpbb_root_path, $phpEx, $auth, $config, $db, $user);

		return $error;
	}
}
