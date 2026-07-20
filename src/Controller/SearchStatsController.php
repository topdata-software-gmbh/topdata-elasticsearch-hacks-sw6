<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class SearchStatsController extends AbstractController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route(
        path: '/api/_action/topdata-elasticsearch-hacks-sw6/search-stats/export',
        name: 'api.action.elasticsearchhackssw6.search-stats.export',
        methods: ['GET']
    )]
    public function exportAction(): Response
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT term, count, zero_count, avg_result_count, created_at, last_searched_at 
             FROM tdeh_search_stats 
             ORDER BY count DESC'
        );

        $csv = "\xEF\xBB\xBF";
        $csv .= '"term","total_searches","zero_result_searches","avg_result_count","created_at","last_searched_at"' . "\n";

        foreach ($rows as $row) {
            $csv .= sprintf(
                '"%s",%d,%d,%d,"%s","%s"' . "\n",
                str_replace('"', '""', $row['term']),
                (int)$row['count'],
                (int)$row['zero_count'],
                (int)$row['avg_result_count'],
                $row['created_at'] ?? '',
                $row['last_searched_at'] ?? ''
            );
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="search-statistics.csv"',
        ]);
    }

    #[Route(
        path: '/api/_action/topdata-elasticsearch-hacks-sw6/search-stats/reset',
        name: 'api.action.elasticsearchhackssw6.search-stats.reset',
        methods: ['POST']
    )]
    public function resetAction(): JsonResponse
    {
        $this->connection->executeStatement('TRUNCATE TABLE `tdeh_search_stats`');
        $this->connection->executeStatement('TRUNCATE TABLE `tdeh_search_log`');

        return new JsonResponse(['success' => true]);
    }
}
