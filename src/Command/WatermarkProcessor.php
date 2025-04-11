<?php
namespace Watermarker\Command;

use Omeka\Mvc\Controller\Plugin\Messenger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * Command to batch process media and apply watermarks
 */
class WatermarkProcessor extends Command
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure()
    {
        $this
            ->setName('watermarker:process')
            ->setDescription('Process and apply watermarks to media')
            ->setHelp('This command allows you to apply watermarks to existing media')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of media to process',
                100
            )
            ->addOption(
                'offset',
                'o',
                InputOption::VALUE_REQUIRED,
                'Offset to start from',
                0
            )
            ->addOption(
                'media-id',
                'm',
                InputOption::VALUE_REQUIRED,
                'Process a specific media by ID'
            )
            ->addOption(
                'item-id',
                'i',
                InputOption::VALUE_REQUIRED,
                'Process all media for a specific item by ID'
            )
            ->addOption(
                'item-set-id',
                's',
                InputOption::VALUE_REQUIRED,
                'Process all media for all items in a specific item set by ID'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force reapplication of watermarks even if already watermarked'
            );
    }

    /**
     * Execute the command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Watermarker Media Processor');

        // Get services
        $api = $this->serviceLocator->get('Omeka\ApiManager');
        $logger = $this->serviceLocator->get('Omeka\Logger');
        $applicator = $this->serviceLocator->get('Watermarker\Service\WatermarkApplicator');

        // Get supported types from settings
        $settings = $this->serviceLocator->get('Omeka\Settings');
        $supportedTypesStr = $settings->get('supported_image_types', 'image/jpeg, image/png, image/webp, image/tiff, image/gif, image/bmp');
        $supportedTypes = array_map('trim', explode(',', $supportedTypesStr));

        // Process by specific media ID
        $mediaId = $input->getOption('media-id');
        if ($mediaId) {
            $io->section('Processing media ID: ' . $mediaId);
            try {
                $media = $api->read('media', $mediaId)->getContent();
                $result = $applicator->applyWatermark($media);

                if ($result) {
                    $io->success('Successfully applied watermark to media ID ' . $mediaId);
                    return 0;
                } else {
                    $io->error('Failed to apply watermark to media ID ' . $mediaId);
                    return 1;
                }
            } catch (\Exception $e) {
                $io->error('Error: ' . $e->getMessage());
                return 1;
            }
        }

        // Process by item ID
        $itemId = $input->getOption('item-id');
        if ($itemId) {
            $io->section('Processing all media for item ID: ' . $itemId);
            try {
                $item = $api->read('items', $itemId)->getContent();
                $mediaList = $item->media();

                $io->progressStart(count($mediaList));
                $successful = 0;
                $failed = 0;

                foreach ($mediaList as $media) {
                    $result = $applicator->applyWatermark($media);
                    if ($result) {
                        $successful++;
                    } else {
                        $failed++;
                    }
                    $io->progressAdvance();
                }

                $io->progressFinish();
                $io->success(sprintf(
                    'Processed %d media: %d successful, %d failed',
                    count($mediaList),
                    $successful,
                    $failed
                ));

                return ($failed > 0) ? 1 : 0;
            } catch (\Exception $e) {
                $io->error('Error: ' . $e->getMessage());
                return 1;
            }
        }

        // Process by item set ID
        $itemSetId = $input->getOption('item-set-id');
        if ($itemSetId) {
            $io->section('Processing all media for item set ID: ' . $itemSetId);
            try {
                // First get all items in the set
                $items = $api->search('items', ['item_set_id' => $itemSetId])->getContent();

                $io->note(sprintf('Found %d items in the set', count($items)));

                $allMedia = [];
                // Gather all media from the items
                foreach ($items as $item) {
                    $mediaList = $item->media();
                    foreach ($mediaList as $media) {
                        $allMedia[] = $media;
                    }
                }

                $io->note(sprintf('Found %d media to process', count($allMedia)));
                $io->progressStart(count($allMedia));

                $successful = 0;
                $failed = 0;

                foreach ($allMedia as $media) {
                    $result = $applicator->applyWatermark($media);
                    if ($result) {
                        $successful++;
                    } else {
                        $failed++;
                    }
                    $io->progressAdvance();
                }

                $io->progressFinish();
                $io->success(sprintf(
                    'Processed %d media: %d successful, %d failed',
                    count($allMedia),
                    $successful,
                    $failed
                ));

                return ($failed > 0) ? 1 : 0;
            } catch (\Exception $e) {
                $io->error('Error: ' . $e->getMessage());
                return 1;
            }
        }

        // Process in batch mode
        $limit = (int) $input->getOption('limit');
        $offset = (int) $input->getOption('offset');
        $force = (bool) $input->getOption('force');

        $io->section('Processing in batch mode');
        $io->note(sprintf('Limit: %d, Offset: %d', $limit, $offset));

        try {
            // Build query to find media that needs watermarking
            $query = [
                'media_type' => $supportedTypes, // Use configured supported types
            ];

            $mediaList = $api->search('media', $query)->getContent();

            if (count($mediaList) === 0) {
                $io->warning('No media found with the specified criteria');
                return 0;
            }

            $io->note(sprintf('Found %d media to process', count($mediaList)));
            $io->progressStart(count($mediaList));

            $successful = 0;
            $failed = 0;

            foreach ($mediaList as $media) {
                $result = $applicator->applyWatermark($media);
                if ($result) {
                    $successful++;
                } else {
                    $failed++;
                }
                $io->progressAdvance();
            }

            $io->progressFinish();
            $io->success(sprintf(
                'Processed %d media: %d successful, %d failed',
                count($mediaList),
                $successful,
                $failed
            ));

            return ($failed > 0) ? 1 : 0;
        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}