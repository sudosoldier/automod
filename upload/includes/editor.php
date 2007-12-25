<?php
/** 
*
* @package mods_manager
* @version $Id$
* @copyright (c) 2007 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

// Constant Defines for actions
//define('AFTER',		1);
//define('BEFORE',	2);


/**
* Editor Class
* Runs through file sequential, ie new finds must come after previous finds
* SQL and file copying not handled
* @package mods_manager
* @todo: implement some string checkin, way too much can go wild here
*/
class editor
{
	var $file_contents = '';
	var $start_index = 0;
	var $transfer;

	function editor()
	{
		global $phpbb_root_path, $config;

		if (!class_exists('transfer'))
		{
			global $phpEx;
			include($phpbb_root_path . 'includes/functions_transfer.' . $phpEx);
		}

		// user needs to select ftp or ftp using fsock
		if (!is_writable($phpbb_root_path) && $config['ftp_method'])
		{
			$this->transfer = new $config['ftp_method']($config['ftp_host'], $config['ftp_username'], request_var('password', ''), $config['ftp_root_path'], $config['ftp_port'], $config['ftp_timeout']);
			$this->transfer->open_session();
		}
	}

	/**
	* Make all line endings the same - UNIX
	*/
	function normalize($string)
	{
		$string = str_replace(array("\r\n", "\r"), "\n", $string);
		return $string;
	}

	/**
	* Open a file with IO, for processing
	* 
	* @param string $filename - relative path from phpBB Root to the file to open
	*/
	function open_file($filename)
	{
		global $phpbb_root_path;

		$this->file_contents = $this->normalize(@file($phpbb_root_path . $filename));
		$this->start_index = 0;
	}

	/**
	* Moves files or complete directories
	*
	* @param $from string Can be a file or a directory. Will move either the file or all files within the directory
	* @param $to string Where to move the file(s) to. If not specified then will get moved to the root folder
	*/
	function copy_content($from, $to = '', $strip = '')
	{
		global $phpbb_root_path, $edited_root;

		if (strpos($from, $phpbb_root_path) !== 0)
		{
			$from = $phpbb_root_path . $from;
		}
		
		if (strpos($to, $phpbb_root_path) !== 0)
		{
			$to = $phpbb_root_path . $to;
		}

		$files = array();
		if (is_dir($from))
		{
			// get all of the files within the directory
			$files = find_files($from , '.*', 5);
		}
		else if (is_file($from))
		{
			$files = array($from);
		}

		if (empty($files))
		{
			return false;
		}

		// is the directory writeable? if so, then we don't have to deal with FTP
		if (is_writeable($phpbb_root_path))
		{
			foreach ($files as $file)
			{
				if (!@copy($file, $to))
				{
					return false;
				}
			}
		}
		else
		{
			// ftp
			foreach ($files as $file)
			{
				if (is_dir($to))
				{
					$to_file = str_replace($strip, '', $file);
				}
				else
				{
					$to_file = $to;
				}

				$this->transfer->overwrite_file($file, $to_file);
			}
		}

		return true;
	}

	/**
	* Checks if a find is present
	* Keep in mind partial finds and multi-line finds
	* 
	* @param string $find - string to find
	* @return mixed : array with position information if $find is found; false otherwise
	*/
	function find($find)
	{
		$find_success = 0;

		$find = $this->normalize($find);
		$find_ary = explode("\n", $find);

		$total_lines = sizeof($this->file_contents);
		$find_lines = sizeof($find_ary);

		// we process the file sequentially ... so we keep track of 
		for ($i = $this->start_index; $i < $total_lines; $i++)
		{
			for ($j = 0; $j < $find_lines; $j++)
			{
				// using $this->file_contents[$i + $j] to keep the array pointer where I want it
				// if the first line of the find (index 0) is being looked at, $i + $j = $i.
				// if $j is > 0, we look at the next line of the file being inspected
				// hopefully, this is a decent performer.

				if (!$find_ary[$j])
				{
					// line is blank.  Assume we can find a blank line, and continue on
					$find_success += 1;
					continue;
				}

				if (strpos($this->file_contents[$i + $j], $find_ary[$j]) !== false)
				{
					// we found this part of the line
					$find_success += 1;

					if ($find_success == $find_lines)
					{
						// we found the proper number of lines
						$this->start_index = $i;

						// return our array offsets
						return array(
							'start' => $i,
							'end' => $i + $j,
						);
					}
				}
				else
				{
					// the find failed.  Reset $find_success
					$find_success = false;

					// skip to next iteration of outer loop, that is, skip to the next line
					break;
				}

			}
		}

		// if return has not been previously invoked, the find failed.
		return false;
	}

	/**
	* Find a string within a given line
	*
	* @param string $find Complete find - narrows the scope of the inline search
	* @param string $inline_find - the substring to find
	* @param int $start_offset - the line number where $find starts
	* @param int $end_offset - the line number where $find ends
	* 
	* @return bool success or failure of find
	*/ 
	function inline_find($find, $inline_find, $start_offset = false, $end_offset = false)
	{
		$find = $this->normalize($find);

		if ($start_offset === false || $end_offset === false)
		{
			$offsets = $this->find($find);

			if (!$offsets)
			{
				// the find failed, so no further action can occur.
				return false;
			}

			$start_offset = $offsets['start'];
			$end_offset = $offsets['end'];

			unset($offsets);
		}

		// similar method to find().  Just much more limited scope
		for ($i = $start_offset; $i <= $end_offset; $i++)
		{
			$string_offset = strpos($this->file_contents[$i], $inline_find);
			if ($string_offset !== false)
			{
				// if we find something, return the line number, string offset, and find length
				return array(
					'array_offset'	=> $i,
					'string_offset'	=> $string_offset,
					'find_length'	=> strlen($inline_find),
				);
			}
		}

		return false;
	}


	/**
	* Add a string to the file, BEFORE/AFTER the given find string
	* @param string $find - Complete find - narrows the scope of the inline search
	* @param string $add - The string to be added before or after $find
	* @param string $pos - BEFORE or AFTER
	* @param int $start_offset - First line in the FIND
	* @param int $end_offset - Last line in the FIND
	* 
	* @return bool success or failure of add
	*/
	function add_string($find, $add, $pos, $start_offset = false, $end_offset = false)
	{
		// this seems pretty simple...throughly test
		$add = $this->normalize($add);

		if ($start_offset === false || $end_offset === false)
		{
			$offsets = $this->find($find);

			if (!$offsets)
			{
				// the find failed, so the add cannot occur.
				return false;
			}

			$start_offset = $offsets['start'];
			$end_offset = $offsets['end'];

			unset($offsets);
		}

		// make sure our new lines are correct
		$add = "\n" . $add . "\n";

		if ($pos == 'AFTER')
		{
			$this->file_contents[$end_offset] .= $add;
		}

		if ($pos == 'BEFORE')
		{
			$this->file_contents[$start_offset] = $add . $this->file_contents[$start_offset];
		}

		return true;
	}

	/**
	* Increment (or perform custom operation) on  the given wildcard
	* Support multiple wildcards {%:1}, {%:2} etc...
	* This method is a variation on the inline find and replace methods
	* 
	* @param string $find - Complete find - contains $inline_find
	* @param string $inline_find - contains tokens to be replaced
	* @param string $operation - tokens to do some math
	* @param int $start_offset - First line in the FIND
	* @param int $end_offset - Last line in the FIND
	* 
	* @return bool
	*/
	function inc_string($find, $inline_find, $operation, $start_offset = false, $end_offset = false)
	{
		if ($start_offset === false || $end_offset === false)
		{
			$offsets = $this->find($find);

			if (!$offsets)
			{
				// the find failed, so the add cannot occur.
				return false;
			}

			$start_offset = $offsets['start'];
			$end_offset = $offsets['end'];

			unset($offsets);
		}

		// parse the MODX operator
		preg_match('#{%:(\d+)} ?([+-]) ?(\d*)#', $operation, $action);
		// make sure there is actually a number here
		$action[3] = ($action[3]) ? $action[3] : 1;

		$matches = 0;
		// $start_offset _should_ equal $end_offset, but we allow other cases
		for ($i = $start_offset; $i <= $end_offset; $i++)
		{
			$inline_find = preg_replace('#{%:(\d+)}#', '(\d+)', $inline_find);

			if (preg_match('#' . $inline_find . '#is', $this->file_contents[$i], $find_contents))
			{
				// now we can do some math
				// $find_contents[1] is the original number, $action[2] is the operator
				$new_number = eval('return ' . ((int) $find_contents[1]) . $action[2] . ((int) $action[3]) . ';');

				// now we replace
				$new_contents = str_replace($find_contents[1], $new_number, $find_contents[0]);

				$this->file_contents[$i] = str_replace($find_contents[0], $new_contents, $this->file_contents[$i]);

				$matches += 1;
			}
		}

		if (!$matches)
		{
			return false;
		}

		return true;
	}


	/**
	* Replace a string - replaces the entirety of $find with $replace
	* 
	* @param string $find - Complete find - contains $inline_find
	* @param string $replace - Will replace $find
	* @param int $start_offset - First line in the FIND
	* @param int $end_offset - Last line in the FIND
	* 
	* @return bool
	*/
	function replace_string($find, $replace, $start_offset = false, $end_offset = false)
	{
		$replace = $this->normalize($replace);

		if ($start_offset === false || $end_offset === false)
		{
			$offsets = $this->find($find);

			if (!$offsets)
			{
				return false;
			}

			$start_offset = $offsets['start'];
			$end_offset = $offsets['end'];
			unset($offsets);
		}

		for ($i = $start_offset; $i < $end_offset; $i++)
		{
			unset($this->file_contents[$i]);
		}

		$this->file_contents[$start_offset] = $replace;

		return true;
	}

	/*
	* Replace $inline_find with $inline_replace
	* Arguments are very similar to inline_add, below
	*/
	function inline_replace($find, $inline_find, $inline_replace, $array_offset = false, $string_offset = false, $length = false)
	{
		if ($string_offset === false || $length === false)
		{
			// look for the inline find
			$inline_offsets = $this->inline_find($find, $inline_find);

			if (!$inline_offsets)
			{
				return false;
			}

			$array_offset = $inline_offsets['array_offset'];
			$string_offset = $inline_offsets['string_offset'];
			$length = $inline_offsets['find_length'];
			unset($inline_offsets);
		}

		$this->file_contents[$array_offset] = substr_replace($this->file_contents[$array_offset], $inline_replace, $string_offset, $length);
		
		return true;
	}

	/**
	* Adds a string inline before or after a given find
	* 
	* @param string $find Complete find - narrows the scope of the inline search
	* @param string $inline_find - the string to add before or after
	* @param string $inline_add - added before or after $inline_find
	* @param string $pos - 'BEFORE' or 'AFTER'
	* @param int $array_offset - line number where $inline_find may be found (optional)
	* @param int $string_offset - location within the line where $inline_find begins (optional)
	* @param int $length - essentially strlen($inline_find) (optional)
	* 
	* @return bool success or failure of action
	*/
	function inline_add($find, $inline_find, $inline_add, $pos, $array_offset = false, $string_offset = false, $length = false)
	{
		if ($string_offset === false || $length === false)
		{
			// look for the inline find
			$inline_offsets = $this->inline_find($find, $inline_find);

			if (!$inline_offsets)
			{
				return false;
			}

			$array_offset = $inline_offsets['array_offset'];
			$string_offset = $inline_offsets['string_offset'];
			$length = $inline_offsets['find_length'];
			unset($inline_offsets);
		}

		if ($string_offset + $length > strlen($this->file_contents[$array_offset]))
		{
			// we have an invalid string offset.  rats.
			return false;
		}

		if ($pos == 'AFTER')
		{
			$this->file_contents[$array_offset] = substr_replace($this->file_contents[$array_offset], $inline_add, $string_offset + $length, 0);
		}
		else if ($pos == 'BEFORE')
		{
			$this->file_contents[$array_offset] = substr_replace($this->file_contents[$array_offset], $inline_add, $string_offset, 0);
		}

		return true;
	}

	/**
	* Write & close file
	*/
	function close_file($new_filename)
	{
		global $phpbb_root_path;

		if (!file_exists($phpbb_root_path . dirname($new_filename)))
		{
			recursive_mkdir($phpbb_root_path . dirname($new_filename), 0777);
		}

		if (is_writable($phpbb_root_path . $new_filename) || is_writable($phpbb_root_path . dirname($new_filename)))
		{
			// skip FTP, use local file functions
			$fr = @fopen($phpbb_root_path . $new_filename, 'wb');
			@fwrite($fr, implode('', $this->file_contents));
			@fclose($fr);
			@chmod($phpbb_root_path . $new_filename, 0777);
		}
		else
		{
			return $this->transfer->write_file($new_filename, implode('', $this->file_contents));
		}
	}
}

/**
* List files matching specified PCRE pattern.
*
* @access public
* @param string Relative or absolute path to the directory to be scanned.
* @param string Search pattern (perl compatible regular expression).
* @param integer Number of subdirectory levels to scan (set to 1 to scan only current).
* @param integer This one is used internally to control recursion level.
* @return array List of all files found matching the specified pattern.
*/
function find_files($directory, $pattern, $max_levels = 3, $_current_level = 1)
{
	if ($_current_level <= 1)
	{
		if (strpos($directory, '\\') !== false)
		{
			$directory = str_replace('\\', '/', $directory);
		}
		if (empty($directory))
		{
			$directory = './';
		}
		else if (substr($directory, -1) != '/')
		{
			$directory .= '/';
		}
	}

	$files = array();
	$subdir = array();
	if (is_dir($directory))
	{
		$handle = @opendir($directory);
		while (($file = @readdir($handle)) !== false)
		{
			if ( $file == '.' || $file == '..' )
			{
				continue;
			}

			$fullname = $directory . $file;

			if (is_dir($fullname))
			{
				if ($_current_level < $max_levels)
				{
					$subdir = array_merge($subdir, find_files($fullname . '/', $pattern, $max_levels, $_current_level + 1));
				}
			}
			else
			{
				if (preg_match('/^' . $pattern . '$/i', $file))
				{
					$files[] = $fullname;
				}
			}
		}
		@closedir($handle);
		sort($files);
	}

	return array_merge($files, $subdir);
}

/**
* @author Michal Nazarewicz (from the php manual)
* Creates all non-existant directories in a path
*/
function recursive_mkdir($path, $mode = 0777)
{
	$dirs = explode('/', $path);
	$count = sizeof($dirs);
	$path = '.';
	for ($i = 0; $i < $count; $i++)
	{
		$path .= '/' . $dirs[$i];

		if (!is_dir($path))
		{
			@mkdir($path, $mode);
			@chmod($path, $mode);
			
			if (!is_dir($path))
			{
				return false;
			}
		}
	}
	return true;
}

?>