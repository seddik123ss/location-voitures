using System.ComponentModel.DataAnnotations;
using ContractService.Dtos;
using ContractService.Errors;
using ContractService.Security;
using ContractService.Services;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace ContractService.Controllers;

/// <summary>
/// États des lieux. Ils sont créés par la remise des clés (PICKUP) et par le retour (RETURN).
/// </summary>
[ApiController]
[Route("api/inspections")]
[Produces("application/json")]
[Tags("Inspections")]
[Authorize(Roles = Roles.Staff)]
public class InspectionsController : ControllerBase
{
    private readonly ContractManager _manager;

    public InspectionsController(ContractManager manager)
    {
        _manager = manager;
    }

    /// <summary>Lister les états des lieux (paginé).</summary>
    /// <param name="page">Numéro de page, à partir de 0.</param>
    /// <param name="size">Taille de page (1 à 100).</param>
    /// <param name="contractId">Filtrer par contrat.</param>
    /// <param name="ct"></param>
    /// <response code="200">Page d'états des lieux.</response>
    /// <response code="400">Paramètres de pagination invalides.</response>
    [HttpGet]
    [ProducesResponseType(typeof(PageResponse<InspectionDto>), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status400BadRequest)]
    public Task<PageResponse<InspectionDto>> List(
        [FromQuery, Range(0, int.MaxValue)] int page = 0,
        [FromQuery, Range(1, 100)] int size = 20,
        [FromQuery] Guid? contractId = null,
        CancellationToken ct = default) =>
        _manager.ListInspectionsAsync(page, size, contractId, ct);

    /// <summary>Consulter un état des lieux.</summary>
    /// <param name="id">UUID de l'inspection.</param>
    /// <param name="ct"></param>
    /// <response code="200">L'état des lieux.</response>
    /// <response code="404">Inspection introuvable.</response>
    [HttpGet("{id:guid}")]
    [ProducesResponseType(typeof(InspectionDto), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status404NotFound)]
    public async Task<InspectionDto> Get(Guid id, CancellationToken ct) =>
        (await _manager.GetInspectionAsync(id, ct)).ToDto();

    /// <summary>Compléter un état des lieux (dommages, photos) tant que le contrat est actif.</summary>
    /// <param name="id">UUID de l'inspection.</param>
    /// <param name="request">Dommages et photos.</param>
    /// <param name="ct"></param>
    /// <response code="200">Inspection modifiée.</response>
    /// <response code="400">Corps invalide.</response>
    /// <response code="404">Inspection introuvable.</response>
    /// <response code="409">Contrat clôturé.</response>
    [HttpPut("{id:guid}")]
    [ProducesResponseType(typeof(InspectionDto), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status400BadRequest)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status404NotFound)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status409Conflict)]
    public async Task<InspectionDto> Update(Guid id, UpdateInspectionRequest request, CancellationToken ct) =>
        (await _manager.UpdateInspectionAsync(id, request, ct)).ToDto();
}
