using System.ComponentModel.DataAnnotations;
using ContractService.Dtos;
using ContractService.Errors;
using ContractService.Models;
using ContractService.Security;
using ContractService.Services;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace ContractService.Controllers;

/// <summary>Contrats de location : remise des clés, retour du véhicule et frais.</summary>
[ApiController]
[Route("api/contracts")]
[Produces("application/json")]
[Tags("Contracts")]
[Authorize(Roles = Roles.AnyUser)]
public class ContractsController : ControllerBase
{
    private readonly ContractManager _manager;

    public ContractsController(ContractManager manager)
    {
        _manager = manager;
    }

    /// <summary>Contrat chargé, avec vérification qu'un CLIENT ne lit que les siens (sinon 403).</summary>
    private async Task<Models.Contract> GetVisibleAsync(Guid id, CancellationToken ct)
    {
        var contract = await _manager.GetAsync(id, ct);
        if (User.IsClientOnly() && contract.UserId != User.UserId())
            throw new ApiException(StatusCodes.Status403Forbidden, "FORBIDDEN", "Ce contrat appartient à un autre client");
        return contract;
    }

    /// <summary>Lister les contrats (paginé). Un CLIENT ne voit que ses propres contrats.</summary>
    /// <param name="page">Numéro de page, à partir de 0.</param>
    /// <param name="size">Taille de page (1 à 100).</param>
    /// <param name="userId">Filtrer par client.</param>
    /// <param name="status">Filtrer par statut (ACTIVE ou CLOSED).</param>
    /// <param name="ct"></param>
    /// <response code="200">Page de contrats.</response>
    /// <response code="400">Paramètres de pagination invalides.</response>
    [HttpGet]
    [ProducesResponseType(typeof(PageResponse<ContractDto>), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status400BadRequest)]
    public Task<PageResponse<ContractDto>> List(
        [FromQuery, Range(0, int.MaxValue)] int page = 0,
        [FromQuery, Range(1, 100)] int size = 20,
        [FromQuery] Guid? userId = null,
        [FromQuery] ContractStatus? status = null,
        CancellationToken ct = default) =>
        _manager.ListAsync(page, size, User.IsClientOnly() ? User.UserId() ?? Guid.Empty : userId, status, ct);

    /// <summary>Consulter un contrat, avec ses inspections et ses frais.</summary>
    /// <param name="id">UUID du contrat.</param>
    /// <param name="ct"></param>
    /// <response code="200">Le contrat.</response>
    /// <response code="404">Contrat introuvable.</response>
    [HttpGet("{id:guid}")]
    [ProducesResponseType(typeof(ContractDto), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status404NotFound)]
    public async Task<ContractDto> Get(Guid id, CancellationToken ct) =>
        (await GetVisibleAsync(id, ct)).ToDto();

    /// <summary>Remise des clés : créer le contrat et l'état des lieux de départ.</summary>
    /// <remarks>
    /// La réservation doit être CONFIRMED et sa caution payée, sinon 409.
    /// Un seul contrat actif par réservation, sinon 409.
    ///
    ///     POST /api/contracts
    ///     {
    ///       "reservationId": "33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8",
    ///       "mileage": 28750,
    ///       "fuelLevel": 1,
    ///       "damages": null,
    ///       "photos": []
    ///     }
    /// </remarks>
    /// <param name="request">Réservation et état des lieux de départ.</param>
    /// <param name="ct"></param>
    /// <response code="201">Contrat créé (en-tête Location).</response>
    /// <response code="400">Corps invalide.</response>
    /// <response code="404">Réservation introuvable.</response>
    /// <response code="409">Réservation non confirmée, caution non payée ou contrat actif existant.</response>
    /// <response code="502">Réponse illisible d'un autre service.</response>
    /// <response code="503">Service réservation ou paiement indisponible.</response>
    [HttpPost]
    [Authorize(Roles = Roles.Staff)]
    [ProducesResponseType(typeof(ContractDto), StatusCodes.Status201Created)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status400BadRequest)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status404NotFound)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status409Conflict)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status502BadGateway)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status503ServiceUnavailable)]
    public async Task<ActionResult<ContractDto>> Create(CreateContractRequest request, CancellationToken ct)
    {
        var contract = await _manager.CreateAsync(request, ct);
        return CreatedAtAction(nameof(Get), new { id = contract.Id }, contract.ToDto());
    }

    /// <summary>Modifier un contrat actif (date de signature).</summary>
    /// <param name="id">UUID du contrat.</param>
    /// <param name="request">Nouvelles valeurs.</param>
    /// <param name="ct"></param>
    /// <response code="200">Contrat modifié.</response>
    /// <response code="400">Corps invalide.</response>
    /// <response code="404">Contrat introuvable.</response>
    /// <response code="409">Contrat déjà clôturé.</response>
    [HttpPut("{id:guid}")]
    [Authorize(Roles = Roles.Staff)]
    [ProducesResponseType(typeof(ContractDto), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status400BadRequest)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status404NotFound)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status409Conflict)]
    public async Task<ContractDto> Update(Guid id, UpdateContractRequest request, CancellationToken ct) =>
        (await _manager.UpdateAsync(id, request, ct)).ToDto();

    /// <summary>Supprimer un contrat actif (annulation d'une remise des clés saisie par erreur).</summary>
    /// <param name="id">UUID du contrat.</param>
    /// <param name="ct"></param>
    /// <response code="204">Contrat supprimé.</response>
    /// <response code="404">Contrat introuvable.</response>
    /// <response code="409">Contrat clôturé : suppression impossible.</response>
    [HttpDelete("{id:guid}")]
    [Authorize(Roles = Roles.Staff)]
    [ProducesResponseType(StatusCodes.Status204NoContent)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status404NotFound)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status409Conflict)]
    public async Task<IActionResult> Delete(Guid id, CancellationToken ct)
    {
        await _manager.DeleteAsync(id, ct);
        return NoContent();
    }

    /// <summary>Retour du véhicule : état des lieux de retour, calcul des frais et clôture du contrat.</summary>
    /// <remarks>
    /// Frais calculés automatiquement :
    /// - LATE : chaque jour entamé après 12:00 UTC le dernier jour, au tarif journalier de la catégorie ;
    /// - FUEL : part du réservoir manquante x 140 TND ;
    /// - MILEAGE : km au-delà de 250 km par jour réservé x 0.200 TND ;
    /// - DAMAGE : montant estimé par l'agent (damageFee).
    ///
    ///     POST /api/contracts/{id}/return
    ///     {
    ///       "mileage": 29400,
    ///       "fuelLevel": 0.75,
    ///       "damages": null,
    ///       "damageFee": 0,
    ///       "returnedAt": "2026-11-25T11:00:00Z"
    ///     }
    /// </remarks>
    /// <param name="id">UUID du contrat.</param>
    /// <param name="request">État des lieux de retour.</param>
    /// <param name="ct"></param>
    /// <response code="200">Contrat clôturé, frais et caution à libérer.</response>
    /// <response code="400">Corps invalide.</response>
    /// <response code="404">Contrat, réservation ou véhicule introuvable.</response>
    /// <response code="409">Contrat déjà clôturé.</response>
    /// <response code="422">Kilométrage au retour inférieur à celui du départ.</response>
    /// <response code="502">Réponse illisible d'un autre service.</response>
    /// <response code="503">Service réservation ou véhicule indisponible.</response>
    [HttpPost("{id:guid}/return")]
    [Authorize(Roles = Roles.Staff)]
    [ProducesResponseType(typeof(ReturnSettlementDto), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status400BadRequest)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status404NotFound)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status409Conflict)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status422UnprocessableEntity)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status502BadGateway)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status503ServiceUnavailable)]
    public Task<ReturnSettlementDto> ReturnVehicle(Guid id, ReturnVehicleRequest request, CancellationToken ct) =>
        _manager.ReturnVehicleAsync(id, request, ct);

    /// <summary>Lister les frais supplémentaires d'un contrat.</summary>
    /// <param name="id">UUID du contrat.</param>
    /// <param name="ct"></param>
    /// <response code="200">Frais du contrat (vide tant que le véhicule n'est pas rendu).</response>
    /// <response code="404">Contrat introuvable.</response>
    [HttpGet("{id:guid}/extra-fees")]
    [ProducesResponseType(typeof(IReadOnlyList<ExtraFeeDto>), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status404NotFound)]
    public async Task<IReadOnlyList<ExtraFeeDto>> ExtraFees(Guid id, CancellationToken ct) =>
        (await GetVisibleAsync(id, ct)).ToDto().ExtraFees;
}
