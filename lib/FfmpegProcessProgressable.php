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
	 * undocumented class
	 *
	 * @access public
	 * @author Oliver Lillie
	 * @package default
	 */
	class FfmpegProcessProgressable extends FfmpegProcess 
	{
		private $_progress_callbacks;
		
		public function __construct($binary_path, $temp_directory)
		{
			parent::__construct($binary_path, $temp_directory);
			
			$this->_progress_callbacks = array();
		}
		
		/**
		 * Sets a command on ffmpeg that sets a timelimit
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param string $timelimit_in_seconds 
		 * @return void
		 */
		public function setProcessTimelimit($timelimit_in_seconds)
		{
			$parser = new FfmpegParser($this->_config);
			$commands = $parser->getCommands();
			if(isset($commands['timelimit']) === false)
			{
				throw new Exception('The -timelimit command is not supported by your version of FFmpeg.');
			}
			
			if($timelimit_in_seconds <= 0)
			{
				throw new Exception('The timelimit must be greater than 0 seconds.');
			}
			
			$this->addCommand('-timelimit', $timelimit_in_seconds);
		}
		
		/**
		 * Attaches a progress handler to the ffmpeg progress. 
		 * The progress handler is executed during the ffmpeg process.
		 * Attaching a handler causes PHP to block.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param string $callback 
		 * @return void
		 */
		public function attachProgressHandler($callback)
		{
			if(is_object($callback) === true)
			{
				if(is_subclass_of($callback, 'PHPVideoToolkit\ProgressHandlerAbstract') === false)
				{
					throw new Exception('If supplying an object to attach as a progress handler, that object must inherit from ProgressHandlerAbstract.');
				}

				$callback->attachFfmpegProcess($this, $this->_temp_directory);
			}
			else if(is_callable($callback) === false)
			{
				throw new Exception('The progress handler must either be a class that extends from ProgressHandlerAbstract or a callable function.');
			}
			
			array_push($this->_progress_callbacks, $callback);
		}
		
		/**
		 * This function is used to execute the callback handlers when present.
		 *
		 * IMPORTANT! This is a protected function, however due to the nature of the 
		 * callbacks, it must be public in order to be callable. 
		 *
		 * @access protected
		 * @author Oliver Lillie
		 * @return void
		 */
		public function _executionCallbackRunner()
		{
	        foreach($this->_progress_callbacks as $callback)
			{
				if(is_object($callback) === true)
				{
					$callback->callback();
				}
	            else
				{
					call_user_func($callback, $this);
				}
	        }
		}
		
		/**
		 * Executes the ffmpeg process and can be supplied with an optional progress callback.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param mixed $callback If given it must be a valid function that is callable.
		 * @return void
		 */
		public function execute($callback=null)
		{
			if($callback !== null)
			{
				if(is_callable($callback) === false)
				{
					throw new Exception('Callback is not callable.');
				}

				$this->attachProgressHandler($callback);
			}
			
			if(empty($this->_progress_callbacks) === false)
			{
				$callback = array($this, '_executionCallbackRunner');
			}

			$this->getExecBuffer()
				 ->setBlocking(false)
				 ->execute($callback);
			
			return $this;
		}
		
		/**
		 * Once the process has been completed this function can be called to return the output
		 * of the process. Depending on what the process is outputting depends on what is returned.
		 * If a single video or audio is being outputted then the related PHPVideoToolkit media object
		 * will be returned. However if multiple files are being outputed then an array of the associated
		 * objects are returned. Typically speaking an array will be returned when %index or %timecode
		 * are within the output path.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @return mixed
		 */
		public function getOutput($post_process_callback=null)
		{
			if($this->isCompleted() === false)
			{
				throw new FfmpegProcessOutputException('Encoding has not yet started.');
			}
			
//			check for an error.
			if($this->hasError() === true)
			{
//				check for specific recieved signal errors.
				$last_split = $this->getLastSplit();
				if(preg_match('/Received signal ([0-9]+): terminating\./', $last_split, $matches) > 0)
				{
					$kill_signals = array(
						1 => 'Hang up detected on controlling terminal or death of controlling process.',
						2 => 'User sent an interrupt signal.',
						3 => 'User sent a quit signal.',
						4 => 'Illegal instruction.',
						6 => 'Abort signal from abort(3).',
						8 => 'Floating point exception.',
						9 => 'Kill signal sent.',
						11 => 'Invalid memory reference',
						13 => 'Broken pipe: write to pipe with no readers',
						14 => 'Timer signal from alarm(2)',
						15 => 'Termination signal sent.',
						24 => 'Imposed time limit ({length} seconds) exceeded.',
					);
					// TODO add more signals.
					$kill_int = (int) $matches[1];
					if(isset($kill_signals[$kill_int]) === true)
					{
						$message = $kill_signals[$kill_int];
						if($kill_int == 24)
						{
							$length = $this->getCommand('-timelimit');
							$length = !$length ? 'unknown' : $length;
							$message = str_replace('{length}', $length, $message);
						}
						throw new FfmpegProcessOutputException('Process was aborted. '.$message);
					}
					else
					{
						throw new FfmpegProcessOutputException('Termination signal received and the process aborted. Signal was '.$matches[1]);
					}
				}
			
				throw new FfmpegProcessOutputException('Encoding failed and an error was returned from ffmpeg. Error code '.$this->getErrorCode().' was returned the message (if any) was: '.$last_split);
			}
			
			if($post_process_callback !== null)
			{
				if(is_callable($post_process_callback) === false)
				{
					throw new Exception('The supplied post proces scallback is not callable.');
				}
			}
			
//			get the output of the process
			$output = $this->getOutputPath();
			
//			we have the output path but we now need to treat differently dependant on if we have multiple file output.
			if(preg_match('/\.(\%([0-9]*)d)\.([0-9\.]+_[0-9\.]+\.)?_(i|t)\./', $output, $matches) > 0)
			{
//				determine what we have to rename all the files to.
				$convert_back_to = $matches[4] === 't' ? 'timecode' : (int) $matches[2];
				
//				get the glob path and then find all the files from this output
				$output_glob_path = str_replace($matches[0], '.*.'.$matches[3].'_'.$matches[4].'.', $output);
				$output = glob($output_glob_path);
				
//				sort the output naturally so that if there is no index padding that we get the frames in the correct order.
				natsort($output);

//				loop and rename the output.
				$rename = array();
				$timecode = null;
				foreach ($output as $path)
				{
					$actual_path = preg_replace('/\._u\.[0-9]{5}_[a-z0-9]{5}_[0-9]+\.u_\./', '.', $path);
					if($convert_back_to === 'timecode')
					{
//						if the start timecode has not been generated then find the required from the path string.
						if($timecode === null)
						{
							$matches[3] = rtrim($matches[3], '.');
							$matches[3] = explode('_', $matches[3]);
							$timecode = new Timecode($matches[3][1], Timecode::INPUT_FORMAT_SECONDS, $matches[3][0]);
						}
						else
						{
							$timecode->frame += 1;
						}
						$actual_path = preg_replace('/\.[0-9]{12}\.[0-9\.]+_[0-9\.]+\._t\./', $timecode->getTimecode('%hh_%mm_%ss_%ms', false), $actual_path);
					}
					else
					{
						$actual_path = preg_replace('/\.([0-9]+)\._i\./', '$1', $actual_path);
					}
					rename($path, $actual_path);
				}
			}
			else
			{
//				check for a none multiple file existence
				if(empty($output) === true)
				{
					throw new FfmpegProcessOutputException('Unable to find output for the process as it was not set.');
				}
				else if(is_file($output) === false)
				{
					throw new FfmpegProcessOutputException('The output "'.$output.'", of the Ffmpeg process does not exist.');
				}
				else if(filesize($output) <= 0)
				{
					throw new FfmpegProcessOutputException('The output "'.$output.'", of the Ffmpeg process is a 0 byte file. Something must have gone wrong however it wasn\'t reported as an error by FFmpeg.');
				}
				
//				get the media class from the output.
//				create the object from the class name and return the new object.
				$media_class = $this->_findMediaClass($output);
				$output = new $media_class($output, null, $this->_config);
				
//				do any post processing callbacks
				if($post_process_callback !== null)
				{
					$output = call_user_func($post_process_callback, $output, $this);
				}
				
//				finally return the output to the user.
				return $output;
			}
		}
		
		/**
		 * Attempts to read the data about the file given by $path and then returns the class
		 * name of the related media object.
		 *
		 * @access protected
		 * @author Oliver Lillie
		 * @param string $path 
		 * @return string
		 */
		protected function _findMediaClass($path)
		{
//			read the output to determine what it is so it can be post processed.
			try
			{
				$parser = new MediaParser($this->_config);
				$output_information = $parser->getFileInformation($path, false);
			}
			catch(Exception $e)
			{
				throw new Exception('The output "'.$output.'", of the Ffmpeg process could not be read by MediaParser.', 0, $e);
			}
			
//			now we have the information switch between the types and create the return object.
			$class = 'Media';
			$type = $output_information['type'];
			switch($type)
			{
				case 'audio' :
				case 'video' :
				case 'image' :
					$class = '\\PHPVideoToolkit\\'.ucfirst(strtolower($type));
					break;
			}
			
			return $class;
		}

	}
