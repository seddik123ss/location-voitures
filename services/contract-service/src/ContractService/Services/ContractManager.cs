using ContractService.Clients;
using ContractService.Data;
using ContractService.Dtos;
using ContractService.Errors;
using ContractService.Messaging;
using ContractService.Models;
using Microsoft.EntityFrameworkCore;

namespace ContractService.Services;

/// <summary>
/// Règles métier des contrats : remise des clés, retour du véhicule, frais et clôture.
/// </summary>
public class ContractManager
{
    private readonly ContractDbContext _db;
    private readonly IReservationClient _reservations;
    private readonly IPaymentClient _payments;
    private readonly IVehicleClient _vehicles;
    private readonly FeeCalculator _fees;
    private readonly TimeProvider _clock;
    private readonly IEventPublisher _events;
    private readonly ILogger<ContractManager> _logger;

    public ContractManager(
        ContractDbContext db,
        IReservationClient reservations,
        IPaymentClient payments,
        IVehicleClient vehicles,
        FeeCalculator fees,
        TimeProvider clock,
        IEventPublisher events,
        ILogger<ContractManager> logger)
    {
        _db = db;
        _reservations = reservations;
        _payments = payments;
        _vehicles = vehicles;
        _fees = fees;
        _clock = clock;
        _events = events;
        _logger = logger;
    }

    private IQueryable<Contract> ContractsWithDetails =>
        _db.Contracts.Include(c => c.Inspections).Include(c => c.ExtraFees);

    public async Task<PageResponse<ContractDto>> ListAsync(int page, int size, Guid? userId, ContractStatus? status, CancellationToken ct)
    {
        var query = ContractsWithDetails.AsNoTracking();
        if (userId is not null) query = query.Where(c => c.UserId == userId);
        if (status is not null) query = query.Where(c => c.Status == status);

        var total = await query.CountAsync(ct);
        var items = await query
            .OrderByDescending(c => c.SignedAt)
            .Skip(page * size)
            .Take(size)
            .ToListAsync(ct);

        return new PageResponse<ContractDto>(items.Select(c => c.ToDto()).ToList(), page, size, total);
    }

    public async Task<Contract> GetAsync(Guid id, CancellationToken ct) =>
        await ContractsWithDetails.FirstOrDefaultAsync(c => c.Id == id, ct)
        ?? throw ApiException.NotFound($"Contrat {id} introuvable");

    /// <summary>Remise des clés : vérifie la réservation et la caution, crée le contrat et l'état des lieux de départ.</summary>
    public async Task<Contract> CreateAsync(CreateContractRequest request, CancellationToken ct)
    {
        var reservationId = request.ReservationId!.Value;

        var reservation = await _reservations.GetReservationAsync(reservationId, ct)
            ?? throw ApiException.NotFound($"Réservation {reservationId} introuvable");

        if (reservation.Status != "CONFIRMED")
            throw ApiException.Conflict($"La réservation doit être CONFIRMED (statut actuel : {reservation.Status})");

        var payments = await _payments.GetPaymentsByReservationAsync(reservationId, ct);
        var depositPaid = payments
            .Where(p => p.Type == "DEPOSIT" && p.Status == "SUCCEEDED")
            .Sum(p => p.Amount);
        if (depositPaid < reservation.DepositAmount)
            throw ApiException.Conflict($"La caution de {reservation.DepositAmount:0.000} TND n'est pas payée");

        if (await _db.Contracts.AnyAsync(c => c.ReservationId == reservationId && c.Status == ContractStatus.ACTIVE, ct))
            throw ApiException.Conflict("Un contrat actif existe déjà pour cette réservation");

        var signedAt = ToUtc(request.SignedAt) ?? _clock.GetUtcNow().UtcDateTime;

        var contract = new Contract
        {
            Id = Guid.NewGuid(),
            Number = await NextNumberAsync(signedAt.Year, ct),
            ReservationId = reservationId,
            UserId = reservation.UserId,
            VehicleId = reservation.VehicleId,
            SignedAt = signedAt,
            Status = ContractStatus.ACTIVE
        };
        contract.Inspections.Add(new Inspection
        {
            Id = Guid.NewGuid(),
            ContractId = contract.Id,
            Type = InspectionType.PICKUP,
            Mileage = request.Mileage!.Value,
            FuelLevel = request.FuelLevel!.Value,
            Damages = request.Damages,
            Photos = request.Photos ?? new List<string>(),
            InspectedAt = signedAt
        });

        // Publication "dans la transaction" : si RabbitMQ est absent, rien n'est enregistré (503)
        await using var tx = await _db.Database.BeginTransactionAsync(ct);
        _db.Contracts.Add(contract);
        await _db.SaveChangesAsync(ct);
        await _events.PublishAsync(Queues.VehicleContractEvents, "VehicleHandedOver", new
        {
            contractId = contract.Id,
            reservationId = contract.ReservationId,
            vehicleId = contract.VehicleId,
            mileage = request.Mileage!.Value,
            handedOverAt = signedAt
        }, signedAt, ct);
        await tx.CommitAsync(ct);
        return contract;
    }

    public async Task<Contract> UpdateAsync(Guid id, UpdateContractRequest request, CancellationToken ct)
    {
        var contract = await GetAsync(id, ct);
        if (contract.Status == ContractStatus.CLOSED)
            throw ApiException.Conflict("Un contrat clôturé ne peut plus être modifié");

        contract.SignedAt = ToUtc(request.SignedAt)!.Value;
        await _db.SaveChangesAsync(ct);
        return contract;
    }

    public async Task DeleteAsync(Guid id, CancellationToken ct)
    {
        var contract = await GetAsync(id, ct);
        if (contract.Status == ContractStatus.CLOSED)
            throw ApiException.Conflict("Un contrat clôturé ne peut pas être supprimé");

        // Le véhicule a été marqué RENTED à la remise des clés : la suppression (remise annulée) le libère.
        // VehicleReturned reprend le kilométrage et le carburant du départ ; dans la transaction, comme au retour.
        var pickup = contract.Inspections.FirstOrDefault(i => i.Type == InspectionType.PICKUP);
        var now = _clock.GetUtcNow().UtcDateTime;

        await using var tx = await _db.Database.BeginTransactionAsync(ct);
        _db.Contracts.Remove(contract);
        await _db.SaveChangesAsync(ct);
        if (pickup is not null)
        {
            await _events.PublishAsync(Queues.VehicleContractEvents, "VehicleReturned", new
            {
                contractId = contract.Id,
                reservationId = contract.ReservationId,
                vehicleId = contract.VehicleId,
                mileage = pickup.Mileage,
                fuelLevel = pickup.FuelLevel,
                returnedAt = now
            }, now, ct);
        }
        await tx.CommitAsync(ct);
    }

    /// <summary>Retour du véhicule : état des lieux de retour, calcul des frais, clôture du contrat.</summary>
    public async Task<ReturnSettlementDto> ReturnVehicleAsync(Guid id, ReturnVehicleRequest request, CancellationToken ct)
    {
        var contract = await GetAsync(id, ct);
        if (contract.Status == ContractStatus.CLOSED)
            throw ApiException.Conflict("Ce contrat est déjà clôturé");

        var pickup = contract.Inspections.FirstOrDefault(i => i.Type == InspectionType.PICKUP)
            ?? throw ApiException.Conflict("Le contrat n'a pas d'état des lieux de départ");

        var returnMileage = request.Mileage!.Value;
        if (returnMileage < pickup.Mileage)
            throw ApiException.Unprocessable(
                $"Le kilométrage au retour ({returnMileage}) doit être supérieur ou égal à celui du départ ({pickup.Mileage})",
                "mileage");

        var reservation = await _reservations.GetReservationAsync(contract.ReservationId, ct)
            ?? throw ApiException.NotFound($"Réservation {contract.ReservationId} introuvable");
        var vehicle = await _vehicles.GetVehicleAsync(contract.VehicleId, ct)
            ?? throw ApiException.NotFound($"Véhicule {contract.VehicleId} introuvable");

        var returnedAt = ToUtc(request.ReturnedAt) ?? _clock.GetUtcNow().UtcDateTime;
        if (returnedAt < contract.SignedAt)
            throw ApiException.Unprocessable("La date de retour doit être postérieure à la signature", "returnedAt");

        // _db.Add : nouvelle ligne (sinon EF prend l'ID déjà fixé pour une ligne existante à modifier)
        _db.Inspections.Add(new Inspection
        {
            Id = Guid.NewGuid(),
            ContractId = contract.Id,
            Contract = contract,
            Type = InspectionType.RETURN,
            Mileage = returnMileage,
            FuelLevel = request.FuelLevel!.Value,
            Damages = request.Damages,
            Photos = request.Photos ?? new List<string>(),
            InspectedAt = returnedAt
        });

        var fees = _fees.Calculate(new FeeCalculator.FeeInput(
            reservation.StartDate,
            reservation.EndDate,
            returnedAt,
            vehicle.Category.DailyRate,
            pickup.Mileage,
            returnMileage,
            pickup.FuelLevel,
            request.FuelLevel!.Value,
            request.DamageFee ?? 0m));

        foreach (var (type, amount) in fees)
        {
            _db.ExtraFees.Add(new ExtraFee { Id = Guid.NewGuid(), ContractId = contract.Id, Contract = contract, Type = type, Amount = amount });
        }

        contract.Status = ContractStatus.CLOSED;
        contract.ClosedAt = returnedAt;

        // Les frais sont facturés à part : la caution est libérée en entier (comme docs/events/ReturnSettled.json).
        var dto = contract.ToDto();
        var settlement = new ReturnSettlementDto(dto, dto.ExtraFees, dto.TotalExtraFees, reservation.DepositAmount);

        // VehicleReturned et ReturnSettled : dans la transaction (503 et rien d'enregistré si RabbitMQ est absent)
        await using (var tx = await _db.Database.BeginTransactionAsync(ct))
        {
            await _db.SaveChangesAsync(ct);
            await _events.PublishAsync(Queues.VehicleContractEvents, "VehicleReturned", new
            {
                contractId = contract.Id,
                reservationId = contract.ReservationId,
                vehicleId = contract.VehicleId,
                mileage = returnMileage,
                fuelLevel = request.FuelLevel!.Value,
                returnedAt
            }, returnedAt, ct);
            await _events.PublishAsync(Queues.PaymentReturnSettled, "ReturnSettled", new
            {
                contractId = contract.Id,
                reservationId = contract.ReservationId,
                userId = contract.UserId,
                extraFees = dto.ExtraFees.Select(f => new { type = f.Type.ToString(), amount = f.Amount }),
                depositToRelease = settlement.DepositToRelease
            }, returnedAt, ct);
            await tx.CommitAsync(ct);
        }

        // ContractClosed : best effort (log seulement si RabbitMQ est absent)
        try
        {
            await _events.PublishAsync(Queues.ReservationContractClosed, "ContractClosed", new
            {
                contractId = contract.Id,
                reservationId = contract.ReservationId,
                closedAt = returnedAt
            }, returnedAt, ct);
        }
        catch (ApiException e)
        {
            _logger.LogWarning("ContractClosed non publié pour {ContractId} : {Message}", contract.Id, e.Message);
        }

        return settlement;
    }

    public async Task<PageResponse<InspectionDto>> ListInspectionsAsync(int page, int size, Guid? contractId, CancellationToken ct)
    {
        var query = _db.Inspections.AsNoTracking();
        if (contractId is not null) query = query.Where(i => i.ContractId == contractId);

        var total = await query.CountAsync(ct);
        var items = await query
            .OrderByDescending(i => i.InspectedAt)
            .Skip(page * size)
            .Take(size)
            .ToListAsync(ct);

        return new PageResponse<InspectionDto>(items.Select(i => i.ToDto()).ToList(), page, size, total);
    }

    public async Task<Inspection> GetInspectionAsync(Guid id, CancellationToken ct) =>
        await _db.Inspections.FirstOrDefaultAsync(i => i.Id == id, ct)
        ?? throw ApiException.NotFound($"Inspection {id} introuvable");

    public async Task<Inspection> UpdateInspectionAsync(Guid id, UpdateInspectionRequest request, CancellationToken ct)
    {
        var inspection = await GetInspectionAsync(id, ct);
        var contract = await _db.Contracts.FirstAsync(c => c.Id == inspection.ContractId, ct);
        if (contract.Status == ContractStatus.CLOSED)
            throw ApiException.Conflict("Les inspections d'un contrat clôturé ne peuvent plus être modifiées");

        inspection.Damages = request.Damages;
        inspection.Photos = request.Photos ?? new List<string>();
        await _db.SaveChangesAsync(ct);
        return inspection;
    }

    private async Task<string> NextNumberAsync(int year, CancellationToken ct)
    {
        var prefix = $"CTR-{year}-";
        var last = await _db.Contracts
            .Where(c => c.Number.StartsWith(prefix))
            .OrderByDescending(c => c.Number)
            .Select(c => c.Number)
            .FirstOrDefaultAsync(ct);
        var next = last is null ? 1 : int.Parse(last[prefix.Length..]) + 1;
        return $"{prefix}{next:0000}";
    }

    private static DateTime? ToUtc(DateTime? d) =>
        d is null ? null : d.Value.Kind == DateTimeKind.Unspecified
            ? DateTime.SpecifyKind(d.Value, DateTimeKind.Utc)
            : d.Value.ToUniversalTime();
}
