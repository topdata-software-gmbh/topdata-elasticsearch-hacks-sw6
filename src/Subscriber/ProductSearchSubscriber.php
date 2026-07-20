<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestResultEvent;
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
            ProductEvents::PRODUCT_SEARCH_RESULT => 'onSearchResult',
            ProductEvents::PRODUCT_SUGGEST_RESULT => 'onSearchResult',
        ];
    }

    public function onSearchResult($event): void
    {
        $result = $event->getResult();
        $term = $result->getCriteria()->getTerm();

        if ($term === null || trim($term) === '') {
            return;
        }

        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) > 255) {
            $term = mb_substr($term, 0, 255);
        }

        if (mb_strlen($term) < 2) {
            return;
        }

        $resultCount = $result->getTotal();
        $sessionToken = $event->getSalesChannelContext()->getToken();

        try {
            $this->connection->executeStatement(
                'INSERT INTO `tdeh_search_log` (`id`, `session_token`, `term`, `result_count`, `created_at`)
                 VALUES (:id, :session_token, :term, :result_count, :now)',
                [
                    'id' => Uuid::randomBytes(),
                    'session_token' => $sessionToken,
                    'term' => $term,
                    'result_count' => $resultCount,
                    'now' => (new \DateTime())->format('Y-m-d H:i:s.v')
                ]
            );
        } catch (\Throwable $e) {
        }
    }
}
