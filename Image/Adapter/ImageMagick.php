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
class ImageMagick extends \Magento\Framework\Image\Adapter\ImageMagick
{

    /**
     * The blur factor where > 1 is blurry, < 1 is sharp
     */
    const BLUR_FACTOR = 1;

    /**
     *
     * @var string cache for sRGB color profile
     */
    private static $profileData;

    /**
     * Options Container
     *
     * @var array
     */
    protected $_options = [
        'resolution' => ['x' => 300, 'y' => 300],
        'small_image' => ['width' => 300, 'height' => 300],
        'sharpen' => ['radius' => 4, 'deviation' => 1],
    ];

    /**
     *
     * @var array cache for watermark resources
     */
    private static $watermarks = [];

    private function getSRGBProfileData()
    {
        if (empty(self::$profileData)) {
            self::$profileData = file_get_contents(__DIR__.'/sRGB_IEC61966-2-1_black_scaled.icc');
        }
        return self::$profileData;
    }

    public function open($filename)
    {
        parent::open($filename);

        $this->_imageHandler->profileImage('icc', $this->getSRGBProfileData());
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

        $this->_imageHandler->resizeImage(
            $dims['dst']['width'],
            $dims['dst']['height'],
            \Imagick::FILTER_LANCZOS,
            static::BLUR_FACTOR
        );

        if ($dims['dst']['width'] !== $dims['frame']['width'] ||
            $dims['dst']['height'] !== $dims['frame']['height']
        ) {
            $this->_imageHandler->extentImage(
                $dims['frame']['width'],
                $dims['frame']['height'],
                -$dims['dst']['x'],
                -$dims['dst']['y']
            );
        }

        $this->refreshImageDimensions();
    }

    protected function _applyOptions()
    {
        parent::_applyOptions();

        $this->_imageHandler->setInterlaceScheme(\Imagick::INTERLACE_LINE);
    }

    private function getWatermarkResource($imagePath, $opacity)
    {
        $pathinfo = pathinfo($imagePath);
        $suffix = '-tmp';

        if ($this->getWatermarkPosition() === self::POSITION_STRETCH) {
            $watermarkWidth = $this->_imageSrcWidth;
            $watermarkHeight = $this->_imageSrcHeight;
        } else {
            $watermarkWidth = $this->getWatermarkWidth();
            $watermarkHeight = $this->getWatermarkHeight();
        }

        if ($watermarkWidth && $watermarkHeight) {
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
            return self::$watermarks[$cachedFilename] = $this->_getImagickObject($cachedFilename);
        } catch (\ImagickException $e) {
            // file does not exist continue
        }

        if (empty($imagePath) || !file_exists($imagePath)) {
            throw new \LogicException(self::ERROR_WATERMARK_IMAGE_ABSENT);
        }

        list($watermarkSrcWidth, $watermarkSrcHeight, $watermarkFileType) = $this->_getImageOptions($imagePath);

        $watermark = $this->_getImagickObject($imagePath);

        if ($watermarkWidth && $watermarkHeight &&
            ($watermarkWidth !== $watermarkSrcWidth ||
            $watermarkHeight !== $watermarkSrcHeight)
        ) {
            $watermark->resizeImage(
                $watermarkWidth,
                $watermarkHeight,
                \Imagick::FILTER_LANCZOS,
                static::BLUR_FACTOR
            );
        }

        if ($opacity < 100) {
            if (method_exists($watermark, 'getImageAlphaChannel') && $watermark->getImageAlphaChannel() == 0) {
                // available from imagick 6.4.0
                $watermark->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
            }
            $watermark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, round($opacity / 100, 1), \Imagick::CHANNEL_OPACITY);
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
        if (empty($imagePath) || !file_exists($imagePath)) {
            throw new \LogicException(self::ERROR_WATERMARK_IMAGE_ABSENT);
        }

        $this->_checkCanProcess();

        $origOpacity = $this->getWatermarkImageOpacity() ? $this->getWatermarkImageOpacity() : $opacity;

        $watermark = $this->getWatermarkResource($imagePath, $origOpacity);

        $this->_imageHandler->setImageAlphaChannel( 11 /*\Imagick::ALPHACHANNEL_FLATTEN - a bug makes this undefined in some installations even though 11 works*/);

        $compositeChannels = \Imagick::CHANNEL_ALL;
        $compositeChannels &= ~(\Imagick::CHANNEL_OPACITY);

        switch ($this->getWatermarkPosition()) {
            case self::POSITION_STRETCH:
                $positionX = 0;
                $positionY = 0;
                break;
            case self::POSITION_CENTER:
                $positionX = floor(($this->_imageSrcWidth - $watermark->getImageWidth()) / 2);
                $positionY = floor(($this->_imageSrcHeight - $watermark->getImageHeight()) / 2);
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
                            \Imagick::COMPOSITE_OVER,
                            $offsetX,
                            $offsetY,
                            $compositeChannels
                        );
                        $offsetX += $watermark->getImageWidth();
                    }
                    $offsetX = $positionX;
                    $offsetY += $watermark->getImageHeight();
                }
            } else {
                $this->_imageHandler->compositeImage(
                    $watermark,
                    \Imagick::COMPOSITE_OVER,
                    $positionX,
                    $positionY,
                    $compositeChannels
                );
            }
        } catch (\ImagickException $e) {
            throw new \Exception('Unable to create watermark.', $e->getCode(), $e);
        }
    }


    /**
     * Save image to specific path.
     *
     * If some folders of path does not exist they will be created
     *
     * @param null|string $destination
     * @param null|string $newName
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException If destination path is not writable
     */
    public function save($destination = null, $newName = null)
    {
        $fileName = $this->_prepareDestination($destination, $newName);

        $this->_applyOptions();
        if ( ! $this->_quality || $this->_quality < 95 ) {
            $this->_imageHandler->stripImage();
        }
        $this->_imageHandler->writeImage($fileName);
    }
}
