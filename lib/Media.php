<?php
	
	/**
	 * This file is part of the PHP Video Toolkit v2 package.
	 *
	 * @author Oliver Lillie (aka buggedcom) <publicmail@buggedcom.co.uk>
	 * @license Dual licensed under MIT and GPLv2
	 * @copyright Copyright (c) 2008 Oliver Lillie <http://www.buggedcom.co.uk>
	 * @package PHPVideoToolkit V2
	 * @version 2.0.0.a
	 * @uses ffmpeg http://ffmpeg.sourceforge.net/
	 */
	 
	namespace PHPVideoToolkit;

	/**
	 * This class provides generic data parsing for the output from FFmpeg from specific
	 * media files. Parts of the code borrow heavily from Jorrit Schippers version of 
	 * PHPVideoToolkit v 0.1.9.
	 *
	 * @access public
	 * @author Oliver Lillie
	 * @author Jorrit Schippers
	 * @package default
	 */
	class Media extends MediaParser
	{
	    /**
	     * Overwrite constants used in save, saveNonBlocking and getExecutionCommand
	     */
	    const OVERWRITE_FAIL = -1;
	    const OVERWRITE_EXISTING = -2;
	    const OVERWRITE_UNIQUE = -3;

		protected $_media_file_path;
		protected $_media_input_format;
		
		private $_blocking;

		public $last_error_message;
		public $error_messages;
		
		protected $_layers;
		protected $_prepends;
		protected $_appends;
		protected $_extract_segment;
		protected $_split_options;
		
		protected $_metadata;
		protected $_supported_meta_data;
		
		private $_output_path;
		private $_processing_path;
		
		private $_post_process_callbacks;
		
		protected $_process;

		public function __construct($media_file_path, Format $input_format=null, $ffmpeg_path, $temp_directory)
		{
			parent::__construct($ffmpeg_path, $temp_directory);
			
//			validate the file exists and is readable.
			if($media_file_path !== null)
			{
				$real_file_path = realpath($media_file_path);
				
				if($real_file_path === false || is_file($real_file_path) === false)
				{
					throw new Exception('The file "'.$media_file_path.'" cannot be found in \\PHPVideoToolkit\\Media::__construct.');
				}
				else if(is_readable($real_file_path) === false)
				{
					throw new Exception('The file "'.$media_file_path.'" is not readable in \\PHPVideoToolkit\\Media::__construct.');
				}
			
				$this->_media_file_path = $real_file_path;
			}
			
			$this->setInputFormat($input_format);
			
			$this->last_error_message = null;
			$this->error_messages = array();
			
			$this->_layers = array();
			$this->_prepends = array();
			$this->_appends = array();
			$this->_extract_segment = array();
			$this->_split_options = array();
			$this->_metadata = array();
			
			$this->_output_path = null;
			$this->_processing_path = null;
			$this->_blocking = true;
			
			// @see http://multimedia.cx/eggs/supplying-ffmpeg-with-metadata/
			// @see http://wiki.multimedia.cx/index.php?title=FFmpeg_Metadata
			$this->_supported_meta_data = array(
				'title', 
				'date', 
				'author', 
				'album_artist', 
				'album', 
				'grouping', 
				'composer', 
				'year', 
				'track', 
				'comment', 
				'genre', 
				'copyright', 
				'description', 
				'synopsis', 
				'show', 
				'episode_id', 
				'network', 
				'lyrics', 
			);
			
			$this->_post_process_callbacks = array(
				'toolkit' => array(),
				'user' => array(),
			);
			
			$this->_process = new FfmpegProcessProgressable($ffmpeg_path, $temp_directory);
		}
		
		public function setInputFormat(Format $input_format=null)
		{
//			create a default input format if none is set.
			if($input_format === null)
			{
				$this->_media_input_format = $this->getDefaultFormat('input');
			}
			else
			{
				$this->_media_input_format = $input_format;
			}
		}
		
		public function getInputFormat()
		{
			return $this->_media_input_format;
		}

		/**
		 * Returns the default (empty) input format for the type of media object this class is.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param string $type Either input for an input format or output for an output format.
		 * @return Format
		 */
		public function getDefaultFormat($type)
		{
			return $this->_getDefaultFormat($type, 'Format');
		}
		
		/**
		 * Returns a format class set to the specific output/input type.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param string $type Either input for an input format or output for an output format.
		 * @param string $class_name The class name of the Format instance to return.
		 * @package Format Returns an instance of a Format object or child class.
		 */
		protected function _getDefaultFormat($type, $class_name)
		{
			if(in_array($type, array('input', 'output')) === false)
			{
				throw new Exception('Unrecognised format type "'.$type.'".');
			}
			
			$class_name = '\\PHPVideoToolkit\\'.$class_name;
			if(class_exists($class_name) === false)
			{
				throw new Exception('Class does not exist, "'.$class_name.'".');
			}
			else if($class_name !== '\\PHPVideoToolkit\\Format' && is_subclass_of($class_name, '\\PHPVideoToolkit\\Format') === false)
			{
				throw new Exception('The class "'.$class_name.'" is not a subclass of \\PHPVideoToolkit\\Format.');
			}
			
			return new $class_name($type, $this->_program_path, $this->_temp_directory);
		}
		
		/**
		 * Returns the real path of the media asset.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @return void
		 */
		public function getMediaPath()
		{
			return $this->_media_file_path;
		}
		
		/**
		 * Sets global meta data on the media. That being said "global" does not mean it sets the
		 * meta data on the media streams, rather just the meta data on the container.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param string $key 
		 * @param string $value 
		 * @param boolean $force 
		 * @return void
		 */
		public function setMetaData($key, $value=null, $force=false)
		{
			if(is_array($key) === true)
			{
				foreach ($key as $k => $v)
				{
					$this->setMetaData($k, $v);
				}
				return $this;
			}
			
			if(empty($key) === true)
			{
				throw new Exception('Empty metadata key. Metadata keys must be at least one character long.');
			}
			
//			check that meta key is supported by this format.
			$key = strtolower($key);
			if($force === false && in_array($key, $this->_supported_meta_data) === false)
			{
				throw new Exception('The metadata key "'.$key.'" cannot be set as it is not honoured by the muxer.');
			}
			
			$this->_metadata[$key] = $value;
			return $this;
		}
		
		/**
		 * Removes all the global meta data. That being said "global" does not mean it removes the
		 * meta data from the media streams, rather just the meta data on the container.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @return void
		 */
		public function purgeMetaData()
		{
			$this->_metadata = array();

			$meta = $this->readGlobalMetaData();
			if(empty($meta) === false)
			{
				foreach ($meta as $key => $ignored)
				{
					$this->setMetaData($key, '', true);
				}
			}
			
			return $this;
		}
		
		/**
		 * Appends a media item to the current Media object by joining the specified media
		 * object after this file, ie new Media + this Media.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param Media $media 
		 * @param Format $input_format 
		 * @return Media
		 */
		public function append(Media $media, Format $input_format=null)
		{
			return $this;
		}
		
		/**
		 * Prepends a media item to the current Media object by joining the specified media
		 * object before this file, ie this Media + new Media.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param Media $media 
		 * @param Format $input_format 
		 * @return Media
		 */
		public function prepend(Media $media, Format $input_format=null)
		{
			return $this;
		}
		
		/**
		 * Adds a media object ontop of the current
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param Media $media 
		 * @param Format $input_format 
		 * @param Timecode $timecode_start 
		 * @param Timecode $timecode_end 
		 * @param mixed $layer_index If null then the layered media object is added to the top of the list.
		 *	If an index is given then it is inserted at that index. If a layer already exists at the specified index
		 *	then the layers above and including the index are all moved up one.
		 * @return Media
		 */
		public function layer(Media $media, Format $input_format=null, Timecode $timecode_start=null, Timecode $timecode_end=null, $layer_index=null)
		{
			return $this;
		}
		
		/**
		 * Extracts a segment of the media object.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param Timecode $from_timecode 
		 * @param Timecode $to_timecode 
		 * @param boolean $accurate If true then accuracy is prefered over performance.
		 * @return Media
		 */
		public function extractSegment(Timecode $from_timecode=null, Timecode $to_timecode=null, $accurate=false)
		{
//			check that a segment extract has not already been set
			if(empty($this->_extract_segment) === false)
			{
				throw new Exception('Extract segment options have already been set. You cannot call extractSegment more than once on a '.get_class($this).' object.');
			}
			
//			check that a split has already been set as if it has we can't extract a segment
//			however we can extract a segment, then split it.
			if(empty($this->_split_options) === false)
			{
				throw new Exception('You cannot extract a segment once '.get_class($this).'::split has been called. You can however extract a segment, the call '.get_class($this).'::split.');
			}
			
//			check the timecodes against the duration
			$duration = $this->readDuration();
			if($from_timecode !== null && $duration->total_seconds < $from_timecode->total_seconds)
			{
				throw new Exception('The duration of the media is less than the starting timecode specified.');
			}
			else if($to_timecode !== null && $duration->total_seconds < $to_timecode->total_seconds)
			{
				throw new Exception('The duration of the media is less than the end timecode specified.');
			}
			
			$this->_extract_segment = array(
				'preseek' => null,
				'seek' => null,
				'length' => null,
			);
			
//			if the from timecode is greater than say 15 seconds, we will stream seek to 15 seconds before the 
//			required extracted segment before the input to improve extract performance.
// 			See http://ffmpeg.org/trac/ffmpeg/wiki/Seeking%20with%20FFmpeg
			$pre_input_stream_seek_offset = 0;
			$pre_input_stream_seek_adjustment = 15;
			if($from_timecode !== null)
			{
				if($accurate === false)
				{
					if($from_timecode->total_seconds > $pre_input_stream_seek_adjustment)
					{
						$pre_input_stream_seek_offset = $from_timecode->total_seconds-$pre_input_stream_seek_adjustment;
				
						$seek_timecode = new Timecode($pre_input_stream_seek_offset, Timecode::INPUT_FORMAT_SECONDS);
						$this->_extract_segment['preseek'] = $seek_timecode;
					}

//					if we have a pre input stream seek then input video is then offset by that ammount
					if($pre_input_stream_seek_offset > 0)
					{
						$from_timecode = new Timecode($pre_input_stream_seek_adjustment, Timecode::INPUT_FORMAT_SECONDS);
					}
				}

//				then seek the exact position after input
				$begin_position = $from_timecode->getTimecode('%hh:%mm:%ss.%ms', false);
				$this->_extract_segment['seek'] = $from_timecode;
			}
			else
			{
				$from_timecode = new Timecode(0, Timecode::INPUT_FORMAT_SECONDS);
			}

//			then add the number of seconds to export for if there is an end timecode.
			if($to_timecode !== null)
			{
//				if we have a pre input stream seek then input video is then offset by that ammount
				if($pre_input_stream_seek_offset > 0)
				{
					$to_timecode = new Timecode($to_timecode->total_seconds-$pre_input_stream_seek_offset, Timecode::INPUT_FORMAT_SECONDS);
				}
				$this->_extract_segment['length'] = $to_timecode->total_seconds - $from_timecode->total_seconds;
			}
			
			return $this;
		}
		
		/**
		 * Splits (aka ffmpeg segment) the output into multiple files.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @return void
		 */
		public function split($split_by, $time_delta=0, $output_list_path=null)
		{
//			check that segment is available to ffmpeg
			$ffmpeg = Factory::ffmpegParser();
			$formats = $ffmpeg->getFormats();
			if(isset($formats['segment']) === false)
			{
				throw new Exception('Unable to split media as the ffmpeg option "-segment" is not supported by your version of ffmpeg.');
			}
			
//			check to see if split options are already set
			if(empty($this->_split_options) === false)
			{
				throw new Exception('Split options have already been set. You cannot call split more than once on a '.get_class($this).' object.');
			}
			
			$this->_split_options = array();
			$duration = $this->readDuration();
			
//			check the split by
			if(empty($split_by) === true)
			{
				throw new Exception('The split by value is empty, in \\PHPVideoToolkit\\'.get_class($this).'::split');
			}
//			if we have an array, it's either timecodes (seconds) or integers (frames)
			else if(is_array($split_by) === true)
			{
//				we check to see if we have a timecode object, if we do then we are spliting at exact points
				if(is_object($split_by[0]) === true)
				{
					$times = array();
					foreach ($split_by as $key=>$timecode)
					{
						if(get_class($timecode) !== 'PHPVideoToolkit\Timecode')
						{
							throw new Exception('The split by timecode specified in index '.$key.' is not a \\PHPVideoToolkit\\Timecode object.');
						}
						
//						check the timecode against the total number of seconds in the media duration.
						$seconds = $timecode->total_seconds;
						if($seconds > $duration->total_seconds)
						{
							throw new Exception('The split by timecode specified in index '.$key.' is greater than the duration of the media ('.$duration->total_seconds.' seconds).');
						}
						
						array_push($times, $seconds);
					}
				
					$this->_split_options['segment_times'] = implode(',', $times);
				}
//				otherwise we are spliting at frames
				else
				{
					$times = array();
					foreach ($split_by as $key=>$integer)
					{
						if(is_int($integer) === false)
						{
							throw new Exception('The split by frame number specified in index '.$key.' is not an integer.');
						}
						
						
//						check the frame number against the total number of frames in the media duration.
						// TODO total frame rate comparison
						// $seconds = ceil($timecode->total_seconds);
						// if($seconds > $duration->total_seconds)
						// {
						// 	throw new Exception('The split by timecode specified in index '.$key.' is greater than the duration of the media ('.$duration->total_seconds.' seconds).');
						// }
						// 
						array_push($times, $integer);
					}
				
					$this->_split_options['segment_frames'] = implode(',', $times);
				}
			}
//			anything else is treated as an integer of which each split is the same length.
			else 
			{
				if($split_by < 1)
				{
					throw new Exception('The split by value must be >= 1, in \\PHPVideoToolkit\\'.get_class($this).'::split');
				}
						
//				check the split time against the total number of seconds in the media duration.
				if($split_by > $duration->total_seconds)
				{
					throw new Exception('The split by value is greater than the duration of the media ('.$duration->total_seconds.' seconds).');
				}
						
				$this->_split_options['segment_time'] = (int) $split_by;
			}

//			check time delta
			if($time_delta < 0)
			{
				throw new Exception('The time delta specified "'.$time_delta.'", in \\PHPVideoToolkit\\'.get_class($this).'::split must be >= 0');
			}
			else if($time_delta > 0)
			{
				$this->_split_options['segment_time_delta'] = (float) $time_delta;
			}
			
//			check the directory that contains the output list is writeable
			if(empty($output_list_path) === false)
			{
				$output_list = realpath($output_list_path);
				$output_list_dir = dirname($output_list);
				if(is_dir($output_list_dir) === false)
				{
					throw new Exception('The directory for the output list file "'.$output_list_path.'" does not exist, in \\PHPVideoToolkit\\'.get_class($this).'::split');
				}
				else if(is_writeable($output_list_dir) === false)
				{
					throw new Exception('The directory for the output list file "'.$output_list_path.'" is not writeable, in \\PHPVideoToolkit\\'.get_class($this).'::split');
				}
				
				$this->_split_options['segment_list'] = $output_list_path;
			}
			
			return $this;
		}
		
		/**
		 * Returns the exec buffer object by reference.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @return void
		 */
		public function &getProcess()
		{
			return $this->_process;
		}
		
		/**
		 * Gets the final length of the output based upon the extraction/split commands
		 * If the output is bing split, then an array will be returned, otherwise a float.
		 * IMPORTANT! The duration(s) returned are based of various configurable options
		 * and the resulting output by ffmpeg may vary slightly.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @return mixed
		 */
		public function getEstimatedFinalDuration()
		{
			$duration = $this->readDuration();
			$duration_seconds = $duration->total_seconds;
			
//			as extractSegment must always be called before split therefore we process the segment options first
			if(empty($this->_extract_segment) === false)
			{
				if(empty($this->_extract_segment['length']) === false)
				{
					$duration_seconds = $this->_extract_segment['length'];
				}
				else
				{
					if(empty($this->_extract_segment['preseek']) === false)
					{
						$duration_seconds -= $this->_extract_segment['preseek']->total_seconds;
					}
					if(empty($this->_extract_segment['seek']) === false)
					{
						$duration_seconds -= $this->_extract_segment['preseek']->total_seconds;
					}
				}
				
				$duration = new Timecode($duration_seconds, Timecode::INPUT_FORMAT_SECONDS);
			}
			
//			do we have any split options?
			if(empty($this->_split_options) === false)
			{
				// segment_time, a single time in seconds
				// segment_times, multiple times in seconds
				// segment_frames, frames
			}
			
			return $duration;
		}
		
		/**
		 * Registers a post process function. Any function registered is called after all the toolkit
		 * post process callbacks have been made.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param Function $callback 
		 * @return self
		 */
		public function registerSavePostProcess($callback)
		{
			$this->_registerSavePostProcess($callback, 'user');
			return $this;
		}
		
		/**
		 * Registers a post process function. 
		 * 
		 * @access protected
		 * @author Oliver Lillie
		 * @param Function $callback 
		 * @param string $callback_type
		 * @return void
		 */
		protected function _registerSavePostProcess($callback, $callback_type, $index=null)
		{
			if(is_callable($callback) === false)
			{
				throw new Exception('The callback "'.$callback.'" is not callable.');
			}
			if(in_array($type, array('user', 'toolkit')) === false)
			{
				throw new Exception('The callback type "'.$callback_type.'" is not recognised.');
			}
			
			if($index === null)
			{
				array_push($this->_post_process_callbacks[$callback_type], $callback);
			}
			else
			{
				array_splice($this->_post_process_callbacks[$callback_type], $index, 0, $callback);
			}
		}
		
		/**
		 * Saves any changes to the media file to the given save path.
		 * IMPORTANT! This save blocks PHP execution, meaning that once called, the PHP interpretter
		 * will NOT continue untill the video/audio/media file(s) have been transcoded.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param string $save_path 
		 * @param Format $output_format 
		 * @param string $overwrite 
		 * @param ProgressHandlerAbstract $progress_handler
		 * @return mixed If the blocking mode of the process is set to block, the it returns a new 
		 * 	Media object on a successfull completion, otherwise an exception is thrown. If the blocking
		 *	mode is non blocking then the underlying FfmpegProcess is returned.
		 */
		public function save($save_path=null, Format $output_format=null, $overwrite=Media::OVERWRITE_FAIL, ProgressHandlerAbstract &$progress_handler=null)
		{
//			pre process all of the common functionality and pre process the output format.
			$this->_savePreProcess($output_format, $save_path, $overwrite, $progress_handler);
			
//			set the progress handler 
			if($progress_handler !== null)
			{
				$progress_handler->setTotalDuration($this->getEstimatedFinalDuration());
				$this->_process->attachProgressHandler($progress_handler);
			}
			
//			add the commands from the output format to the exec buffer
//			NOTE; this cannot be done in _savePreProcess as it must be done after, to ensure all the subclass
//			_savePreProcess functionality and main media class functionality is properly executed.
			$this->_saveAddOutputFormatCommands($output_format);

//			set the processing output path
//			exec the buffer
//			set the blocking mode
//			and execute the ffmpeg process.
			$this->_process->setOutputPath($this->_processing_path)
						   ->getExecBuffer()
						   ->setBlocking($this->_blocking)
						   ->execute();
			
//			now we work out what we are returning as it depends on the blocking status.
			if($this->_blocking === true)
			{
				return $this->_process->getOutput();
			}
			
//			just return the process if the process is non blocking.
			return $this->_process;
		}
		
		/**
	 	 * Saves any changes to the media file to the given save path.
	 	 * IMPORTANT! This save does NOT block PHP execution, meaning that once called, the PHP interpretter
	 	 * will IMMEDIATELY continue. PHP will continue, in all likelyhood, exit before the ffmpeg has
		 * completed the transcoding of any output.
		 *
		 * If you need to monitor the output for completion or processing then you can supplied a Processor
		 * object that will setup monitoring dependant on which processor is supplied.
	 	 *
		 * @access public
		 * @author Oliver Lillie
		 * @param string $save_path 
		 * @param Format $output_format 
		 * @param string $overwrite 
		 * @return boolean If the command is sent without error then true is returned, otherwise false.
		 *	The last error message is set to Media->last_error_message. A full list of error messages is 
		 *	available through Media->error_messages.
		 */
		public function saveNonBlocking($save_path=null, Format $output_format=null, $overwrite=self::OVERWRITE_FAIL, ProgressHandlerAbstract &$progress_handler=null)
		{
//			set the non blocking of the exec process
			$this->_blocking = false;
			
//			set the progress handler 
			if($progress_handler !== null)
			{
//				because only certain types of handlers are compatible with non blocking saves we need to check for compatibility.
				if($progress_handler->getNonBlockingCompatibilityStatus() === false)
				{
					throw new Exception('The progress handler given is not compatible with a non blocking save. This typically means that you have supplied a callback function in the constructor of the progress handler. Any progress handler with a supplied callback blocks PHP. Instead you should call $handler->probe() after the saveNonBlocking function call to get the progress of the encode.');
				}
			}
			
			return $this->save($save_path, $output_format, $overwrite, $progress_handler);
		}
		
		/**
		 * Generates the command sent through to exec to invoke ffmpeg. This can be useful if you
		 * want to manage the execution of the command yourself, for instance using a transcode queue.
		 *
		 * If you need to monitor the output for completion or processing then you can supplied a Processor
		 * object that will setup monitoring dependant on which processor is supplied.
	 	 *
		 * @access public
		 * @author Oliver Lillie
		 * @param Format $output_format 
		 * @param string $save_path 
		 * @param string $overwrite 
		 * @param Processor $processor
		 * @return string
		 */
		public function getExecutionCommand($save_path, Format $output_format=null, $overwrite=self::OVERWRITE_FAIL)
		{
//			pre process all of the common functionality and pre process the output format.
			$this->_savePreProcess($output_format, $save_path, $overwrite);
			
//			add the commands from the output format to the exec buffer
//			NOTE; this cannot be done in _savePreProcess as it must be done after, to ensure all the subclass
//			_savePreProcess functionality and main media class functionality is properly executed.
			$this->_saveAddOutputFormatCommands($output_format);

//			generate a processing address within the temp directory
			$this->_process->setOutputPath($this->_processing_path);

			Trace::vars($this->_process->getExecutableString());
		}
		
		/**
		 * All three save functions, save, saveNonBlocking and getExecutionCommand have common things they 
		 * have to do before they are processed. This function contains those execution "warm-up" procedures.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param Format $output_format 
		 * @param string $save_path 
		 * @param string $overwrite 
		 * @param ProgressHandlerAbstract $progress_handler 
		 * @return void
		 */
		protected function _savePreProcess(Format &$output_format=null, &$save_path, $overwrite, ProgressHandlerAbstract &$progress_handler=null)
		{
//			do some processing on the input format
			// $this->_processInputFormat();
			
//			if the save path is null then we are overwriting the existing media file.
			if($save_path === null)
			{
				$overwrite = self::OVERWRITE_UNIQUE;
				$save_path = $this->_media_file_path;
			}
			
//			do some pre processing of the output format
			$this->_processOutputFormat($output_format, $save_path);
			
//			check the save path.
			$has_timecode_or_index = false;
			$basename = basename($save_path);
			$save_dir = dirname($save_path);
			$save_dir = realpath($save_dir);
			if($save_dir === false || is_dir($save_dir) === false)
			{
				throw new Exception('The directory that the output is to be saved to, "'.$save_dir.'" does not exist.');
			}
			else if(is_writeable($save_dir) === false || is_readable($save_dir) === false)
			{
				throw new Exception('The directory that the output is to be saved to, "'.$save_dir.'" is not read-writeable.');
			}
//			check to see if we have a split output name.
//			although this is technically still allowed by ffmpeg, phpvideotoolkit has depreciated %d in favour of its own %index
			else if(preg_match('/\%([0-9]*)d/', $save_path) > 0)
			{
				throw new Exception('The output file appears to be using ffmpegs\' %d notation for multiple file output. %d notation is depreciated in PHPVideoToolkit in favour of the %index or %timecode notations.');
			}
//			if a %index or %timecode output is added then we can't check for exact file existence
//			we can however check for possible interfering matches.
			else if(($has_timecode_or_index = (preg_match('/\%(timecode|([0-9]+)?index)/', $save_path) > 0)))
			{
				// TODO glob and then regex the related matches to check for errors
			}
//			otherwise check that a file exists and the overrwite status of the request.
			else
			{
				if(is_file($save_dir.DIRECTORY_SEPARATOR.$basename) === true && (empty($overwrite) === true || $overwrite === self::OVERWRITE_FAIL))
				{
					throw new Exception('The output file already exists and overwriting is disabled.');
				}
				else if(is_file($save_dir.DIRECTORY_SEPARATOR.$basename) === true && $overwrite === self::OVERWRITE_EXISTING && is_writeable($save_dir.DIRECTORY_SEPARATOR.$basename) === false)
				{
					throw new Exception('The output file already exists, overwriting is enabled however the file is not writable.');
				}
			}
			$save_path = $save_dir.DIRECTORY_SEPARATOR.$basename;
			
//			set the input files.
			$this->_process->setInputPath($this->_media_file_path);
			
//			process the overwrite status
			switch($overwrite)
			{
		    	case self::OVERWRITE_EXISTING :
					$this->_process->addCommand('-y');
					break;
					
//				insert a unique id into the save path
		    	case self::OVERWRITE_UNIQUE :
					$pathinfo = pathinfo($save_path);
					$save_path = $pathinfo['dirname'].DIRECTORY_SEPARATOR.$pathinfo['filename'].'-u_'.$this->_generateRandomString().'.'.$pathinfo['extension'];
					break;
			}
			$this->_output_path = 
			$this->_processing_path = $save_path;
			
//			check to see if we are extracting a segment
//			It is important that we are the extract commands before any segmenting, so that if we are extracting
//			a segment then spliting the file everything goes as expected.
			if(empty($this->_extract_segment) === false)
			{
				if(empty($this->_extract_segment['preseek']) === false)
				{
					$this->_process->addPreInputCommand('-ss', $this->_extract_segment['preseek']->getTimecode('%hh:%mm:%ss.%ms', false));
				}
				if(empty($this->_extract_segment['seek']) === false)
				{
					$this->_process->addCommand('-ss', $this->_extract_segment['seek']->getTimecode('%hh:%mm:%ss.%ms', false));
				}
				if(empty($this->_extract_segment['length']) === false)
				{
					$this->_process->addCommand('-t', $this->_extract_segment['length']);
				}
			}
			
//			if we are splitting the output
			if(empty($this->_split_options) === false)
			{
//				if so check that a timecode or index has been set
				if($has_timecode_or_index === false)
				{
					$pathinfo = pathinfo($save_path);
					$save_path = 
					$this->_output_path = $pathinfo['dirname'].DIRECTORY_SEPARATOR.$pathinfo['filename'].'-%timecode.'.$pathinfo['extension'];
					$has_timecode_or_index = true;
				}
				
//				if we are splitting we need to add certain commands to make it work.
//				one of those is -map 0. Also note that video and audio objects additionally set their own
//				codecs if not supplied, in their related class function _savePreProcess
				// TODO this may need to be changed dependant on the number of mappings.
				$this->_process->addCommand('-map', '0');
				
				 // -acodec copy 
				 // -vcodec copy 
				
//				we must do this via add command rather than setFormat as it rejects the segment format.
				$this->_process->addCommand('-f', 'segment');
				foreach ($this->_split_options as $command => $arg)
				{
					$this->_process->addCommand('-'.$command, $arg);
				}

//				get the output commands and augment with the final output options.
				$options = $output_format->getFormatOptions();

//				set the split format if an output format has already been set.
				if(empty($options['format']) === false)
				{
					$this->_process->addCommand('-segment_format', $options['format']);
				}
			}
			
//			check to see if we have any global meta
			if(empty($this->_metadata) === false)
			{
				$meta_string = array();
				foreach ($this->_metadata as $key => $value)
				{
					$this->_process->addCommand('-metadata:g', $key.'='.$value.'', true);
				}
			}

//			if we have a timecode or index based path we then have to supply a temporary processing path so that
//			we can perform the rename to timecode and index after they items have been transcoded by ffmpeg.
			if($has_timecode_or_index === true)
			{
				$processing_path = preg_replace('/%timecode/', '%12d', $this->_output_path);
				$processing_path = preg_replace('/%([0-9]+)?index/', '%$1d', $processing_path);
				
//				add a unique identifier to the processing path to prevent overwrites.
				$pathinfo = pathinfo($processing_path);
				$this->_processing_path = $pathinfo['dirname'].DIRECTORY_SEPARATOR.$pathinfo['filename'].'-d_'.$this->_generateRandomString().'.'.$pathinfo['extension'];
			}
		}
		
		/**
		 * Process the output format just before the it is compiled into commands.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param Format &$output_format 
		 * @return void
		 */
		protected function _processOutputFormat(Format &$output_format=null, &$save_path)
		{
//			check to see if we have been set and output format, if not generate an empty one.
			if($output_format === null)
			{
				$output_format = $this->getDefaultFormat('output');
			}
			
//			set the media into the format object so that we can update the format options that
//			require a media object to process.
			$output_format->setMedia($this)
						  ->updateFormatOptions();
		}
		
		protected function _saveAddOutputFormatCommands(Format $output_format=null)
		{
			if($output_format !== null)
			{
				$commands = $output_format->getCommandsHash();
				if(empty($commands) === false)
				{
					$this->_process->addCommands($commands);
				}
			}
		}
		
		protected function _generateRandomString()
		{
			return (rand(99, 99999).'_'.$this->_generateRandomAlphaString(15).'_'.time());
		}
		
		protected function _generateRandomAlphaString($length) 
		{
		    $characters = 'abcdefghijklmnopqrstuvwxyz';
		    $randomString = '';
		    for ($i = 0; $i < $length; $i++)
			{
		        $randomString .= $characters[rand(0, strlen($characters) - 1)];
		    }
		    return $randomString;
		}
		
//		The below functions override the MediaParser functions so to automatically provide
//		the media_file_path each time.
		
		/**
		 * Returns the information about a specific media file.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return array
		 */
		public function read($read_from_cache=true)
		{
			return parent::getFileInformation($this->_media_file_path, $read_from_cache);
		}
		
		/**
		 * Returns the files duration as a Timecode object if available otherwise returns false.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return mixed Returns a Timecode object if the duration is found, otherwise returns null.
		 */
		public function readDuration($read_from_cache=true)
		{
			return parent::getFileDuration($this->_media_file_path, $read_from_cache);
		}
		
		/**
		 * Returns the files duration as a Timecode object if available otherwise returns false.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return mixed Returns a Timecode object if the duration is found, otherwise returns null.
		 */
		public function readGlobalMetaData($read_from_cache=true)
		{
			return parent::getFileGlobalMetadata($this->_media_file_path, $read_from_cache);
		}
		
		/**
		 * Returns the files bitrate if available otherwise returns -1.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return mixed Returns the bitrate as an integer if available otherwise returns -1.
		 */
		public function readBitrate($read_from_cache=true)
		{
			return parent::getFileBitrate($this->_media_file_path, $read_from_cache);
		}
		
		/**
		 * Returns the start point of the file as a Timecode object if available, otherwise returns null.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return mixed Returns a Timecode object if the start point is found, otherwise returns null.
		 */
		public function readStart($read_from_cache=true)
		{
			return parent::getFileStart($this->_media_file_path, $read_from_cache);
		}
		
		/**
		 * Returns the start point of the file as a Timecode object if available, otherwise returns null.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return mixed Returns a string 'audio' or 'video' if media is audio or video, otherwise returns null.
		 */
		public function readType($read_from_cache=true)
		{
			return parent::getFileType($this->_media_file_path, $read_from_cache);
		}
		
		/**
		 * Returns any video information about the file if available.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return mixed Returns an array of found data, otherwise returns null.
		 */
		public function readVideoComponent($read_from_cache=true)
		{
			return parent::getFileVideoComponent($this->_media_file_path, $read_from_cache);
		}
		
		/**
	 	 * Returns any audio information about the file if available.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return mixed Returns an array of found data, otherwise returns null.
		 */
		public function readAudioComponent($read_from_cache=true)
		{
			return parent::getFileAudioComponent($this->_media_file_path, $read_from_cache);
		}
		
		/**
		 * Returns a boolean value determined by the media having an audio channel.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return boolean
		 */
		public function readHasAudio($read_from_cache=true)
		{
			return parent::getFileHasAudio($this->_media_file_path, $read_from_cache);
		}
		
		/**
		 * Returns a boolean value determined by the media having a video channel.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return boolean
		 */
		public function readHasVideo($read_from_cache=true)
		{
			return parent::getFileHasVideo($this->_media_file_path, $read_from_cache);
		}
		
		/**
		 * Returns the raw data provided by ffmpeg about a file.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return mixed Returns false if no data is returned, otherwise returns the raw data as a string.
		 */
		public function readRawInformation($read_from_cache=true)
		{
			return parent::getFileRawInformation($this->_media_file_path, $read_from_cache);
		}
	}