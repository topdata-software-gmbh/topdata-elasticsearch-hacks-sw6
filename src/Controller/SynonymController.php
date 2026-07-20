<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class SynonymController extends AbstractController
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    #[Route(
        path: '/api/_action/topdata-elasticsearch-hacks-sw6/synonyms/export',
        name: 'api.action.elasticsearchhackssw6.synonyms.export',
        methods: ['GET']
    )]
    public function exportAction(): Response
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT term, synonyms, scope, created_at, updated_at FROM tdeh_synonym ORDER BY term ASC'
        );

        $csv = "\xEF\xBB\xBF";
        $csv .= '"term","synonyms","scope","created_at","updated_at"' . "\n";

        foreach ($rows as $row) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s"' . "\n",
                str_replace('"', '""', $row['term']),
                str_replace('"', '""', $row['synonyms']),
                str_replace('"', '""', $row['scope']),
                $row['created_at'] ?? '',
                $row['updated_at'] ?? ''
            );
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="synonyms.csv"',
        ]);
    }
}
