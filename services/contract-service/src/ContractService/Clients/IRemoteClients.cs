namespace ContractService.Clients;

// Chaque méthode renvoie null si l'objet n'existe pas (404 de l'autre service).
// Une implémentation réelle lèvera ApiException.ServiceUnavailable (503) si le service est arrêté
// ou absent d'Eureka, et ApiException.BadGateway (502) si sa réponse est illisible.

public interface IReservationClient
{
    Task<ReservationInfo?> GetReservationAsync(Guid id, CancellationToken ct = default);
}

public interface IPaymentClient
{
    Task<IReadOnlyList<PaymentInfo>> GetPaymentsByReservationAsync(Guid reservationId, CancellationToken ct = default);
}

public interface IVehicleClient
{
    Task<VehicleInfo?> GetVehicleAsync(Guid id, CancellationToken ct = default);
}
