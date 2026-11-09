using ContractService.Dtos;
using ContractService.Errors;
using ContractService.Security;
using ContractService.Services;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace ContractService.Controllers;

/// <summary>
/// Routes entre services (jamais exposées par la Gateway), réservées au rôle INTERNAL
/// (token de compte de service Keycloak).
/// </summary>
[ApiController]
[Route("internal/contracts")]
[Produces("application/json")]
[Tags("Internal")]
[Authorize(Roles = Roles.Internal)]
public class InternalContractsController : ControllerBase
{
    private readonly ContractManager _manager;

    public InternalContractsController(ContractManager manager)
    {
        _manager = manager;
    }

    /// <summary>Contrat vu par un autre service (complaint-service vérifie l'appartenance au client).</summary>
    /// <param name="id">UUID du contrat.</param>
    /// <param name="ct"></param>
    /// <response code="200">{id, number, reservationId, userId, vehicleId, status, signedAt}</response>
    /// <response code="404">Contrat introuvable.</response>
    [HttpGet("{id:guid}")]
    [ProducesResponseType(typeof(InternalContractDto), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiError), StatusCodes.Status404NotFound)]
    public async Task<InternalContractDto> Get(Guid id, CancellationToken ct) =>
        (await _manager.GetAsync(id, ct)).ToInternalDto();
}
