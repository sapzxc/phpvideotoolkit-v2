<?php

namespace tests\basic;

use PHPVideoToolkit\Exception;
use tests\VideoTestAbstraction;

class RotationTest extends VideoTestAbstraction
{
	protected $sourceFilePath = null;

	/**
	 * download sample video file
	 * @throws \PHPVideoToolkit\Exception
	 */
	public function setUp()
	{
		parent::setUp();

		$this->sourceFilePath = \tests\VideoTestAbstraction::getTestFile();
	}

	public function testNoRotation()
	{
		$dstFile = TESTS_TMP_DIR.'simple.mp4';
		$res = $this->basicConvert($this->sourceFilePath, $dstFile);

//		echo "CommandString: ".$res->getCommandString()."\n";
//		echo "Output: ".serialize($res->getOutput())."\n";

		$this->assertFalse($res->hasError(), "Conversion error: ".serialize($res->getMessages()));

		$this->assertFileExists($dstFile, "Dst file does not exists");

		$this->assertGreaterThan(0, filesize($dstFile), "Output file empty");

		// check dimensions
		$srcVideo = new \PHPVideoToolkit\Video($this->sourceFilePath, $this->toolkitConfig);
		$srcDimensions = $srcVideo->readDimensions(false);

		$dstVideo = new \PHPVideoToolkit\Video($dstFile, $this->toolkitConfig);
		$dstDimensions = $dstVideo->readDimensions(false);

		$this->assertEquals($srcDimensions, $dstDimensions);
	}

	public function testRotation90()
	{
		$dstFile = TESTS_TMP_DIR.'rotated90.mp4';
		$res = $this->basicConvert($this->sourceFilePath, $dstFile, 90);

		echo "CommandString: ".$res->getCommandString()."\n";
//		echo "Output: ".serialize($res->getOutput())."\n";

		$this->assertFalse($res->hasError(), "Conversion error: ".serialize($res->getMessages()));

		$this->assertFileExists($dstFile, "Dst file does not exists");

		$this->assertGreaterThan(0, filesize($dstFile), "Output file empty");

		// check dimensions
		$srcVideo = new \PHPVideoToolkit\Video($this->sourceFilePath, $this->toolkitConfig);
		$srcDimensions = $srcVideo->readDimensions(false);

		$dstVideo = new \PHPVideoToolkit\Video($dstFile, $this->toolkitConfig);
		$dstDimensions = $dstVideo->readDimensions(false);
		//swap width and height for dst and then assert
		$w=$dstDimensions['width'];
		$dstDimensions['width'] = $dstDimensions['height'];
		$dstDimensions['height'] = $w;

		$this->assertEquals($srcDimensions, $dstDimensions);
	}

	/**
	 * @param $srcFile
	 * @param $dstFile
	 * @param null|int|bool $rotation
	 * @return \PHPVideoToolkit\FfmpegProcessProgressable
	 */
	protected function basicConvert($srcFile, $dstFile, $rotation=null)
	{
		$outputFormat = new \PHPVideoToolkit\VideoFormat_Mp4('output', $this->toolkitConfig);
		$outputFormat->addCommand('-profile:v','main');
		$outputFormat
			->setVideoCodec('libx264')
			->setVideoBitrate('2500k')
			->setVideoDimensions(\PHPVideoToolkit\VideoFormat::DIMENSION_HD720, null, true)

			->setAudioCodec('aac')
			->setAudioBitrate('96k')
			->setAudioChannels(2)
			->setAudioSampleFrequency(48000)
		;

		if($rotation===true || is_numeric($rotation))
		{
			$outputFormat->setVideoRotation($rotation);
		}

		$video = new \PHPVideoToolkit\Video($srcFile, $this->toolkitConfig);

		return $video->save($dstFile, $outputFormat, \PHPVideoToolkit\Media::OVERWRITE_EXISTING);
	}
}
