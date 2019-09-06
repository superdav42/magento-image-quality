<?php
/**
 * Copyright © David Stone. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace DevStone\ImageQuality\Helper;

class Image extends \Magento\Catalog\Helper\Image
{
    /**
     * Override to properly check for null
     * and add support for setting quality.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string $imageId
     * @param array $attributes
     * @return Image
     */
    public function init($product, $imageId, $attributes = [])
    {
        
        $return = parent::init($product, $imageId, $attributes);
        
        $model = $this->_getModel();
        
        // Set 'keep frame' flag
        $frame = $this->getFrame();
        if (null !== $frame) {
            $model->setKeepFrame($frame);
        }

        // Set 'constrain only' flag
        $constrain = $this->getAttribute('constrain');
        if (null !== $constrain) {
            $model->setConstrainOnly($constrain);
        }

        // Set 'keep aspect ratio' flag
        $aspectRatio = $this->getAttribute('aspect_ratio');
        if (null !== $aspectRatio) {
            $model->setKeepAspectRatio($aspectRatio);
        }

        // Set 'transparency' flag
        $transparency = $this->getAttribute('transparency');
        if (null !== $transparency) {
            $model->setKeepTransparency($transparency);
        }
        
        // Set quality
        $quality = $this->getQuality();
        if (!empty($quality)) {
            $model->setQuality($quality);
        }
        
        return $return;
    }

    /**
     * Retrieve image frame flag
     *
     * @return false|string
     */
    public function getFrame()
    {
        $frame = $this->getAttribute('frame');
        
        if (null === $frame) {
            $frame = $this->scopeConfig->getValue(
                "design/image/keep_frame",
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            if ('theme' === $frame) {
                $frame = $this->getConfigView()->getVarValue('Magento_Catalog', 'product_image_white_borders');
            }
        }
        return $frame;
    }
    /**
     * Gets quality from attribute or config.
     *
     * @return int|null
     */
    private function getQuality()
    {
        $quality = $this->getAttribute('quality');
        if (empty($quality)) {
            $quality = $this->scopeConfig->getValue(
                "design/image/{$this->getType()}_quality",
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            if (empty($quality)) {
                $quality = $this->scopeConfig->getValue(
                    "design/image/custom_quality",
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );
            }
        }

        return $quality;
    }
}
