<?php
namespace tests\video;

use tests\VideoTestAbstraction;

/**
 * Class VideoTest
 * @package tests\video
 */
class VideoTest extends VideoTestAbstraction
{
	public function testGetOptimalDimensions()
	{
		// tests b ased on source video with dimensions 640x480
		$srcVideo = new \PHPVideoToolkit\Video(\tests\VideoTestAbstraction::getTestFile(), $this->toolkitConfig);

		// biggest dimensions
		$calcultedDimensions = $srcVideo->getOptimalDimensions(1024, 768);
		$this->assertEquals(array(
			  "padded_width"	=> 640,
			  "padded_height"	=> 480,
			  "video_width"		=> 640,
			  "video_height"	=> 480,
			  "pad_top"			=> 0,
			  "pad_right"		=> 0,
			  "pad_bottom"		=> 0,
			  "pad_left"		=> 0,
		),$calcultedDimensions);

		// biggest source video
		$calcultedDimensions = $srcVideo->getOptimalDimensions(320, 240);
		$this->assertEquals(array(
			"padded_width"	=> 320,
			"padded_height"	=> 240,
			"video_width"	=> 640,
			"video_height"	=> 480,
			"pad_top"		=> 0,
			"pad_right"		=> 0,
			"pad_bottom"	=> 0,
			"pad_left"		=> 0,
		),$calcultedDimensions);

		// rotated view by 90deg
		$calcultedDimensions = $srcVideo->getOptimalDimensions(768, 1024);
		$this->assertEquals(array(
			"padded_width"	=> 640,
			"padded_height"	=> 480,
			"video_width"	=> 640,
			"video_height"	=> 480,
			"pad_top"		=> 144,
			"pad_right"		=> 0,
			"pad_bottom"	=> 144,
			"pad_left"		=> 0,
		),$calcultedDimensions);
	}

}
