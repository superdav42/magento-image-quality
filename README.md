
Image Quality User Guide v1.0.0
======

Introduction
------
The purpose of this extension is to give store owner's control over how their product images
are processed and presented to the customer. It is designed to require minimal configuration with the
defaults being what most users will need.

Magento has made assumptions about how images should be processed and they do not produce the best results 
for many users, such as hard coding JPEG image quality to 80. There are also several bugs in Magento core related to 
image processing that this extension fixes. 

Installation
-------
Use composer
```
composer require devstone/magento2-module-imagequality
```
or if your really want copy all files in this repository into:
```
app/code/DevStone/ImageQuality
```

Features
------
* Add config options for setting JPEG quality for all product image sizes
* Add config option to enable image frame
* Add [GraphicsMagick](http://www.graphicsmagick.org/) Image Adapter, provides a 10-50% performance increase over ImageMagick
* Fix [core bug](https://github.com/magento/magento2/issues/10661) related to handling watermarks with transparency 
* Performance improvements in GD and ImageMagick adapters
* Improve resize filter in ImageMagick adapter
* Preserve correct colors when embedded [color profiles](http://www.color.org) are used

Configuration
------
In the Admin menu select **Stores** ⟶ **Settings** ⟶ **Configuration** 
then choose **DevStone Extensions** ⟶ **Image Quality**

From here you can set the image quality for the various image sizes. 
A higher number produces better quality images but larger file sizes. The optimum quality setting depends on the content of the images.
* 92 is the highest recommended setting as higher values will not be noticeably different but will create a drastic increase in file size.
* 85 generally produces good file sizes with almost no loss in quality.
* 75 is the lowest recommended setting and will likely produce noticeable differences from the original.

Of course you can use any number between 1-100 or leave it at the default which for most images will be better than Magento's 
default of 80.

GraphicsMagick
------
This extension adds support for the Gmagick PHP extension which is more efficient than Imagick but still has all the same features that
Magento needs. The [Gmagick PHP extension](https://pecl.php.net/package/gmagick) must be installed and active on the server
Magento is running before it can be used. It can be found on pecl and is most Linux distributions package manager.
If it is available at the time this extension is installed it will be automatically enabled.
To enable it later or to switch to GD or ImageMagick in the Admin menu select **Stores** ⟶ **Settings** ⟶ **Configuration** 
then choose **Advanced** ⟶ **Developer**. Find **Image Processing Settings** and choose the desired **Adapter**.


Changelog
------
### 1.0.0 ###
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

Future Plans
------
* webp support
* Allow uploading of images larger than 1920x1200
* Custom resize and sharpen filters
* Support for image optimization tools like mozjpeg, jpegrescan or jpegtran
