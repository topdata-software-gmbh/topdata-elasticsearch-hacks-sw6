<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Topdata\TopdataElasticsearchHacksSW6\Service\ZeroSearchService;

#[Route(defaults: ['_routeScope' => ['api']])]
class ZeroResultsExportController extends AbstractController
{
    private ZeroSearchService $zeroSearchService;

    public function __construct(ZeroSearchService $zeroSearchService)
    {
        $this->zeroSearchService = $zeroSearchService;
    }

    #[Route(
        path: '/api/_action/topdata-elasticsearch-hacks-sw6/zero-results/export',
        name: 'api.action.elasticsearchhackssw6.zero_results.export',
        methods: ['GET']
    )]
    public function export(Request $request): Response
    {
        $limit = $request->query->getInt('limit', 100);
        $minCount = $request->query->getInt('minCount', 1);
        $format = $request->query->get('format', 'json');

        try {
            if ($format === 'json') {
                $data = $this->zeroSearchService->fetchZeroResults($limit, $minCount);
                return new JsonResponse($data);
            }

            $content = $this->zeroSearchService->export($format, $limit, $minCount);
            $response = new Response($content);

            if ($format === 'csv') {
                $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
                $response->headers->set('Content-Disposition', 'attachment; filename="zero_results_export.csv"');
            } elseif ($format === 'markdown') {
                $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
            }

            return $response;
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'An error occurred during export: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
