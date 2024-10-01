<?php
/**
 * Copyright © David Stone. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace DevStone\ImageQuality\Plugins;

class ParamsBuilderPlugin
{
    public function afterBuild(
        \Magento\Catalog\Model\Product\Image\ParamsBuilder $subject,
        array $result,
        array $imageArguments,
        int $scopeId = null) {
        if ( isset($imageArguments['watermark']) && 'false' === $imageArguments['watermark'] ) {
            unset($result['watermark_file']);
            unset($result['watermark_image_opacity']);
            unset($result['watermark_position']);
            unset($result['watermark_width']);
            unset($result['watermark_height']);
        }
        return $result;
    }
}
