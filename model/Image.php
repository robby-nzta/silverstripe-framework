<?php

/**
 * Represents an Image
 *
 * @package framework
 * @subpackage filesystem
 */
class Image extends File implements Flushable {

	const ORIENTATION_SQUARE = 0;
	const ORIENTATION_PORTRAIT = 1;
	const ORIENTATION_LANDSCAPE = 2;

	private static $backend = "GDBackend";

	private static $casting = array(
		'Tag' => 'HTMLText',
	);

	/**
	 * @config
	 * @var int The width of an image thumbnail in a strip.
	 */
	private static $strip_thumbnail_width = 50;

	/**
	 * @config
	 * @var int The height of an image thumbnail in a strip.
	 */
	private static $strip_thumbnail_height = 50;

	/**
	 * @config
	 * @var int The width of an image thumbnail in the CMS.
	 */
	private static $cms_thumbnail_width = 100;

	/**
	 * @config
	 * @var int The height of an image thumbnail in the CMS.
	 */
	private static $cms_thumbnail_height = 100;

	/**
	 * @config
	 * @var int The width of an image thumbnail in the Asset section.
	 */
	private static $asset_thumbnail_width = 100;

	/**
	 * @config
	 * @var int The height of an image thumbnail in the Asset section.
	 */
	private static $asset_thumbnail_height = 100;

	/**
	 * @config
	 * @var int The width of an image preview in the Asset section.
	 */
	private static $asset_preview_width = 400;

	/**
	 * @config
	 * @var int The height of an image preview in the Asset section.
	 */
	private static $asset_preview_height = 200;

	/**
	 * @config
	 * @var bool Force all images to resample in all cases
	 */
	private static $force_resample = false;

	/**
	 * @config
	 * @var bool Regenerates images if set to true. This is set by {@link flush()}
	 */
	private static $flush = false;

	/**
	 * Triggered early in the request when someone requests a flush.
	 */
	public static function flush() {
		self::$flush = true;
	}

	public static function set_backend($backend) {
		self::config()->backend = $backend;
	}

	public static function get_backend() {
		return self::config()->backend;
	}

	/**
	 * Retrieve the original filename from the path of a transformed image.
	 * Any other filenames pass through unchanged.
	 *
	 * @param string $path
	 * @return string
	 */
	public static function strip_resampled_prefix($path) {
		return preg_replace('/_resampled\/(.+\/|[^-]+-)/', '', $path);
	}

	/**
	 * Set up template methods to access the transformations generated by 'generate' methods.
	 */
	public function defineMethods() {
		$methodNames = $this->allMethodNames();
		foreach($methodNames as $methodName) {
			if(substr($methodName,0,8) == 'generate') {
				$this->addWrapperMethod(substr($methodName,8), 'getFormattedImage');
			}
		}

		parent::defineMethods();
	}

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$urlLink = "<div class='field readonly'>";
		$urlLink .= "<label class='left'>"._t('AssetTableField.URL','URL')."</label>";
		$urlLink .= "<span class='readonly'><a href='{$this->Link()}'>{$this->RelativeLink()}</a></span>";
		$urlLink .= "</div>";
		// todo: check why the above code is here, since $urlLink is not used?

		//attach the addition file information for an image to the existing FieldGroup create in the parent class
		$fileAttributes = $fields->fieldByName('Root.Main.FilePreview')->fieldByName('FilePreviewData');
		$fileAttributes->push(new ReadonlyField("Dimensions", _t('AssetTableField.DIM','Dimensions') . ':'));

		return $fields;
	}

	/**
	 * Return an XHTML img tag for this Image,
	 * or NULL if the image file doesn't exist on the filesystem.
	 *
	 * @return string
	 */
	public function getTag() {
		if($this->exists()) {
			$url = $this->getURL();
			$title = ($this->Title) ? $this->Title : $this->Filename;
			if($this->Title) {
				$title = Convert::raw2att($this->Title);
			} else {
				if(preg_match("/([^\/]*)\.[a-zA-Z0-9]{1,6}$/", $title, $matches)) {
					$title = Convert::raw2att($matches[1]);
				}
			}
			return "<img src=\"$url\" alt=\"$title\" />";
		}
	}

	/**
	 * Return an XHTML img tag for this Image.
	 *
	 * @return string
	 */
	public function forTemplate() {
		return $this->getTag();
	}

	/**
	 * File names are filtered through {@link FileNameFilter}, see class documentation
	 * on how to influence this behaviour.
	 *
	 * @deprecated 4.0
	 */
	public function loadUploadedImage($tmpFile) {
		Deprecation::notice('4.0', 'Use the Upload::loadIntoFile()');

		if(!is_array($tmpFile)) {
			user_error("Image::loadUploadedImage() Not passed an array.  Most likely, the form hasn't got the right"
				. "enctype", E_USER_ERROR);
		}

		if(!$tmpFile['size']) {
			return;
		}

		$class = $this->class;

		// Create a folder
		if(!file_exists(ASSETS_PATH)) {
			mkdir(ASSETS_PATH, Config::inst()->get('Filesystem', 'folder_create_mask'));
		}

		if(!file_exists(ASSETS_PATH . "/$class")) {
			mkdir(ASSETS_PATH . "/$class", Config::inst()->get('Filesystem', 'folder_create_mask'));
		}

		// Generate default filename
		$nameFilter = FileNameFilter::create();
		$file = $nameFilter->filter($tmpFile['name']);
		if(!$file) $file = "file.jpg";

		$file = ASSETS_PATH . "/$class/$file";

		while(file_exists(BASE_PATH . "/$file")) {
			$i = $i ? ($i+1) : 2;
			$oldFile = $file;
			$file = preg_replace('/[0-9]*(\.[^.]+$)/', $i . '\\1', $file);
			if($oldFile == $file && $i > 2) user_error("Couldn't fix $file with $i", E_USER_ERROR);
		}

		if(file_exists($tmpFile['tmp_name']) && copy($tmpFile['tmp_name'], BASE_PATH . "/$file")) {
			// Remove the old images

			$this->deleteFormattedImages();
			return true;
		}
	}

	/**
	 * Scale image proportionally to fit within the specified bounds
	 *
	 * @param integer $width The width to size within
	 * @param integer $height The height to size within
	 * @return Image|null
	 */
	public function Fit($width, $height) {
		// Prevent divide by zero on missing/blank file
		if(!$this->getWidth() || !$this->getHeight()) return null;

		// Check if image is already sized to the correct dimension
		$widthRatio = $width / $this->getWidth();
		$heightRatio = $height / $this->getHeight();

		if( $widthRatio < $heightRatio ) {
			// Target is higher aspect ratio than image, so check width
			if($this->isWidth($width) && !Config::inst()->get('Image', 'force_resample')) return $this;
		} else {
			// Target is wider or same aspect ratio as image, so check height
			if($this->isHeight($height) && !Config::inst()->get('Image', 'force_resample')) return $this;
		}

		// Item must be regenerated
		return  $this->getFormattedImage('Fit', $width, $height);
	}

	/**
	 * Scale image proportionally to fit within the specified bounds
	 *
	 * @param Image_Backend $backend
	 * @param integer $width The width to size within
	 * @param integer $height The height to size within
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateFit(Image_Backend $backend, $width, $height) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->resizeRatio($width, $height);
	}

	/**
	 * Proportionally scale down this image if it is wider or taller than the specified dimensions.
	 * Similar to Fit but without up-sampling. Use in templates with $FitMax.
	 *
	 * @uses Image::Fit()
	 * @param integer $width The maximum width of the output image
	 * @param integer $height The maximum height of the output image
	 * @return Image
	 */
	public function FitMax($width, $height) {
		// Temporary $force_resample support for 3.x, to be removed in 4.0
		if (Config::inst()->get('Image', 'force_resample') && $this->getWidth() <= $width && $this->getHeight() <= $height) return $this->Fit($this->getWidth(),$this->getHeight());

		return $this->getWidth() > $width || $this->getHeight() > $height
			? $this->Fit($width,$height)
			: $this;
	}

	/**
	 * Resize and crop image to fill specified dimensions.
	 * Use in templates with $Fill
	 *
	 * @param integer $width Width to crop to
	 * @param integer $height Height to crop to
	 * @return Image|null
	 */
	public function Fill($width, $height) {
		return $this->isSize($width, $height) && !Config::inst()->get('Image', 'force_resample')
			? $this
			: $this->getFormattedImage('Fill', $width, $height);
	}

	/**
	 * Resize and crop image to fill specified dimensions.
	 * Use in templates with $Fill
	 *
	 * @param Image_Backend $backend
	 * @param integer $width Width to crop to
	 * @param integer $height Height to crop to
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateFill(Image_Backend $backend, $width, $height) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->croppedResize($width, $height);
	}

	/**
	 * Crop this image to the aspect ratio defined by the specified width and height,
	 * then scale down the image to those dimensions if it exceeds them.
	 * Similar to Fill but without up-sampling. Use in templates with $FillMax.
	 *
	 * @uses Image::Fill()
	 * @param integer $width The relative (used to determine aspect ratio) and maximum width of the output image
	 * @param integer $height The relative (used to determine aspect ratio) and maximum height of the output image
	 * @return Image
	 */
	public function FillMax($width, $height) {
		// Prevent divide by zero on missing/blank file
		if(!$this->getWidth() || !$this->getHeight()) return null;

		// Temporary $force_resample support for 3.x, to be removed in 4.0
		if (Config::inst()->get('Image', 'force_resample') && $this->isSize($width, $height)) return $this->Fill($width, $height);

		// Is the image already the correct size?
		if ($this->isSize($width, $height)) return $this;

		// If not, make sure the image isn't upsampled
		$imageRatio = $this->getWidth() / $this->getHeight();
		$cropRatio = $width / $height;
		// If cropping on the x axis compare heights
		if ($cropRatio < $imageRatio && $this->getHeight() < $height) return $this->Fill($this->getHeight()*$cropRatio, $this->getHeight());
		// Otherwise we're cropping on the y axis (or not cropping at all) so compare widths
		if ($this->getWidth() < $width) return $this->Fill($this->getWidth(), $this->getWidth()/$cropRatio);

		return $this->Fill($width, $height);
	}

	/**
	 * Fit image to specified dimensions and fill leftover space with a solid colour (default white). Use in templates with $Pad.
	 *
	 * @param integer $width The width to size to
	 * @param integer $height The height to size to
	 * @param integer $transparencyPercent Level of transparency
	 * @return Image|null
	 */
	public function Pad($width, $height, $backgroundColor='FFFFFF', $transparencyPercent = 0) {
		return $this->isSize($width, $height) && !Config::inst()->get('Image', 'force_resample')
			? $this
			: $this->getFormattedImage('Pad', $width, $height, $backgroundColor, $transparencyPercent);
	}

	/**
	 * Fit image to specified dimensions and fill leftover space with a solid colour (default white). Use in templates with $Pad.
	 *
	 * @param Image_Backend $backend
	 * @param integer $width The width to size to
	 * @param integer $height The height to size to
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generatePad(Image_Backend $backend, $width, $height, $backgroundColor='FFFFFF') {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->paddedResize($width, $height, $backgroundColor);
	}

	/**
	 * Scale image proportionally by width. Use in templates with $ScaleWidth.
	 *
	 * @param integer $width The width to set
	 * @return Image|null
	 */
	public function ScaleWidth($width) {
		return $this->isWidth($width) && !Config::inst()->get('Image', 'force_resample')
			? $this
			: $this->getFormattedImage('ScaleWidth', $width);
	}

	/**
	 * Scale image proportionally by width. Use in templates with $ScaleWidth.
	 *
	 * @param Image_Backend $backend
	 * @param int $width The width to set
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateScaleWidth(Image_Backend $backend, $width) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->resizeByWidth($width);
	}

	/**
	 * Proportionally scale down this image if it is wider than the specified width.
	 * Similar to ScaleWidth but without up-sampling. Use in templates with $ScaleMaxWidth.
	 *
	 * @uses Image::ScaleWidth()
	 * @param integer $width The maximum width of the output image
	 * @return Image
	 */
	public function ScaleMaxWidth($width) {
		// Temporary $force_resample support for 3.x, to be removed in 4.0
		if (Config::inst()->get('Image', 'force_resample') && $this->getWidth() <= $width) return $this->ScaleWidth($this->getWidth());

		return $this->getWidth() > $width
			? $this->ScaleWidth($width)
			: $this;
	}

	/**
	 * Scale image proportionally by height. Use in templates with $ScaleHeight.
	 *
	 * @param integer $height The height to set
	 * @return Image|null
	 */
	public function ScaleHeight($height) {
		return $this->isHeight($height) && !Config::inst()->get('Image', 'force_resample')
			? $this
			: $this->getFormattedImage('ScaleHeight', $height);
	}

	/**
	 * Scale image proportionally by height. Use in templates with $ScaleHeight.
	 *
	 * @param Image_Backend $backend
	 * @param integer $height The height to set
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateScaleHeight(Image_Backend $backend, $height){
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->resizeByHeight($height);
	}

	/**
	 * Proportionally scale down this image if it is taller than the specified height.
	 * Similar to ScaleHeight but without up-sampling. Use in templates with $ScaleMaxHeight.
	 *
	 * @uses Image::ScaleHeight()
	 * @param integer $height The maximum height of the output image
	 * @return Image
	 */
	public function ScaleMaxHeight($height) {
		// Temporary $force_resample support for 3.x, to be removed in 4.0
		if (Config::inst()->get('Image', 'force_resample') && $this->getHeight() <= $height) return $this->ScaleHeight($this->getHeight());

		return $this->getHeight() > $height
			? $this->ScaleHeight($height)
			: $this;
	}

	/**
	 * Crop image on X axis if it exceeds specified width. Retain height.
	 * Use in templates with $CropWidth. Example: $Image.ScaleHeight(100).$CropWidth(100)
	 *
	 * @uses Image::Fill()
	 * @param integer $width The maximum width of the output image
	 * @return Image
	 */
	public function CropWidth($width) {
		// Temporary $force_resample support for 3.x, to be removed in 4.0
		if (Config::inst()->get('Image', 'force_resample') && $this->getWidth() <= $width) return $this->Fill($this->getWidth(), $this->getHeight());

		return $this->getWidth() > $width
			? $this->Fill($width, $this->getHeight())
			: $this;
	}

	/**
	 * Crop image on Y axis if it exceeds specified height. Retain width.
	 * Use in templates with $CropHeight. Example: $Image.ScaleWidth(100).CropHeight(100)
	 *
	 * @uses Image::Fill()
	 * @param integer $height The maximum height of the output image
	 * @return Image
	 */
	public function CropHeight($height) {
		// Temporary $force_resample support for 3.x, to be removed in 4.0
		if (Config::inst()->get('Image', 'force_resample') && $this->getHeight() <= $height) return $this->Fill($this->getWidth(), $this->getHeight());

		return $this->getHeight() > $height
			? $this->Fill($this->getWidth(), $height)
			: $this;
	}

	/**
	 * Resize the image by preserving aspect ratio, keeping the image inside the
	 * $width and $height
	 *
	 * @param integer $width The width to size within
	 * @param integer $height The height to size within
	 * @return Image
	 * @deprecated 4.0 Use Fit instead
	 */
	public function SetRatioSize($width, $height) {
		Deprecation::notice('4.0', 'Use Fit instead');
		return $this->Fit($width, $height);
	}

	/**
	 * Resize the image by preserving aspect ratio, keeping the image inside the
	 * $width and $height
	 *
	 * @param Image_Backend $backend
	 * @param integer $width The width to size within
	 * @param integer $height The height to size within
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateSetRatioSize(Image_Backend $backend, $width, $height) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->resizeRatio($width, $height);
	}

	/**
	 * Resize this Image by width, keeping aspect ratio. Use in templates with $SetWidth.
	 *
	 * @param integer $width The width to set
	 * @return Image
	 * @deprecated 4.0 Use ScaleWidth instead
	 */
	public function SetWidth($width) {
		Deprecation::notice('4.0', 'Use ScaleWidth instead');
		return $this->ScaleWidth($width);
	}

	/**
	 * Resize this Image by width, keeping aspect ratio. Use in templates with $SetWidth.
	 *
	 * @param Image_Backend $backend
	 * @param int $width The width to set
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateSetWidth(Image_Backend $backend, $width) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->resizeByWidth($width);
	}

	/**
	 * Resize this Image by height, keeping aspect ratio. Use in templates with $SetHeight.
	 *
	 * @param integer $height The height to set
	 * @return Image
	 * @deprecated 4.0 Use ScaleHeight instead
	 */
	public function SetHeight($height) {
		Deprecation::notice('4.0', 'Use ScaleHeight instead');
		return $this->ScaleHeight($height);
	}

	/**
	 * Resize this Image by height, keeping aspect ratio. Use in templates with $SetHeight.
	 *
	 * @param Image_Backend $backend
	 * @param integer $height The height to set
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateSetHeight(Image_Backend $backend, $height){
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->resizeByHeight($height);
	}

	/**
	 * Resize this Image by both width and height, using padded resize. Use in templates with $SetSize.
	 * @see Image::PaddedImage()
	 *
	 * @param integer $width The width to size to
	 * @param integer $height The height to size to
	 * @return Image
	 * @deprecated 4.0 Use Pad instead
	 */
	public function SetSize($width, $height) {
		Deprecation::notice('4.0', 'Use Pad instead');
		return $this->Pad($width, $height);
	}

	/**
	 * Resize this Image by both width and height, using padded resize. Use in templates with $SetSize.
	 *
	 * @param Image_Backend $backend
	 * @param integer $width The width to size to
	 * @param integer $height The height to size to
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateSetSize(Image_Backend $backend, $width, $height) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->paddedResize($width, $height);
	}

	/**
	 * Resize this image for the CMS. Use in templates with $CMSThumbnail
	 *
	 * @return Image_Cached|null
	 */
	public function CMSThumbnail() {
		return $this->Pad($this->stat('cms_thumbnail_width'),$this->stat('cms_thumbnail_height'));
	}

	/**
	 * Resize this image for the CMS. Use in templates with $CMSThumbnail.
	 *
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateCMSThumbnail(Image_Backend $backend) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->paddedResize($this->stat('cms_thumbnail_width'),$this->stat('cms_thumbnail_height'));
	}

	/**
	 * Resize this image for preview in the Asset section. Use in templates with $AssetLibraryPreview.
	 *
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateAssetLibraryPreview(Image_Backend $backend) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->paddedResize($this->stat('asset_preview_width'),$this->stat('asset_preview_height'));
	}

	/**
	 * Resize this image for thumbnail in the Asset section. Use in templates with $AssetLibraryThumbnail.
	 *
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateAssetLibraryThumbnail(Image_Backend $backend) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->paddedResize($this->stat('asset_thumbnail_width'),$this->stat('asset_thumbnail_height'));
	}

	/**
	 * Resize this image for use as a thumbnail in a strip. Use in templates with $StripThumbnail.
	 *
	 * @return Image_Cached|null
	 */
	public function StripThumbnail() {
		return $this->Fill($this->stat('strip_thumbnail_width'),$this->stat('strip_thumbnail_height'));
	}

	/**
	 * Resize this image for use as a thumbnail in a strip. Use in templates with $StripThumbnail.
	 *
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateStripThumbnail(Image_Backend $backend) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->croppedResize($this->stat('strip_thumbnail_width'),$this->stat('strip_thumbnail_height'));
	}

	/**
	 * Resize this Image by both width and height, using padded resize. Use in templates with $PaddedImage.
	 * @see Image::SetSize()
	 *
	 * @param integer $width The width to size to
	 * @param integer $height The height to size to
	 * @param integer $transparencyPercent Level of transparency
	 * @return Image
	 * @deprecated 4.0 Use Pad instead
	 */
	public function PaddedImage($width, $height, $backgroundColor='FFFFFF', $transparencyPercent = 0) {
		Deprecation::notice('4.0', 'Use Pad instead');
		return $this->Pad($width, $height, $backgroundColor, $transparencyPercent);
	}

	/**
	 * Resize this Image by both width and height, using padded resize. Use in templates with $PaddedImage.
	 *
	 * @param Image_Backend $backend
	 * @param integer $width The width to size to
	 * @param integer $height The height to size to
	 * @param integer $transparencyPercent Level of transparency
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generatePaddedImage(Image_Backend $backend, $width, $height, $backgroundColor = 'FFFFFF', $transparencyPercent = 0) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->paddedResize($width, $height, $backgroundColor, $transparencyPercent);
	}

	/**
	 * Determine if this image is of the specified size
	 *
	 * @param integer $width Width to check
	 * @param integer $height Height to check
	 * @return boolean
	 */
	public function isSize($width, $height) {
		return $this->isWidth($width) && $this->isHeight($height);
	}

	/**
	 * Determine if this image is of the specified width
	 *
	 * @param integer $width Width to check
	 * @return boolean
	 */
	public function isWidth($width) {
		return !empty($width) && $this->getWidth() == $width;
	}

	/**
	 * Determine if this image is of the specified width
	 *
	 * @param integer $height Height to check
	 * @return boolean
	 */
	public function isHeight($height) {
		return !empty($height) && $this->getHeight() == $height;
	}

	/**
	 * Return an image object representing the image in the given format.
	 * This image will be generated using generateFormattedImage().
	 * The generated image is cached, to flush the cache append ?flush=1 to your URL.
	 *
	 * Just pass the correct number of parameters expected by the working function
	 *
	 * @param string $format The name of the format.
	 * @return Image_Cached|null
	 */
	public function getFormattedImage($format) {
		$args = func_get_args();

		if($this->exists()) {
			$cacheFile = call_user_func_array(array($this, "cacheFilename"), $args);

			if(!file_exists(Director::baseFolder()."/".$cacheFile) || self::$flush) {
				call_user_func_array(array($this, "generateFormattedImage"), $args);
			}

			$cached = Injector::inst()->createWithArgs('Image_Cached', array($cacheFile, false, $this));
			return $cached;
		}
	}

	/**
	 * Return the filename for the cached image, given its format name and arguments.
	 * @param string $format The format name.
	 * @return string
	 * @throws InvalidArgumentException
	 */
	public function cacheFilename($format) {
		$args = func_get_args();
		array_shift($args);

		// Note: $folder holds the *original* file, while the Image we're working with
		// may be a formatted image in a child directory (this happens when we're chaining formats)
		$folder = $this->ParentID ? $this->Parent()->Filename : ASSETS_DIR . "/";

		$format = $format . Convert::base64url_encode($args);
		$filename = $format . "/" . $this->Name;

		$pattern = $this->getFilenamePatterns($this->Name);

		// Any previous formats need to be derived from this Image's directory, and prepended to the new filename
		$prepend = array();
		preg_match_all($pattern['GeneratorPattern'], $this->Filename, $matches, PREG_SET_ORDER);
		foreach($matches as $formatdir) {
			$prepend[] = $formatdir[0];
		}
		$filename = implode($prepend) . $filename;

		if (!preg_match($pattern['FullPattern'], $filename)) {
			throw new InvalidArgumentException('Filename ' . $filename
				. ' that should be used to cache a resized image is invalid');
		}

		return $folder . "_resampled/" . $filename;
	}

	/**
	 * Generate an image on the specified format. It will save the image
	 * at the location specified by cacheFilename(). The image will be generated
	 * using the specific 'generate' method for the specified format.
	 *
	 * @param string $format Name of the format to generate.
	 */
	public function generateFormattedImage($format) {
		$args = func_get_args();

		$cacheFile = call_user_func_array(array($this, "cacheFilename"), $args);

		$backend = Injector::inst()->createWithArgs(self::config()->backend, array(
			Director::baseFolder()."/" . $this->Filename,
			$args
		));

		if($backend->hasImageResource()) {

			$generateFunc = "generate$format";
			if($this->hasMethod($generateFunc)){

				array_shift($args);
				array_unshift($args, $backend);

				$backend = call_user_func_array(array($this, $generateFunc), $args);
				if($backend){
					$backend->writeTo(Director::baseFolder()."/" . $cacheFile);
				}

			} else {
				user_error("Image::generateFormattedImage - Image $format public function not found.",E_USER_WARNING);
			}
		}
	}

	/**
	 * Generate a resized copy of this image with the given width & height.
	 * This can be used in templates with $ResizedImage but should be avoided,
	 * as it's the only image manipulation function which can skew an image.
	 *
	 * @param integer $width Width to resize to
	 * @param integer $height Height to resize to
	 * @return Image
	 */
	public function ResizedImage($width, $height) {
		return $this->isSize($width, $height) && !Config::inst()->get('Image', 'force_resample')
			? $this
			: $this->getFormattedImage('ResizedImage', $width, $height);
	}

	/**
	 * Generate a resized copy of this image with the given width & height.
	 * Use in templates with $ResizedImage.
	 *
	 * @param Image_Backend $backend
	 * @param integer $width Width to resize to
	 * @param integer $height Height to resize to
	 * @return Image_Backend|null
	 */
	public function generateResizedImage(Image_Backend $backend, $width, $height) {
		if(!$backend){
			user_error("Image::generateFormattedImage - generateResizedImage is being called by legacy code"
				. " or Image::\$backend is not set.",E_USER_WARNING);
		}else{
			return $backend->resize($width, $height);
		}
	}

	/**
	 * Generate a resized copy of this image with the given width & height, cropping to maintain aspect ratio.
	 * Use in templates with $CroppedImage
	 *
	 * @param integer $width Width to crop to
	 * @param integer $height Height to crop to
	 * @return Image
	 * @deprecated 4.0 Use Fill instead
	 */
	public function CroppedImage($width, $height) {
		Deprecation::notice('4.0', 'Use Fill instead');
		return $this->Fill($width, $height);
	}

	/**
	 * Generate a resized copy of this image with the given width & height, cropping to maintain aspect ratio.
	 * Use in templates with $CroppedImage
	 *
	 * @param Image_Backend $backend
	 * @param integer $width Width to crop to
	 * @param integer $height Height to crop to
	 * @return Image_Backend
	 * @deprecated 4.0 Generate methods are no longer applicable
	 */
	public function generateCroppedImage(Image_Backend $backend, $width, $height) {
		Deprecation::notice('4.0', 'Generate methods are no longer applicable');
		return $backend->croppedResize($width, $height);
	}

	/**
	 * Generate patterns that will help to match filenames of cached images
	 * @param string $filename Filename of source image
	 * @return array
	 */
	private function getFilenamePatterns($filename) {
		$methodNames = $this->allMethodNames(true);
		$generateFuncs = array();
		foreach($methodNames as $methodName) {
			if(substr($methodName, 0, 8) == 'generate') {
				$format = substr($methodName, 8);
				$generateFuncs[] = preg_quote($format);
			}
		}
		// All generate functions may appear any number of times in the image cache name.
		$generateFuncs = implode('|', $generateFuncs);
		$base64url_match = "[a-zA-Z0-9_~]*={0,2}";
		return array(
				'FullPattern' => "/^((?P<Generator>{$generateFuncs})(?P<Args>" . $base64url_match . ")\/)+"
									. preg_quote($filename) . "$/i",
				'GeneratorPattern' => "/(?P<Generator>{$generateFuncs})(?P<Args>" . $base64url_match . ")\//i"
		);
	}

	/**
	 * Generate a list of images that were generated from this image
	 */
	private function getGeneratedImages() {
		$generatedImages = array();
		$cachedFiles = array();

		$folder = $this->ParentID ? $this->Parent()->Filename : ASSETS_DIR . '/';
		$cacheDir = Director::getAbsFile($folder . '_resampled/');

		// Find all paths with the same filename as this Image (the path contains the transformation info)
		if(is_dir($cacheDir)) {
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheDir));
			foreach($files as $path => $file){
				if ($file->getFilename() == $this->Name) {
					$cachedFiles[] = $path;
				}
			}
		}

		$pattern = $this->getFilenamePatterns($this->Name);

		// Reconstruct the image transformation(s) from the format-folder(s) in the path
		// (if chained, they contain the transformations in the correct order)
		foreach($cachedFiles as $cf_path) {
			preg_match_all($pattern['GeneratorPattern'], $cf_path, $matches, PREG_SET_ORDER);

			$generatorArray = array();
			foreach ($matches as $singleMatch) {
				$generatorArray[] = array(
					'Generator' => $singleMatch['Generator'],
					'Args' => Convert::base64url_decode($singleMatch['Args'])
				);
			}

			$generatedImages[] = array(
				'FileName' => $cf_path,
				'Generators' => $generatorArray
			);
		}

		return $generatedImages;
	}

	/**
	 * Regenerate all of the formatted cached images for this image.
	 *
	 * @return int The number of formatted images regenerated
	 */
	public function regenerateFormattedImages() {
		if(!$this->Filename) return 0;

		// Without this, not a single file would be written
		// caused by a check in getFormattedImage()
		$this->flush();

		$numGenerated = 0;
		$generatedImages = $this->getGeneratedImages();
		$doneList = array();
		foreach($generatedImages as $singleImage) {
			$cachedImage = $this;
			if (in_array($singleImage['FileName'], $doneList) ) continue;

			foreach($singleImage['Generators'] as $singleGenerator) {
				$args = array_merge(array($singleGenerator['Generator']), $singleGenerator['Args']);
				$cachedImage = call_user_func_array(array($cachedImage, "getFormattedImage"), $args);
			}
			$doneList[] = $singleImage['FileName'];
			$numGenerated++;
		}

		return $numGenerated;
	}

	/**
	 * Remove all of the formatted cached images for this image.
	 *
	 * @return int The number of formatted images deleted
	 */
	public function deleteFormattedImages() {
		if(!$this->Filename) return 0;

		$numDeleted = 0;
		$generatedImages = $this->getGeneratedImages();
		foreach($generatedImages as $singleImage) {
			$path = $singleImage['FileName'];
			unlink($path);
			$numDeleted++;
			do {
				$path = dirname($path);
			}
			// remove the folder if it's empty (and it's not the assets folder)
			while(!preg_match('/assets$/', $path) && Filesystem::remove_folder_if_empty($path));
		}

		return $numDeleted;
	}

	/**
	 * Get the dimensions of this Image.
	 * @param string $dim If this is equal to "string", return the dimensions in string form,
	 * if it is 0 return the height, if it is 1 return the width.
	 * @return string|int|null
	 */
	public function getDimensions($dim = "string") {
		if($this->getField('Filename')) {

			$imagefile = $this->getFullPath();
			if($this->exists()) {
				$size = getimagesize($imagefile);
				return ($dim === "string") ? "$size[0]x$size[1]" : $size[$dim];
			} else {
				return ($dim === "string") ? "file '$imagefile' not found" : null;
			}
		}
	}

	/**
	 * Get the width of this image.
	 * @return int
	 */
	public function getWidth() {
		return $this->getDimensions(0);
	}

	/**
	 * Get the height of this image.
	 * @return int
	 */
	public function getHeight() {
		return $this->getDimensions(1);
	}

	/**
	 * Get the orientation of this image.
	 * @return ORIENTATION_SQUARE | ORIENTATION_PORTRAIT | ORIENTATION_LANDSCAPE
	 */
	public function getOrientation() {
		$width = $this->getWidth();
		$height = $this->getHeight();
		if($width > $height) {
			return self::ORIENTATION_LANDSCAPE;
		} elseif($height > $width) {
			return self::ORIENTATION_PORTRAIT;
		} else {
			return self::ORIENTATION_SQUARE;
		}
	}

	public function onAfterUpload() {
		$this->deleteFormattedImages();
		parent::onAfterUpload();
	}

	protected function onBeforeDelete() {
		$backend = Injector::inst()->createWithArgs(self::config()->backend, array(
			Director::baseFolder()."/" . $this->Filename
		));
		$backend->onBeforeDelete($this);

		$this->deleteFormattedImages();

		parent::onBeforeDelete();
	}
}

/**
 * A resized / processed {@link Image} object.
 * When Image object are processed or resized, a suitable Image_Cached object is returned, pointing to the
 * cached copy of the processed image.
 *
 * @package framework
 * @subpackage filesystem
 */
class Image_Cached extends Image {

	/**
	 * Create a new cached image.
	 * @param string $filename The filename of the image.
	 * @param boolean $isSingleton This this to true if this is a singleton() object, a stub for calling methods.
	 *                             Singletons don't have their defaults set.
	 */
	public function __construct($filename = null, $isSingleton = false, Image $sourceImage = null) {
		parent::__construct(array(), $isSingleton);
		if ($sourceImage) {
			// Copy properties from source image, except unsafe ones
			$properties = $sourceImage->toMap();
			unset($properties['RecordClassName'], $properties['ClassName']);
			$this->update($properties);
		}
		$this->ID = -1;
		$this->Filename = $filename;
	}

	/**
	 * Override the parent's exists method becuase the ID is explicitly set to -1 on a cached image we can't use the
	 * default check
	 *
	 * @return bool Whether the cached image exists
	 */
	public function exists() {
		return file_exists($this->getFullPath());
	}

	public function getRelativePath() {
		return $this->getField('Filename');
	}

	/**
	 * Prevent creating new tables for the cached record
	 *
	 * @return false
	 */
	public function requireTable() {
		return false;
	}

	/**
	 * Prevent writing the cached image to the database
	 *
	 * @throws Exception
	 */
	public function write($showDebug = false, $forceInsert = false, $forceWrite = false, $writeComponents = false) {
		throw new Exception("{$this->ClassName} can not be written back to the database.");
	}
}
