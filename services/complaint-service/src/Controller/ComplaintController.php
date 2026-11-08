<?php

namespace App\Controller;

use App\Dto\AddMessageRequest;
use App\Dto\ChangePriorityRequest;
use App\Dto\ChangeStatusRequest;
use App\Dto\ComplaintFilter;
use App\Dto\CreateComplaintRequest;
use App\Dto\UpdateComplaintRequest;
use App\Http\ApiResponse;
use App\Presenter\ComplaintPresenter;
use App\Security\ApiUser;
use App\Security\ComplaintVoter;
use App\Service\ComplaintService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Ordre des contrôles : corps JSON (400, avant le contrôleur) -> réclamation introuvable (404)
 * -> droits (403, ComplaintVoter) -> état / règles (409, 422).
 */
#[Route('/api/complaints')]
#[OA\Tag(name: 'Réclamations')]
final class ComplaintController extends AbstractController
{
    /** UUID insensible à la casse (Requirement::UUID n'accepte que les minuscules) ; le service normalise en minuscules. */
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    private const BODY_FIRST = 'Le corps est validé avant la recherche de la réclamation : un corps invalide renvoie 400 même si la réclamation n\'existe pas.';

    public function __construct(
        private readonly ComplaintService $service,
        private readonly ComplaintPresenter $presenter,
    ) {
    }

    #[Route('', name: 'complaint_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Lister les réclamations',
        description: 'Liste paginée, plus récentes d\'abord. Un CLIENT ne voit que les siennes : le filtre userId est forcé à son identifiant (403 s\'il en demande un autre). AGENT et ADMIN voient tout.',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Numéro de page, à partir de 0', schema: new OA\Schema(type: 'integer', default: 0, minimum: 0)),
            new OA\Parameter(name: 'size', in: 'query', description: 'Taille de page', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100, minimum: 1)),
            new OA\Parameter(name: 'userId', in: 'query', description: 'Client auteur (AGENT / ADMIN ; un CLIENT ne peut passer que le sien)', schema: new OA\Schema(type: 'string', format: 'uuid'), example: '611f32de-ce7b-4927-b1c4-d113b6479d59'),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'REJECTED'])),
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string', enum: ['BILLING', 'VEHICLE', 'SERVICE', 'DAMAGE_DISPUTE'])),
            new OA\Parameter(name: 'priority', in: 'query', schema: new OA\Schema(type: 'string', enum: ['LOW', 'MEDIUM', 'HIGH'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Page de réclamations', content: new OA\JsonContent(ref: '#/components/schemas/ComplaintPage')),
            new OA\Response(response: 400, ref: '#/components/responses/BadRequest'),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
        ],
    )]
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

    #[Route('/{id}', name: 'complaint_show', requirements: ['id' => self::UUID], methods: ['GET'])]
    #[OA\Get(
        summary: 'Détail d\'une réclamation',
        description: 'Avec messages, historique des statuts et transitions encore possibles. CLIENT : seulement les siennes (403 sinon).',
        parameters: [new OA\PathParameter(name: 'id', description: 'Identifiant de la réclamation', schema: new OA\Schema(type: 'string', format: 'uuid'), example: 'b15d796a-8360-46da-a5e1-f9c580aac77f')],
        responses: [
            new OA\Response(response: 200, description: 'Réclamation', content: new OA\JsonContent(ref: '#/components/schemas/ComplaintDetail')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        $complaint = $this->service->get($id);
        $this->denyAccessUnlessGranted(ComplaintVoter::VIEW, $complaint, 'Cette réclamation appartient à un autre client');

        return ApiResponse::json($this->presenter->detail($complaint));
    }

    #[Route('', name: 'complaint_create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Créer une réclamation',
        description: 'CLIENT uniquement. Exactement un des deux champs reservationId ou contractId (aucun ou les deux → 422). La réservation ou le contrat doit exister (404) et appartenir à l\'appelant (403). Statut initial OPEN, priorité MEDIUM par défaut.',
        responses: [
            new OA\Response(
                response: 201,
                description: 'Réclamation créée',
                headers: [new OA\Header(header: 'Location', description: 'URL de la réclamation créée', schema: new OA\Schema(type: 'string', example: '/api/complaints/a7f8819d-fc19-4942-9767-71b4546ff46d'))],
                content: new OA\JsonContent(ref: '#/components/schemas/ComplaintDetail'),
            ),
            new OA\Response(response: 400, ref: '#/components/responses/BadRequest'),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, description: 'Appelant qui n\'est pas CLIENT, ou réservation / contrat d\'un autre client', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Cette réservation appartient à un autre client'])),
            new OA\Response(response: 404, description: 'Réservation ou contrat introuvable', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 404, 'error' => 'NOT_FOUND', 'message' => 'Réservation introuvable'])),
            new OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType'),
            new OA\Response(response: 422, description: 'Ni reservationId ni contractId, ou les deux', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 422, 'error' => 'UNPROCESSABLE_ENTITY', 'message' => 'Indiquer exactement un des deux champs reservationId ou contractId', 'details' => [['field' => 'reservationId', 'message' => 'Aucun des deux n\'est renseigné'], ['field' => 'contractId', 'message' => 'Aucun des deux n\'est renseigné']]])),
        ],
    )]
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

    #[Route('/{id}', name: 'complaint_update', requirements: ['id' => self::UUID], methods: ['PUT'])]
    #[OA\Put(
        summary: 'Modifier une réclamation',
        description: 'Client auteur uniquement, tant que la réclamation est OPEN (409 sinon). Remplace subject, description et category. ' . self::BODY_FIRST,
        parameters: [new OA\PathParameter(name: 'id', description: 'Identifiant de la réclamation', schema: new OA\Schema(type: 'string', format: 'uuid'), example: 'b15d796a-8360-46da-a5e1-f9c580aac77f')],
        responses: [
            new OA\Response(response: 200, description: 'Réclamation modifiée', content: new OA\JsonContent(ref: '#/components/schemas/ComplaintDetail')),
            new OA\Response(response: 400, ref: '#/components/responses/BadRequest'),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, description: 'Pas le client auteur', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Seul le client auteur peut modifier cette réclamation'])),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, description: 'Réclamation qui n\'est plus OPEN', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 409, 'error' => 'CONFLICT', 'message' => 'Seule une réclamation OPEN peut être modifiée (statut actuel : RESOLVED)'])),
            new OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType'),
        ],
    )]
    public function update(
        string $id,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] UpdateComplaintRequest $payload,
    ): JsonResponse {
        $complaint = $this->service->get($id);
        $this->denyAccessUnlessGranted(ComplaintVoter::EDIT, $complaint, 'Seul le client auteur peut modifier cette réclamation');
        $this->service->update($complaint, $payload);

        return ApiResponse::json($this->presenter->detail($complaint));
    }

    #[Route('/{id}', name: 'complaint_delete', requirements: ['id' => self::UUID], methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Supprimer une réclamation',
        description: 'Client auteur tant que la réclamation est OPEN (409 sinon) ; ADMIN quel que soit le statut. Messages et historique sont supprimés avec elle.',
        parameters: [new OA\PathParameter(name: 'id', description: 'Identifiant de la réclamation', schema: new OA\Schema(type: 'string', format: 'uuid'), example: 'b15d796a-8360-46da-a5e1-f9c580aac77f')],
        responses: [
            new OA\Response(response: 204, description: 'Réclamation supprimée'),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, description: 'Ni le client auteur ni un ADMIN', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Seul le client auteur ou un ADMIN peut supprimer cette réclamation'])),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, description: 'Client qui supprime une réclamation qui n\'est plus OPEN', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 409, 'error' => 'CONFLICT', 'message' => 'Seule une réclamation OPEN peut être supprimée par le client (statut actuel : IN_PROGRESS)'])),
        ],
    )]
    public function delete(string $id, #[CurrentUser] ApiUser $user): Response
    {
        $complaint = $this->service->get($id);
        $this->denyAccessUnlessGranted(ComplaintVoter::DELETE, $complaint, 'Seul le client auteur ou un ADMIN peut supprimer cette réclamation');
        $this->service->delete($complaint, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/status', name: 'complaint_change_status', requirements: ['id' => self::UUID], methods: ['PATCH'])]
    #[OA\Patch(
        summary: 'Changer le statut',
        description: 'AGENT ou ADMIN. Transitions autorisées : OPEN → IN_PROGRESS | REJECTED ; IN_PROGRESS → RESOLVED | REJECTED | OPEN. RESOLVED et REJECTED sont finaux (409). Le changement est ajouté à l\'historique avec le commentaire. ' . self::BODY_FIRST,
        parameters: [new OA\PathParameter(name: 'id', description: 'Identifiant de la réclamation', schema: new OA\Schema(type: 'string', format: 'uuid'), example: 'b15d796a-8360-46da-a5e1-f9c580aac77f')],
        responses: [
            new OA\Response(response: 200, description: 'Statut changé', content: new OA\JsonContent(ref: '#/components/schemas/ComplaintDetail')),
            new OA\Response(response: 400, ref: '#/components/responses/BadRequest'),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, description: 'Appelant qui n\'est ni AGENT ni ADMIN', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Seuls AGENT et ADMIN peuvent changer le statut'])),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, description: 'Transition interdite', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 409, 'error' => 'CONFLICT', 'message' => 'Transition de statut interdite : RESOLVED -> OPEN', 'details' => [['field' => 'status', 'message' => 'Transitions possibles depuis RESOLVED : aucune (état final)']]])),
            new OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType'),
        ],
    )]
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

    #[Route('/{id}/priority', name: 'complaint_change_priority', requirements: ['id' => self::UUID], methods: ['PATCH'])]
    #[OA\Patch(
        summary: 'Changer la priorité',
        description: 'AGENT ou ADMIN, refusé (409) si la réclamation est dans un état final (RESOLVED, REJECTED). Met à jour updatedAt ; le changement n\'est pas gardé dans l\'historique. ' . self::BODY_FIRST,
        parameters: [new OA\PathParameter(name: 'id', description: 'Identifiant de la réclamation', schema: new OA\Schema(type: 'string', format: 'uuid'), example: 'b15d796a-8360-46da-a5e1-f9c580aac77f')],
        responses: [
            new OA\Response(response: 200, description: 'Priorité changée', content: new OA\JsonContent(ref: '#/components/schemas/ComplaintDetail')),
            new OA\Response(response: 400, ref: '#/components/responses/BadRequest'),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, description: 'Appelant qui n\'est ni AGENT ni ADMIN', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Seuls AGENT et ADMIN peuvent changer la priorité'])),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, description: 'Réclamation dans un état final', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 409, 'error' => 'CONFLICT', 'message' => 'Réclamation clôturée (RESOLVED) : changement de priorité impossible'])),
            new OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType'),
        ],
    )]
    public function changePriority(
        string $id,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ChangePriorityRequest $payload,
    ): JsonResponse {
        $complaint = $this->service->get($id);
        $this->denyAccessUnlessGranted(ComplaintVoter::CHANGE_PRIORITY, $complaint, 'Seuls AGENT et ADMIN peuvent changer la priorité');
        $this->service->changePriority($complaint, $payload);

        return ApiResponse::json($this->presenter->detail($complaint));
    }

    #[Route('/{id}/messages', name: 'complaint_add_message', requirements: ['id' => self::UUID], methods: ['POST'])]
    #[OA\Post(
        summary: 'Ajouter un message',
        description: 'Client auteur, AGENT ou ADMIN ; refusé (409) si la réclamation est dans un état final. Le rôle de l\'auteur est enregistré avec le message. ' . self::BODY_FIRST,
        parameters: [new OA\PathParameter(name: 'id', description: 'Identifiant de la réclamation', schema: new OA\Schema(type: 'string', format: 'uuid'), example: 'b15d796a-8360-46da-a5e1-f9c580aac77f')],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Message ajouté',
                headers: [new OA\Header(header: 'Location', description: 'URL de la réclamation', schema: new OA\Schema(type: 'string', example: '/api/complaints/b15d796a-8360-46da-a5e1-f9c580aac77f'))],
                content: new OA\JsonContent(ref: '#/components/schemas/ComplaintMessage'),
            ),
            new OA\Response(response: 400, ref: '#/components/responses/BadRequest'),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, description: 'CLIENT qui n\'est pas l\'auteur', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Seuls le client auteur, AGENT et ADMIN peuvent écrire sur cette réclamation'])),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType'),
        ],
    )]
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
