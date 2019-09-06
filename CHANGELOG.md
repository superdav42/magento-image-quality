1.0.0
=============
Initial Release
* Add config options for setting JPEG quality for all product image sizes
* Add config option to enable image frame
* Fix bug in core so <product_image_white_borders> tag in view.xml will be respected unless overridden in extension config
* Add support for quality tag in theme's view.xml
* Add GraphicsMagick Image Adapter
* Fix handling of watermarks with transparency
* Performance improvements in GD and ImageMagick adapters
* Improve resize filter in ImageMagick adapter
* Preserve correct colors when embedded color profiles are used