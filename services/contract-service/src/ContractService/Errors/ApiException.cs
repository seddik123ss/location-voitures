namespace ContractService.Errors;

/// <summary>
/// Erreur métier traduite en réponse HTTP au format commun de l'équipe :
/// { "status", "error", "message", "details": [ { "field", "message" } ] }.
/// </summary>
public class ApiException : Exception
{
    public int Status { get; }
    public string Error { get; }
    public IReadOnlyList<ErrorDetail> Details { get; }

    public ApiException(int status, string error, string message, IReadOnlyList<ErrorDetail>? details = null)
        : base(message)
    {
        Status = status;
        Error = error;
        Details = details ?? Array.Empty<ErrorDetail>();
    }

    public static ApiException NotFound(string message) =>
        new(StatusCodes.Status404NotFound, "NOT_FOUND", message);

    public static ApiException Conflict(string message) =>
        new(StatusCodes.Status409Conflict, "CONFLICT", message);

    public static ApiException Unprocessable(string message, string? field = null) =>
        new(StatusCodes.Status422UnprocessableEntity, "UNPROCESSABLE_ENTITY", message,
            field is null ? null : new[] { new ErrorDetail(field, message) });

    public static ApiException BadGateway(string message) =>
        new(StatusCodes.Status502BadGateway, "BAD_GATEWAY", message);

    public static ApiException ServiceUnavailable(string message) =>
        new(StatusCodes.Status503ServiceUnavailable, "SERVICE_UNAVAILABLE", message);
}

public record ErrorDetail(string Field, string Message);

/// <summary>Corps de réponse d'erreur commun à tous les services.</summary>
public record ApiError(int Status, string Error, string Message, IReadOnlyList<ErrorDetail> Details);
