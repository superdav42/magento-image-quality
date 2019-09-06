<?php
/**
 * Copyright © David Stone. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace DevStone\ImageQuality\Image\Adapter;

/**
 * Description of ImageMagick
 *
 * @author David Stone
 */
class GraphicsMagick extends \Magento\Framework\Image\Adapter\AbstractAdapter
{
    /**
     * The blur factor where > 1 is blurry, < 1 is sharp
     */
    const BLUR_FACTOR = 1;

    /**
     * Error messages
     */
    const ERROR_WATERMARK_IMAGE_ABSENT = 'Watermark Image absent.';

    const ERROR_WRONG_IMAGE = 'Image is not readable or file name is empty.';
	
	private static $cloneProperties = [
		'_fileSrcPath',
		'_fileSrcName',
		'imageBackgroundColor',
		'_fileMimeType',
		'_imageSrcWidth',
		'_imageSrcHeight',
		'_fileType',
	];
    
    private static $profileData;
    
    private static $watermarks = [];
	
	private static $previousFileData = [];

	/**
     * Options Container
     *
     * @var array
     */
    protected $_options = [
        'resolution' => ['x' => 72, 'y' => 72],
        'small_image' => ['width' => 300, 'height' => 300],
        'sharpen' => ['radius' => 4, 'deviation' => 1],
    ];

    /**
     * Set/get background color. Check Gmagick::COLOR_* constants
     *
     * @param int|string|array $color
     * @return int
     */
    public function backgroundColor($color = null)
    {
        if ($color) {
            if (is_array($color)) {
                $color = "rgb(" . join(',', $color) . ")";
            }

            $pixel = new \GmagickPixel();
            if (is_numeric($color)) {
                $pixel->setColorValue($color, 1);
            } else {
                $pixel->setColor($color);
            }
            if ($this->_imageHandler) {
                $this->_imageHandler->setImageBackgroundColor($color);
            }
        } else {
            $pixel = $this->_imageHandler->getImageBackgroundColor();
        }

        $this->imageBackgroundColor = $pixel->getColor();

        return $this->imageBackgroundColor;
    }

    /**
     * Open image for processing
     *
     * @param string $filename
     * @return void
     * @throws \Exception
     */
    public function open($filename)
    {
        $this->_fileName = $filename;
        $this->_checkCanProcess();
		
		if ( isset(self::$previousFileData['filename']) && self::$previousFileData['filename'] === $filename) {
			
			foreach (self::$cloneProperties as $property) {
				$this->{$property} = self::$previousFileData[$property];
			}
			$this->_imageHandler = clone self::$previousFileData['_imageHandler'];
		} else {
		
			$this->_getFileAttributes();

			try {
				$this->_imageHandler = new \Gmagick($this->_fileName);
			} catch (\GmagickException $e) {
				throw new \Exception('Unsupported image format.', $e->getCode(), $e);
			}

			$this->backgroundColor();
			$this->getMimeType();
			$this->_imageHandler->profileimage('ICM', $this->getSRGBProfileData());
			
			self::$previousFileData['filename'] = $filename;
			self::$previousFileData['_imageHandler'] = clone $this->_imageHandler;
			
			foreach (self::$cloneProperties as $property) {
				self::$previousFileData[$property] = $this->{$property};
			}
		}
    }

    /**
     * Save image to specific path.
     * If some folders of path does not exist they will be created
     *
     * @param null|string $destination
     * @param null|string $newName
     * @return void
     * @throws \Exception  If destination path is not writable
     */
    public function save($destination = null, $newName = null)
    {
        $fileName = $this->_prepareDestination($destination, $newName);

		$this->_applyOptions();
        $this->_imageHandler->stripImage();
        $this->_imageHandler->writeImage($fileName);
    }

    /**
     * Apply options to image. Will be usable later when create an option container
     *
     * @return $this
     */
    protected function _applyOptions()
    {
        $this->_imageHandler->setCompressionQuality($this->quality());
        $this->_imageHandler->setImageCompression(\Gmagick::COMPRESSION_JPEG);
        $this->_imageHandler->setImageUnits(\Gmagick::RESOLUTION_PIXELSPERINCH);
        $this->_imageHandler->setImageResolution(
            $this->_options['resolution']['x'],
            $this->_options['resolution']['y']
        );

        $this->_imageHandler->setinterlacescheme(\Gmagick::INTERLACE_LINE);

        return $this;
    }

    /**
     * @see \Magento\Framework\Image\Adapter\AbstractAdapter::getImage
     * @return string
     */
    public function getImage()
    {
        $this->_applyOptions();
        return $this->_imageHandler->getImageBlob();
    }

    /**
     * Change the image size
     *
     * @param null|int $frameWidth
     * @param null|int $frameHeight
     * @return void
     */
    public function resize($frameWidth = null, $frameHeight = null)
    {
        $this->_checkCanProcess();
        $dims = $this->_adaptResizeValues($frameWidth, $frameHeight);

        $newImage = new \Gmagick();
        $newImage->newImage(
            $dims['frame']['width'],
            $dims['frame']['height'],
            $this->_imageHandler->getImageBackgroundColor()->getColor()
        );

        $this->_imageHandler->resizeImage(
            $dims['dst']['width'],
            $dims['dst']['height'],
            \Gmagick::FILTER_LANCZOS,
            static::BLUR_FACTOR
        );

        $newImage->compositeImage(
            $this->_imageHandler,
            \Gmagick::COMPOSITE_OVER,
            $dims['dst']['x'],
            $dims['dst']['y']
        );
        $newImage->setImageFormat($this->_imageHandler->getImageFormat());
        $newImage->setImageBackgroundColor($this->_imageHandler->getImageBackgroundColor());
        
        $this->_imageHandler->clear();
        $this->_imageHandler->destroy();
        $this->_imageHandler = $newImage;

        $this->refreshImageDimensions();
    }

    /**
     * Rotate image on specific angle
     *
     * @param int $angle
     * @return void
     */
    public function rotate($angle)
    {
        $this->_checkCanProcess();
        // compatibility with GD2 adapter
        $angle = 360 - $angle;
        $pixel = new \GmagickPixel();
        $pixel->setColor("rgb(" . $this->imageBackgroundColor . ")");

        $this->_imageHandler->rotateImage($pixel, $angle);
        $this->refreshImageDimensions();
    }

    /**
     * Crop image
     *
     * @param int $top
     * @param int $left
     * @param int $right
     * @param int $bottom
     * @return bool
     */
    public function crop($top = 0, $left = 0, $right = 0, $bottom = 0)
    {
        if ($left == 0 && $top == 0 && $right == 0 && $bottom == 0 || !$this->_canProcess()) {
            return false;
        }

        $newWidth = $this->_imageSrcWidth - $left - $right;
        $newHeight = $this->_imageSrcHeight - $top - $bottom;

        $this->_imageHandler->cropImage($newWidth, $newHeight, $left, $top);
        $this->refreshImageDimensions();
        return true;
    }

    private function getWatermarkResource($imagePath, $opacity)
    {
        $pathinfo = pathinfo($imagePath);
        $suffix = '-tmp';
        
        $watermarkWidth = $this->getWatermarkWidth();
        $watermarkHeight = $this->getWatermarkHeight();
        if (($watermarkWidth &&
            $watermarkHeight ) ||
            $this->getWatermarkPosition() === self::POSITION_STRETCH
        ) {
            if ($this->getWatermarkPosition() === self::POSITION_STRETCH) {
                $watermarkWidth = $this->_imageSrcWidth;
                $watermarkHeight = $this->_imageSrcHeight;
            }
            $suffix .= '-'.$watermarkWidth.'x'.$watermarkHeight;
        }
        
        
        if ($opacity < 100) {
            $suffix .= '-O'.$opacity;
        }
        
        $cachedFilename = $pathinfo['dirname'].DIRECTORY_SEPARATOR.$pathinfo['filename'].$suffix.'.png';
        
        if (isset(self::$watermarks[$cachedFilename])) {
            return self::$watermarks[$cachedFilename];
        }
        
        try {
            return self::$watermarks[$cachedFilename] = new \Gmagick($cachedFilename);
        } catch (\GmagickException $e) {
            // continue
        }

        if (empty($imagePath) || !file_exists($imagePath)) {
            throw new \LogicException(self::ERROR_WATERMARK_IMAGE_ABSENT);
        }
        
        list($watermarkSrcWidth, $watermarkSrcHeight, $watermarkFileType) = $this->_getImageOptions($imagePath);
        
        $watermark = new \Gmagick($imagePath);
        
        if ($watermarkWidth && $watermarkHeight &&
            ($watermarkWidth !== $watermarkSrcWidth ||
            $watermarkHeight !== $watermarkSrcHeight)
        ) {
            $watermark->resizeImage(
                $watermarkWidth,
                $watermarkHeight,
                \Gmagick::FILTER_LANCZOS,
                static::BLUR_FACTOR
            );
        }
        
        if ($opacity < 100 && extension_loaded('gd')) {
            // Gmagick cannot deal properly with transparency
            // so we use gd with blobs
            
            $gdWatermark = imagecreatefromstring($watermark->getimagesblob());
            
            imagesavealpha($gdWatermark, true);

            if (false !== imageistruecolor($gdWatermark)) {
                imagepalettetotruecolor($gdWatermark);
            }

            Gd2::filterOpacity($gdWatermark, $opacity);
            
            ob_start();
            imagepng($gdWatermark);
            $watermark = new \Gmagick;
            $watermark->readimageblob(ob_get_clean());
        }
        
        $watermark->writeImage($cachedFilename);
        
        self::$watermarks[$cachedFilename] = $watermark;
        return $watermark;
    }
    /**
     * Add watermark to image
     *
     * @param string $imagePath
     * @param int $positionX
     * @param int $positionY
     * @param int $opacity
     * @param bool $tile
     * @return void
     * @throws \LogicException
     * @throws \Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function watermark($imagePath, $positionX = 0, $positionY = 0, $opacity = 30, $tile = false)
    {
        $this->_checkCanProcess();
        
        $origOpacity = $this->getWatermarkImageOpacity() ? $this->getWatermarkImageOpacity() : $opacity;
        
        $watermark = $this->getWatermarkResource($imagePath, $origOpacity);

        switch ($this->getWatermarkPosition()) {
            case self::POSITION_STRETCH:
                $watermark->sampleImage($this->_imageSrcWidth, $this->_imageSrcHeight);
                break;
            case self::POSITION_CENTER:
                $positionX = ($this->_imageSrcWidth - $watermark->getImageWidth()) / 2;
                $positionY = ($this->_imageSrcHeight - $watermark->getImageHeight()) / 2;
                break;
            case self::POSITION_TOP_RIGHT:
                $positionX = $this->_imageSrcWidth - $watermark->getImageWidth();
                break;
            case self::POSITION_BOTTOM_RIGHT:
                $positionX = $this->_imageSrcWidth - $watermark->getImageWidth();
                $positionY = $this->_imageSrcHeight - $watermark->getImageHeight();
                break;
            case self::POSITION_BOTTOM_LEFT:
                $positionY = $this->_imageSrcHeight - $watermark->getImageHeight();
                break;
            case self::POSITION_TILE:
                $positionX = 0;
                $positionY = 0;
                $tile = true;
                break;
        }

        try {
            if ($tile) {
                $offsetX = $positionX;
                $offsetY = $positionY;
                while ($offsetY <= $this->_imageSrcHeight + $watermark->getImageHeight()) {
                    while ($offsetX <= $this->_imageSrcWidth + $watermark->getImageWidth()) {
                        $this->_imageHandler->compositeImage(
                            $watermark,
                            \Gmagick::COMPOSITE_OVER,
                            $offsetX,
                            $offsetY
                        );
                        $offsetX += $watermark->getImageWidth();
                    }
                    $offsetX = $positionX;
                    $offsetY += $watermark->getImageHeight();
                }
            } else {
                $this->_imageHandler->compositeImage(
                    $watermark,
                    \Gmagick::COMPOSITE_OVER,
                    $positionX,
                    $positionY
                );
            }
        } catch (\GmagickException $e) {
            throw new \Exception('Unable to create watermark.', $e->getCode(), $e);
        }
    }

    /**
     * Checks required dependencies
     *
     * @return void
     * @throws \Exception If some of dependencies are missing
     */
    public function checkDependencies()
    {
        if (!class_exists('\Gmagick', false)) {
            throw new \Exception("Required PHP extension 'Gmagick' was not loaded.");
        }
    }

    /**
     * Reassign image dimensions
     *
     * @return void
     */
    public function refreshImageDimensions()
    {
        $this->_imageSrcWidth = $this->_imageHandler->getImageWidth();
        $this->_imageSrcHeight = $this->_imageHandler->getImageHeight();
        $this->_imageHandler->setImagePage($this->_imageSrcWidth, $this->_imageSrcHeight, 0, 0);
    }

    /**
     * Standard destructor. Destroy stored information about image
     */
    public function __destruct()
    {
        $this->destroy();
    }

    /**
     * Destroy stored information about image
     *
     * @return $this
     */
    public function destroy()
    {
        if (null !== $this->_imageHandler && $this->_imageHandler instanceof \Gmagick) {
            $this->_imageHandler = null;
        }
        return $this;
    }

    /**
     * Returns rgba array of the specified pixel
     *
     * @param int $x
     * @param int $y
     * @return array
     */
    public function getColorAt($x, $y)
    {
        $pixel = $this->_imageHandler->getImagePixelColor($x, $y);

        $color = $pixel->getColor();
        $rgbaColor = [
            'red' => $color['r'],
            'green' => $color['g'],
            'blue' => $color['b'],
            'alpha' => (1 - $color['a']) * 127,
        ];
        return $rgbaColor;
    }

    /**
     * Check whether the adapter can work with the image
     *
     * @throws \LogicException
     * @return true
     */
    protected function _checkCanProcess()
    {
        if (!$this->_canProcess()) {
            throw new \LogicException(self::ERROR_WRONG_IMAGE);
        }
        return true;
    }

    /**
     * Create Image from string
     *
     * @param string $text
     * @param string $font
     * @return \Magento\Framework\Image\Adapter\AbstractAdapter
     */
    public function createPngFromString($text, $font = '')
    {
        $image = $this->_getGmagickObject();
        $draw = $this->_getGmagickDrawObject();
        $color = $this->_getGmagickPixelObject('#000000');
        $background = $this->_getGmagickPixelObject('#ffffff00');
        // Transparent

        if (!empty($font)) {
            if (method_exists($image, 'setFont')) {
                $image->setFont($font);
            } elseif (method_exists($draw, 'setFont')) {
                $draw->setFont($font);
            }
        }

        // Font size for ImageMagick is set in pixels, while the for GD2 it is in points. 3/4 is ratio between them
        $draw->setFontSize($this->_fontSize * 4 / 3);
        $draw->setFillColor($color);
        $draw->setStrokeAntialias(true);
        $draw->setTextAntialias(true);

        $metrics = $image->queryFontMetrics($draw, $text);

        $draw->annotation(0, $metrics['ascender'], $text);

        $height = abs($metrics['ascender']) + abs($metrics['descender']);
        $image->newImage($metrics['textWidth'], $height, $background);
        $this->_fileType = IMAGETYPE_PNG;
        $image->setImageFormat('png');
        $image->drawImage($draw);
        $this->_imageHandler = $image;

        return $this;
    }

    /**
     * Get Gmagick object
     *
     * @param mixed $files
     * @return \Gmagick
     */
    protected function _getGmagickObject($files = null)
    {
        return new \Gmagick($files);
    }

    /**
     * Get GmagickDraw object
     *
     * @return \GmagickDraw
     */
    protected function _getGmagickDrawObject()
    {
        return new \GmagickDraw();
    }

    /**
     * Get GmagickPixel object
     *
     * @param string|null $color
     * @return \GmagickPixel
     */
    protected function _getGmagickPixelObject($color = null)
    {
        return new \GmagickPixel($color);
    }
    
    protected function getSRGBProfileData()
    {
        if (empty(self::$profileData)) {
            self::$profileData = file_get_contents(__DIR__.'/sRGB_IEC61966-2-1_black_scaled.icc');
        }
        
        return self::$profileData;
    }
}
