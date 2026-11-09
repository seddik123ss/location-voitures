namespace ContractService.Dtos;

/// <summary>Liste paginée : { items, page, size, total } (page commence à 0).</summary>
public record PageResponse<T>(IReadOnlyList<T> Items, int Page, int Size, int Total);
