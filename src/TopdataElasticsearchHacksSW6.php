<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Topdata\TopdataElasticsearchHacksSW6\DependencyInjection\ElasticsearchAnalysisCompilerPass;

class TopdataElasticsearchHacksSW6 extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ElasticsearchAnalysisCompilerPass());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        foreach (['tdeh_synonym', 'tdeh_zero_search', 'topdata_es_synonym', 'topdata_es_zero_search'] as $table) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }
}
