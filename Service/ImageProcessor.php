<?php

declare(strict_types=1);

namespace Learning\CatalogImageResize\Service;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product\Image\ParamsBuilder;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Catalog\Model\View\Asset\ImageFactory as AssetImageFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Image;
use Magento\Framework\Image\Factory as ImageFactory;
use Magento\Framework\View\ConfigInterface as ViewConfig;
use Magento\Theme\Model\Config\Customization as ThemeCustomizationConfig;
use Magento\Theme\Model\ResourceModel\Theme\Collection as ThemeCollection;

/**
 * Image processor service
 *
 * Handles product image resizing operations
 */
class ImageProcessor
{
    /**
     * @var Filesystem\Directory\WriteInterface
     */
    private $mediaDirectory;

    /**
     * @var MediaConfig
     */
    private $imageConfig;

    /**
     * @var ThemeCollection
     */
    private $themeCollection;

    /**
     * @var ThemeCustomizationConfig
     */
    private $themeCustomizationConfig;

    /**
     * @var ViewConfig
     */
    private $viewConfig;

    /**
     * @var ParamsBuilder
     */
    private $paramsBuilder;

    /**
     * @var AssetImageFactory
     */
    private $assetImageFactory;

    /**
     * @var ImageFactory
     */
    private $imageFactory;

    /**
     * Initialize dependencies
     *
     * @param Filesystem $filesystem
     * @param MediaConfig $imageConfig
     * @param ThemeCollection $themeCollection
     * @param ThemeCustomizationConfig $themeCustomizationConfig
     * @param ViewConfig $viewConfig
     * @param ParamsBuilder $paramsBuilder
     * @param AssetImageFactory $assetImageFactory
     * @param ImageFactory $imageFactory
     * @throws FileSystemException
     */
    public function __construct(
        Filesystem $filesystem,
        MediaConfig $imageConfig,
        ThemeCollection $themeCollection,
        ThemeCustomizationConfig $themeCustomizationConfig,
        ViewConfig $viewConfig,
        ParamsBuilder $paramsBuilder,
        AssetImageFactory $assetImageFactory,
        ImageFactory $imageFactory
    ) {
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->imageConfig = $imageConfig;
        $this->themeCollection = $themeCollection;
        $this->themeCustomizationConfig = $themeCustomizationConfig;
        $this->viewConfig = $viewConfig;
        $this->paramsBuilder = $paramsBuilder;
        $this->assetImageFactory = $assetImageFactory;
        $this->imageFactory = $imageFactory;
    }

    /**
     * Get themes currently in use
     *
     * @return array
     */
    public function getThemesInUse(): array
    {
        $themesInUse = [];
        $registeredThemes = $this->themeCollection->loadRegisteredThemes();
        $storesByThemes = $this->themeCustomizationConfig->getStoresByThemes();
        $keyType = is_integer(key($storesByThemes)) ? 'getId' : 'getCode';

        foreach ($registeredThemes as $registeredTheme) {
            if (array_key_exists($registeredTheme->$keyType(), $storesByThemes)) {
                $themesInUse[] = $registeredTheme;
            }
        }

        return $themesInUse;
    }

    /**
     * Get view image configurations from themes
     *
     * @param array $themes
     * @return array
     */
    public function getViewImages(array $themes): array
    {
        $viewImages = [];

        foreach ($themes as $theme) {
            $config = $this->viewConfig->getViewConfig([
                'area' => Area::AREA_FRONTEND,
                'themeModel' => $theme,
            ]);
            $images = $config->getMediaEntities('Magento_Catalog', ImageHelper::MEDIA_TYPE_CONFIG_NODE);

            foreach ($images as $imageId => $imageData) {
                $uniqIndex = $this->getUniqueImageIndex($imageData);
                $imageData['id'] = $imageId;
                $viewImages[$uniqIndex] = $imageData;
            }
        }

        return $viewImages;
    }

    /**
     * Get unique index for image configuration
     *
     * @param array $imageData
     * @return string
     */
    private function getUniqueImageIndex(array $imageData): string
    {
        ksort($imageData);
        unset($imageData['type']);

        return md5(json_encode($imageData));
    }

    /**
     * Resize image based on view configuration
     *
     * @param array $viewImage
     * @param string $originalImagePath
     * @param string $originalImageName
     * @return void
     * @throws LocalizedException
     */
    public function resize(array $viewImage, string $originalImagePath, string $originalImageName): void
    {
        $imageParams = $this->paramsBuilder->build($viewImage);
        $image = $this->makeImage($originalImagePath, $imageParams);
        $imageAsset = $this->assetImageFactory->create([
            'miscParams' => $imageParams,
            'filePath' => $originalImageName,
        ]);

        if (isset($imageParams['watermark_file'])) {
            $this->applyWatermark($image, $imageParams);
        }

        if ($imageParams['image_width'] !== null && $imageParams['image_height'] !== null) {
            $image->resize($imageParams['image_width'], $imageParams['image_height']);
        }

        $image->save($imageAsset->getPath());
    }

    /**
     * Create image instance with parameters
     *
     * @param string $originalImagePath
     * @param array $imageParams
     * @return Image
     */
    private function makeImage(string $originalImagePath, array $imageParams): Image
    {
        $image = $this->imageFactory->create($originalImagePath);
        $image->keepAspectRatio($imageParams['keep_aspect_ratio']);
        $image->keepFrame($imageParams['keep_frame']);
        $image->keepTransparency($imageParams['keep_transparency']);
        $image->constrainOnly($imageParams['constrain_only']);
        $image->backgroundColor($imageParams['background']);
        $image->quality($imageParams['quality']);

        return $image;
    }

    /**
     * Apply watermark to image
     *
     * @param Image $image
     * @param array $imageParams
     * @return void
     */
    private function applyWatermark(Image $image, array $imageParams): void
    {
        if ($imageParams['watermark_height'] !== null) {
            $image->setWatermarkHeight($imageParams['watermark_height']);
        }

        if ($imageParams['watermark_width'] !== null) {
            $image->setWatermarkWidth($imageParams['watermark_width']);
        }

        if ($imageParams['watermark_position'] !== null) {
            $image->setWatermarkPosition($imageParams['watermark_position']);
        }

        if ($imageParams['watermark_image_opacity'] !== null) {
            $image->setWatermarkImageOpacity($imageParams['watermark_image_opacity']);
        }

        $image->watermark($this->getWatermarkFilePath($imageParams['watermark_file']));
    }

    /**
     * Get watermark file path
     *
     * @param string $file
     * @return string
     */
    private function getWatermarkFilePath(string $file): string
    {
        $path = $this->imageConfig->getMediaPath('/watermark/' . $file);

        return $this->mediaDirectory->getAbsolutePath($path);
    }

    /**
     * Get absolute path for original image
     *
     * @param string $imageName
     * @return string
     */
    public function getOriginalImagePath(string $imageName): string
    {
        return $this->mediaDirectory->getAbsolutePath(
            $this->imageConfig->getMediaPath($imageName)
        );
    }
}
