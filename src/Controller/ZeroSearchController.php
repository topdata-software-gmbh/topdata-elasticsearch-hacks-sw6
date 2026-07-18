<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ZeroSearchController extends AbstractController
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    #[Route(
        path: '/api/_action/topdata-elasticsearch-hacks-sw6/zero-results/export',
        name: 'api.action.elasticsearchhackssw6.zero-results.export',
        methods: ['GET']
    )]
    public function exportAction(): Response
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT term, count, created_at, last_searched_at FROM topdata_es_zero_search ORDER BY count DESC'
        );

        $csv = "\xEF\xBB\xBF";
        $csv .= '"term","count","created_at","last_searched_at"' . "\n";

        foreach ($rows as $row) {
            $csv .= sprintf(
                '"%s",%d,"%s","%s"' . "\n",
                str_replace('"', '""', $row['term']),
                (int)$row['count'],
                $row['created_at'] ?? '',
                $row['last_searched_at'] ?? ''
            );
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="zero-search-results.csv"',
        ]);
    }

    #[Route(
        path: '/api/_action/topdata-elasticsearch-hacks-sw6/zero-results/reset',
        name: 'api.action.elasticsearchhackssw6.zero-results.reset',
        methods: ['POST']
    )]
    public function resetAction(): JsonResponse
    {
        $this->connection->executeStatement('TRUNCATE TABLE `topdata_es_zero_search`');

        return new JsonResponse(['success' => true]);
    }
}
