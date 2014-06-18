<?php

namespace tests;


class VideoTestAbstraction extends \PHPUnit_Framework_TestCase
{
	/**
	 * @var null|\PHPVideoToolkit\Config
	 */
	protected $toolkitConfig = null;

	/**
	 * tests set up
	 */
	public function setUp()
	{
		$this->toolkitConfig = new \PHPVideoToolkit\Config(array(
			'temp_directory'	=> TESTS_TMP_DIR,
			'ffmpeg'			=> FFMPEG_BASE_PATH.'/ffmpeg',
			'ffprobe'			=> FFMPEG_BASE_PATH.'/ffprobe',
			'yamdi'				=> FFMPEG_BASE_PATH.'/yamdi',
			'qtfaststart'		=> FFMPEG_BASE_PATH.'/qt-faststart',
		));
	}

	/**
	 * tests tear down
	 */
	public function tearDown()
	{
		//TODO
	}

	public static function getTestFile()
	{
		$sourceFilePath = TESTS_TMP_DIR.'src.mov';
		if(!file_exists($sourceFilePath))
		{
			$url = 'http://a1408.g.akamai.net/5/1408/1388/2005110403/1a1a1ad948be278cff2d96046ad90768d848b41947aa1986/sample_iTunes.mov.zip';

			$name = basename(parse_url($url, PHP_URL_PATH));
			exec('cd '.TESTS_TMP_DIR.' && rm -f '.$name.' && wget '.$url.' && unzip '.$name);

			if(!rename(TESTS_TMP_DIR.'sample_iTunes.mov', TESTS_TMP_DIR.'src.mov'))
			{
				throw new \Exception('Cant rename downloaded test file "sample_iTunes.mov" to "src.mov".');
			}
		}

		return $sourceFilePath;
	}
}
