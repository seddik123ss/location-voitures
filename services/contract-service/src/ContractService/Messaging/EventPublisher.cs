using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using ContractService.Errors;
using RabbitMQ.Client;

namespace ContractService.Messaging;

/// <summary>Noms des files (docs/events/README.md). contract-service est seulement producteur.</summary>
public static class Queues
{
    public const string VehicleContractEvents = "vehicle.contract_events";
    public const string PaymentReturnSettled = "payment.return_settled";
    public const string ReservationContractClosed = "reservation.contract_closed";
}

/// <summary>Enveloppe commune : { eventId, type, occurredAt, data }.</summary>
public record EventEnvelope(Guid EventId, string Type, DateTime OccurredAt, object Data);

public interface IEventPublisher
{
    /// <summary>Publie un message persistant ; lève ApiException 503 si RabbitMQ est injoignable.</summary>
    Task PublishAsync(string queue, string type, object data, DateTime occurredAt, CancellationToken ct = default);
}

public class RabbitMqOptions
{
    public string Url { get; set; } = "amqp://guest:guest@localhost:5672";
}

/// <summary>
/// Publie sur l'exchange par défaut avec routing key = nom de la file (la file est déclarée par le consommateur).
/// Messages persistants (delivery mode 2), content_type application/json, confirmation du broker attendue.
/// </summary>
public sealed class RabbitMqEventPublisher : IEventPublisher, IDisposable
{
    public static readonly JsonSerializerOptions Json = new(JsonSerializerDefaults.Web)
    {
        Converters = { new JsonStringEnumConverter() },
        Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping
    };

    private readonly ConnectionFactory _factory;
    private readonly ILogger<RabbitMqEventPublisher> _logger;
    private readonly object _lock = new();
    private IConnection? _connection;

    public RabbitMqEventPublisher(Microsoft.Extensions.Options.IOptions<RabbitMqOptions> options, ILogger<RabbitMqEventPublisher> logger)
    {
        _factory = new ConnectionFactory
        {
            Uri = new Uri(options.Value.Url),
            ClientProvidedName = "contract-service",
            AutomaticRecoveryEnabled = true,
            RequestedConnectionTimeout = TimeSpan.FromSeconds(3)
        };
        _logger = logger;
    }

    public Task PublishAsync(string queue, string type, object data, DateTime occurredAt, CancellationToken ct = default)
    {
        var envelope = new EventEnvelope(Guid.NewGuid(), type, DateTime.SpecifyKind(occurredAt, DateTimeKind.Utc), data);
        var body = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(envelope, Json));

        try
        {
            lock (_lock)
            {
                if (_connection is not { IsOpen: true })
                {
                    _connection?.Dispose();
                    _connection = _factory.CreateConnection();
                }

                using var channel = _connection.CreateModel();
                channel.ConfirmSelect();
                var props = channel.CreateBasicProperties();
                props.Persistent = true;
                props.ContentType = "application/json";
                props.MessageId = envelope.EventId.ToString();
                props.Type = type;
                channel.BasicPublish(exchange: "", routingKey: queue, basicProperties: props, body: body);
                channel.WaitForConfirmsOrDie(TimeSpan.FromSeconds(5));
            }
        }
        catch (Exception e) when (e is not ApiException)
        {
            _logger.LogError(e, "Publication {Type} sur {Queue} impossible", type, queue);
            throw ApiException.ServiceUnavailable("RabbitMQ est indisponible : opération annulée");
        }

        _logger.LogInformation("Publié {Type} ({EventId}) sur {Queue}", type, envelope.EventId, queue);
        return Task.CompletedTask;
    }

    public void Dispose() => _connection?.Dispose();
}
