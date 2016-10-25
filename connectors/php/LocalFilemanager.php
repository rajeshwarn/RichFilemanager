<?php
/**
 *	Filemanager PHP class
 *
 *	Class for the filemanager connector
 *
 *	@license	MIT License
 *	@author		Riaan Los <mail (at) riaanlos (dot) nl>
 *	@author		Simon Georget <simon (at) linea21 (dot) com>
 *	@author		Pavel Solomienko <https://github.com/servocoder/>
 *	@copyright	Authors
 */

require_once('BaseFilemanager.php');
require_once('LocalUploadHandler.php');

class LocalFilemanager extends BaseFilemanager
{
	protected $allowed_actions = [];
	protected $doc_root;
	protected $path_to_files;
	protected $dynamic_fileroot;

	public function __construct($config = [])
    {
		parent::__construct($config);

		$fileRoot = $this->config['options']['fileRoot'];
		if ($fileRoot !== false) {
			// takes $_SERVER['DOCUMENT_ROOT'] as files root; "fileRoot" is a suffix
			if($this->config['options']['serverRoot'] === true) {
				$this->doc_root = $_SERVER['DOCUMENT_ROOT'];
				$this->path_to_files = $_SERVER['DOCUMENT_ROOT'] . '/' . $fileRoot;
			}
			// takes "fileRoot" as files root; "fileRoot" is a full server path
			else {
				$this->doc_root = $fileRoot;
				$this->path_to_files = $fileRoot;
			}
		} else {
			$this->doc_root = $_SERVER['DOCUMENT_ROOT'];
			$this->path_to_files = $this->fm_path . '/userfiles';
		}

		// normalize slashes in paths
        $this->doc_root = $this->cleanPath($this->doc_root);
		$this->path_to_files = $this->cleanPath($this->path_to_files);
        $this->dynamic_fileroot = $this->subtractPath($this->path_to_files, $this->doc_root);

		Log::info('$this->fm_path: "' . $this->fm_path . '"');
		Log::info('$this->path_to_files: "' . $this->path_to_files . '"');
		Log::info('$this->doc_root: "' . $this->doc_root . '"');
		Log::info('$this->dynamic_fileroot: "' . $this->dynamic_fileroot . '"');

		$this->setPermissions();
		$this->loadLanguageFile();
	}

    /**
     * Allow Filemanager to be used with dynamic folders
     * @param string $path - i.e '/var/www/'
     * @param bool $mkdir
     */
	public function setFileRoot($path, $mkdir = false)
    {
		if($this->config['options']['serverRoot'] === true) {
			$this->dynamic_fileroot = $path;
			$this->path_to_files = $this->cleanPath($this->doc_root . '/' . $path);
		} else {
			$this->path_to_files = $this->cleanPath($path);
		}

		Log::info('Overwritten with setFileRoot() method:');
		Log::info('$this->path_to_files: "' . $this->path_to_files . '"');
		Log::info('$this->dynamic_fileroot: "' . $this->dynamic_fileroot . '"');

		if($mkdir && !file_exists($this->path_to_files)) {
			mkdir($this->path_to_files, 0755, true);
			Log::info('creating "' . $this->path_to_files . '" folder through mkdir()');
		}
	}

	/**
	 * @param array $settings
	 * @return LocalUploadHandler
	 */
	public function initUploader($settings = [])
	{
		$data = [
			'images_only' => $this->config['upload']['imagesOnly'] || (isset($this->refParams['type']) && strtolower($this->refParams['type'])=='images'),
		] + $settings;

		if(isset($data['upload_dir'])) {
			$data['thumbnails_dir'] = rtrim($this->get_thumbnail_path($data['upload_dir']), '/');
		}

		return new LocalUploadHandler([
			'fm' => [
				'instance' => $this,
				'data' => $data,
			],
		]);
	}

	/**
	 * @inheritdoc
	 */
	public function getfolder()
    {
		$files_list = [];
        $response_data = [];
        $target_path = $this->get['path'];
		$target_fullpath = $this->getFullPath($target_path, true);
		Log::info('opening folder "' . $target_fullpath . '"');

		if(!is_dir($target_fullpath)) {
			$this->error(sprintf($this->lang('DIRECTORY_NOT_EXIST'), $target_path));
		}

		// check if file is readable
		if(!$this->has_system_permission($target_fullpath, ['r'])) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		if(!$handle = @opendir($target_fullpath)) {
			$this->error(sprintf($this->lang('UNABLE_TO_OPEN_DIRECTORY'), $target_path));
		} else {
			while (false !== ($file = readdir($handle))) {
				if($file != "." && $file != "..") {
					array_push($files_list, $file);
				}
			}
			closedir($handle);

			foreach($files_list as $file) {
				$file_path = $target_path . $file;
                if(is_dir($target_fullpath . $file)) {
                    $file_path .= '/';
                }

                $item = $this->get_file_info($file_path);
                if($this->filter_output($item)) {
                    $response_data[] = $item;
                }
			}
		}

		return $response_data;
	}

	/**
	 * @inheritdoc
	 */
	public function getfile()
	{
        $target_path = $this->get['path'];
        $target_fullpath = $this->getFullPath($target_path, true);
		Log::info('opening file "' . $target_fullpath . '"');

		// check if file is readable
		if(!$this->has_system_permission($target_fullpath, ['r'])) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

        $item = $this->get_file_info($target_path);
        if(!$this->filter_output($item)) {
            $this->error(sprintf($this->lang('NOT_ALLOWED')));
        }

        return $item;
	}

	/**
	 * @inheritdoc
	 */
	public function upload()
	{
	    $target_path = $this->post['path'];
        $target_fullpath = $this->getFullPath($target_path, true);
		Log::info('uploading to "' . $target_fullpath . '"');

		// check if file is writable
		if(!$this->has_system_permission($target_fullpath, ['w'])) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		if(!$this->has_permission('upload')) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

        $content = $this->initUploader([
			'upload_dir' => $target_fullpath,
		])->post(false);

        $response_data = [];
        $files = isset($content[$this->config['upload']['paramName']]) ?
            $content[$this->config['upload']['paramName']] : null;
        // there is only one file in the array as long as "singleFileUploads" is set to "true"
        if ($files && is_array($files) && is_object($files[0])) {
            $file = $files[0];
            if(isset($file->error)) {
                $this->error($file->error);
            } else {
                $relative_path = $this->cleanPath('/' . $target_path . '/' . $file->name);
                $item = $this->get_file_info($relative_path);
                $response_data[] = $item;
            }
        } else {
            $this->error(sprintf($this->lang('ERROR_UPLOADING_FILE')));
        }

        return $response_data;
	}

	/**
	 * @inheritdoc
	 */
	public function addfolder()
	{
        $target_path = $this->get['path'];
        $target_fullpath = $this->getFullPath($target_path, true);

        $target_name = $this->get['name'];
		$folder_name = $this->normalizeString($target_name);
        $folder_name = rtrim($folder_name, '/') . '/';
		$new_fullpath = $target_fullpath . $folder_name;
		Log::info('adding folder "' . $new_fullpath . '"');

		if(is_dir($new_fullpath)) {
			$this->error(sprintf($this->lang('DIRECTORY_ALREADY_EXISTS'), $target_name));
		}

		if(!mkdir($new_fullpath, 0755)) {
			$this->error(sprintf($this->lang('UNABLE_TO_CREATE_DIRECTORY'), $target_name));
		}

        $relative_path = $this->cleanPath('/' . $target_path . $folder_name);
        return $this->get_file_info($relative_path);
	}

	/**
	 * @inheritdoc
	 */
	public function rename()
	{
		$suffix = '';

		if(substr($this->get['old'], -1, 1) == '/') {
			$this->get['old'] = substr($this->get['old'], 0, (strlen($this->get['old'])-1));
			$suffix = '/';
		}
		$tmp = explode('/', $this->get['old']);
		$filename = $tmp[(sizeof($tmp)-1)];

		$newPath = substr($this->get['old'], 0, strripos($this->get['old'], '/' . $filename));
		$newName = $this->normalizeString($this->get['new'], ['.', '-']);

		$old_file = $this->getFullPath($this->get['old'], true) . $suffix;
		$new_file = $this->getFullPath($newPath, true) . '/' . $newName . $suffix;
		Log::info('renaming "' . $old_file . '" to "' . $new_file . '"');

		if(!$this->has_permission('rename')) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		// forbid to change path during rename
		if(strrpos($this->get['new'], '/') !== false) {
			$this->error(sprintf($this->lang('FORBIDDEN_CHAR_SLASH')));
		}

		// check if file is writable
		if(!$this->has_system_permission($old_file, ['w'])) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		// check if not requesting main FM userfiles folder
		if($this->is_root_folder($old_file)) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		// for file only - we check if the new given extension is allowed regarding the security Policy settings
		if(is_file($old_file) && $this->config['security']['allowChangeExtensions'] && !$this->is_allowed_file_type($new_file)) {
			$this->error(sprintf($this->lang('INVALID_FILE_TYPE')));
		}

		if(file_exists($new_file)) {
			if($suffix == '/' && is_dir($new_file)) {
				$this->error(sprintf($this->lang('DIRECTORY_ALREADY_EXISTS'), $newName));
			}
			if($suffix == '' && is_file($new_file)) {
				$this->error(sprintf($this->lang('FILE_ALREADY_EXISTS'), $newName));
			}
		}

		if(!rename($old_file, $new_file)) {
			if(is_dir($old_file)) {
				$this->error(sprintf($this->lang('ERROR_RENAMING_DIRECTORY'), $filename, $newName));
			} else {
				$this->error(sprintf($this->lang('ERROR_RENAMING_FILE'), $filename, $newName));
			}
		} else {
			Log::info('renamed "' . $old_file . '" to "' . $new_file . '"');

			// for image only - rename thumbnail if original image was successfully renamed
			if(!is_dir($new_file)) {
				$new_thumbnail = $this->get_thumbnail_path($new_file);
				$old_thumbnail = $this->get_thumbnail_path($old_file);
				if(file_exists($old_thumbnail)) {
					rename($old_thumbnail, $new_thumbnail);
				}
			}
		}

		$relative_path = $this->cleanPath('/' . $newPath . '/' . $newName . $suffix);
        return $this->get_file_info($relative_path);
	}

	/**
	 * @inheritdoc
	 */
	public function move()
	{
        $source_path = $this->get['old'];
        $suffix = (substr($source_path, -1, 1) == '/') ? '/' : '';
		$tmp = explode('/', trim($source_path, '/'));
		$filename = array_pop($tmp); // file name or new dir name

        $target_path = $this->get['new'] . '/';
        $target_path = $this->expandPath($target_path, true);

		$source_fullpath = $this->getFullPath($source_path, true);
        $target_fullpath = $this->getFullPath($target_path, true);
		$new_fullpath = $target_fullpath . $filename . $suffix;
		Log::info('moving "' . $source_fullpath . '" to "' . $new_fullpath . '"');

		// check if file is writable
		if(!$this->has_system_permission($source_fullpath, ['w']) || !$this->has_system_permission($target_fullpath, ['w'])) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		// check if not requesting main FM userfiles folder
		if($this->is_root_folder($source_fullpath)) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		if(!$this->has_permission('move')) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		// check if file already exists
		if (file_exists($new_fullpath)) {
			if(is_dir($new_fullpath)) {
				$this->error(sprintf($this->lang('DIRECTORY_ALREADY_EXISTS'), rtrim($this->get['new'], '/') . '/' . $filename));
			} else {
				$this->error(sprintf($this->lang('FILE_ALREADY_EXISTS'), rtrim($this->get['new'], '/') . '/' . $filename));
			}
		}

		// create dir if not exists
		if (!file_exists($target_fullpath)) {
			if(!mkdir($target_fullpath, 0755, true)) {
				$this->error(sprintf($this->lang('UNABLE_TO_CREATE_DIRECTORY'), $target_fullpath));
			}
		}

		// should be retrieved before rename operation
		$old_thumbnail = $this->get_thumbnail_path($source_fullpath);

		// move file or folder
		if(!rename($source_fullpath, $new_fullpath)) {
			if(is_dir($source_fullpath)) {
				$this->error(sprintf($this->lang('ERROR_RENAMING_DIRECTORY'), $filename, $this->get['new']));
			} else {
				$this->error(sprintf($this->lang('ERROR_RENAMING_FILE'), $filename, $this->get['new']));
			}
		} else {
			Log::info('moved "' . $source_fullpath . '" to "' . $new_fullpath . '"');

			// move thumbnail file or thumbnails folder if exists
			if(file_exists($old_thumbnail)) {
				$new_thumbnail = $this->get_thumbnail_path($new_fullpath);
				// delete old thumbnail(s) if destination folder does not exist
				if(file_exists(dirname($new_thumbnail))) {
					rename($old_thumbnail, $new_thumbnail);
				} else {
					is_dir($old_thumbnail) ? $this->unlinkRecursive($old_thumbnail) : unlink($old_thumbnail);
				}
			}
		}

        $relative_path = $this->cleanPath('/' . $target_path . $filename . $suffix);
        return $this->get_file_info($relative_path);
	}

	/**
	 * @inheritdoc
	 */
	public function replace()
	{
        $source_path = $this->post['path'];
        $source_fullpath = $this->getFullPath($source_path);
        Log::info('replacing file "' . $source_fullpath . '"');

        $target_path = dirname($source_path) . '/';
        $target_fullpath = $this->getFullPath($target_path, true);
        Log::info('replacing target path "' . $target_fullpath . '"');

		if(!$this->has_permission('replace') || !$this->has_permission('upload')) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		if(is_dir($source_fullpath)) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		// we check the given file has the same extension as the old one
		if(strtolower(pathinfo($_FILES[$this->config['upload']['paramName']]['name'], PATHINFO_EXTENSION)) != strtolower(pathinfo($source_path, PATHINFO_EXTENSION))) {
			$this->error(sprintf($this->lang('ERROR_REPLACING_FILE') . ' ' . pathinfo($source_path, PATHINFO_EXTENSION)));
		}

		// check if file is writable
		if(!$this->has_system_permission($source_fullpath, ['w'])) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		// check if targey path is writable
		if(!$this->has_system_permission($target_fullpath, ['w'])) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

        $content = $this->initUploader([
            'upload_dir' => $target_fullpath,
        ])->post(false);

        $response_data = [];
        $files = isset($content[$this->config['upload']['paramName']]) ?
            $content[$this->config['upload']['paramName']] : null;
        // there is only one file in the array as long as "singleFileUploads" is set to "true"
        if ($files && is_array($files) && is_object($files[0])) {
            $file = $files[0];
            if(isset($file->error)) {
                $this->error($file->error);
            } else {
                $replacement_fullpath = $target_fullpath . $file->name;
                Log::info('replacing "' . $source_fullpath . '" with "' . $replacement_fullpath . '"');

                rename($replacement_fullpath, $source_fullpath);

                $new_thumbnail = $this->get_thumbnail_path($replacement_fullpath);
                $old_thumbnail = $this->get_thumbnail_path($source_fullpath);
                if(file_exists($new_thumbnail)) {
                    rename($new_thumbnail, $old_thumbnail);
                }

                $relative_path = $this->cleanPath('/' . $source_path);
                $item = $this->get_file_info($relative_path);
                $response_data[] = $item;
            }
        } else {
            $this->error(sprintf($this->lang('ERROR_UPLOADING_FILE')));
        }

        return $response_data;
	}

	/**
	 * @inheritdoc
	 */
	public function editfile()
    {
        $target_path = $this->get['path'];
		$target_fullpath = $this->getFullPath($target_path, true);
		Log::info('opening "' . $target_fullpath . '"');

		// check if file is writable
		if(!$this->has_system_permission($target_fullpath, ['w'])) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		if(!$this->has_permission('edit') || !$this->is_editable($target_fullpath)) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		$content = file_get_contents($target_fullpath);
		$content = htmlspecialchars($content);

		if($content === false) {
			$this->error(sprintf($this->lang('ERROR_OPENING_FILE')));
		}

        $item = $this->get_file_info($target_path);
        $item['attributes']['content'] = $content;

        return $item;
	}

	/**
	 * @inheritdoc
	 */
	public function savefile()
    {
        $target_path = $this->post['path'];
		$target_fullpath = $this->getFullPath($target_path, true);
		Log::info('saving "' . $target_fullpath . '"');

		if(!$this->has_permission('edit') || !$this->is_editable($target_fullpath)) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		if(!$this->has_system_permission($target_fullpath, ['w'])) {
			$this->error(sprintf($this->lang('ERROR_WRITING_PERM')));
		}

		$content = htmlspecialchars_decode($this->post['content']);
		$result = file_put_contents($target_fullpath, $content, LOCK_EX);

		if(!is_numeric($result)) {
			$this->error(sprintf($this->lang('ERROR_SAVING_FILE')));
		}

		Log::info('saved "' . $target_fullpath . '"');

        return $this->get_file_info($target_path);
	}

	/**
	 * Seekable stream: http://stackoverflow.com/a/23046071/1789808
	 * @inheritdoc
	 */
	public function readfile()
	{
        $target_path = $this->get['path'];
		$target_fullpath = $this->getFullPath($target_path, true);
		$filesize = filesize($target_fullpath);
		$length = $filesize;
		$offset = 0;

		if(isset($_SERVER['HTTP_RANGE'])) {
			if(!preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches)) {
				header('HTTP/1.1 416 Requested Range Not Satisfiable');
				header('Content-Range: bytes */' . $filesize);
				exit;
			}

			$offset = intval($matches[1]);

			if(isset($matches[2])) {
				$end = intval($matches[2]);
				if($offset > $end) {
					header('HTTP/1.1 416 Requested Range Not Satisfiable');
					header('Content-Range: bytes */' . $filesize);
					exit;
				}
				$length = $end - $offset;
			} else {
				$length = $filesize - $offset;
			}

			$bytes_start = $offset;
			$bytes_end = $offset + $length - 1;

			header('HTTP/1.1 206 Partial Content');
			// A full-length file will indeed be "bytes 0-x/x+1", think of 0-indexed array counts
			header('Content-Range: bytes ' . $bytes_start . '-' . $bytes_end . '/' . $filesize);
			// While playing media by direct link (not via FM) FireFox and IE doesn't allow seeking (rewind) it in player
			// This header can fix this behavior if to put it out of this condition, but it breaks PDF preview
			header('Accept-Ranges: bytes');
		}

		header('Content-Type: ' . mime_content_type($target_fullpath));
		header("Content-Transfer-Encoding: binary");
		header("Content-Length: " . $length);
		header('Content-Disposition: inline; filename="' . basename($target_fullpath) . '"');

		$fp = fopen($target_fullpath, 'r');
		fseek($fp, $offset);
		$position = 0;

		while($position < $length) {
			$chunk = min($length - $position, 1024 * 8);

			echo fread($fp, $chunk);
			flush();
			ob_flush();

			$position += $chunk;
		}
		exit;
	}

	/**
	 * @inheritdoc
	 */
	public function getimage($thumbnail)
	{
        $target_path = $this->get['path'];
		$target_fullpath = $this->getFullPath($target_path, true);
		Log::info('loading image "' . $target_fullpath . '"');

		// if $thumbnail is set to true we return the thumbnail
		if($thumbnail === true && $this->config['images']['thumbnail']['enabled'] === true) {
			// get thumbnail (and create it if needed)
			$returned_path = $this->get_thumbnail($target_fullpath);
		} else {
			$returned_path = $target_fullpath;
		}

		header("Content-type: image/octet-stream");
		header("Content-Transfer-Encoding: binary");
		header("Content-length: " . $this->get_real_filesize($returned_path));
		header('Content-Disposition: inline; filename="' . basename($returned_path) . '"');

		readfile($returned_path);
		exit();
	}

	/**
	 * @inheritdoc
	 */
	public function delete()
	{
        $target_path = $this->get['path'];
		$target_fullpath = $this->getFullPath($target_path, true);
		$thumbnail_path = $this->get_thumbnail_path($target_fullpath);
		Log::info('deleting "' . $target_fullpath . '"');

		if(!$this->has_permission('delete')) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		// check if file is writable
		if(!$this->has_system_permission($target_fullpath, ['w'])) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

		// check if not requesting main FM userfiles folder
		if($this->is_root_folder($target_fullpath)) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

        $item = $this->get_file_info($target_path);
        if(!$this->filter_output($item)) {
            $this->error(sprintf($this->lang('NOT_ALLOWED')));
        }

		if(is_dir($target_fullpath)) {
			$this->unlinkRecursive($target_fullpath);
			Log::info('deleted "' . $target_fullpath . '"');

			// delete thumbnails if exists
			if(file_exists($thumbnail_path)) {
				$this->unlinkRecursive($thumbnail_path);
			}
		} else {
			unlink($target_fullpath);
			Log::info('deleted "' . $target_fullpath . '"');

			// delete thumbnails if exists
			if(file_exists($thumbnail_path)) {
				unlink($thumbnail_path);
			}
		}

		return $item;
	}

	/**
	 * @inheritdoc
	 */
	public function download()
    {
        $target_path = $this->get['path'];
		$target_fullpath = $this->getFullPath($target_path, true);
		Log::info('downloading "' . $target_fullpath . '"');

		if(!$this->has_permission('download')) {
			$this->error(sprintf($this->lang('NOT_ALLOWED')));
		}

		// check if file is writable
		if(!$this->has_system_permission($target_fullpath, ['w'])) {
			$this->error(sprintf($this->lang('NOT_ALLOWED_SYSTEM')));
		}

        $item = $this->get_file_info($target_path);
        if(!$this->filter_output($item)) {
            $this->error(sprintf($this->lang('NOT_ALLOWED')));
        }

		if($item["type"] === self::TYPE_FOLDER) {
			// check if permission is granted
			if($this->config['security']['allowFolderDownload'] == false ) {
				$this->error(sprintf($this->lang('NOT_ALLOWED')));
			}

			// check if not requesting main FM userfiles folder
			if($this->is_root_folder($target_fullpath)) {
				$this->error(sprintf($this->lang('NOT_ALLOWED')));
			}
		}

		if($this->isAjaxRequest()) {
            return $item;
        } else {
            if($item["type"] === self::TYPE_FOLDER) {
                $destination_path = sys_get_temp_dir().'/fm_'.uniqid().'.zip';

                // if Zip archive is created
                if($this->zipFile($target_fullpath, $destination_path, true)) {
                    $target_fullpath = $destination_path;
                } else {
                    $this->error($this->lang('ERROR_CREATING_ZIP'));
                }
            }

            header('Content-Description: File Transfer');
            header('Content-Type: ' . mime_content_type($target_fullpath));
            header('Content-Disposition: attachment; filename=' . basename($target_fullpath));
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . $this->get_real_filesize($target_fullpath));
            // handle caching
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

            readfile($target_fullpath);
            Log::info('downloaded "' . $target_fullpath . '"');
            exit;
        }
	}

	/**
	 * @inheritdoc
	 */
	public function summarize()
	{
        $attributes = [
            'size' => 0,
            'files' => 0,
            'folders' => 0,
            'sizeLimit' => $this->config['options']['fileRootSizeLimit'],
        ];

		$path = rtrim($this->path_to_files, '/') . '/';
		try {
			$this->getDirSummary($path, $attributes);
		} catch (Exception $e) {
			$this->error(sprintf($this->lang('ERROR_SERVER')));
		}

        return [
            'id' => '/',
            'type' => 'summary',
            'attributes' => $attributes,
        ];
	}

	/**
	 * Creates a zip file from source to destination
	 * @param  	string $source Source path for zip
	 * @param  	string $destination Destination path for zip
	 * @param  	boolean $includeFolder If true includes the source folder also
	 * @return 	boolean
	 * @link	http://stackoverflow.com/questions/17584869/zip-main-folder-with-sub-folder-inside
	 */
	public function zipFile($source, $destination, $includeFolder = false)
	{
		if (!extension_loaded('zip') || !file_exists($source)) {
			return false;
		}

		$zip = new ZipArchive();
		if (!$zip->open($destination, ZIPARCHIVE::CREATE)) {
			return false;
		}

		$source = str_replace('\\', '/', realpath($source));
		$folder = $includeFolder ? basename($source) . '/' : '';

		if (is_dir($source) === true) {
			// add file to prevent empty archive error on download
			$zip->addFromString('fm.txt', "This archive has been generated by Rich Filemanager : https://github.com/servocoder/RichFilemanager/");

			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ($files as $file) {
				$file = str_replace('\\', '/', realpath($file));

				if (is_dir($file) === true) {
					$path = str_replace($source . '/', '', $file . '/');
					$zip->addEmptyDir($folder . $path);
				} else if (is_file($file) === true) {
					$path = str_replace($source . '/', '', $file);
					$zip->addFromString($folder . $path, file_get_contents($file));
				}
			}
		} else if (is_file($source) === true) {
			$zip->addFromString($folder . basename($source), file_get_contents($source));
		}

		return $zip->close();
	}

    protected function setPermissions()
    {
		$this->allowed_actions = $this->config['options']['capabilities'];
		if($this->config['edit']['enabled']) {
			array_push($this->allowed_actions, 'edit');
		}
	}

    /**
     * Check if system permission is granted
     * @param string $filepath
     * @param array $permissions
     * @return bool
     */
    protected function has_system_permission($filepath, $permissions)
    {
		if(in_array('r', $permissions)) {
			if(!is_readable($filepath)) {
				Log::info('Not readable path "' . $filepath . '"');
				return false;
			};
		}
		if(in_array('w', $permissions)) {
			if(!is_writable($filepath)) {
				Log::info('Not writable path "' . $filepath . '"');
				return false;
			}
		}
		return true;
	}

	/**
	 * Create array with file properties
	 * @param string $relative_path
	 * @return array
	 */
	protected function get_file_info($relative_path)
    {
		$fullpath = $this->getFullPath($relative_path);
		$pathInfo = pathinfo($fullpath);
		$filemtime = filemtime($fullpath);

		// check if file is writable and readable
		$protected = $this->has_system_permission($fullpath, ['w', 'r']) ? 0 : 1;

		if(is_dir($fullpath)) {
            $model = $this->folderModel;
		} else {
            $model = $this->fileModel;
            $model['attributes']['size'] = $this->get_real_filesize($fullpath);
            $model['attributes']['extension'] = isset($pathInfo['extension']) ? $pathInfo['extension'] : '';

			if($this->is_image_file($fullpath)) {
				if($model['attributes']['size']) {
					list($width, $height, $type, $attr) = getimagesize($fullpath);
				} else {
					list($width, $height) = [0, 0];
				}

                $model['attributes']['width'] = $width;
                $model['attributes']['height'] = $height;
			}
		}

        $model['id'] = $relative_path;
        $model['attributes']['name'] = $pathInfo['basename'];
        $model['attributes']['path'] = $this->getDynamicPath($fullpath);
        $model['attributes']['protected'] = $protected;
        $model['attributes']['timestamp'] = $filemtime;
        $model['attributes']['modified'] = $this->formatDate($filemtime);
        //$model['attributes']['created'] = $model['attributes']['modified']; // PHP cannot get create timestamp
        return $model;
	}

    /**
     * Return full path to file
     * @param string $path
     * @param bool $verify If file or folder exists and valid
     * @return mixed|string
     */
	protected function getFullPath($path, $verify = false)
    {
		$full_path = $this->cleanPath($this->path_to_files . '/' . $path);

		if($verify === true) {
			if(!file_exists($full_path) || !$this->is_valid_path($full_path)) {
				$langKey = is_dir($full_path) ? 'DIRECTORY_NOT_EXIST' : 'FILE_DOES_NOT_EXIST';
				$this->error(sprintf($this->lang($langKey), $path));
			}
		}
		return $full_path;
	}

	/**
	 * Returns path without document root
	 * @param string $fullPath
	 * @return mixed
	 */
	protected function getDynamicPath($fullPath)
	{
	    // empty string makes FM to use connector path for preview instead of absolute path
        if(empty($this->dynamic_fileroot)) {
            return '';
        }
	    $path = $this->dynamic_fileroot . '/' . $this->getRelativePath($fullPath);
        return $this->cleanPath($path);
	}

	/**
	 * Returns path without "path_to_files"
	 * @param string $fullPath
     * @return mixed
	 */
    protected function getRelativePath($fullPath)
    {
		return $this->subtractPath($fullPath, $this->path_to_files);
	}

	/**
	 * Subtracts subpath from the fullpath
	 * @param string $fullPath
	 * @param string $subPath
     * @return string
	 */
    protected function subtractPath($fullPath, $subPath)
    {
		$position = strrpos($fullPath, $subPath);
        if($position === 0) {
            $path = substr($fullPath, strlen($subPath));
            return $path ? $this->cleanPath('/' . $path) : '';
        }
        return '';
	}

    /**
     * Check whether path is valid by comparing paths
     * @param string $path
     * @return bool
     */
	protected function is_valid_path($path)
    {
        $rp_substr = substr(realpath($path) . DIRECTORY_SEPARATOR, 0, strlen(realpath($this->path_to_files))) . DIRECTORY_SEPARATOR;
        $rp_files = realpath($this->path_to_files) . DIRECTORY_SEPARATOR;

		// handle better symlinks & network path - issue #448
		$pattern = ['/\\\\+/', '/\/+/'];
		$replacement = ['\\\\', '/'];
		$rp_substr = preg_replace($pattern, $replacement, $rp_substr);
		$rp_files = preg_replace($pattern, $replacement, $rp_files);
		$match = ($rp_substr === $rp_files);

		if(!$match) {
			Log::info('Invalid path "' . $path . '"');
			Log::info('real path: "' . $rp_substr . '"');
			Log::info('path to files: "' . $rp_files . '"');
		}
		return $match;
	}

    /**
     * Delete folder recursive
     * @param string $dir
     * @param bool $deleteRootToo
     */
    protected function unlinkRecursive($dir, $deleteRootToo = true)
    {
		if(!$dh = @opendir($dir)) {
			return;
		}
		while (false !== ($obj = readdir($dh))) {
			if($obj == '.' || $obj == '..') {
				continue;
			}

			if (!@unlink($dir . '/' . $obj)) {
				$this->unlinkRecursive($dir.'/'.$obj, true);
			}
		}
		closedir($dh);

		if ($deleteRootToo) {
			@rmdir($dir);
		}

		return;
	}

	/**
	 * Clean path string to remove multiple slashes, etc.
	 * @param string $string
	 * @return $string
	 */
	public function cleanPath($string)
	{
	    // replace backslashes (windows separators)
        $string = str_replace("\\", "/", $string);
		// remove multiple slashes
        $string = preg_replace('#/+#', '/', $string);
        return $string;
	}

	/**
	 * Clean string to retrieve correct file/folder name.
	 * @param string $string
	 * @param array $allowed
	 * @return array|mixed
	 */
	public function normalizeString($string, $allowed = [])
	{
		$allow = '';
		if(!empty($allowed)) {
			foreach ($allowed as $value) {
				$allow .= "\\$value";
			}
		}

		if($this->config['security']['normalizeFilename'] === true) {
			// Remove path information and dots around the filename, to prevent uploading
			// into different directories or replacing hidden system files.
			// Also remove control characters and spaces (\x00..\x20) around the filename:
			$string = trim(basename(stripslashes($string)), ".\x00..\x20");

			// Replace chars which are not related to any language
			$replacements = [' '=>'_', '\''=>'_', '/'=>'', '\\'=>''];
			$string = strtr($string, $replacements);
		}

		if($this->config['options']['charsLatinOnly'] === true) {
			// transliterate if extension is loaded
			if(extension_loaded('intl') === true && function_exists('transliterator_transliterate')) {
				$options = 'Any-Latin; Latin-ASCII; NFD; [:Nonspacing Mark:] Remove; NFC;';
				$string = transliterator_transliterate($options, $string);
			}
			// clean up all non-latin chars
			$string = preg_replace("/[^{$allow}_a-zA-Z0-9]/u", '', $string);
		}

		// remove double underscore
		$string = preg_replace('/[_]+/', '_', $string);

		return $string;
	}

	/**
	 * Checking if permission is set or not for a given action
	 * @param string $action
	 * @return boolean
	 */
    protected function has_permission($action)
    {
		return in_array($action, $this->allowed_actions);
	}

    /**
     * Load using "langCode" var passed into URL if present and if exists
     * Otherwise use default configuration var.
     */
	protected function loadLanguageFile()
    {
        $lang = $this->config['options']['culture'];
        if(isset($this->refParams['langCode'])) {
            $lang = $this->refParams['langCode'];
        }
        $this->language = $this->retrieve_json_file("/scripts/languages/{$lang}.json");
	}

    /**
     * Check whether the folder is root
     * @param string $path
     * @return bool
     */
	protected function is_root_folder($path)
    {
		return rtrim($this->path_to_files, '/') == rtrim($path, '/');
	}

    /**
     * Check whether the file could be edited regarding configuration setup
     * @param string $file
     * @return bool
     */
	protected function is_editable($file)
    {
		$path_parts = pathinfo($file);
		$types = array_map('strtolower', $this->config['edit']['editExt']);

		return in_array($path_parts['extension'], $types);
	}

	/**
	 * Remove "../" from path
	 * @param string $path Path to be converted
	 * @param bool $clean If dir names should be cleaned
	 * @return string or false in case of error (as exception are not used here)
	 */
	public function expandPath($path, $clean = false)
	{
		$todo = explode('/', $path);
		$fullPath = [];

		foreach ($todo as $dir) {
			if ($dir == '..') {
				$element = array_pop($fullPath);
				if (is_null($element)) {
					return false;
				}
			} else {
				if ($clean) {
					$dir = $this->normalizeString($dir);
				}
				array_push($fullPath, $dir);
			}
		}
		return implode('/', $fullPath);
	}

	/**
	 * Creates URL to asset based on it relative path
	 * @param $path
	 * @return string
	 */
	protected function getFmUrl($path)
	{
		if(isset($this->config['fmUrl']) && !empty($this->config['fmUrl']) && strpos($path, '/') !== 0) {
			$url = $this->config['fmUrl'] . '/' . $path;
			return $this->cleanPath($url);
		}
		return $path;
	}

	/**
	 * Format timestamp string
	 * @param string $timestamp
	 * @return string
	 */
	protected function formatDate($timestamp)
	{
		return date($this->config['options']['dateFormat'], $timestamp);
	}

	/**
	 * Returns summary info for specified folder
	 * @param $dir $path
	 * @param array $result
	 * @return array
	 */
	public function getDirSummary($dir, &$result = ['size' => 0, 'files' => 0, 'folders' => 0])
	{
		// suppress permission denied and other errors
		$files = @scandir($dir);
		if($files === false) {
			return $result;
		}

		foreach($files as $value) {
			if($value == "." || $value == "..") {
				continue;
			}
			$path = $dir . $value;
			$subPath = substr($path, strlen($dir));

			if (is_dir($path)) {
				if (!in_array($subPath, $this->config['exclude']['unallowed_dirs']) &&
					!preg_match($this->config['exclude']['unallowed_dirs_REGEXP'], $subPath)) {
					$result['folders']++;
					$this->getDirSummary($path . '/', $result);
				}
			} else if (
				!in_array($subPath, $this->config['exclude']['unallowed_files']) &&
				!preg_match($this->config['exclude']['unallowed_files_REGEXP'], $subPath)) {
				$result['files']++;
				$result['size'] += filesize($path);
			}
		}

		return $result;
	}

	/**
	 * Calculates total size of all files
	 * @return mixed
	 */
	public function getRootTotalSize()
	{
		$path = rtrim($this->path_to_files, '/') . '/';
		$result = $this->getDirSummary($path);
		return $result['size'];
	}

	/**
	 * Return Thumbnail path from given path, works for both file and dir path
	 * @param string $path
	 * @return string
	 */
	protected function get_thumbnail_path($path)
	{
		$relative_path = $this->getRelativePath($path);
		$thumbnail_path = $this->path_to_files . '/' . $this->config['images']['thumbnail']['dir'] . '/';

		if(is_dir($path)) {
			$thumbnail_fullpath = $thumbnail_path . $relative_path . '/';
		} else {
			$thumbnail_fullpath = $thumbnail_path . dirname($relative_path) . '/' . basename($path);
		}

		return $this->cleanPath($thumbnail_fullpath);
	}

	/**
	 * Returns path to image file thumbnail, creates thumbnail if doesn't exist
	 * @param string $path
	 * @return string
	 */
	protected function get_thumbnail($path)
	{
		$thumbnail_fullpath = $this->get_thumbnail_path($path);

		// generate thumbnail if it doesn't exist or caching is disabled
		if(!file_exists($thumbnail_fullpath) || $this->config['images']['thumbnail']['cache'] === false) {
			$this->createThumbnail($path, $thumbnail_fullpath);
		}

		return $thumbnail_fullpath;
	}

	/**
	 * Creates thumbnail from the original image
	 * @param $imagePath
	 * @param $thumbnailPath
	 */
	protected function createThumbnail($imagePath, $thumbnailPath)
	{
		if($this->config['images']['thumbnail']['enabled'] === true) {
			Log::info('generating thumbnail "' . $thumbnailPath . '"');

			// create folder if it does not exist
			if(!file_exists(dirname($thumbnailPath))) {
				mkdir(dirname($thumbnailPath), 0755, true);
			}

			$this->initUploader([
				'upload_dir' => dirname($imagePath) . '/',
			])->create_thumbnail_image($imagePath);
		}
	}

}