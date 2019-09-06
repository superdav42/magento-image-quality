<?php
/**
 * Copyright © David Stone. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace DevStone\ImageQuality\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * @codeCoverageIgnore
 */
class InstallData implements InstallDataInterface
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $config;
    
    /**
     * @var \Magento\Framework\Image\AdapterFactory
     */
    private $adapterFactory;
    
    /**
     *
     * @var \Magento\Catalog\Model\Product\ImageFactory
     */
    private $imageFactory;

	/**
	 * 
	 * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
	 * @param \Magento\Framework\Image\AdapterFactory $adapterFactory
	 * @param \Magento\Catalog\Model\Product\ImageFactory $imageFactory
	 */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\Image\AdapterFactory $adapterFactory,
        \Magento\Catalog\Model\Product\ImageFactory $imageFactory
    ) {
        $this->config = $config;
        $this->adapterFactory = $adapterFactory;
        $this->imageFactory = $imageFactory;

    }

    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $currentAdapter = (string) $this->config->getValue(
            \Magento\Framework\Image\Adapter\Config::XML_PATH_IMAGE_ADAPTER
        );
        
        $preferredAdapters = [
            'GRAPHICSMAGICK', // Gmagick first because it's faster and supports all features
            'IMAGEMAGICK',    // Imagick second because it makes better images but slower
            'GD2',            // Because we want to make sure a supported adapter is enabled
        ];
		
        foreach ($preferredAdapters as $adapterAlias) {
            try {
                
				$adapter = $this->adapterFactory->create($adapterAlias);
                $adapter->checkDependencies(); // throws exception if not supported
                
				if ($adapterAlias !== $currentAdapter) {

					$setup->updateTableRow(
						'core_config_data', // table name 
						'path',             // id field
						\Magento\Framework\Image\Adapter\Config::XML_PATH_IMAGE_ADAPTER, // row id
						'value',			// field to update
						$adapterAlias		// value to update field with
					);
					
					if (method_exists($this->config, 'clean')) {
						$this->config->clean();
					}
                }
                break;
				
            } catch (\Exception $e) {
                // not supported
				continue;
            }
        }
        
        // Clear Image cache so new images are automatically generated with new settings
        $this->imageFactory->create()->clearCache();
    }
}
