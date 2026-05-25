<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductSearchSubscriber implements EventSubscriberInterface
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_SEARCH_RESULT => 'onSearchResult'
        ];
    }

    public function onSearchResult(ProductSearchResultEvent $event): void
    {
        $result = $event->getResult();

        if ($result->getTotal() !== 0) {
            return;
        }

        $term = $result->getCriteria()->getTerm();
        if ($term === null || trim($term) === '') {
            return;
        }

        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) > 255) {
            $term = mb_substr($term, 0, 255);
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO `topdata_es_zero_search` (`id`, `term`, `count`, `created_at`, `last_searched_at`)
                 VALUES (:id, :term, 1, :now, :now)
                 ON DUPLICATE KEY UPDATE `count` = `count` + 1, `last_searched_at` = :now',
                [
                    'id' => Uuid::randomBytes(),
                    'term' => $term,
                    'now' => (new \DateTime())->format('Y-m-d H:i:s.v')
                ]
            );
        } catch (\Throwable $e) {
            // Prevent failure logging from degrading active storefront performance
        }
    }
}
