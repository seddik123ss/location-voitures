namespace ContractService.Models;

/// <summary>
/// Frais supplémentaire calculé au retour du véhicule (montant en TND, 3 décimales).
/// </summary>
public class ExtraFee
{
    public Guid Id { get; set; }

    public Guid ContractId { get; set; }
    public Contract? Contract { get; set; }

    public ExtraFeeType Type { get; set; }

    public decimal Amount { get; set; }
}
