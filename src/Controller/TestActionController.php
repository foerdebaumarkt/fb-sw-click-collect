<?php declare(strict_types=1);

namespace FoerdeClickCollect\Controller;

use FoerdeClickCollect\Service\ReminderService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TestActionController
{
    public function __construct(
        private readonly ReminderService $reminderService,
        private readonly string $kernelEnvironment
    ) {}

    /**
     * Lightweight test-only endpoint to trigger reminders in dev/test environments.
     */
    #[Route(path: '/api/_action/foerde-click-collect/run-reminders', name: 'api.foerde_click_collect.run_reminders', methods: ['POST'], defaults: ['_routeScope' => ['api']])]
    public function runReminders(Request $request): JsonResponse
    {
        if (!in_array($this->kernelEnvironment, ['dev', 'test'], true)) {
            return new JsonResponse(['error' => 'not-available'], 404);
        }

        try {
            $sent = $this->reminderService->sendReminders(null);
            return new JsonResponse(['sent' => $sent]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
