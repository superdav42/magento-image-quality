<?php
namespace DevStone\ImageQuality\Model\Product;

use Magento\Catalog\Model\Product\Image as ProductImage;

use Magento\Catalog\Model\View\Asset\ImageFactory;
use Magento\Catalog\Model\View\Asset\PlaceholderFactory;
use Magento\Catalog\Model\Product\Image\ParamsBuilder;
use Magento\Framework\Serialize\SerializerInterface;

class Image extends ProductImage
{
    /**
     * @var string
     */
    private string $cachePrefix = 'IMG_INFO';

    private \Magento\Framework\View\Asset\LocalInterface $imageAsset;


    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\Product\Media\Config $catalogProductMediaConfig,
        \Magento\MediaStorage\Helper\File\Storage\Database $coreFileStorageDatabase,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Image\Factory $imageFactory,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\View\FileSystem $viewFileSystem,
        private readonly ImageFactory $viewAssetImageFactory,
        private readonly PlaceholderFactory $viewAssetPlaceholderFactory,
        private readonly ParamsBuilder $paramsBuilder,
        private readonly SerializerInterface $serializer,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = [],
    ) {
        parent::__construct(
            $context,
            $registry,
            $storeManager,
            $catalogProductMediaConfig,
            $coreFileStorageDatabase,
            $filesystem,
            $imageFactory,
            $assetRepo,
            $viewFileSystem,
            $viewAssetImageFactory,
            $viewAssetPlaceholderFactory,
            $scopeConfig,
            $resource,
            $resourceCollection,
            $data,
            $serializer,
            $paramsBuilder
        );
    }

    public function setBaseFile($file)
    {
        $this->_isBaseFilePlaceholder = false;

        $this->imageAsset = $this->viewAssetImageFactory->create(
            [
                'miscParams' => $this->getMiscParams(),
                'filePath' => $file,
            ]
        );
        if ($file == 'no_selection' || !$this->_fileExists($this->imageAsset->getSourceFile())) {
            $this->_isBaseFilePlaceholder = true;
            $this->imageAsset = $this->viewAssetPlaceholderFactory->create(
                [
                    'type' => $this->getDestinationSubdir(),
                ]
            );
        }

        $this->_baseFile = $this->imageAsset->getSourceFile();

        return $this;
    }
    private function getMiscParams()
    {
        $builtParams = $this->paramsBuilder->build([
            'type' => $this->getDestinationSubdir(),
            'width' => $this->getWidth(),
            'height' => $this->getHeight(),
            'frame' => $this->_keepFrame,
            'constrain' => $this->_constrainOnly,
            'aspect_ratio' => $this->_keepAspectRatio,
            'transparency' => $this->_keepTransparency,
            'background' => $this->_backgroundColor,
            'angle' => $this->_angle,
            'quality' => $this->getQuality()
        ]);

        // These lines are why this whole class is overwritten.
        // A consistent hash is not maintained if the watermark params are left here
        if (empty($this->_watermarkFile)) {
            unset($builtParams['watermark_file']);
            unset($builtParams['watermark_image_opacity']);
            unset($builtParams['watermark_position']);
            unset($builtParams['watermark_width']);
            unset($builtParams['watermark_height']);
        }
        $builtParams['quality'] = $this->getQuality();
        return $builtParams;
    }

    /**
     * Save file
     *
     * @return $this
     */
    public function saveFile()
    {
        if ($this->_isBaseFilePlaceholder) {
            return $this;
        }
        $filename = $this->getBaseFile() ? $this->imageAsset->getPath() : null;
        $this->getImageProcessor()->save($filename);
        $this->_coreFileStorageDatabase->saveFile($filename);
        return $this;
    }

    /**
     * Get url
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->imageAsset->getUrl();
    }

    /**
     * Check is image cached
     *
     * @return bool
     */
    public function isCached()
    {
        $path = $this->imageAsset->getPath();
        return is_array($this->loadImageInfoFromCache($path)) || $this->_mediaDirectory->isExist($path);
    }

    /**
     * Return resized product image information
     *
     * @return array
     * @throws NotLoadInfoImageException
     */
    public function getResizedImageInfo()
    {
        try {
            $image = null;
            if ($this->isBaseFilePlaceholder() == true) {
                $image = $this->imageAsset->getSourceFile();
            } else {
                $image = $this->imageAsset->getPath();
            }

            $imageProperties = $this->getImageSize($image);

            return $imageProperties;
        } finally {
            if (empty($imageProperties)) {
                throw new NotLoadInfoImageException(__('Can\'t get information about the picture: %1', $image));
            }
        }
    }
    /**
     * Get image size
     *
     * @param string $imagePath
     * @return array
     */
    private function getImageSize($imagePath)
    {
        $imageInfo = $this->loadImageInfoFromCache($imagePath);
        if (!isset($imageInfo['size'])) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $imageSize = getimagesize($imagePath);
            $this->saveImageInfoToCache(['size' => $imageSize], $imagePath);
            return $imageSize;
        } else {
            return $imageInfo['size'];
        }
    }

    /**
     * Save image data to cache
     *
     * @param array $imageInfo
     * @param string $imagePath
     * @return void
     */
    private function saveImageInfoToCache(array $imageInfo, string $imagePath)
    {
        $imagePath = $this->cachePrefix . $imagePath;
        $this->_cacheManager->save(
            $this->serializer->serialize($imageInfo),
            $imagePath,
            [$this->cachePrefix]
        );
    }

    /**
     * Load image data from cache
     *
     * @param string $imagePath
     * @return array|false
     */
    private function loadImageInfoFromCache(string $imagePath)
    {
        $imagePath = $this->cachePrefix . $imagePath;
        $cacheData = $this->_cacheManager->load($imagePath);
        if (!$cacheData) {
            return false;
        } else {
            return $this->serializer->unserialize($cacheData);
        }
    }
}
