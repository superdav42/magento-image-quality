<?php
/**
 * Copyright © David Stone. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace DevStone\ImageQuality\Image\Adapter;

/**
 * Override Gd2 to backport https://github.com/magento/magento2/pull/11060
 * and properly handle watermarks
 */
class Gd2 extends \Magento\Framework\Image\Adapter\Gd2
{
    
    /**
     * ICC header size in APP2 segment
     *
     * 'ICC_PROFILE' 0x00 chunk_no chunk_cnt
     */
    const ICC_HEADER_LEN = 14;

    /**
     * maximum data len of a JPEG marker
     */
    const MAX_BYTES_IN_MARKER = 65533;

    /**
     * ICC header marker
     */
    const ICC_MARKER = "ICC_PROFILE\x00";

    /**
     * Rendering intent field (Bytes 64 to 67 in ICC profile data)
     */
    const ICC_RI_PERCEPTUAL = 0x00000000;
    const ICC_RI_RELATIVE_COLORIMETRIC = 0x00000001;
    const ICC_RI_SATURATION = 0x00000002;
    const ICC_RI_ABSOLUTE_COLORIMETRIC = 0x00000003;
    /**
     * ICC profile data
     * @var     string
     */
    private $icc_profile = '';
    
    /**
     * ICC profile data size
     * @var     int
     */
    private $icc_size = 0;
    /**
     * ICC profile data chunks count
     * @var     int
     */
    private $icc_chunks = 0;
    
    private static $watermarks = [];
    
    /**
     * Image output callbacks by type
     *
     * @var array
     */
    private static $callbacks = [
        IMAGETYPE_GIF => ['output' => 'imagegif', 'create' => 'imagecreatefromgif'],
        IMAGETYPE_JPEG => ['output' => 'imagejpeg', 'create' => 'imagecreatefromjpeg'],
        IMAGETYPE_PNG => ['output' => 'imagepng', 'create' => 'imagecreatefrompng'],
        IMAGETYPE_XBM => ['output' => 'imagexbm', 'create' => 'imagecreatefromxbm'],
        IMAGETYPE_WBMP => ['output' => 'imagewbmp', 'create' => 'imagecreatefromxbm'],
    ];
    
    /**
     * For properties reset, e.g. mimeType caching.
     *
     * @return void
     */
    protected function _reset()
    {
        $this->icc_chunks = 0;
        $this->icc_size = 0;
        $this->icc_profile = '';
        parent::_reset();
    }
    
    /**
     * Open image for processing
     *
     * @param string $filename
     * @return void
     * @throws \OverflowException
     */
    public function open($filename)
    {
        parent::open($filename);
        
        $this->loadProfileFromJPEG($filename);
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

        if (!$this->_resized) {
            // keep alpha transparency
            $isAlpha = false;
            $isTrueColor = false;
            $this->getTransparency($this->_imageHandler, $this->_fileType, $isAlpha, $isTrueColor);
            if ($isAlpha) {
                if ($isTrueColor) {
                    $newImage = imagecreatetruecolor($this->_imageSrcWidth, $this->_imageSrcHeight);
                } else {
                    $newImage = imagecreate($this->_imageSrcWidth, $this->_imageSrcHeight);
                }
                $this->_fillBackgroundColor($newImage);
                imagecopy($newImage, $this->_imageHandler, 0, 0, 0, 0, $this->_imageSrcWidth, $this->_imageSrcHeight);
                $this->imageDestroy();
                $this->_imageHandler = $newImage;
            }
        }

        // Enable interlace
        imageinterlace($this->_imageHandler, true);

        // Set image quality value
        switch ($this->_fileType) {
            case IMAGETYPE_PNG:
                $quality = 9;   // For PNG files compression level must be from 0 (no compression) to 9.
                break;

            case IMAGETYPE_JPEG:
                $quality = $this->quality();
                break;

            default:
                $quality = null;    // No compression.
        }

        // Prepare callback method parameters
        $functionParameters = [$this->_imageHandler, $fileName];
        if ($quality) {
            $functionParameters[] = $quality;
        }

        call_user_func_array($this->getCallback('output'), $functionParameters);
        $this->saveProfileToJPEG($fileName);
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
        
        if (false !== ($watermark = @imagecreatefrompng($cachedFilename))) {
            self::$watermarks[$cachedFilename] = $watermark;
            return $watermark;
        }
        
        list($watermarkSrcWidth, $watermarkSrcHeight, $watermarkFileType) = $this->_getImageOptions($imagePath);
        
        $watermark = call_user_func(
            $this->getCallback('create', $watermarkFileType, 'Unsupported watermark image format.'),
            $imagePath
        );
        
        imagesavealpha($watermark, true);
        
        if (false !== imageistruecolor($watermark)) {
            imagepalettetotruecolor($watermark);
        }
        
        if ($watermarkWidth && $watermarkHeight &&
            ($watermarkWidth !== $watermarkSrcWidth ||
            $watermarkHeight !== $watermarkSrcHeight)
        ) {
            $newWatermark = imagecreatetruecolor($watermarkWidth, $watermarkHeight);
            imagealphablending($newWatermark, false);
            imagesavealpha($newWatermark, true);
            if (function_exists('imageantialias')) {
                imageantialias($newWatermark, true);
            }
            $transparent = imagecolorallocatealpha($newWatermark, 255, 255, 255, 127);
            imagefill($newWatermark, 0, 0, $transparent);
            imagecolortransparent($newWatermark, $transparent);

            imagecopyresampled(
                $newWatermark,
                $watermark,
                0,
                0,
                0,
                0,
                $this->getWatermarkWidth(),
                $this->getWatermarkHeight(),
                imagesx($watermark),
                imagesy($watermark)
            );
            $watermark = $newWatermark;
        }
        
        if ($opacity < 100) {
            static::filterOpacity($watermark, $opacity);
        }
        
        imagepng($watermark, $cachedFilename);
        
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function watermark($imagePath, $positionX = 0, $positionY = 0, $opacity = 30, $tile = false)
    {
        $origOpacity = $this->getWatermarkImageOpacity() ? $this->getWatermarkImageOpacity() : $opacity;
        
        $watermark = $this->getWatermarkResource($imagePath, $origOpacity);

        if ($this->getWatermarkPosition() == self::POSITION_TILE || $tile) {
            $offsetX = $positionX;
            $offsetY = $positionY;
            while ($offsetY <= $this->_imageSrcHeight + imagesy($watermark)) {
                while ($offsetX <= $this->_imageSrcWidth + imagesx($watermark)) {
                    imagecopy(
                        $this->_imageHandler,
                        $watermark,
                        $offsetX,
                        $offsetY,
                        0,
                        0,
                        imagesx($watermark),
                        imagesy($watermark)
                    );
                    $offsetX += imagesx($watermark);
                }
                $offsetX = $positionX;
                $offsetY += imagesy($watermark);
            }
        } else {
            if ($this->getWatermarkPosition() == self::POSITION_STRETCH) {
                $positionX = $positionY = 0;
            } elseif ($this->getWatermarkPosition() == self::POSITION_CENTER) {
                $positionX = $this->_imageSrcWidth / 2 - imagesx($watermark) / 2;
                $positionY = $this->_imageSrcHeight / 2 - imagesy($watermark) / 2;
            } elseif ($this->getWatermarkPosition() == self::POSITION_TOP_RIGHT) {
                $positionX = $this->_imageSrcWidth - imagesx($watermark);
            } elseif ($this->getWatermarkPosition() == self::POSITION_TOP_LEFT) {
                $positionX = $positionY = 0;
            } elseif ($this->getWatermarkPosition() == self::POSITION_BOTTOM_RIGHT) {
                $positionX = $this->_imageSrcWidth - imagesx($watermark);
                $positionY = $this->_imageSrcHeight - imagesy($watermark);
            } elseif ($this->getWatermarkPosition() == self::POSITION_BOTTOM_LEFT) {
                $positionY = $this->_imageSrcHeight - imagesy($watermark);
            }
            
            imagecopy(
                $this->_imageHandler,
                $watermark,
                $positionX,
                $positionY,
                0,
                0,
                imagesx($watermark),
                imagesy($watermark)
            );
        }
        
        $this->refreshImageDimensions();
    }
    
    /**
     * Obtain function name, basing on image type and callback type
     *
     * @param string $callbackType
     * @param null|int $fileType
     * @param string $unsupportedText
     * @return string
     * @throws \Exception
     */
    private function getCallback($callbackType, $fileType = null, $unsupportedText = 'Unsupported image format.')
    {
        if (null === $fileType) {
            $fileType = $this->_fileType;
        }
        if (empty(self::$callbacks[$fileType])) {
            throw new \Exception($unsupportedText);
        }
        if (empty(self::$callbacks[$fileType][$callbackType])) {
            throw new \Exception('Callback not found.');
        }
        return self::$callbacks[$fileType][$callbackType];
    }
    
    
    /**
     * Checks if image has alpha transparency
     *
     * @param resource $imageResource
     * @param int $fileType one of the constants IMAGETYPE_*
     * @param bool &$isAlpha
     * @param bool &$isTrueColor
     * @return boolean
     * @SuppressWarnings(PHPMD.BooleanGetMethodName)
     */
    private function getTransparency($imageResource, $fileType, &$isAlpha = false, &$isTrueColor = false)
    {
        $isAlpha = false;
        $isTrueColor = false;
        // assume that transparency is supported by gif/png only
        if (IMAGETYPE_GIF === $fileType || IMAGETYPE_PNG === $fileType) {
            // check for specific transparent color
            $transparentIndex = imagecolortransparent($imageResource);
            if ($transparentIndex >= 0) {
                return $transparentIndex;
            } elseif (IMAGETYPE_PNG === $fileType) {
                // assume that truecolor PNG has transparency
                $isAlpha = $this->checkAlpha($this->_fileName);
                $isTrueColor = true;
                // -1
                return $transparentIndex;
            }
        }
        if (IMAGETYPE_JPEG === $fileType) {
            $isTrueColor = true;
        }
        return false;
    }
    
    /**
     * Modify opacity of image
     * statis so GraphicsMagick adapter can use it
     *
     * @param resource $img gd image resource id,
     * @param int      $opacity  opacity in percentage (eg. 80)
     * @return boolean success
     */
    public static function filterOpacity($img, $opacity) //params:
    {
        if (!isset($opacity)) {
            return false;
        }
        $opacity /= 100;

        //get image width and height
        $w = imagesx($img);
        $h = imagesy($img);

        //turn alpha blending off
        imagealphablending($img, false);

        //loop through image pixels and modify alpha for each
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                //get current alpha value (represents the TANSPARENCY!)
                $colorxy = imagecolorat($img, $x, $y);
                $alpha = ( $colorxy >> 24 ) & 0xFF;
                //calculate new alpha

                $alpha = 127 + $opacity * ( $alpha - 127 );
                //get the color index with new alpha
                $alphacolorxy = imagecolorallocatealpha(
                    $img,
                    ( $colorxy >> 16 ) & 0xFF,
                    ( $colorxy >> 8 ) & 0xFF,
                    $colorxy & 0xFF,
                    $alpha
                );
                //set pixel with the new color + opacity
                if (!imagesetpixel($img, $x, $y, $alphacolorxy)) {
                    return false;
                }
            }
        }
        return true;
    }
    
    /**
     * Load ICC profile from JPEG file.
     *
     * Returns true if profile successfully loaded, false otherwise.
     *
     * @param       string      file name
     * @return      bool
     */
    private function loadProfileFromJPEG($fname)
    {
        $f = file_get_contents($fname);
        $len = strlen($f);
        $pos = 0;
        $counter = 0;
        $profile_chunks = [];

        while ($pos < $len && $counter < 1000) {
            $pos = strpos($f, "\xff", $pos);
            if ($pos === false) {
                break; // dalsie 0xFF sa uz nenaslo - koniec vyhladavania
            }

            $type = $this->getJPEGSegmentType($f, $pos);
            switch ($type) {
                case 0xe2: // APP2
                    $size = $this->getJPEGSegmentSize($f, $pos);
                    
                    if ($this->getJPEGSegmentContainsICC($f, $pos, $size)) {
                        list($chunk_no, $chunk_cnt) = $this->getJPEGSegmentICCChunkInfo($f, $pos);

                        if ($chunk_no <= $chunk_cnt) {
                            $profile_chunks[$chunk_no] = $this->getJPEGSegmentICCChunk($f, $pos);

                            if ($chunk_no == $chunk_cnt) {
                                ksort($profile_chunks);
                                $this->setProfile(implode('', $profile_chunks));
                                return true;
                            }
                        }
                    }
                    $pos += $size + 2; // size of segment data + 2B size of segment marker
                    break;

                case 0xe0: // APP0
                case 0xe1: // APP1
                case 0xe3: // APP3
                case 0xe4: // APP4
                case 0xe5: // APP5
                case 0xe6: // APP6
                case 0xe7: // APP7
                case 0xe8: // APP8
                case 0xe9: // APP9
                case 0xea: // APP10
                case 0xeb: // APP11
                case 0xec: // APP12
                case 0xed: // APP13
                case 0xee: // APP14
                case 0xef: // APP15
                case 0xc0: // SOF0
                case 0xc2: // SOF2
                case 0xc4: // DHT
                case 0xdb: // DQT
                case 0xda: // SOS
                case 0xfe: // COM
                    $size = $this->getJPEGSegmentSize($f, $pos);
                    $pos += $size + 2; // size of segment data + 2B size of segment marker
                    break;

                default:
                    $pos += 2;
                    break;
            }
            $counter++;
        }

        return false;
    }
    
    /**
     * Save previously loaded ICC profile into JPEG file.
     *
     * @param       string      JPEG file name
     */
    private function saveProfileToJPEG($fname)
    {
        if ($this->icc_profile == '') {
            return;
        }
        if (!file_exists($fname)) {
            return;
        }
        if (!is_readable($fname)) {
            return;
        }

        $f = file_get_contents($fname);
        if ($this->insertProfile($f)) {
            file_put_contents($fname, $f);
        }
    }
    
    /**
     * Size of JPEG segment
     *
     * @param       string      file data
     * @param       int         start of segment
     * @return      int
     */
    private function getJPEGSegmentSize(&$f, $pos)
    {
        $arr = unpack('nint', substr($f, $pos + 2, 2)); // segment size has offset 2 and length 2B
        return $arr['int'];
    }

    /**
     * Type of JPEG segment
     *
     * @param       string      file data
     * @param       int         start of segment
     * @return      int
     */
    private function getJPEGSegmentType(&$f, $pos)
    {
        $arr = unpack('Cchar', substr($f, $pos + 1, 1)); // segment type has offset 1 and length 1B
        return $arr['char'];
    }

    /**
     * Check if segment contains ICC profile marker
     *
     * @param       string      file data
     * @param       int         position of segment data
     * @param       int         size of segment data (without 2 bytes of size field)
     * @return      bool
     */
    private function getJPEGSegmentContainsICC(&$f, $pos, $size)
    {
        if ($size < self::ICC_HEADER_LEN) {
            return false; // ICC_PROFILE 0x00 Marker_no Marker_cnt
        }
        
        // 4B offset in segment data = 2B segment marker + 2B segment size data
        return (bool) (substr($f, $pos + 4, self::ICC_HEADER_LEN - 2) == self::ICC_MARKER);
    }

    /**
     * Get ICC segment chunk info
     *
     * @param       string      file data
     * @param       int         position of segment data
     * @return      array       {chunk_no, chunk_cnt}
     */
    private function getJPEGSegmentICCChunkInfo(&$f, $pos)
    {
        // 16B offset to data = 2B segment marker + 2B segment size + 'ICC_PROFILE' + 0x00,
        // 1. byte chunk number, 2. byte chunks count
        $a = unpack('Cchunk_no/Cchunk_count', substr($f, $pos + 16, 2));
        return array_values($a);
    }

    /**
     * Returns chunk of ICC profile data from segment.
     *
     * @param       string      &data
     * @param       int         current position
     * @return      string
     */
    private function getJPEGSegmentICCChunk(&$f, $pos)
    {
        $data_offset = $pos + 4 + self::ICC_HEADER_LEN; // 4B JPEG APP offset + 14B ICC header offset
        $size = $this->getJPEGSegmentSize($f, $pos);
        $data_size = $size - self::ICC_HEADER_LEN - 2; // 14B ICC header - 2B of size data
        return substr($f, $data_offset, $data_size);
    }

    /**
     * Inserts profile to JPEG data.
     *
     * Inserts profile immediately after SOI section
     *
     * @param       string      &data
     * @return      bool
     */
    private function insertProfile(&$jpeg_data)
    {
        $len = strlen($jpeg_data);
        $pos = 0;
        $counter = 0;

        while ($pos < $len && $counter < 100) {
            $pos = strpos($jpeg_data, "\xff", $pos);
            if ($pos === false) {
                break; // no more 0xFF - we can end up with search
            }

            // analyze next segment
            $type = $this->getJPEGSegmentType($jpeg_data, $pos);

            switch ($type) {
                case 0xd8: // SOI
                    $pos += 2;
                    
                    $p_data = $this->prepareJPEGProfileData();
                    if ($p_data != '') {
                        $before = substr($jpeg_data, 0, $pos);
                        $after = substr($jpeg_data, $pos);
                        $jpeg_data = $before . $p_data . $after;
                        return true;
                    }
                    return false;

                case 0xe0: // APP0
                case 0xe1: // APP1
                case 0xe2: // APP2
                case 0xe3: // APP3
                case 0xe4: // APP4
                case 0xe5: // APP5
                case 0xe6: // APP6
                case 0xe7: // APP7
                case 0xe8: // APP8
                case 0xe9: // APP9
                case 0xea: // APP10
                case 0xeb: // APP11
                case 0xec: // APP12
                case 0xed: // APP13
                case 0xee: // APP14
                case 0xef: // APP15
                case 0xc0: // SOF0
                case 0xc2: // SOF2
                case 0xc4: // DHT
                case 0xdb: // DQT
                case 0xda: // SOS
                case 0xfe: // COM
                    $size = $this->getJPEGSegmentSize($jpeg_data, $pos);
                    $pos += $size + 2; // size of segment data + 2B size of segment marker
                    break;

                default:
                    $pos += 2;
                    break;
            }
            $counter++;
        }

        return false;
    }
    /**
     * Set profile directly
     *
     * @param       string      profile data
     */
    private function setProfile($data)
    {
        $this->icc_profile = $data;
        $this->icc_size = strlen($data);
        $this->countChunks();
    }
    
    /**
     * Count in how many chunks we need to divide the profile to store it in JPEG APP2 segments
     */
    private function countChunks()
    {
        $this->icc_chunks = ceil($this->icc_size / ((float) (self::MAX_BYTES_IN_MARKER - self::ICC_HEADER_LEN)));
    }
    
    /**
     * Prepare all data needed to be inserted into JPEG file to add ICC profile.
     *
     * @return      string
     */
    private function prepareJPEGProfileData()
    {
        $data = '';

        for ($i = 1; $i <= $this->icc_chunks; $i++) {
            $chunk = $this->getChunk($i);
            $chunk_size = strlen($chunk);
            $data .= "\xff\xe2" . pack('n', $chunk_size + 2 + self::ICC_HEADER_LEN); // APP2 segment marker + size field
            $data .= self::ICC_MARKER . pack('CC', $i, $this->icc_chunks); // profile marker inside segment
            $data .= $chunk;
        }

        return $data;
    }
    
    /**
     * Get data of given chunk
     *
     * @param       int         chunk number
     * @return      string
     */
    private function getChunk($chunk_no)
    {
        if ($chunk_no > $this->icc_chunks) {
            return '';
        }
        
        $max_chunk_size = self::MAX_BYTES_IN_MARKER - self::ICC_HEADER_LEN;
        $from = ($chunk_no - 1) * $max_chunk_size;
        $bytes = ($chunk_no < $this->icc_chunks) ? $max_chunk_size : $this->icc_size % $max_chunk_size;

        return substr($this->icc_profile, $from, $bytes);
    }
}
