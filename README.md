# Magento Image Quality
Improve image quality of product images in Magento 2

# Overview
The purpose of this extension is to give store owner’s control over how their product images are processed and presented to the customer.

It is designed to require minimal configuration with the defaults being what most users will need.

Magento has made assumptions about how images should be processed and they do not produce the best results for many users, such as hard coding JPEG image quality to 80. There are also several bugs in Magento core related to image processing that this extension fixes.

Features
* Add config options for setting JPEG quality for all product image sizes
* Add config option to enable image frame
* Add GraphicsMagick Image Adapter, provides a 10-50% performance increase over ImageMagick
* Fix core bug related to handling watermarks with transparency
* Performance improvements in GD and ImageMagick adapters
* Improve resize filter in ImageMagick adapter
* Preserve correct colors when embedded color profiles are used
