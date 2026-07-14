<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\Service\SiteInformationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Prints every domain this TYPO3 instance serves as JSON.
 *
 * The MCP gateway builds its routing allowlist from the projects' .mcp.json
 * files; the optional "domains" key lists all hosts of the instance (site
 * base + all baseVariants) so URLs from additional domains (e.g. country
 * variants like partner.example.org) can be mapped to the correct backend.
 * Embed the output into the project's .mcp.json:
 *
 *   vendor/bin/typo3 mcp:domains
 *   -> {"domains": ["www.example.com", "stage.example.com", ...]}
 */
class DomainsCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp(
            'Outputs {"domains": [...]} with every site base host and all baseVariant hosts, '
            . 'deduplicated. Intended for the "domains" key of the project\'s .mcp.json, '
            . 'which the MCP gateway reads when building its domain→backend mapping.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domains = GeneralUtility::makeInstance(SiteInformationService::class)->getAllDomains();
        sort($domains);

        $output->writeln((string)json_encode(
            ['domains' => array_values($domains)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));

        return Command::SUCCESS;
    }
}
