<?php

declare(strict_types=1);

namespace WeltPixel\ShopifyCheckout\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WeltPixel\ShopifyCheckout\Exception\ApiException;
use WeltPixel\ShopifyCheckout\Model\Api\Client;
use WeltPixel\ShopifyCheckout\Model\Webhook\OrderProcessor;
use WeltPixel\ShopifyCheckout\Model\Config;

/**
 * CLI command to replay Shopify orders into Magento as a webhook fallback.
 */
class SyncOrders extends Command
{
    private const OPTION_ORDER_ID = 'order-id';
    private const OPTION_ENVIRONMENT = 'environment';

    /** @var Client */
    private Client $client;

    /** @var OrderProcessor */
    private OrderProcessor $orderProcessor;

    /** @var Config */
    private Config $config;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var State */
    private State $appState;

    /**
     * @param Client $client
     * @param OrderProcessor $orderProcessor
     * @param Config $config
     * @param LoggerInterface $logger
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        Client $client,
        OrderProcessor $orderProcessor,
        Config $config,
        LoggerInterface $logger,
        State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
        $this->client = $client;
        $this->orderProcessor = $orderProcessor;
        $this->config = $config;
        $this->logger = $logger;
        $this->appState = $appState;
    }

    /**
     * Configure command metadata and options.
     */
    protected function configure(): void
    {
        $this->setName('weltpixel:shopify:sync-orders')
            ->setDescription('Replay Shopify orders into Magento (webhook fallback).')
            ->addOption(
                self::OPTION_ORDER_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'Shopify order ID to replay'
            )
            ->addOption(
                self::OPTION_ENVIRONMENT,
                null,
                InputOption::VALUE_OPTIONAL,
                'Environment to use (sandbox or production)'
            );
    }

    /**
     * Execute the CLI command to fetch and process a Shopify order.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $orderId = $input->getOption(self::OPTION_ORDER_ID);
        if (!$orderId) {
            $output->writeln('<error>Please provide --order-id with the Shopify order ID.</error>');
            return Cli::RETURN_FAILURE;
        }

        $environment = $input->getOption(self::OPTION_ENVIRONMENT) ?: $this->config->getEnvironment();
        $environment = strtolower((string) $environment);

        if (!in_array($environment, ['sandbox', 'production'], true)) {
            $output->writeln('<error>Unsupported environment. Use sandbox or production.</error>');
            return Cli::RETURN_FAILURE;
        }

        try {
            $this->ensureAreaCode();
            $output->writeln(sprintf('<info>Fetching Shopify order %s (%s)...</info>', $orderId, $environment));
            $response = $this->client->callAdmin(
                'GET',
                'orders/' . $orderId . '.json',
                [],
                [],
                null,
                $environment
            );

            $order = $response['body']['order'] ?? null;
            if (!$order) {
                $output->writeln('<error>Order payload missing.</error>');
                return Cli::RETURN_FAILURE;
            }

            $this->orderProcessor->process($order);
            $output->writeln('<info>Magento order created/updated successfully.</info>');
            return Cli::RETURN_SUCCESS;
        } catch (ApiException $exception) {
            $output->writeln('<error>API error: ' . $exception->getMessage() . '</error>');
            $this->logger->error('Shopify CLI sync API error', ['exception' => $exception]);
            return Cli::RETURN_FAILURE;
        } catch (\Throwable $exception) {
            $output->writeln('<error>Unexpected error: ' . $exception->getMessage() . '</error>');
            $this->logger->critical('Shopify CLI sync unexpected error', ['exception' => $exception]);
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * Ensure Magento area code is set before order creation routines.
     */
    private function ensureAreaCode(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        }
    }
}
