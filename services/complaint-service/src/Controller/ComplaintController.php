<?php

namespace App\Controller;

use App\Dto\AddMessageRequest;
use App\Dto\ChangeStatusRequest;
use App\Dto\ComplaintFilter;
use App\Dto\CreateComplaintRequest;
use App\Dto\UpdateComplaintRequest;
use App\Http\ApiResponse;
use App\Presenter\ComplaintPresenter;
use App\Security\ApiUser;
use App\Security\ComplaintVoter;
use App\Service\ComplaintService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Ordre des contrôles : réclamation introuvable (404) -> droits (403, ComplaintVoter) -> état / règles (409, 422).
 * Corps JSON invalide ou champ manquant : 400 avec details par champ.
 */
#[Route('/api/complaints')]
final class ComplaintController extends AbstractController
{
    public function __construct(
        private readonly ComplaintService $service,
        private readonly ComplaintPresenter $presenter,
    ) {
    }

    #[Route('', name: 'complaint_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ApiUser $user): JsonResponse
    {
        $filter = ComplaintFilter::fromRequest($request);
        $result = $this->service->search($filter, $user);

        return ApiResponse::json([
            'items' => array_map($this->presenter->summary(...), $result['items']),
            'page' => $filter->page,
            'size' => $filter->size,
            'total' => $result['total'],
        ]);
    }

    #[Route('/{id}', name: 'complaint_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $complaint = $this->service->get($id);
        $this->denyAccessUnlessGranted(ComplaintVoter::VIEW, $complaint, 'Cette réclamation appartient à un autre client');

        return ApiResponse::json($this->presenter->detail($complaint));
    }

    #[Route('', name: 'complaint_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] CreateComplaintRequest $payload,
        #[CurrentUser] ApiUser $user,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_CLIENT', null, 'Seul un client peut créer une réclamation');
        $complaint = $this->service->create($payload, $user);

        return ApiResponse::json($this->presenter->detail($complaint), Response::HTTP_CREATED, [
            'Location' => $this->generateUrl('complaint_show', ['id' => $complaint->getId()]),
        ]);
    }

    #[Route('/{id}', name: 'complaint_update', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    public function update(
        string $id,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] UpdateComplaintRequest $payload,
    ): JsonResponse {
        $complaint = $this->service->get($id);
        $this->denyAccessUnlessGranted(ComplaintVoter::EDIT, $complaint, 'Seul le client auteur peut modifier cette réclamation');
        $this->service->update($complaint, $payload);

        return ApiResponse::json($this->presenter->detail($complaint));
    }

    #[Route('/{id}', name: 'complaint_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] ApiUser $user): Response
    {
        $complaint = $this->service->get($id);
        $this->denyAccessUnlessGranted(ComplaintVoter::DELETE, $complaint, 'Seul le client auteur ou un ADMIN peut supprimer cette réclamation');
        $this->service->delete($complaint, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/status', name: 'complaint_change_status', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    public function changeStatus(
        string $id,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ChangeStatusRequest $payload,
        #[CurrentUser] ApiUser $user,
    ): JsonResponse {
        $complaint = $this->service->get($id);
        $this->denyAccessUnlessGranted(ComplaintVoter::CHANGE_STATUS, $complaint, 'Seuls AGENT et ADMIN peuvent changer le statut');
        $this->service->changeStatus($complaint, $payload, $user);

        return ApiResponse::json($this->presenter->detail($complaint));
    }

    #[Route('/{id}/messages', name: 'complaint_add_message', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function addMessage(
        string $id,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] AddMessageRequest $payload,
        #[CurrentUser] ApiUser $user,
    ): JsonResponse {
        $complaint = $this->service->get($id);
        $this->denyAccessUnlessGranted(ComplaintVoter::ADD_MESSAGE, $complaint, 'Seuls le client auteur, AGENT et ADMIN peuvent écrire sur cette réclamation');
        $message = $this->service->addMessage($complaint, $payload, $user);

        return ApiResponse::json($this->presenter->message($message), Response::HTTP_CREATED, [
            'Location' => $this->generateUrl('complaint_show', ['id' => $complaint->getId()]),
        ]);
    }
}
