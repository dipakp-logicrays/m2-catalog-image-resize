<?php

declare(strict_types=1);

namespace Learning\CatalogImageResize\Console\Command;

use Learning\CatalogImageResize\Service\ImageProcessor;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command for resizing product catalog images
 */
class ResizeImagesCommand extends Command
{
    private const OPTION_PRODUCT_IDS = 'product-ids';
    private const OPTION_PRODUCT_SKUS = 'product-skus';
    private const OPTION_ALL = 'all';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_BATCH_SIZE = 'batch-size';
    private const OPTION_MAX_RECORDS = 'max-records';

    /**
     * @var ProductCollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var ImageProcessor
     */
    private $imageProcessor;

    /**
     * @var State
     */
    private $state;

    /**
     * @var int
     */
    private $totalImagesProcessed = 0;

    /**
     * @var int
     */
    private $totalImagesSkipped = 0;

    /**
     * @var int
     */
    private $totalImagesFailed = 0;

    /**
     * @var array
     */
    private $errorMessages = [];

    /**
     * @var array
     */
    private $originalFilters = [];

    /**
     * Initialize dependencies
     *
     * @param ProductCollectionFactory $productCollectionFactory
     * @param ImageProcessor $imageProcessor
     * @param State $state
     * @param string|null $name
     */
    public function __construct(
        ProductCollectionFactory $productCollectionFactory,
        ImageProcessor $imageProcessor,
        State $state,
        ?string $name = null
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->imageProcessor = $imageProcessor;
        $this->state = $state;

        parent::__construct($name);
    }

    /**
     * Configure command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('learning:catalog:images:resize')
            ->setDescription('Resize product catalog images for all configured image types with advanced options')
            ->setHelp($this->getHelpText())
            ->addOption(
                self::OPTION_PRODUCT_IDS,
                'p',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of product IDs (e.g., 1,2,3)'
            )
            ->addOption(
                self::OPTION_PRODUCT_SKUS,
                's',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of product SKUs (e.g., SKU1,SKU2,SKU3)'
            )
            ->addOption(
                self::OPTION_ALL,
                'a',
                InputOption::VALUE_NONE,
                'Process all active products'
            )
            ->addOption(
                self::OPTION_BATCH_SIZE,
                'b',
                InputOption::VALUE_OPTIONAL,
                'Number of products to process per batch',
                50
            )
            ->addOption(
                self::OPTION_MAX_RECORDS,
                'm',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of products to process (0 = all products)',
                0
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                'd',
                InputOption::VALUE_NONE,
                'Dry run mode - show what would be processed without actually processing'
            );
    }

    /**
     * Get help text for command
     *
     * @return string
     */
    private function getHelpText(): string
    {
        return <<<HELP
<fg=cyan>DESCRIPTION:</>
  This command resizes product catalog images for all configured image types across all active themes.
  It generates optimized images for different contexts (thumbnails, product pages, listings, etc.).

<fg=cyan>USAGE:</>
  bin/magento learning:catalog:images:resize [OPTIONS]

<fg=cyan>OPTIONS:</>
  <fg=green>-p, --product-ids=IDS</>
      Process specific product IDs (comma-separated)
      Example: --product-ids=1,2,3

  <fg=green>-s, --product-skus=SKUS</>
      Process specific product SKUs (comma-separated)
      Example: --product-skus="24-MB01,Test Product-1"

  <fg=green>-a, --all</>
      Process all active products in the catalog

  <fg=green>-b, --batch-size=SIZE</>
      Number of products to process per batch (default: 50)
      Example: --batch-size=100

  <fg=green>-m, --max-records=COUNT</>
      Maximum number of products to process (0 = unlimited, default: 0)
      Example: --max-records=500

  <fg=green>-d, --dry-run</>
      Preview mode - shows what would be processed without actually processing

<fg=cyan>EXAMPLES:</>
  <fg=yellow># Process all active products</>
  bin/magento learning:catalog:images:resize --all

  <fg=yellow># Process specific product by SKU</>
  bin/magento learning:catalog:images:resize --product-skus=24-MB01

  <fg=yellow># Process multiple products by ID</>
  bin/magento learning:catalog:images:resize --product-ids=1,2,3

  <fg=yellow># Process all products with custom batch size</>
  bin/magento learning:catalog:images:resize --all --batch-size=100

  <fg=yellow># Preview what would be processed (dry run)</>
  bin/magento learning:catalog:images:resize --all --dry-run

  <fg=yellow># Process first 500 products only</>
  bin/magento learning:catalog:images:resize --all --max-records=500

<fg=cyan>NOTES:</>
  - Only active products (status = enabled) are processed
  - Products without images will be skipped
  - All configured themes and image types will be generated
  - Images are optimized for quality and performance
  - Original images remain untouched

HELP;
    }

    /**
     * Execute command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws LocalizedException
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        try {
            $this->state->setAreaCode(Area::AREA_GLOBAL);
        } catch (LocalizedException $e) {
            // Area code already set
        }

        $isDryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);
        $batchSize = (int) $input->getOption(self::OPTION_BATCH_SIZE);
        $maxRecords = (int) $input->getOption(self::OPTION_MAX_RECORDS);

        $this->displayBanner($output);

        if ($isDryRun) {
            $output->writeln('');
            $output->writeln('<fg=yellow>🔍 Running in DRY RUN mode - no images will be processed</>');
            $output->writeln('');
        }

        $productCollection = $this->getProductCollection($input, $output);

        if (!$productCollection) {
            return Cli::RETURN_FAILURE;
        }

        $totalProducts = $productCollection->getSize();

        if ($maxRecords > 0 && $maxRecords < $totalProducts) {
            $totalProducts = $maxRecords;
            $output->writeln(sprintf('<info>📊 Processing limited to %d products as requested</info>', $maxRecords));
        }

        if ($totalProducts === 0) {
            $output->writeln('');
            $output->writeln('<error>❌ No products found matching the criteria</error>');

            return Cli::RETURN_FAILURE;
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>📦 Total Products to Process: <fg=cyan>%d</></info>', $totalProducts));
        $output->writeln(sprintf('<info>📏 Batch Size: <fg=cyan>%d</></info>', $batchSize));

        $themesInUse = $this->imageProcessor->getThemesInUse();
        $viewImages = $this->imageProcessor->getViewImages($themesInUse);

        $output->writeln(sprintf('<info>🎨 Themes Found: <fg=cyan>%d</></info>', count($themesInUse)));
        $output->writeln(sprintf('<info>🖼️  Image Types to Generate: <fg=cyan>%d</></info>', count($viewImages)));
        $output->writeln('');

        if ($isDryRun) {
            return $this->displayDryRunInfo($productCollection, $viewImages, $output, $maxRecords);
        }

        return $this->processProductsInBatches(
            $productCollection,
            $viewImages,
            $batchSize,
            $maxRecords,
            $output
        );
    }

    /**
     * Display command banner
     *
     * @param OutputInterface $output
     * @return void
     */
    private function displayBanner(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln(' <fg=cyan>_____  _____  _____  _____  _      _____  _____       _____ _____ _____ _____ _____ _____ </>');
        $output->writeln(' <fg=cyan>|     ||  _  ||_   _||  _  || |    |     ||   __|     | __  ||   __|   __|_   _||__   ||   __|</>');
        $output->writeln(' <fg=cyan>|   --||     |  | |  |     || |__  |  |  ||  |  |     |    -||   __|__   | | |  |   __||   __|</>');
        $output->writeln(' <fg=cyan>|_____||__|__|  |_|  |__|__||____| |_____||_____|     |__|__||_____|_____| |_|  |_____||_____|</>');
        $output->writeln('');
        $output->writeln(' <fg=green>═══════════════════════════════════════════════════════════════════════════════════════════</>');
        $output->writeln(' <fg=green>                         📸 Catalog Image Resize Tool 📸                                   </>');
        $output->writeln(' <fg=green>═══════════════════════════════════════════════════════════════════════════════════════════</>');
    }

    /**
     * Get product collection based on input options
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return ProductCollection|null
     */
    private function getProductCollection(
        InputInterface $input,
        OutputInterface $output
    ): ?ProductCollection {
        $productIds = $input->getOption(self::OPTION_PRODUCT_IDS);
        $productSkus = $input->getOption(self::OPTION_PRODUCT_SKUS);
        $processAll = $input->getOption(self::OPTION_ALL);

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*')
            ->addAttributeToFilter('status', ['eq' => 1])
            ->addMediaGalleryData();

        if ($productIds) {
            $ids = array_map('trim', explode(',', (string) $productIds));
            $collection->addFieldToFilter('entity_id', ['in' => $ids]);
            $this->originalFilters = ['type' => 'ids', 'values' => $ids];
            $output->writeln('');
            $output->writeln(sprintf('<info>🔍 Filter: Processing products with IDs: <fg=yellow>%s</></info>', $productIds));
        } elseif ($productSkus) {
            $skus = array_map('trim', explode(',', (string) $productSkus));
            $collection->addFieldToFilter('sku', ['in' => $skus]);
            $this->originalFilters = ['type' => 'skus', 'values' => $skus];
            $output->writeln('');
            $output->writeln(sprintf('<info>🔍 Filter: Processing products with SKUs: <fg=yellow>%s</></info>', $productSkus));
        } elseif ($processAll) {
            $this->originalFilters = ['type' => 'all'];
            $output->writeln('');
            $output->writeln('<info>🔍 Filter: Processing <fg=yellow>ALL</> active products</info>');
        } else {
            $output->writeln('');
            $output->writeln('<error>❌ Error: Please specify one of the following options:</error>');
            $output->writeln('');
            $output->writeln('<comment>📋 Available Options:</comment>');
            $output->writeln('  <fg=green>--product-ids=</>    Process specific product IDs');
            $output->writeln('  <fg=green>--product-skus=</>   Process specific product SKUs');
            $output->writeln('  <fg=green>--all</>             Process all active products');
            $output->writeln('  <fg=green>--batch-size=</>     Number of products per batch (default: 50)');
            $output->writeln('  <fg=green>--max-records=</>    Maximum products to process (default: unlimited)');
            $output->writeln('  <fg=green>--dry-run</>         Preview without processing');
            $output->writeln('');
            $output->writeln('<comment>💡 Usage Examples:</comment>');
            $output->writeln('  php bin/magento learning:catalog:images:resize --product-ids=1,2,3');
            $output->writeln('  php bin/magento learning:catalog:images:resize --product-skus=SKU1,SKU2,SKU3');
            $output->writeln('  php bin/magento learning:catalog:images:resize --all --batch-size=100');
            $output->writeln('  php bin/magento learning:catalog:images:resize --all --max-records=500');
            $output->writeln('  php bin/magento learning:catalog:images:resize --all --dry-run');
            $output->writeln('');

            return null;
        }

        return $collection;
    }

    /**
     * Display dry run information
     *
     * @param ProductCollection $productCollection
     * @param array $viewImages
     * @param OutputInterface $output
     * @param int $maxRecords
     * @return int
     */
    private function displayDryRunInfo(
        ProductCollection $productCollection,
        array $viewImages,
        OutputInterface $output,
        int $maxRecords
    ): int {
        $output->writeln('<comment>📋 Products that would be processed:</comment>');
        $output->writeln('');

        $count = 0;
        $totalOperations = 0;

        foreach ($productCollection as $product) {
            if ($maxRecords > 0 && $count >= $maxRecords) {
                break;
            }

            $imageCount = count($product->getMediaGalleryImages());
            $operations = $imageCount * count($viewImages);
            $totalOperations += $operations;

            $output->writeln(sprintf(
                '  <fg=cyan>Product ID:</> <fg=yellow>%-6s</> | <fg=cyan>SKU:</> <fg=yellow>%-20s</> | <fg=cyan>Images:</> <fg=green>%-3d</> | <fg=cyan>Operations:</> <fg=magenta>%d</>',
                $product->getId(),
                substr($product->getSku(), 0, 20),
                $imageCount,
                $operations
            ));

            $count++;
        }

        $output->writeln('');
        $output->writeln('<fg=green>═══════════════════════════════════════════════════════════════════════════════════════════</>');
        $output->writeln(sprintf('<info>📊 Summary: <fg=cyan>%d</> products | <fg=magenta>%d</> total image operations</info>', $count, $totalOperations));
        $output->writeln('<fg=green>═══════════════════════════════════════════════════════════════════════════════════════════</>');
        $output->writeln('');
        $output->writeln('<info>✅ To actually process images, run the command without --dry-run option</info>');
        $output->writeln('');

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Process products in batches
     *
     * @param ProductCollection $productCollection
     * @param array $viewImages
     * @param int $batchSize
     * @param int $maxRecords
     * @param OutputInterface $output
     * @return int
     */
    private function processProductsInBatches(
        ProductCollection $productCollection,
        array $viewImages,
        int $batchSize,
        int $maxRecords,
        OutputInterface $output
    ): int {
        $totalProducts = $productCollection->getSize();

        if ($maxRecords > 0 && $maxRecords < $totalProducts) {
            $totalProducts = $maxRecords;
        }

        $totalPages = (int) ceil($totalProducts / $batchSize);
        $currentPage = 1;
        $processedCount = 0;

        $output->writeln('<fg=green>🚀 Starting image processing...</>');
        $output->writeln('');

        while ($currentPage <= $totalPages && ($maxRecords === 0 || $processedCount < $maxRecords)) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<fg=cyan>═══════════════════ 📦 Batch %d of %d ═══════════════════</>',
                $currentPage,
                $totalPages
            ));
            $output->writeln('');

            $collection = $this->productCollectionFactory->create();
            $collection->addAttributeToSelect('*')
                ->addAttributeToFilter('status', ['eq' => 1]);

            // Apply filters BEFORE addMediaGalleryData() to avoid filter bypass
            $this->applyOriginalFilters($collection);

            $collection->addMediaGalleryData()
                ->setPageSize($batchSize)
                ->setCurPage($currentPage);

            $batchCount = $collection->getSize();

            if ($batchCount === 0) {
                $output->writeln('<info>ℹ️  No more products to process</info>');
                break;
            }

            $batchSuccessProducts = 0;
            $batchErrorProducts = 0;

            foreach ($collection as $product) {
                if ($maxRecords > 0 && $processedCount >= $maxRecords) {
                    break 2;
                }

                $result = $this->processProduct($product, $viewImages, $output);

                if ($result) {
                    $batchSuccessProducts++;
                } else {
                    $batchErrorProducts++;
                }

                $processedCount++;
            }

            $output->writeln('');
            $output->writeln(sprintf(
                '<info>✅ Batch %d completed: <fg=green>%d successful</>, <fg=red>%d errors</></info>',
                $currentPage,
                $batchSuccessProducts,
                $batchErrorProducts
            ));

            $currentPage++;
        }

        return $this->displayFinalSummary($output);
    }

    /**
     * Apply original filters to collection
     *
     * @param ProductCollection $collection
     * @return void
     */
    private function applyOriginalFilters(ProductCollection $collection): void
    {
        if (empty($this->originalFilters)) {
            return;
        }

        switch ($this->originalFilters['type']) {
            case 'ids':
                $collection->addFieldToFilter('entity_id', ['in' => $this->originalFilters['values']]);
                break;
            case 'skus':
                // Use attribute filter for SKU to handle media gallery joins correctly
                $collection->addAttributeToFilter('sku', ['in' => $this->originalFilters['values']]);
                break;
            case 'all':
            default:
                // No additional filters needed for "all"
                break;
        }
    }

    /**
     * Process single product
     *
     * @param Product $product
     * @param array $viewImages
     * @param OutputInterface $output
     * @return bool
     */
    private function processProduct(
        Product $product,
        array $viewImages,
        OutputInterface $output
    ): bool {
        $galleryImages = $product->getMediaGalleryImages();

        if (!$galleryImages || $galleryImages->getSize() === 0) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<fg=yellow>⚠️  Product ID: %s | SKU: %s | No images found - Skipping</>',
                $product->getId(),
                $product->getSku()
            ));
            $this->totalImagesSkipped++;

            return true;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<fg=cyan>🔄 Processing Product ID: <fg=yellow>%s</> | SKU: <fg=yellow>%s</> | Images: <fg=green>%d</>',
            $product->getId(),
            $product->getSku(),
            $galleryImages->getSize()
        ));

        $totalOperations = $galleryImages->getSize() * count($viewImages);
        $progressBar = new ProgressBar($output, $totalOperations);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        $hasErrors = false;
        $productImagesProcessed = 0;
        $productImagesFailed = 0;

        foreach ($galleryImages as $image) {
            $originalImageName = $image->getFile();
            $originalImagePath = $this->imageProcessor->getOriginalImagePath($originalImageName);

            if (!file_exists($originalImagePath)) {
                $progressBar->clear();
                $output->writeln('');
                $output->writeln(sprintf(
                    '<error>❌ Product ID %s | File "%s" does not exist</error>',
                    $product->getId(),
                    basename($originalImagePath)
                ));
                $this->errorMessages[] = sprintf(
                    'Product ID %s | SKU %s | File not found: %s',
                    $product->getId(),
                    $product->getSku(),
                    basename($originalImagePath)
                );
                $progressBar->display();
                $hasErrors = true;
                $this->totalImagesFailed++;

                continue;
            }

            foreach ($viewImages as $viewImage) {
                try {
                    $this->imageProcessor->resize($viewImage, $originalImagePath, $originalImageName);
                    $progressBar->setMessage(sprintf(
                        'Processing: %s | Type: %s',
                        basename($originalImageName),
                        $viewImage['id'] ?? 'unknown'
                    ));
                    $productImagesProcessed++;
                    $this->totalImagesProcessed++;
                } catch (\Exception $e) {
                    $progressBar->clear();
                    $output->writeln('');
                    $output->writeln(sprintf(
                        '<error>❌ Error: %s | Type: %s | %s</error>',
                        basename($originalImageName),
                        $viewImage['id'] ?? 'unknown',
                        $e->getMessage()
                    ));
                    $this->errorMessages[] = sprintf(
                        'Product ID %s | SKU %s | Image: %s | Type: %s | Error: %s',
                        $product->getId(),
                        $product->getSku(),
                        basename($originalImageName),
                        $viewImage['id'] ?? 'unknown',
                        $e->getMessage()
                    );
                    $progressBar->display();
                    $hasErrors = true;
                    $productImagesFailed++;
                    $this->totalImagesFailed++;
                }

                $progressBar->advance();
            }
        }

        $progressBar->setMessage('Complete');
        $progressBar->finish();
        $output->writeln('');

        if (!$hasErrors) {
            $output->writeln(sprintf(
                '<fg=green>✅ Product ID %s completed successfully - %d images processed</>',
                $product->getId(),
                $productImagesProcessed
            ));
        } else {
            $output->writeln(sprintf(
                '<fg=yellow>⚠️  Product ID %s completed with warnings - %d processed, %d failed</>',
                $product->getId(),
                $productImagesProcessed,
                $productImagesFailed
            ));
        }

        return !$hasErrors;
    }

    /**
     * Display final summary
     *
     * @param OutputInterface $output
     * @return int
     */
    private function displayFinalSummary(OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('');
        $output->writeln('<fg=green>═══════════════════════════════════════════════════════════════════════════════════════════</>');
        $output->writeln('<fg=green>                                  📊 FINAL SUMMARY 📊                                      </>');
        $output->writeln('<fg=green>═══════════════════════════════════════════════════════════════════════════════════════════</>');
        $output->writeln('');

        $totalImages = $this->totalImagesProcessed + $this->totalImagesFailed + $this->totalImagesSkipped;

        $output->writeln(sprintf('  <fg=cyan>📦 Total Images:</> <fg=white>%d</>', $totalImages));
        $output->writeln(sprintf('  <fg=green>✅ Successfully Processed:</> <fg=green>%d</>', $this->totalImagesProcessed));

        if ($this->totalImagesSkipped > 0) {
            $output->writeln(sprintf('  <fg=yellow>⏭️  Skipped:</> <fg=yellow>%d</>', $this->totalImagesSkipped));
        }

        if ($this->totalImagesFailed > 0) {
            $output->writeln(sprintf('  <fg=red>❌ Failed:</> <fg=red>%d</>', $this->totalImagesFailed));
        }

        $successRate = $totalImages > 0
            ? round(($this->totalImagesProcessed / $totalImages) * 100, 2)
            : 0;

        $output->writeln('');
        $output->writeln(sprintf('  <fg=cyan>📈 Success Rate:</> <fg=white>%s%%</>', $successRate));

        $output->writeln('');
        $output->writeln('<fg=green>═══════════════════════════════════════════════════════════════════════════════════════════</>');

        if (count($this->errorMessages) > 0) {
            $output->writeln('');
            $output->writeln('<fg=red>❌ Error Details:</>');
            $output->writeln('');

            foreach ($this->errorMessages as $index => $message) {
                $output->writeln(sprintf('  %d. <error>%s</error>', $index + 1, $message));
            }

            $output->writeln('');
            $output->writeln('<fg=green>═══════════════════════════════════════════════════════════════════════════════════════════</>');
        }

        $output->writeln('');

        if ($this->totalImagesFailed > 0) {
            $output->writeln('<fg=yellow>⚠️  Process completed with some errors. Please review the error details above.</>');
        } else {
            $output->writeln('<fg=green>🎉 All images have been resized successfully! 🎉</>');
        }

        $output->writeln('');

        return Cli::RETURN_SUCCESS;
    }
}
